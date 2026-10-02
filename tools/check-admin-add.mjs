/**
 * Проверка добавления записей через админку: node tools/check-admin-add.mjs <пароль> [адрес]
 *
 * Добавляет новость, статью и позицию каталога так же, как это делает форма,
 * и пробует занять адрес, который уже занят другой страницей. Проверяет, что
 * страницы появились по выведенным из слага адресам, что столкновение отклонено
 * понятным сообщением, а соседние страницы целы. В конце возвращает data/ как было.
 *
 * Нужен запущенный dev-сервер: php -S 127.0.0.1:8099 app/dev-router.php
 */
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';

const ROOT = path.resolve(import.meta.dirname, '..');
const PASS = process.argv[2];
const BASE = (process.argv[3] || 'http://127.0.0.1:8099').replace(/\/$/, '');
if (!PASS) { console.error('Укажите пароль админки: node tools/check-admin-add.mjs <пароль>'); process.exit(2); }

const DATA = path.join(ROOT, 'data');
const DIST = path.join(ROOT, 'dist');
const SNAP = fs.mkdtempSync(path.join(os.tmpdir(), 'zdso-add-'));
function walk(dir, base = '') {
  const out = [];
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) out.push(...walk(p, base + '/' + e.name));
    else if (e.name.endsWith('.json')) out.push(base + '/' + e.name);
  }
  return out;
}
const files = walk(DATA);
for (const f of files) {
  fs.mkdirSync(path.dirname(path.join(SNAP, f)), { recursive: true });
  fs.copyFileSync(path.join(DATA, f), path.join(SNAP, f));
}
const restore = () => {
  for (const f of walk(SNAP)) fs.copyFileSync(path.join(SNAP, f), path.join(DATA, f));
  for (const f of walk(DATA)) if (!files.includes(f)) fs.unlinkSync(path.join(DATA, f));
  fs.rmSync(SNAP, { recursive: true, force: true });
};

/* ---------------------------------------------------------------- вход */
let cookie = '';
const setCookie = res => {
  for (const c of (res.headers.getSetCookie ? res.headers.getSetCookie() : [])) {
    const kv = c.split(';')[0];
    const k = kv.split('=')[0];
    cookie = cookie.split('; ').filter(Boolean).filter(x => !x.startsWith(k + '=')).concat(kv).join('; ');
  }
};
let r = await fetch(BASE + '/admin/login', {
  method: 'POST', redirect: 'manual',
  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  body: 'password=' + encodeURIComponent(PASS),
});
setCookie(r);
if (r.status !== 303) { console.error('вход не удался, код ' + r.status); restore(); process.exit(1); }
r = await fetch(BASE + '/admin/', { headers: { cookie } });
setCookie(r);
const csrf = ((await r.text()).match(/"csrf":"([^"]+)"/) || [])[1];

const api = async (p, body) => {
  const res = await fetch(BASE + '/admin/api/' + p, {
    method: body ? 'POST' : 'GET',
    headers: { cookie, 'X-CSRF': csrf, ...(body ? { 'Content-Type': 'application/json' } : {}) },
    body: body ? JSON.stringify(body) : undefined,
  });
  const t = await res.text();
  try { return JSON.parse(t); } catch (e) { return { ok: false, error: 'ответ не JSON (код ' + res.status + '): ' + t.replace(/s+/g, ' ').slice(0, 300) }; }
};

