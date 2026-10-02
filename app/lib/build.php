<?php
// Сборка сайта: data/*.json → готовые HTML-страницы, карты сайта, robots.txt, поисковый индекс.
// Все страницы сначала собираются в памяти; если по дороге ошибка — на диск ничего не пишется.
declare(strict_types=1);

final class Build
{
    public static array $pages = [];    // путь страницы => html
    public static array $sitemap = [];  // [loc, priority, group]
    public static array $images = [];   // loc страницы => [пути картинок]
    public static array $files = [];    // файл => путь страницы, для защиты от совпадающих адресов
}

function emit_page(string $pth, string $html, array $o = []): void
{
    // Две страницы в один файл — это молча потерянная страница. Чаще всего так
    // случается, когда в админке задали адрес, который уже занят другой записью.
    $file = page_file($pth);
    if (isset(Build::$files[$file])) {
        throw new RuntimeException('Адрес «' . $pth . '» собирается дважды (файл ' . $file . '). '
            . 'Скорее всего, у двух записей одинаковый адрес — поправьте один из них.');
    }
    Build::$files[$file] = $pth;
    Build::$pages[$pth] = $html;
    if ($o['sitemap'] ?? true) {
        Build::$sitemap[] = ['loc' => abs_url($pth), 'priority' => $o['priority'] ?? '0.6', 'group' => $o['group'] ?? 'pages'];
    }
    if (!empty($o['images'])) Build::$images[abs_url($pth)] = $o['images'];
}

function page_file(string $pth): string
{
    return str_ends_with($pth, '/') ? ltrim($pth, '/') . 'index.html' : ltrim($pth, '/');
}

function render_site(): array
{
    Build::$pages = []; Build::$sitemap = []; Build::$images = []; Build::$files = [];
    load_site_data();
    validate_data();

    page_home();

    foreach (S::$sections as $s) page_catalog_section($s);
    foreach (S::$items as $i) page_catalog_item($i);

    page_tehnika_hub();
    foreach (S::$tehnika as $t) page_tehnika($t);

    page_services_hub();
    foreach (S::$services as $s) page_service($s);

    page_price(); page_price_shchekovye(); page_price_konusnye();

    page_company(); page_production(); page_certificates(); page_partners();
    page_reviews(); page_staff(); page_requisites(); page_vacancy();

    page_contacts(); page_delivery();

    page_info_hub();
    page_articles_index();
    foreach (S::$articles as $a) page_article($a);
    page_news_index();
    foreach (S::$news as $n) page_news($n);
    page_faq(); page_stock(); page_projects();

    page_search(); page_sitemap_html();
    page_privacy(); page_consent(); page_not_found();

    $files = [];
    foreach (Build::$pages as $pth => $html) $files[page_file($pth)] = $html;

    foreach (sitemap_files() as $rel => $xml) $files[$rel] = $xml;
    $files['robots.txt'] = robots_txt();
    $files['404.php'] = error_handler_php();
    $files['site.webmanifest'] = webmanifest_json();
    $files['500.html'] = error_500_html();
    $files['search-index.json'] = search_index();
    $files['price/price-zdso.csv'] = price_csv();

    // Кэш размеров картинок: чтобы повторная сборка не читала 700 файлов заново.
    ensure_dir(storage_path());
    write_file_atomic(storage_path('img-dims.json'), json_pretty(S::$imgDims));

    return $files;
}

/** Индексный sitemap + отдельные карты по типам страниц + карта изображений. */
function sitemap_files(): array
{
    $byGroup = [];
    foreach (Build::$sitemap as $u) $byGroup[$u['group']][] = $u;
    ksort($byGroup);
    $now = gmdate('Y-m-d');

    $out = [];
    $index = [];
    foreach ($byGroup as $group => $urls) {
        $rel = 'sitemap-' . $group . '.xml';
        $body = '';
        foreach ($urls as $u) {
            $lm = page_lastmod(substr($u['loc'], strlen(S::$siteUrl . S::$base))) ?: $now;
            $body .= "  <url><loc>" . esc($u['loc']) . "</loc><lastmod>$lm</lastmod><priority>{$u['priority']}</priority></url>\n";
        }
        $out[$rel] = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n$body</urlset>\n";
        $index[] = $rel;
    }

    // карта изображений
    $imgBody = '';
    foreach (S::$items as $i) {
        if (empty($i['photos'])) continue;
        $imgBody .= '  <url><loc>' . esc(abs_url($i['url'])) . "</loc>\n";
        foreach ($i['photos'] as $p) {
            $imgBody .= '    <image:image><image:loc>' . esc(abs_url($p)) . '</image:loc><image:title>' . esc($i['name']) . "</image:title></image:image>\n";
        }
        $imgBody .= "  </url>\n";
    }
    if ($imgBody !== '') {
        $out['sitemap-images.xml'] = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:image=\"http://www.google.com/schemas/sitemap-image/1.1\">\n"
            . $imgBody . "</urlset>\n";
        $index[] = 'sitemap-images.xml';
    }

    $idx = '';
    foreach ($index as $rel) $idx .= '  <sitemap><loc>' . esc(abs_url('/' . $rel)) . "</loc><lastmod>$now</lastmod></sitemap>\n";
    $out['sitemap.xml'] = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<sitemapindex xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n$idx</sitemapindex>\n";
    return $out;
}

