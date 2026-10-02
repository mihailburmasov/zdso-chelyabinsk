<?php
// Каталог: нормализация номеров чертежей, узлы, карточки, фильтры, поисковый индекс.
declare(strict_types=1);

/**
 * Нормализация обозначения для поиска и сравнения:
 * убираем всё, кроме цифр и латиницы, похожие кириллические буквы приводим к латинице.
 * Запись с точками, пробелами и дефисами даёт одну и ту же строку.
 */
function norm_draw(string $s): string
{
    static $map = [
        'А' => 'A', 'В' => 'B', 'С' => 'C', 'Е' => 'E', 'Н' => 'H', 'К' => 'K', 'М' => 'M',
        'О' => 'O', 'Р' => 'P', 'Т' => 'T', 'Х' => 'X', 'У' => 'Y', 'І' => 'I',
    ];
    $s = mb_strtoupper($s, 'UTF-8');
    $s = strtr($s, $map);
    return (string)preg_replace('~[^0-9A-Z]~u', '', $s);
}

/** Нормализация произвольной поисковой строки (слова + обозначения). */
function norm_query(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    return (string)preg_replace('~\s+~u', ' ', $s);
}

/** Все нормализованные обозначения детали, по которым её можно найти. */
function item_keys(array $i): array
{
    $keys = [];
    foreach (array_merge([$i['draw'] ?? ''], $i['draw_alt'] ?? []) as $d) {
        $d = (string)$d;
        if ($d === '') continue;
        $n = norm_draw($d);
        if ($n !== '' && !in_array($n, $keys, true)) $keys[] = $n;
        // 12-значная форма прайса ↔ 10-значная форма каталога
        if (preg_match('~^(\d{10})\d{2}$~', $n, $m) && !in_array($m[1], $keys, true)) $keys[] = $m[1];
        if (preg_match('~^\d{10}$~', $n) && !in_array($n . '00', $keys, true)) $keys[] = $n . '00';
    }
    return $keys;
}

/** Узел, в который входит деталь. */
function item_unit(array $i): string
{
    $map = S::$units['by_section'] ?? [];
    $group = '';
    foreach ($map as $slugPart => $key) if (str_contains($i['section'], '/' . $slugPart . '/')) { $group = $key; break; }
    if ($group === '' || empty(S::$units[$group])) return '';
    $name = mb_strtolower($i['name'], 'UTF-8');
    foreach (S::$units[$group] as $u) {
        foreach ($u['match'] as $needle) if (str_contains($name, mb_strtolower($needle, 'UTF-8'))) return $u['name'];
    }
    return '';
}

function stock_badge(array $i): string
{
    return ($i['stock'] ?? 'in_stock') === 'in_stock'
        ? '<span class="badge badge--stock">В наличии</span>'
        : '<span class="badge badge--order">Изготовление' . (!empty($i['lead']) ? ' · ' . esc($i['lead']) : '') . '</span>';
}

function price_html(array $i, string $cls = 'pitem__price'): string
{
    return $i['price'] !== null
        ? '<span class="' . $cls . '">' . number_format((float)$i['price'], 0, ',', ' ') . ' ₽</span>'
        : '<span class="' . $cls . ' ' . $cls . '--ask">Цена по запросу</span>';
}

/** Текст сообщения для мессенджера по карточке товара. */
function item_msg_text(array $i): string
{
    $s = 'Здравствуйте! Интересует: ' . $i['name'];
    if (!empty($i['draw']) && !str_contains($i['name'], (string)$i['draw'])) $s .= ' ' . $i['draw'];
    if (!empty($i['model']) && !str_contains($i['name'], (string)$i['model'])) $s .= ' (' . $i['model'] . ')';
    return $s . '. Нужна цена и срок.';
}

