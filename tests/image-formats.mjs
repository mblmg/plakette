// Outside judge for image delivery: what does Chromium really download, and is any image broken?
//   NODE_PATH=/usr/local/lib/node_modules node tests/image-formats.mjs [--fallback] [--out file.json] URL…
// --fallback: <source type="image/avif|webp"> are changed to an unsupported type in the HTML (= a browser without
//             AVIF/WebP) – every image must still render through the original <img>.
// Per page and viewport (mobile 412×915 @2.625, desktop 1366×900): every image response (URL, content-type, bytes),
// totals per type, and broken images (complete && naturalWidth == 0 after scrolling through the page).
import { createRequire } from 'module';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
import fs from 'fs';

const args = process.argv.slice(2);
const fallback = args.includes('--fallback');
const outIdx = args.indexOf('--out');
const out = outIdx >= 0 ? args[outIdx + 1] : null;
const urls = args.filter((a, i) => !a.startsWith('--') && (outIdx < 0 || i !== outIdx + 1));
const viewports = {
  mobile: { viewport: { width: 412, height: 915 }, deviceScaleFactor: 2.625, isMobile: true, hasTouch: true },
  desktop: { viewport: { width: 1366, height: 900 }, deviceScaleFactor: 1 },
};

const browser = await chromium.launch({ executablePath: '/usr/local/bin/chromium', args: ['--no-sandbox'] });
const result = { fallback, pages: [] };
for (const url of urls) {
  for (const [vp, opts] of Object.entries(viewports)) {
    const ctx = await browser.newContext({ ...opts, ignoreHTTPSErrors: true });
    const page = await ctx.newPage();
    if (fallback) {
      await page.route('**/*', async (route) => {
        const req = route.request();
        if (req.resourceType() !== 'document') return route.continue();
        const resp = await route.fetch();
        let body = await resp.text();
        body = body.replace(/type=(["'])image\/(avif|webp)\1/gi, 'type=$1image/x-plk-unsupported$1')
                   .replace(/data-src(set)?-webp=/gi, 'data-plk-disabled$1=');
        await route.fulfill({ response: resp, body });
      });
    }
    const images = [];
    page.on('response', async (resp) => {
      if (resp.request().resourceType() !== 'image') return;
      let bytes = 0;
      try { bytes = (await resp.body()).length; } catch (e) { /* aborted */ }
      images.push({ url: resp.url(), status: resp.status(), type: (resp.headers()['content-type'] || '').split(';')[0], bytes });
    });
    await page.goto(url, { waitUntil: 'networkidle', timeout: 60000 });
    // Scroll through the page so lazy images load.
    await page.evaluate(async () => {
      for (let y = 0; y < document.body.scrollHeight; y += 400) { window.scrollTo(0, y); await new Promise(r => setTimeout(r, 120)); }
      window.scrollTo(0, 0);
    });
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(800);
    const dom = await page.evaluate(() => [...document.images].map(i => ({
      src: i.currentSrc || i.src, complete: i.complete, w: i.naturalWidth,
      inPicture: !!i.closest('picture'), visible: i.getClientRects().length > 0,
    })));
    const broken = dom.filter(i => i.complete && i.w === 0 && i.src && !i.src.startsWith('data:'));
    const totals = {};
    for (const i of images.filter(i => i.status < 400)) {
      const t = i.type || '?';
      totals[t] = totals[t] || { n: 0, bytes: 0 };
      totals[t].n++; totals[t].bytes += i.bytes;
    }
    const sum = images.reduce((s, i) => s + i.bytes, 0);
    result.pages.push({ url, viewport: vp, image_bytes: sum, totals, broken, imgs: dom.length, in_picture: dom.filter(i => i.inPicture).length, images, current: dom.map(i => i.src) });
    await ctx.close();
  }
}
await browser.close();
for (const p of result.pages) {
  const t = Object.entries(p.totals).map(([k, v]) => `${k.replace('image/', '')} ${v.n}× ${(v.bytes / 1024).toFixed(1)} KB`).join(', ');
  console.log(`${fallback ? '[fallback] ' : ''}${p.viewport.padEnd(7)} ${p.url}  images ${(p.image_bytes / 1024).toFixed(1)} KB  (${t})  <img> ${p.imgs}, in <picture> ${p.in_picture}, broken ${p.broken.length}`);
  for (const b of p.broken) console.log('   BROKEN', b.src);
}
if (out) fs.writeFileSync(out, JSON.stringify(result, null, 2));
