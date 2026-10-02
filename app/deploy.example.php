<?php
// Пример доступа для app/bin/deploy.php. Скопируйте в app/deploy.local.php (в git не хранится) и заполните.
return [
    'host'     => 'ftp.masterskie174.ru', // FTP-сервер из панели хостинга
    'user'     => 'login',
    'pass'     => 'password',
    'ssl'      => false,                  // true — FTPS, если хостинг поддерживает
    'root'     => 'masterskie174.ru',     // папка сайта от корня FTP (в ней app, data, public_html)
    'docroot'  => 'public_html',          // корень сайта внутри этой папки
    'site_url' => 'https://masterskie174.ru',
    // тот же ключ, что deploy_key в app/config.server.php — по нему сервер пересобирает сайт после заливки
    'deploy_key' => '',
];