/** Карточка товара в листинге. Данные фильтров — в data-атрибутах, фильтрация на клиенте. */
function item_card(array $i, bool $eager = false): string
{
    $unit = item_unit($i);
    $photo = $i['photos'][0] ?? '';
    $h = '<article class="pitem" data-item'
        . ' data-model="' . esc($i['model']) . '"'
        . ' data-unit="' . esc($unit) . '"'
        . ' data-material="' . esc($i['material']) . '"'
        . ' data-weight="' . ($i['weight'] !== null ? (float)$i['weight'] : '') . '"'
        . ' data-stock="' . esc($i['stock']) . '"'
        . ' data-price="' . ($i['price'] !== null ? (float)$i['price'] : '') . '"'
        . ' data-name="' . esc(mb_strtolower($i['name'], 'UTF-8')) . '"'
        . ' data-keys="' . esc(implode(' ', item_keys($i))) . '">';
    $h .= '<a class="pitem__ph" href="' . href($i['url']) . '" tabindex="-1" aria-hidden="true">' . cat_img($photo, $i['name'], ['eager' => $eager, 'ph' => 'Нужно фото детали']) . '</a>';
    $h .= '<div class="pitem__body">';
    $h .= '<a class="pitem__name" href="' . href($i['url']) . '">' . esc($i['name']) . '</a>';
    if (!empty($i['draw'])) $h .= '<div class="pitem__draw">Чертёж ' . esc($i['draw']) . '</div>';
    $meta = [];
    if (!empty($i['model'])) $meta[] = esc($i['model']);
    if ($unit !== '') $meta[] = esc($unit);
    if ($i['weight'] !== null) $meta[] = 'масса ' . esc(fmt_weight((float)$i['weight']));
    if (!empty($i['qty'])) $meta[] = 'на машину ' . esc($i['qty']);
    if ($meta) $h .= '<div class="pitem__meta">' . implode('<span aria-hidden="true">·</span>', array_map(fn($m) => '<span>' . $m . '</span>', $meta)) . '</div>';
    $h .= '</div>';
    $h .= '<div class="pitem__foot">' . stock_badge($i) . price_html($i)
        . '<button type="button" class="btn btn--ghost btn--sm" data-modal="kp" data-subject="' . esc($i['name']) . '" style="margin-left:auto">В заявку</button></div>';
    $h .= '</article>';
    return $h;
}

function fmt_weight(float $w): string
{
    return ($w === floor($w) ? (string)(int)$w : rtrim(rtrim(number_format($w, 3, ',', ' '), '0'), ',')) . ' кг';
}

/** Все товары раздела и его подразделов. */
function section_items(string $url): array
{
    $out = S::$itemsBySection[$url] ?? [];
    foreach (S::$childSections[$url] ?? [] as $c) $out = array_merge($out, section_items($c['url']));
    return $out;
}

function section_path(string $url): array
{
    $trail = [];
    $cur = $url;
    while ($cur && isset(S::$sections[$cur])) {
        $trail[] = S::$sections[$cur];
        $cur = S::$sections[$cur]['parent'] ?? null;
    }
    return array_reverse($trail);
}

function section_crumbs(string $url, ?array $item = null): array
{
    $c = [['name' => 'Главная', 'url' => '/']];
    foreach (section_path($url) as $s) $c[] = ['name' => $s['name'], 'url' => $s['url']];
    if ($item) $c[] = ['name' => $item['name'], 'url' => $item['url']];
    return $c;
}

/** Блок фильтров: собирается из фактических значений товаров раздела. */
function filters_html(array $items): string
{
    $models = $units = $materials = $stocks = [];
    $wMin = null; $wMax = null; $hasPrice = false;
    foreach ($items as $i) {
        if (!empty($i['model'])) $models[$i['model']] = ($models[$i['model']] ?? 0) + 1;
        $u = item_unit($i);
        if ($u !== '') $units[$u] = ($units[$u] ?? 0) + 1;
        if (!empty($i['material'])) $materials[$i['material']] = ($materials[$i['material']] ?? 0) + 1;
        $stocks[$i['stock']] = ($stocks[$i['stock']] ?? 0) + 1;
        if ($i['weight'] !== null) { $wMin = $wMin === null ? (float)$i['weight'] : min($wMin, (float)$i['weight']); $wMax = max((float)$wMax, (float)$i['weight']); }
        if ($i['price'] !== null) $hasPrice = true;
    }
    ksort($models, SORT_NATURAL); ksort($units); ksort($materials);

    $group = function (string $label, string $key, array $vals): string {
        if (count($vals) < 2) return '';
        $h = '<div class="fgroup"><b>' . esc($label) . '</b>';
        foreach ($vals as $v => $n) {
            $h .= '<label class="fopt"><input type="checkbox" data-filter="' . esc($key) . '" value="' . esc($v) . '"><span>' . esc($v) . '</span><em>' . (int)$n . '</em></label>';
        }
        return $h . '</div>';
    };

    $h = '<aside class="filters" data-filters><h2>Подбор</h2>';
    $h .= $group('Модель техники', 'model', $models);
    $h .= $group('Узел', 'unit', $units);
    $h .= $group('Материал', 'material', $materials);
    if ($wMax) {
        $h .= '<div class="fgroup"><b>Масса, кг</b><div class="fnum">'
            . '<input type="number" inputmode="decimal" min="0" step="0.1" placeholder="от ' . esc(rtrim(rtrim(number_format((float)$wMin, 1, '.', ''), '0'), '.')) . '" data-filter="wmin" aria-label="Масса от, кг">'
            . '<input type="number" inputmode="decimal" min="0" step="0.1" placeholder="до ' . esc((string)(int)$wMax) . '" data-filter="wmax" aria-label="Масса до, кг">'
            . '</div></div>';
    }
    if (count($stocks) > 1) {
        $h .= '<div class="fgroup"><b>Наличие</b>'
            . '<label class="fopt"><input type="checkbox" data-filter="stock" value="in_stock"><span>В наличии</span><em>' . (int)($stocks['in_stock'] ?? 0) . '</em></label>'
            . '<label class="fopt"><input type="checkbox" data-filter="stock" value="made_to_order"><span>Под изготовление</span><em>' . (int)($stocks['made_to_order'] ?? 0) . '</em></label></div>';
    }
    if ($hasPrice) {
        $h .= '<div class="fgroup"><b>Цена</b><label class="fopt"><input type="checkbox" data-filter="hasprice" value="1"><span>Только с ценой на сайте</span></label></div>';
    }
    $h .= '<div class="fgroup"><b>Номер чертежа</b><div class="fnum"><input type="search" class="mono" placeholder="например, 4844802022" data-filter="draw" aria-label="Фильтр по номеру чертежа"></div></div>';
    $h .= '<div class="filters__foot"><button type="button" class="btn btn--ghost btn--sm" data-filters-reset>Сбросить</button>'
        . '<button type="button" class="btn btn--dark btn--sm filters-toggle" data-filters-close>Показать</button></div>';
    return $h . '</aside>';
}

