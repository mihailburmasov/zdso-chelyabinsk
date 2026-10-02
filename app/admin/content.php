<?php
// Чтение и запись полей форм в data/*.json, проверка значений, сохранение с пересборкой сайта.
declare(strict_types=1);

final class Docs
{
    public static array $cache = [];
}

function doc_get(string $rel): array
{
    if (!array_key_exists($rel, Docs::$cache)) Docs::$cache[$rel] = read_json(data_path($rel));
    return Docs::$cache[$rel];
}

function split_bind(string $bind): array
{
    [$file, $path] = explode('#', $bind, 2) + [1 => ''];
    return [$file, $path === '' ? [] : explode('.', $path)];
}

function arr_get(array $a, array $path)
{
    foreach ($path as $k) { if (!is_array($a) || !array_key_exists($k, $a)) return null; $a = $a[$k]; }
    return $a;
}
function arr_set(array &$a, array $path, $value): void
{
    if (!$path) { $a = $value; return; }
    $ref = &$a;
    foreach ($path as $i => $k) {
        if ($i === count($path) - 1) { $ref[$k] = $value; return; }
        if (!isset($ref[$k]) || !is_array($ref[$k])) $ref[$k] = [];
        $ref = &$ref[$k];
    }
}

function bind_get(string $bind)
{
    [$file, $path] = split_bind($bind);
    return arr_get(doc_get($file), $path);
}

// ---------- проверка значений по типу поля ----------
function clean_text($v, array $fd): string
{
    if (is_array($v)) throw new InvalidArgumentException('ожидался текст');
    $s = trim(str_replace(["\r\n", "\r"], "\n", (string)$v));
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    if ($fd['type'] === 'text') $s = preg_replace('/\s*\n\s*/u', ' ', $s);
    if (mb_strlen($s) > 10000) throw new InvalidArgumentException('слишком длинный текст');
    if (!empty($fd['required']) && $s === '') throw new InvalidArgumentException('поле обязательно');
    return $s;
}

