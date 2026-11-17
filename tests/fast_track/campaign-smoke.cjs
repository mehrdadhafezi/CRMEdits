const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { chromium } = require(process.argv[2]);

// Match the affected admin and portal containers; there is no WordPress or customer data.
const markup = `
<div class="wrap sn-campaign-admin" dir="rtl">
  <p class="description">تنظیمات کمپین آزمایشی</p>
  <section class="sn-campaign-section"><button>ذخیره</button></section>
</div>
<div class="sn-campaign-portal" dir="rtl">
  <header class="sn-campaign-portal-header"><div>
    <span>گزارش کمپین آزمایشی</span><h1>کمپین نمونه</h1><p>متن آزمایشی</p>
  </div></header>
  <section class="sn-campaign-section"><button>نمایش</button></section>
</div>`;

async function smoke() {
  const style = readFileSync('assets/css/campaign-analytics.css', 'utf8');
  const browser = await chromium.launch({ executablePath: process.env.SN_SMOKE_BROWSER || undefined });
  try {
    const page = await browser.newPage({ viewport: { width: 360, height: 800 } });
    await page.route('**/*', route => route.abort());
    for (const width of [360, 1280]) {
      await page.setViewportSize({ width, height: 800 });
      await page.setContent(markup);
      await page.addStyleTag({ content: style });
      assert.equal(await page.locator('.sn-campaign-portal').evaluate(
        element => getComputedStyle(element).direction), 'rtl');
      assert.equal(await page.locator('.sn-campaign-section').first().evaluate(
        element => getComputedStyle(element).borderTopWidth), '1px');
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
        `campaign page overflows the ${width}px viewport`);
      for (const element of await page.locator('.description, .sn-campaign-portal-header, button').all()) {
        assert.ok(await element.isVisible(), 'affected label, header or control is hidden');
        const { x, width: elementWidth } = await element.boundingBox();
        assert.ok(x >= 0 && x + elementWidth <= width, 'affected element leaves the viewport');
      }
      console.log(`PASS campaign admin and portal layout at ${width}px RTL`);
    }
  } finally {
    await browser.close();
  }
}

smoke().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
