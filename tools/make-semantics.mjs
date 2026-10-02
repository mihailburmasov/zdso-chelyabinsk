/**
 * Черновое семантическое ядро: seo/semantics.csv.
 * Запуск: node tools/make-semantics.mjs
 *
 * Частотность не проставлена: Wordstat и выгрузка запросов из Яндекс.Вебмастера
 * требуют доступов, которых у нас нет. В колонке «Частотность» стоит пометка
 * «нужен Wordstat» — её заполняют после получения доступа.
 */
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(import.meta.dirname, '..');
const rd = f => JSON.parse(fs.readFileSync(path.join(ROOT, 'data', f), 'utf8'));
const sections = rd('catalog/sections.json');
const tehnika = rd('tehnika.json');
const services = rd('services.json');
const articles = rd('articles.json');
const itemDir = path.join(ROOT, 'data', 'catalog', 'items');
const items = fs.readdirSync(itemDir).filter(f => f.endsWith('.json'))
  .flatMap(f => JSON.parse(fs.readFileSync(path.join(itemDir, f), 'utf8')));

const rows = [];
const add = (q, group, url, note = '') => rows.push({ q, group, url, note });

/* 1. Спрос по модели техники — основной коммерческий. */
for (const t of tehnika) {
  const u = '/tehnika/' + t.slug + '/';
  const names = [t.name.replace(/\s*\(.*\)$/, ''), ...t.aliases];
  const seen = new Set();
  for (const n of names) {
    const key = n.toLowerCase();
    if (seen.has(key)) continue;
    seen.add(key);
    add(`запчасти ${n}`, 'Модель техники', u);
    add(`запчасти для ${n}`, 'Модель техники', u);
    add(`купить запчасти ${n}`, 'Модель техники', u);
    add(`${n} запчасти цена`, 'Модель техники', u);
  }
  add(`каталог запчастей ${t.name}`, 'Модель техники', u);
  add(`комплект запчастей ${t.name}`, 'Модель техники', u);
}

/* 2. Спрос по номеру чертежа — самый горячий и почти никем не закрытый. */
for (const i of items) {
  if (!i.draw) continue;
  add(i.draw, 'Номер чертежа', i.url);
  if (i.model) add(`${i.draw} ${i.model}`, 'Номер чертежа', i.url);
  for (const alt of i.draw_alt || []) add(String(alt), 'Номер чертежа', i.url, 'альтернативное обозначение');
}

/* 3. Спрос по типу детали. */
const typeSeen = new Set();
for (const i of items) {
  const base = i.name.replace(/\s*\d[\d.\-/]{4,}.*$/, '').replace(/\s*\(.*?\)\s*/g, ' ').trim();
  if (base.length < 5) continue;
  const model = i.model ? ' ' + i.model.replace(/\s*\(.*\)$/, '') : '';
  const q = (base + model).toLowerCase();
  if (typeSeen.has(q)) continue;
  typeSeen.add(q);
  add(q, 'Тип детали', i.section);
  add(q + ' купить', 'Тип детали', i.section);
}

/* 4. Спрос по услуге. */
for (const s of services) {
  const n = s.name.replace(/\s*\(.*?\)\s*/g, ' ').replace(/,.*$/, '').trim().toLowerCase();
  add(n, 'Услуга', s.url);
  add(n + ' челябинск', 'Услуга', s.url);
  add(n + ' цена', 'Услуга', s.url);
  add(n + ' на заказ', 'Услуга', s.url);
}
add('литьё 110г13л на заказ', 'Услуга', '/services/lite/');
add('отливки по чертежам заказчика челябинск', 'Услуга', '/services/lite/');
add('капитальный ремонт щековой дробилки', 'Услуга', '/services/remont-drobilok/');
add('ремонт конусной дробилки', 'Услуга', '/services/remont-drobilok/');
add('плетение канилированной сетки', 'Услуга', '/services/setka-riflenaya/');
add('сетка рифлёная гост 3306-88 купить', 'Услуга', '/catalog/zapasnye-chasti-i-komplektuyushchie/zapchasti-dlya-grokhotov/setka-riflenaya-dlya-grokhotov/');
add('механообработка чпу челябинск', 'Услуга', '/services/mehanoobrabotka/');

/* 5. Разделы каталога и информационный спрос. */
for (const s of Object.values(sections)) {
  if (s.url === '/catalog/') continue;
  const n = s.name.toLowerCase();
  add(n, 'Раздел каталога', s.url);
  add(n + ' купить', 'Раздел каталога', s.url);
}
for (const a of articles) add(a.title.toLowerCase().replace(/[«»]/g, ''), 'Информационный', a.url);
add('как подобрать ячейку сетки для грохота', 'Информационный', '/info/articles/kak-podobrat-yacheyku-riflenoy-setki/');
add('чем отличается 110г13л от 35гл', 'Информационный', '/info/articles/110g13l-ili-35gl/');
add('когда менять дробящие плиты', 'Информационный', '/info/articles/iznos-drobyashchih-plit/');

/* 6. Общие коммерческие. */
for (const q of [
  'завод дробильно-сортировочного оборудования',
  'запчасти для дробилок челябинск',
  'запчасти дробильного оборудования',
  'запчасти для грохотов',
  'запчасти для питателей',
  'бронеболт купить',
  'футеровочный болт м30',
  'компаунд fillbond 17',
  'ролики конвейерные купить',
]) add(q, 'Общий коммерческий', '/');

/* ------------------------------------------------------------- запись */
const seen = new Set();
const uniq = rows.filter(r => {
  const k = r.q.toLowerCase() + '|' + r.url;
  if (seen.has(k)) return false;
  seen.add(k);
  return true;
}).sort((a, b) => a.group.localeCompare(b.group, 'ru') || a.q.localeCompare(b.q, 'ru'));

const csv = v => '"' + String(v ?? '').replace(/"/g, '""') + '"';
fs.mkdirSync(path.join(ROOT, 'seo'), { recursive: true });
fs.writeFileSync(path.join(ROOT, 'seo', 'semantics.csv'),
  '﻿' + ['Запрос;Частотность;Группа;Целевой URL;Примечание']
    .concat(uniq.map(r => [r.q, 'нужен Wordstat', r.group, r.url, r.note].map(csv).join(';')))
    .join('\r\n') + '\r\n', 'utf8');

const byGroup = {};
for (const r of uniq) byGroup[r.group] = (byGroup[r.group] || 0) + 1;
console.log('запросов в ядре:', uniq.length);
for (const [g, n] of Object.entries(byGroup).sort((a, b) => b[1] - a[1])) console.log('  ' + g + ': ' + n);
