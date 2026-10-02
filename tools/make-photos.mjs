/**
 * Медиатека: data/photos.json. Идентификатор фото — это его путь на сайте,
 * поэтому в карточках товаров и на страницах хранится ровно то же значение.
 * Папка «Со старого сайта» — 745 файлов из /upload/, перенесённых как есть.
 * Запуск: node tools/make-photos.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const ROOT = path.resolve(import.meta.dirname, '..');
const PUB = path.join(ROOT, 'public');
const out = path.join(ROOT, 'data', 'photos.json');
const prev = fs.existsSync(out) ? JSON.parse(fs.readFileSync(out, 'utf8')) : {};
const prevById = new Map();
for (const list of Object.values(prev)) for (const p of list) prevById.set(p.id, p);

// Размеры берём из кэша сборки, если он есть: там они уже посчитаны.
const dimsFile = path.join(ROOT, 'storage', 'img-dims.json');
const dims = fs.existsSync(dimsFile) ? JSON.parse(fs.readFileSync(dimsFile, 'utf8')) : {};

// Где какое фото используется — чтобы подписать осмысленно.
const itemDir = path.join(ROOT, 'data', 'catalog', 'items');
const altByPath = {};
if (fs.existsSync(itemDir)) {
  for (const f of fs.readdirSync(itemDir)) {
    for (const i of JSON.parse(fs.readFileSync(path.join(itemDir, f), 'utf8'))) {
      for (const p of i.photos || []) altByPath[p] ??= i.name + (i.model ? ' — ' + i.model : '');
    }
  }
}
for (const s of Object.values(JSON.parse(fs.readFileSync(path.join(ROOT, 'data', 'catalog', 'sections.json'), 'utf8')))) {
  if (s.photo) altByPath[s.photo] ??= s.name;
}

function walk(dir, base) {
  const out = [];
  if (!fs.existsSync(dir)) return out;
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) out.push(...walk(p, base + '/' + e.name));
    else if (/\.(jpe?g|png|webp|gif|avif)$/i.test(e.name)) out.push(base + '/' + e.name);
  }
  return out;
}

function entry(rel, fallbackAlt) {
  const old = prevById.get(rel);
  if (old) return old;
  const wh = dims[rel];
  return { id: rel, w: wh ? wh[0] : 0, h: wh ? wh[1] : 0, alt: altByPath[rel] || fallbackAlt };
}

const oldSite = walk(path.join(PUB, 'upload', 'iblock'), '/upload/iblock')
  .concat(walk(path.join(PUB, 'upload', 'resize_cache'), '/upload/resize_cache'))
  .sort();
const media = walk(path.join(PUB, 'upload', 'media'), '/upload/media').sort();

const photos = {
  media: media.map(p => entry(p, 'Фото')),
  'old-site': oldSite.map(p => entry(p, 'Фото со старого сайта')),
};
fs.writeFileSync(out, JSON.stringify(photos, null, 2) + '\n', 'utf8');
console.log('медиатека: новых загрузок', photos.media.length, '· со старого сайта', photos['old-site'].length);
console.log('с осмысленной подписью:', photos['old-site'].filter(p => p.alt !== 'Фото со старого сайта').length);
