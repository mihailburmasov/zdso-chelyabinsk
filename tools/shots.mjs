/**
 * Скриншоты через CDP с эмуляцией устройства: node tools/shots.mjs
 * Окно Chrome на Windows не сужается меньше ~500 px, поэтому --window-size
 * для мобильной ширины не годится — нужен Emulation.setDeviceMetricsOverride.
 * Заодно проверяет горизонтальный выезд (scrollWidth > clientWidth).
 */
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';

const CHROME = process.env.CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const BASE = process.env.BASE || 'http://127.0.0.1:8098';
const OUT = process.env.SHOTS_DIR || path.join(os.tmpdir(), 'zdso-shots');
const PORT = 9333;

const PAGES = [
  ['home', '/'],
  ['catalog', '/catalog/'],
  ['section', '/catalog/zapasnye-chasti-i-komplektuyushchie/zapchasti-dlya-drobilok/drobilki-shchekovye/smd-108a/'],
  ['item', '/catalog/zapasnye-chasti-i-komplektuyushchie/zapchasti-dlya-drobilok/drobilki-shchekovye/smd-108a/plita-drobyashchaya-podvizhnaya-4844802022/'],
  ['tehnika', '/tehnika/smd-108a/'],
  ['price', '/price/drobilki-shchekovye/'],
  ['service', '/services/lite/'],
  ['contacts', '/contacts/'],
  ['article', '/info/articles/kak-podobrat-yacheyku-riflenoy-setki/'],
  ['search', '/search/'],
  ['e404', '/net-takoy-stranicy/'],
];
const VIEWS = [
  ['360', 360, 760],
  ['768', 768, 1024],
  ['1280', 1280, 900],
  ['1920', 1920, 1000],
];

fs.mkdirSync(OUT, { recursive: true });
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'zdso-chrome-'));
const chrome = spawn(CHROME, [
  '--headless=new', '--disable-gpu', '--no-sandbox', '--hide-scrollbars',
  '--remote-debugging-port=' + PORT, '--user-data-dir=' + profile, 'about:blank',
], { stdio: 'ignore' });

const sleep = ms => new Promise(r => setTimeout(r, ms));

async function wsUrl() {
  for (let i = 0; i < 60; i++) {
    try {
      const r = await fetch(`http://127.0.0.1:${PORT}/json/version`);
      const j = await r.json();
      if (j.webSocketDebuggerUrl) return j.webSocketDebuggerUrl;
    } catch {}
    await sleep(250);
  }
  throw new Error('Chrome не поднялся');
}

class CDP {
  constructor(ws) { this.ws = ws; this.id = 0; this.waits = new Map(); this.sessions = new Map();
    ws.addEventListener('message', ev => {
      const m = JSON.parse(ev.data);
      if (m.id && this.waits.has(m.id)) { const [res, rej] = this.waits.get(m.id); this.waits.delete(m.id);
        m.error ? rej(new Error(m.error.message)) : res(m.result); }
    });
  }
  send(method, params = {}, sessionId) {
    const id = ++this.id;
    return new Promise((res, rej) => {
      this.waits.set(id, [res, rej]);
      this.ws.send(JSON.stringify({ id, method, params, sessionId }));
    });
  }
}

const url = await wsUrl();
const ws = new WebSocket(url);
await new Promise(r => ws.addEventListener('open', r, { once: true }));
const cdp = new CDP(ws);

const { targetId } = await cdp.send('Target.createTarget', { url: 'about:blank' });
const { sessionId } = await cdp.send('Target.attachToTarget', { targetId, flatten: true });
await cdp.send('Page.enable', {}, sessionId);
await cdp.send('Runtime.enable', {}, sessionId);

const problems = [];
for (const [vname, w, h] of VIEWS) {
  for (const [pname, p] of PAGES) {
    await cdp.send('Emulation.setDeviceMetricsOverride', {
      width: w, height: h, deviceScaleFactor: 1, mobile: w < 768,
    }, sessionId);
    await cdp.send('Page.navigate', { url: BASE + p }, sessionId);
    await sleep(w < 768 ? 900 : 700);
    const { result } = await cdp.send('Runtime.evaluate', {
      expression: `(() => {
        const d = document.documentElement;
        const over = [...document.querySelectorAll('body *')]
          .filter(el => el.getBoundingClientRect().right > d.clientWidth + 1 && getComputedStyle(el).position !== 'fixed')
          .slice(0, 5)
          .map(el => el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.split(' ').filter(Boolean).slice(0,2).join('.') : ''));
        return JSON.stringify({ sw: d.scrollWidth, cw: d.clientWidth, h1: document.querySelectorAll('h1').length, over });
      })()`,
      returnByValue: true,
    }, sessionId);
    const r = JSON.parse(result.value);
    if (r.sw > r.cw + 1) problems.push(`${vname}px ${p}: горизонтальный выезд ${r.sw} > ${r.cw}; виновники: ${r.over.join(', ')}`);
    if (r.h1 !== 1) problems.push(`${vname}px ${p}: H1 на странице ${r.h1}`);
    const shot = await cdp.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: process.env.FULLPAGE === '1' }, sessionId);
    fs.writeFileSync(path.join(OUT, `${pname}-${vname}.png`), Buffer.from(shot.data, 'base64'));
  }
  console.log(`ширина ${vname}: снято ${PAGES.length} страниц`);
}

ws.close();
chrome.kill();
console.log('\nскриншоты:', OUT);
if (problems.length) {
  console.log('\nПРОБЛЕМЫ (' + problems.length + '):');
  problems.forEach(x => console.log('  ' + x));
  process.exitCode = 1;
} else {
  console.log('\nГоризонтального выезда нет, H1 ровно один на всех проверенных страницах и ширинах.');
}
