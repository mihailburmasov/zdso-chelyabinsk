/**
 * Проверка форм в настоящем браузере: node tools/check-forms.mjs
 * Открывает модальное окно разных типов и смотрит, что в нём показано.
 * Нужен запущенный сервер: node tools/serve.mjs 8098
 */
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';

const CHROME = process.env.CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const BASE = process.env.BASE || 'http://127.0.0.1:8098';
const PORT = 9335;

const CASES = [
  { page: '/', sel: '[data-modal="callback"]', kind: 'callback', comment: false, files: false, btn: 'Жду звонка' },
  { page: '/services/lite/', sel: '[data-modal="drawing"]', kind: 'drawing', comment: true, files: true, btn: 'Отправить чертёж' },
  { page: '/catalog/zapasnye-chasti-i-komplektuyushchie/zapchasti-dlya-drobilok/drobilki-shchekovye/smd-108a/plita-drobyashchaya-podvizhnaya-4844802022/', sel: '[data-modal="kp"]', kind: 'kp', comment: true, files: true, btn: 'Запросить КП' },
  { page: '/tehnika/smd-108a/', sel: '[data-modal="komplekt"]', kind: 'komplekt', comment: true, files: true, btn: 'Подобрать комплект' },
];

const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'zdso-forms-'));
const chrome = spawn(CHROME, ['--headless=new', '--disable-gpu', '--no-sandbox',
  '--remote-debugging-port=' + PORT, '--user-data-dir=' + profile, 'about:blank'], { stdio: 'ignore' });
const sleep = ms => new Promise(r => setTimeout(r, ms));

async function wsUrl() {
  for (let i = 0; i < 60; i++) {
    try { const j = await (await fetch(`http://127.0.0.1:${PORT}/json/version`)).json(); if (j.webSocketDebuggerUrl) return j.webSocketDebuggerUrl; } catch {}
    await sleep(250);
  }
  throw new Error('Chrome не поднялся');
}
const ws = new WebSocket(await wsUrl());
await new Promise(r => ws.addEventListener('open', r, { once: true }));
let id = 0; const waits = new Map();
ws.addEventListener('message', ev => {
  const m = JSON.parse(ev.data);
  if (m.id && waits.has(m.id)) { const [res, rej] = waits.get(m.id); waits.delete(m.id); m.error ? rej(new Error(m.error.message)) : res(m.result); }
});
const send = (method, params = {}, sessionId) => new Promise((res, rej) => { const i = ++id; waits.set(i, [res, rej]); ws.send(JSON.stringify({ id: i, method, params, sessionId })); });

const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
await send('Page.enable', {}, sessionId); await send('Runtime.enable', {}, sessionId);

const problems = [];
for (const c of CASES) {
  await send('Page.navigate', { url: BASE + c.page }, sessionId);
  await sleep(900);
  const { result } = await send('Runtime.evaluate', {
    expression: `(() => {
      const b = document.querySelector(${JSON.stringify(c.sel)});
      if (!b) return JSON.stringify({ err: 'нет кнопки ' + ${JSON.stringify(c.sel)} });
      b.click();
      const d = document.querySelector('[data-dialog="callback"]');
      if (!d || !d.open) return JSON.stringify({ err: 'окно не открылось' });
      const f = d.querySelector('form');
      return JSON.stringify({
        kind: f.querySelector('[name="kind"]').value,
        subject: f.querySelector('[name="subject"]').value,
        comment: !d.querySelector('[data-field="comment"]').hidden,
        files: !d.querySelector('[data-field="files"]').hidden,
        btn: f.querySelector('button[type="submit"]').textContent.trim(),
        title: d.querySelector('h2').textContent.trim(),
        consent: !!f.querySelector('[name="consent"]'),
        opened: !!f.dataset.opened
      });
    })()`,
    returnByValue: true,
  }, sessionId);
  const r = JSON.parse(result.value);
  if (r.err) { problems.push(`${c.page} (${c.kind}): ${r.err}`); continue; }
  if (r.kind !== c.kind) problems.push(`${c.page}: тип заявки ${r.kind}, ожидался ${c.kind}`);
  if (r.comment !== c.comment) problems.push(`${c.page} (${c.kind}): поле комментария ${r.comment ? 'показано' : 'скрыто'}, ожидалось ${c.comment ? 'показано' : 'скрыто'}`);
  if (r.files !== c.files) problems.push(`${c.page} (${c.kind}): загрузка файлов ${r.files ? 'показана' : 'скрыта'}, ожидалось ${c.files ? 'показана' : 'скрыта'}`);
  if (r.btn !== c.btn) problems.push(`${c.page} (${c.kind}): кнопка «${r.btn}», ожидалось «${c.btn}»`);
  if (!r.consent) problems.push(`${c.page} (${c.kind}): нет чекбокса согласия`);
  if (!r.opened) problems.push(`${c.page} (${c.kind}): не засечено время открытия формы`);
  console.log(`  ${c.kind}: «${r.title}» · кнопка «${r.btn}» · комментарий ${r.comment ? 'есть' : 'нет'} · файлы ${r.files ? 'есть' : 'нет'}${r.subject ? ' · предмет: ' + r.subject.slice(0, 40) : ''}`);
}

ws.close(); chrome.kill();
console.log(`\nпроверено типов заявок: ${CASES.length}`);
if (problems.length) { console.log('\nПРОБЛЕМЫ:\n  ' + problems.join('\n  ')); process.exitCode = 1; }
else console.log('Формы открываются нужным типом, с нужными полями и надписями.');
