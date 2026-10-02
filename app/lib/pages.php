<?php
// Страницы сайта. Каждая функция собирает одну страницу (или группу) и отдаёт её в emit_page().
declare(strict_types=1);

function validate_data(): void
{
    $err = [];
    $c = S::$company;
    foreach (['brand', 'legal_full', 'inn', 'ogrn', 'phone_display', 'phone_href', 'email', 'legal_address'] as $k) {
        if (empty($c[$k])) $err[] = "company.json: пустое поле $k";
    }
    if (!preg_match('~^\+?\d{11}$~', (string)$c['phone_href'])) $err[] = 'company.json: phone_href должен быть в виде +7XXXXXXXXXX';
    if (!filter_var($c['email'], FILTER_VALIDATE_EMAIL)) $err[] = 'company.json: email не похож на адрес';
    foreach (S::$company['messengers'] as $k => $m) {
        $u = trim((string)($m['url'] ?? ''));
        if ($u !== '' && !preg_match('~^https://~', $u)) $err[] = "company.json: мессенджер $k — ссылка должна начинаться с https://";
    }
    foreach (S::$items as $u => $i) {
        if (!isset(S::$sections[$i['section']])) $err[] = "Товар $u ссылается на несуществующий раздел {$i['section']}";
        if (trim((string)$i['name']) === '') $err[] = "Товар $u без названия";
    }
    foreach (S::$sections as $u => $s) {
        if (!empty($s['parent']) && !isset(S::$sections[$s['parent']])) $err[] = "Раздел $u ссылается на несуществующий родитель {$s['parent']}";
    }
    foreach (S::$tehnika as $m) {
        if ($m['parts_section'] !== '' && !isset(S::$sections[$m['parts_section']])) $err[] = "Модель {$m['slug']}: нет раздела {$m['parts_section']}";
    }

    // Адрес страницы собирается из слага, поэтому пустой или кривой слаг — это
    // либо страница без адреса, либо запись, которая затирает соседнюю.
    $slugs = [
        'Модель техники' => [S::$tehnika, 'name'],
        'Услуга' => [S::$services, 'name'],
        'Новость' => [S::$news, 'title'],
        'Статья' => [S::$articles, 'title'],
    ];
    foreach ($slugs as $what => [$list, $titleKey]) {
        $seen = [];
        foreach ($list as $x) {
            $slug = (string)($x['slug'] ?? '');
            $label = (string)($x[$titleKey] ?? '(без названия)');
            if ($slug === '') { $err[] = "{$what} «{$label}»: не заполнен адрес страницы (латиницей, например smd-108a)"; continue; }
            if (!preg_match('~^[a-z0-9-]+$~', $slug)) {
                $err[] = "{$what} «{$label}»: в адресе «{$slug}» можно использовать только латинские буквы, цифры и дефис";
                continue;
            }
            if (isset($seen[$slug])) $err[] = "{$what} «{$label}»: адрес «{$slug}» уже занят записью «{$seen[$slug]}»";
            $seen[$slug] = $label;
        }
    }
    if ($err) throw new RuntimeException("Данные не прошли проверку:\n - " . implode("\n - ", $err));
}

/* ----------------------------------------------------------------- главная */

function page_home(): void
{
    $p = S::$pages['home'];
    $c = S::$company;

    $b = '<section class="hero"><div class="container">';
    $b .= '<div class="hero__grid"><div>';
    $b .= '<h1>' . esc($p['h1']) . '</h1>';
    $b .= '<p class="hero__lead">' . esc($p['lead']) . '</p>';
    $b .= '<div class="hero__search">' . search_widget('hero-search', 'Номер чертежа, артикул или модель') . '</div>';
    $b .= '<p class="hero__hint">' . esc($p['search_hint']) . '</p>';
    $b .= '<div class="actions"><a class="btn btn--primary" href="' . href('/catalog/') . '">Подобрать запчасть</a>';
    $m = messengers('Здравствуйте! Отправляю чертёж на расчёт.');
    $wa = $m[0]['url'];
    $b .= $wa !== ''
        ? '<a class="btn btn--light" href="' . esc($wa) . '" target="_blank" rel="noopener" data-goal="click_whatsapp">Отправить чертёж в WhatsApp</a>'
        : '<button type="button" class="btn btn--light" data-modal="drawing">Отправить чертёж</button>';
    $b .= '</div></div>';
    $b .= '<div class="hero__facts">' . join_map($c['numbers'], fn($n) => '<div class="fact"><b>' . esc($n['value']) . '</b><span>' . esc($n['label']) . '</span></div>') . '</div>';
    $b .= '</div></div></section>';

    // Быстрый вход по моделям техники
    $b .= '<section class="section"><div class="container"><div class="section__head"><h2>Быстрый вход по модели техники</h2>'
        . '<a class="section__more" href="' . href('/tehnika/') . '">Вся техника ' . icon('arrow') . '</a></div>';
    $b .= '<div class="grid grid--models">' . join_map(S::$tehnika, function ($t) {
        $n = count(section_items($t['parts_section']));
        return '<a class="tile" href="' . href('/tehnika/' . $t['slug'] . '/') . '"><b>' . esc($t['name']) . '</b>'
            . '<span>' . esc($t['kind']) . ' · ' . n_pos($n) . '</span></a>';
    }) . '</div></div></section>';

    // Направления каталога
    $b .= '<section class="section section--alt"><div class="container"><div class="section__head"><h2>Каталог</h2>'
        . '<a class="section__more" href="' . href('/catalog/') . '">Весь каталог ' . icon('arrow') . '</a></div><div class="grid grid--2">';
    foreach (S::$childSections['/catalog/'] ?? [] as $s) {
        $n = count(section_items($s['url']));
        $b .= '<a class="card" href="' . href($s['url']) . '"><h3>' . esc($s['name']) . '</h3>'
            . '<p>' . esc($s['intro'] ?: $s['text_old']) . '</p>'
            . '<span class="card__more">' . ($n ? n_pos($n) . ' ' : 'Смотреть ') . icon('arrow') . '</span></a>';
    }
    $b .= '</div></div></section>';

    // Услуги
    $b .= '<section class="section"><div class="container"><div class="section__head"><h2>Услуги производства</h2>'
        . '<a class="section__more" href="' . href('/services/') . '">Все услуги ' . icon('arrow') . '</a></div><div class="grid grid--2">';
    foreach (S::$services as $s) {
        $b .= '<a class="card" href="' . href($s['url']) . '"><h3>' . esc($s['name']) . '</h3><p>' . esc($s['short']) . '</p>'
            . '<span class="card__more">Подробнее ' . icon('arrow') . '</span></a>';
    }
    $b .= '</div></div></section>';

    // Производство
    $b .= '<section class="section section--alt"><div class="container"><div class="section__head"><h2>Производство, а не перепродажа</h2>'
        . '<a class="section__more" href="' . href('/company/production/') . '">О производстве ' . icon('arrow') . '</a></div>';
    $b .= '<div class="grid grid--2">' . join_map($p['intro_blocks'], fn($x) => '<div class="card"><h3>' . esc($x['h']) . '</h3><p>' . esc($x['text']) . '</p></div>') . '</div>';
    $b .= '</div></section>';

    // Новости
    if (S::$news) {
        $b .= '<section class="section"><div class="container"><div class="section__head"><h2>Отгрузки и новости</h2>'
            . '<a class="section__more" href="' . href('/info/news/') . '">Все записи ' . icon('arrow') . '</a></div><div class="grid grid--3">';
        foreach (array_slice(S::$news, 0, 3) as $n) {
            $b .= '<a class="card newscard" href="' . href($n['url']) . '"><time datetime="' . esc($n['date']) . '">' . esc(date_ru($n['date'])) . '</time>'
                . '<h3>' . esc($n['title']) . '</h3><p>' . esc($n['lead']) . '</p></a>';
        }
        $b .= '</div></div></section>';
    }

    // База знаний
    $b .= '<section class="section section--alt"><div class="container"><div class="section__head"><h2>База знаний</h2>'
        . '<a class="section__more" href="' . href('/info/articles/') . '">Все статьи ' . icon('arrow') . '</a></div><div class="grid grid--3">';
    foreach (array_slice(S::$articles, 0, 3) as $a) {
        $b .= '<a class="card" href="' . href($a['url']) . '"><h3>' . esc($a['title']) . '</h3><p>' . esc($a['lead']) . '</p></a>';
    }
    $b .= '</div></div></section>';

    // FAQ
    $b .= '<section class="section"><div class="container"><h2>Частые вопросы</h2>' . faq_html(array_slice(S::$faq, 0, 6))
        . '<p><a href="' . href('/info/faq/') . '">Все вопросы и ответы ' . icon('arrow') . '</a></p></div></section>';

    // SEO-текст
    $b .= '<section class="section section--alt"><div class="container"><div class="prose"><h2>Завод дробильно-сортировочного оборудования, Челябинск</h2>'
        . '<p>' . esc($p['seo_text']) . '</p></div></div></section>';

    $b .= cta_block(['page' => '/', 'file' => true, 'kind' => 'drawing', 'btn' => 'Отправить заявку', 'title' => 'Нужна деталь или расчёт?']);

    emit_page('/', shell([
        'path' => '/',
        'title' => $p['title'],
        'description' => $p['description'],
        'body' => $b,
        'ld' => [faq_ld(array_slice(S::$faq, 0, 6))],
    ]), ['priority' => '1.0', 'group' => 'pages']);
}

