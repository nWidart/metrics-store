// Renders banner.png and social-preview.png from art.html with the local Google Chrome.
// Run from this folder: npx -y -p playwright-core node render.cjs [package]
const { chromium } = require('playwright-core');
const path = require('path');

const pkg = process.argv[2] || path.basename(path.resolve(__dirname, '../..'));
const chrome = process.env.CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

(async () => {
  const browser = await chromium.launch({ executablePath: chrome });
  const errors = [];
  for (const [kind, file, width, height, scale] of [
    ['banner', 'banner.png', 1280, 400, 2],
    ['social', 'social-preview.png', 1280, 640, 1],
  ]) {
    const page = await browser.newPage({ viewport: { width, height }, deviceScaleFactor: scale });
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(`file://${path.join(__dirname, 'art.html')}?pkg=${pkg}&kind=${kind}`);
    // The banner keeps transparent rounded corners; GitHub needs an opaque social preview.
    await page.screenshot({ path: path.join(__dirname, file), omitBackground: kind === 'banner' });
    await page.close();
  }
  await browser.close();
  if (errors.length) {
    console.error(errors.join('\n'));
    process.exit(1);
  }
})();
