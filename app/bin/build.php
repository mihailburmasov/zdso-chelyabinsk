<?php
// Сборка сайта из командной строки: php app/bin/build.php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

try {
    $r = build_site();
    echo "Собрано страниц: {$r['pages']}, изменено файлов: {$r['changed']}, удалено: {$r['removed']}, {$r['ms']} мс  (base=\"" . S::$base . "\", url=" . S::$siteUrl . (S::$draft ? ', DRAFT' : '') . ")\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка сборки: ' . $e->getMessage() . "\n");
    exit(1);
}
