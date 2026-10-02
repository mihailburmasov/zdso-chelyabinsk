<?php
// Вход в админку. Ядро лежит вне корня сайта (папка app рядом с корнем) — здесь только передача запроса.
if (is_file(__DIR__ . '/../../app/admin/index.php')) { require __DIR__ . '/../../app/admin/index.php'; exit; }
http_response_code(404);
