const { chromium } = require('../tests/Browser/node_modules/playwright');
const { readFileSync, writeFileSync } = require('node:fs');
const { resolve, join } = require('node:path');

const directory = resolve(__dirname, '../public/brand');
const svg = readFileSync(join(directory, 'favicon.svg'), 'utf8');
const monochrome = readFileSync(join(directory, 'monochrome.svg'), 'utf8');
const tokens = readFileSync(join(directory, 'tokens.css'), 'utf8').replace(
    /url\("fonts\/([^"]+)"\)/g,
    (_, file) => `url("data:font/woff2;base64,${readFileSync(join(directory, 'fonts', file)).toString('base64')}")`,
);

async function generate() {
    const browser = await chromium.launch();
    try {
        const page = await browser.newPage({ deviceScaleFactor: 1 });
        const pngs = [];
        for (const size of [16, 32, 48, 180, 192, 512]) {
            await page.setViewportSize({ width: size, height: size });
            await page.setContent(`<style>html,body{margin:0}svg{display:block;width:100%;height:100%}</style>${svg}`);
            const file = size === 180 ? 'apple-touch-icon.png' : `icon-${size}.png`;
            const png = await page.screenshot({ path: join(directory, file), animations: 'disabled' });
            if (size <= 48) pngs.push({ size, png });
        }
        // The maskable mark stays inside the central 80%-diameter safe circle.
        await page.setContent(`<style>html,body{margin:0}svg{display:block;width:100%;height:100%}</style>${svg.replace('<path ', '<path transform="translate(3.2 3.2) scale(.9)" ')}`);
        await page.screenshot({ path: join(directory, 'maskable-512.png') });
        const header = Buffer.alloc(6 + pngs.length * 16);
        header.writeUInt16LE(1, 2);
        header.writeUInt16LE(pngs.length, 4);
        let offset = header.length;
        pngs.forEach(({ size, png }, index) => {
            const entry = 6 + index * 16;
            header[entry] = header[entry + 1] = size;
            header.writeUInt16LE(1, entry + 4);
            header.writeUInt16LE(32, entry + 6);
            header.writeUInt32LE(png.length, entry + 8);
            header.writeUInt32LE(offset, entry + 12);
            offset += png.length;
        });
        writeFileSync(resolve(directory, '../favicon.ico'), Buffer.concat([header, ...pngs.map(({ png }) => png)]));
        for (const [name, width, height] of [
            ['social-preview', 1200, 630],
            ['social-square', 1080, 1080],
            ['social-portrait', 1080, 1350],
        ]) {
            await page.setViewportSize({ width, height });
            await page.setContent(`<style>${tokens}
                html,body{margin:0}body{height:100vh;box-sizing:border-box;padding:72px;background:var(--color-background);color:var(--color-text-primary);display:flex;flex-direction:column;justify-content:center;font-family:var(--font-body)}
                .brand{display:flex;align-items:center;gap:16px;font-size:44px;font-weight:600}
                svg{width:80px;height:80px}path{fill:var(--color-primary)}
                h1{font:600 76px/1.14 var(--font-heading);max-width:920px;margin:40px 0 24px}
                p{font-size:28px;color:var(--color-text-secondary);margin:0}
            </style><div class="brand">${monochrome}<span>Vistora</span></div>
            <h1>Historische beelden.<br>Dichtbij gebracht.</h1><p>Ontdek verhalen in de collectie.</p>`);
            await page.evaluate(() => document.fonts.ready);
            await page.screenshot({ path: join(directory, `${name}.png`), animations: 'disabled' });
        }
        console.log('Generated Vistora favicon, install icons and three brand-only social assets.');
    } finally {
        await browser.close();
    }
}

generate().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
