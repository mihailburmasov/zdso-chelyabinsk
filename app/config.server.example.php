<?php
// Пример серверных настроек. Копируется в app/config.server.php и заливается при deploy init.
// Раскладка на хостинге (папка сайта ~/masterskie174.ru):
//   ~/masterskie174.ru/app          — ядро и админка (вне корня сайта)
//   ~/masterskie174.ru/data         — контент: единственный источник правды
//   ~/masterskie174.ru/storage      — пароль, сессии, заявки, история, корзина (создаётся сама)
//   ~/masterskie174.ru/public_html  — корень сайта: статика из public/ + собранные страницы
return [
    'site_url'    => 'https://masterskie174.ru',
    'base_path'   => '',
    'public_dir'  => ROOT_DIR . '/public_html',
    'out_dir'     => ROOT_DIR . '/public_html',
    // Ключ, по которому скрипт заливки просит сервер пересобрать сайт.
    // Любая случайная строка не короче 24 знаков; тот же ключ — в app/deploy.local.php.
    'deploy_key'  => '',
];
