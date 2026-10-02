/**
 * Карта редиректов, .htaccess и SEO-выгрузки.
 * Запуск: node tools/make-seo.mjs   (после php app/bin/build.php)
 *
 * Делает:
 *   seo/urls_current.csv  — инвентаризация всех адресов старого сайта
 *   seo/redirects.csv     — карта keep / 301 с покрытием 100% старых адресов
 *   public/.htaccess      — правила с подставленным блоком редиректов
 * Проверяет: все старые адреса покрыты, все цели существуют в dist/, цепочек нет.
 */
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(import.meta.dirname, '..');
const A = path.join(ROOT, 'seo', '_audit');
const SITE = 'https://masterskie174.ru';

const rd = f => JSON.parse(fs.readFileSync(path.join(A, f), 'utf8'));
const sitemapUrls = fs.readFileSync(path.join(A, 'all_urls.txt'), 'utf8').split('\n').map(s => s.trim()).filter(Boolean);
const navNames = rd('names.json');
const crawl = rd('crawl.json');
const crawlBy = new Map(crawl.map(r => [r.url, r]));
const legacy = rd('legacy_map.json');          // старый адрес каталога → канонический

const sections = Object.values(JSON.parse(fs.readFileSync(path.join(ROOT, 'data', 'catalog', 'sections.json'), 'utf8')));
const itemDir = path.join(ROOT, 'data', 'catalog', 'items');
const items = fs.readdirSync(itemDir).filter(f => f.endsWith('.json'))
  .flatMap(f => JSON.parse(fs.readFileSync(path.join(itemDir, f), 'utf8')));

/* --------------------------------------------- что есть в собранном сайте */
const DIST = path.join(ROOT, 'dist');
const built = new Set();
(function walk(dir, base = '') {
  if (!fs.existsSync(dir)) return;
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    if (e.isDirectory()) walk(path.join(dir, e.name), base + '/' + e.name);
    else if (e.name === 'index.html') built.add(base + '/');
    else if (e.name.endsWith('.html')) built.add(base + '/' + e.name);
  }
})(DIST);

/* ------------------------------------- редиректы страниц вне каталога */
const PAGE_REDIRECTS = {
  '/company/history/': ['/company/', 'демонстрационный текст шаблона (про нефтедобычу), переносить нечего'],
  '/company/licenses/': ['/company/certificates/', 'демонстрационный текст шаблона (разрешения на рыбалку и охоту); новая страница сертификатов'],
  '/info/more/': ['/info/', 'демо-страница шаблона Aspro'],
  '/info/more/buttons/': ['/info/', 'демо-страница шаблона Aspro'],
  '/info/more/elements/': ['/info/', 'демо-страница шаблона Aspro'],
  '/info/more/icons/': ['/info/', 'демо-страница шаблона Aspro'],
  '/info/more/typograpy/': ['/info/', 'демо-страница шаблона Aspro'],
  '/study/': ['/', 'демо-раздел «Каталог курсов», к деятельности завода не относится'],
  '/form/': ['/contacts/', 'служебная страница формы Битрикса'],
  '/cart/': ['/catalog/', 'корзина отключена, страница отдавала 404'],
  '/cart/order/': ['/catalog/', 'корзина отключена, страница отдавала 404'],
  '/404.php': [null, 'служебная страница Битрикса, уже отдавала 404; из карты сайта исключена'],
  '/500.html': [null, 'служебная страница ошибки сервера, остаётся как есть'],
};

/* ------------------------------------------------- собираем все адреса */
const skip = u => /\/filter\//.test(u) || /\/(?:apply|clear)\//.test(u) || u.includes('?');
const allOld = [...new Set([...sitemapUrls, ...Object.keys(navNames)])].filter(u => !skip(u)).sort();

const sourceOf = u => {
  const s = [];
  if (sitemapUrls.includes(u)) s.push('sitemap');
  if (navNames[u]) s.push('навигация');
  const r = crawlBy.get(u);
  if (r && r.status) s.push('краулинг ' + r.status);
  return s.join(' + ') || 'навигация';
};

/* --------------------------------------------------------- карта правил */
const rows = [];
const problems = [];

for (const u of allOld) {
  const r = crawlBy.get(u);
  const oldStatus = r ? r.status : '';
  let target = null, type = 'keep', note = '';

  if (u in PAGE_REDIRECTS) {
    const [t, n] = PAGE_REDIRECTS[u];
    note = n;
    if (t === null) { type = 'drop'; } else { type = '301'; target = t; }
  } else if (legacy[u]) {
    type = '301';
    target = legacy[u];
    note = u.startsWith('/catalog/zapchasti-dlya-drobilnogo-oborudovaniya/') ? 'старая схема адресов каталога'
      : u.startsWith('/catalog/setka-riflenaya-dlya-grokhotov/') ? 'сетка перенесена под «Запчасти для грохотов»'
        : u.startsWith('/catalog/zapchasti-dlya-abz/') ? 'АБЗ перенесён под «Запасные части и комплектующие»'
          : 'тупиковый раздел без собственных товаров';
  } else {
    target = u;
    note = (r && /^Каталог( товаров)? - /.test(r.title || '')) ? 'адрес сохранён; на старом сайте страница отдавала пустую копию каталога' : 'адрес сохранён';
  }

  // приоритет: главная и то, что реально рендерилось и имело уникальный title
  const prio = u === '/' ? 1
    : (u === '/catalog/' || u === '/contacts/' || u === '/company/' || u === '/price/') ? 1
      : (r && r.status === 200 && !/^Каталог( товаров)? - /.test(r.title || '')) ? 2 : 3;

  rows.push({ old: u, new: target, type, prio, source: sourceOf(u), oldStatus, note, check: '' });
}

