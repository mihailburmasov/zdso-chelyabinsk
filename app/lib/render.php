<?php
// Общие части страниц: данные сайта, экранирование, ссылки, картинки,
// шапка, подвал, оболочка страницы, формы, кнопки мессенджеров, микроразметка.
declare(strict_types=1);

/** Данные сайта на время одной сборки. */
final class S
{
    public static string $base = '';
    public static string $siteUrl = '';
    public static string $buildDate = '';
    public static bool $draft = false;

    public static array $company = [];
    public static array $structure = [];
    public static array $pages = [];
    public static array $sections = [];      // url => раздел каталога
    public static array $items = [];         // url => товар
    public static array $itemsBySection = []; // url раздела => [товары]
    public static array $childSections = []; // url раздела => [подразделы]
    public static array $tehnika = [];
    public static array $tehBySection = [];  // url раздела запчастей => модель
    public static array $services = [];
    public static array $svcBySlug = [];
    public static array $news = [];
    public static array $articles = [];
    public static array $faq = [];
    public static array $stock = [];
    public static array $vacancies = [];
    public static array $price = [];
    public static array $legal = [];
    public static array $units = [];
    public static array $imgDims = [];       // путь картинки => [w,h]
    public static array $photos = [];        // папка медиатеки => список фото
    public static array $photoIndex = [];    // путь фото => запись медиатеки
    public static array $heroDims = [];      // не используется, нужен админке
}

