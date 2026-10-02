/**
 * Проверка собранного сайта: node tools/check.mjs
 * Битые внутренние ссылки, недостающие картинки, canonical, H1, title и description,
 * карты сайта, микроразметка, следы соцсетей. Ничего не чинит — только показывает.
 */
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(import.meta.dirname, '..');
const DIST = path.join(ROOT, 'dist');
const SITE = 'https://masterskie174.ru';

const files = [];
(function walk(dir, base = '') {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p, base + '/' + e.name);
    else files.push({ abs: p, rel: base + '/' + e.name });
  }
})(DIST);

// 500.html отдаётся сервером при аварии и собирается не сборщиком — его не проверяем.
const pages = files.filter(f => f.rel.endsWith('.html') && f.rel !== '/500.html');
const exists = new Set(files.map(f => f.rel));
const urlOf = rel => rel.endsWith('/index.html') ? rel.slice(0, -'index.html'.length) : rel;
const pageUrls = new Set(pages.map(p => urlOf(p.rel)));

const problems = [];
const warn = [];
const titles = new Map();
const descs = new Map();
let checkedLinks = 0, checkedImgs = 0;

for (const p of pages) {
  const html = fs.readFileSync(p.abs, 'utf8');
  const url = urlOf(p.rel);

  const title = (html.match(/<title>([\s\S]*?)<\/title>/) || [])[1] || '';
  const desc = (html.match(/<meta name="description" content="([^"]*)"/) || [])[1] || '';
  const canon = (html.match(/<link rel="canonical" href="([^"]*)"/) || [])[1] || '';
  const h1n = (html.match(/<h1[\s>]/g) || []).length;
  const noindex = /<meta name="robots" content="noindex/.test(html);

  if (!title) problems.push(`${url}: нет title`);
  if (!noindex && !desc) problems.push(`${url}: нет description`);
  if (!canon) problems.push(`${url}: нет canonical`);
  else if (canon !== SITE + url) problems.push(`${url}: canonical не на себя — ${canon}`);
  if (h1n !== 1) problems.push(`${url}: H1 на странице ${h1n}`);
  if (desc.length > 180) problems.push(`${url}: description ${desc.length} знаков (цель до 180)`);

  if (!noindex) {
    if (titles.has(title)) problems.push(`дубль title: ${url} и ${titles.get(title)}`);
    else titles.set(title, url);
    if (desc && descs.has(desc)) problems.push(`дубль description: ${url} и ${descs.get(desc)}`);
    else if (desc) descs.set(desc, url);
  }

  // внутренние ссылки
  for (const m of html.matchAll(/<a\s[^>]*href="(\/[^"#?]*)"/g)) {
    const href = m[1];
    checkedLinks++;
    if (exists.has(href) || pageUrls.has(href) || exists.has(href.replace(/\/$/, '/index.html'))) continue;
    problems.push(`${url}: битая ссылка ${href}`);
  }
  // картинки
  for (const m of html.matchAll(/<img\s[^>]*src="(\/[^"?]*)"/g)) {
    checkedImgs++;
    if (!exists.has(m[1])) problems.push(`${url}: нет картинки ${m[1]}`);
  }
  // соцсети, которых быть не должно
  for (const bad of ['twitter', 'facebook', 'fb.com', 'x.com']) {
    if (html.toLowerCase().includes(bad)) problems.push(`${url}: встретилось «${bad}»`);
  }
  // микроразметка должна разбираться
  for (const m of html.matchAll(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/g)) {
    try {
      const o = JSON.parse(m[1]);
      if (o['@type'] === 'Product' && o.offers && o.offers.price == null) {
        problems.push(`${url}: Product c Offer без цены`);
      }
    } catch (e) {
      problems.push(`${url}: микроразметка не разбирается — ${e.message}`);
    }
  }
}

