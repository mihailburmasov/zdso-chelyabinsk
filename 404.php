<?php
// Файл собран сборщиком (app/lib/build.php). Руками не правится.
declare(strict_types=1);

$root = __DIR__;
$uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
$qs = '';
if (($p = strpos($uri, '?')) !== false) { $qs = substr($uri, $p); $uri = substr($uri, 0, $p); }
$uri = rawurldecode($uri);

$lower = mb_strtolower($uri, 'UTF-8');
if ($lower !== $uri && preg_match('~^/[a-z0-9/_.-]*$~i', $uri)) {
    $candidate = rtrim($lower, '/') . '/';
    $file = $root . str_replace('..', '', $candidate) . 'index.html';
    if (is_file($file)) {
        header('Location: ' . 'https://mihailburmasov.github.io' . $candidate . $qs, true, 301);
        exit;
    }
}

http_response_code(404);
header('Content-Type: text/html; charset=UTF-8');
$page = $root . '/404.html';
if (is_file($page)) { readfile($page); exit; }
echo '<!doctype html><html lang="ru"><meta charset="utf-8"><title>Страница не найдена</title>'
    . '<p>Страница не найдена. <a href="/">На главную</a></p>';
