/**
 * Проверка поиска по номеру чертежа в настоящем браузере: node tools/check-search.mjs
 * Открывает /search/?q=… в headless Chrome и смотрит, что нашлось первым.
 * Нужен запущенный сервер: node tools/serve.mjs 8098
 */
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';

const CHROME = process.env.CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const BASE = process.env.BASE || 'http://127.0.0.1:8098';
const PORT = 9334;

// запрос → часть адреса, которая должна оказаться в первом результате
const CASES = [
  ['4844802022', '/plita-drobyashchaya-podvizhnaya-4844802022/'],
  ['484480202200', '/plita-drobyashchaya-podvizhnaya-4844802022/'],
  ['4844 802 022', '/plita-drobyashchaya-podvizhnaya-4844802022/'],
  ['4844-802-022', '/plita-drobyashchaya-podvizhnaya-4844802022/'],
  ['4844.802.022', '/plita-drobyashchaya-podvizhnaya-4844802022/'],
  ['СМД-108А 4844802022', '/plita-drobyashchaya-podvizhnaya-4844802022/'],
  ['1060411001', '/pitatel-plastinchatyy-tk-16a-dro-604/'],
  ['ТК15А-01-201', '/pitatel-plastinchatyy-tk-16a-dro-604/'],
  ['TK15A-01-201', '/pitatel-plastinchatyy-tk-16a-dro-604/'],
  ['297-4-0-1', '/dro-592-ksd-600/'],
  ['1059204001', '/dro-592-ksd-600/'],
  ['СМД-108А', '/tehnika/smd-108a/'],
  ['смд 108', '/tehnika/smd-108a/'],
  ['KSD-900', '/tehnika/smd-120a-ksd-900/'],
  ['плита дробящая', '/catalog/'],
  ['литьё по чертежам', '/services/lite/'],
];

const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'zdso-search-'));
const chrome = spawn(CHROME, [
  '--headless=new', '--disable-gpu', '--no-sandbox',
  '--remote-debugging-port=' + PORT, '--user-data-dir=' + profile, 'about:blank',
], { stdio: 'ignore' });
const sleep = ms => new Promise(r => setTimeout(r, ms));

async function wsUrl() {
  for (let i = 0; i < 60; i++) {
    try {
      const j = await (await fetch(`http://127.0.0.1:${PORT}/json/version`)).json();
      if (j.webSocketDebuggerUrl) return j.webSocketDebuggerUrl;
    } catch {}
    await sleep(250);
  }
  throw new Error('Chrome не поднялся');
}

const ws = new WebSocket(await wsUrl());
await new Promise(r => ws.addEventListener('open', r, { once: true }));
let id = 0;
const waits = new Map();
ws.addEventListener('message', ev => {
  const m = JSON.parse(ev.data);
  if (m.id && waits.has(m.id)) { const [res, rej] = waits.get(m.id); waits.delete(m.id); m.error ? rej(new Error(m.error.message)) : res(m.result); }
});
const send = (method, params = {}, sessionId) => new Promise((res, rej) => {
  const i = ++id; waits.set(i, [res, rej]);
  ws.send(JSON.stringify({ id: i, method, params, sessionId }));
});

const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
await send('Page.enable', {}, sessionId);
await send('Runtime.enable', {}, sessionId);

const problems = [];
for (const [q, expect] of CASES) {
  await send('Page.navigate', { url: BASE + '/search/?q=' + encodeURIComponent(q) }, sessionId);
  let hits = null;
  for (let i = 0; i < 25; i++) {
    await sleep(160);
    const { result } = await send('Runtime.evaluate', {
      expression: `(() => {
        const box = document.querySelector('[data-search-page]');
        if (!box || !box.innerHTML.trim()) return 'null';
        const a = [...box.querySelectorAll('a.search__hit')].slice(0, 5).map(x => x.getAttribute('href'));
        return JSON.stringify({ n: box.querySelectorAll('a.search__hit').length, first: a });
      })()`,
      returnByValue: true,
    }, sessionId);
    if (result.value && result.value !== 'null') { hits = JSON.parse(result.value); break; }
  }
  if (!hits) { problems.push(`«${q}»: результаты не отрисовались`); continue; }
  if (!hits.n) { problems.push(`«${q}»: ничего не нашлось`); continue; }
  if (!hits.first[0] || !hits.first[0].includes(expect)) {
    problems.push(`«${q}»: первый результат ${hits.first[0]}, ожидалось содержащее ${expect}`);
    continue;
  }
  console.log(`  «${q}» → ${hits.first[0]} (всего ${hits.n})`);
}

ws.close();
chrome.kill();
console.log(`\nпроверено запросов: ${CASES.length}`);
if (problems.length) {
  console.log(`\nПРОБЛЕМЫ (${problems.length}):\n  ` + problems.join('\n  '));
  process.exitCode = 1;
} else {
  console.log('Поиск по номеру чертежа работает во всех проверенных написаниях.');
}
