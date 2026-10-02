<?php
// Приём заявок с форм сайта. Обработчик лежит вне корня сайта (папка app рядом с корнем).
if (is_file(__DIR__ . '/../app/lead.php')) { require __DIR__ . '/../app/lead.php'; exit; }
http_response_code(503);
