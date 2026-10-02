<?php
/**
 * WebP-двойники для фотографий, которые реально используются на сайте.
 * Исходный файл остаётся на прежнем пути (старые адреса картинок в индексе не ломаются),
 * рядом появляется <путь>.webp — его отдаёт <picture>, а <img> остаётся запасным вариантом.
 * Запуск: php tools/make-webp.php
 */
declare(strict_types=1);
$root = dirname(__DIR__);
$pub = $root . '/public';

$used = [];
$add = function (?string $p) use (&$used) { if ($p && str_starts_with($p, '/upload/')) $used[$p] = true; };

foreach (glob($root . '/data/catalog/items/*.json') ?: [] as $f) {
    foreach (json_decode((string)file_get_contents($f), true) ?: [] as $i) {
        foreach ($i['photos'] ?? [] as $p) $add($p);
    }
}
foreach (json_decode((string)file_get_contents($root . '/data/catalog/sections.json'), true) ?: [] as $s) $add($s['photo'] ?? null);
foreach (['news.json', 'stock.json'] as $f) {
    foreach (json_decode((string)file_get_contents($root . '/data/' . $f), true) ?: [] as $x) {
        foreach ($x['photos'] ?? [] as $p) $add($p);
    }
}

$made = 0; $skip = 0; $fail = 0; $saved = 0;
foreach (array_keys($used) as $rel) {
    $src = $pub . $rel;
    if (!is_file($src)) { $fail++; continue; }
    $dst = $src . '.webp';
    if (is_file($dst) && filemtime($dst) >= filemtime($src)) { $skip++; continue; }
    $info = @getimagesize($src);
    $im = match ($info[2] ?? 0) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
        IMAGETYPE_PNG => @imagecreatefrompng($src),
        IMAGETYPE_WEBP => null,
        default => null,
    };
    if (!$im) { $fail++; continue; }
    imagepalettetotruecolor($im);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    // Шире 1600 px на сайте всё равно не показывается
    $w = imagesx($im); $h = imagesy($im);
    if (max($w, $h) > 1600) {
        $k = 1600 / max($w, $h);
        $nw = (int)round($w * $k); $nh = (int)round($h * $k);
        $re = imagecreatetruecolor($nw, $nh);
        imagealphablending($re, false); imagesavealpha($re, true);
        imagecopyresampled($re, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($im); $im = $re;
    }
    if (!@imagewebp($im, $dst, 80)) { imagedestroy($im); $fail++; continue; }
    imagedestroy($im);
    $saved += max(0, filesize($src) - filesize($dst));
    $made++;
}
printf("использованных фотографий: %d · создано webp: %d · уже были: %d · пропущено: %d · экономия: %.1f МБ\n",
    count($used), $made, $skip, $fail, $saved / 1048576);
