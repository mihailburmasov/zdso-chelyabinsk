<?php
// Приём заявок с форм сайта: проверка, антиспам, журнал, вложения, почта и Telegram.
// Заявка пишется в журнал первой — её видно в админке, даже если почта не сработала.
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/admin/ops.php';
require_once __DIR__ . '/lead-deliver.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function lead_reply(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_FLAGS);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') lead_reply(405, ['ok' => false, 'error' => 'Только POST']);

// Пустые $_FILES при непустом теле запроса — превышен post_max_size.
if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    lead_reply(413, ['ok' => false, 'error' => 'Файлы слишком большие. Отправьте архив до 20 МБ или пришлите чертёж в мессенджер.']);
}

$field = fn(string $k, int $max) => mb_substr(trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)($_POST[$k] ?? ''))), 0, $max);

// Антиспам без капчи: honeypot и время заполнения формы.
// Подозрительную заявку не выбрасываем: пишем в журнал с пометкой и не шлём уведомления,
// иначе настоящая заявка может пропасть без следа из-за ложного срабатывания.
$suspect = '';
if ($field('website', 200) !== '') $suspect = 'заполнено скрытое поле';
// В поле t приходит время заполнения формы в миллисекундах, посчитанное браузером:
// сравнивать часы браузера с часами сервера нельзя — расхождение ломает проверку.
$elapsed = (int)$field('t', 12);
if ($suspect === '' && $elapsed > 0 && $elapsed < 1500) $suspect = 'форма заполнена за ' . $elapsed . ' мс';

$KINDS = [
    'callback' => 'Заказать звонок',
    'kp' => 'Запрос КП на деталь',
    'drawing' => 'Чертёж или спецификация',
    'komplekt' => 'Подбор комплекта запчастей',
    'question' => 'Вопрос менеджеру',
];
$kind = $field('kind', 20);
if (!isset($KINDS[$kind])) $kind = 'question';

$lead = [
    'date' => date('Y-m-d H:i:s'),
    'kind' => $kind,
    'kind_label' => $KINDS[$kind],
    'name' => $field('name', 80),
    'phone' => $field('phone', 30),
    'subject' => $field('subject', 300),
    'comment' => $field('comment', 2000),
    'page' => $field('page', 500),
    'files' => [],
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'ua' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
    'suspect' => $suspect,
];

$digits = (string)preg_replace('~\D~', '', $lead['phone']);
if (mb_strlen($lead['name']) < 2) lead_reply(400, ['ok' => false, 'error' => 'Укажите имя']);
if (strlen($digits) < 10 || strlen($digits) > 12) lead_reply(400, ['ok' => false, 'error' => 'Укажите телефон: не меньше 10 цифр']);
// Согласие проверяем на сервере, а не только атрибутом required: его легко обойти.
if (($_POST['consent'] ?? '') === '') lead_reply(400, ['ok' => false, 'error' => 'Без согласия на обработку персональных данных мы не можем принять заявку']);

/* ------------------------------------------------------------- вложения */
const LEAD_MAX_TOTAL = 20 * 1024 * 1024;
const LEAD_EXT = ['pdf', 'dwg', 'dxf', 'jpg', 'jpeg', 'png', 'webp', 'xlsx', 'xls', 'csv', 'zip', 'rar', '7z'];
/**
 * Что точно нельзя принимать, как бы файл ни назвали. Основной фильтр — список расширений
 * выше; тип по содержимому проверяем «от обратного», потому что база magic на разных
 * серверах определяет DWG и XLSX по-разному, и белый список отказывал бы настоящим чертежам.
 */
const LEAD_MIME_DENY = [
    'application/x-dosexec', 'application/x-executable', 'application/x-msdownload',
    'application/x-sharedlib', 'application/x-mach-binary', 'application/vnd.microsoft.portable-executable',
    'text/html', 'application/xhtml+xml', 'application/x-httpd-php', 'text/x-php',
    'application/x-msdos-program', 'application/java-archive', 'application/x-bat',
    'application/x-shellscript', 'text/x-shellscript',
];