/* ---------------------------------------------------------------- каталог */

function page_catalog_section(array $s): void
{
    $kids = S::$childSections[$s['url']] ?? [];
    $own = S::$itemsBySection[$s['url']] ?? [];
    $all = section_items($s['url']);
    $crumbs = section_crumbs($s['url']);
    $teh = S::$tehBySection[$s['url']] ?? null;
    $isRoot = $s['url'] === '/catalog/';

    $title = $s['title'] ?: ($isRoot
        ? 'Каталог запчастей для дробильно-сортировочного оборудования | ЗДСО'
        : $s['name'] . ' — купить с доставкой по России | Завод ДСО, Челябинск');
    $desc = $s['description'] ?: section_meta_desc($s, $all);

    $b = '<div class="container">' . crumbs($crumbs) . '</div>';
    $b .= '<div class="container"><h1>' . esc($s['name']) . '</h1>';
    if ($s['intro'] !== '' || $s['text_old'] !== '') $b .= '<p class="lead">' . esc($s['intro'] ?: $s['text_old']) . '</p>';
    if ($teh) {
        $b .= '<p><a class="btn btn--ghost btn--sm" href="' . href('/tehnika/' . $teh['slug'] . '/') . '">'
            . icon('gear') . ' Страница модели ' . esc($teh['name']) . ': ТТХ, узлы, таблица деталей</a></p>';
    }
    $b .= '</div>';

    if ($kids) {
        $b .= '<section class="section section--tight"><div class="container"><div class="grid grid--2">';
        foreach ($kids as $k) {
            $n = count(section_items($k['url']));
            $b .= '<a class="card" href="' . href($k['url']) . '"><h3>' . esc($k['name']) . '</h3>'
                . ($k['intro'] || $k['text_old'] ? '<p>' . esc($k['intro'] ?: $k['text_old']) . '</p>' : '')
                . '<span class="card__more">' . ($n ? n_pos($n) . ' ' : 'Смотреть ') . icon('arrow') . '</span></a>';
        }
        $b .= '</div></div></section>';
    }

    if ($own) {
        $b .= '<section class="section section--tight"><div class="container"><div class="cat">';
        $b .= filters_html($own);
        $b .= '<div><div id="items">' . toolbar_html(count($own))
            . '<div class="items" data-items>' . join_map($own, fn($i, $k) => item_card($i, $k < 4)) . '</div>'
            . '<p class="empty" data-empty hidden>Под эти условия ничего не нашлось. Сбросьте часть фильтров или напишите нам — подберём по номеру чертежа.</p>'
            . '</div></div></div></div></section>';
    } elseif (!$kids) {
        $b .= '<section class="section section--tight"><div class="container"><p class="empty">В этом разделе пока нет отдельных позиций на сайте. Напишите, что нужно — подберём и посчитаем.</p></div></section>';
    }

    if ($s['text'] !== '') {
        $b .= '<section class="section section--alt"><div class="container"><div class="prose">' . $s['text'] . '</div></div></section>';
    }

    $b .= cta_block(['page' => $s['url'], 'subject' => 'Раздел: ' . $s['name'], 'kind' => 'question',
        'title' => 'Не нашли нужную позицию?', 'text' => 'Пришлите номер чертежа или фото бирки — проверим наличие и посчитаем изготовление.']);

    $ld = [crumbs_ld($crumbs)];
    if ($own) {
        $ld[] = [
            '@context' => 'https://schema.org', '@type' => 'ItemList',
            'name' => $s['name'],
            'numberOfItems' => count($own),
            'itemListElement' => array_map(fn($i, $k) => [
                '@type' => 'ListItem', 'position' => $k + 1, 'name' => $i['name'], 'url' => abs_url($i['url']),
            ], array_slice($own, 0, 100), array_keys(array_slice($own, 0, 100))),
        ];
    }

    emit_page($s['url'], shell([
        'path' => $s['url'], 'title' => $title, 'description' => $desc, 'body' => $b, 'ld' => $ld,
    ]), ['priority' => $isRoot ? '0.9' : '0.8', 'group' => 'catalog']);
}

function section_meta_desc(array $s, array $items): string
{
    $n = count($items);
    return $s['name'] . ': ' . ($n ? n_pos($n) . ' с номерами чертежей, массой и наличием. ' : '')
        . 'Собственное производство в Челябинске, отгрузка по России.';
}

function page_catalog_item(array $i): void
{
    $sec = S::$sections[$i['section']];
    $crumbs = section_crumbs($i['section'], $i);
    $unit = item_unit($i);
    $teh = null;
    foreach (S::$tehnika as $m) if ($m['name'] === $i['model']) { $teh = $m; break; }
    $msg = item_msg_text($i);

    $title = $i['title'] ?: trim($i['name'] . ($i['model'] && !str_contains($i['name'], $i['model']) ? ' для ' . $i['model'] : '')) . ' — купить в Челябинске | ЗДСО';
    $descParts = [$i['name']];
    if (!empty($i['draw']) && !str_contains($i['name'], (string)$i['draw'])) $descParts[] = 'чертёж ' . $i['draw'];
    if ($i['weight'] !== null) $descParts[] = 'масса ' . fmt_weight((float)$i['weight']);
    if (!empty($i['qty'])) $descParts[] = 'на машину ' . $i['qty'];
    $descParts[] = $i['price'] !== null ? 'цена на сайте' : 'цена по запросу';
    $desc = $i['description'] ?: (implode(', ', $descParts) . '. Производство в Челябинске, отгрузка по России.');

    $b = '<div class="container">' . crumbs($crumbs) . '</div>';
    $b .= '<section class="section section--tight"><div class="container"><div class="prod">';

    // галерея
    $b .= '<div class="prod__gal">';
    $photos = $i['photos'];
    $b .= '<div class="prod__main" data-gal-main>' . cat_img($photos[0] ?? '', $i['name'], ['eager' => true, 'ph' => 'Нужно фото реальной детали']) . '</div>';
    if (count($photos) > 1) {
        $b .= '<div class="prod__thumbs" role="group" aria-label="Фотографии">' . join_map($photos, function ($p, $k) use ($i) {
            return '<button type="button" data-gal-thumb="' . esc(href($p)) . '" aria-pressed="' . ($k === 0 ? 'true' : 'false') . '">'
                . cat_img($p, $i['name'] . ' — фото ' . ($k + 1)) . '</button>';
        }) . '</div>';
    }
    $b .= '</div>';

    // правая колонка
    $b .= '<div><h1>' . esc($i['name']) . '</h1>';
    if (!empty($i['draw'])) {
        $b .= '<div class="drawbox"><span class="drawbox__l">Номер чертежа</span><span class="drawbox__v mono">' . esc($i['draw']) . '</span>'
            . '<button type="button" class="btn btn--ghost btn--sm copy-btn" data-copy="' . esc($i['draw']) . '">' . icon('copy') . '<span>Скопировать</span></button></div>';
    }

    $spec = [];
    if (!empty($i['draw'])) $spec[] = ['Номер чертежа', '<span class="mono">' . esc($i['draw']) . '</span>'];
    if (!empty($i['draw_alt'])) $spec[] = ['Альтернативные обозначения', '<span class="mono">' . esc(implode(', ', $i['draw_alt'])) . '</span>'];
    if (!empty($i['model'])) $spec[] = ['Модель техники', $teh ? '<a href="' . href('/tehnika/' . $teh['slug'] . '/') . '">' . esc($i['model']) . '</a>' : esc($i['model'])];
    if ($unit !== '') $spec[] = ['Узел', esc($unit)];
    if (!empty($i['material'])) $spec[] = ['Материал', esc($i['material'])];
    if ($i['weight'] !== null) $spec[] = ['Масса', esc(fmt_weight((float)$i['weight']))];
    if (!empty($i['qty'])) $spec[] = ['Количество на машину', esc($i['qty'])];
    if (!empty($i['dims'])) $spec[] = ['Габариты', esc($i['dims'])];
    if (!empty($i['gost'])) $spec[] = ['Стандарт', esc($i['gost'])];
    $spec[] = ['Раздел каталога', '<a href="' . href($sec['url']) . '">' . esc($sec['name']) . '</a>'];
    $b .= '<table class="spec"><caption class="visually-hidden">Характеристики</caption><tbody>'
        . join_map($spec, fn($r) => '<tr><th>' . $r[0] . '</th><td>' . $r[1] . '</td></tr>') . '</tbody></table>';

    $b .= '<div class="prod__buy">';
    $b .= $i['price'] !== null
        ? '<p class="prod__price">' . number_format((float)$i['price'], 0, ',', ' ') . ' ₽</p><p class="prod__pricenote">Цена из прайс-листа. Уточняйте актуальность при заказе.</p>'
        : '<p class="prod__price prod__price--ask">Цена по запросу</p><p class="prod__pricenote">Цена зависит от марки материала, количества и срока. Ответим с ценой и сроком.</p>';
    $b .= '<p>' . stock_badge($i) . '</p>';
    $b .= '<div class="actions"><button type="button" class="btn btn--primary" data-modal="kp" data-subject="' . esc($i['name']) . '" data-comment="' . esc($msg) . '">Запросить КП</button>'
        . '<a class="btn btn--ghost" href="' . phone_href() . '" data-goal="click_phone">' . icon('phone') . ' ' . esc(phone_display()) . '</a></div>';
    $b .= messenger_buttons($msg);
    $b .= '</div>';

    if ($i['about'] !== '') $b .= '<div class="prose"><h2>Назначение детали</h2><p>' . esc($i['about']) . '</p></div>';
    $b .= '</div></div></div></section>';

    // сопутствующие детали того же узла
    $same = [];
    foreach (S::$itemsBySection[$i['section']] ?? [] as $o) {
        if ($o['url'] === $i['url']) continue;
        if ($unit !== '' && item_unit($o) !== $unit) continue;
        $same[] = $o;
    }
    if ($same) {
        $b .= '<section class="section section--alt"><div class="container"><h2>' . ($unit !== '' ? 'Другие детали узла «' . esc($unit) . '»' : 'Другие детали этого раздела') . '</h2>'
            . '<div class="items">' . join_map(array_slice($same, 0, 8), fn($o) => item_card($o)) . '</div>'
            . '<p style="margin-top:14px"><a href="' . href($sec['url']) . '">Все позиции раздела «' . esc($sec['name']) . '» ' . icon('arrow') . '</a></p></div></section>';
    }

    $ld = [crumbs_ld($crumbs), product_ld($i, $sec)];
    $b .= cta_block([
        'page' => $i['url'], 'kind' => 'kp', 'subject' => $i['name'], 'comment' => $msg,
        'msgText' => $msg, 'title' => 'Запросить цену и срок на эту деталь',
        'text' => 'Ответим с ценой, наличием и сроком изготовления. Можно просто прислать фото бирки в мессенджер.',
        'btn' => 'Запросить КП',
    ]);

    emit_page($i['url'], shell([
        'path' => $i['url'], 'title' => $title, 'description' => $desc, 'body' => $b, 'ld' => $ld,
        'ogType' => 'product',
        'ogImage' => !empty($photos) ? abs_url($photos[0]) : null,
    ]), ['priority' => '0.7', 'group' => 'catalog']);
}

