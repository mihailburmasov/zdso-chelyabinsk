<?php
/**
 * Проверка письма с заявкой без почтового сервера: php tools/check-mail.php
 * Собирает письмо так же, как это делает приём заявок, сохраняет .eml и разбирает его:
 * есть ли текст, приложены ли файлы, не превышен ли лимит на вложения.
 */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/admin/content.php';
require __DIR__ . '/../app/admin/ops.php';
require __DIR__ . '/../app/lead-deliver.php';

$tmp = sys_get_temp_dir() . '/zdso-mail';
@mkdir($tmp, 0777, true);

file_put_contents("$tmp/chertezh.dwg", 'AC1032' . str_repeat("\0", 2048));
file_put_contents("$tmp/spec.pdf", "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");
file_put_contents("$tmp/ogromnyy.zip", str_repeat('x', 11 * 1024 * 1024)); // больше лимита

$text = "Запрос КП на деталь: Плита дробящая подвижная 4844802022\nИмя: Пётр\nТелефон: +7 900 000-00-00";
$files = [
    ['name' => 'чертёж.dwg', 'path' => "$tmp/chertezh.dwg", 'size' => filesize("$tmp/chertezh.dwg")],
    ['name' => 'спецификация.pdf', 'path' => "$tmp/spec.pdf", 'size' => filesize("$tmp/spec.pdf")],
];

$problems = [];

/* ---- письмо без вложений ---- */
[$h0, $b0, $s0] = lead_mail_build('Вопрос менеджеру', $text, [], 'noreply@masterskie174.ru');
if (!str_contains($h0, 'Content-Type: text/plain')) $problems[] = 'письмо без вложений не text/plain';
if (base64_decode(str_replace(["\r\n", "\n"], '', $b0)) !== $text) $problems[] = 'текст письма без вложений не совпал';

/* ---- письмо с вложениями ---- */
[$h, $b, $subj] = lead_mail_build('Запрос КП на деталь', $text, $files, 'noreply@masterskie174.ru');
preg_match('~boundary="([^"]+)"~', $h, $m);
$boundary = $m[1] ?? '';
if (!$boundary) $problems[] = 'в заголовках нет boundary';
if (!str_contains($h, 'multipart/mixed')) $problems[] = 'письмо с вложениями не multipart/mixed';

$eml = $h . "\r\nSubject: $subj\r\nTo: info@zdso.ru\r\n\r\n" . $b;
file_put_contents("$tmp/lead.eml", $eml);

$parts = array_values(array_filter(explode("--$boundary", $b), fn($p) => trim($p) !== '' && trim($p) !== '--'));
if (count($parts) !== 3) $problems[] = 'частей в письме ' . count($parts) . ', ожидалось 3 (текст и два файла)';

$decodedText = base64_decode(preg_replace('~^.*?\r\n\r\n~s', '', $parts[0] ?? ''));
if (trim($decodedText) !== trim($text)) $problems[] = 'текст в первой части письма не совпал';

foreach (array_slice($parts, 1) as $i => $p) {
    $want = $files[$i];
    if (!str_contains($p, 'Content-Disposition: attachment')) $problems[] = "часть " . ($i + 2) . ": нет Content-Disposition: attachment";
    if (!str_contains($p, '=?UTF-8?B?' . base64_encode($want['name']) . '?=')) $problems[] = "часть " . ($i + 2) . ": имя файла «{$want['name']}» не закодировано";
    $bin = base64_decode(preg_replace('~^.*?\r\n\r\n~s', '', $p));
    if ($bin !== file_get_contents($want['path'])) $problems[] = "часть " . ($i + 2) . ": содержимое «{$want['name']}» не совпало";
}

/* ---- лимит на вложения ---- */
$big = array_merge($files, [['name' => 'огромный.zip', 'path' => "$tmp/ogromnyy.zip", 'size' => filesize("$tmp/ogromnyy.zip")]]);
[$hb, $bb] = lead_mail_build('Прислать чертёж', $text, $big, 'noreply@masterskie174.ru');
if (str_contains($bb, base64_encode(str_repeat('x', 1024)))) $problems[] = 'слишком большой файл всё-таки попал в письмо';
if (!str_contains(base64_decode(preg_replace('~^.*?\r\n\r\n~s', '', explode("--", $bb, 3)[1] ?? '')), 'Не приложены к письму')) {
    $problems[] = 'в письме нет предупреждения о том, что крупный файл не приложен';
}
$size = strlen($bb);
if ($size > 12 * 1024 * 1024) $problems[] = 'письмо получилось ' . round($size / 1048576, 1) . ' МБ — лимит вложений не сработал';

echo "письмо без вложений: " . strlen($b0) . " байт\n";
echo "письмо с двумя вложениями: " . strlen($b) . " байт, частей: " . count($parts) . "\n";
echo "письмо с файлом сверх лимита: " . round($size / 1024, 1) . " КБ (большой файл не приложен)\n";
echo "образец сохранён: $tmp/lead.eml\n";

foreach (['chertezh.dwg', 'spec.pdf', 'ogromnyy.zip'] as $f) @unlink("$tmp/$f");

if ($problems) {
    echo "\nПРОБЛЕМЫ (" . count($problems) . "):\n  " . implode("\n  ", $problems) . "\n";
    exit(1);
}
echo "\nПисьмо собирается корректно: текст на месте, вложения приложены целиком, лимит соблюдён.\n";
