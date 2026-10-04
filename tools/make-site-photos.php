<?php
/**
 * Фотографии оформления сайта, логотип и иконки.
 * Запуск: php tools/make-site-photos.php
 *
 * Источники:
 *   - фотографии заказчика — со старого сайта, лежат в public/upload/iblock;
 *   - стоковые фото с лицензией CC0 — в tools/src/photos (откуда и на каких условиях взяты: docs/PHOTOS.md);
 *   - логотип — tools/src/logo.png, взят с masterskie174.ru/logo.png.
 *
 * Результат — public/img/bg/*.webp и *.jpg в нескольких ширинах, public/img/logo*.png|webp,
 * иконки и og-картинка. Всё это код (деплой заливает public/img), а не контент клиента.
 */
declare(strict_types=1);
$root = dirname(__DIR__);
$pub = $root . '/public';
$src = $root . '/tools/src';
@mkdir($pub . '/img/bg', 0775, true);

function load(string $f): GdImage
{
    $im = @imagecreatefromstring((string)file_get_contents($f));
    if (!$im) throw new RuntimeException("не читается: $f");
    imagepalettetotruecolor($im);
    return $im;
}

/** Обрезка под пропорцию $ratio (ш/в) с точкой фокуса $fx,$fy (0…1) и уменьшение до ширины $w. */
function crop_to(GdImage $im, float $ratio, int $w, float $fx = .5, float $fy = .5): GdImage
{
    $sw = imagesx($im); $sh = imagesy($im);
    if ($sw / $sh > $ratio) { $ch = $sh; $cw = (int)round($sh * $ratio); }
    else { $cw = $sw; $ch = (int)round($sw / $ratio); }
    $x = (int)round(max(0, min($sw - $cw, $fx * $sw - $cw / 2)));
    $y = (int)round(max(0, min($sh - $ch, $fy * $sh - $ch / 2)));
    $w = min($w, $cw); // не растягиваем
    $h = (int)round($w / $ratio);
    $out = imagecreatetruecolor($w, $h);
    imagecopyresampled($out, $im, 0, 0, $x, $y, $w, $h, $cw, $ch);
    return $out;
}

$total = 0;
function save(GdImage $im, string $base, int $q = 74): void
{
    global $total;
    imagewebp($im, $base . '.webp', $q);
    imagejpeg($im, $base . '.jpg', $q + 4);
    $total += filesize($base . '.webp');
}

$up = $pub . '/upload/iblock/';
// имя => [файл, пропорция, ширины, фокус x, фокус y]
$jobs = [
    // первый экран главной: дробильно-сортировочный комплекс (фото заказчика)
    'hero'        => [$up . '1d1/1d18e327b72a1cf33b9888963aef687c.jpeg', 3 / 2, [1000, 640], .5, .5],
    // полосы внутренних страниц: широкие, 16:5
    'catalog'     => [$up . '02d/02d2570bcbbd93f200851a34e8e5588f.JPG', 16 / 5, [1024, 640], .5, .45],
    'services'    => [$up . 'dee/deec09ee432ad191f0b39a1561af5eb8.jpg', 16 / 5, [929, 640], .5, .5],
    'company'     => [$up . 'a34/a34ae086fc4eed9aba151e35def03e0d.jpg', 16 / 5, [1024, 640], .5, .5],
    'tehnika'     => [$src . '/photos/open-pit.jpg', 16 / 5, [1600, 1024, 640], .5, .55, 58],
    'price'       => [$src . '/photos/crushed-stone.jpg', 16 / 5, [1600, 1024, 640], .5, .5, 58],
    'contacts'    => [$src . '/photos/open-pit-green.jpg', 16 / 5, [1600, 1024, 640], .5, .72, 58],
    'info'        => [$src . '/photos/conveyor-plant.jpg', 16 / 5, [1600, 1024, 640], .5, .4, 58],
    'pages'       => [$src . '/photos/conveyor-tower.jpg', 16 / 5, [1600, 1024, 640], .5, .45, 58],
    // карточки «Производство» на главной, 4:3 (фото заказчика)
    'pr-workshop' => [$up . 'a34/a34ae086fc4eed9aba151e35def03e0d.jpg', 4 / 3, [640], .5, .5],
    'pr-casting'  => [$up . 'dee/deec09ee432ad191f0b39a1561af5eb8.jpg', 4 / 3, [640], .5, .5],
    'pr-sieve'    => [$up . '261/261c08ba0480a8498075666482e73653.jpg', 4 / 3, [640], .5, .6],
    'pr-plate'    => [$up . '02d/02d2570bcbbd93f200851a34e8e5588f.JPG', 4 / 3, [640], .5, .5],
    'pr-cone'     => [$up . '355/355fa7028d9fed980f18a031ae09172a.JPG', 4 / 3, [640], .5, .5],
    'pr-plant'    => [$up . '1d1/1d18e327b72a1cf33b9888963aef687c.jpeg', 4 / 3, [640], .5, .5],
];
foreach ($jobs as $name => $j) {
    [$file, $ratio, $widths, $fx, $fy] = $j;
    $q = $j[5] ?? 74;
    $im = load($file);
    foreach ($widths as $w) {
        $c = crop_to($im, $ratio, $w, $fx, $fy);
        save($c, $pub . '/img/bg/' . $name . '-' . $w, $q);
        imagedestroy($c);
    }
    imagedestroy($im);
}