/** Product + Offer. Offer с ценой — только там, где цена есть: иначе валидаторы ругаются. */
function product_ld(array $i, array $sec): array
{
    $ld = [
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $i['name'],
        'url' => abs_url($i['url']),
        'category' => $sec['name'],
        'brand' => ['@type' => 'Brand', 'name' => S::$company['brand']],
        'manufacturer' => ['@id' => abs_url('/#org')],
    ];
    if (!empty($i['draw'])) { $ld['mpn'] = $i['draw']; $ld['sku'] = $i['draw']; }
    if (!empty($i['material'])) $ld['material'] = $i['material'];
    if ($i['weight'] !== null) $ld['weight'] = ['@type' => 'QuantitativeValue', 'value' => (float)$i['weight'], 'unitCode' => 'KGM'];
    if (!empty($i['photos'])) $ld['image'] = array_map('abs_url', $i['photos']);
    if ($i['about'] !== '') $ld['description'] = $i['about'];
    if ($i['price'] !== null) {
        $ld['offers'] = [
            '@type' => 'Offer',
            'url' => abs_url($i['url']),
            'price' => (float)$i['price'],
            'priceCurrency' => 'RUB',
            'availability' => $i['stock'] === 'in_stock' ? 'https://schema.org/InStock' : 'https://schema.org/PreOrder',
            'seller' => ['@id' => abs_url('/#org')],
        ];
    }
    return $ld;
}

/* ---------------------------------------------------------- модели техники */

function page_tehnika_hub(): void
{
    $crumbs = [['name' => 'Главная', 'url' => '/'], ['name' => 'Техника', 'url' => '/tehnika/']];
    $b = '<div class="container">' . crumbs($crumbs) . '</div>';
    $b .= '<div class="container"><h1>Техника, под которую есть запчасти</h1>'
        . '<p class="lead">По каждой машине — полная таблица деталей с номерами чертежей, массой и количеством на машину. Это та страница, с которой удобно собирать комплект.</p></div>';
    $b .= '<section class="section"><div class="container"><div class="grid grid--2">';
    foreach (S::$tehnika as $t) {
        $n = count(section_items($t['parts_section']));
        $b .= '<a class="card" href="' . href('/tehnika/' . $t['slug'] . '/') . '"><h3>' . esc($t['name']) . '</h3>'
            . '<p>' . esc($t['kind']) . '. ' . n_pos($n) . ' запчастей в каталоге.</p>'
            . '<span class="card__more">Таблица деталей ' . icon('arrow') . '</span></a>';
    }
    $b .= '</div></div></section>';
    $b .= cta_block(['page' => '/tehnika/', 'kind' => 'komplekt', 'title' => 'Подобрать комплект на машину',
        'text' => 'Напишите модель и что меняете — посчитаем комплект целиком, с массой и сроками.', 'btn' => 'Подобрать комплект']);

    emit_page('/tehnika/', shell([
        'path' => '/tehnika/',
        'title' => 'Запчасти по моделям техники: СМД, КСД, КМД, П-804, ТК-16А, ДС | ЗДСО',
        'description' => 'Запчасти для щековых дробилок СМД-108А, СМД-109А, СМД-110А, конусных КСД-600, КСД-900, КСД-1200, КМД-1200, питателей П-804 и ТК-16А, оборудования АБЗ ДС-158 и ДС-185.',
        'body' => $b, 'ld' => [crumbs_ld($crumbs)],
    ]), ['priority' => '0.9', 'group' => 'tehnika']);
}

function page_tehnika(array $t): void
{
    $url = '/tehnika/' . $t['slug'] . '/';
    $items = section_items($t['parts_section']);
    $sec = S::$sections[$t['parts_section']] ?? null;
    $crumbs = [['name' => 'Главная', 'url' => '/'], ['name' => 'Техника', 'url' => '/tehnika/'], ['name' => $t['name'], 'url' => $url]];

    // группировка по узлам
    $byUnit = [];
    foreach ($items as $i) {
        $u = item_unit($i) ?: 'Прочие детали';
        $byUnit[$u][] = $i;
    }
    uasort($byUnit, fn($a, $b) => count($b) <=> count($a));

    $b = '<div class="container">' . crumbs($crumbs) . '</div>';
    $b .= '<div class="container"><h1>Запчасти для ' . esc($t['name']) . '</h1>'
        . '<p class="lead">' . esc($t['intro']) . '</p>'
        . '<div class="actions"><button type="button" class="btn btn--primary" data-modal="komplekt" data-subject="Комплект запчастей на ' . esc($t['name']) . '">Подобрать комплект</button>'
        . ($sec ? '<a class="btn btn--ghost" href="' . href($sec['url']) . '">Раздел каталога ' . icon('arrow') . '</a>' : '')
        . '</div></div>';

    if (!empty($t['ttx'])) {
        $b .= '<section class="section section--tight"><div class="container"><h2>Технические характеристики</h2>'
            . '<div class="prose"><table><tbody>' . join_map($t['ttx'], fn($r) => '<tr><th>' . esc($r[0]) . '</th><td>' . esc($r[1]) . '</td></tr>') . '</tbody></table></div></div></section>';
    }

    if (count($byUnit) > 1) {
        $b .= '<section class="section section--tight"><div class="container"><h2>Узлы машины</h2><div class="units">';
        foreach ($byUnit as $u => $list) {
            $b .= '<div class="unit"><b>' . esc($u) . '</b><span>' . n_pos(count($list)) . '</span></div>';
        }
        $b .= '</div></div></section>';
    }

    // полная таблица деталей
    $b .= '<section class="section"><div class="container"><h2>Все детали ' . esc($t['name']) . ' — ' . n_pos(count($items)) . '</h2>'
        . '<div class="tfilter"><label class="visually-hidden" for="tf-' . esc($t['slug']) . '">Фильтр по номеру или названию</label>'
        . '<input type="search" id="tf-' . esc($t['slug']) . '" placeholder="Фильтр: номер чертежа или название детали" data-table-filter="#teh-table">'
        . '<a class="btn btn--ghost" href="' . href('/price/') . '">' . icon('download') . ' Прайс-лист</a></div>'
        . '<div class="tscroll"><table class="tbl" id="teh-table"><thead><tr>'
        . '<th class="sticky-col wrap">Наименование</th><th>Номер чертежа</th><th>Узел</th><th class="num">Масса, кг</th><th>На машину</th><th class="num">Цена</th><th>Наличие</th>'
        . '</tr></thead><tbody>';
    foreach ($byUnit as $u => $list) {
        foreach ($list as $i) {
            $b .= '<tr data-row="' . esc(mb_strtolower($i['name'], 'UTF-8') . ' ' . implode(' ', item_keys($i))) . '">'
                . '<td class="sticky-col wrap"><a href="' . href($i['url']) . '">' . esc($i['name']) . '</a></td>'
                . '<td class="mono">' . esc($i['draw'] ?: '—') . '</td>'
                . '<td>' . esc($u) . '</td>'
                . '<td class="num">' . ($i['weight'] !== null ? esc(rtrim(rtrim(number_format((float)$i['weight'], 3, ',', ' '), '0'), ',')) : '—') . '</td>'
                . '<td>' . esc($i['qty'] ?: '—') . '</td>'
                . '<td class="num">' . ($i['price'] !== null ? esc(number_format((float)$i['price'], 0, ',', ' ')) . ' ₽' : 'по запросу') . '</td>'
                . '<td>' . stock_badge($i) . '</td></tr>';
        }
    }
    $b .= '</tbody></table></div>';
    $b .= '<p class="small muted" style="margin-top:10px">Масса и количество на машину — из заводского прайс-листа, где эти данные есть. Если по позиции данных нет, уточним по запросу.</p>';
    $b .= '</div></section>';

    $b .= cta_block([
        'page' => $url, 'kind' => 'komplekt', 'subject' => 'Комплект запчастей на ' . $t['name'],
        'msgText' => 'Здравствуйте! Нужен комплект запчастей на ' . $t['name'] . '. Подскажите цену и срок.',
        'title' => 'Собрать комплект на ' . $t['name'],
        'text' => 'Отметьте нужные позиции или просто опишите, что меняете — посчитаем комплект с массой и сроками.',
        'btn' => 'Подобрать комплект', 'file' => true,
    ]);

    emit_page($url, shell([
        'path' => $url,
        'title' => 'Запчасти для ' . $t['name'] . ' — каталог с номерами чертежей и ценами | ЗДСО',
        'description' => 'Запчасти для ' . $t['name'] . ' (' . mb_strtolower($t['kind']) . '): ' . count($items)
            . ' позиций с номерами чертежей, массой и количеством на машину. Собственное производство, Челябинск.',
        'body' => $b, 'ld' => [crumbs_ld($crumbs), [
            '@context' => 'https://schema.org', '@type' => 'ItemList',
            'name' => 'Запчасти для ' . $t['name'], 'numberOfItems' => count($items),
            'itemListElement' => array_map(fn($i, $k) => ['@type' => 'ListItem', 'position' => $k + 1, 'name' => $i['name'], 'url' => abs_url($i['url'])],
                array_slice($items, 0, 100), array_keys(array_slice($items, 0, 100))),
        ]],
    ]), ['priority' => '0.9', 'group' => 'tehnika']);
}