function toolbar_html(int $count): string
{
    return '<div class="toolbar">'
        . '<button type="button" class="btn btn--ghost btn--sm filters-toggle" data-filters-open>' . icon('filter') . ' Подбор</button>'
        . '<span class="toolbar__count"><b data-count>' . $count . '</b> <span data-count-total>из ' . $count . '</span></span>'
        . '<span class="toolbar__right">'
        . '<label class="visually-hidden" for="sortsel">Сортировка</label>'
        . '<select class="select" id="sortsel" data-sort>'
        . '<option value="name">По названию</option>'
        . '<option value="draw">По номеру чертежа</option>'
        . '<option value="weight-asc">Масса: по возрастанию</option>'
        . '<option value="weight-desc">Масса: по убыванию</option>'
        . '<option value="price-asc">Цена: по возрастанию</option>'
        . '</select>'
        . '<span class="viewtog" role="group" aria-label="Вид списка">'
        . '<button type="button" data-view="grid" aria-pressed="true" aria-label="Плиткой">' . icon('grid') . '</button>'
        . '<button type="button" data-view="list" aria-pressed="false" aria-label="Списком">' . icon('list') . '</button>'
        . '</span></span></div>';
}

/** Поисковый индекс: товары, разделы, модели техники, услуги, статьи. */
function search_index(): string
{
    $rows = [];
    foreach (S::$items as $i) {
        $rows[] = [
            't' => 'i',
            'u' => href($i['url']),
            'n' => $i['name'],
            'd' => (string)$i['draw'],
            'k' => implode(' ', item_keys($i)),
            'm' => (string)$i['model'],
            's' => (S::$sections[$i['section']]['name'] ?? ''),
            'p' => $i['price'] !== null ? (float)$i['price'] : null,
            'st' => $i['stock'],
        ];
    }
    foreach (S::$sections as $s) {
        if ($s['url'] === '/catalog/') continue;
        $rows[] = ['t' => 's', 'u' => href($s['url']), 'n' => $s['name'], 'd' => '', 'k' => '', 'm' => (string)$s['model'], 's' => 'Раздел каталога'];
    }
    foreach (S::$tehnika as $m) {
        $rows[] = ['t' => 'm', 'u' => href('/tehnika/' . $m['slug'] . '/'), 'n' => 'Запчасти ' . $m['name'],
            'd' => '', 'k' => norm_draw(implode(' ', $m['aliases'])), 'm' => $m['name'], 's' => $m['kind'],
            'al' => implode(' ', $m['aliases'])];
    }
    foreach (S::$services as $s) $rows[] = ['t' => 'v', 'u' => href($s['url']), 'n' => $s['name'], 'd' => '', 'k' => '', 'm' => '', 's' => 'Услуга'];
    foreach (S::$articles as $a) $rows[] = ['t' => 'a', 'u' => href($a['url']), 'n' => $a['title'], 'd' => '', 'k' => '', 'm' => '', 's' => 'База знаний'];
    return (string)json_encode(['v' => S::$buildDate, 'rows' => $rows], JSON_FLAGS);
}
