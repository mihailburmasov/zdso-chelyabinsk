<?php
// Доступ к админке: секретный адрес (без него любой адрес админки отдаёт 404), пароль, сессии, защита от подбора.
// Всё хранится в storage/ — эта папка не публикуется и не попадает в git.
declare(strict_types=1);

const SESSION_TTL = 30 * 86400;
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_WINDOW = 15 * 60;

function admin_account(): ?array
{
    $f = storage_path('admin.json');
    return is_file($f) ? read_json($f) : null;
}

function admin_save_account(array $acc): void
{
    write_file_atomic(storage_path('admin.json'), json_pretty($acc));
}

// Создание или смена учётной записи (из командной строки или из настроек)
function admin_set_password(string $password, ?string $secret = null): array
{
    if (mb_strlen($password) < 8) throw new RuntimeException('Пароль должен быть не короче 8 символов');
    $acc = admin_account() ?? [];
    $acc['hash'] = password_hash($password, PASSWORD_DEFAULT);
    if ($secret !== null) {
        if (!preg_match('~^[a-z0-9-]{6,64}$~', $secret)) throw new RuntimeException('Секретное слово: латиница, цифры и дефис, от 6 символов');
        $acc['secret'] = $secret;
    }
    if (empty($acc['secret'])) $acc['secret'] = 'vhod-' . bin2hex(random_bytes(5));
    $acc['changed'] = date('c');
    admin_save_account($acc);
    return $acc;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || ($_SERVER['SERVER_PORT'] ?? '') === '443';
}
function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
function is_local_request(): bool { return in_array(client_ip(), ['127.0.0.1', '::1'], true); }

function admin_cookie(string $name, string $value, int $ttl, bool $httpOnly = true): void
{
    setcookie($name, $value, [
        'expires' => $ttl > 0 ? time() + $ttl : time() - 3600,
        'path' => (S::$base ?: '') . '/',
        'secure' => is_https(),
        'httponly' => $httpOnly,
        'samesite' => 'Lax',
    ]);
}

// ---------- секретный адрес ----------
// Пропуск привязан к секретному слову: смена слова отзывает все выданные пропуски
function gate_value(array $acc): string { return hash_hmac('sha256', 'gate', (string)$acc['secret']); }

function gate_passed(): bool
{
    $acc = admin_account();
    if (!$acc) return false;
    if (is_local_request() && cfg('admin_local_no_gate')) return true;
    return hash_equals(gate_value($acc), (string)($_COOKIE['sg_gate'] ?? ''));
}

function gate_open(): void
{
    admin_cookie('sg_gate', gate_value(admin_account()), 365 * 86400);
}

// ---------- сессии ----------
function session_file(string $token): string { return storage_path('sessions/' . hash('sha256', $token) . '.json'); }

function session_current(): ?array
{
    static $cache = false;
    if ($cache !== false) return $cache;
    $token = (string)($_COOKIE['sg_sess'] ?? '');
    $cache = null;
    if (!preg_match('~^[a-f0-9]{64}$~', $token)) return null;
    $f = session_file($token);
    if (!is_file($f)) return null;
    $s = read_json($f);
    if (($s['expires'] ?? 0) < time()) { @unlink($f); return null; }
    return $cache = $s;
}

function session_start_new(): void
{
    $token = bin2hex(random_bytes(32));
    ensure_dir(storage_path('sessions'));
    write_file_atomic(session_file($token), json_pretty(['created' => time(), 'expires' => time() + SESSION_TTL, 'csrf' => bin2hex(random_bytes(16)), 'ip' => client_ip()]));
    admin_cookie('sg_sess', $token, SESSION_TTL);
    admin_cookie('sg_adm', '1', SESSION_TTL, false); // только для кнопки «Редактировать» на сайте, доступа не даёт
    // старые сессии чистим заодно
    foreach (glob(storage_path('sessions/*.json')) ?: [] as $f) {
        $s = json_decode((string)file_get_contents($f), true);
        if (($s['expires'] ?? 0) < time()) @unlink($f);
    }
}

function session_end(): void
{
    $token = (string)($_COOKIE['sg_sess'] ?? '');
    if (preg_match('~^[a-f0-9]{64}$~', $token)) @unlink(session_file($token));
    admin_cookie('sg_sess', '', 0);
    admin_cookie('sg_adm', '', 0, false);
}

// ---------- защита от подбора: счётчик в файле, а не в памяти процесса ----------
function login_attempts_file(): string { return storage_path('login-attempts.json'); }

function login_blocked(): int
{
    $f = login_attempts_file();
    $all = is_file($f) ? read_json($f) : [];
    $mine = array_filter($all[client_ip()] ?? [], fn($t) => $t > time() - LOGIN_WINDOW);
    if (count($mine) < LOGIN_MAX_ATTEMPTS) return 0;
    return max(1, (int)ceil((min($mine) + LOGIN_WINDOW - time()) / 60));
}

function login_register_fail(): void
{
    $f = login_attempts_file();
    $all = is_file($f) ? read_json($f) : [];
    foreach ($all as $ip => $list) { $all[$ip] = array_values(array_filter($list, fn($t) => $t > time() - LOGIN_WINDOW)); if (!$all[$ip]) unset($all[$ip]); }
    $all[client_ip()][] = time();
    write_file_atomic($f, json_pretty($all));
}

function login_attempt(string $password): ?string
{
    if ($m = login_blocked()) return "Слишком много попыток. Попробуйте через {$m} мин.";
    $acc = admin_account();
    if (!$acc || !password_verify($password, $acc['hash'] ?? '')) {
        login_register_fail();
        usleep(400000);
        return 'Неверный пароль';
    }
    session_start_new();
    return null;
}
