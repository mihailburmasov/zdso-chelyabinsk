<?php
// Описание форм админки: какие поля есть у каждого раздела и в какое место данных они пишут.
// bind = "файл#путь.в.json". Сохранять можно только поля, описанные здесь.
declare(strict_types=1);

function f(string $type, string $bind, string $label, string $hint = '', array $o = []): array
{
    return ['type' => $type, 'bind' => $bind, 'label' => $label, 'hint' => $hint] + $o;
}
function k(string $type, string $key, string $label, string $hint = '', array $o = []): array
{
    return ['type' => $type, 'key' => $key, 'label' => $label, 'hint' => $hint] + $o;
}
function head(string $title, string $note = ''): array { return ['type' => 'head', 'label' => $title, 'hint' => $note]; }

/** Пара полей «заголовок и описание для поиска». */
function meta_fields(string $prefix, string $note = ''): array
{
    return [
        head('Для поисковиков', $note ?: 'Заголовок и описание, которые видны в результатах Яндекса и Google. На самой странице не показываются.'),
        f('text', $prefix . 'title', 'Заголовок во вкладке и в поиске', 'Оптимально 50–70 знаков', ['counter' => 70]),
        f('textarea', $prefix . 'description', 'Описание в поиске', 'Оптимально 140–170 знаков', ['counter' => 170, 'rows' => 3]),
    ];
}

function page_head_fields(string $p): array
{
    return [
        head('Заголовок страницы'),
        f('text', $p . 'h1', 'Заголовок (H1)'),
        f('textarea', $p . 'lead', 'Текст под заголовком', '', ['rows' => 3]),
    ];
}

function faq_item(): array { return [k('text', 'q', 'Вопрос'), k('textarea', 'a', 'Ответ', '', ['rows' => 3])]; }
function block_item(): array
{
    return [
        k('text', 'h', 'Заголовок блока'),
        k('textarea', 'text', 'Текст', '', ['rows' => 4, 'omitEmpty' => true]),
        k('list', 'list', 'Список пунктов', 'Если нужен не текст, а список', ['omitEmpty' => true]),
    ];
}

