const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

async function main() {
    const html = fs.readFileSync(process.argv[2], 'utf8');
    const css = fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'app.css'), 'utf8');
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
        await page.setContent(html);
        await page.addStyleTag({ content: css });
        // Isolate the catalogue surface from the separately owned global navigation.
        await page.locator('header, footer').evaluateAll(nodes => nodes.forEach(node => node.remove()));
        const geometry = () => page.evaluate(() => ({
            viewport: window.innerWidth,
            page: document.documentElement.scrollWidth,
            tables: [...document.querySelectorAll('.catalogue-table-scroll')].map(node => ({
                width: node.clientWidth,
                scroll: node.scrollWidth
            }))
        }));
        const mobile = await geometry();
        assert.equal(mobile.page, 390, JSON.stringify(mobile));
        assert(mobile.tables.some(table => table.scroll > table.width), 'Populated table must scroll internally');
        const region = page.locator('.catalogue-table-scroll').nth(mobile.tables.findIndex(table => table.scroll > table.width));
        await region.scrollIntoViewIfNeeded();
        await region.focus();
        await page.keyboard.down('ArrowRight');
        await page.waitForTimeout(500);
        await page.keyboard.up('ArrowRight');
        assert(await region.evaluate(node => node.scrollLeft > 0), 'Keyboard must scroll the focused region');
        await page.setViewportSize({ width: 1280, height: 900 });
        const desktop = await geometry();
        assert.equal(desktop.page, 1280, JSON.stringify(desktop));
        await page.setViewportSize({ width: 390, height: 844 });
        await page.locator('.catalogue-table-scroll').evaluateAll(nodes => nodes.forEach(node => node.replaceWith(...node.childNodes)));
        const unwrapped = await geometry();
        assert(unwrapped.page > 390, 'Fixture must reproduce overflow without the fix');
        console.log(JSON.stringify({ mobile, desktop, withoutFix: unwrapped.page, keyboardScroll: true }));
    } finally {
        await browser.close();
    }
}

main().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