// карты сайта
const index = fs.readFileSync(path.join(DIST, 'sitemap.xml'), 'utf8');
const maps = [...index.matchAll(/<loc>([^<]+)<\/loc>/g)].map(m => m[1]);
let inSitemap = 0;
const seenLoc = new Set();
for (const m of maps) {
  const rel = m.replace(SITE, '');
  if (!exists.has(rel)) { problems.push(`sitemap.xml ссылается на отсутствующий ${rel}`); continue; }
  const xml = fs.readFileSync(path.join(DIST, rel), 'utf8');
  for (const l of xml.matchAll(/<loc>([^<]+)<\/loc>/g)) {
    const u = l[1].replace(SITE, '');
    if (rel.endsWith('sitemap-images.xml')) continue;
    inSitemap++;
    if (seenLoc.has(u)) problems.push(`дубль в картах сайта: ${u}`);
    seenLoc.add(u);
    if (!pageUrls.has(u)) problems.push(`в карте сайта есть, а страницы нет: ${u}`);
  }
}
// noindex-страницы в карте быть не должно
for (const u of seenLoc) {
  const f = path.join(DIST, u.replace(/\/$/, '/index.html').replace(/^\//, ''));
  if (fs.existsSync(f) && /<meta name="robots" content="noindex/.test(fs.readFileSync(f, 'utf8'))) {
    problems.push(`в карте сайта страница с noindex: ${u}`);
  }
}

// robots.txt
const robots = fs.readFileSync(path.join(DIST, 'robots.txt'), 'utf8');
if (/^Host:/m.test(robots)) problems.push('robots.txt: осталась директива Host:');
if (!/Clean-param/.test(robots)) problems.push('robots.txt: нет Clean-param');
if (!robots.includes('Sitemap: ' + SITE + '/sitemap.xml')) problems.push('robots.txt: нет ссылки на карту сайта');
const staging = /^Disallow: \/$/m.test(robots);
if (staging) problems.push('robots.txt ЗАКРЫТ ЦЕЛИКОМ — это режим стенда, на бою так быть не должно');

// .htaccess: боевой вариант обязан канонизировать хост, стендовый — наоборот, не должен.
// Это вторая половина «забытого noindex»: собрать сайт как бой, а .htaccess оставить стендовый.
const ht = fs.readFileSync(path.join(ROOT, 'public', '.htaccess'), 'utf8');
const hasHostRules = /RewriteCond %\{HTTPS\} !=on/.test(ht) && ht.includes(SITE + '/$1');
if (!staging && !hasHostRules) {
  problems.push('.htaccess собран для СТЕНДА (без канонизации хоста), а robots.txt открыт — пересоберите: node tools/make-seo.mjs');
}
if (staging && hasHostRules) {
  problems.push('robots.txt стендовый, а .htaccess боевой — запросы со стенда уедут на боевой домен: STAGING=1 node tools/make-seo.mjs');
}
if (!/\(\.\+\?\/\)filter\//.test(ht)) problems.push('.htaccess: нет правила для старых адресов умного фильтра Битрикса');

// поисковый индекс
const si = JSON.parse(fs.readFileSync(path.join(DIST, 'search-index.json'), 'utf8'));
const byDraw = si.rows.filter(r => r.t === 'i' && r.d);
if (byDraw.length < 300) problems.push(`в поисковом индексе всего ${byDraw.length} позиций с номером чертежа`);

console.log(`страниц: ${pages.length}`);
console.log(`в картах сайта: ${inSitemap}`);
console.log(`проверено ссылок: ${checkedLinks}, картинок: ${checkedImgs}`);
console.log(`уникальных title: ${titles.size}, description: ${descs.size}`);
console.log(`в поисковом индексе: ${si.rows.length} записей, из них с номером чертежа ${byDraw.length}`);
if (warn.length) console.log('\nЗамечания:\n  ' + warn.join('\n  '));
if (problems.length) {
  const shown = problems.slice(0, 40);
  console.log(`\nПРОБЛЕМЫ (${problems.length}):\n  ` + shown.join('\n  '));
  if (problems.length > shown.length) console.log(`  …и ещё ${problems.length - shown.length}`);
  process.exitCode = 1;
} else {
  console.log('\nПроверки пройдены.');
}