/**
 * Честная дата изменения: берём время правки того файла данных, из которого собрана
 * страница. Для раздела каталога и его позиций это файл этого раздела — правка одного
 * раздела не помечает изменённым весь сайт.
 */
function page_lastmod(string $path): string
{
    static $cache = [];
    if (isset($cache[$path])) return $cache[$path];
    $d = rtrim((string)cfg('data_dir'), '/\\');
    $files = [];
    if (str_starts_with($path, '/catalog/')) {
        // У позиции — файл её раздела; у самого раздела ещё и список разделов.
        $isItem = isset(S::$items[$path]);
        $sec = $isItem ? S::$items[$path]['section'] : $path;
        $key = trim(str_replace('/catalog/', '', $sec), '/');
        $files[] = $d . '/catalog/items/' . ($key === '' ? 'catalog' : str_replace('/', '--', $key)) . '.json';
        if (!$isItem) $files[] = $d . '/catalog/sections.json';
    } elseif (str_starts_with($path, '/tehnika/')) {
        $files[] = $d . '/tehnika.json';
    } elseif (str_starts_with($path, '/services/')) {
        $files[] = $d . '/services.json';
    } elseif (str_starts_with($path, '/info/news/')) {
        $files[] = $d . '/news.json';
    } elseif (str_starts_with($path, '/info/articles/')) {
        $files[] = $d . '/articles.json';
    } elseif (str_starts_with($path, '/price/')) {
        $files[] = $d . '/price.json';
    } else {
        $files[] = $d . '/pages.json';
    }
    // company.json сюда не входит: смена телефона не делает содержимое страницы новым.
    $t = 0;
    foreach ($files as $f) if (is_file($f)) $t = max($t, (int)filemtime($f));
    return $cache[$path] = $t ? gmdate('Y-m-d', $t) : '';
}

/**
 * Обработчик 404: приводит адрес в верхнем регистре к нижнему и отдаёт 301,
 * если такая страница есть (RewriteMap в .htaccess недоступен — только в конфиге сервера).
 * Собирается здесь, чтобы домен брался из настроек, а не был вписан руками.
 */
