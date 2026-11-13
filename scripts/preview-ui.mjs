// Capture the setup screen at real desktop/mobile viewports with Chrome DevTools.
import { spawn } from 'node:child_process';
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const root = process.cwd();
const library = process.argv.includes('--library');
const browser = spawn(process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', [
  '--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
  '--remote-debugging-port=9334', `--user-data-dir=${path.join(root, '.browser-preview', 'responsive')}`, 'about:blank',
], { windowsHide: true, stdio: 'ignore' });
let socket;
try {
  let tabs;
  for (let attempt = 0; attempt < 50; attempt++) {
    try { tabs = await (await fetch('http://127.0.0.1:9334/json/list')).json(); break; }
    catch { await new Promise(resolve => setTimeout(resolve, 100)); }
  }
  if (!tabs?.length) throw new Error('Chrome did not start.');
  socket = new WebSocket(tabs.find(tab => tab.type === 'page').webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
  let id = 0;
  const pending = new Map();
  socket.onmessage = event => {
    const message = JSON.parse(event.data);
    const request = pending.get(message.id);
    if (!request) return;
    pending.delete(message.id);
    clearTimeout(request.timer);
    message.error ? request.reject(new Error(message.error.message)) : request.resolve(message.result);
  };
  const send = (method, params = {}) => new Promise((resolve, reject) => {
    const requestId = ++id;
    const timer = setTimeout(() => { pending.delete(requestId); reject(new Error(`Timeout: ${method}`)); }, 15000);
    pending.set(requestId, { resolve, reject, timer });
    socket.send(JSON.stringify({ id: requestId, method, params }));
  });
  await mkdir(path.join(root, 'artifacts'), { recursive: true });
  for (const [label, width, height, mobile] of [['desktop', 1440, 1000, false], ['mobile', 390, 844, true]]) {
    await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile });
    await send('Page.navigate', { url: library ? pathToFileURL(path.join(root, 'artifacts/library-preview.html')).href : 'http://localhost:8081/setup' });
    await send('Runtime.evaluate', { expression: 'new Promise(resolve => { if (document.readyState === "complete") resolve(); else window.addEventListener("load", resolve, {once:true}); })', awaitPromise: true });
    const metrics = await send('Runtime.evaluate', { expression: 'JSON.stringify({width:innerWidth,content:document.documentElement.scrollWidth,logo:document.querySelector(".brand, .brand-lockup img")?.naturalWidth})', returnByValue: true });
    const result = JSON.parse(metrics.result.value);
    if (result.content > width || !result.logo) throw new Error(`Invalid ${label} layout: ${JSON.stringify(result)}`);
    const screenshot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
    await writeFile(path.join(root, 'artifacts', `${library ? 'library' : 'setup'}-${label}.png`), Buffer.from(screenshot.data, 'base64'));
    if (library) {
      const checks = await send('Runtime.evaluate', { expression: `(() => {
        const search = document.getElementById('video-search');
        const find = text => { search.value = text; search.dispatchEvent(new Event('input')); };
        find('INTRODUCAO');
        if (document.querySelectorAll('.video-card:not([hidden])').length !== 1) throw new Error('Accent search failed');
        find('no-such-video');
        if (document.getElementById('search-empty').hidden) throw new Error('Empty state failed');
        find('');
        if (document.querySelectorAll('.video-card:not([hidden])').length !== 6) throw new Error('Search reset failed');
        document.querySelector('a[href="#new-video"]').click();
        if (!document.getElementById('new-video').open) throw new Error('Upload did not open');
        if (document.getElementById('upload-form').checkValidity()) throw new Error('Missing fields accepted');
        document.querySelector('.video-details').open = true;
        if (document.documentElement.scrollWidth > innerWidth) throw new Error('Expanded layout overflow');
        return 'search, empty state, reset, upload and expanded layout passed';
      })()`, returnByValue: true });
      if (checks.exceptionDetails) throw new Error(JSON.stringify(checks.exceptionDetails));
      console.log(`${label}: ${checks.result.value}`);
      await send('Runtime.evaluate', { expression: 'window.scrollTo(0, 0)' });
      const expanded = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
      await writeFile(path.join(root, 'artifacts', `library-${label}-expanded.png`), Buffer.from(expanded.data, 'base64'));
    }
    console.log(`${label}: logo loaded, viewport ${result.width}px, content ${result.content}px`);
  }
  await send('Browser.close');
} finally {
  socket?.close();
  browser.kill();
}
