# Фотографии оформления

Первый экран главной, полосы под шапкой внутренних страниц, галерея «Производство»
на главной и фон блока заявки. Готовит их `php tools/make-site-photos.php`, результат —
`public/img/bg/<имя>-<ширина>.webp|jpg`. Эти файлы относятся к коду и заливаются
на хостинг вместе с ним, а не через медиатеку.

Какое фото у какого раздела, выбирает `page_band()` в `app/lib/render.php`.
Чтобы заменить фото: положите исходник, поправьте строку в `$jobs` скрипта
и запустите его, затем `php app/bin/build.php`.

## Фото заказчика (со старого сайта)

| Имя | Где | Исходник |
|---|---|---|
| `hero`, `pr-plant` | первый экран главной, галерея | `upload/iblock/1d1/1d18e327….jpeg` — дробильно-сортировочный комплекс |
| `catalog`, `pr-plate` | каталог, галерея | `upload/iblock/02d/02d2570b….JPG` — дробящая плита |
| `services`, `pr-casting` | услуги, галерея | `upload/iblock/dee/deec09ee….jpg` — заливка форм |
| `company`, `pr-workshop` | раздел «Компания», галерея | `upload/iblock/a34/a34ae086….jpg` — цех |
| `pr-sieve` | галерея | `upload/iblock/261/261c08ba….jpg` — плетение сетки |
| `pr-cone` | галерея | `upload/iblock/355/355fa702….JPG` — броня конусной дробилки |

## Стоковые фото (CC0, без указания автора)

Исходники лежат в `tools/src/photos/`. Лицензия файлов с Wikimedia Commons проверена
через API Commons (`LicenseShortName: CC0`), а все снимки WordPress Photo Directory
публикуются под CC0.

| Файл | Где | Источник |
|---|---|---|
| `open-pit.jpg` | техника | [Kittilä, Finland; Open Pit Mine](https://commons.wikimedia.org/wiki/File:Kittilla,_Finland;_Open_Pit_Mine.jpg), Wikimedia Commons, CC0 |
| `open-pit-green.jpg` | контакты | [Fushun West Open-pit Mine](https://commons.wikimedia.org/wiki/File:Fushun_West_Open-pit_Mine.jpg), Wikimedia Commons, CC0 |
| `crushed-stone.jpg` | прайс-лист | [WordPress Photo Directory](https://pd.w.org/2025/03/33567e07018115f39.04343479-2048x1536.jpeg), CC0 |
| `conveyor-plant.jpg` | новости, статьи, FAQ | [WordPress Photo Directory](https://pd.w.org/2022/01/7961f038959bba55.95564697-2048x1536.jpg), CC0 |
| `conveyor-tower.jpg` | прочие страницы, фон блока заявки | [WordPress Photo Directory](https://pd.w.org/2022/01/61861f030e5039a13.98486407-2048x1536.jpg), CC0 |

Фото мобильных дробилок с фирменными надписями производителей не брали намеренно:
на сайте завода, который делает запчасти к дробилкам, чужой бренд на первом плане ни к чему.

## Логотип и иконки

Логотип — `tools/src/logo.png`, взят с `masterskie174.ru/logo.png` (657×183, PNG с прозрачностью).
Вектора у заказчика нет, поэтому иконки сайта и `favicon.svg` сделаны из красной «О» логотипа.
Если появится векторный логотип — замените `tools/src/logo.png` и перезапустите скрипт.