function load_site_data(): void
{
    $d = rtrim((string)cfg('data_dir'), '/\\');
    S::$siteUrl = rtrim((string)cfg('site_url'), '/');
    S::$base = rtrim((string)cfg('base_path'), '/');
    S::$buildDate = gmdate('Y-m-d');
    S::$draft = getenv('DRAFT') === '1' || (bool)cfg('draft');

    S::$company = read_json("$d/company.json");
    S::$structure = read_json("$d/structure.json");
    S::$pages = read_json("$d/pages.json");
    S::$tehnika = read_json("$d/tehnika.json");
    S::$services = read_json("$d/services.json");
    foreach (S::$services as $i => $s) S::$services[$i]['url'] = '/services/' . ($s['slug'] ?? '') . '/';
    // Адрес выводим из слага, а не берём из данных: иначе опечатка в поле «адрес»
    // перезаписывает чужую страницу (например, новость с адресом /contacts/).
    S::$news = array_map(fn($n) => $n + ['url' => '/info/news/' . ($n['slug'] ?? '') . '/'], read_json("$d/news.json"));
    foreach (S::$news as $i => $n) S::$news[$i]['url'] = '/info/news/' . ($n['slug'] ?? '') . '/';
    S::$articles = read_json("$d/articles.json");
    foreach (S::$articles as $i => $a) S::$articles[$i]['url'] = '/info/articles/' . ($a['slug'] ?? '') . '/';
    S::$faq = read_json("$d/faq.json");
    S::$stock = read_json("$d/stock.json");
    S::$vacancies = read_json("$d/vacancies.json");
    S::$price = read_json("$d/price.json");
    S::$legal = read_json("$d/legal.json");
    S::$units = read_json("$d/units.json");

    S::$sections = [];
    foreach (read_json("$d/catalog/sections.json") as $s) S::$sections[$s['url']] = section_defaults($s);
    uksort(S::$sections, 'strcmp');
    // Товары лежат по файлу на раздел: админка правит один раздел за раз.
    // Необязательные поля в сохранённых из админки записях могут отсутствовать — дополняем.
    S::$items = [];
    foreach (glob("$d/catalog/items/*.json") ?: [] as $f) {
        foreach (read_json($f) as $i) {
            if (!empty($i['hidden'])) continue;
            S::$items[$i['url']] = item_defaults($i);
        }
    }

    S::$itemsBySection = S::$childSections = [];
    foreach (S::$sections as $u => $s) { S::$itemsBySection[$u] = []; S::$childSections[$u] = []; }
    foreach (S::$items as $i) S::$itemsBySection[$i['section']][] = $i;
    foreach (S::$sections as $u => $s) if (!empty($s['parent']) && isset(S::$sections[$s['parent']])) S::$childSections[$s['parent']][] = $s;
    foreach (S::$itemsBySection as $u => &$list) usort($list, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    unset($list);

    S::$svcBySlug = [];
    foreach (S::$services as $s) S::$svcBySlug[$s['slug']] = $s;
    S::$tehBySection = [];
    foreach (S::$tehnika as $m) if ($m['parts_section'] !== '') S::$tehBySection[$m['parts_section']] = $m;

    usort(S::$news, fn($a, $b) => strcmp($b['date'], $a['date']));

    // Медиатека: ключ записи — путь файла, поэтому значение поля «фото» и путь совпадают.
    S::$photos = is_file("$d/photos.json") ? read_json("$d/photos.json") : [];
    S::$photoIndex = [];
    foreach (S::$photos as $list) foreach ($list as $p) S::$photoIndex[$p['id']] = $p;

    $cache = storage_path('img-dims.json');
    S::$imgDims = is_file($cache) ? read_json($cache) : [];
}

/* ---------------------------------------------------------------- базовое */

/** Полный набор полей позиции каталога: админка не записывает пустые необязательные поля. */
function item_defaults(array $i): array
{
    return $i + [
        'type' => 'part', 'name' => '', 'name_source' => 'site',
        'draw' => '', 'draw_alt' => [], 'model' => '', 'material' => '',
        'weight' => null, 'qty' => '', 'dims' => '', 'gost' => '',
        'price' => null, 'stock' => 'in_stock', 'lead' => '',
        'photos' => [], 'about' => '', 'title' => '', 'description' => '',
        'legacy_urls' => [], 'section' => '/catalog/',
    ];
}

/** То же для раздела каталога. */
function section_defaults(array $s): array
{
    return $s + [
        'name' => '', 'name_source' => 'site', 'model' => '', 'photo' => '',
        'text_old' => '', 'title' => '', 'description' => '', 'intro' => '',
        'text' => '', 'legacy_urls' => [], 'parent' => null, 'slug' => '',
    ];
}

function esc($s): string
{
    return str_replace(['&', '<', '>', '"'], ['&amp;', '&lt;', '&gt;', '&quot;'], (string)($s ?? ''));
}
function href(string $p): string { return S::$base . $p; }
function abs_url(string $p): string { return S::$siteUrl . S::$base . $p; }
function asset(string $p): string
{
    static $cache = [];
    $f = rtrim((string)cfg('public_dir'), '/\\') . $p;
    $cache[$p] ??= is_file($f) ? substr(md5_file($f), 0, 10) : S::$buildDate;
    return S::$base . $p . '?v=' . $cache[$p];
}
function json_ld($o): string { return str_replace('<', '\\u003c', (string)json_encode($o, JSON_FLAGS | JSON_UNESCAPED_LINE_TERMINATORS)); }
function join_map(array $list, callable $fn, string $sep = ''): string { return implode($sep, array_map($fn, $list, array_keys($list))); }
function nbsp(string $s): string { return str_replace(' — ', ' — ', $s); }

/**
 * Фоновое фото оформления из public/img/bg (готовит tools/make-site-photos.php):
 * все ширины файла <name>-<w>.webp идут в srcset, самая узкая jpg — запасной вариант.
 */
function bg_picture(string $name, string $class, array $o = []): string
{
    static $cache = [];
    if (!isset($cache[$name])) {
        $dir = rtrim((string)cfg('public_dir'), '/\\') . '/img/bg/';
        $ws = [];
        foreach (glob($dir . $name . '-*.webp') ?: [] as $f) {
            if (preg_match('/-(\d+)\.webp$/', $f, $m)) $ws[] = (int)$m[1];
        }
        sort($ws);
        $cache[$name] = $ws;
    }
    $ws = $cache[$name];
    if (!$ws) return '';
    $srcset = implode(', ', array_map(fn($w) => href('/img/bg/' . $name . '-' . $w . '.webp') . ' ' . $w . 'w', $ws));
    $fallback = $ws[min(1, count($ws) - 1)];
    $attrs = !empty($o['eager']) ? ' fetchpriority="high" decoding="async"' : ' loading="lazy" decoding="async"';
    return '<picture class="' . esc($class) . '"><source type="image/webp" srcset="' . $srcset . '" sizes="' . esc($o['sizes'] ?? '100vw') . '">'
        . '<img src="' . href('/img/bg/' . $name . '-' . $fallback . '.jpg') . '" alt="' . esc($o['alt'] ?? '') . '"' . $attrs . '></picture>';
}

/** Полоса с фотографией под шапкой внутренних страниц: фото и подпись — по разделу сайта. */
function page_band(string $path, bool $slim = false, bool $search = true): string
{
    static $map = [
        'catalog'  => ['catalog', 'Каталог запчастей', 'Детали к дробилкам, грохотам и питателям — с номерами чертежей'],
        'tehnika'  => ['tehnika', 'Техника и запчасти к ней', 'Щековые и конусные дробилки, грохоты, питатели'],
        'services' => ['services', 'Услуги производства', 'Литьё, механообработка, ремонт дробилок и питателей, плетение сетки'],
        'news'     => ['info', 'Новости и отгрузки', 'Что делаем и куда отправляем'],
        'articles' => ['info', 'База знаний', 'Как выбрать, заменить и продлить срок службы деталей'],
        'price'    => ['price', 'Прайс-лист', 'Цены на запчасти от производителя'],
        'company'  => ['company', 'О заводе', 'Собственное производство в Челябинске с 2003 года'],
        'contacts' => ['contacts', 'Как с нами связаться', 'Челябинск · отгрузка по всей России'],
        'pages'    => ['pages', 'Завод ДСО', 'Запчасти для дробильно-сортировочного оборудования'],
    ];
    [$img, $title, $sub] = $map[edit_route($path)] ?? $map['pages'];
    if (str_starts_with($path, '/info/') && !str_starts_with($path, '/info/news/')) [$img, $title, $sub] = $map['articles'];
    // Подпись — оформление (aria-hidden). Поиск справа — рабочий: он заменяет строку поиска,
    // которой больше нет в шапке. На узких экранах его прячет CSS — там лупа в шапке и кнопка «Поиск» внизу.
    return '<div class="pband' . ($slim ? ' pband--slim' : '') . '">'
        . bg_picture($img, 'pband__bg', ['eager' => true])
        . '<div class="container pband__in"><div class="pband__text" aria-hidden="true"><span class="pband__t">' . esc($title) . '</span>'
        . ($slim ? '' : '<span class="pband__s">' . esc($sub) . '</span>') . '</div>'
        . ($search ? '<div class="pband__search">' . search_widget('band-search', 'Номер чертежа, артикул или модель') . '</div>' : '')
        . '</div></div>';
}

/** Размеры картинки из public/ — чтобы в вёрстке всегда были width и height. */
function img_dims(string $path): array
{
    if (isset(S::$imgDims[$path])) return S::$imgDims[$path];
    $f = rtrim((string)cfg('public_dir'), '/\\') . $path;
    $wh = is_file($f) ? @getimagesize($f) : false;
    $out = $wh ? [(int)$wh[0], (int)$wh[1]] : [800, 600];
    S::$imgDims[$path] = $out;
    return $out;
}

/**
 * Картинка каталога. Отсутствующее фото превращается в аккуратную заглушку.
 * Если рядом с файлом лежит <путь>.webp (их делает tools/make-webp.php), отдаём его
 * через <picture>, а исходный файл остаётся запасным вариантом — и старый адрес картинки,
 * который уже в индексе, продолжает работать.
 */
function cat_img(string $path, string $alt, array $o = []): string
{
    if ($path === '') return img_placeholder($o['ph'] ?? 'Фото детали');
    [$w, $h] = img_dims($path);
    $eager = !empty($o['eager']);
    $img = '<img src="' . href($path) . '" alt="' . esc($alt) . '" width="' . $w . '" height="' . $h . '"'
        . ($eager ? ' fetchpriority="high" decoding="sync"' : ' loading="lazy" decoding="async"')
        . (isset($o['sizes']) ? ' sizes="' . esc($o['sizes']) . '"' : '') . '>';
    return has_webp($path)
        ? '<picture><source type="image/webp" srcset="' . href($path . '.webp') . '">' . $img . '</picture>'
        : $img;
}

/** Есть ли рядом с картинкой её webp-двойник. Список считается один раз за сборку. */
function has_webp(string $path): bool
{
    static $cache = [];
    if (!isset($cache[$path])) {
        $cache[$path] = is_file(rtrim((string)cfg('public_dir'), '/\\') . $path . '.webp');
    }
    return $cache[$path];
}

function img_placeholder(string $label): string
{
    return '<span class="noimg">' . icon('image') . '<span>' . esc($label) . '</span></span>';
}

/* --------------------------------------------------------- контакты и связь */

function phone_display(): string { return (string)S::$company['phone_display']; }
function phone_href(): string { return 'tel:+' . ltrim((string)S::$company['phone_href'], '+'); }
function company_email(): string { return (string)S::$company['email']; }

function phone_link(string $cls = '', bool $sub = false): string
{
    return '<a class="' . $cls . '" href="' . phone_href() . '" data-goal="click_phone">' . esc(phone_display())
        . ($sub ? '<small>' . esc(S::$company['schedule_short']) . '</small>' : '') . '</a>';
}

/** Список мессенджеров с подставленным текстом сообщения. */
function messengers(string $text = ''): array
{
    $m = S::$company['messengers'];
    $q = $text !== '' ? rawurlencode($text) : '';
    $out = [];
    foreach ([
        ['whatsapp', 'WhatsApp', 'wa'],
        ['telegram', 'Telegram', 'tg'],
        ['max', 'MAX', 'max'],
    ] as [$key, $label, $mod]) {
        $url = trim((string)($m[$key]['url'] ?? ''));
        // Предзаполненный текст поддерживает только WhatsApp; у Telegram ?text= работает
        // не для личных чатов, у MAX параметра текста нет.
        if ($url !== '' && $key === 'whatsapp' && $q !== '') $url .= (str_contains($url, '?') ? '&' : '?') . 'text=' . $q;
        $out[] = ['key' => $key, 'label' => $label, 'mod' => $mod, 'url' => $url, 'prefill' => $key === 'whatsapp'];
    }
    return $out;
}

function messenger_icons(string $cls = 'hdr__msg'): string
{
    return '<div class="' . $cls . '">' . join_map(messengers(), function ($m) {
        $off = $m['url'] === '';
        return '<a class="icon-btn icon-btn--' . $m['mod'] . ($off ? ' is-off' : '') . '" href="' . esc($m['url'] ?: '#') . '"'
            . ($off ? ' aria-disabled="true" title="Ссылка уточняется"' : ' target="_blank" rel="noopener"')
            . ' data-goal="click_' . $m['key'] . '" aria-label="Написать в ' . esc($m['label']) . '">' . icon($m['key']) . '</a>';
    }) . '</div>';
}

/** Крупные кнопки мессенджеров (карточка товара, страница модели, контакты). */
function messenger_buttons(string $text = ''): string
{
    return '<div class="msgrow">' . join_map(messengers($text), function ($m) {
        if ($m['url'] === '') {
            return '<span class="msgbtn msgbtn--off" title="Ссылка на ' . esc($m['label']) . ' уточняется">' . icon($m['key']) . esc($m['label']) . '</span>';
        }
        return '<a class="msgbtn msgbtn--' . $m['mod'] . '" href="' . esc($m['url']) . '" target="_blank" rel="noopener"'
            . ' data-goal="click_' . $m['key'] . '">' . icon($m['key']) . esc($m['label']) . '</a>';
    }) . '</div>';
}

/* ------------------------------------------------------------------ формы */

function consent_field(string $id): string
{
    return '<label class="consent" for="' . $id . '"><input type="checkbox" id="' . $id . '" name="consent" value="1" checked required>'
        . '<span>Согласен на обработку персональных данных и ознакомлен с <a href="' . href(S::$pages['privacy']['url']) . '" target="_blank" rel="noopener">политикой конфиденциальности</a>.</span></label>';
}

/**
 * Форма заявки. Варианты: callback, kp, drawing, komplekt, question.
 * Поля минимальны: имя и телефон; остальное по необходимости.
 */
function form_html(array $o): string
{
    $kind = $o['kind'] ?? 'question';
    $id = $o['id'] ?? ('f-' . $kind);
    $dark = !empty($o['dark']);
    $withFile = !empty($o['file']);
    $subject = $o['subject'] ?? '';
    $comment = $o['comment'] ?? '';
    $btn = $o['btn'] ?? 'Отправить заявку';

    $h = '<form class="form' . ($dark ? ' form--dark' : '') . '" id="' . esc($id) . '" method="post" action="' . href('/lead.php') . '"'
        . ' enctype="multipart/form-data" data-form="' . esc($kind) . '" novalidate>';
    $h .= '<input type="hidden" name="kind" value="' . esc($kind) . '">';
    $h .= '<input type="hidden" name="page" value="' . esc($o['page'] ?? '') . '">';
    $h .= '<input type="hidden" name="subject" value="' . esc($subject) . '">';
    $h .= '<input type="hidden" name="t" value="">';
    $h .= '<div class="hp" aria-hidden="true"><label for="' . esc($id) . '-site">Сайт</label><input type="text" id="' . esc($id) . '-site" name="website" tabindex="-1" autocomplete="off"></div>';

    $h .= '<div class="form__row form__row--2">';
    $h .= '<div class="field"><label for="' . esc($id) . '-name">Имя *</label><input type="text" id="' . esc($id) . '-name" name="name" required autocomplete="name" maxlength="80"><span class="field__err" data-err="name"></span></div>';
    $h .= '<div class="field"><label for="' . esc($id) . '-phone">Телефон *</label><input type="tel" id="' . esc($id) . '-phone" name="phone" required autocomplete="tel" inputmode="tel" maxlength="30" placeholder="+7 ___ ___-__-__"><span class="field__err" data-err="phone"></span></div>';
    $h .= '</div>';

    // В модальном окне поля есть всегда: тип заявки там переключается на лету.
    if ($kind !== 'callback' || !empty($o['modal'])) {
        $h .= '<div class="field" data-field="comment"' . ($kind === 'callback' ? ' hidden' : '') . '><label for="' . esc($id) . '-comment">' . esc($o['commentLabel'] ?? 'Что нужно (необязательно)') . '</label>'
            . '<textarea id="' . esc($id) . '-comment" name="comment" maxlength="2000">' . esc($comment) . '</textarea></div>';
    }
    if ($withFile) {
        $h .= '<div class="filedrop" data-field="files"' . ($kind === 'callback' ? ' hidden' : '') . '><input type="file" id="' . esc($id) . '-files" name="files[]" multiple accept=".pdf,.dwg,.dxf,.jpg,.jpeg,.png,.webp,.xlsx,.xls,.zip,.rar,.7z">'
            . '<label for="' . esc($id) . '-files">' . icon('file') . ' Выбрать файлы</label>'
            . '<p class="field__hint" style="margin:8px 0 0">Чертёж, спецификация или фото бирки. PDF, DWG, JPG, XLSX, ZIP — до 20 МБ.</p>'
            . '<ul class="filedrop__list" data-filelist></ul></div>';
    }
    $h .= consent_field($id . '-consent');
    $h .= '<div class="actions"><button type="submit" class="btn btn--primary">' . esc($btn) . '</button></div>';
    $h .= '<p class="form__msg" data-msg hidden></p>';
    $h .= '</form>';
    return $h;
}

/** Блок «Отправьте заявку» для низа страниц. */
function cta_block(array $o = []): string
{
    $title = $o['title'] ?? 'Нужна деталь или расчёт?';
    $text = $o['text'] ?? 'Пришлите номер чертежа, фото бирки или чертёж — ответим с ценой, наличием и сроком изготовления.';
    return '<section class="section section--brand" id="zayavka">' . bg_picture('pages', 'section--brand__bg') . '<div class="container">
  <div class="hero__grid">
    <div>
      <h2>' . esc($title) . '</h2>
      <p class="hero__lead">' . esc($text) . '</p>
      <p class="ftr__contact">' . phone_link('', false) . ' · <a href="mailto:' . esc(company_email()) . '" data-goal="click_email">' . esc(company_email()) . '</a></p>
      ' . messenger_buttons($o['msgText'] ?? '') . '
    </div>
    <div>' . form_html(['kind' => $o['kind'] ?? 'question', 'id' => 'cta-form', 'dark' => true, 'subject' => $o['subject'] ?? '', 'comment' => $o['comment'] ?? '', 'page' => $o['page'] ?? '', 'file' => $o['file'] ?? false, 'btn' => $o['btn'] ?? 'Отправить заявку']) . '</div>
  </div>
</div></section>';
}

/* --------------------------------------------------------------- навигация */

function menu_children(array $node): array
{
    $c = $node['children'] ?? [];
    if ($c === 'catalog') {
        $out = [];
        foreach (S::$childSections['/catalog/'] ?? [] as $s) $out[] = ['url' => $s['url'], 'label' => $s['name']];
        return $out;
    }
    if ($c === 'tehnika') return array_map(fn($m) => ['url' => '/tehnika/' . $m['slug'] . '/', 'label' => $m['name'] . ' — ' . mb_strtolower($m['kind'])], S::$tehnika);
    if ($c === 'services') return array_map(fn($s) => ['url' => $s['url'], 'label' => $s['name']], S::$services);
    return is_array($c) ? $c : [];
}

function site_header(string $current): string
{
    $h = '<header class="hdr"><div class="container">';
    $h .= '<div class="hdr__top">';
    $h .= '<a class="logo" href="' . href('/') . '" aria-label="Завод ДСО — на главную">'
        . '<picture><source type="image/webp" srcset="' . href('/img/logo-164.webp') . ' 1x, ' . href('/img/logo-328.webp') . ' 2x">'
        . '<img class="logo__img" src="' . href('/img/logo-164.png') . '" srcset="' . href('/img/logo-328.png') . ' 2x" width="164" height="46" alt="ДСО"></picture>'
        . '<span class="logo__t"><b>Завод дробильно-сортировочного оборудования</b><span>Челябинск · с 2003 года</span></span></a>';
    $h .= '<span class="hdr__spacer"></span>';
    $h .= '<button type="button" class="icon-btn hdr__search-btn" data-toggle-search aria-label="Поиск по номеру чертежа" aria-expanded="false">' . icon('search') . '</button>';
    $h .= messenger_icons('hdr__msg');
    $h .= phone_link('hdr__tel', true);
    $h .= '<button type="button" class="btn btn--primary btn--sm hdr__cta" data-modal="callback">Заказать звонок</button>';
    $h .= '<button type="button" class="icon-btn hdr__burger" data-toggle-menu aria-label="Меню" aria-expanded="false">' . icon('menu') . '</button>';
    $h .= '</div>';

    $h .= '<div class="hdr__search" data-search-wrap>' . search_widget('hdr-search', 'Номер чертежа, артикул или модель — например, 4844802022 или СМД-108А') . '</div>';

    $h .= '<nav class="hdr__nav" aria-label="Основная навигация"><ul class="nav">';
    foreach (S::$structure['menu'] as $m) {
        $kids = menu_children($m);
        $active = $current === $m['url'] || ($m['url'] !== '/' && str_starts_with($current, $m['url']));
        $h .= '<li class="' . ($active ? 'is-active' : '') . '"><a href="' . href($m['url']) . '">' . esc($m['label']) . '</a>';
        if ($kids) {
            $h .= '<ul class="nav__sub">' . join_map($kids, fn($k) => '<li><a href="' . href($k['url']) . '">' . esc($k['label']) . '</a></li>') . '</ul>';
        }
        $h .= '</li>';
    }
    $h .= '</ul></nav>';
    $h .= '</div></header>';

    // мобильное меню
    $h .= '<div class="mnav" data-mnav hidden><div class="mnav__back" data-close-menu></div><div class="mnav__panel" role="dialog" aria-label="Меню">'
        . '<button type="button" class="icon-btn mnav__close" data-close-menu aria-label="Закрыть меню">' . icon('close') . '</button>'
        . '<p class="ftr__contact" style="margin:4px 0 12px">' . phone_link('', false) . '</p>'
        . '<button type="button" class="btn btn--primary btn--block mnav__cta" data-modal="callback">Заказать звонок</button>'
        . messenger_icons('ftr__msg');
    foreach (S::$structure['menu'] as $m) {
        $kids = menu_children($m);
        $h .= '<h2>' . esc($m['label']) . '</h2><ul><li><a href="' . href($m['url']) . '">' . esc($m['label']) . ' — все</a></li>'
            . join_map($kids, fn($k) => '<li><a href="' . href($k['url']) . '">' . esc($k['label']) . '</a></li>') . '</ul>';
    }
    $h .= '</div></div>';
    return $h;
}

function search_widget(string $id, string $placeholder): string
{
    return '<div class="search" data-search><div class="search__row">'
        . '<label class="visually-hidden" for="' . esc($id) . '">Поиск по номеру чертежа, артикулу или модели</label>'
        . '<input class="search__input" id="' . esc($id) . '" type="search" autocomplete="off" spellcheck="false"'
        . ' placeholder="' . esc($placeholder) . '" data-search-input>'
        . '<button type="button" class="btn btn--primary" data-search-go>' . icon('search') . '<span class="visually-hidden">Найти</span></button>'
        . '</div><div class="search__res" data-search-res role="listbox" aria-label="Результаты поиска"></div></div>';
}

function site_footer(): string
{
    $c = S::$company;
    $h = '<footer class="ftr"><div class="container"><div class="ftr__grid">';
    $h .= '<div class="ftr__brand"><a class="ftr__logo" href="' . href('/') . '" aria-label="На главную"><img src="' . href('/img/logo-164.png') . '" srcset="' . href('/img/logo-328.png') . ' 2x" width="164" height="46" alt="ДСО" loading="lazy"></a><b>' . esc($c['brand']) . '</b>'
        . '<p class="small" style="margin:8px 0 12px">Производство и поставка запасных частей, расходных материалов и оборудования для дробильно-сортировочного, горно-добывающего и дорожно-строительного оборудования.</p>'
        . '<p class="ftr__contact">' . phone_link('', false) . '<br><a href="mailto:' . esc(company_email()) . '" data-goal="click_email">' . esc(company_email()) . '</a><br>'
        . esc($c['schedule']) . '</p>'
        . messenger_icons('ftr__msg')
        . '<p class="ftr__req">' . esc($c['legal_short']) . ' · ИНН ' . esc($c['inn']) . ' · ОГРН ' . esc($c['ogrn']) . '<br>' . esc($c['legal_address']) . '</p>'
        . '</div>';
    foreach (S::$structure['footer_columns'] as $col) {
        $links = $col['links'];
        if ($links === 'tehnika') $links = array_map(fn($m) => ['url' => '/tehnika/' . $m['slug'] . '/', 'label' => 'Запчасти ' . $m['name']], S::$tehnika);
        elseif ($links === 'services') $links = array_map(fn($s) => ['url' => $s['url'], 'label' => $s['name']], S::$services);
        $h .= '<div><h3>' . esc($col['label']) . '</h3><ul>'
            . join_map($links, fn($l) => '<li><a href="' . href($l['url']) . '">' . esc($l['label']) . '</a></li>') . '</ul></div>';
    }
    $h .= '</div><div class="ftr__bottom">';
    $h .= '<span>© ' . esc($c['founded_year']) . '–' . date('Y') . ' ' . esc($c['brand']) . '</span>';
    $h .= '<a href="' . href(S::$pages['privacy']['url']) . '">Политика конфиденциальности</a>';
    $h .= '<a href="' . href(S::$pages['consent']['url']) . '">Согласие на обработку данных</a>';
    $h .= '<a href="' . href('/sitemap/') . '">Карта сайта</a>';
    $h .= '<a class="ftr__credit" href="https://sitomika.ru/?utm_source=' . esc($c['credit_slug']) . '&amp;utm_medium=footer&amp;utm_campaign=client-sites" target="_blank" rel="noopener">Разработано в sitomika.ru</a>';
    $h .= '</div></div></footer>';
    return $h;
}

function floating_contact(): string
{
    $m = messengers();
    $h = '<div class="fab">';
    foreach ($m as $x) {
        if ($x['url'] === '') continue;
        $h .= '<a class="fab--' . $x['mod'] . '" href="' . esc($x['url']) . '" target="_blank" rel="noopener" data-goal="click_' . $x['key'] . '" aria-label="Написать в ' . esc($x['label']) . '">' . icon($x['key']) . '</a>';
    }
    $h .= '<a class="fab--call" href="' . phone_href() . '" data-goal="click_phone" aria-label="Позвонить">' . icon('phone') . '</a></div>';

    $h .= '<nav class="mbar" aria-label="Быстрая связь">';
    $h .= '<a href="' . phone_href() . '" data-goal="click_phone">' . icon('phone') . '<span>Позвонить</span></a>';
    foreach ($m as $x) {
        if ($x['url'] === '') continue;
        $h .= '<a href="' . esc($x['url']) . '" target="_blank" rel="noopener" data-goal="click_' . $x['key'] . '">' . icon($x['key']) . '<span>' . esc($x['label']) . '</span></a>';
    }
    $h .= '<button type="button" data-toggle-search>' . icon('search') . '<span>Поиск</span></button>';
    $h .= '</nav>';
    return $h;
}

function modal_html(): string
{
    return '<dialog class="modal" data-dialog="callback"><div class="modal__in">'
        . '<button type="button" class="icon-btn modal__x" data-close-dialog aria-label="Закрыть">' . icon('close') . '</button>'
        . '<h2>Заказать звонок</h2><p class="small muted" data-modal-note>Перезвоним в рабочее время: ' . esc(S::$company['schedule']) . '.</p>'
        . form_html(['kind' => 'callback', 'id' => 'modal-callback', 'modal' => true, 'file' => true, 'btn' => 'Жду звонка'])
        . '</div></dialog>';
}

function cookie_html(): string
{
    return '<div class="cookie" data-cookie hidden>'
        . '<span>Сайт использует cookie: они нужны для работы страниц и для статистики Яндекс Метрики. Подробнее — в <a href="' . href(S::$pages['privacy']['url']) . '">политике конфиденциальности</a>.</span>'
        . '<button type="button" class="btn btn--light btn--sm" data-cookie-ok>Понятно</button></div>';
}

/* ------------------------------------------------------------- микроразметка */

function org_ld(): array
{
    $c = S::$company;
    return [
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        '@id' => abs_url('/#org'),
        'name' => $c['brand'],
        'legalName' => $c['legal_full'],
        'alternateName' => ['ЗДСО', 'Завод ДСО'],
        'url' => abs_url('/'),
        'logo' => abs_url('/img/logo.png'),
        'taxID' => $c['inn'],
        'vatID' => $c['inn'],
        'identifier' => ['@type' => 'PropertyValue', 'name' => 'ОГРН', 'value' => $c['ogrn']],
        'foundingDate' => (string)$c['founded_year'],
        'telephone' => '+' . ltrim((string)$c['phone_href'], '+'),
        'email' => $c['email'],
        'address' => [
            '@type' => 'PostalAddress',
            'streetAddress' => 'Комсомольский проспект, д. 10',
            'addressLocality' => 'Челябинск',
            'addressRegion' => 'Челябинская область',
            'postalCode' => '454008',
            'addressCountry' => 'RU',
        ],
        'areaServed' => ['@type' => 'Country', 'name' => 'Россия'],
        // sameAs намеренно отсутствует: подтверждённых профилей нет,
        // Twitter и Facebook удалены по требованию заказчика.
    ];
}

function website_ld(): array
{
    return [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        '@id' => abs_url('/#website'),
        'url' => abs_url('/'),
        'name' => S::$company['brand'],
        'inLanguage' => 'ru-RU',
        'publisher' => ['@id' => abs_url('/#org')],
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => ['@type' => 'EntryPoint', 'urlTemplate' => abs_url('/search/') . '?q={search_term_string}'],
            'query-input' => 'required name=search_term_string',
        ],
    ];
}

