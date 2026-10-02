/**
 * Сборка data/*.json из артефактов аудита живого сайта (seo/_audit/).
 * Запуск: node tools/make-data.mjs
 *
 * Правило: ничего не выдумываем. Названия берём с сайта; если страница на сайте
 * не отдавала название — восстанавливаем из слага и помечаем name_source:"slug",
 * такие позиции попадают в docs/CLIENT-REQUESTS.md на проверку.
 */
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(import.meta.dirname, '..');
const A = path.join(ROOT, 'seo', '_audit');
const rd = f => JSON.parse(fs.readFileSync(path.join(A, f), 'utf8'));
const rt = f => fs.readFileSync(path.join(A, f), 'utf8');

const sitemapUrls = rt('all_urls.txt').split('\n').map(s => s.trim()).filter(Boolean);
const navNames = rd('names.json');          // url → название из навигации/листингов
const pageImages = rd('page_images.json');  // url → картинки из контентной области
const crawl = rd('crawl.json');
const crawlBy = new Map(crawl.map(r => [r.url, r]));
const genericTitle = t => /^Каталог( товаров)? - /.test(t || '');

// ─────────────────────────────────────────────────────────── канонические разделы
const CAT = '/catalog/';
const ZCH = CAT + 'zapasnye-chasti-i-komplektuyushchie/';
const DROB = ZCH + 'zapchasti-dlya-drobilok/';
const GROH = ZCH + 'zapchasti-dlya-grokhotov/';
const SETKA = GROH + 'setka-riflenaya-dlya-grokhotov/';
const PIT = ZCH + 'zapchasti-dlya-pitately/';
const ABZ = ZCH + 'zapchasti-dlya-abz/';
const DSO = CAT + 'drobilno-sortirovochnoe-oborudovanie/';
const BOLT = CAT + 'futerovochnye-bolty-bronebolty/';
const FILL = CAT + 'kompaund-epoksidnyy-dvukhkomponentnyy-fillbond-17/';

/** Приводит любой старый адрес каталога к каноническому. */
function canonCatalog(u) {
  // старая схема «запчасти для дробильного оборудования»
  let m = u.match(/^\/catalog\/zapchasti-dlya-drobilnogo-oborudovaniya\/(.*)$/);
  if (m) {
    const rest = m[1];
    if (!rest) return DROB;
    if (rest.startsWith('drobilki-')) return DROB + rest;
    if (rest.startsWith('pitatel-')) return PIT + rest;
    return DROB + rest;
  }
  // та же ветка, но уже вложенная в «запасные части»
  m = u.match(/^\/catalog\/zapasnye-chasti-i-komplektuyushchie\/zapchasti-dlya-drobilnogo-oborudovaniya\/(.*)$/);
  if (m) return m[1] ? DROB + m[1] : DROB;

  // сетка рифлёная: верхний уровень → под «запчасти для грохотов»
  m = u.match(/^\/catalog\/setka-riflenaya-dlya-grokhotov\/(.*)$/);
  if (m) return m[1] ? SETKA + m[1] : SETKA;
  m = u.match(/^\/catalog\/zapasnye-chasti-i-komplektuyushchie\/setka-riflenaya-dlya-grokhotov\/(.*)$/);
  if (m) return m[1] ? SETKA + m[1] : SETKA;

  // запчасти для АБЗ
  m = u.match(/^\/catalog\/zapchasti-dlya-abz\/(.*)$/);
  if (m) return m[1] ? ABZ + m[1] : ABZ;
  if (u === ZCH + 'zapchasti-dlya-ds-158-ds-185/') return ABZ + 'zapchasti-dlya-ds-158-ds-185/';

  // тупиковые разделы
  if (u.startsWith(CAT + 'drobilki/zapchasti-dlya-drobilnogo-oborudovaniya/drobilki-shchekovye/')) return DROB + 'drobilki-shchekovye/';
  if (u.startsWith(CAT + 'drobilki/')) return DROB;
  if (u === CAT + 'oborudovanie/' || u === CAT + 'promyshlennoe-oborudovanie/') return DSO;
  if (u.startsWith(CAT + 'komplektuyushchie-dlya-konveerov/roliki-konveyernye/')) return ZCH + 'zapchasti-dlya-konveyerov/';
  if (u.startsWith(CAT + 'komplektuyushchie-dlya-konveerov/') || u === CAT + 'komplektuyushchie-dlya-konveyerov/') return ZCH + 'zapchasti-dlya-konveyerov/';

  return u; // адрес уже канонический
}

