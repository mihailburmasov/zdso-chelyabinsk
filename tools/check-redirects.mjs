/**
 * Проверка карты редиректов на живом сервере (стенд или бой).
 *   node tools/check-redirects.mjs https://stend.masterskie174.ru
 *   node tools/check-redirects.mjs https://masterskie174.ru --limit 50
 *
 * Для каждой строки seo/redirects.csv:
 *   тип 301  — старый адрес должен отдать ровно один 301 на указанную цель, цель — 200
 *   тип keep — адрес должен отдавать 200 без переходов
 *   тип drop — адрес должен отдавать 404
 * Запускать ОБЯЗАТЕЛЬНО до релиза: ни PHP-роутер, ни сборщик .htaccess не исполняют.
 */
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(import.meta.dirname, '..');
const BASE = (process.argv[2] || '').replace(/\/$/, '');
if (!BASE) {
  console.error('Укажите адрес: node tools/check-redirects.mjs https://stend.masterskie174.ru');
  process.exit(2);
}
const limitArg = process.argv.indexOf('--limit');
const LIMIT = limitArg > 0 ? Number(process.argv[limitArg + 1]) : Infinity;
const CONCURRENCY = Number(process.env.CONCURRENCY || 6);

// Разбор CSV с разделителем «;» и кавычками
const raw = fs.readFileSync(path.join(ROOT, 'seo', 'redirects.csv'), 'utf8').replace(/^﻿/, '');
const lines = raw.split(/\r?\n/).filter(Boolean);
const rows = lines.slice(1).map(l => {
  const cells = [];
  let cur = '', q = false;
  for (let i = 0; i < l.length; i++) {
    const c = l[i];
    if (q) {
      if (c === '"' && l[i + 1] === '"') { cur += '"'; i++; }
      else if (c === '"') q = false;
      else cur += c;
    } else if (c === '"') q = true;
    else if (c === ';') { cells.push(cur); cur = ''; }
    else cur += c;
  }
  cells.push(cur);
  return { old: cells[0], new: cells[1], type: cells[2] };
}).filter(r => r.old).slice(0, LIMIT);

const problems = [];
let done = 0, ok = 0;

async function head(url) {
  const res = await fetch(url, { redirect: 'manual', headers: { 'User-Agent': 'ZDSO-redirect-check/1.0' } });
  return { status: res.status, location: res.headers.get('location') || '' };
}

async function worker(queue) {
  while (queue.length) {
    const r = queue.shift();
    try {
      const a = await head(BASE + r.old);
      if (r.type === '301') {
        if (a.status !== 301) {
          problems.push(`${r.old}: ожидался 301, получен ${a.status}${a.location ? ' → ' + a.location : ''}`);
        } else {
          const want = [BASE + r.new, r.new];
          if (!want.includes(a.location)) {
            problems.push(`${r.old}: 301 ведёт на ${a.location}, ожидалось ${BASE + r.new}`);
          } else {
            const b = await head(a.location.startsWith('http') ? a.location : BASE + a.location);
            if (b.status !== 200) problems.push(`${r.old} → ${r.new}: цель отдаёт ${b.status}${b.location ? ' → ' + b.location : ''} (должна быть 200 без второго перехода)`);
            else ok++;
          }
        }
      } else if (r.type === 'keep') {
        if (a.status !== 200) problems.push(`${r.old}: адрес сохраняется, но отдаёт ${a.status}${a.location ? ' → ' + a.location : ''}`);
        else ok++;
      } else if (r.type === 'drop') {
        if (a.status !== 404 && a.status !== 410) problems.push(`${r.old}: должен отдавать 404, отдаёт ${a.status}`);
        else ok++;
      }
    } catch (e) {
      problems.push(`${r.old}: запрос не прошёл — ${e.message}`);
    }
    if (++done % 100 === 0) console.log(`${done}/${rows.length}`);
  }
}

const queue = rows.slice();
await Promise.all(Array.from({ length: CONCURRENCY }, () => worker(queue)));

/* --------------------- варианты хоста: http, www, http+www ---------------------
   Именно на них старый сайт давал цепочку www → http → https и уводил на незащищённый
   протокол. Проверяем страницы доверия и выборку правил карты. */
const HOST = BASE.replace(/^https?:\/\//, '').replace(/^www\./, '');
const PROTO = BASE.startsWith('https') ? 'https' : 'http';
const sample = ['/', '/company/', '/contacts/', '/catalog/', '/price/']
  .concat(rows.filter(r => r.type === '301').slice(0, 10).map(r => r.old));

let hostOk = 0, hostTotal = 0;
for (const p of sample) {
  for (const variant of [`http://${HOST}`, `${PROTO}://www.${HOST}`, `http://www.${HOST}`]) {
    hostTotal++;
    let url = variant + p;
    const chain = [];
    try {
      for (let hop = 0; hop < 5; hop++) {
        const res = await fetch(url, { redirect: 'manual', headers: { 'User-Agent': 'ZDSO-redirect-check/1.0' } });
        chain.push(`${res.status} ${url}`);
        if (res.status < 300 || res.status >= 400) break;
        const loc = res.headers.get('location') || '';
        if (!loc) break;
        url = loc.startsWith('http') ? loc : BASE + loc;
      }
    } catch (e) {
      problems.push(`${variant}${p}: запрос не прошёл — ${e.message}`);
      continue;
    }
    const hops = chain.length - 1;
    const last = chain[chain.length - 1] || '';
    if (hops > 1) problems.push(`${variant}${p}: переходов ${hops}, должен быть один — ${chain.join(' → ')}`);
    else if (!last.startsWith('200')) problems.push(`${variant}${p}: в конце ${last}`);
    else if (chain.slice(1).some(c => c.includes(' http://'))) problems.push(`${variant}${p}: по дороге уход на http — ${chain.join(' → ')}`);
    else hostOk++;
  }
}

console.log(`\nпроверено адресов: ${rows.length}, корректно: ${ok}`);
console.log(`проверено вариантов хоста (http, www, http+www): ${hostTotal}, корректно: ${hostOk}`);
if (problems.length) {
  const shown = problems.slice(0, 50);
  console.log(`\nПРОБЛЕМЫ (${problems.length}):\n  ` + shown.join('\n  '));
  if (problems.length > shown.length) console.log(`  …и ещё ${problems.length - shown.length}`);
  process.exitCode = 1;
} else {
  console.log('\nВсе правила отрабатывают: один 301 и затем 200, цепочек нет.');
}
