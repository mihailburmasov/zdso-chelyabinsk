<?php
// Каталог в админке: раздел = одна запись. Товары раздела лежат в data/catalog/items/<ключ>.json
// и правятся карточками внутри формы раздела — так менеджер работает с одним разделом за раз.
declare(strict_types=1);

/** Ключ файла раздела: путь после /catalog/, слеши заменены на двойной дефис. */
function catsec_key(string $url): string
{
    $k = trim(str_replace('/catalog/', '', $url), '/');
    return $k === '' ? 'catalog' : str_replace('/', '--', $k);
}

function catsec_file(string $key): string { return "catalog/items/$key.json"; }

/** Все разделы каталога в порядке дерева, с числом товаров. */
function catsec_all(): array
{
    $sections = doc_get('catalog/sections.json');
    $out = [];
    foreach ($sections as $key => $s) {
        $items = is_file(data_path(catsec_file($key))) ? doc_get(catsec_file($key)) : [];
        $depth = substr_count(trim(str_replace('/catalog/', '', $s['url']), '/'), '/');
        $out[] = [
            'slug' => $key,
            'title' => $s['name'],
            'url' => $s['url'],
            'short' => $s['intro'] ?: '—',
            'count' => count($items),
            'depth' => $s['url'] === '/catalog/' ? 0 : $depth + 1,
            'hidden' => false,
        ];
    }
    return $out;
}

function catsec_find(string $key): array
{
    $sections = doc_get('catalog/sections.json');
    if (!isset($sections[$key])) throw new InvalidArgumentException('Раздел каталога не найден');
    return [$key, $sections[$key]];
}

/** Поля формы раздела: сам раздел + карточки его товаров. */
function catsec_fields(): array
{
    return array_merge(
        [
            head('Раздел каталога', 'Адрес раздела не меняется — он уже в поиске. Менять можно название и тексты.'),
            f('text', 'catalog/sections.json#{svc}.name', 'Название раздела', 'Показывается в меню, в крошках и в заголовке H1'),
            f('textarea', 'catalog/sections.json#{svc}.intro', 'Короткое вступление', 'Одно-два предложения под заголовком и в карточке раздела', ['rows' => 3]),
            f('textarea', 'catalog/sections.json#{svc}.text', 'Текст под списком товаров', 'Можно с тегами <h2>, <p>, <ul>. 1500–2500 знаков помогают разделу в поиске', ['rows' => 10]),
            f('photo', 'catalog/sections.json#{svc}.photo', 'Фото раздела', 'Показывается в карточке раздела'),
            head('Для поисковиков', 'Если оставить пустым — соберутся автоматически из названия и числа позиций.'),
            f('text', 'catalog/sections.json#{svc}.title', 'Заголовок во вкладке и в поиске', 'Оптимально 50–70 знаков', ['counter' => 70]),
            f('textarea', 'catalog/sections.json#{svc}.description', 'Описание в поиске', 'Оптимально 140–170 знаков', ['counter' => 170, 'rows' => 3]),
            head('Товары раздела', 'Каждая карточка — отдельная страница на сайте. Адрес страницы собирается из названия при создании и дальше не меняется.'),
            f('items', 'catalog/items/{svc}.json', 'Позиции', '', [
                'itemName' => 'Позиция',
                'collapsed' => true,
                'item' => catalog_item_fields(),
            ]),
        ],
    );
}

function catalog_item_fields(): array
{
    return [
        k('text', 'name', 'Название', 'Как на бирке: «Плита дробящая подвижная 4844802022»'),
        k('text', 'draw', 'Номер чертежа', 'Основной номер. Поиск найдёт его в любом написании'),
        k('list', 'draw_alt', 'Альтернативные обозначения', 'Второй номер той же детали: 12-значная форма, обозначение по старой документации'),
        k('text', 'model', 'Модель техники', 'Например: СМД-108А'),
        k('text', 'material', 'Материал', 'Например: 110Г13Л'),
        k('number', 'weight', 'Масса, кг'),
        k('text', 'qty', 'Количество на машину', 'Например: 2 шт.'),
        k('text', 'dims', 'Габариты'),
        k('text', 'gost', 'ГОСТ'),
        k('number', 'price', 'Цена, ₽', 'Пусто — на сайте будет «цена по запросу»'),
        k('text', 'stock', 'Наличие', 'in_stock — в наличии, made_to_order — под изготовление'),
        k('text', 'lead', 'Срок изготовления', 'Например: 14 дней. Показывается, если наличие — «под изготовление»'),
        k('textarea', 'about', 'Назначение детали', '2–4 предложения: в какой узел входит, что происходит при износе', ['rows' => 4]),
        k('photos', 'photos', 'Фотографии', 'Первая показывается в списке и в карточке'),
        k('bool', 'hidden', 'Скрыть с сайта'),
    ];
}