const problems = [];
const pageExists = u => fs.existsSync(path.join(DIST, u.replace(/^\//, ''), 'index.html'));
const contactsBefore = fs.readFileSync(path.join(DIST, 'contacts', 'index.html'), 'utf8');

/** Добавить карточку в список раздела админки так, как это делает форма. */
async function addCard(sectionId, bind, card) {
  const d = await api('section?id=' + sectionId);
  if (!d.ok) return { ok: false, error: d.error };
  const values = d.values;
  values[bind] = [...(values[bind] || []), card];
  return api('section', { id: sectionId, values });
}

/* ---------------------------------------------------------- новость */
let res = await addCard('news', 'news.json', {
  slug: 'proverka-dobavleniya', date: '2026-10-02', title: 'Проверка добавления',
  lead: 'Запись создана автоматической проверкой.', body: [], photos: [], models: [],
});
if (!res.ok) problems.push('новость не добавилась: ' + res.error);
else if (!pageExists('/info/news/proverka-dobavleniya/')) problems.push('страница новости не собралась по адресу из слага');

/* ----------------------------------------------------------- статья */
res = await addCard('articles', 'articles.json', {
  slug: 'proverka-stati', date: '2026-10-02', title: 'Проверка статьи',
  description: 'Статья создана автоматической проверкой, чтобы убедиться, что адрес собирается из слага.',
  lead: 'Проверка.', body: [], related_service: '', related_section: '',
});
if (!res.ok) problems.push('статья не добавилась: ' + res.error);
else if (!pageExists('/info/articles/proverka-stati/')) problems.push('страница статьи не собралась по адресу из слага');

/* ------------------------------------------- позиция каталога из формы */
const KEY = 'zapasnye-chasti-i-komplektuyushchie--zapchasti-dlya-abz--zapchasti-dlya-ds-158-ds-185';
const sec = await api('service?slug=' + KEY);
if (!sec.ok) problems.push('раздел каталога не открылся: ' + sec.error);
else {
  const bind = 'catalog/items/{svc}.json';
  const values = sec.values;
  // Карточка приходит ровно с полями формы — без url, slug, section и type
  values[bind] = [...values[bind], {
    name: 'Бронелист проверочный ДС-158-45-10-999', draw: 'ДС-158-45-10-999', draw_alt: [],
    model: 'ДС-158 / ДС-185', material: '', weight: 12.5, qty: '2 шт.', dims: '', gost: '',
    price: null, stock: 'in_stock', lead: '', about: 'Позиция добавлена проверкой.',
    photos: [], hidden: false,
  }];
  const res2 = await api('service', { slug: KEY, values });
  if (!res2.ok) problems.push('позиция из формы не добавилась: ' + res2.error);
  else {
    const list = JSON.parse(fs.readFileSync(path.join(DATA, 'catalog/items', KEY + '.json'), 'utf8'));
    const added = list.find(i => i.name.startsWith('Бронелист проверочный'));
    if (!added) problems.push('позиция не попала в файл раздела');
    else {
      if (!added.url || !added.slug || !added.section) problems.push('у позиции из формы не заполнены url/slug/section');
      if (added.hidden !== true) problems.push('позиция из формы должна создаваться скрытой');
      if (added.url !== '/catalog/zapasnye-chasti-i-komplektuyushchie/zapchasti-dlya-abz/zapchasti-dlya-ds-158-ds-185/' + added.slug + '/') {
        problems.push('адрес позиции собран неверно: ' + added.url);
      }
    }
  }
}

/* --------------------------------- попытка занять адрес чужой страницы */
res = await addCard('news', 'news.json', {
  slug: 'contacts', date: '2026-10-02', title: 'Попытка занять чужой адрес',
  lead: 'Эта запись не должна перезаписать страницу контактов.', body: [], photos: [], models: [],
});
// Адрес /info/news/contacts/ свободен — столкновения быть не должно, запись пройдёт.
// А вот одинаковый слаг у двух новостей обязан отвергаться.
if (!res.ok) problems.push('новость со слагом contacts не добавилась, хотя адрес свободен: ' + res.error);
res = await addCard('news', 'news.json', {
  slug: 'proverka-dobavleniya', date: '2026-10-02', title: 'Дубль адреса',
  lead: 'Этот слаг уже занят.', body: [], photos: [], models: [],
});
if (res.ok) problems.push('повторный слаг новости приняли — должно было отклонить');
else if (!/адрес|занят/i.test(res.error)) problems.push('сообщение о дубле адреса непонятное: ' + res.error);

/* ------------------------------------- соседняя страница должна быть цела */
const contactsAfter = fs.readFileSync(path.join(DIST, 'contacts', 'index.html'), 'utf8');
if (contactsAfter !== contactsBefore) problems.push('страница контактов изменилась после добавления записей');

restore();
console.log('данные восстановлены из снимка');

if (problems.length) {
  console.log(`\nПРОБЛЕМЫ (${problems.length}):\n  ` + problems.join('\n  '));
  process.exitCode = 1;
} else {
  console.log('\nДобавление записей работает: страницы появляются по адресу из слага, занятый адрес отклоняется, соседние страницы целы.');
}