function admin_sections(): array
{
    $S = [];

    /* ---------------------------------------------------------- Страницы */
    $home = 'pages.json#home.';
    $S['home'] = ['title' => 'Главная', 'group' => 'Страницы', 'url' => '/', 'fields' => array_merge(
        page_head_fields($home),
        [
            f('text', $home . 'search_hint', 'Подсказка под строкой поиска'),
            head('Блоки «производство, а не перепродажа»'),
            f('items', $home . 'intro_blocks', 'Карточки', '', ['item' => [k('text', 'h', 'Заголовок'), k('textarea', 'text', 'Текст', '', ['rows' => 3])], 'itemName' => 'Карточка']),
            head('Текст внизу главной', 'Длинный абзац для поиска. Виден посетителю, но читают его редко — пишите по делу.'),
            f('textarea', $home . 'seo_text', 'Текст', '', ['rows' => 10]),
        ],
        meta_fields($home)
    )];

    $c = 'company.json#';
    $S['company-facts'] = ['title' => 'Компания и контакты', 'group' => 'Страницы', 'url' => '/contacts/', 'fields' => [
        head('Это единственное место, где меняются контакты', 'Телефон, почта и адреса подставляются отсюда во все шапки, подвалы, формы и микроразметку.'),
        f('text', $c . 'brand', 'Название для посетителя'),
        f('text', $c . 'legal_short', 'Сокращённое юридическое название'),
        f('text', $c . 'legal_full', 'Полное юридическое название'),
        f('number', $c . 'founded_year', 'Год основания'),
        head('Связь'),
        f('text', $c . 'phone_display', 'Телефон как на экране', 'Например: +7 (351) 219-90-00'),
        f('text', $c . 'phone_href', 'Телефон для ссылки', 'Только цифры с плюсом: +73512199000. Должен совпадать с тем, что на экране.'),
        f('email', $c . 'email', 'Электронная почта'),
        f('text', $c . 'schedule', 'Режим работы'),
        f('text', $c . 'schedule_short', 'Режим работы коротко', 'Показывается под телефоном в шапке'),
        head('Мессенджеры', 'Пока ссылка пустая, кнопка на сайте показывается неактивной — это заметно и подталкивает заполнить.'),
        f('url', $c . 'messengers.whatsapp.url', 'WhatsApp', 'https://wa.me/7XXXXXXXXXX'),
        f('url', $c . 'messengers.telegram.url', 'Telegram', 'https://t.me/никнейм'),
        f('url', $c . 'messengers.max.url', 'MAX', 'Ссылка из QR-кода профиля: https://max.ru/u/… или https://max.ru/никнейм'),
        head('Адреса'),
        f('items', $c . 'offices', 'Площадки', '', ['item' => [
            k('text', 'kind', 'Что это', 'Офис, склад, производство'),
            k('text', 'address', 'Адрес'),
            k('text', 'note', 'Пояснение', '', ['omitEmpty' => true]),
        ], 'itemName' => 'Площадка']),
        head('Реквизиты'),
        f('text', $c . 'inn', 'ИНН'),
        f('text', $c . 'kpp', 'КПП'),
        f('text', $c . 'ogrn', 'ОГРН'),
        f('text', $c . 'legal_address', 'Юридический адрес'),
        f('text', $c . 'bank', 'Банк'),
        f('text', $c . 'bank_bik', 'БИК'),
        f('text', $c . 'bank_account', 'Расчётный счёт'),
        f('text', $c . 'bank_corr', 'Корреспондентский счёт'),
        head('Цифры на главной'),
        f('items', $c . 'numbers', 'Плитки', '', ['item' => [k('text', 'value', 'Число'), k('text', 'label', 'Подпись')], 'itemName' => 'Плитка']),
        head('Счётчики и подтверждения прав', 'Эти значения трогать не надо: при их потере слетают права в Вебмастере и накопленная статистика.'),
        f('text', $c . 'analytics.yandex_metrika', 'Номер счётчика Яндекс Метрики'),
        f('text', $c . 'analytics.yandex_verification', 'Код подтверждения Яндекса'),
        f('text', $c . 'analytics.google_verification', 'Код подтверждения Google'),
    ]];

    $ct = 'pages.json#contacts.';
    $S['contacts'] = ['title' => 'Страница «Контакты»', 'group' => 'Страницы', 'url' => '/contacts/', 'fields' => array_merge(
        page_head_fields($ct),
        [
            f('text', $ct . 'map_note', 'Подпись у карты'),
            f('textarea', $ct . 'truck_note', 'Примечание про проезд', '', ['rows' => 2]),
        ],
        meta_fields($ct)
    )];

    $cm = 'pages.json#company.';
    $S['company-page'] = ['title' => 'Технические возможности', 'group' => 'Страницы', 'url' => '/company/', 'fields' => array_merge(
        page_head_fields($cm),
        [f('items', $cm . 'blocks', 'Блоки страницы', '', ['item' => block_item(), 'itemName' => 'Блок', 'collapsed' => true])],
        meta_fields($cm)
    )];

    $pr = 'pages.json#production.';
    $S['production'] = ['title' => 'Производство', 'group' => 'Страницы', 'url' => '/company/production/', 'fields' => array_merge(
        page_head_fields($pr),
        [
            f('items', $pr . 'sections', 'Участки', '', ['item' => [k('text', 'h', 'Название участка'), k('textarea', 'text', 'Что делает', '', ['rows' => 3])], 'itemName' => 'Участок']),
            head('Фотографии цехов'),
            f('bool', $pr . 'photos_pending', 'Показывать заметку «нужны фото»'),
            f('textarea', $pr . 'photos_note', 'Текст заметки', '', ['rows' => 3]),
        ],
        meta_fields($pr)
    )];

    foreach ([
        ['certificates', 'Сертификаты', '/company/certificates/', 'items'],
        ['partners', 'Партнёры', '/company/partners/', 'items'],
        ['reviews', 'Отзывы клиентов', '/company/reviews/', 'items'],
        ['projects', 'Кейсы', '/projects/', 'items'],
    ] as [$key, $title, $url, $listKey]) {
        $p = "pages.json#$key.";
        $S[$key] = ['title' => $title, 'group' => 'Страницы', 'url' => $url, 'fields' => array_merge(
            page_head_fields($p),
            [
                head('Содержимое'),
                f('items', $p . $listKey, 'Записи', 'Пока список пуст, на странице показывается заметка «раздел готов к наполнению»', ['item' => [
                    k('text', 'title', 'Заголовок'),
                    k('textarea', 'text', 'Текст', '', ['rows' => 3, 'omitEmpty' => true]),
                    k('photo', 'photo', 'Фото', '', ['omitEmpty' => true]),
                ], 'itemName' => 'Запись']),
                f('bool', $p . 'items_pending', 'Показывать заметку «раздел готов к наполнению»'),
                f('textarea', $p . 'items_note', 'Текст заметки', '', ['rows' => 3]),
            ],
            meta_fields($p)
        )];
    }

    $st = 'pages.json#staff.';
    $S['staff'] = ['title' => 'Отдел продаж', 'group' => 'Страницы', 'url' => '/company/staff/', 'fields' => array_merge(
        page_head_fields($st),
        [
            head('Менеджеры', 'Имя, должность и прямой телефон — это коммерческий фактор Яндекса.'),
            f('items', $st . 'people', 'Сотрудники', '', ['item' => [
                k('text', 'name', 'Имя и фамилия'),
                k('text', 'role', 'Должность'),
                k('text', 'phone_display', 'Телефон как на экране', '', ['omitEmpty' => true]),
                k('text', 'phone', 'Телефон для ссылки', 'Только цифры с плюсом', ['omitEmpty' => true]),
            ], 'itemName' => 'Сотрудник']),
            f('bool', $st . 'people_pending', 'Показывать заметку «нужны контакты менеджеров»'),
            f('textarea', $st . 'people_note', 'Текст заметки', '', ['rows' => 3]),
        ],
        meta_fields($st)
    )];

    $dl = 'pages.json#delivery.';
    $S['delivery'] = ['title' => 'Доставка и оплата', 'group' => 'Страницы', 'url' => '/delivery/', 'fields' => array_merge(
        page_head_fields($dl),
        [
            f('items', $dl . 'blocks', 'Блоки страницы', '', ['item' => block_item(), 'itemName' => 'Блок', 'collapsed' => true]),
            f('textarea', $dl . 'pending_note', 'Заметка «что уточнить»', 'Когда данные появятся — очистите поле, заметка пропадёт', ['rows' => 3]),
        ],
        meta_fields($dl)
    )];

    foreach ([
        ['info', 'Информация (хаб)', '/info/'],
        ['articles_index', 'База знаний (список)', '/info/articles/'],
        ['news_index', 'Новости (список)', '/info/news/'],
        ['faq_index', 'Вопрос-ответ (шапка)', '/info/faq/'],
        ['sitemap', 'Карта сайта', '/sitemap/'],
        ['search', 'Страница поиска', '/search/'],
        ['e404', 'Страница 404', '/404.html'],
    ] as [$key, $title, $url]) {
        $p = "pages.json#$key.";
        $S[$key] = ['title' => $title, 'group' => 'Страницы', 'url' => $url,
            'fields' => array_merge(page_head_fields($p), meta_fields($p))];
    }

    /* ---------------------------------------------------------- Каталог */
    $S['tehnika'] = ['title' => 'Модели техники', 'group' => 'Каталог', 'url' => '/tehnika/', 'fields' => [
        head('Страницы моделей техники', 'По каждой машине собирается страница с таблицей всех деталей. Раздел каталога указывается адресом — он должен существовать.'),
        f('items', 'tehnika.json', 'Модели', '', ['itemName' => 'Модель', 'collapsed' => true, 'item' => [
            k('text', 'slug', 'Адрес страницы', 'Латиницей, через дефис: smd-108a → /tehnika/smd-108a/'),
            k('text', 'name', 'Название модели'),
            k('text', 'kind', 'Тип машины', 'Щековая дробилка, конусная дробилка, питатель'),
            k('list', 'aliases', 'Как ещё называют', 'Варианты написания для поиска: СМД-108, СМД 108, SMD-108A'),
            k('text', 'parts_section', 'Адрес раздела каталога с запчастями', 'Например: /catalog/zapasnye-chasti-i-komplektuyushchie/zapchasti-dlya-drobilok/drobilki-shchekovye/smd-108a/'),
            k('text', 'equipment_url', 'Адрес карточки машины в каталоге'),
            k('textarea', 'intro', 'Вступление', '', ['rows' => 5]),
            k('bool', 'ttx_pending', 'ТТХ ещё не получены от клиента'),
        ]]),
    ]];

    $S['units'] = ['title' => 'Узлы оборудования', 'group' => 'Каталог', 'url' => '/tehnika/', 'fields' => [
        head('Как детали распределяются по узлам', 'Деталь попадает в первый узел, чьё слово встретилось в её названии. Порядок важен: сверху — более точные правила.'),
        f('items', 'units.json#shchekovye', 'Щековые дробилки', '', ['itemName' => 'Узел', 'item' => [k('text', 'name', 'Название узла'), k('list', 'match', 'Слова в названии детали')]]),
        f('items', 'units.json#konusnye', 'Конусные дробилки', '', ['itemName' => 'Узел', 'item' => [k('text', 'name', 'Название узла'), k('list', 'match', 'Слова в названии детали')]]),
        f('items', 'units.json#pitateli', 'Питатели', '', ['itemName' => 'Узел', 'item' => [k('text', 'name', 'Название узла'), k('list', 'match', 'Слова в названии детали')]]),
        f('items', 'units.json#abz', 'Оборудование АБЗ', '', ['itemName' => 'Узел', 'item' => [k('text', 'name', 'Название узла'), k('list', 'match', 'Слова в названии детали')]]),
    ]];

    $pp = 'pages.json#price.';
    $S['price'] = ['title' => 'Прайс-лист', 'group' => 'Каталог', 'url' => '/price/', 'fields' => array_merge(
        page_head_fields($pp),
        [
            f('textarea', $pp . 'valid_note', 'Заметка об актуальности цен', '', ['rows' => 2]),
            head('Таблица прайса', 'Группа — это машина. Строки внутри группы — позиции. Пустая цена показывается как «по запросу».'),
            f('text', 'price.json#updated', 'Дата актуальности', 'Например: 02.10.2026'),
            f('items', 'price.json#groups', 'Группы', '', ['itemName' => 'Группа', 'collapsed' => true, 'item' => [
                k('text', 'model', 'Модель машины'),
                k('items', 'rows', 'Строки', '', ['itemName' => 'Строка', 'collapsed' => true, 'item' => [
                    k('text', 'draw', 'Номер чертежа'),
                    k('text', 'name', 'Наименование детали'),
                    k('text', 'qty', 'Количество'),
                    k('number', 'weight', 'Вес, кг', '', ['omitEmpty' => true]),
                    k('number', 'price', 'Цена, ₽', 'Пусто — «по запросу»', ['omitEmpty' => true]),
                ]]),
            ]]),
        ],
        meta_fields($pp)
    )];

    /* ---------------------------------------------------------- Контент */
    $S['services'] = ['title' => 'Услуги', 'group' => 'Контент', 'url' => '/services/', 'fields' => [
        head('Страницы услуг', 'Адрес берётся из поля «Адрес страницы» и после публикации его лучше не менять.'),
        f('items', 'services.json', 'Услуги', '', ['itemName' => 'Услуга', 'collapsed' => true, 'item' => [
            k('text', 'slug', 'Адрес страницы', 'Латиницей, через дефис: lite → /services/lite/. После публикации не менять — адрес уже в поиске'),
            k('text', 'name', 'Название услуги'),
            k('textarea', 'short', 'Короткое описание', 'Показывается в карточке на главной и в списке услуг', ['rows' => 3]),
            k('textarea', 'lead', 'Текст под заголовком', '', ['rows' => 3]),
            k('items', 'blocks', 'Блоки страницы', '', ['itemName' => 'Блок', 'collapsed' => true, 'item' => block_item()]),
            k('list', 'steps', 'Как это происходит', 'По одному шагу в строке'),
            k('text', 'cta', 'Надпись на кнопке заявки'),
            k('items', 'faq', 'Вопросы по услуге', '', ['itemName' => 'Вопрос', 'item' => faq_item()]),
            k('text', 'title', 'Заголовок для поиска', '', ['counter' => 70]),
            k('textarea', 'description', 'Описание для поиска', '', ['counter' => 170, 'rows' => 3]),
        ]]),
    ]];

    $S['news'] = ['title' => 'Новости и отгрузки', 'group' => 'Контент', 'url' => '/info/news/', 'fields' => [
        head('Записи', 'Проще всего вести этот раздел фотографиями отгрузок: одна запись в две недели уже хорошо.'),
        f('items', 'news.json', 'Новости', '', ['itemName' => 'Новость', 'collapsed' => true, 'item' => [
            k('text', 'slug', 'Адрес записи', 'Латиницей, через дефис: adres → /info/news/adres/'),
            k('text', 'date', 'Дата', 'В виде 2026-10-02'),
            k('text', 'title', 'Заголовок'),
            k('textarea', 'lead', 'Короткий текст', '', ['rows' => 3]),
            k('items', 'body', 'Блоки текста', '', ['itemName' => 'Блок', 'item' => block_item(), 'collapsed' => true]),
            k('photos', 'photos', 'Фотографии'),
            k('list', 'models', 'Модели техники', 'Названия как на страницах техники — внизу появятся ссылки'),
        ]]),
    ]];

    $S['articles'] = ['title' => 'База знаний', 'group' => 'Контент', 'url' => '/info/articles/', 'fields' => [
        head('Статьи', 'Информационный трафик приходит именно сюда. Одна статья в две недели — рабочий темп.'),
        f('items', 'articles.json', 'Статьи', '', ['itemName' => 'Статья', 'collapsed' => true, 'item' => [
            k('text', 'slug', 'Адрес статьи', 'Латиницей, через дефис: adres → /info/articles/adres/'),
            k('text', 'date', 'Дата', 'В виде 2026-10-02'),
            k('text', 'title', 'Заголовок'),
            k('textarea', 'description', 'Описание для поиска', '', ['counter' => 170, 'rows' => 3]),
            k('textarea', 'lead', 'Текст под заголовком', '', ['rows' => 3]),
            k('items', 'body', 'Блоки текста', '', ['itemName' => 'Блок', 'item' => block_item(), 'collapsed' => true]),
            k('text', 'related_service', 'Ссылка на услугу', 'Адрес услуги латиницей, например lite', ['omitEmpty' => true]),
            k('text', 'related_section', 'Ссылка на раздел каталога', 'Полный адрес раздела', ['omitEmpty' => true]),
        ]]),
    ]];

    $S['faq'] = ['title' => 'Вопрос-ответ', 'group' => 'Контент', 'url' => '/info/faq/', 'fields' => [
        head('Вопросы', 'Первые шесть вопросов показываются ещё и на главной.'),
        f('items', 'faq.json', 'Вопросы', '', ['itemName' => 'Вопрос', 'item' => faq_item()]),
    ]];

    $S['stock'] = ['title' => 'Спецпредложения', 'group' => 'Контент', 'url' => '/info/stock/', 'fields' => [
        f('items', 'stock.json', 'Предложения', '', ['itemName' => 'Предложение', 'item' => [
            k('text', 'title', 'Заголовок'),
            k('textarea', 'lead', 'Описание', '', ['rows' => 3]),
            k('text', 'date', 'Дата', 'В виде 2026-10-02', ['omitEmpty' => true]),
            k('photos', 'photos', 'Фотографии'),
            k('text', 'cta', 'Надпись на кнопке'),
        ]]),
    ]];

    $S['vacancies'] = ['title' => 'Вакансии', 'group' => 'Контент', 'url' => '/company/vacancy/', 'fields' => [
        f('items', 'vacancies.json', 'Вакансии', '', ['itemName' => 'Вакансия', 'collapsed' => true, 'item' => [
            k('text', 'group', 'Подразделение', 'Производство, отдел продаж'),
            k('text', 'title', 'Должность'),
            k('text', 'salary', 'Зарплата'),
            k('text', 'experience', 'Опыт и образование'),
            k('text', 'schedule', 'График'),
            k('list', 'conditions', 'Условия'),
            k('list', 'requirements', 'Требования'),
            k('list', 'duties', 'Обязанности'),
        ]]),
    ]];

    /* ------------------------------------------------------ Юридическое */
    $S['legal'] = ['title' => 'Политика и согласие', 'group' => 'Юридическое', 'url' => '/politika-konfidentsialnosti/', 'fields' => [
        head('Политика конфиденциальности', 'Подстановки {{legal_full}}, {{inn}}, {{ogrn}}, {{legal_address}}, {{email}} заменяются реквизитами из раздела «Компания и контакты».'),
        f('text', 'legal.json#privacy.revision', 'Дата редакции', 'В виде 2026-10-02'),
        f('items', 'legal.json#privacy.blocks', 'Разделы политики', '', ['itemName' => 'Раздел', 'collapsed' => true, 'item' => [
            k('text', 'h', 'Заголовок', '', ['omitEmpty' => true]),
            k('list', 'p', 'Абзацы', '', ['omitEmpty' => true]),
            k('list', 'list', 'Список', '', ['omitEmpty' => true]),
        ]]),
        head('Согласие на обработку персональных данных'),
        f('text', 'legal.json#consent.revision', 'Дата редакции'),
        f('items', 'legal.json#consent.blocks', 'Разделы согласия', '', ['itemName' => 'Раздел', 'collapsed' => true, 'item' => [
            k('text', 'h', 'Заголовок', '', ['omitEmpty' => true]),
            k('list', 'p', 'Абзацы', '', ['omitEmpty' => true]),
        ]]),
        head('Тексты для поисковиков'),
        f('text', 'pages.json#privacy.title', 'Заголовок политики в поиске', '', ['counter' => 70]),
        f('textarea', 'pages.json#privacy.description', 'Описание политики в поиске', '', ['counter' => 170, 'rows' => 3]),
        f('text', 'pages.json#consent.title', 'Заголовок согласия в поиске', '', ['counter' => 70]),
        f('textarea', 'pages.json#consent.description', 'Описание согласия в поиске', '', ['counter' => 170, 'rows' => 3]),
    ]];

    /* ------------------------------------------------------- Навигация */
    $S['menu'] = ['title' => 'Меню и подвал', 'group' => 'Юридическое', 'url' => '/', 'fields' => [
        head('Верхнее меню', 'Подпункты правятся только разработчиком: у «Каталога», «Техники» и «Услуг» они собираются автоматически, у остальных заданы списком. Здесь меняются название и адрес пункта.'),
        f('items', 'structure.json#menu', 'Пункты меню', '', ['itemName' => 'Пункт', 'collapsed' => true, 'item' => [
            k('text', 'url', 'Адрес'),
            k('text', 'label', 'Название'),
        ]]),
    ]];

    return $S;
}

/** Поля формы раздела каталога — живут в catalog.php. */
function service_fields(): array { return catsec_fields(); }

/** Поля формы по их bind — чтобы при сохранении принять только описанные поля. */
function schema_binds(array $fields): array
{
    $out = [];
    foreach ($fields as $fd) if (!empty($fd['bind'])) $out[$fd['bind']] = $fd;
    return $out;
}