/** Новая позиция в разделе. Возвращает адрес страницы. */
function catalog_item_create(string $key, string $name): string
{
    $name = trim($name);
    if (mb_strlen($name) < 3) throw new InvalidArgumentException('Введите название позиции');
    [, $sec] = catsec_find($key);
    $slug = translit_slug($name);
    $file = catsec_file($key);
    $items = is_file(data_path($file)) ? doc_get($file) : [];
    $taken = array_column($items, 'slug');
    $base = $slug; $n = 2;
    while (in_array($slug, $taken, true)) $slug = $base . '-' . $n++;

    $draw = '';
    if (preg_match('~\b(\d{7,12}(?:[-/]\d{1,2})?)\b~', $name, $m)) $draw = $m[1];

    $items[] = [
        'url' => $sec['url'] . $slug . '/',
        'slug' => $slug,
        'section' => $sec['url'],
        'type' => 'part',
        'name' => $name,
        'name_source' => 'admin',
        'draw' => $draw,
        'draw_alt' => [],
        'model' => $sec['model'] ?? '',
        'material' => '',
        'weight' => null,
        'qty' => '',
        'dims' => '',
        'gost' => '',
        'price' => null,
        'stock' => 'in_stock',
        'lead' => '',
        'photos' => [],
        'about' => '',
        'title' => '',
        'description' => '',
        'legacy_urls' => [],
        'hidden' => true,
    ];
    usort($items, fn($a, $b) => strcoll($a['name'], $b['name']));
    commit_changes([$file => $items]);
    return $sec['url'] . $slug . '/';
}

/** Новый раздел внутри существующего. */
function catsec_create(string $parentKey, string $name): string
{
    $name = trim($name);
    if (mb_strlen($name) < 3) throw new InvalidArgumentException('Введите название раздела');
    [, $parent] = catsec_find($parentKey);
    $sections = doc_get('catalog/sections.json');
    $slug = translit_slug($name);
    $url = $parent['url'] . $slug . '/';
    $base = $slug; $n = 2;
    while (in_array($url, array_column($sections, 'url'), true)) { $slug = $base . '-' . $n++; $url = $parent['url'] . $slug . '/'; }

    $key = catsec_key($url);
    $sections[$key] = [
        'url' => $url, 'slug' => $slug, 'parent' => $parent['url'],
        'name' => $name, 'name_source' => 'admin', 'model' => '',
        'photo' => '', 'text_old' => '', 'title' => '', 'description' => '',
        'intro' => '', 'text' => '', 'legacy_urls' => [],
    ];
    // uasort, а не usort: ключи — это имена файлов разделов, терять их нельзя
    uasort($sections, fn($a, $b) => strcmp($a['url'], $b['url']));
    commit_changes(['catalog/sections.json' => $sections, catsec_file($key) => []]);
    return $key;
}

/** Где используется раздел — чтобы не удалить то, на что ссылаются. */
function catsec_usage(string $key): array
{
    [, $sec] = catsec_find($key);
    $uses = [];
    foreach (doc_get('catalog/sections.json') as $s) {
        if (($s['parent'] ?? '') === $sec['url']) $uses[] = 'подраздел «' . $s['name'] . '»';
    }
    foreach (doc_get('tehnika.json') as $t) {
        if ($t['parts_section'] === $sec['url']) $uses[] = 'страница модели «' . $t['name'] . '»';
    }
    $items = is_file(data_path(catsec_file($key))) ? doc_get(catsec_file($key)) : [];
    if ($items) $uses[] = count($items) . ' ' . plural(count($items), ['позиция', 'позиции', 'позиций']) . ' внутри';
    return $uses;
}

function catsec_delete(string $key): void
{
    if ($uses = catsec_usage($key)) {
        throw new InvalidArgumentException('Раздел нельзя удалить: ' . implode(', ', $uses) . '. Сначала перенесите или удалите содержимое.');
    }
    [, $sec] = catsec_find($key);
    $sections = doc_get('catalog/sections.json');
    unset($sections[$key]);
    trash_put('catsec', $key, $sec['name'], ['section' => $sec, 'items' => []]);
    commit_changes(['catalog/sections.json' => $sections, catsec_file($key) => null]);
}

/**
 * Карточка, добавленная кнопкой «+ Добавить: позиция» прямо в форме раздела, приходит
 * только с полями формы: без адреса, слага и раздела. Дописываем их здесь, иначе
 * сборка упадёт на странице без адреса. Новая позиция создаётся скрытой.
 */
function catsec_normalize_items(string $key, array $changes): array
{
    $file = catsec_file($key);
    if (!isset($changes[$file]) || !is_array($changes[$file])) return $changes;
    [, $sec] = catsec_find($key);
    $items = $changes[$file];
    $taken = [];
    foreach ($items as $i) if (!empty($i['slug'])) $taken[] = $i['slug'];

    foreach ($items as $n => $i) {
        if (!empty($i['url']) && !empty($i['slug']) && !empty($i['section'])) continue;
        $name = trim((string)($i['name'] ?? ''));
        if ($name === '') throw new InvalidArgumentException('У новой позиции не заполнено название — без него нельзя собрать адрес страницы.');
        $slug = (string)($i['slug'] ?? '');
        if ($slug === '' || !preg_match('~^[a-z0-9-]+$~', $slug)) {
            $slug = translit_slug($name);
            $base = $slug;
            $n2 = 2;
            while (in_array($slug, $taken, true)) $slug = $base . '-' . $n2++;
        }
        $taken[] = $slug;
        $items[$n] = array_merge([
            'url' => $sec['url'] . $slug . '/',
            'slug' => $slug,
            'section' => $sec['url'],
            'type' => 'part',
            'name_source' => 'admin',
            'draw' => '',
            'draw_alt' => [],
            'model' => $sec['model'] ?? '',
            'stock' => 'in_stock',
            'photos' => [],
            'legacy_urls' => [],
        ], $i, ['url' => $sec['url'] . $slug . '/', 'slug' => $slug, 'section' => $sec['url'], 'hidden' => true]);
    }
    $changes[$file] = array_values($items);
    return $changes;
}
