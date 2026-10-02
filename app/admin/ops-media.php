<?php
// Медиатека. Идентификатор фото — его путь на сайте (/upload/media/... или /upload/iblock/...),
// поэтому в карточках товаров и на страницах хранится ровно то значение, которое видит браузер.
declare(strict_types=1);

function media_dir(): string { return rtrim((string)cfg('public_dir'), '/\\') . '/upload/media'; }

function photo_cat_labels(): array
{
    $labels = doc_get('structure.json')['photoCats'] ?? ['media' => 'Загруженные фото', 'old-site' => 'Со старого сайта'];
    foreach (doc_get('photos.json') as $key => $_) $labels[$key] ??= $key;
    return $labels;
}

/** Где используется фото: карточки товаров, разделы, новости, спецпредложения, страницы. */
function photo_usage(string $id): array
{
    $uses = [];
    foreach (doc_get('catalog/sections.json') as $s) {
        if (($s['photo'] ?? '') === $id) $uses[] = 'раздел «' . $s['name'] . '»';
    }
    foreach (glob(rtrim((string)cfg('data_dir'), '/\\') . '/catalog/items/*.json') ?: [] as $f) {
        foreach (read_json($f) as $i) {
            if (in_array($id, $i['photos'] ?? [], true)) $uses[] = 'позиция «' . $i['name'] . '»';
        }
    }
    foreach (['news.json' => 'новость', 'stock.json' => 'спецпредложение'] as $file => $what) {
        foreach (doc_get($file) as $x) {
            if (in_array($id, $x['photos'] ?? [], true)) $uses[] = $what . ' «' . ($x['title'] ?? '') . '»';
        }
    }
    foreach (doc_get('pages.json') as $key => $p) {
        foreach ($p['items'] ?? [] as $x) if (($x['photo'] ?? '') === $id) $uses[] = 'страница «' . ($p['h1'] ?? $key) . '»';
    }
    return $uses;
}

function photos_payload(): array
{
    $cats = [];
    $labels = photo_cat_labels();
    foreach (doc_get('photos.json') as $key => $list) {
        $cats[] = ['key' => $key, 'label' => $labels[$key] ?? $key, 'photos' => $list];
    }
    return ['cats' => $cats, 'arts' => [], 'base' => S::$base, 'pathIsId' => true];
}

function next_photo_path(string $cat): string
{
    $max = 0;
    foreach (doc_get('photos.json')[$cat] ?? [] as $p) {
        if (preg_match('~-(\d+)\.webp$~', (string)$p['id'], $m)) $max = max($max, (int)$m[1]);
    }
    do { $name = $cat . '-' . str_pad((string)++$max, 3, '0', STR_PAD_LEFT) . '.webp'; } while (is_file(media_dir() . '/' . $name));
    return '/upload/media/' . $name;
}

/** Загрузка фото: поворот по EXIF, уменьшение до 1600 px, перевод в WebP. */
function photo_upload(string $cat, array $file, string $alt): array
{
    if (!isset(doc_get('photos.json')[$cat])) throw new InvalidArgumentException('Папка не найдена');
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException(($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'Файл слишком большой' : 'Файл не загрузился');
    }
    if (($file['size'] ?? 0) > 30 * 1024 * 1024) throw new InvalidArgumentException('Файл больше 30 МБ');
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpeg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($types[$info[2]])) throw new InvalidArgumentException('Нужна картинка JPG, PNG или WebP');
    if ($info[0] * $info[1] > 60_000_000) throw new InvalidArgumentException('Слишком большое разрешение');
    @ini_set('memory_limit', '512M');
    $src = match ($types[$info[2]]) {
        'jpeg' => @imagecreatefromjpeg($file['tmp_name']),
        'png' => @imagecreatefrompng($file['tmp_name']),
        'webp' => @imagecreatefromwebp($file['tmp_name']),
    };
    if (!$src) throw new InvalidArgumentException('Не удалось прочитать картинку');
    if ($types[$info[2]] === 'jpeg' && function_exists('exif_read_data')) {
        $o = (int)(@exif_read_data($file['tmp_name'])['Orientation'] ?? 1);
        $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($rot) { $r = imagerotate($src, $rot, 0); if ($r) { imagedestroy($src); $src = $r; } }
    }
    $path = next_photo_path($cat);
    ensure_dir(media_dir());
    [$w, $h] = webp_fit($src, 1600, 78, rtrim((string)cfg('public_dir'), '/\\') . $path);
    imagedestroy($src);
    $entry = ['id' => $path, 'w' => $w, 'h' => $h, 'alt' => mb_substr(trim($alt) ?: 'Фото', 0, 300)];
    $photos = doc_get('photos.json');
    $photos[$cat][] = $entry;
    try { commit_changes(['photos.json' => $photos]); }
    catch (Throwable $e) { @unlink(rtrim((string)cfg('public_dir'), '/\\') . $path); throw $e; }
    return $entry;
}