/** Страницы вне каталога: что сохраняем, что переадресуем. */
const PAGE_MAP = {
  '/company/history/': '/company/',                 // демо-текст Aspro про нефтедобычу
  '/company/licenses/': '/company/certificates/',   // демо-текст про рыбалку и охоту
  '/info/more/': '/info/',
  '/info/more/buttons/': '/info/',
  '/info/more/elements/': '/info/',
  '/info/more/icons/': '/info/',
  '/info/more/typograpy/': '/info/',
  '/study/': '/',
  '/form/': '/contacts/',
  '/cart/': CAT,
  '/cart/order/': CAT,
  '/404.php': null,   // служебная, не индексируется
  '/500.html': null,
};

// ────────────────────────────────────────────────────────── номера чертежей
const CYR2LAT = { 'А': 'A', 'В': 'B', 'С': 'C', 'Е': 'E', 'Н': 'H', 'К': 'K', 'М': 'M', 'О': 'O', 'Р': 'P', 'Т': 'T', 'Х': 'X', 'У': 'Y' };
export const normDraw = s => String(s || '').toUpperCase()
  .replace(/[А-Я]/g, c => CYR2LAT[c] || c)
  .replace(/[^0-9A-Z]/g, '');

/** Основной номер чертежа и альтернативные обозначения из названия детали. */
function parseDesignations(name) {
  const gostFree = name.replace(/ГОСТ\s*[\d]+(?:[-–]\d+)?/gi, ' ');
  const alt = [];
  // всё, что в скобках и похоже на обозначение (не словесное уточнение)
  for (const m of name.matchAll(/\(([^()]{3,40})\)/g)) {
    const v = m[1].trim();
    if (/\d/.test(v) && /^[0-9A-Za-zА-Яа-я.\-/\s]+$/.test(v) && !/^[А-Яа-я\s]+$/.test(v)) alt.push(v);
  }
  // длинные цифровые номера, в том числе с суффиксом исполнения -10 и с точками
  const main = [];
  for (const m of gostFree.matchAll(/\b(\d{2,3}(?:\.\d{2,3}){2,3}|\d{7,12}(?:[-/]\d{1,2}(?:\/\d{1,2})?)?)\b/g)) {
    main.push(m[1]);
  }
  const draw = main[0] || '';
  const rest = main.slice(1);
  const seen = new Set([normDraw(draw)]);
  const alts = [];
  for (const v of [...rest, ...alt]) {
    const n = normDraw(v);
    if (n && !seen.has(n)) { seen.add(n); alts.push(v); }
  }
  return { draw, alts };
}

/** 10-значный номер ↔ 12-значная форма из прайса (суффикс исполнения). */
const priceForm = d => (/^\d{10}$/.test(d) ? d + '00' : '');

// ─────────────────────────────────────────────────────────── прайс: вес и кол-во
const priceRows = [];
{
  let group = '';
  for (const line of rt('price_rows.txt').split('\n')) {
    if (line.startsWith('## ')) { group = line.slice(3).trim(); continue; }
    const c = line.split(' ;; ').map(s => s.trim());
    if (c.length < 5 || !/^\d+$/.test(c[0])) continue;
    const nm = c[1];
    const dm = nm.match(/^(\d{10,12}(?:[-/]\d{1,2}(?:\/\d{1,2})?)?)\s+(.*)$/);
    priceRows.push({
      model: group,
      draw: dm ? dm[1] : '',
      name: dm ? dm[2] : nm,
      qty: c[2],
      weight: c[3] ? Number(c[3].replace(',', '.')) : null,
      price: /^[\d\s]+[.,]\d\d$/.test(c[4]) ? Number(c[4].replace(/\s/g, '').replace(',', '.')) : null,
    });
  }
}
const priceByDraw = new Map();
for (const r of priceRows) {
  if (!r.draw) continue;
  const keys = [normDraw(r.draw)];
  // 12-значная форма прайса → 10-значная форма каталога
  const d12 = normDraw(r.draw);
  if (/^\d{12}$/.test(d12)) keys.push(d12.slice(0, 10));
  if (/^\d{10}\d{2}$/.test(d12)) keys.push(d12.slice(0, 10));
  for (const k of keys) if (!priceByDraw.has(k + '|' + r.model)) priceByDraw.set(k + '|' + r.model, r);
}