function crumbs_ld(array $items): array
{
    return [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => array_map(fn($it, $i) => [
            '@type' => 'ListItem', 'position' => $i + 1, 'name' => $it['name'], 'item' => abs_url($it['url']),
        ], $items, array_keys($items)),
    ];
}

function faq_ld(array $list): array
{
    return [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => array_map(fn($f) => [
            '@type' => 'Question', 'name' => $f['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
        ], $list),
    ];
}

/* ----------------------------------------------------------------- блоки */

function crumbs(array $items): string
{
    $n = count($items);
    return '<nav class="crumbs" aria-label="Хлебные крошки"><ol>' . join_map($items, fn($it, $i) => $i === $n - 1
        ? '<li aria-current="page">' . esc($it['name']) . '</li>'
        : '<li><a href="' . href($it['url']) . '">' . esc($it['name']) . '</a></li>') . '</ol></nav>';
}

function faq_html(array $list): string
{
    return '<div class="faq">' . join_map($list, fn($f) =>
        '<details class="faq__item"><summary>' . esc($f['q']) . '</summary><div class="faq__a"><p>' . esc($f['a']) . '</p></div></details>') . '</div>';
}

function pending_block(string $note): string
{
    return '<div class="pending"><b>Раздел готов к наполнению.</b> ' . esc($note) . '</div>';
}

/** Блоки вида [{h, text|list|table}] — используются на страницах услуг, компании, статей. */
function blocks_html(array $blocks): string
{
    return join_map($blocks, function ($b) {
        $h = isset($b['h']) ? '<h2>' . esc($b['h']) . '</h2>' : '';
        if (!empty($b['text'])) $h .= '<p>' . esc($b['text']) . '</p>';
        if (!empty($b['p'])) foreach ($b['p'] as $p) $h .= '<p>' . esc($p) . '</p>';
        if (!empty($b['list'])) $h .= '<ul class="checks">' . join_map($b['list'], fn($l) => '<li>' . esc($l) . '</li>') . '</ul>';
        if (!empty($b['table'])) {
            $h .= '<table><tbody>' . join_map($b['table'], fn($r) => '<tr><th>' . esc($r[0]) . '</th><td>' . esc($r[1]) . '</td></tr>') . '</tbody></table>';
        }
        return $h;
    });
}

/** Описание страницы: обрезаем по границе слова до нужной длины (цель 140–170 знаков). */
function meta_desc(string $s, int $max = 170): string
{
    $s = trim((string)preg_replace('~\s+~u', ' ', $s));
    if (mb_strlen($s) <= $max) return $s;
    $cut = mb_substr($s, 0, $max);
    $sp = mb_strrpos($cut, ' ');
    if ($sp !== false && $sp > $max * 0.6) $cut = mb_substr($cut, 0, $sp);
    return rtrim($cut, ' ,.;:—-') . '.';
}

/** Склонение числительных: plural(3, ['позиция','позиции','позиций']) → «позиции». */
function plural(int $n, array $forms): string
{
    $m = abs($n) % 100;
    $m1 = $m % 10;
    if ($m > 10 && $m < 20) return $forms[2];
    if ($m1 > 1 && $m1 < 5) return $forms[1];
    if ($m1 === 1) return $forms[0];
    return $forms[2];
}
function n_pos(int $n): string { return $n . ' ' . plural($n, ['позиция', 'позиции', 'позиций']); }

function date_ru(string $ymd): string
{
    if ($ymd === '') return '';
    static $m = [1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
    [$y, $mm, $d] = array_map('intval', explode('-', $ymd));
    return $d . ' ' . $m[$mm] . ' ' . $y;
}

/** Кнопка «Редактировать» для вошедшего в админку. */
function edit_route(string $pth): string
{
    if ($pth === '/') return 'home';
    if (str_starts_with($pth, '/catalog/')) return 'catalog';
    if (str_starts_with($pth, '/tehnika/')) return 'tehnika';
    if (str_starts_with($pth, '/services/')) return 'services';
    if (str_starts_with($pth, '/info/news/')) return 'news';
    if (str_starts_with($pth, '/info/articles/')) return 'articles';
    if (str_starts_with($pth, '/price/')) return 'price';
    if (str_starts_with($pth, '/company/')) return 'company';
    if ($pth === '/contacts/') return 'contacts';
    return 'pages';
}

/* -------------------------------------------------------------- оболочка */

function shell(array $o): string
{
    $c = S::$company;
    $path = $o['path'];
    $title = $o['title'];
    // Длину описания держим в пределах, которые показывает выдача (цель 140–170 знаков).
    $desc = meta_desc((string)($o['description'] ?? ''), 180);
    $canonical = abs_url($path);
    $ld = $o['ld'] ?? [];
    $noindex = !empty($o['noindex']);
    $ogImage = abs_url('/img/og-default.png');

    $h = "<!DOCTYPE html>\n<html lang=\"ru\">\n<head>\n";
    $h .= '<meta charset="UTF-8">' . "\n";
    $h .= '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
    $h .= '<title>' . esc($title) . '</title>' . "\n";
    if ($desc !== '') $h .= '<meta name="description" content="' . esc($desc) . '">' . "\n";
    $h .= '<link rel="canonical" href="' . esc($canonical) . '">' . "\n";
    if ($noindex) $h .= '<meta name="robots" content="noindex, follow">' . "\n";
    if (getenv('STAGING') === '1') $h .= '<meta name="robots" content="noindex, nofollow">' . "\n";
    $h .= '<meta name="yandex-verification" content="' . esc($c['analytics']['yandex_verification']) . '">' . "\n";
    $h .= '<meta name="google-site-verification" content="' . esc($c['analytics']['google_verification']) . '">' . "\n";
    // Open Graph. Карточек Twitter намеренно нет: Twitter удалён с сайта по требованию заказчика.
    $h .= '<meta property="og:type" content="' . esc($o['ogType'] ?? 'website') . '">' . "\n";
    $h .= '<meta property="og:locale" content="ru_RU">' . "\n";
    $h .= '<meta property="og:site_name" content="' . esc($c['brand']) . '">' . "\n";
    $h .= '<meta property="og:title" content="' . esc($title) . '">' . "\n";
    if ($desc !== '') $h .= '<meta property="og:description" content="' . esc($desc) . '">' . "\n";
    $h .= '<meta property="og:url" content="' . esc($canonical) . '">' . "\n";
    $h .= '<meta property="og:image" content="' . esc($o['ogImage'] ?? $ogImage) . '">' . "\n";
    $h .= '<link rel="icon" href="' . href('/favicon.svg') . '" type="image/svg+xml">' . "\n";
    $h .= '<link rel="icon" href="' . href('/favicon.ico') . '" sizes="any">' . "\n";
    $h .= '<link rel="apple-touch-icon" href="' . href('/img/apple-touch-icon.png') . '">' . "\n";
    $h .= '<link rel="manifest" href="' . href('/site.webmanifest') . '">' . "\n";
    $h .= '<meta name="theme-color" content="#ffffff">' . "\n";
    $h .= '<link rel="preload" href="' . href('/fonts/manrope-cyrillic-700-normal.woff2') . '" as="font" type="font/woff2" crossorigin>' . "\n";
    $h .= '<link rel="preload" href="' . href('/fonts/manrope-cyrillic-400-normal.woff2') . '" as="font" type="font/woff2" crossorigin>' . "\n";
    $h .= '<style>' . critical_css() . '</style>' . "\n";
    $h .= '<link rel="stylesheet" href="' . asset('/css/style.css') . '">' . "\n";
    foreach (array_merge([org_ld(), website_ld()], $ld) as $obj) {
        $h .= '<script type="application/ld+json">' . json_ld($obj) . '</script>' . "\n";
    }
    $h .= '</head>' . "\n" . '<body class="has-mbar" data-page="' . esc($path) . '">' . "\n";
    $h .= metrika_html();
    $h .= '<a class="skip-link" href="#main">К основному содержанию</a>' . "\n";
    $h .= site_header($path);
    $h .= '<main id="main">' . ($path === '/' ? '' : page_band($path, ($o['ogType'] ?? '') === 'product', !str_contains($o['body'], 'data-search-input'))) . $o['body'] . '</main>';
    $h .= site_footer();
    $h .= floating_contact();
    $h .= modal_html();
    $h .= cookie_html();
    $h .= '<script>window.ZDSO=' . json_ld([
        'base' => S::$base,
        'metrika' => (string)S::$company['analytics']['yandex_metrika'],
        'searchIndex' => href('/search-index.json'),
        'searchPage' => href('/search/'),
        'editRoute' => edit_route($path),
    ]) . ';</script>' . "\n";
    $h .= '<script src="' . asset('/js/main.js') . '" defer></script>' . "\n";
    $h .= '</body>' . "\n</html>\n";
    return $h;
}

/** Критический CSS первого экрана: инлайном, чтобы не ждать загрузки style.css. */
function critical_css(): string
{
    return ':root{--brand-600:#1a6fd6;--brand-800:#0f4a96;--amber-500:#e8761a;--steel-200:#c9d2da;--steel-400:#7d8c9b;--head-h:60px;--gut:16px;--max:1320px}'
        . '@media(min-width:768px){:root{--gut:24px;--head-h:72px}}'
        . '*,*::before,*::after{box-sizing:border-box}'
        . 'body{margin:0;font-family:Manrope,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;font-size:16px;line-height:1.55;color:#16191c;background:#fff;overflow-x:clip}'
        . '.container{max-width:var(--max);margin:0 auto;padding:0 var(--gut)}'
        . '.hdr{position:sticky;top:0;z-index:400;background:#fff;color:#16191c;box-shadow:0 1px 0 #dde3e9}'
        . '.hdr a{color:#16191c;text-decoration:none}'
        . '.hdr__top{display:flex;align-items:center;gap:12px;min-height:var(--head-h)}'
        . '.hdr__spacer{flex:1 1 auto}'
        . '.logo{display:flex;align-items:center;gap:10px;font-weight:800}'
        . '.logo__img{width:auto;height:36px}'
        . '.hero{position:relative;overflow:hidden;background:var(--brand-800);color:#fff;padding:32px 0 36px}'
        . '.pband{position:relative;background:var(--brand-800);min-height:120px}'
        . 'h1{margin:0 0 .5em;font-weight:800;line-height:1.15;font-size:clamp(1.6rem,1.25rem + 1.8vw,2.6rem)}'
        . '.hero h1{color:#fff}'
        . '[hidden]{display:none!important}';
}

function metrika_html(): string
{
    $id = (string)S::$company['analytics']['yandex_metrika'];
    if ($id === '') return '';
    // Счётчик грузится отложенно (после первого взаимодействия или через 3 с) — чтобы не портить LCP.
    return '<script>(function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};m[i].l=1*new Date();'
        . 'var f=function(){if(m.__ymLoaded)return;m.__ymLoaded=1;k=e.createElement(t),a=e.getElementsByTagName(t)[0];k.async=1;k.src=r;a.parentNode.insertBefore(k,a);'
        . 'm[i](' . $id . ',"init",{clickmap:true,trackLinks:true,accurateTrackBounce:true,webvisor:true,ecommerce:"dataLayer"});};'
        . '["scroll","mousemove","touchstart","keydown"].forEach(function(ev){e.addEventListener(ev,f,{once:true,passive:true})});setTimeout(f,3000);'
        . '})(window,document,"script","https://mc.yandex.ru/metrika/tag.js","ym");</script>'
        . '<noscript><div><img src="https://mc.yandex.ru/watch/' . $id . '" style="position:absolute;left:-9999px" alt=""></div></noscript>';
}