/** Манифест: собирается, чтобы пути уважали base_path (нужно для демо на поддпути). */
function webmanifest_json(): string
{
    return json_encode([
        'name' => S::$company['brand'],
        'short_name' => S::$company['short'],
        'lang' => 'ru-RU',
        'start_url' => href('/'),
        'scope' => href('/'),
        'display' => 'browser',
        'background_color' => '#ffffff',
        'theme_color' => '#1A1D21',
        'icons' => [
            ['src' => href('/favicon.svg'), 'sizes' => 'any', 'type' => 'image/svg+xml'],
            ['src' => href('/img/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => href('/img/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
        ],
    ], JSON_FLAGS | JSON_PRETTY_PRINT) . "
";
}

function error_handler_php(): string
{
    $site = var_export(S::$siteUrl, true);
    return <<<PHP
<?php
// Файл собран сборщиком (app/lib/build.php). Руками не правится.
declare(strict_types=1);

\$root = __DIR__;
\$uri = (string)(\$_SERVER['REQUEST_URI'] ?? '/');
\$qs = '';
if ((\$p = strpos(\$uri, '?')) !== false) { \$qs = substr(\$uri, \$p); \$uri = substr(\$uri, 0, \$p); }
\$uri = rawurldecode(\$uri);

\$lower = mb_strtolower(\$uri, 'UTF-8');
if (\$lower !== \$uri && preg_match('~^/[a-z0-9/_.-]*\$~i', \$uri)) {
    \$candidate = rtrim(\$lower, '/') . '/';
    \$file = \$root . str_replace('..', '', \$candidate) . 'index.html';
    if (is_file(\$file)) {
        header('Location: ' . {$site} . \$candidate . \$qs, true, 301);
        exit;
    }
}

http_response_code(404);
header('Content-Type: text/html; charset=UTF-8');
\$page = \$root . '/404.html';
if (is_file(\$page)) { readfile(\$page); exit; }
echo '<!doctype html><html lang="ru"><meta charset="utf-8"><title>Страница не найдена</title>'
    . '<p>Страница не найдена. <a href="/">На главную</a></p>';

PHP;
}

/** Страница на случай аварии сервера: телефон и почта подставляются из данных. */
function error_500_html(): string
{
    $c = S::$company;
    $tel = esc(phone_href());
    $phone = esc(phone_display());
    $mail = esc(company_email());
    $brand = esc($c['brand']);
    return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Сайт временно недоступен — {$brand}</title>
<style>
  body{margin:0;min-height:100vh;display:grid;place-items:center;background:#1a1d21;color:#fff;
       font:16px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;padding:24px}
  .b{max-width:560px;text-align:center}
  h1{font-size:1.6rem;margin:0 0 12px}
  p{color:#c9d2da}
  a{color:#e8761a;font-weight:700}
</style>
</head>
<body>
  <div class="b">
    <h1>Сайт временно недоступен</h1>
    <p>Мы уже разбираемся. Если нужно срочно — позвоните: <a href="{$tel}">{$phone}</a>
    или напишите на <a href="mailto:{$mail}">{$mail}</a>.</p>
    <p>{$brand}, Челябинск.</p>
  </div>
</body>
</html>

HTML;
}

function robots_txt(): string
{
    if (getenv('STAGING') === '1') {
        return "# СТЕНД. Индексация закрыта целиком.\nUser-agent: *\nDisallow: /\n";
    }
    $site = S::$siteUrl;
    return <<<TXT
User-agent: *
Disallow: /admin/
Disallow: /lead.php
Disallow: /search/?
Disallow: /*?utm_
Disallow: /*?from=
Disallow: /*?PAGEN
Disallow: /*?ORDER_BY
Disallow: /*?set_filter=
Disallow: /*/filter/
Allow: /css/
Allow: /js/
Allow: /fonts/
Allow: /img/
Allow: /upload/
Clean-param: utm_source&utm_medium&utm_campaign&utm_term&utm_content&utm_referrer&yclid&gclid&ymclid&_openstat&from&roistat&erid /
Clean-param: sort&order&view&PAGEN_1&PAGEN_2&set_filter&q /

Sitemap: {$site}/sitemap.xml

TXT;
}

/** Прайс в CSV — чтобы страницу прайса можно было выгрузить в Excel. */
function price_csv(): string
{
    $rows = [["\xEF\xBB\xBFМодель", 'Номер чертежа', 'Наименование детали', 'Кол-во на машину', 'Вес, кг', 'Цена за ед., ₽']];
    foreach (S::$price['groups'] as $g) {
        foreach ($g['rows'] as $r) {
            $rows[] = [$g['model'], (string)($r['draw'] ?? ''), $r['name'], (string)($r['qty'] ?? ''),
                ($r['weight'] ?? null) !== null ? str_replace('.', ',', (string)$r['weight']) : '',
                ($r['price'] ?? null) !== null ? (string)$r['price'] : 'по запросу'];
        }
    }
    $out = '';
    foreach ($rows as $r) {
        $out .= implode(';', array_map(fn($v) => '"' . str_replace('"', '""', (string)$v) . '"', $r)) . "\r\n";
    }
    return $out;
}

/** Полная сборка с записью на диск. */
function build_site(): array
{
    return with_lock(function () {
        $t0 = microtime(true);
        $files = render_site();
        $out = rtrim((string)cfg('out_dir'), '/\\');
        ensure_dir($out);

        $pub = realpath((string)cfg('public_dir'));
        if ($pub && $pub !== realpath($out)) mirror_static($pub, $out, array_keys($files));

        $changed = 0;
        foreach ($files as $rel => $content) {
            $f = "$out/$rel";
            if (is_file($f) && file_get_contents($f) === $content) continue;
            write_file_atomic($f, $content);
            $changed++;
        }
        $manifestFile = storage_path('build-manifest.json');
        $prev = is_file($manifestFile) ? read_json($manifestFile) : [];
        $removed = 0;
        foreach (array_diff($prev, array_keys($files)) as $rel) {
            $f = "$out/$rel";
            if (is_file($f)) { unlink($f); $removed++; }
            $dir = dirname($f);
            while ($dir !== $out && is_dir($dir) && count(scandir($dir)) === 2) { rmdir($dir); $dir = dirname($dir); }
        }
        write_file_atomic($manifestFile, json_pretty(array_keys($files)));
        return [
            'pages' => count(Build::$pages), 'changed' => $changed, 'removed' => $removed,
            'ms' => (int)round((microtime(true) - $t0) * 1000),
        ];
    });
}

/** Статика из public копируется в out, лишнее удаляется (режим разработки). */
function mirror_static(string $src, string $dst, array $generated): void
{
    $keep = array_flip($generated);
    $seen = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($src) + 1));
        $seen[$rel] = true;
        $to = "$dst/$rel";
        if (is_file($to) && filesize($to) === $f->getSize() && filemtime($to) >= $f->getMTime()) continue;
        ensure_dir(dirname($to));
        copy($f->getPathname(), $to);
    }
    if (!is_dir($dst)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dst, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dst) + 1));
        if ($f->isDir()) { if (count(scandir($f->getPathname())) === 2) rmdir($f->getPathname()); continue; }
        if (!isset($seen[$rel]) && !isset($keep[$rel])) unlink($f->getPathname());
    }
}