// ──────────────────────────────────────────────── восстановление имени из слага
const TRANSLIT = [
  ['shch', 'щ'], ['sch', 'щ'], ['yo', 'ё'], ['zh', 'ж'], ['kh', 'х'], ['ts', 'ц'], ['ch', 'ч'],
  ['sh', 'ш'], ['yu', 'ю'], ['ya', 'я'], ['yy', 'ый'], ['iy', 'ий'], ['ye', 'е'],
  ['a', 'а'], ['b', 'б'], ['v', 'в'], ['g', 'г'], ['d', 'д'], ['e', 'е'], ['z', 'з'], ['i', 'и'],
  ['j', 'й'], ['k', 'к'], ['l', 'л'], ['m', 'м'], ['n', 'н'], ['o', 'о'], ['p', 'п'], ['r', 'р'],
  ['s', 'с'], ['t', 'т'], ['u', 'у'], ['f', 'ф'], ['h', 'х'], ['c', 'ц'], ['y', 'ы'], ['\'', 'ь'],
];
function fromSlug(slug) {
  const words = slug.split('-');
  const out = words.map(w => {
    if (/^\d/.test(w) || /^[a-z]?\d/.test(w)) return w.toUpperCase().replace(/KH/g, 'Х');
    let s = w;
    for (const [lat, cyr] of TRANSLIT) s = s.split(lat).join(cyr);
    return s;
  }).join(' ');
  return out.charAt(0).toUpperCase() + out.slice(1);
}

// ───────────────────────────────────────────────────── собираем множество адресов
const allKnown = new Set([...sitemapUrls, ...Object.keys(navNames)]);
// фильтровые и служебные адреса в структуру не берём
const skip = u => /\/filter\//.test(u) || /\/(?:apply|clear)\//.test(u) || u.includes('?');

const canonSet = new Set();
const legacy = new Map(); // старый url → канонический

for (const u of allKnown) {
  if (skip(u)) continue;
  if (!u.startsWith(CAT)) continue;
  const c = canonCatalog(u);
  canonSet.add(c);
  if (c !== u) legacy.set(u, c);
}
// родительские разделы канонических адресов обязаны существовать
for (const u of [...canonSet]) {
  const parts = u.replace(CAT, '').split('/').filter(Boolean);
  for (let i = 1; i < parts.length; i++) canonSet.add(CAT + parts.slice(0, i).join('/') + '/');
}
canonSet.add(CAT);
canonSet.add(ZCH + 'zapchasti-dlya-konveyerov/');
for (const s of ['drobilki/', 'drobilki/konusnye-drobilki/', 'drobilki/shchekovye-drobilki/', 'drobilki/rotornye-drobilki/', 'pitateli/', 'grokhota/', 'konveyera/']) canonSet.add(DSO + s);
canonSet.add(SETKA + 'setka-riflenaya-dlya-grokhotov-iz-nemetskoy-pruzhinnoy-stali/');
canonSet.add(SETKA + 'setka-riflenaya-dlya-grokhotov-iz-rossiyskoy-stali/');
canonSet.add(ABZ + 'zapchasti-dlya-ds-158-ds-185/');

// Разделы, у которых на текущем сайте нет вложений, но по смыслу это разделы.
const FORCED_SECTIONS = new Set([
  CAT, DSO, DSO + 'drobilki/', DSO + 'drobilki/konusnye-drobilki/', DSO + 'drobilki/shchekovye-drobilki/',
  DSO + 'drobilki/rotornye-drobilki/', DSO + 'pitateli/', DSO + 'grokhota/', DSO + 'konveyera/',
  ZCH, ZCH + 'zapchasti-dlya-konveyerov/', DROB, DROB + 'drobilki-konusnye/', DROB + 'drobilki-shchekovye/',
  GROH, SETKA, SETKA + 'setka-riflenaya-dlya-grokhotov-iz-nemetskoy-pruzhinnoy-stali/',
  SETKA + 'setka-riflenaya-dlya-grokhotov-iz-rossiyskoy-stali/',
  PIT, ABZ, ABZ + 'zapchasti-dlya-ds-158-ds-185/', BOLT, FILL,
]);

