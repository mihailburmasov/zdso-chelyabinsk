<?php
/**
 * Какие MIME-типы определяет этот сервер для файлов, которые присылают снабженцы.
 * Запуск: php tools/check-mime.php
 * Чёрный список типов — в app/lead.php (константа LEAD_MIME_DENY).
 * Запускать на хостинге тоже: база magic там своя и может отличаться.
 */
declare(strict_types=1);
$tmp = sys_get_temp_dir() . '/zdso-mime';
@mkdir($tmp, 0777, true);

$samples = [];

// PDF
file_put_contents("$tmp/a.pdf", "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");
$samples['pdf'] = "$tmp/a.pdf";

// DWG: подпись версии в первых шести байтах
foreach (['AC1015' => 'dwg-2000', 'AC1021' => 'dwg-2007', 'AC1027' => 'dwg-2013', 'AC1032' => 'dwg-2018'] as $magic => $label) {
    file_put_contents("$tmp/$label.dwg", $magic . str_repeat("\0", 128));
    $samples[$label] = "$tmp/$label.dwg";
}

// DXF — обычный текст
file_put_contents("$tmp/a.dxf", "0\nSECTION\n2\nHEADER\n0\nENDSEC\n0\nEOF\n");
$samples['dxf'] = "$tmp/a.dxf";

// JPEG и PNG — рисуем через GD
$im = imagecreatetruecolor(8, 8);
imagejpeg($im, "$tmp/a.jpg", 80);
imagepng($im, "$tmp/a.png");
imagewebp($im, "$tmp/a.webp");
imagedestroy($im);
$samples['jpg'] = "$tmp/a.jpg";
$samples['png'] = "$tmp/a.png";
$samples['webp'] = "$tmp/a.webp";

// XLSX и ZIP
if (class_exists('ZipArchive')) {
    $z = new ZipArchive();
    $z->open("$tmp/a.xlsx", ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $z->addFromString('xl/workbook.xml', '<workbook/>');
    $z->close();
    $samples['xlsx'] = "$tmp/a.xlsx";

    $z = new ZipArchive();
    $z->open("$tmp/a.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString('readme.txt', 'test');
    $z->close();
    $samples['zip'] = "$tmp/a.zip";
}

// CSV
file_put_contents("$tmp/a.csv", "номер;наименование\n4844802022;Плита\n");
$samples['csv'] = "$tmp/a.csv";

// Чёрный список берём прямо из обработчика заявок, чтобы он не разъехался с проверкой
$src = (string)file_get_contents(__DIR__ . '/../app/lead.php');
preg_match('~const LEAD_MIME_DENY = \[(.*?)\];~s', $src, $m);
preg_match_all("~'([^']+)'~", $m[1] ?? '', $mm);
$deny = $mm[1] ?? [];

$fi = finfo_open(FILEINFO_MIME_TYPE);
$bad = [];
printf("%-12s %-16s %s\n", 'тип', 'что определилось', 'проходит проверку');
foreach ($samples as $label => $path) {
    $mime = (string)finfo_file($fi, $path);
    $ok = !in_array($mime, $deny, true);
    printf("%-12s %-40s %s\n", $label, $mime, $ok ? 'да' : 'НЕТ');
    if (!$ok) $bad[$label] = $mime;
}
finfo_close($fi);

// Отдельно убеждаемся, что опасное действительно отсекается
file_put_contents("$tmp/x.php", "<?php echo 1;");
file_put_contents("$tmp/x.html", "<html><body><h1>hi</h1></body></html>");
$fi2 = finfo_open(FILEINFO_MIME_TYPE);
foreach (['x.php', 'x.html'] as $n) {
    $t = (string)finfo_file($fi2, "$tmp/$n");
    $blocked = in_array($t, $deny, true);
    printf("%-12s %-40s %s\n", $n, $t, $blocked ? 'отсекается — верно' : 'ПРОХОДИТ — плохо');
    if (!$blocked) $bad[$n] = $t;
    @unlink("$tmp/$n");
}
finfo_close($fi2);

foreach ($samples as $p) @unlink($p);
@rmdir($tmp);

if ($bad) {
    echo "\nПРОБЛЕМЫ — поправьте LEAD_MIME_DENY в app/lead.php:\n";
    foreach ($bad as $k => $t) echo "    $k → $t\n";
    exit(1);
}
echo "\nФорматы снабженцев проходят, исполняемое и веб-страницы отсекаются.\n";