$saved = [];
if (!empty($_FILES['files']['name']) && is_array($_FILES['files']['name'])) {
    $total = 0;
    $dir = storage_path('leads/files/' . date('Y-m'));
    foreach ($_FILES['files']['name'] as $k => $origName) {
        if (($_FILES['files']['error'][$k] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        if (($_FILES['files']['error'][$k] ?? 0) !== UPLOAD_ERR_OK) {
            lead_reply(400, ['ok' => false, 'error' => 'Файл «' . htmlspecialchars((string)$origName) . '» не загрузился. Попробуйте меньший размер.']);
        }
        $tmp = (string)$_FILES['files']['tmp_name'][$k];
        $size = (int)$_FILES['files']['size'][$k];
        $total += $size;
        if ($total > LEAD_MAX_TOTAL) lead_reply(413, ['ok' => false, 'error' => 'Суммарный размер файлов больше 20 МБ']);
        $ext = strtolower((string)pathinfo((string)$origName, PATHINFO_EXTENSION));
        if (!in_array($ext, LEAD_EXT, true)) {
            lead_reply(400, ['ok' => false, 'error' => 'Формат «' . htmlspecialchars($ext) . '» не принимаем. Можно PDF, DWG, DXF, JPG, PNG, XLSX, ZIP.']);
        }
        // Тип по содержимому — отсекаем исполняемое и веб-страницы, остальное пропускаем.
        $mime = '';
        if (function_exists('finfo_open') && ($fi = finfo_open(FILEINFO_MIME_TYPE))) {
            $mime = (string)finfo_file($fi, $tmp);
            finfo_close($fi);
        }
        if ($mime !== '' && in_array($mime, LEAD_MIME_DENY, true)) {
            lead_reply(400, ['ok' => false, 'error' => 'Файл «' . htmlspecialchars((string)$origName) . '» нельзя загрузить: это программа или веб-страница.']);
        }
        ensure_dir($dir);
        $safe = preg_replace('~[^a-zA-Z0-9._-]+~', '_', (string)$origName);
        $name = date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '-' . mb_substr((string)$safe, -60);
        if (!@move_uploaded_file($tmp, $dir . '/' . $name)) {
            lead_reply(500, ['ok' => false, 'error' => 'Не удалось сохранить файл. Пришлите его в мессенджер.']);
        }
        $saved[] = ['name' => (string)$origName, 'stored' => date('Y-m') . '/' . $name, 'size' => $size, 'mime' => $mime];
    }
}
$lead['files'] = $saved;

/* ----------------------------------------------- журнал и лимит уведомлений */
try {
    $notify = with_lock(function () use (&$lead) {
        $f = storage_path('lead-rate.json');
        $all = is_file($f) ? read_json($f) : [];
        foreach ($all as $ip => $list) {
            $all[$ip] = array_values(array_filter($list, fn($t) => $t > time() - 3600));
            if (!$all[$ip]) unset($all[$ip]);
        }
        // 30 заявок в час с одного адреса: офис за одним IP пройдёт, бот — нет.
        $notify = count($all[$lead['ip']] ?? []) < 30;
        $all[$lead['ip']][] = time();
        write_file_atomic($f, json_pretty($all));
        if (!$notify) $lead['muted'] = true;
        ensure_dir(storage_path('leads'));
        if (file_put_contents(storage_path('leads/' . date('Y-m') . '.jsonl'), json_encode($lead, JSON_FLAGS) . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('journal');
        }
        return $notify;
    });
} catch (Throwable $e) {
    error_log('lead: ' . $e->getMessage());
    lead_reply(500, ['ok' => false, 'error' => 'Не удалось сохранить заявку. Позвоните нам: ' . (read_json(cfg('data_dir') . '/company.json')['phone_display'] ?? '')]);
}

$subject = $lead['kind_label'] . ($lead['subject'] !== '' ? ': ' . $lead['subject'] : '');
$lines = [
    $subject,
    'Имя: ' . $lead['name'],
    'Телефон: ' . $lead['phone'],
    'Комментарий: ' . ($lead['comment'] ?: '—'),
];
if ($saved) {
    $lines[] = 'Файлы (' . count($saved) . '): ' . implode(', ', array_map(fn($f) => $f['name'] . ' — ' . round($f['size'] / 1048576, 1) . ' МБ', $saved));
    $lines[] = 'Приложены к этому письму, скачать также можно в админке, раздел «Заявки».';
}
$lines[] = 'Страница: ' . ($lead['page'] ?: '—');
$lines[] = 'Время: ' . $lead['date'];

if (!$notify || $suspect !== '') lead_reply(200, ['ok' => true]);

// Посетителю отвечаем сразу, уведомления уходят после ответа.
$body = json_encode(['ok' => true], JSON_FLAGS);
ignore_user_abort(true);
http_response_code(200);
header('Content-Length: ' . strlen((string)$body));
header('Connection: close');
echo $body;
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
else { while (ob_get_level() > 0) ob_end_flush(); flush(); }
lead_deliver(implode("\n", $lines), $subject, array_map(fn($f) => [
    'name' => $f['name'],
    'path' => storage_path('leads/files/' . $f['stored']),
    'size' => $f['size'],
], $saved));