function clean_value(array $fd, $v)
{
    $label = $fd['label'] ?? ($fd['key'] ?? '');
    if ($v === null && in_array($fd['type'], ['list', 'items', 'photos', 'services'], true)) $v = []; // поля нет в данных — пустой список
    try {
        switch ($fd['type']) {
            case 'text': case 'textarea': return clean_text($v, $fd);
            case 'url':
                $s = clean_text($v, ['type' => 'text'] + $fd);
                if ($s !== '' && !preg_match('~^https?://[^\s"<>]+$~i', $s)) throw new InvalidArgumentException('ссылка должна начинаться с https://');
                return $s;
            case 'email':
                $s = clean_text($v, ['type' => 'text'] + $fd);
                if ($s !== '' && !filter_var($s, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('неверный адрес почты');
                return $s;
            case 'number':
                if ($v === '' || $v === null) return null;
                if (!is_numeric($v)) throw new InvalidArgumentException('нужно число');
                // Дробное сохраняем дробным: массы деталей бывают 0,42 кг и 2,32 кг.
                $f = (float)$v;
                return $f == (int)$f && abs($f) < PHP_INT_MAX ? (int)$f : $f;
            case 'bool': return (bool)$v;
            case 'list':
                if (!is_array($v)) throw new InvalidArgumentException('ожидался список');
                return array_values(array_filter(array_map(fn($x) => clean_text($x, ['type' => !empty($fd['multiline']) ? 'textarea' : 'text']), $v), fn($x) => $x !== ''));
            case 'items':
                if (!is_array($v)) throw new InvalidArgumentException('ожидался список');
                $out = [];
                foreach (array_values($v) as $i => $row) {
                    if (!is_array($row)) continue;
                    // Порядок ключей — как в исходной записи; поля, которых нет в форме, сохраняются как были
                    $subs = array_column($fd['item'], null, 'key');
                    $item = [];
                    foreach (array_unique(array_merge(array_keys($row), array_keys($subs))) as $key) {
                        if (!isset($subs[$key])) {
                            // Поля, которых нет в форме, проносим как есть — иначе сохранение из админки
                            // съедает то, что форма не показывает (например, таблицы внутри блоков).
                            if (!is_string($key) || !preg_match('~^[A-Za-z_]{1,40}$~', $key)) continue;
                            $raw = $row[$key] ?? null;
                            if (is_string($raw)) $item[$key] = clean_text($raw, ['type' => 'textarea']);
                            elseif (is_scalar($raw) || $raw === null) $item[$key] = $raw;
                            elseif (is_array($raw)) $item[$key] = passthrough_value($raw);
                            continue;
                        }
                        $sub = $subs[$key];
                        $val = clean_value($sub, $row[$key] ?? null);
                        if ($sub['type'] === 'bool') { if ($val) $item[$key] = true; continue; }
                        // Пустое необязательное поле не записываем — в том числе пустой список:
                        // иначе первое же сохранение дописывает в данные "list": [] и даёт ложный дифф.
                        if (is_blank($val) && (!empty($sub['omitEmpty']) || !array_key_exists($key, $row))) continue;
                        $item[$key] = $val;
                    }
                    // строки, где все поля пустые, отбрасываем
                    $filled = array_filter($item, fn($x) => $x !== '' && $x !== [] && $x !== null && $x !== false);
                    if ($filled) $out[] = $item;
                }
                return $out;
            case 'icon':
                $v = (string)$v;
                if (!isset(ICONS[$v])) throw new InvalidArgumentException('неизвестная иконка');
                return $v;
            case 'photo':
                $v = (string)$v;
                if ($v === '') return ''; // фото не выбрано — это нормально
                if (!isset(S::$photoIndex[$v])) throw new InvalidArgumentException('фото не найдено');
                return $v;
            case 'photos':
                if (!is_array($v)) throw new InvalidArgumentException('ожидался список фото');
                foreach ($v as $id) if (!isset(S::$photoIndex[$id]) && !isset(S::$photos[$id])) throw new InvalidArgumentException('фото ' . $id . ' не найдено');
                return array_values(array_unique(array_map('strval', $v)));
            case 'service':
                $v = (string)$v;
                if (!is_file(data_path("services/$v.json"))) throw new InvalidArgumentException('услуга не найдена');
                return $v;
            case 'services':
                if (!is_array($v)) throw new InvalidArgumentException('ожидался список услуг');
                foreach ($v as $sl) if (!preg_match('~^[a-z0-9-]+$~', (string)$sl) || !is_file(data_path("services/$sl.json"))) throw new InvalidArgumentException('услуга ' . $sl . ' не найдена');
                return array_values(array_unique(array_map('strval', $v)));
            case 'category':
                $v = (string)$v;
                if (!isset(S::$photos[$v])) throw new InvalidArgumentException('папка фото не найдена');
                return $v;
            case 'herobg':
                if (!is_array($v)) throw new InvalidArgumentException('не выбран фон');
                if (!empty($v['art'])) { if (!isset(S::$heroDims[$v['art']])) throw new InvalidArgumentException('арт не найден'); $out = ['art' => (string)$v['art']]; }
                elseif (!empty($v['photo'])) { if (!isset(S::$photoIndex[$v['photo']])) throw new InvalidArgumentException('фото не найдено'); $out = ['photo' => (string)$v['photo']]; }
                else throw new InvalidArgumentException('не выбран фон');
                if (!empty($v['pos'])) { if (!preg_match('~^[a-z0-9% .-]{1,30}$~i', (string)$v['pos'])) throw new InvalidArgumentException('неверная позиция'); $out['pos'] = (string)$v['pos']; }
                return $out;
        }
    } catch (InvalidArgumentException $e) {
        throw new InvalidArgumentException("«{$label}»: " . $e->getMessage());
    }
    throw new InvalidArgumentException("«{$label}»: неизвестный тип поля");
}

// Телефон «8 927 203-73-73» -> ссылка +79272037373
function tel_from_display(string $s): string
{
    $d = preg_replace('~\D~', '', $s);
    if (strlen($d) === 11 && ($d[0] === '8' || $d[0] === '7')) return '+7' . substr($d, 1);
    if (strlen($d) === 10) return '+7' . $d;
    return '+' . $d;
}

// Значения полей раздела для формы
function section_values(array $fields, string $svc = ''): array
{
    $out = [];
    foreach ($fields as $fd) {
        if (empty($fd['bind'])) continue;
        $b = str_replace('{svc}', $svc, $fd['bind']);
        $out[$fd['bind']] = bind_get($b);
    }
    return $out;
}

// Применить присланные значения к документам. Возвращает [файл => новое содержимое] только для изменённых файлов.
function apply_values(array $fields, array $values, string $svc = ''): array
{
    load_site_data(); // для проверки ссылок на фото и услуги
    $docs = [];
    foreach (schema_binds($fields) as $bind => $fd) {
        if (!array_key_exists($bind, $values)) continue;
        $val = clean_value($fd, $values[$bind]);
        $real = str_replace('{svc}', $svc, $bind);
        [$file, $path] = split_bind($real);
        $docs[$file] ??= doc_get($file);
        if ($real === 'company.json#phones') {
            if (!$val) throw new InvalidArgumentException('Нужен хотя бы один телефон');
            $val = array_map(fn($p) => ['display' => $p['display'], 'tel' => tel_from_display($p['display'])], $val);
        }
        if ($fd['type'] === 'bool' && !$val) { unset_path($docs[$file], $path); continue; }
        // пустое значение там, где и раньше ничего не было, не записываем (null остаётся null, ключ не появляется)
        if (is_blank($val) && is_blank(arr_get($docs[$file], $path))) continue;
        arr_set($docs[$file], $path, $val);
    }
    return array_filter($docs, fn($d, $file) => $d !== doc_get($file), ARRAY_FILTER_USE_BOTH);
}

function is_blank($v): bool { return $v === null || $v === '' || $v === []; }

function unset_path(array &$a, array $path): void
{
    $last = array_pop($path);
    $ref = &$a;
    foreach ($path as $k) { if (!isset($ref[$k]) || !is_array($ref[$k])) return; $ref = &$ref[$k]; }
    unset($ref[$last]);
}

// Записать изменённые файлы и пересобрать сайт. Если сборка упала — вернуть файлы как были.
// $files: [относительный путь => данные или null (удалить файл)]
function commit_changes(array $files, string $who = 'admin'): array
{
    if (!$files) return ['changed' => 0, 'build' => null];
    return with_lock(function () use ($files, $who) {
        $before = [];
        foreach ($files as $rel => $data) {
            $f = data_path($rel);
            $before[$rel] = is_file($f) ? file_get_contents($f) : null;
        }
        try {
            foreach ($files as $rel => $data) {
                if ($data === null) { if ($before[$rel] !== null) { backup_revision($rel, $before[$rel], $who); unlink(data_path($rel)); } }
                else write_data_json($rel, $data, $who);
            }
            $build = build_site();
        } catch (Throwable $e) {
            foreach ($before as $rel => $raw) {
                if ($raw === null) @unlink(data_path($rel));
                else write_file_atomic(data_path($rel), $raw);
            }
            try { build_site(); } catch (Throwable $e2) { /* сайт остаётся в последнем собранном виде */ }
            throw new RuntimeException('Изменения не сохранены: ' . $e->getMessage());
        } finally {
            Docs::$cache = [];
        }
        return ['changed' => count($files), 'build' => $build];
    });
}

/**
 * Значение, которого нет в форме: возвращаем как было, лишь очищая строки и ограничивая
 * глубину и размер, чтобы в данные не попало что-то неожиданное.
 */
function passthrough_value($v, int $depth = 0)
{
    if ($depth > 4) throw new InvalidArgumentException('слишком глубокая структура данных');
    if (is_string($v)) return clean_text($v, ['type' => 'textarea']);
    if (is_scalar($v) || $v === null) return $v;
    if (!is_array($v)) throw new InvalidArgumentException('неподдерживаемое значение');
    if (count($v) > 500) throw new InvalidArgumentException('слишком длинный список');
    $out = [];
    foreach ($v as $k => $x) {
        if (is_string($k) && !preg_match('~^[A-Za-z0-9_-]{1,40}$~', $k)) throw new InvalidArgumentException('недопустимый ключ');
        $out[$k] = passthrough_value($x, $depth + 1);
    }
    return $out;
}