/* ----------------------------------------------------------------- услуги */

function page_services_hub(): void
{
    $crumbs = [['name' => 'Главная', 'url' => '/'], ['name' => 'Услуги', 'url' => '/services/']];
    $b = '<div class="container">' . crumbs($crumbs) . '</div>';
    $b .= '<div class="container"><h1>Услуги производства</h1>'
        . '<p class="lead">Литьё, механообработка, ремонт и плетение сетки — на собственных участках в Челябинске и Бакале.</p></div>';
    $b .= '<section class="section"><div class="container"><div class="grid grid--2">';
    foreach (S::$services as $s) {
        $b .= '<a class="card" href="' . href($s['url']) . '"><h3>' . esc($s['name']) . '</h3><p>' . esc($s['short']) . '</p>'
            . '<span class="card__more">Подробнее ' . icon('arrow') . '</span></a>';
    }
    $b .= '</div></div></section>';
    $b .= cta_block(['page' => '/services/', 'kind' => 'drawing', 'file' => true, 'title' => 'Прислать чертёж на расчёт', 'btn' => 'Отправить чертёж']);

    emit_page('/services/', shell([
        'path' => '/services/',
        'title' => 'Услуги: литьё, механообработка, ремонт дробилок, плетение сетки | ЗДСО',
        'description' => 'Литьё по чертежам заказчика (110Г13Л, 15…45Л, СЧ15…25, ИЧХ16), механообработка на ЧПУ, капремонт дробилок и питателей, плетение рифлёной сетки. Челябинск.',
        'body' => $b, 'ld' => [crumbs_ld($crumbs)],
    ]), ['priority' => '0.8', 'group' => 'pages']);
}

function page_service(array $s): void
{
    $crumbs = [['name' => 'Главная', 'url' => '/'], ['name' => 'Услуги', 'url' => '/services/'], ['name' => $s['name'], 'url' => $s['url']]];
    $b = '<div class="container">' . crumbs($crumbs) . '</div>';
    $b .= '<div class="container"><h1>' . esc($s['name']) . '</h1><p class="lead">' . esc($s['lead']) . '</p>'
        . '<div class="actions"><button type="button" class="btn btn--primary" data-modal="drawing" data-subject="' . esc($s['name']) . '">' . esc($s['cta']) . '</button>'
        . '<a class="btn btn--ghost" href="' . phone_href() . '" data-goal="click_phone">' . icon('phone') . ' ' . esc(phone_display()) . '</a></div></div>';
    $b .= '<section class="section"><div class="container"><div class="prose">' . blocks_html($s['blocks']) . '</div></div></section>';
    if (!empty($s['steps'])) {
        $b .= '<section class="section section--alt"><div class="container"><h2>Как это происходит</h2>'
            . '<ol class="steps">' . join_map($s['steps'], fn($x) => '<li>' . esc($x) . '</li>') . '</ol></div></section>';
    }
    if (!empty($s['faq'])) {
        $b .= '<section class="section"><div class="container"><h2>Вопросы по услуге</h2>' . faq_html($s['faq']) . '</div></section>';
    }
    $b .= cta_block(['page' => $s['url'], 'kind' => 'drawing', 'file' => true, 'subject' => $s['name'],
        'msgText' => 'Здравствуйте! Интересует услуга: ' . $s['name'] . '.',
        'title' => $s['cta'], 'btn' => 'Отправить заявку']);

    $ld = [crumbs_ld($crumbs), [
        '@context' => 'https://schema.org', '@type' => 'Service',
        'name' => $s['name'], 'description' => $s['short'],
        'provider' => ['@id' => abs_url('/#org')],
        'areaServed' => ['@type' => 'Country', 'name' => 'Россия'],
        'url' => abs_url($s['url']),
    ]];
    if (!empty($s['faq'])) $ld[] = faq_ld($s['faq']);

    emit_page($s['url'], shell([
        'path' => $s['url'], 'title' => $s['title'], 'description' => $s['description'], 'body' => $b, 'ld' => $ld,
    ]), ['priority' => '0.8', 'group' => 'pages']);
}

/* -------------------------------------------------------------- прайс-лист */

function price_table_html(array $groups, string $id): string
{
    $h = '<div class="tfilter"><label class="visually-hidden" for="' . esc($id) . '-f">Фильтр по номеру или названию</label>'
        . '<input type="search" id="' . esc($id) . '-f" placeholder="Фильтр: номер чертежа или название детали" data-table-filter="#' . esc($id) . '">'
        . '<a class="btn btn--ghost" href="' . href('/price/price-zdso.csv') . '" download>' . icon('download') . ' Скачать CSV</a></div>';
    $h .= '<div class="tscroll"><table class="tbl" id="' . esc($id) . '"><thead><tr>'
        . '<th class="sticky-col wrap">Наименование детали</th><th>Номер чертежа</th><th>Кол-во</th><th class="num">Вес, кг</th><th class="num">Цена за ед.</th>'
        . '</tr></thead><tbody>';
    foreach ($groups as $g) {
        $h .= '<tr class="tbl__group" data-row="' . esc(mb_strtolower($g['model'], 'UTF-8')) . '"><td class="sticky-col" colspan="5">' . esc($g['model']) . '</td></tr>';
        foreach ($g['rows'] as $r) {
            $h .= '<tr data-row="' . esc(mb_strtolower($r['name'], 'UTF-8') . ' ' . norm_draw((string)$r['draw']) . ' ' . mb_strtolower($g['model'], 'UTF-8')) . '">'
                . '<td class="sticky-col wrap">' . esc($r['name']) . '</td>'
                . '<td class="mono">' . esc(($r['draw'] ?? '') ?: '—') . '</td>'
                . '<td>' . esc($r['qty'] ?? '') . '</td>'
                . '<td class="num">' . (($r['weight'] ?? null) !== null ? esc(rtrim(rtrim(number_format((float)$r['weight'], 3, ',', ' '), '0'), ',')) : '—') . '</td>'
                . '<td class="num">' . (($r['price'] ?? null) !== null ? esc(number_format((float)$r['price'], 0, ',', ' ')) . ' ₽' : 'по запросу') . '</td></tr>';
        }
    }
    return $h . '</tbody></table></div>';
}

