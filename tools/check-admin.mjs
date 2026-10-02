/**
 * Круговой тест админки: node tools/check-admin.mjs <пароль> [адрес]
 *
 * Делает снимок data/, затем для каждого раздела админки и каждого раздела каталога
 * загружает форму и сохраняет её без изменений. После прогона сверяет все файлы данных
 * со снимком. Любое расхождение — это поле, которое админка теряет или искажает.
 * Ловит то, что не видно глазом: потерю полей вне формы, округление чисел,
 * null → "", пропавшие пустые списки.
 *
 * Нужен запущенный dev-сервер: php -S 127.0.0.1:8099 app/dev-router.php
 */
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';

const ROOT = path.resolve(import.meta.dirname, '..');
const PASS = process.argv[2];
const BASE = (process.argv[3] || 'http://127.0.0.1:8099').replace(/\/$/, '');
if (!PASS) { console.error('Укажите пароль админки: node tools/check-admin.mjs <пароль>'); process.exit(2); }

const DATA = path.join(ROOT, 'data');
const SNAP = fs.mkdtempSync(path.join(os.tmpdir(), 'zdso-snap-'));

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
console.log(`снимок сделан: ${files.length} файлов`);

/* ---------------------------------------------------------------- вход */
let cookie = '';
const setCookie = res => {
  const raw = res.headers.getSetCookie ? res.headers.getSetCookie() : [];
  for (const c of raw) {
    const [kv] = c.split(';');
    const [k] = kv.split('=');
    cookie = cookie.split('; ').filter(Boolean).filter(x => !x.startsWith(k + '=')).concat(kv).join('; ');
  }
};
let r = await fetch(BASE + '/admin/login', {
  method: 'POST', redirect: 'manual',
  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  body: 'password=' + encodeURIComponent(PASS),
});
setCookie(r);
if (r.status !== 303) { console.error('вход не удался, код ' + r.status); process.exit(1); }

r = await fetch(BASE + '/admin/', { headers: { cookie } });
setCookie(r);
const html = await r.text();
const csrf = (html.match(/"csrf":"([^"]+)"/) || [])[1];
if (!csrf) { console.error('не удалось получить csrf'); process.exit(1); }

const api = async (p, body) => {
  const res = await fetch(BASE + '/admin/api/' + p, {
    method: body ? 'POST' : 'GET',
    headers: { cookie, 'X-CSRF': csrf, ...(body ? { 'Content-Type': 'application/json' } : {}) },
    body: body ? JSON.stringify(body) : undefined,
  });
  const t = await res.text();
  try { return JSON.parse(t); } catch { return { ok: false, error: t.slice(0, 200) }; }
};

/* ------------------------------------------------- прогон всех разделов */
const boot = await api('bootstrap');
if (!boot.ok) { console.error('bootstrap: ' + boot.error); process.exit(1); }

const problems = [];
let saved = 0, unchanged = 0;

for (const n of boot.nav) {
  const d = await api('section?id=' + encodeURIComponent(n.id));
  if (!d.ok) { problems.push(`раздел ${n.id}: ${d.error}`); continue; }
  const res = await api('section', { id: n.id, values: d.values });
  if (!res.ok) { problems.push(`раздел ${n.id}: ${res.error}`); continue; }
  res.message.includes('Изменений нет') ? unchanged++ : saved++;
}
for (const s of boot.services) {
  const d = await api('service?slug=' + encodeURIComponent(s.slug));
  if (!d.ok) { problems.push(`каталог ${s.slug}: ${d.error}`); continue; }
  const res = await api('service', { slug: s.slug, values: d.values });
  if (!res.ok) { problems.push(`каталог ${s.slug}: ${res.error}`); continue; }
  res.message.includes('Изменений нет') ? unchanged++ : saved++;
}
console.log(`прогнано форм: ${boot.nav.length + boot.services.length} (без изменений ${unchanged}, записано ${saved})`);

/* ---------------------------------------------------- сверка со снимком */
// Сравниваем значения, а не текст файла: порядок ключей роли не играет.
function norm(v) {
  if (Array.isArray(v)) return v.map(norm);
  if (v && typeof v === 'object') {
    const o = {};
    for (const k of Object.keys(v).sort()) o[k] = norm(v[k]);
    return o;
  }
  return v;
}
function diffPaths(a, b, at = '', out = []) {
  if (out.length > 40) return out;
  const ta = Array.isArray(a) ? 'array' : a === null ? 'null' : typeof a;
  const tb = Array.isArray(b) ? 'array' : b === null ? 'null' : typeof b;
  if (ta !== tb) { out.push(`${at}: ${ta} ${JSON.stringify(a)} → ${tb} ${JSON.stringify(b)}`); return out; }
  if (ta === 'array') {
    if (a.length !== b.length) out.push(`${at}: длина ${a.length} → ${b.length}`);
    for (let i = 0; i < Math.min(a.length, b.length); i++) diffPaths(a[i], b[i], `${at}[${i}]`, out);
  } else if (ta === 'object') {
    for (const k of new Set([...Object.keys(a), ...Object.keys(b)])) {
      if (!(k in a)) { out.push(`${at}.${k}: появился ${JSON.stringify(b[k])}`); continue; }
      if (!(k in b)) { out.push(`${at}.${k}: ПРОПАЛ ${JSON.stringify(a[k])}`); continue; }
      diffPaths(a[k], b[k], `${at}.${k}`, out);
    }
  } else if (a !== b) out.push(`${at}: ${JSON.stringify(a)} → ${JSON.stringify(b)}`);
  return out;
}

let changedFiles = 0;
for (const f of files) {
  const before = norm(JSON.parse(fs.readFileSync(path.join(SNAP, f), 'utf8')));
  const afterFile = path.join(DATA, f);
  if (!fs.existsSync(afterFile)) { problems.push(`${f}: файл пропал`); continue; }
  const after = norm(JSON.parse(fs.readFileSync(afterFile, 'utf8')));
  const d = diffPaths(before, after);
  if (d.length) {
    changedFiles++;
    problems.push(`${f}:\n      ` + d.slice(0, 8).join('\n      ') + (d.length > 8 ? `\n      …и ещё ${d.length - 8}` : ''));
  }
}
for (const f of walk(DATA)) if (!files.includes(f)) problems.push(`${f}: появился новый файл`);

// Контрольные дробные значения: их теряло округление в поле «число»
const price = JSON.parse(fs.readFileSync(path.join(DATA, 'price.json'), 'utf8'));
const weights = price.groups.flatMap(g => g.rows.map(r => r.weight)).filter(w => w !== null);
for (const w of [0.045, 2.32, 0.42, 8.05]) {
  if (!weights.includes(w)) problems.push(`в price.json пропал дробный вес ${w} — где-то осталось округление`);
}
const abz = JSON.parse(fs.readFileSync(path.join(DATA, 'catalog/items/zapasnye-chasti-i-komplektuyushchie--zapchasti-dlya-drobilok--drobilki-shchekovye--smd-108a.json'), 'utf8'));
if (!abz.some(i => i.weight === 0.6)) problems.push('в карточках СМД-108А пропал дробный вес 0,6 кг');

console.log(`файлов данных изменилось после прогона: ${changedFiles} из ${files.length}`);
fs.rmSync(SNAP, { recursive: true, force: true });

if (problems.length) {
  console.log(`\nПРОБЛЕМЫ (${problems.length}):\n  ` + problems.slice(0, 20).join('\n  '));
  process.exitCode = 1;
} else {
  console.log('\nАдминка возвращает данные без потерь: после полного прогона все файлы совпадают со снимком.');
}