/* ---------------------------------------------------------------- логотип */
$logo = load($src . '/logo.png');
imagealphablending($logo, false); imagesavealpha($logo, true);
$lw = imagesx($logo); $lh = imagesy($logo);
foreach ([164, 328] as $w) {
    $h = (int)round($lh * $w / $lw);
    $o = imagecreatetruecolor($w, $h);
    imagealphablending($o, false); imagesavealpha($o, true);
    imagefill($o, 0, 0, imagecolorallocatealpha($o, 0, 0, 0, 127));
    imagecopyresampled($o, $logo, 0, 0, 0, 0, $w, $h, $lw, $lh);
    imagepng($o, $pub . "/img/logo-$w.png", 9);
    imagewebp($o, $pub . "/img/logo-$w.webp", 90);
    imagedestroy($o);
}
copy($src . '/logo.png', $pub . '/img/logo.png'); // полноразмерный — для микроразметки Organization

/* ----------------------------------------------------------------- иконки */
// Знак для иконок — красная «О» из логотипа (x 450…650, y 6…176) на белом скруглённом квадрате:
// всё слово «ДСО» в 16 px не читается.
function icon_mark(GdImage $logo, int $size, bool $bg = true): GdImage
{
    $im = imagecreatetruecolor($size, $size);
    imagealphablending($im, false); imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagealphablending($im, true);
    if ($bg) {
        $white = imagecolorallocate($im, 255, 255, 255);
        $r = (int)round($size * .2);
        imagefilledrectangle($im, $r, 0, $size - $r - 1, $size - 1, $white);
        imagefilledrectangle($im, 0, $r, $size - 1, $size - $r - 1, $white);
        foreach ([[$r, $r], [$size - $r - 1, $r], [$r, $size - $r - 1], [$size - $r - 1, $size - $r - 1]] as [$cx, $cy]) {
            imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $white);
        }
    }
    // В прямоугольник «О» заходит край чёрной «С» — берём только красные пиксели.
    static $o = null;
    if (!$o) {
        $o = imagecreatetruecolor(200, 170);
        imagealphablending($o, false); imagesavealpha($o, true);
        $clear = imagecolorallocatealpha($o, 0, 0, 0, 127);
        for ($y = 0; $y < 170; $y++) for ($x = 0; $x < 200; $x++) {
            $c = imagecolorat($logo, 450 + $x, 6 + $y);
            $red = (($c >> 16) & 255) - (($c >> 8) & 255) > 60;
            imagesetpixel($o, $x, $y, $red ? $c : $clear);
        }
    }
    $pad = $bg ? $size * .12 : 0;
    $cw = 200; $ch = 170;
    $tw = $size - 2 * $pad; $th = $tw * $ch / $cw;
    imagecopyresampled($im, $o, (int)round($pad), (int)round(($size - $th) / 2), 0, 0, (int)round($tw), (int)round($th), $cw, $ch);
    return $im;
}
foreach ([16, 32, 180, 192, 512] as $s) {
    $im = icon_mark($logo, $s, $s > 32);
    $name = $s === 180 ? '/img/apple-touch-icon.png' : ($s <= 32 ? "/img/favicon-{$s}x{$s}.png" : "/img/icon-{$s}.png");
    imagepng($im, $pub . $name, 9);
    imagedestroy($im);
}
$png = file_get_contents($pub . '/img/favicon-32x32.png');
file_put_contents($pub . '/favicon.ico', pack('vvv', 0, 1, 1) . pack('CCCCvvVV', 32, 32, 0, 0, 1, 32, strlen($png), 22) . $png);
// favicon.svg — тот же знак, растр 64 px внутри SVG (вектора логотипа у заказчика нет)
$im = icon_mark($logo, 64, false);
ob_start(); imagepng($im, null, 9); $b64 = base64_encode((string)ob_get_clean()); imagedestroy($im);
file_put_contents($pub . '/favicon.svg', '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 64 64">'
    . '<image width="64" height="64" xlink:href="data:image/png;base64,' . $b64 . '"/></svg>');

// og-картинка 1200×630: фото комплекса, белая плашка с логотипом, синяя полоса
$og = crop_to(load($up . '1d1/1d18e327b72a1cf33b9888963aef687c.jpeg'), 1200 / 630, 1200);
if (imagesx($og) < 1200) { $t = imagecreatetruecolor(1200, 630); imagecopyresampled($t, $og, 0, 0, 0, 0, 1200, 630, imagesx($og), imagesy($og)); $og = $t; }
imagealphablending($og, true);
imagefilledrectangle($og, 0, 0, 1199, 629, imagecolorallocatealpha($og, 0x12, 0x4c, 0x91, 70));
imagefilledrectangle($og, 0, 600, 1199, 629, imagecolorallocate($og, 0xE8, 0x76, 0x1A));
imagefilledrectangle($og, 80, 200, 640, 400, imagecolorallocate($og, 255, 255, 255));
imagecopyresampled($og, $logo, 120, 238, 0, 0, 480, (int)round(480 * $lh / $lw), $lw, $lh);
imagepng($og, $pub . '/img/og-default.png', 9);

printf("фото оформления: %d шт., webp %.0f КБ всего; логотип, иконки и og-картинка готовы\n", count($jobs), $total / 1024);
