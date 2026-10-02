<?php
// Операции админки: каталог, медиатека, корзина, история правок, заявки, настройки.
declare(strict_types=1);

// =====================================================================
// ОБЩЕЕ
// =====================================================================
function translit_slug(string $s): string
{
    static $map = ['а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'j', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya', '×' => 'kh'];
    $s = strtr(mb_strtolower($s), $map);
    $s = trim((string)preg_replace('~[^a-z0-9]+~', '-', $s), '-');
    return substr($s, 0, 70) ?: 'pozicija';
}

require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/ops-media.php';

// =====================================================================
// КОРЗИНА: удалённое не стирается, а переезжает сюда. Автоочистки нет.
// =====================================================================
function trash_index(): array
{
    $f = storage_path('trash/index.json');
    return is_file($f) ? read_json($f) : [];
}
function trash_save_index(array $idx): void { write_file_atomic(storage_path('trash/index.json'), json_pretty(array_values($idx))); }

function trash_put(string $type, string $ref, string $title, array $payload): string
{
    $tid = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    ensure_dir(storage_path("trash/$tid"));
    write_file_atomic(storage_path("trash/$tid/item.json"), json_pretty(['type' => $type, 'ref' => $ref, 'title' => $title] + $payload));
    $idx = trash_index();
    array_unshift($idx, ['id' => $tid, 'type' => $type, 'ref' => $ref, 'title' => $title, 'date' => date('c')]);
    trash_save_index($idx);
    return $tid;
}
function trash_forget(string $tid): void { trash_save_index(array_filter(trash_index(), fn($t) => $t['id'] !== $tid)); }
function trash_find(string $type, string $ref): ?array
{
    foreach (trash_index() as $t) if ($t['type'] === $type && $t['ref'] === $ref) return $t;
    return null;
}

function trash_restore(string $tid): string
{
    if (!preg_match('~^[0-9a-f-]+$~', $tid) || !is_file(storage_path("trash/$tid/item.json"))) throw new InvalidArgumentException('Не найдено в корзине');
    $item = read_json(storage_path("trash/$tid/item.json"));
    if ($item['type'] === 'catsec') {
        $sections = doc_get('catalog/sections.json');
        if (in_array($item['section']['url'], array_column($sections, 'url'), true)) throw new InvalidArgumentException('Раздел с таким адресом уже есть');
        $sections[$item['ref']] = $item['section'];
        uasort($sections, fn($a, $b) => strcmp($a['url'], $b['url']));
        commit_changes(['catalog/sections.json' => $sections, catsec_file($item['ref']) => $item['items'] ?? []]);
        $msg = 'Раздел каталога возвращён.';
    } else {
        $photos = doc_get('photos.json');
        $cat = isset($photos[$item['cat']]) ? $item['cat'] : array_key_first($photos);
        foreach ($item['files'] as $fn) rename(storage_path("trash/$tid/$fn"), media_dir() . "/$fn");
        $photos[$cat][] = $item['entry'];
        commit_changes(['photos.json' => $photos]);
        $msg = 'Фото возвращено в медиатеку.';
    }
    trash_forget($tid);
    rrmdir(storage_path("trash/$tid"));
    return $msg;
}

// =====================================================================
// ИСТОРИЯ ПРАВОК
// =====================================================================
function file_label(string $rel): string
{
    static $names = [
        'pages.json' => 'Тексты страниц', 'company.json' => 'Компания и контакты',
        'structure.json' => 'Меню и подвал', 'photos.json' => 'Медиатека',
        'services.json' => 'Услуги', 'tehnika.json' => 'Модели техники',
        'news.json' => 'Новости и отгрузки', 'articles.json' => 'База знаний',
        'faq.json' => 'Вопрос-ответ', 'stock.json' => 'Спецпредложения',
        'vacancies.json' => 'Вакансии', 'price.json' => 'Прайс-лист',
        'legal.json' => 'Юридические тексты', 'units.json' => 'Узлы оборудования',
        'catalog/sections.json' => 'Разделы каталога',
    ];
    if (isset($names[$rel])) return $names[$rel];
    if (str_starts_with($rel, 'catalog/items/')) {
        $key = basename($rel, '.json');
        foreach (doc_get('catalog/sections.json') as $s) if (catsec_key($s['url']) === $key) return 'Каталог: ' . $s['name'];
        return 'Каталог: ' . $key;
    }
    return $rel;
}

