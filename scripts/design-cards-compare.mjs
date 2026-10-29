#!/usr/bin/env node
// Renders every "Design cards" story from a static Storybook build at its
// card's size, in light and dark, and compares each with
// docs/design/screenshots/<Name>-<theme>.png (the visual target, PLAN.md).
// Writes the renders, a diff image per card and report.html to .design-check/.
//
//   npx storybook build -o ../../.design-check/storybook   (from packages/ui)
//   node scripts/design-cards-compare.mjs [--only Button,Badge] [--max 0.05] [--channel chrome]
//
// The diff counts pixels whose colour differs by more than 32 on any channel.
// Font rasterising differs between renderers, so small numbers are expected;
// read the side-by-side images, not only the percentage. With --max, the run
// exits 1 when any card differs by more than that fraction.
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const outDir = path.join(root, '.design-check');
const sbDir = path.join(outDir, 'storybook');
const shots = path.join(root, 'docs/design/screenshots');
const arg = (name) => {
  const i = process.argv.indexOf(name);
  return i > 0 ? process.argv[i + 1] : undefined;
};
const only = arg('--only')?.split(',');
const max = arg('--max') !== undefined ? Number(arg('--max')) : undefined;

if (!fs.existsSync(path.join(sbDir, 'index.json'))) {
  console.error(`No Storybook build at ${sbDir}. Build it first (see the header of this script).`);
  process.exit(2);
}

const TYPES = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json', '.woff2': 'font/woff2', '.svg': 'image/svg+xml', '.png': 'image/png' };
const server = http.createServer((req, res) => {
  const file = path.join(sbDir, decodeURIComponent(new URL(req.url, 'http://x').pathname));
  if (!file.startsWith(sbDir) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) {
    res.writeHead(404).end();
    return;
  }
  res.writeHead(200, { 'content-type': TYPES[path.extname(file)] ?? 'application/octet-stream' });
  fs.createReadStream(file).pipe(res);
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const base = `http://127.0.0.1:${server.address().port}`;

const index = JSON.parse(fs.readFileSync(path.join(sbDir, 'index.json'), 'utf8'));
const stories = Object.values(index.entries).filter((e) => e.type === 'story' && e.title === 'Design cards');
const cardHeight = (name) => {
  const head = fs.readFileSync(path.join(root, 'docs/design/components', name, 'index.html'), 'utf8').split('\n')[0];
  return Number(/height=(\d+)/.exec(head)?.[1] ?? 400);
};

// --channel chrome uses the installed Google Chrome when Playwright's own browser isn't downloaded.
const channel = arg('--channel');
const browser = await chromium.launch(channel ? { channel } : {});
const page = await browser.newPage({ deviceScaleFactor: 1 });
const rows = [];
let worst = 0;
for (const story of stories) {
  const name = story.name;
  if (only && !only.includes(name)) continue;
  const height = cardHeight(name);
  for (const theme of ['light', 'dark']) {
    await page.setViewportSize({ width: 960, height });
    await page.goto(`${base}/iframe.html?id=${story.id}&viewMode=story&globals=theme:${theme}`, { waitUntil: 'networkidle' });
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(400); // let open menus, tooltips and popovers settle
    const actual = path.join(outDir, `${name}-${theme}.png`);
    await page.screenshot({ path: actual, clip: { x: 0, y: 0, width: 960, height } });
    const ref = path.join(shots, `${name}-${theme}.png`);
    let ratio = null;
    if (fs.existsSync(ref)) {
      const result = await page.evaluate(
        async ([a, b]) => {
          const load = (src) => new Promise((ok) => { const i = new Image(); i.onload = () => ok(i); i.src = src; });
          const [ia, ib] = await Promise.all([load(a), load(b)]);
          const w = Math.max(ia.width, ib.width), h = Math.max(ia.height, ib.height);
          const draw = (img) => { const c = new OffscreenCanvas(w, h); const x = c.getContext('2d'); x.drawImage(img, 0, 0); return x.getImageData(0, 0, w, h).data; };
          const da = draw(ia), db = draw(ib);
          const out = new OffscreenCanvas(w, h); const ox = out.getContext('2d'); const diff = ox.createImageData(w, h);
          let n = 0;
          for (let p = 0; p < da.length; p += 4) {
            const d = Math.max(Math.abs(da[p] - db[p]), Math.abs(da[p + 1] - db[p + 1]), Math.abs(da[p + 2] - db[p + 2]));
            const off = d > 32;
            if (off) n++;
            diff.data[p] = off ? 230 : da[p] * 0.3 + 178; diff.data[p + 1] = off ? 30 : da[p + 1] * 0.3 + 178; diff.data[p + 2] = off ? 60 : da[p + 2] * 0.3 + 178; diff.data[p + 3] = 255;
          }
          ox.putImageData(diff, 0, 0);
          const blob = await out.convertToBlob({ type: 'image/png' });
          const bytes = new Uint8Array(await blob.arrayBuffer());
          let bin = ''; for (const byte of bytes) bin += String.fromCharCode(byte);
          return { ratio: n / (w * h), png: btoa(bin) };
        },
        [`data:image/png;base64,${fs.readFileSync(actual).toString('base64')}`, `data:image/png;base64,${fs.readFileSync(ref).toString('base64')}`]
      );
      ratio = result.ratio;
      fs.writeFileSync(path.join(outDir, `${name}-${theme}.diff.png`), Buffer.from(result.png, 'base64'));
      worst = Math.max(worst, ratio);
    }
    rows.push({ name, theme, ratio, ref: path.relative(outDir, ref).replaceAll('\\', '/') });
    console.log(`${name.padEnd(16)} ${theme.padEnd(5)} ${ratio === null ? 'no reference' : (ratio * 100).toFixed(2) + '% differs'}`);
  }
}
await browser.close();
server.close();

const html = `<!doctype html><meta charset="utf-8"><title>Design cards check</title>
<style>body{font:14px system-ui;margin:24px;background:#eee}h2{font-size:16px;margin:32px 0 8px}figure{margin:0 0 12px}figcaption{font-size:12px;color:#555;margin-bottom:4px}img{display:block;border:1px solid #ccc;max-width:960px}</style>
${rows.map((r) => `<h2>${r.name} · ${r.theme} · ${r.ratio === null ? 'no reference' : (r.ratio * 100).toFixed(2) + '% differs'}</h2>
<figure><figcaption>Design (screenshots/)</figcaption><img src="${r.ref}"></figure>
<figure><figcaption>Built (Storybook)</figcaption><img src="${r.name}-${r.theme}.png"></figure>
${r.ratio === null ? '' : `<figure><figcaption>Differences in red</figcaption><img src="${r.name}-${r.theme}.diff.png"></figure>`}`).join('\n')}`;
fs.writeFileSync(path.join(outDir, 'report.html'), html);
console.log(`\nReport: ${path.join(outDir, 'report.html')}`);
if (max !== undefined && worst > max) {
  console.error(`Worst card differs by ${(worst * 100).toFixed(2)}%, above --max ${max * 100}%.`);
  process.exit(1);
}