function page_price(): void
{
    $p = S::$pages['price'];
    $crumbs = [['name' => 'Главная', 'url' => '/'], ['name' => 'Прайс-лист', 'url' => '/price/']];
    $b = '<div class="container">' . crumbs($crumbs) . '</div>';
    $b .= '<div class="container"><h1>' . esc($p['h1']) . '</h1><p class="lead">' . esc($p['lead']) . '</p>'
        . '<div class="grid grid--2" style="margin:18px 0">'
        . '<a class="card" href="' . href('/price/drobilki-shchekovye/') . '"><h3>Дробилки щековые</h3><p>СМД-108А, СМД-109А, СМД-110А — 146 позиций с массой и количеством на машину.</p><span class="card__more">Открыть ' . icon('arrow') . '</span></a>'
        . '<a class="card" href="' . href('/price/drobilki-konusnye/') . '"><h3>Дробилки конусные</h3><p>КСД-600, КСД-900 и КМД-900, КСД-1200 и КМД-1200 — номенклатура в каталоге по каждой модели.</p><span class="card__more">Открыть ' . icon('arrow') . '</span></a>'
        . '</div></div>';
    $b .= '<section class="section section--tight"><div class="container">' . price_table_html(S::$price['groups'], 'price-all') . '</div></section>';
    $b .= '<div class="container"><div class="note"><p>' . esc($p['valid_note']) . '</p></div></div>';
    $b .= cta_block(['page' => '/price/', 'kind' => 'kp', 'title' => 'Запросить цены по своему списку', 'btn' => 'Отправить список', 'file' => true]);

    emit_page('/price/', shell(['path' => '/price/', 'title' => $p['title'], 'description' => $p['description'], 'body' => $b, 'ld' => [crumbs_ld($crumbs)]]),
        ['priority' => '0.8', 'group' => 'pages']);
}

function page_price_shchekovye(): void
{
    $p = S::$pages['price_shchekovye'];
    $crumbs = [['name' => 'Главная', 'url' => '/'], ['name' => 'Прайс-лист', 'url' => '/price/'], ['name' => 'Дробилки щековые', 'url' => $p['url']]];
    $b = '<div class="container">' . crumbs($crumbs) . '</div>';
    $b .= '<div class="container"><h1>' . esc($p['h1']) . '</h1><p class="lead">' . esc($p['lead']) . '</p></div>';
    $b .= '<section class="section section--tight"><div class="container">' . price_table_html(S::$price['groups'], 'price-shch') . '</div></section>';
    $b .= '<div class="container"><p class="small muted">Позиции с ценой — по данным прайс-листа; остальные считаем по запросу. Карточки этих деталей с фотографиями — в <a href="' . href('/catalog/zapasnye-chasti-i-komplektuyushchie/zapchasti-dlya-drobilok/drobilki-shchekovye/') . '">каталоге</a>.</p></div>';
    $b .= cta_block(['page' => $p['url'], 'kind' => 'kp', 'title' => 'Запросить цены по списку', 'btn' => 'Отправить список', 'file' => true]);

    emit_page($p['url'], shell(['path' => $p['url'], 'title' => $p['title'], 'description' => $p['description'], 'body' => $b, 'ld' => [crumbs_ld($crumbs)]]),
        ['priority' => '0.7', 'group' => 'pages']);
}

function page_price_konusnye(): void
{
    $p = S::$pages['price_konusnye'];
    $crumbs = [['name' => 'Главная', 'url' => '/'], ['name' => 'Прайс-лист', 'url' => '/price/'], ['name' => 'Дробилки конусные', 'url' => $p['url']]];
    $b = '<div class="container">' . crumbs($crumbs) . '</div>';
    $b .= '<div class="container"><h1>' . esc($p['h1']) . '</h1><p class="lead">' . esc($p['lead']) . '</p></div>';
    $b .= '<section class="section"><div class="container"><div class="grid grid--2">';
    foreach (S::$tehnika as $t) {
        if ($t['kind'] !== 'Конусная дробилка') continue;
        $n = count(section_items($t['parts_section']));
        $b .= '<a class="card" href="' . href('/tehnika/' . $t['slug'] . '/') . '"><h3>' . esc($t['name']) . '</h3>'
            . '<p>' . n_pos($n) . ' с номерами чертежей и массой.</p><span class="card__more">Таблица деталей ' . icon('arrow') . '</span></a>';
    }
    $b .= '</div><div class="note" style="margin-top:18px"><p>' . esc($p['pending_note']) . '</p></div></div></section>';
    $b .= cta_block(['page' => $p['url'], 'kind' => 'kp', 'title' => 'Запросить цены на запчасти конусных дробилок', 'btn' => 'Отправить запрос', 'file' => true]);

    emit_page($p['url'], shell(['path' => $p['url'], 'title' => $p['title'], 'description' => $p['description'], 'body' => $b, 'ld' => [crumbs_ld($crumbs)]]),
        ['priority' => '0.6', 'group' => 'pages']);
}

/* ---------------------------------------------------------------- компания */

function simple_page(string $key, callable $content, array $o = []): void
{
    $p = S::$pages[$key];
    $crumbs = $o['crumbs'] ?? [['name' => 'Главная', 'url' => '/'], ['name' => $p['h1'], 'url' => $p['url']]];
    $b = '<div class="container">' . crumbs($crumbs) . '</div>';
    $b .= '<div class="container"><h1>' . esc($p['h1']) . '</h1>'
        . (!empty($p['lead']) ? '<p class="lead">' . esc($p['lead']) . '</p>' : '') . '</div>';
    $b .= $content($p);
    if (empty($o['noCta'])) {
        $b .= cta_block(array_merge(['page' => $p['url']], $o['cta'] ?? []));
    }
    emit_page($p['url'], shell([
        'path' => $p['url'], 'title' => $p['title'], 'description' => $p['description'] ?? '',
        'body' => $b, 'ld' => array_merge([crumbs_ld($crumbs)], $o['ld'] ?? []), 'noindex' => $o['noindex'] ?? false,
    ]), ['priority' => $o['priority'] ?? '0.6', 'group' => $o['group'] ?? 'pages', 'sitemap' => $o['sitemap'] ?? true]);
}

function company_crumbs(string $name, string $url): array
{
    return [['name' => 'Главная', 'url' => '/'], ['name' => 'Компания', 'url' => '/company/'], ['name' => $name, 'url' => $url]];
}

function page_company(): void
{
    simple_page('company', fn($p) => '<section class="section"><div class="container"><div class="prose">' . blocks_html($p['blocks']) . '</div></div></section>'
        . '<section class="section section--alt"><div class="container"><div class="grid grid--2">'
        . '<a class="card" href="' . href('/company/production/') . '"><h3>Производство</h3><p>Участки, станочный парк, фотографии цехов.</p><span class="card__more">Смотреть ' . icon('arrow') . '</span></a>'
        . '<a class="card" href="' . href('/company/certificates/') . '"><h3>Сертификаты и документы</h3><p>Документы на продукцию — по запросу.</p><span class="card__more">Смотреть ' . icon('arrow') . '</span></a>'
        . '<a class="card" href="' . href('/company/requisites/') . '"><h3>Реквизиты</h3><p>ИНН, ОГРН, банковские реквизиты.</p><span class="card__more">Смотреть ' . icon('arrow') . '</span></a>'
        . '<a class="card" href="' . href('/services/') . '"><h3>Услуги</h3><p>Литьё, механообработка, ремонт, сетка.</p><span class="card__more">Смотреть ' . icon('arrow') . '</span></a>'
        . '</div></div></section>', ['priority' => '0.8', 'cta' => ['kind' => 'drawing', 'file' => true, 'title' => 'Прислать чертёж на расчёт', 'btn' => 'Отправить чертёж']]);
}

function page_production(): void
{
    simple_page('production', function ($p) {
        $h = '<section class="section"><div class="container"><div class="grid grid--2">'
            . join_map($p['sections'], fn($s) => '<div class="card"><h3>' . esc($s['h']) . '</h3><p>' . esc($s['text']) . '</p></div>') . '</div>';
        if (!empty($p['photos_pending'])) $h .= '<div style="margin-top:20px">' . pending_block($p['photos_note']) . '</div>';
        $h .= '</div></section>';
        return $h;
    }, ['crumbs' => company_crumbs('Производство', '/company/production/'), 'priority' => '0.7',
        'cta' => ['kind' => 'drawing', 'file' => true, 'title' => 'Прислать чертёж на расчёт', 'btn' => 'Отправить чертёж']]);
}

function page_certificates(): void
{
    simple_page('certificates', fn($p) => '<section class="section"><div class="container">'
        . (!empty($p['items']) ? '<ul class="gal">' . join_map($p['items'], fn($x) => '<li>' . cat_img($x['photo'] ?? '', $x['title'] ?? 'Сертификат') . '<p class="small">' . esc($x['title'] ?? '') . '</p></li>') . '</ul>' : '')
        . (!empty($p['items_pending']) ? pending_block($p['items_note']) : '')
        . '</div></section>', ['crumbs' => company_crumbs('Сертификаты', '/company/certificates/'), 'priority' => '0.5',
        'cta' => ['kind' => 'question', 'title' => 'Нужен документ на позицию?', 'text' => 'Напишите, на какую деталь нужен сертификат или паспорт — пришлём копию.', 'btn' => 'Запросить документ']]);
}

function page_partners(): void
{
    simple_page('partners', fn($p) => '<section class="section"><div class="container">'
        . (!empty($p['items_pending']) ? pending_block($p['items_note']) : '') . '</div></section>',
        ['crumbs' => company_crumbs('Партнёры', '/company/partners/'), 'priority' => '0.4', 'noCta' => false,
            'cta' => ['kind' => 'question', 'title' => 'Хотите стать нашим заказчиком?', 'btn' => 'Написать']]);
}