function revisions_list(int $limit = 200): array
{
    $out = [];
    foreach (glob(storage_path('revisions/*'), GLOB_ONLYDIR) ?: [] as $dir) {
        $rel = str_replace('__', '/', basename($dir));
        foreach (glob("$dir/*.json") ?: [] as $f) {
            $name = basename($f, '.json');
            if (!preg_match('~^(\d{4}-\d\d-\d\d)_(\d\d)-(\d\d)-(\d\d)~', $name, $m)) continue;
            $out[] = ['id' => basename($dir) . '/' . $name, 'file' => $rel, 'label' => file_label($rel), 'date' => "{$m[1]} {$m[2]}:{$m[3]}:{$m[4]}"];
        }
    }
    usort($out, fn($a, $b) => strcmp($b['date'], $a['date']));
    return array_slice($out, 0, $limit);
}

// Вернуть версию файла, какой она была ДО указанной правки
function revision_restore(string $id): void
{
    if (!preg_match('~^([a-z0-9_.-]+)/([0-9_a-z-]+)$~i', $id, $m)) throw new InvalidArgumentException('Неверная версия');
    $f = storage_path("revisions/{$m[1]}/{$m[2]}.json");
    if (!is_file($f)) throw new InvalidArgumentException('Версия не найдена');
    $rel = str_replace('__', '/', $m[1]);
    $data = read_json($f);
    commit_changes([$rel => $data], 'restore');
}

// =====================================================================
// ЗАЯВКИ (пишет app/lead.php)
// =====================================================================
function leads_list(int $limit = 300): array
{
    $out = [];
    $files = glob(storage_path('leads/*.jsonl')) ?: [];
    rsort($files);
    foreach ($files as $f) {
        $lines = array_reverse(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        foreach ($lines as $l) { $j = json_decode($l, true); if ($j) $out[] = $j; if (count($out) >= $limit) break 2; }
    }
    return $out;
}

// =====================================================================
// НАСТРОЙКИ (storage/settings.json — не публикуется и не попадает в git)
// =====================================================================
function settings_get(): array
{
    $f = storage_path('settings.json');
    $s = is_file($f) ? read_json($f) : [];
    return $s + ['leadEmails' => [], 'tgToken' => '', 'tgChats' => [], 'mailFrom' => ''];
}
function settings_save(array $v): void
{
    $emails = array_values(array_filter(array_map('trim', (array)($v['leadEmails'] ?? []))));
    foreach ($emails as $e) if (!filter_var($e, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException("Неверный адрес почты: $e");
    $chats = array_values(array_filter(array_map('trim', (array)($v['tgChats'] ?? []))));
    foreach ($chats as $c) if (!preg_match('~^-?\d{3,20}$~', $c)) throw new InvalidArgumentException("Chat ID — это число, например 123456789 или -1001234567890");
    $token = trim((string)($v['tgToken'] ?? ''));
    if ($token === '••••••') $token = settings_get()['tgToken'];
    if ($token !== '' && !preg_match('~^\d{5,15}:[A-Za-z0-9_-]{30,}$~', $token)) throw new InvalidArgumentException('Токен бота выглядит неверно');
    $from = trim((string)($v['mailFrom'] ?? ''));
    if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Неверный адрес отправителя');
    write_file_atomic(storage_path('settings.json'), json_pretty(['leadEmails' => $emails, 'tgToken' => $token, 'tgChats' => $chats, 'mailFrom' => $from]));
}