/* ----------------------------------------------------------- проверки */
// 1) цели существуют в собранном сайте
for (const r of rows) {
  if (r.type === 'drop') { r.check = 'отдаёт 404 (служебная)'; continue; }
  if (!built.has(r.new)) { problems.push(`цель не собрана: ${r.old} → ${r.new}`); r.check = 'ЦЕЛЬ НЕ НАЙДЕНА'; }
  else r.check = r.type === 'keep' ? 'страница есть, адрес не меняется' : 'цель собрана, ожидается один 301';
}
// 2) цепочек нет: цель не может быть источником 301
const redirSources = new Set(rows.filter(r => r.type === '301').map(r => r.old));
for (const r of rows) {
  if (r.type === '301' && redirSources.has(r.new)) problems.push(`цепочка: ${r.old} → ${r.new} → …`);
}
// 3) покрытие
const covered = new Set(rows.map(r => r.old));
for (const u of allOld) if (!covered.has(u)) problems.push(`адрес не покрыт: ${u}`);

/* ------------------------------------------------------------- выгрузки */
const csv = v => '"' + String(v ?? '').replace(/"/g, '""') + '"';
fs.mkdirSync(path.join(ROOT, 'seo'), { recursive: true });

fs.writeFileSync(path.join(ROOT, 'seo', 'redirects.csv'),
  '﻿' + ['Старый URL;Новый URL;Тип;Приоритет;Источник;Код ответа сейчас;Комментарий;Статус проверки']
    .concat(rows.map(r => [r.old, r.new ?? '', r.type, r.prio, r.source, r.oldStatus, r.note, r.check].map(csv).join(';')))
    .join('\r\n') + '\r\n', 'utf8');

fs.writeFileSync(path.join(ROOT, 'seo', 'urls_current.csv'),
  '﻿' + ['URL;Код ответа;Title;Description;H1;В sitemap;В навигации;Отдавал пустую копию каталога']
    .concat(allOld.map(u => {
      const r = crawlBy.get(u) || {};
      const generic = /^Каталог( товаров)? - /.test(r.title || '');
      return [u, r.status ?? '', r.title ?? '', r.description ?? '', r.h1 ?? '',
        sitemapUrls.includes(u) ? 'да' : 'нет', navNames[u] ? 'да' : 'нет', generic ? 'да' : 'нет'].map(csv).join(';');
    }))
    .join('\r\n') + '\r\n', 'utf8');

/* --------------------------------------------------------- .htaccess */
// На стенде абсолютные адреса использовать нельзя: иначе каждый запрос уедет на боевой сайт.
const STAGING = process.env.STAGING === '1';
const TARGET = STAGING ? '' : SITE;

const redirectBlock = rows
  .filter(r => r.type === '301')
  .sort((a, b) => a.old.localeCompare(b.old))
  .map(r => {
    const from = r.old.replace(/^\//, '').replace(/([.?*+^$[\]\\(){}|-])/g, '\\$1');
    return `RewriteRule "^${from}$" "${TARGET}${r.new}" [R=301,L,NE]`;
  }).join('\n');

// Правила канонизации хоста на стенде выключены: там другой домен и часто нет сертификата.
const hostRules = STAGING ? `# Стенд: правила канонического хоста и https отключены (STAGING=1).
# Перед релизом соберите .htaccess без STAGING — тогда они появятся.` : `# http → https (301, а не 302, как было на старом сайте)
RewriteCond %{HTTPS} !=on
RewriteCond %{HTTP:X-Forwarded-Proto} !=https
RewriteRule ^(.*)$ ${SITE}/$1 [R=301,L,NE]
# www → без www (на старом сайте www уводил обратно на http — цепочка из трёх переходов)
RewriteCond %{HTTP_HOST} ^www\\.(.+)$ [NC]
RewriteRule ^(.*)$ ${SITE}/$1 [R=301,L,NE]
# index.php в адресе — дубль главной
RewriteRule ^index\\.php$ ${SITE}/ [R=301,L]
RewriteRule ^(.*)/index\\.php$ ${SITE}/$1/ [R=301,L]`;

const htaccess = `# ============================================================================
# masterskie174.ru — Завод ДСО. Файл собран скриптом tools/make-seo.mjs.
# Правила правятся в генераторе, а не здесь: при пересборке файл перезаписывается.
# Порядок важен: сначала старые адреса → новые (абсолютной ссылкой, один переход),
# затем канонизация хоста и слеша.
# ============================================================================

AddDefaultCharset UTF-8
Options -Indexes -MultiViews
DirectoryIndex index.html index.php
# Свой переход со слеша делаем сами: DirectorySlash за nginx-прокси уводит на http.
DirectorySlash Off

ErrorDocument 404 /404.php

<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /

# --- 1. Карта переноса адресов (${rows.filter(r => r.type === '301').length} правил) ---
${redirectBlock}

# --- 2. Канонический хост и протокол ---
${hostRules}

# --- 2a. Старые адреса умного фильтра Битрикса ведут в свой раздел ---
RewriteRule "^(.+?/)filter/.*$" "${TARGET}/$1" [R=301,L,NE]

# --- 3. Завершающий слеш обязателен ---
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_URI} !^/(lead|404)\\.php$
RewriteCond %{REQUEST_URI} !^/admin(/|$)
RewriteCond %{REQUEST_URI} !\\.[a-zA-Z0-9]{2,5}$
RewriteCond %{REQUEST_URI} !/$
RewriteRule ^(.*)$ ${TARGET}/$1/ [R=301,L,NE]

# --- 4. Админка ---
RewriteCond %{REQUEST_URI} ^/admin(/.*)?$
RewriteRule ^admin(/.*)?$ /admin/index.php [L,QSA]
</IfModule>

# --- Заголовки безопасности ---
<IfModule mod_headers.c>
  Header always set X-Content-Type-Options "nosniff"
  Header always set X-Frame-Options "SAMEORIGIN"
  Header always set Referrer-Policy "strict-origin-when-cross-origin"
  Header always set Permissions-Policy "geolocation=(), microphone=(), camera=()"
  # HSTS включать только после того, как сертификат точно работает и продлевается.
  # Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
  # Вебвизор Метрики грузит воркеры из blob: и ходит на поддомены Яндекса — иначе он молча не работает.
  # Перед релизом проверить консоль и отладчик целей Метрики на стенде.
  Header always set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' https://mc.yandex.ru https://yastatic.net; worker-src 'self' blob:; style-src 'self' 'unsafe-inline'; img-src 'self' data: https://mc.yandex.ru https://*.yandex.ru https://*.yandex.net; font-src 'self'; connect-src 'self' https://mc.yandex.ru https://*.yandex.ru https://*.yandex.net; frame-src https://yandex.ru https://*.yandex.ru; form-action 'self'; base-uri 'self'; object-src 'none'"
  <FilesMatch "\\.(css|js|woff2|png|jpe?g|webp|avif|svg|ico)$">
    Header set Cache-Control "public, max-age=31536000, immutable"
  </FilesMatch>
  <FilesMatch "\\.(html|xml|json|csv|txt)$">
    Header set Cache-Control "public, max-age=600, must-revalidate"
  </FilesMatch>
</IfModule>

# --- Сжатие ---
<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE text/html text/css text/plain text/xml application/javascript application/json image/svg+xml
</IfModule>
<IfModule mod_brotli.c>
  AddOutputFilterByType BROTLI_COMPRESS text/html text/css text/plain text/xml application/javascript application/json image/svg+xml
</IfModule>

# --- Типы и срок жизни ---
<IfModule mod_mime.c>
  AddType font/woff2 .woff2
  AddType image/webp .webp
  AddType image/avif .avif
  AddType application/manifest+json .webmanifest
</IfModule>

# --- Закрываем служебное ---
<FilesMatch "^(\\.htaccess|\\.user\\.ini|composer\\.(json|lock)|.*\\.md)$">
  Require all denied
</FilesMatch>
`;

fs.writeFileSync(path.join(ROOT, 'public', '.htaccess'), htaccess, 'utf8');
if (STAGING) console.log('ВНИМАНИЕ: .htaccess собран для СТЕНДА — без канонизации хоста и с относительными целями.');

/* ----------------------------------------------------------- отчёт */
const n301 = rows.filter(r => r.type === '301').length;
const nKeep = rows.filter(r => r.type === 'keep').length;
const nDrop = rows.filter(r => r.type === 'drop').length;
console.log(`старых адресов: ${rows.length} (sitemap ${sitemapUrls.length}, в навигации но не в sitemap ${allOld.filter(u => navNames[u] && !sitemapUrls.includes(u)).length})`);
console.log(`  адрес сохранён: ${nKeep}`);
console.log(`  301 на новый адрес: ${n301}`);
console.log(`  остаётся 404 (служебные): ${nDrop}`);
console.log(`страниц в dist: ${built.size}`);
console.log(`новых адресов, которых не было на старом сайте: ${[...built].filter(u => !covered.has(u)).length}`);
if (problems.length) {
  console.log('\nПРОБЛЕМЫ (' + problems.length + '):');
  problems.slice(0, 30).forEach(p => console.log('  ' + p));
  process.exitCode = 1;
} else {
  console.log('\nПроверки пройдены: все старые адреса покрыты, цели собраны, цепочек нет.');
}
