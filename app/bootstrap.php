<?php
// Точка подключения ядра: пути, настройки, общие функции.
// Настройки по умолчанию — app/config.php, серверные и секретные — app/config.local.php (в git не хранится).
declare(strict_types=1);

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Yekaterinburg');

define('APP_DIR', __DIR__);
define('ROOT_DIR', dirname(__DIR__));

function cfg(?string $key = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require APP_DIR . '/config.php';
        if (is_file(APP_DIR . '/config.local.php')) $cfg = array_replace($cfg, require APP_DIR . '/config.local.php');
        // переменные окружения переопределяют адрес сайта (как в прежнем сборщике на Node)
        if (getenv('SITE_URL') !== false) $cfg['site_url'] = getenv('SITE_URL');
        if (getenv('SITE_BASE') !== false) $cfg['base_path'] = getenv('SITE_BASE');
        if (getenv('OUT_DIR') !== false) $cfg['out_dir'] = getenv('OUT_DIR');
        if (getenv('STORAGE_DIR') !== false) $cfg['storage_dir'] = getenv('STORAGE_DIR');
    }
    return $key === null ? $cfg : ($cfg[$key] ?? null);
}

require APP_DIR . '/lib/fs.php';
require APP_DIR . '/lib/icons.php';
require APP_DIR . '/lib/render.php';
require APP_DIR . '/lib/catalog.php';
require APP_DIR . '/lib/pages.php';
require APP_DIR . '/lib/build.php';