function webp_fit($src, int $max, int $q, string $dest): array
{
    $w = imagesx($src); $h = imagesy($src);
    $k = min(1, $max / max($w, $h));
    $nw = max(1, (int)round($w * $k)); $nh = max(1, (int)round($h * $k));
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false); imagesavealpha($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    ensure_dir(dirname($dest));
    $tmp = $dest . '.tmp';
    if (!imagewebp($dst, $tmp, $q)) throw new RuntimeException('Не удалось сохранить WebP');
    imagedestroy($dst);
    rename($tmp, $dest);
    return [$nw, $nh];
}

function photo_update(string $id, array $v): void
{
    $photos = doc_get('photos.json');
    foreach ($photos as $cat => $list) {
        foreach ($list as $i => $p) {
            if ($p['id'] !== $id) continue;
            $p['alt'] = mb_substr(trim((string)($v['alt'] ?? $p['alt'])), 0, 300) ?: $p['alt'];
            $to = (string)($v['cat'] ?? $cat);
            if (!isset($photos[$to])) throw new InvalidArgumentException('Папка не найдена');
            if ($to === $cat) $photos[$cat][$i] = $p;
            else { array_splice($photos[$cat], $i, 1); $photos[$to][] = $p; }
            commit_changes(['photos.json' => $photos]);
            return;
        }
    }
    throw new InvalidArgumentException('Фото не найдено');
}

function photo_move(string $id, int $dir): void
{
    $photos = doc_get('photos.json');
    foreach ($photos as $cat => $list) {
        foreach ($list as $i => $p) {
            if ($p['id'] !== $id) continue;
            $j = $i + $dir;
            if ($j < 0 || $j >= count($list)) return;
            [$photos[$cat][$i], $photos[$cat][$j]] = [$photos[$cat][$j], $photos[$cat][$i]];
            commit_changes(['photos.json' => $photos]);
            return;
        }
    }
}

function photo_delete(string $id): void
{
    if ($uses = photo_usage($id)) {
        throw new InvalidArgumentException('Фото используется: ' . implode(', ', array_slice(array_unique($uses), 0, 5)) . '. Сначала замените его там.');
    }
    $photos = doc_get('photos.json');
    foreach ($photos as $cat => $list) {
        foreach ($list as $i => $p) {
            if ($p['id'] !== $id) continue;
            array_splice($photos[$cat], $i, 1);
            $abs = rtrim((string)cfg('public_dir'), '/\\') . $id;
            $fn = basename($id);
            $files = is_file($abs) ? [$fn] : [];
            $tid = trash_put('photo', $id, $p['alt'], ['entry' => $p, 'cat' => $cat, 'files' => $files, 'path' => $id]);
            if ($files) rename($abs, storage_path("trash/$tid/$fn"));
            try { commit_changes(['photos.json' => $photos]); }
            catch (Throwable $e) {
                if ($files) rename(storage_path("trash/$tid/$fn"), $abs);
                trash_forget($tid);
                throw $e;
            }
            return;
        }
    }
    throw new InvalidArgumentException('Фото не найдено');
}

function photo_cat_add(string $label): string
{
    $label = trim($label);
    if (mb_strlen($label) < 2) throw new InvalidArgumentException('Введите название папки');
    $key = translit_slug($label);
    $photos = doc_get('photos.json');
    $n = 2; $base = $key;
    while (isset($photos[$key])) $key = $base . '-' . $n++;
    $photos[$key] = [];
    $st = doc_get('structure.json');
    $st['photoCats'][$key] = $label;
    commit_changes(['photos.json' => $photos, 'structure.json' => $st]);
    return $key;
}