function page_reviews(): void
{
    simple_page('reviews', fn($p) => '<section class="section"><div class="container">'
        . (!empty($p['items']) ? '<div class="grid grid--2">' . join_map($p['items'], fn($r) => '<figure class="card"><blockquote>' . esc($r['text'] ?? '') . '</blockquote><figcaption class="small muted">' . esc($r['author'] ?? '') . '</figcaption></figure>') . '</div>' : '')
        . (!empty($p['items_pending']) ? pending_block($p['items_note']) : '') . '</div></section>',
        ['crumbs' => company_crumbs('Отзывы клиентов', '/company/reviews/'), 'priority' => '0.5',
            'cta' => ['kind' => 'question', 'title' => 'Работали с нами?', 'text' => 'Напишите отзыв — опубликуем с вашего разрешения.', 'btn' => 'Оставить отзыв']]);
}

function page_staff(): void
{
    simple_page('staff', function ($p) {
        $c = S::$company;
        $h = '<section class="section"><div class="container"><div class="grid grid--2">';
        $h .= '<div class="card"><h3>Приём заявок</h3><p class="ftr__contact">' . phone_link('', false) . '<br>'
            . '<a href="mailto:' . esc(company_email()) . '" data-goal="click_email">' . esc(company_email()) . '</a><br>' . esc($c['schedule']) . '</p>'
            . messenger_buttons('Здравствуйте! Вопрос по запчастям.') . '</div>';
        if (!empty($p['people'])) {
            foreach ($p['people'] as $x) {
                $h .= '<div class="card"><h3>' . esc($x['name']) . '</h3><p>' . esc($x['role'] ?? '') . '</p>'
                    . (!empty($x['phone']) ? '<p><a href="tel:' . esc($x['phone']) . '">' . esc($x['phone_display'] ?? $x['phone']) . '</a></p>' : '') . '</div>';
            }
        }
        $h .= '</div>';
        if (!empty($p['people_pending'])) $h .= '<div style="margin-top:20px">' . pending_block($p['people_note']) . '</div>';
        return $h . '</div></section>';
    }, ['crumbs' => company_crumbs('Отдел продаж', '/company/staff/'), 'priority' => '0.5',
        'cta' => ['kind' => 'question', 'title' => 'Задать вопрос менеджеру', 'btn' => 'Задать вопрос']]);
}

function page_requisites(): void
{
    simple_page('requisites', function () {
        $c = S::$company;
        $rows = [
            ['Полное наименование', $c['legal_full']],
            ['Сокращённое наименование', $c['legal_short']],
            ['ИНН / КПП', $c['inn'] . ' / ' . $c['kpp']],
            ['ОГРН', $c['ogrn']],
            ['Юридический адрес', $c['legal_address']],
            ['Фактический адрес', $c['legal_address']],
            ['Телефон', $c['phone_display']],
            ['Электронная почта', $c['email']],
            ['Банк', $c['bank']],
            ['БИК', $c['bank_bik']],
            ['Расчётный счёт', $c['bank_account']],
            ['Корреспондентский счёт', $c['bank_corr']],
        ];
        return '<section class="section"><div class="container"><div class="prose"><table><tbody>'
            . join_map($rows, fn($r) => '<tr><th>' . esc($r[0]) . '</th><td>' . esc($r[1]) . '</td></tr>')
            . '</tbody></table></div></div></section>';
    }, ['crumbs' => company_crumbs('Реквизиты', '/company/requisites/'), 'priority' => '0.6', 'noCta' => true]);
}

function page_vacancy(): void
{
    simple_page('vacancy', function () {
        $h = '<section class="section"><div class="container">';
        $group = '';
        foreach (S::$vacancies as $v) {
            if ($v['group'] !== $group) { $group = $v['group']; $h .= '<h2>' . esc($group) . '</h2>'; }
            $h .= '<div class="card" style="margin-bottom:14px"><h3>' . esc($v['title']) . '</h3>'
                . '<p><b>' . esc($v['salary']) . '</b> · ' . esc($v['experience']) . ' · ' . esc($v['schedule']) . '</p>';
            foreach ([['Условия', 'conditions'], ['Требования', 'requirements'], ['Обязанности', 'duties']] as [$label, $key]) {
                if (empty($v[$key])) continue;
                $h .= '<h4>' . esc($label) . '</h4><ul>' . join_map($v[$key], fn($x) => '<li>' . esc($x) . '</li>') . '</ul>';
            }
            $h .= '<button type="button" class="btn btn--ghost btn--sm" data-modal="question" data-subject="Резюме: ' . esc($v['title']) . '">Отправить резюме</button></div>';
        }
        return $h . '</div></section>';
    }, ['crumbs' => company_crumbs('Вакансии', '/company/vacancy/'), 'priority' => '0.4', 'noCta' => true]);
}

/* ---------------------------------------------------------------- контакты */

function page_contacts(): void
{
    $p = S::$pages['contacts'];
    $c = S::$company;
    $crumbs = [['name' => 'Главная', 'url' => '/'], ['name' => 'Контакты', 'url' => '/contacts/']];

    $b = '<div class="container">' . crumbs($crumbs) . '</div>';
    $b .= '<div class="container"><h1>' . esc($p['h1']) . '</h1><p class="lead">' . esc($p['lead']) . '</p></div>';
    $b .= '<section class="section section--tight"><div class="container"><div class="grid grid--2">';
    $b .= '<div class="card"><h3>Связь</h3><p class="ftr__contact">' . phone_link('', false) . '<br>'
        . '<a href="mailto:' . esc(company_email()) . '" data-goal="click_email">' . esc(company_email()) . '</a><br>'
        . esc($c['schedule']) . '</p>' . messenger_buttons('Здравствуйте! Вопрос по запчастям.') . '</div>';
    foreach ($c['offices'] as $o) {
        $b .= '<div class="card"><h3>' . esc($o['kind']) . '</h3><p>' . icon('pin', 'card__ic') . ' ' . esc($o['address']) . '</p>'
            . '<p class="small muted">' . esc($o['note']) . '</p></div>';
    }
    $b .= '</div></div></section>';

    // карта по клику
    $b .= '<section class="section section--tight"><div class="container"><h2>На карте</h2>'
        . '<div class="card" data-map style="align-items:flex-start"><p class="small muted">' . esc($p['map_note']) . '</p>'
        . '<button type="button" class="btn btn--ghost" data-map-load data-src="https://yandex.ru/map-widget/v1/?text='
        . rawurlencode('Челябинск, Комсомольский проспект, 10') . '&z=16">' . icon('pin') . ' Показать карту</button></div></div></section>';

    $b .= '<section class="section"><div class="container"><div class="grid grid--2">'
        . '<div><h2>Отправить заявку</h2><p class="muted">Имя и телефон — всё, что нужно. Остальное по желанию.</p>'
        . form_html(['kind' => 'question', 'id' => 'contacts-form', 'page' => '/contacts/', 'file' => true, 'btn' => 'Отправить заявку']) . '</div>'
        . '<div><h2>Реквизиты</h2><p>' . esc($c['legal_full']) . '<br>ИНН ' . esc($c['inn']) . ' · КПП ' . esc($c['kpp']) . ' · ОГРН ' . esc($c['ogrn']) . '<br>' . esc($c['legal_address']) . '</p>'
        . '<p><a href="' . href('/company/requisites/') . '">Полные реквизиты ' . icon('arrow') . '</a></p>'
        . '<div class="note"><p>' . esc($p['truck_note']) . '</p></div></div>'
        . '</div></div></section>';

    $ld = [crumbs_ld($crumbs), [
        '@context' => 'https://schema.org', '@type' => 'LocalBusiness',
        '@id' => abs_url('/contacts/#local'),
        'name' => $c['brand'], 'legalName' => $c['legal_full'],
        'parentOrganization' => ['@id' => abs_url('/#org')],
        'url' => abs_url('/contacts/'),
        'telephone' => '+' . ltrim((string)$c['phone_href'], '+'),
        'email' => $c['email'],
        'address' => [
            '@type' => 'PostalAddress', 'streetAddress' => 'Комсомольский проспект, д. 10',
            'addressLocality' => 'Челябинск', 'addressRegion' => 'Челябинская область',
            'postalCode' => '454008', 'addressCountry' => 'RU',
        ],
        'openingHoursSpecification' => [[
            '@type' => 'OpeningHoursSpecification',
            'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
            'opens' => '08:00', 'closes' => '17:00',
        ]],
    ]];

    emit_page('/contacts/', shell(['path' => '/contacts/', 'title' => $p['title'], 'description' => $p['description'], 'body' => $b, 'ld' => $ld]),
        ['priority' => '0.9', 'group' => 'pages']);
}

function page_delivery(): void
{
    simple_page('delivery', function ($p) {
        $h = '<section class="section"><div class="container"><div class="prose">' . blocks_html($p['blocks']) . '</div>';
        $h .= '<div style="margin-top:20px">' . pending_block($p['pending_note']) . '</div>';
        return $h . '</div></section>';
    }, ['priority' => '0.7', 'cta' => ['kind' => 'question', 'title' => 'Посчитать отгрузку', 'text' => 'Напишите позицию и город — посчитаем массу, габарит и подскажем по доставке.', 'btn' => 'Посчитать отгрузку']]);
}