const canonList = [...canonSet].sort();
const isSection = u => FORCED_SECTIONS.has(u) || canonList.some(o => o !== u && o.startsWith(u));

/** Названия, которые нельзя оставлять как на сайте или как из слага. */
const NAME_FIX = {
  [ZCH + 'zapchasti-dlya-abz/']: 'Запчасти для АБЗ',
  [ABZ + 'zapchasti-dlya-ds-158-ds-185/']: 'Запчасти для ДС-158 и ДС-185',
  [PIT]: 'Запчасти для питателей',                     // на сайте опечатка «питателй»
  [ZCH + 'zapchasti-dlya-konveyerov/']: 'Запчасти для конвейеров',
  [SETKA + 'setka-riflenaya-dlya-grokhotov-iz-nemetskoy-pruzhinnoy-stali/']: 'Сетка рифлёная для грохотов из немецкой пружинной стали',
  [SETKA + 'setka-riflenaya-dlya-grokhotov-iz-rossiyskoy-stali/']: 'Сетка рифлёная (канилированная) для грохотов из российской стали',
  [SETKA]: 'Сетка рифлёная для грохотов',
  [DSO + 'drobilki/rotornye-drobilki/']: 'Роторные дробилки',
  [DSO + 'pitateli/']: 'Питатели',
  [DSO + 'grokhota/']: 'Грохота',
  [DSO + 'konveyera/']: 'Конвейера',
};

/** Слаг сетки однозначно задаёт ячейку: setka-riflenaya-yach-1-6kh1-6 → 1,6×1,6. */
function meshName(slug, german) {
  const m = slug.match(/^setka-riflenaya-yach-([\d-]+)kh([\d-]+?)(-gost3306-88)?$/);
  if (!m) return null;
  const num = s => s.replace(/^(\d+)-(\d+)$/, '$1,$2');
  const cell = num(m[1]) + '×' + num(m[2]);
  return 'Сетка рифлёная (канилированная) яч. ' + cell
    + (m[3] ? ' ГОСТ 3306-88' : '')
    + (german ? ', немецкая пружинная сталь' : '');
}

// ─────────────────────────────────────────────────────────────── имена и фото
const legacyOf = c => [...legacy.entries()].filter(([, v]) => v === c).map(([k]) => k);

function nameFor(u) {
  if (NAME_FIX[u]) return { name: NAME_FIX[u], src: 'site' };
  const slug = u.replace(/\/$/, '').split('/').pop();
  const mesh = meshName(slug, u.includes('nemetskoy'));
  if (mesh) return { name: mesh, src: 'site' };
  if (navNames[u]) return { name: navNames[u], src: 'site' };
  const r = crawlBy.get(u);
  if (r && r.h1 && !genericTitle(r.title)) return { name: r.h1.trim(), src: 'site' };
  for (const l of legacyOf(u)) {
    if (navNames[l]) return { name: navNames[l], src: 'site' };
    const rl = crawlBy.get(l);
    if (rl && rl.h1 && !genericTitle(rl.title)) return { name: rl.h1.trim(), src: 'site' };
  }
  return { name: fromSlug(u.replace(/\/$/, '').split('/').pop()), src: 'slug' };
}

// Картинки общей страницы каталога: ими «заражены» все страницы, которые на старом
// сайте не рендерились и отдавали копию /catalog/. Такие фото к детали не относятся.
const GENERIC_IMAGES = new Set(pageImages['/catalog/'] || []);

function photosFor(u) {
  const out = [];
  for (const src of [u, ...legacyOf(u)]) {
    // со страниц, которые отдавали пустую копию каталога, картинки не берём
    if (genericTitle(crawlBy.get(src)?.title)) continue;
    for (const p of pageImages[src] || []) {
      if (GENERIC_IMAGES.has(p)) continue;
      // оригиналы предпочтительнее нарезок Битрикса
      if (!out.includes(p)) out.push(p);
    }
  }
  const orig = out.filter(p => p.startsWith('/upload/iblock/'));
  const resized = out.filter(p => !orig.length && p.startsWith('/upload/resize_cache/'));
  return (orig.length ? orig : resized).slice(0, 6);
}

