<?php
// Отправка заявки на почту и в Telegram. Настройки — в админке (storage/settings.json).
// Вложения (чертежи, спецификации, фото бирки) уходят вместе с заявкой:
// письмом — как вложения MIME, в Telegram — отдельными документами.
declare(strict_types=1);

/** Больше этого объёма вложений в письмо не кладём: почтовые серверы такие письма режут. */
const MAIL_ATTACH_LIMIT = 10 * 1024 * 1024;

/**
 * @param string $text  текст заявки
 * @param string $subject тема письма
 * @param array  $files  [['name' => 'чертёж.pdf', 'path' => '/абсолютный/путь', 'size' => 12345], …]
 */
function lead_deliver(string $text, string $subject, array $files = []): array
{
    $set = settings_get();
    $res = [];

    $emails = $set['leadEmails'];
    if (!$emails) {
        $c = read_json(cfg('data_dir') . '/company.json');
        if (!empty($c['email'])) $emails = [$c['email']];
    }
    if ($emails && function_exists('mail')) {
        $host = (string)preg_replace('~[^a-z0-9.-]~i', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
        $from = $set['mailFrom'] ?: 'noreply@' . preg_replace('~^www\.~', '', $host);
        $res['mail'] = lead_mail($emails, $subject, $text, $files, $from);
    }

    if ($set['tgToken'] && $set['tgChats']) {
        foreach ($set['tgChats'] as $chat) {
            $res['tg'][$chat] = tg_send($set['tgToken'], $chat, $text);
            foreach ($files as $f) {
                if (!is_file($f['path'])) continue;
                // Telegram принимает документы до 50 МБ; у нас лимит заявки — 20 МБ.
                $res['tg_files'][$chat][$f['name']] = tg_document($set['tgToken'], $chat, $f['path'], $f['name']);
            }
        }
    }
    return $res;
}

/** Письмо: без вложений — простой текст, с вложениями — multipart/mixed. */
function lead_mail(array $to, string $subject, string $text, array $files, string $from): bool
{
    [$headers, $body, $enc] = lead_mail_build($subject, $text, $files, $from);
    return @mail(implode(', ', $to), $enc, $body, $headers);
}

/**
 * Сборка письма отдельно от отправки — чтобы её можно было проверить без почтового сервера:
 * php tools/check-mail.php сохраняет результат в .eml и разбирает его.
 * Возвращает [заголовки, тело, тема в кодировке].
 */
function lead_mail_build(string $subject, string $text, array $files, string $from): array
{
    $enc = fn(string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
    $headers = "From: " . $enc('Сайт Завод ДСО') . " <$from>\r\nMIME-Version: 1.0\r\n";

    // Почтовые серверы режут крупные письма. Выше лимита вложения не прикладываем,
    // а честно пишем, что файлы лежат в админке, — иначе письмо просто не дойдёт.
    $real = [];
    $total = 0;
    $tooBig = [];
    foreach (array_filter($files, fn($f) => is_file($f['path'])) as $f) {
        $size = (int)filesize($f['path']);
        if ($total + $size > MAIL_ATTACH_LIMIT) { $tooBig[] = $f['name']; continue; }
        $total += $size;
        $real[] = $f;
    }
    if ($tooBig) {
        $text .= "\n\nНе приложены к письму (слишком большие): " . implode(', ', $tooBig)
            . "\nСкачайте их в админке, раздел «Заявки».";
    }
    if (!$real) {
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64";
        return [$headers, chunk_split(base64_encode($text)), $enc($subject)];
    }

    $b = 'zdso-' . bin2hex(random_bytes(8));
    $headers .= "Content-Type: multipart/mixed; boundary=\"$b\"";
    $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text)) . "\r\n";
    foreach ($real as $f) {
        $name = $enc((string)$f['name']);
        $type = lead_mime((string)$f['path']);
        $body .= "--$b\r\nContent-Type: $type; name=\"$name\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "Content-Disposition: attachment; filename=\"$name\"\r\n\r\n"
            . chunk_split(base64_encode((string)file_get_contents($f['path']))) . "\r\n";
    }
    $body .= "--$b--\r\n";
    return [$headers, $body, $enc($subject)];
}

function lead_mime(string $path): string
{
    if (function_exists('finfo_open') && ($fi = finfo_open(FILEINFO_MIME_TYPE))) {
        $m = finfo_file($fi, $path);
        finfo_close($fi);
        if (is_string($m) && $m !== '') return $m;
    }
    return 'application/octet-stream';
}

function tg_send(string $token, string $chat, string $text): bool
{
    $ch = curl_init("https://api.telegram.org/bot$token/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => 'true'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $out = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $out !== false && $code === 200;
}

function tg_document(string $token, string $chat, string $path, string $name): bool
{
    if (!function_exists('curl_file_create')) return false;
    $ch = curl_init("https://api.telegram.org/bot$token/sendDocument");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'chat_id' => $chat,
            'document' => curl_file_create($path, lead_mime($path), $name),
        ],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $out = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $out !== false && $code === 200;
}