/* ------------------------------------------------------------- информация */

function page_info_hub(): void
{
    simple_page('info', fn($p) => '<section class="section"><div class="container"><div class="grid grid--2">'
        . '<a class="card" href="' . href('/info/articles/') . '"><h3>База знаний</h3><p>Как подобрать ячейку сетки, когда менять плиты и брони, чем 110Г13Л отличается от углеродистых сталей.</p><span class="card__more">' . count(S::$articles) . ' статей ' . icon('arrow') . '</span></a>'
        . '<a class="card" href="' . href('/info/news/') . '"><h3>Новости и отгрузки</h3><p>Что изготовили и отгрузили.</p><span class="card__more">' . count(S::$news) . ' записей ' . icon('arrow') . '</span></a>'
        . '<a class="card" href="' . href('/info/stock/') . '"><h3>Спецпредложения</h3><p>Оборудование и запчасти по специальной цене.</p><span class="card__more">Смотреть ' . icon('arrow') . '</span></a>'
        . '<a class="card" href="' . href('/info/faq/') . '"><h3>Вопрос-ответ</h3><p>Частые вопросы снабженцев и главных механиков.</p><span class="card__more">' . count(S::$faq) . ' вопросов ' . icon('arrow') . '</span></a>'
        . '<a class="card" href="' . href('/projects/') . '"><h3>Кейсы</h3><p>Примеры выполненных работ.</p><span class="card__more">Смотреть ' . icon('arrow') . '</span></a>'
        . '</div></div></section>', ['priority' => '0.5', 'noCta' => true]);
}

function page_articles_index(): void
{
    simple_page('articles_index', fn($p) => '<section class="section"><div class="container"><div class="grid grid--2">'
        . join_map(S::$articles, fn($a) => '<a class="card newscard" href="' . href($a['url']) . '">'
            . '<time datetime="' . esc($a['date']) . '">' . esc(date_ru($a['date'])) . '</time>'
            . '<h3>' . esc($a['title']) . '</h3><p>' . esc($a['lead']) . '</p></a>')
        . '</div></div></section>',
        ['crumbs' => [['name' => 'Главная', 'url' => '/'], ['name' => 'Информация', 'url' => '/info/'], ['name' => 'База знаний', 'url' => '/info/articles/']],
            'priority' => '0.7', 'noCta' => true]);
}

function page_article(array $a): void
{
    $crumbs = [['name' => 'Главная', 'url' => '/'], ['name' => 'Информация', 'url' => '/info/'],
        ['name' => 'База знаний', 'url' => '/info/articles/'], ['name' => $a['title'], 'url' => $a['url']]];
    $b = '<div class="container">' . crumbs($crumbs) . '</div>';
    $b .= '<div class="container"><article class="prose"><h1>' . esc($a['title']) . '</h1>'
        . '<p class="small muted"><time datetime="' . esc($a['date']) . '">' . esc(date_ru($a['date'])) . '</time></p>'
        . '<p class="lead">' . esc($a['lead']) . '</p>' . blocks_html($a['body']) . '</article>';
    $links = [];
    if (!empty($a['related_service']) && isset(S::$svcBySlug[$a['related_service']])) {
        $s = S::$svcBySlug[$a['related_service']];
        $links[] = '<a href="' . href($s['url']) . '">Услуга: ' . esc($s['name']) . '</a>';
    }
    if (!empty($a['related_section']) && isset(S::$sections[$a['related_section']])) {
        $s = S::$sections[$a['related_section']];
        $links[] = '<a href="' . href($s['url']) . '">Каталог: ' . esc($s['name']) . '</a>';
    }
    if ($links) $b .= '<p>' . implode(' · ', $links) . '</p>';
    $b .= '</div>';
    $b .= cta_block(['page' => $a['url'], 'kind' => 'question', 'title' => 'Нужна помощь с подбором?', 'btn' => 'Задать вопрос']);

    emit_page($a['url'], shell([
        'path' => $a['url'], 'title' => $a['title'] . ' | Завод ДСО', 'description' => $a['description'],
        'body' => $b, 'ogType' => 'article',
        'ld' => [crumbs_ld($crumbs), [
            '@context' => 'https://schema.org', '@type' => 'Article',
            'headline' => $a['title'], 'description' => $a['description'],
            'datePublished' => $a['date'], 'dateModified' => $a['date'],
            'author' => ['@id' => abs_url('/#org')], 'publisher' => ['@id' => abs_url('/#org')],
            'mainEntityOfPage' => abs_url($a['url']), 'inLanguage' => 'ru-RU',
        ]],
    ]), ['priority' => '0.6', 'group' => 'articles']);
}

function page_news_index(): void
{
    simple_page('news_index', fn($p) => '<section class="section"><div class="container"><div class="grid grid--2">'
        . join_map(S::$news, fn($n) => '<a class="card newscard" href="' . href($n['url']) . '">'
            . '<time datetime="' . esc($n['date']) . '">' . esc(date_ru($n['date'])) . '</time>'
            . '<h3>' . esc($n['title']) . '</h3><p>' . esc($n['lead']) . '</p></a>')
        . '</div></div></section>',
        ['crumbs' => [['name' => 'Главная', 'url' => '/'], ['name' => 'Информация', 'url' => '/info/'], ['name' => 'Новости и отгрузки', 'url' => '/info/news/']],
            'priority' => '0.6', 'noCta' => true]);
}

function page_news(array $n): void
{
    $crumbs = [['name' => 'Главная', 'url' => '/'], ['name' => 'Информация', 'url' => '/info/'],
        ['name' => 'Новости и отгрузки', 'url' => '/info/news/'], ['name' => $n['title'], 'url' => $n['url']]];
    $b = '<div class="container">' . crumbs($crumbs) . '</div>';
    $b .= '<div class="container"><article class="prose"><h1>' . esc($n['title']) . '</h1>'
        . '<p class="small muted"><time datetime="' . esc($n['date']) . '">' . esc(date_ru($n['date'])) . '</time></p>'
        . '<p class="lead">' . esc($n['lead']) . '</p>' . blocks_html($n['body'] ?? []);
    if (!empty($n['photos'])) {
        $b .= '<ul class="gal">' . join_map($n['photos'], fn($p) => '<li>' . cat_img($p, $n['title']) . '</li>') . '</ul>';
    }
    $b .= '</article>';
    $rel = [];
    foreach ($n['models'] ?? [] as $mname) {
        foreach (S::$tehnika as $t) if ($t['name'] === $mname) $rel[] = '<a href="' . href('/tehnika/' . $t['slug'] . '/') . '">Запчасти ' . esc($t['name']) . '</a>';
    }
    if ($rel) $b .= '<p>' . implode(' · ', $rel) . '</p>';
    $b .= '</div>';
    $b .= cta_block(['page' => $n['url'], 'kind' => 'kp', 'title' => 'Нужны такие же детали?', 'btn' => 'Запросить КП']);

    emit_page($n['url'], shell([
        'path' => $n['url'], 'title' => $n['title'] . ' — Завод ДСО, Челябинск',
        'description' => mb_substr($n['lead'], 0, 300), 'body' => $b, 'ogType' => 'article',
        'ld' => [crumbs_ld($crumbs), [
            '@context' => 'https://schema.org', '@type' => 'NewsArticle',
            'headline' => $n['title'], 'description' => $n['lead'],
            'datePublished' => $n['date'], 'dateModified' => $n['date'],
            'author' => ['@id' => abs_url('/#org')], 'publisher' => ['@id' => abs_url('/#org')],
            'mainEntityOfPage' => abs_url($n['url']), 'inLanguage' => 'ru-RU',
        ]],
    ]), ['priority' => '0.4', 'group' => 'news']);
}

function page_faq(): void
{
    simple_page('faq_index', fn($p) => '<section class="section"><div class="container">' . faq_html(S::$faq) . '</div></section>',
        ['crumbs' => [['name' => 'Главная', 'url' => '/'], ['name' => 'Информация', 'url' => '/info/'], ['name' => 'Вопросы и ответы', 'url' => '/info/faq/']],
            'priority' => '0.6', 'ld' => [faq_ld(S::$faq)],
            'cta' => ['kind' => 'question', 'title' => 'Не нашли свой вопрос?', 'btn' => 'Задать вопрос']]);
}

function page_stock(): void
{
    simple_page('stock', fn($p) => '<section class="section"><div class="container"><div class="grid grid--2">'
        . join_map(S::$stock, fn($s) => '<div class="card"><span class="badge badge--new">Спецпредложение</span><h3 style="margin-top:8px">' . esc($s['title']) . '</h3>'
            . '<p>' . esc($s['lead']) . '</p>'
            . (!empty($s['date']) ? '<p class="small muted">Опубликовано ' . esc(date_ru($s['date'])) . '</p>' : '')
            . '<button type="button" class="btn btn--primary btn--sm" data-modal="kp" data-subject="' . esc($s['title']) . '" style="margin-top:auto">' . esc($s['cta']) . '</button></div>')
        . '</div></div></section>',
        ['crumbs' => [['name' => 'Главная', 'url' => '/'], ['name' => 'Информация', 'url' => '/info/'], ['name' => 'Спецпредложения', 'url' => '/info/stock/']],
            'priority' => '0.5', 'noCta' => true]);
}

