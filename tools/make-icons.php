<?php
// Генерация растровых иконок и og-картинки. Текст не рисуем — локального TTF
// с кириллицей в проекте нет, а подключать внешний шрифт нельзя.
function logo_mark(int $size): \GdImage {
    $im = imagecreatetruecolor($size, $size);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    $amber = imagecolorallocate($im, 0xE8, 0x76, 0x1A);
    $ink = imagecolorallocate($im, 0x14, 0x17, 0x1A);
    $r = (int)round($size * 0.18);
    imagefilledrectangle($im, $r, 0, $size - $r, $size - 1, $amber);
    imagefilledrectangle($im, 0, $r, $size - 1, $size - $r, $amber);
    foreach ([[$r,$r],[$size-$r-1,$r],[$r,$size-$r-1],[$size-$r-1,$size-$r-1]] as [$cx,$cy]) {
        imagefilledellipse($im, $cx, $cy, $r*2, $r*2, $amber);
    }
    // стилизованная «Д» + вертикаль «С»: прямоугольники, без шрифта
    $u = $size / 40;
    imagefilledrectangle($im, (int)(9*$u), (int)(11*$u), (int)(13.6*$u), (int)(29*$u), $ink);
    imagefilledrectangle($im, (int)(13.6*$u), (int)(11*$u), (int)(21*$u), (int)(15*$u), $ink);
    imagefilledrectangle($im, (int)(13.6*$u), (int)(25*$u), (int)(21*$u), (int)(29*$u), $ink);
    imagefilledrectangle($im, (int)(21*$u), (int)(13*$u), (int)(26*$u), (int)(27*$u), $ink);
    imagefilledrectangle($im, (int)(28.5*$u), (int)(11*$u), (int)(32*$u), (int)(29*$u), $ink);
    return $im;
}
$out = __DIR__ . '/../public';
foreach ([16, 32, 180, 192, 512] as $s) {
    $im = logo_mark($s);
    $name = $s === 180 ? '/img/apple-touch-icon.png' : ($s <= 32 ? "/img/favicon-{$s}x{$s}.png" : "/img/icon-{$s}.png");
    imagepng($im, $out . $name, 9);
    imagedestroy($im);
}
// favicon.ico из 32×32 PNG (одно изображение, формат PNG-in-ICO понимают все браузеры)
$png = file_get_contents($out . '/img/favicon-32x32.png');
$ico = pack('vvv', 0, 1, 1) . pack('CCCCvvVV', 32, 32, 0, 0, 1, 32, strlen($png), 22) . $png;
file_put_contents($out . '/favicon.ico', $ico);

// og-картинка 1200×630: графитовый фон, янтарная полоса, знак.
$og = imagecreatetruecolor(1200, 630);
imagefill($og, 0, 0, imagecolorallocate($og, 0x1A, 0x1D, 0x21));
imagefilledrectangle($og, 0, 600, 1199, 629, imagecolorallocate($og, 0xE8, 0x76, 0x1A));
$grid = imagecolorallocate($og, 0x26, 0x2A, 0x2F);
for ($x = 0; $x < 1200; $x += 40) imageline($og, $x, 0, $x, 599, $grid);
for ($y = 0; $y < 600; $y += 40) imageline($og, 0, $y, 1199, $y, $grid);
$mark = logo_mark(260);
imagecopy($og, $mark, 80, 170, 0, 0, 260, 260);
imagepng($og, $out . '/img/og-default.png', 9);
echo "иконки и og-картинка готовы\n";
