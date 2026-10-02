<?php
// Создание учётной записи админки или сброс пароля:
//   php app/bin/admin-setup.php <пароль> [секретное-слово]
// Секретное слово — часть адреса входа: https://сайт/admin/<слово>. Без него админка отвечает 404.
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/admin/auth.php';

$password = $argv[1] ?? '';
$secret = $argv[2] ?? null;
if ($password === '') {
    fwrite(STDERR, "Использование: php app/bin/admin-setup.php <пароль> [секретное-слово]\n");
    exit(1);
}
try {
    $acc = admin_set_password($password, $secret);
    $url = rtrim((string)cfg('site_url'), '/') . rtrim((string)cfg('base_path'), '/') . '/admin/' . $acc['secret'];
    echo "Готово. Адрес входа: $url\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