function page_projects(): void
{
    simple_page('projects', fn($p) => '<section class="section"><div class="container">'
        . (!empty($p['items']) ? '<div class="grid grid--2">' . join_map($p['items'], fn($x) => '<div class="card"><h3>' . esc($x['title'] ?? '') . '</h3><p>' . esc($x['text'] ?? '') . '</p></div>') . '</div>' : '')
        . (!empty($p['items_pending']) ? pending_block($p['items_note']) : '') . '</div></section>',
        ['priority' => '0.5', 'cta' => ['kind' => 'question', 'title' => 'Нужна похожая работа?', 'btn' => 'Обсудить задачу', 'file' => true]]);
}

/* ------------------------------------------------------------- служебные */

function page_search(): void
{
    $p = S::$pages['search'];
    $crumbs = [['name' => 'Главная', 'url' => '/'], ['name' => 'Поиск', 'url' => '/search/']];
    $b = '<div class="container">' . crumbs($crumbs) . '</div>';
    $b .= '<div class="container"><h1>' . esc($p['h1']) . '</h1><p class="lead">' . esc($p['lead']) . '</p>'
        . '<div style="max-width:720px;margin:18px 0">' . search_widget('page-search', 'Например: 4844802022, 484480202200, ТК15А-01-201 или СМД-108А') . '</div>'
        . '<div data-search-page></div>'
        . '<div class="prose" style="margin-top:28px"><h2>Как работает поиск</h2>'
        . '<p>Номер можно вводить как угодно: <span class="mono">4844802022</span>, <span class="mono">484480202200</span>, '
        . '<span class="mono">4844 802 022</span>, <span class="mono">4844-802-022</span> — поиск приводит написание к одной форме, '
        . 'в том числе запись с точками, если номер так указан в вашей документации. '
        . 'Также работает поиск по альтернативному обозначению (например, <span class="mono">ТК15А-01-201</span> или <span class="mono">297-4-0-1</span>), '
        . 'по названию детали и по модели машины.</p>'
        . '<p>Если ничего не нашлось — <a href="' . href('/contacts/') . '">напишите нам</a> или пришлите фото бирки в мессенджер, подберём вручную.</p></div>'
        . '</div>';
    $b .= cta_block(['page' => '/search/', 'kind' => 'question', 'title' => 'Не нашли деталь?', 'btn' => 'Подобрать вручную', 'file' => true]);

    emit_page('/search/', shell(['path' => '/search/', 'title' => $p['title'], 'description' => $p['description'],
        'body' => $b, 'ld' => [crumbs_ld($crumbs)]]), ['priority' => '0.7', 'group' => 'pages']);
}

function page_sitemap_html(): void
{
    simple_page('sitemap', function () {
        $h = '<section class="section"><div class="container"><div class="prose">';
        $h .= '<h2>Каталог</h2><ul>';
        foreach (S::$sections as $s) {
            if ($s['url'] === '/catalog/') continue;
            $depth = count(explode('/', trim(str_replace('/catalog/', '', $s['url']), '/')));
            $h .= '<li style="margin-left:' . (($depth - 1) * 16) . 'px"><a href="' . href($s['url']) . '">' . esc($s['name']) . '</a> <span class="small muted">' . count(S::$itemsBySection[$s['url']] ?? []) . '</span></li>';
        }
        $h .= '</ul><h2>Техника</h2><ul>'
            . join_map(S::$tehnika, fn($t) => '<li><a href="' . href('/tehnika/' . $t['slug'] . '/') . '">Запчасти ' . esc($t['name']) . '</a></li>')
            . '</ul><h2>Услуги</h2><ul>'
            . join_map(S::$services, fn($s) => '<li><a href="' . href($s['url']) . '">' . esc($s['name']) . '</a></li>')
            . '</ul><h2>База знаний</h2><ul>'
            . join_map(S::$articles, fn($a) => '<li><a href="' . href($a['url']) . '">' . esc($a['title']) . '</a></li>')
            . '</ul><h2>Компания и информация</h2><ul>';
        foreach ([
            '/company/' => 'Технические возможности', '/company/production/' => 'Производство',
            '/company/certificates/' => 'Сертификаты', '/company/partners/' => 'Партнёры',
            '/company/reviews/' => 'Отзывы клиентов', '/company/staff/' => 'Отдел продаж',
            '/company/requisites/' => 'Реквизиты', '/company/vacancy/' => 'Вакансии',
            '/price/' => 'Прайс-лист', '/delivery/' => 'Доставка и оплата', '/contacts/' => 'Контакты',
            '/info/' => 'Информация', '/info/news/' => 'Новости и отгрузки', '/info/faq/' => 'Вопрос-ответ',
            '/info/stock/' => 'Спецпредложения', '/projects/' => 'Кейсы', '/search/' => 'Поиск по номеру чертежа',
            '/politika-konfidentsialnosti/' => 'Политика конфиденциальности', '/info/processing/' => 'Согласие на обработку данных',
        ] as $u => $label) {
            $h .= '<li><a href="' . href($u) . '">' . esc($label) . '</a></li>';
        }
        return $h . '</ul></div></div></section>';
    }, ['priority' => '0.3', 'noCta' => true]);
}

function legal_html(array $blocks): string
{
    $c = S::$company;
    $vars = [
        '{{legal_full}}' => $c['legal_full'], '{{legal_short}}' => $c['legal_short'],
        '{{inn}}' => $c['inn'], '{{ogrn}}' => $c['ogrn'],
        '{{legal_address}}' => $c['legal_address'], '{{email}}' => $c['email'],
    ];
    $sub = fn(string $s): string => esc(strtr($s, $vars));
    return join_map($blocks, function ($b) use ($sub) {
        $h = isset($b['h']) ? '<h2>' . $sub($b['h']) . '</h2>' : '';
        foreach ($b['p'] ?? [] as $p) $h .= '<p>' . $sub($p) . '</p>';
        if (!empty($b['list'])) $h .= '<ul>' . join_map($b['list'], fn($l) => '<li>' . $sub($l) . '</li>') . '</ul>';
        return $h;
    });
}

function page_privacy(): void
{
    $p = S::$pages['privacy'];
    $l = S::$legal['privacy'];
    $crumbs = [['name' => 'Главная', 'url' => '/'], ['name' => $p['h1'], 'url' => $p['url']]];
    $b = '<div class="container">' . crumbs($crumbs) . '</div>'
        . '<div class="container"><article class="prose"><h1>' . esc($p['h1']) . '</h1>'
        . '<p class="small muted">Редакция от ' . esc(date_ru($l['revision'])) . '</p>'
        . legal_html($l['blocks']) . '</article></div>';
    emit_page($p['url'], shell(['path' => $p['url'], 'title' => $p['title'], 'description' => $p['description'], 'body' => $b, 'ld' => [crumbs_ld($crumbs)]]),
        ['priority' => '0.2', 'group' => 'pages']);
}

function page_consent(): void
{
    $p = S::$pages['consent'];
    $l = S::$legal['consent'];
    $crumbs = [['name' => 'Главная', 'url' => '/'], ['name' => 'Информация', 'url' => '/info/'], ['name' => $p['h1'], 'url' => $p['url']]];
    $b = '<div class="container">' . crumbs($crumbs) . '</div>'
        . '<div class="container"><article class="prose"><h1>' . esc($p['h1']) . '</h1>'
        . '<p class="small muted">Редакция от ' . esc(date_ru($l['revision'])) . '</p>'
        . legal_html($l['blocks']) . '</article></div>';
    emit_page($p['url'], shell(['path' => $p['url'], 'title' => $p['title'], 'description' => $p['description'], 'body' => $b, 'ld' => [crumbs_ld($crumbs)]]),
        ['priority' => '0.2', 'group' => 'pages']);
}

function page_not_found(): void
{
    $p = S::$pages['e404'];
    $b = '<div class="container"><section class="section"><h1>' . esc($p['h1']) . '</h1><p class="lead">' . esc($p['lead']) . '</p>'
        . '<div style="max-width:680px;margin:20px 0">' . search_widget('e404-search', 'Номер чертежа или модель') . '</div>'
        . '<div class="grid grid--2">'
        . '<a class="card" href="' . href('/catalog/') . '"><h3>Каталог</h3><p>Все разделы запчастей и оборудования.</p></a>'
        . '<a class="card" href="' . href('/tehnika/') . '"><h3>Техника</h3><p>Таблицы деталей по моделям машин.</p></a>'
        . '<a class="card" href="' . href('/price/') . '"><h3>Прайс-лист</h3><p>Номера чертежей, масса, количество на машину.</p></a>'
        . '<a class="card" href="' . href('/contacts/') . '"><h3>Контакты</h3><p>Телефон, почта, мессенджеры.</p></a>'
        . '</div></section></div>';
    emit_page('/404.html', shell(['path' => '/404.html', 'title' => $p['title'], 'body' => $b, 'noindex' => true]), ['sitemap' => false]);
}
