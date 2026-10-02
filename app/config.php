<?php
// Настройки по умолчанию — для работы на своём компьютере.
// На хостинге рядом кладётся app/config.local.php и переопределяет нужные ключи.
return [
    // Домен не меняется — это условие заказчика.
    'site_url'    => 'https://masterskie174.ru',
    'base_path'   => '',
    'studio_slug' => 'zdso-chelyabinsk',

    // data — единственный источник правды. public — статика. out — куда пишутся готовые страницы.
    'data_dir'    => ROOT_DIR . '/data',
    'public_dir'  => ROOT_DIR . '/public',
    'out_dir'     => ROOT_DIR . '/dist',
    'storage_dir' => ROOT_DIR . '/storage',

    'draft'       => false,
];
