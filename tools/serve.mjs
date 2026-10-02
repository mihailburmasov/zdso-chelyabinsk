// Статический сервер для проверок и скриншотов: node tools/serve.mjs [порт]
import http from 'node:http'; import fs from 'node:fs'; import path from 'node:path';
const ROOT = path.resolve(import.meta.dirname, '..', 'dist');
const PORT = Number(process.argv[2] || 8098);
const T = { '.html':'text/html; charset=utf-8', '.css':'text/css', '.js':'text/javascript', '.json':'application/json',
  '.svg':'image/svg+xml', '.png':'image/png', '.jpg':'image/jpeg', '.jpeg':'image/jpeg', '.JPG':'image/jpeg',
  '.webp':'image/webp', '.woff2':'font/woff2', '.xml':'application/xml', '.txt':'text/plain; charset=utf-8',
  '.csv':'text/csv; charset=utf-8', '.ico':'image/x-icon', '.webmanifest':'application/manifest+json' };
http.createServer((req, res) => {
  let p = decodeURIComponent(new URL(req.url, 'http://x').pathname);
  if (p.endsWith('/')) p += 'index.html';
  const f = path.join(ROOT, p);
  if (!f.startsWith(ROOT) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) {
    res.writeHead(404, { 'Content-Type': 'text/html; charset=utf-8' });
    return res.end(fs.existsSync(path.join(ROOT, '404.html')) ? fs.readFileSync(path.join(ROOT, '404.html')) : 'нет');
  }
  res.writeHead(200, { 'Content-Type': T[path.extname(f)] || 'application/octet-stream' });
  fs.createReadStream(f).pipe(res);
}).listen(PORT, '127.0.0.1', () => console.log('dist на http://127.0.0.1:' + PORT));