// ──────────────────────────────────────────────────────── модель техники по адресу
const MODELS = [
  { key: 'smd-108a', name: 'СМД-108А', kind: 'Щековая дробилка' },
  { key: 'smd-109a', name: 'СМД-109А', kind: 'Щековая дробилка' },
  { key: 'smd-110a', name: 'СМД-110А', kind: 'Щековая дробилка' },
  { key: 'dro-592-ksd-600', name: 'ДРО-592 (КСД-600)', kind: 'Конусная дробилка' },
  { key: 'smd-120a-ksd-900', name: 'СМД-120А (КСД-900)', kind: 'Конусная дробилка' },
  { key: 'ksd-1200-i-kmd-1200', name: 'КСД-1200 и КМД-1200', kind: 'Конусная дробилка' },
  { key: 'pitatel-plastinchatyy-p-804-104920-10', name: 'П-804 (104920-10)', kind: 'Питатель пластинчатый' },
  { key: 'pitatel-plastinchatyy-tk-16a-dro-604', name: 'ТК-16А (ДРО-604)', kind: 'Питатель пластинчатый' },
  { key: 'zapchasti-dlya-ds-158-ds-185', name: 'ДС-158 / ДС-185', kind: 'Оборудование АБЗ' },
];
const modelFor = u => MODELS.find(m => u.includes('/' + m.key + '/'))?.name || '';

// ───────────────────────────────────────────────────────────── сборка структур
const sections = [];
const items = [];

for (const u of canonList) {
  const { name, src } = nameFor(u);
  const r = crawlBy.get(u);
  const photos = photosFor(u);
  const model = modelFor(u);

  if (isSection(u)) {
    const parts = u.replace(CAT, '').split('/').filter(Boolean);
    sections.push({
      url: u,
      slug: parts[parts.length - 1] || 'catalog',
      parent: parts.length > 1 ? CAT + parts.slice(0, -1).join('/') + '/' : (parts.length === 1 ? CAT : null),
      name: u === CAT ? 'Каталог' : name,
      name_source: u === CAT ? 'site' : src,
      model,
      photo: photos[0] || '',
      text_old: (r?.text || '').trim(),
      title: '',
      description: '',
      intro: '',
      text: '',
      legacy_urls: legacyOf(u),
    });
  } else {
    const parts = u.replace(CAT, '').split('/').filter(Boolean);
    const section = parts.length > 1 ? CAT + parts.slice(0, -1).join('/') + '/' : CAT;
    const type = u.startsWith(DSO) ? 'equipment'
      : u.startsWith(SETKA) ? 'mesh'
        : u.startsWith(BOLT) ? 'bolt' : 'part';
    const { draw, alts } = parseDesignations(type === 'mesh' || type === 'bolt' ? '' : name);
    const pr = model ? priceByDraw.get(normDraw(draw) + '|' + model) : null;
    // 12-значную форму пишем в альтернативные обозначения только там, где она реально
    // встречается в прайсе для этой же машины: иначе это была бы выдумка. Как ключ поиска
    // она работает в любом случае — её добавляет item_keys() в app/lib/catalog.php.
    const altAll = [...alts];
    const pf = priceForm(normDraw(draw));
    if (pf && model && priceByDraw.has(pf + '|' + model) && !altAll.map(normDraw).includes(pf)) altAll.push(pf);
    items.push({
      url: u,
      slug: parts[parts.length - 1],
      section,
      type,
      name,
      name_source: src,
      draw,
      draw_alt: altAll,
      model,
      material: '',
      weight: pr?.weight ?? null,
      qty: pr?.qty || '',
      dims: '',
      gost: (name.match(/ГОСТ\s*[\d]+(?:[-–]\d+)?/i) || [''])[0],
      price: pr?.price ?? null,
      stock: 'in_stock',
      lead: '',
      photos,
      about: '',
      title: '',
      description: '',
      legacy_urls: legacyOf(u),
    });
  }
}

// ───────────────────────────────────────────────────────── отчёт и запись
const warn = [];
const noName = [...sections, ...items].filter(x => x.name_source === 'slug');
fs.mkdirSync(path.join(ROOT, 'data', 'catalog'), { recursive: true });

