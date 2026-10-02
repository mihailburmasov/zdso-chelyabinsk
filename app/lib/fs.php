<?php
// Файлы: чтение и атомарная запись JSON, копия перед каждой записью, блокировка.
declare(strict_types=1);

const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

function ensure_dir(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Не удалось создать папку ' . $dir);
}

function read_json(string $file)
{
    $raw = @file_get_contents($file);
    if ($raw === false) throw new RuntimeException('Нет файла ' . $file);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) throw new RuntimeException('Ошибка в JSON ' . basename($file) . ': ' . json_last_error_msg());
    return $data;
}

// JSON с отступом в 2 пробела — как у прежних файлов, чтобы git показывал только настоящие изменения.
function json_pretty($data): string
{
    $s = json_encode($data, JSON_FLAGS | JSON_PRETTY_PRINT);
    if ($s === false) throw new RuntimeException('Не удалось записать JSON: ' . json_last_error_msg());
    $s = preg_replace_callback('/^( {4})+/m', fn($m) => str_repeat('  ', intdiv(strlen($m[0]), 4)), $s);
    return $s . "\n";
}

// Атомарная запись: сначала во временный файл рядом, затем переименование.
function write_file_atomic(string $file, string $content): void
{
    ensure_dir(dirname($file));
    $tmp = $file . '.tmp' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $content, LOCK_EX) === false) throw new RuntimeException('Не удалось записать ' . $file);
    if (!@rename($tmp, $file)) {
        // Windows не переименовывает поверх занятого файла — пробуем удалить и повторить
        @unlink($file);
        if (!@rename($tmp, $file)) { @unlink($tmp); throw new RuntimeException('Не удалось записать ' . $file); }
    }
}

// Запись данных сайта: копия прежней версии в storage/revisions, затем атомарная запись.
function write_data_json(string $rel, $data, string $who = ''): void
{
    $file = data_path($rel);
    if (is_file($file)) backup_revision($rel, (string)file_get_contents($file), $who);
    write_file_atomic($file, json_pretty($data));
}

function data_path(string $rel): string
{
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    if ($rel === '' || str_contains($rel, '..') || !preg_match('~^[a-z0-9/_.-]+\.json$~i', $rel)) throw new RuntimeException('Недопустимый путь ' . $rel);
    return cfg('data_dir') . '/' . $rel;
}

function storage_path(string $rel = ''): string
{
    return rtrim(cfg('storage_dir'), '/') . ($rel !== '' ? '/' . ltrim($rel, '/') : '');
}

// Ревизии хранятся без автоочистки: удаление старого — только руками.
function backup_revision(string $rel, string $content, string $who = ''): void
{
    $dir = storage_path('revisions/' . str_replace('/', '__', $rel));
    ensure_dir($dir);
    $name = date('Y-m-d_H-i-s') . '_' . bin2hex(random_bytes(2)) . ($who !== '' ? '_' . preg_replace('~[^a-z0-9-]~i', '', $who) : '') . '.json';
    file_put_contents($dir . '/' . $name, $content);
}

// Взаимное исключение сборок и записей: одна операция за раз.
// Повторный вход из того же запроса (сохранение -> сборка) не блокируется.
function with_lock(callable $fn)
{
    static $depth = 0;
    if ($depth > 0) { $depth++; try { return $fn(); } finally { $depth--; } }
    ensure_dir(storage_path());
    $h = fopen(storage_path('.lock'), 'c');
    if (!$h || !flock($h, LOCK_EX)) throw new RuntimeException('Не удалось получить блокировку');
    $depth = 1;
    try { return $fn(); } finally { $depth = 0; flock($h, LOCK_UN); fclose($h); }
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    rmdir($dir);
}
