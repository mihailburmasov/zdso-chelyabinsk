<?php
// Вход в админку. Все адреса /admin/... приходят сюда (см. public/.htaccess и app/dev-router.php).
// Без пропуска (секретного адреса) на любой адрес отвечаем обычной страницей 404 сайта.
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/ui/icons.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/schema.php';
require __DIR__ . '/content.php';
require __DIR__ . '/ops.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

S::$base = rtrim((string)cfg('base_path'), '/');
$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$prefix = S::$base . '/admin';
if (!str_starts_with($uri, $prefix)) not_found();
$route = trim(substr($uri, strlen($prefix)), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function not_found(): void
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    $f = rtrim(cfg('out_dir'), '/\\') . '/404.html';
    echo is_file($f) ? file_get_contents($f) : 'Not found';
    exit;
}
function json_out(array $body, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_FLAGS | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function redirect(string $to): void { header('Location: ' . $to, true, 303); exit; }

// ---------- пересборка после заливки кода (app/bin/deploy.php), только по ключу из настроек сервера ----------
if ($route === 'deploy-rebuild' && $method === 'POST' && (string)cfg('deploy_key') !== '' && strlen((string)cfg('deploy_key')) >= 24) {
    if (!hash_equals((string)cfg('deploy_key'), (string)($_SERVER['HTTP_X_DEPLOY_KEY'] ?? ''))) not_found();
    try { $r = build_site(); json_out(['ok' => true, 'message' => "страниц {$r['pages']}, изменено файлов {$r['changed']}"]); }
    catch (Throwable $e) { json_out(['ok' => false, 'error' => $e->getMessage()], 500); }
}

// ---------- пропуск по секретному адресу ----------
$acc = admin_account();
if ($acc && $route !== '' && hash_equals((string)$acc['secret'], $route)) {
    gate_open();
    redirect($prefix . '/');
}
if (!gate_passed()) not_found();

// ---------- статика админки ----------
if (preg_match('~^asset/(admin\.(?:css|js))$~', $route, $m)) {
    header('Content-Type: ' . (str_ends_with($m[1], '.css') ? 'text/css' : 'text/javascript') . '; charset=utf-8');
    header('Cache-Control: private, max-age=300');
    readfile(__DIR__ . '/ui/' . $m[1]);
    exit;
}

// ---------- вход и выход ----------
if ($route === 'login') {
    $error = null;
    if ($method === 'POST') {
        $error = login_attempt((string)($_POST['password'] ?? ''));
        if ($error === null) redirect($prefix . '/');
    }
    require __DIR__ . '/ui/login.php';
    exit;
}
if ($route === 'logout' && $method === 'POST') { session_end(); redirect($prefix . '/login'); }

$sess = session_current();
if (!$sess) {
    if (str_starts_with($route, 'api/')) json_out(['ok' => false, 'error' => 'Сессия истекла, войдите снова', 'relogin' => true], 401);
    redirect($prefix . '/login');
}

// ---------- файл, приложенный к заявке ----------
if ($route === 'lead-file' && $method === 'GET') {
    $rel = (string)($_GET['f'] ?? '');
    // Разрешаем только путь вида ГГГГ-ММ/имя-файла, без переходов вверх.
    if (!preg_match('~^\d{4}-\d{2}/[A-Za-z0-9._-]{1,200}$~', $rel)) not_found();
    $base = realpath(storage_path('leads/files'));
    $file = realpath($base . '/' . $rel);
    if (!$base || !$file || !str_starts_with($file, $base) || !is_file($file)) not_found();
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($file));
    header('Content-Disposition: attachment; filename="' . preg_replace('~[^A-Za-z0-9._-]~', '_', basename($file)) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}

// ---------- API ----------
if (str_starts_with($route, 'api/')) {
    if ($method === 'POST' && !hash_equals($sess['csrf'], (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) json_out(['ok' => false, 'error' => 'Устаревшая страница, обновите её'], 403);
    $in = [];
    if ($method === 'POST' && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    try {
        json_out(['ok' => true] + api(substr($route, 4), $method, $in));
    } catch (InvalidArgumentException $e) {
        json_out(['ok' => false, 'error' => $e->getMessage()], 400);
    } catch (Throwable $e) {
        error_log('admin: ' . $e);
        json_out(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// ---------- оболочка админки (дальше всё рисует admin.js) ----------
if ($route === '' && $method === 'GET') {
    require __DIR__ . '/ui/layout.php';
    exit;
}
not_found();

/** Ключ раздела каталога из запроса. */
function slug_arg(array $in): string
{
    $slug = (string)($in['slug'] ?? $_GET['slug'] ?? '');
    if (!preg_match('~^[a-z0-9-]{1,160}$~', $slug)) throw new InvalidArgumentException('Раздел каталога не найден');
    catsec_find($slug); // бросит исключение, если такого раздела нет
    return $slug;
}

function api(string $action, string $method, array $in): array
{
    load_site_data();
    $sections = admin_sections();
    switch ($method . ' ' . $action) {
        case 'GET bootstrap':
            $nav = [];
            foreach ($sections as $id => $s) $nav[] = ['id' => $id, 'title' => $s['title'], 'group' => $s['group']];
            return ['nav' => $nav, 'icons' => ICON_LABELS, 'services' => catsec_all(), 'base' => S::$base, 'siteUrl' => S::$siteUrl, 'leads' => count(leads_list(1000)), 'trash' => count(trash_index())];

        case 'GET section':
            $id = (string)($_GET['id'] ?? '');
            if (!isset($sections[$id])) throw new InvalidArgumentException('Раздел не найден');
            $s = $sections[$id];
            return ['title' => $s['title'], 'url' => $s['url'], 'fields' => $s['fields'], 'values' => section_values($s['fields'])];

        case 'POST section':
            $id = (string)($in['id'] ?? '');
            if (!isset($sections[$id])) throw new InvalidArgumentException('Раздел не найден');
            $changes = apply_values($sections[$id]['fields'], (array)($in['values'] ?? []));
            commit_changes($changes);
            return ['message' => $changes ? 'Сохранено, сайт обновлён' : 'Изменений нет'];

        case 'GET services':
            return ['services' => catsec_all()];

        case 'GET service':
            $slug = slug_arg([]);
            [, $sec] = catsec_find($slug);
            $fields = catsec_fields();
            return ['slug' => $slug, 'title' => $sec['name'], 'url' => $sec['url'], 'fields' => $fields, 'values' => section_values($fields, $slug)];

        case 'POST service':
            $slug = slug_arg($in);
            $changes = catsec_normalize_items($slug, apply_values(catsec_fields(), (array)($in['values'] ?? []), $slug));
            commit_changes($changes);
            return ['message' => $changes ? 'Сохранено, сайт обновлён' : 'Изменений нет'];

        case 'POST service/create':
            $parent = (string)($in['parent'] ?? 'catalog');
            return ['slug' => catsec_create($parent, (string)($in['title'] ?? '')), 'message' => 'Раздел создан. Добавьте в него позиции и сохраните.'];
        case 'POST item/create':
            $slug = slug_arg($in);
            return ['url' => catalog_item_create($slug, (string)($in['title'] ?? '')), 'message' => 'Позиция создана скрытой — заполните её и снимите галочку «Скрыть с сайта».'];
        case 'POST service/delete':
            catsec_delete(slug_arg($in));
            return ['message' => 'Раздел каталога перемещён в корзину'];

        case 'GET photos':
            return photos_payload();
        case 'POST photo/upload':
            if (empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) throw new InvalidArgumentException('Файл слишком большой для хостинга — уменьшите фото или увеличьте лимит загрузки');
            $e = photo_upload((string)($_POST['cat'] ?? ''), $_FILES['file'] ?? [], (string)($_POST['alt'] ?? ''));
            return ['photo' => $e, 'message' => 'Фото загружено'];
        case 'POST photo/update':
            photo_update((string)($in['id'] ?? ''), $in);
            return ['message' => 'Сохранено'];
        case 'POST photo/move':
            photo_move((string)($in['id'] ?? ''), (int)($in['dir'] ?? 0) < 0 ? -1 : 1);
            return [];
        case 'POST photo/delete':
            photo_delete((string)($in['id'] ?? ''));
            return ['message' => 'Фото перемещено в корзину'];
        case 'GET photo/usage':
            return ['uses' => array_values(array_unique(photo_usage((string)($_GET['id'] ?? ''))))];
        case 'POST photo/category':
            return ['key' => photo_cat_add((string)($in['label'] ?? '')), 'message' => 'Папка создана'];

        case 'GET trash':
            return ['items' => trash_index()];
        case 'POST trash/restore':
            return ['message' => trash_restore((string)($in['id'] ?? ''))];

        case 'GET revisions':
            return ['items' => revisions_list()];
        case 'POST revision/restore':
            revision_restore((string)($in['id'] ?? ''));
            return ['message' => 'Версия восстановлена, сайт обновлён'];

        case 'GET leads':
            return ['items' => leads_list()];

        case 'GET settings':
            $s = settings_get();
            if ($s['tgToken'] !== '') $s['tgToken'] = '••••••';
            $acc = admin_account();
            return ['settings' => $s, 'secretUrl' => S::$siteUrl . S::$base . '/admin/' . $acc['secret'], 'companyEmail' => doc_get('company.json')['email'] ?? ''];
        case 'POST settings':
            settings_save($in);
            return ['message' => 'Настройки сохранены'];
        case 'POST settings/test-lead':
            require_once dirname(__DIR__) . '/lead-deliver.php';
            $r = lead_deliver("Проверка: так будут приходить заявки с сайта.\nВремя: " . date('Y-m-d H:i:s'), 'Проверка заявок с сайта');
            return ['result' => $r, 'message' => 'Проверка отправлена'];
        case 'POST settings/password':
            $acc = admin_account();
            if (!password_verify((string)($in['current'] ?? ''), $acc['hash'] ?? '')) throw new InvalidArgumentException('Текущий пароль указан неверно');
            admin_set_password((string)($in['password'] ?? ''));
            return ['message' => 'Пароль изменён'];

        case 'POST rebuild':
            $r = build_site();
            return ['message' => "Сайт пересобран: страниц {$r['pages']}, изменено файлов {$r['changed']}"];
    }
    throw new InvalidArgumentException('Неизвестное действие');
}