// Повторный запуск не должен стирать то, что уже написано или отредактировано в админке:
// переносим редакторские поля из прежней версии файлов по совпадению адреса.
const KEEP_ITEM = ['about', 'title', 'description', 'material', 'dims', 'gost', 'lead', 'stock', 'price', 'hidden'];
const KEEP_SEC = ['intro', 'text', 'title', 'description', 'name'];
function carryOverItems(list, keys) {
  const dir = path.join(ROOT, 'data', 'catalog', 'items');
  if (!fs.existsSync(dir)) return;
  const prev = new Map();
  for (const f of fs.readdirSync(dir)) {
    if (!f.endsWith('.json')) continue;
    for (const x of JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8'))) prev.set(x.url, x);
  }
  for (const cur of list) {
    const old = prev.get(cur.url);
    if (!old) continue;
    for (const k of keys) {
      const v = old[k];
      if (v === undefined || v === null || v === '' || (Array.isArray(v) && !v.length)) continue;
      cur[k] = v;
    }
  }
}

function carryOverSections(list, keys) {
  const f = path.join(ROOT, 'data', 'catalog', 'sections.json');
  if (!fs.existsSync(f)) return;
  const raw = JSON.parse(fs.readFileSync(f, 'utf8'));
  const prev = new Map(Object.values(Array.isArray(raw) ? raw : raw).map(x => [x.url, x]));
  for (const cur of list) {
    const old = prev.get(cur.url);
    if (!old) continue;
    for (const k of keys) {
      const v = old[k];
      if (v === undefined || v === null || v === '' || (Array.isArray(v) && !v.length)) continue;
      cur[k] = v;
    }
  }
}

function carryOver(file, list, keys) {
  const f = path.join(ROOT, 'data', file);
  if (!fs.existsSync(f)) return;
  const prev = new Map(JSON.parse(fs.readFileSync(f, 'utf8')).map(x => [x.url, x]));
  for (const cur of list) {
    const old = prev.get(cur.url);
    if (!old) continue;
    for (const k of keys) {
      const v = old[k];
      if (v === undefined || v === null || v === '' || (Array.isArray(v) && !v.length)) continue;
      cur[k] = v;
    }
  }
}
carryOverItems(items, KEEP_ITEM);
carryOverSections(sections, KEEP_SEC);
/** Ключ файла раздела: путь после /catalog/, слеши заменены на двойной дефис. */
export const sectionKey = u => u.replace('/catalog/', '').replace(/\/$/, '').split('/').join('--') || 'catalog';

const jw = (f, v) => fs.writeFileSync(path.join(ROOT, 'data', f), JSON.stringify(v, null, 2).replace(/\n/g, '\n') + '\n', 'utf8');
jw('catalog/sections.json', Object.fromEntries(
  sections.sort((a, b) => a.url.localeCompare(b.url)).map(s => [sectionKey(s.url), s])
));

// Товары раскладываем по файлам разделов: так админка правит один раздел за раз,
// а не файл на 560 записей. Ключ файла — путь раздела без /catalog/, слеши → двойной дефис.
const ITEM_DIR = path.join(ROOT, 'data', 'catalog', 'items');
fs.mkdirSync(ITEM_DIR, { recursive: true });
for (const f of fs.readdirSync(ITEM_DIR)) if (f.endsWith('.json')) fs.unlinkSync(path.join(ITEM_DIR, f));
const bySection = {};
for (const s of sections) bySection[sectionKey(s.url)] = [];
for (const i of items) {
  const k = sectionKey(i.section);
  (bySection[k] ||= []).push(i);
}
for (const [k, list] of Object.entries(bySection)) {
  list.sort((a, b) => a.name.localeCompare(b.name, 'ru'));
  fs.writeFileSync(path.join(ITEM_DIR, k + '.json'), JSON.stringify(list, null, 2) + String.fromCharCode(10), 'utf8');
}
const flat = path.join(ROOT, 'data', 'catalog', 'items.json');
if (fs.existsSync(flat)) fs.unlinkSync(flat);

console.log('разделов:', sections.length, 'товаров:', items.length);
console.log('названий восстановлено из слага:', noName.length);
console.log('товаров с номером чертежа:', items.filter(i => i.draw).length);
console.log('товаров с весом из прайса:', items.filter(i => i.weight != null).length);
console.log('товаров с ценой из прайса:', items.filter(i => i.price != null).length);
console.log('переадресаций каталога:', legacy.size);
console.log('строк прайса:', priceRows.length);
fs.writeFileSync(path.join(ROOT, 'seo', '_audit', 'legacy_map.json'),
  JSON.stringify(Object.fromEntries(legacy), null, 1), 'utf8');
if (warn.length) console.log('ВНИМАНИЕ:\n' + warn.join('\n'));
