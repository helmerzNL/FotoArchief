import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { readFileSync, realpathSync } from 'node:fs';
import { dirname, join, sep } from 'node:path';
import { tmpdir } from 'node:os';

type AssetName = 'review' | 'stale' | 'revoke' | 'embargo' | 'trash' | 'volunteer';
interface Fixture {
  url: string;
  assets: Record<AssetName, { id: string; file_id: string; title: string }>;
}
const manifest = process.env.FOTOARCHIEF_BROWSER_MANIFEST;
if (!manifest) throw new Error('Run through the disposable PHP browser fixture.');
const root = dirname(realpathSync(manifest));
if (!root.startsWith(realpathSync(tmpdir()) + sep + 'fotoarchief-browser-')) {
  throw new Error('Browser fixture must live in the system temporary directory.');
}
readFileSync(join(root, '.disposable-browser-fixture'));
const fixture: Fixture = JSON.parse(readFileSync(manifest, 'utf8'));
if (new URL(fixture.url).hostname !== '127.0.0.1') throw new Error('Only disposable loopback HTTP is allowed.');
const url = (path: string) => fixture.url + path;
const assetPath = (name: AssetName) => `/admin/assets/${fixture.assets[name].id}`;

async function login(page: Page, role = 'administrator') {
  await page.goto(url('/login'));
  await page.getByRole('textbox', { name: 'E-mailadres', exact: true }).first().fill(
    role === 'administrator' ? 'release@example.test' : `${role}@browser.example.test`,
  );
  await page.getByRole('textbox', { name: 'Wachtwoord', exact: true }).fill('disposable-smoke-password');
  await page.getByRole('button', { name: 'Inloggen', exact: true }).click();
  await expect(page).toHaveURL(url(role === 'administrator' ? '/admin' : '/admin/assets'));
}

async function privatePreview(page: Page) {
  const image = page.getByRole('img').first();
  await expect(image).toBeVisible();
  await expect.poll(() => image.evaluate((element: HTMLImageElement) =>
    element.complete && element.naturalWidth > 0)).toBe(true);
}

async function visibility(context: BrowserContext, name: 'revoke' | 'trash' | 'embargo', status: number) {
  for (const path of [
    `/foto/browser-${name}`,
    `/foto/browser-${name}/media/preview1200`,
    `/iiif/browser-${name}/manifest.json`,
  ]) {
    const response = await context.request.get(url(path));
    expect(response.status(), path).toBe(status);
    if (status === 200 && path.includes('/media/')) {
      expect(response.headers()['content-type']).toContain('image/jpeg');
      expect((await response.body()).length).toBeGreaterThan(100);
    }
  }
}

async function roleContext(browser: Browser, role: string) {
  const context = await browser.newContext();
  const page = await context.newPage();
  await login(page, role);
  return { context, page };
}

test('11 - review preserves metadata revisions, rejects changed source and records decisions', async ({ page }) => {
  await login(page);
  await page.goto(url(assetPath('review')));
  await expect(page.getByText('Bronrevisie 1 · huidige revisie 1', { exact: true })).toBeVisible();
  await page.getByRole('textbox', { name: 'Titel', exact: true }).fill('Browser review aangepast');
  await page.getByRole('button', { name: 'Opslaan', exact: true }).click();
  await expect(page.getByText('Bronrevisie 1 · huidige revisie 2', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Voorstel accepteren', exact: true }).first().click();
  await expect(page.getByRole('textbox', { name: 'Beschrijving', exact: true }))
    .toHaveValue('Gecontroleerd marktplein uit de browserproef.');
  await page.getByRole('button', { name: 'Voorstel accepteren', exact: true }).first().click();
  await expect(page.getByRole('textbox', { name: 'Tags (komma-gescheiden, maximaal 20)', exact: true }))
    .toHaveValue('browser-marktplein');
  await page.getByRole('textbox', { name: 'Afwijsreden (optioneel)' }).fill('Onjuiste interpretatie in browserproef');
  await page.getByRole('button', { name: 'Voorstel afwijzen', exact: true }).click();
  await expect(page.getByText('Beoordeling: Afgewezen')).toBeVisible();
  await expect(page.getByText('Onjuiste interpretatie in browserproef', { exact: false })).toBeVisible();
  await expect(page.getByText(/ai\.suggestion\.accepted/).first()).toBeVisible();
  await page.reload();
  await expect(page.getByRole('button', { name: 'Voorstel accepteren', exact: true })).toHaveCount(0);
  await page.goto(url(assetPath('stale')));
  await page.getByRole('button', { name: 'Voorstel accepteren', exact: true }).first().click();
  await expect(page.getByText('Het primaire fotobestand is vervangen, gewijzigd of niet meer beschikbaar.', { exact: false })).toBeVisible();
  await expect(page.getByRole('textbox', { name: 'Beschrijving', exact: true })).toHaveValue('');
});

test('12 - browser upload runs real ingest, renders private bytes and exposes duplicate failure', async ({ page, browser }) => {
  await login(page);
  async function upload() {
    await page.goto(url('/admin/assets'));
    await page.getByLabel('Kies bestanden of sleep ze hierheen').setInputFiles(join(root, 'browser-upload.png'));
    await page.getByRole('button', { name: 'Uploaden', exact: true }).click();
    await expect(page.getByText('Ontvangen, verwerking ingepland.', { exact: false })).toBeVisible();
    await page.getByRole('link', { name: 'Bekijk verwerking', exact: true }).click();
  }
  await upload();
  await expect.poll(async () => {
    await page.reload();
    return await page.locator('main').innerText();
  }, { timeout: 60_000, intervals: [500, 1000] }).toContain('browser-upload.png: completed');
  await expect(page.getByText('NIET GESCAND', { exact: true })).toBeVisible();
  await privatePreview(page);
  const privateUrl = await page.getByRole('img').first().getAttribute('src');
  expect(privateUrl).toBeTruthy();
  const anonymous = await browser.newContext();
  try {
    const response = await anonymous.request.get(new URL(privateUrl!, fixture.url).href, { maxRedirects: 0 });
    expect([302, 401, 403, 404]).toContain(response.status());
    expect(response.headers()['content-type'] ?? '').not.toContain('image/');
  } finally { await anonymous.close(); }
  await page.getByRole('link', { name: 'Verwerkingsdetails en logboek van browser-upload.png', exact: true }).click();
  await expect(page.getByText('Geen actieve foutmeldingen geregistreerd voor deze taak.')).toBeVisible();
  await upload();
  await expect.poll(async () => {
    await page.reload();
    return await page.locator('main').innerText();
  }, { timeout: 60_000, intervals: [500, 1000] }).toContain('browser-upload.png: rejected');
  await expect(page.getByRole('alert')).toHaveText('Dit bestand bestaat al in het archief. Er is geen tweede origineel toegevoegd.');
  await expect(page.getByRole('img')).toHaveCount(0);
});

test('13 - real logins enforce viewer, volunteer ownership and editor/archivist boundaries', async ({ browser }) => {
  for (const role of ['viewer', 'volunteer', 'editor', 'archivist']) {
    const { context, page } = await roleContext(browser, role);
    try {
      if (role === 'viewer') {
        await expect(page.getByRole('button', { name: 'Uploaden', exact: true })).toHaveCount(0);
      }
      const own = await page.goto(url(assetPath('volunteer')));
      expect(own?.status()).toBe(role === 'viewer' ? 403 : 200);
      await expect(page.getByRole('button', { name: 'Opslaan', exact: true })).toHaveCount(role === 'viewer' ? 0 : 1);
      await expect(page.getByRole('button', { name: 'Voorstel accepteren', exact: true })).toHaveCount(role === 'viewer' ? 0 : 3);
      const other = await page.goto(url(assetPath('stale')));
      expect(other?.status()).toBe(['viewer', 'volunteer'].includes(role) ? 403 : 200);
      const publication = await page.goto(url(`/admin/publications/${fixture.assets.revoke.id}`));
      expect(publication?.status()).toBe(['viewer', 'volunteer'].includes(role) ? 403 : 200);
      await expect(page.getByRole('button', { name: 'Direct intrekken', exact: true }))
        .toHaveCount(['viewer', 'volunteer'].includes(role) ? 0 : 1);
    } finally { await context.close(); }
  }
});

test('14 - revocation, embargo and trash close public detail, image and IIIF routes', async ({ page, browser }) => {
  const anonymous = await browser.newContext();
  try {
    await visibility(anonymous, 'revoke', 200);
    await visibility(anonymous, 'trash', 200);
    await visibility(anonymous, 'embargo', 404);
    await login(page);
    await page.goto(url(`/admin/publications/${fixture.assets.revoke.id}`));
    await page.getByRole('textbox', { name: 'Reden', exact: true }).fill('Browser acceptatie intrekken');
    await page.getByRole('button', { name: 'Direct intrekken', exact: true }).click();
    await expect(page.getByText('Publicatie ingetrokken.', { exact: true })).toBeVisible();
    await visibility(anonymous, 'revoke', 404);
    await page.goto(url(assetPath('trash')));
    await page.getByRole('textbox', { name: 'Reden voor verwijdering', exact: true }).fill('Browser acceptatie prullenbak');
    page.once('dialog', dialog => dialog.accept());
    await page.getByRole('button', { name: 'Naar prullenbak verplaatsen', exact: true }).click();
    await expect(page).toHaveURL(url('/admin/operations/trash'));
    await expect(page.getByText('Asset FA-BROWSER-TRASH is verplaatst naar de prullenbak.', { exact: true })).toBeVisible();
    await visibility(anonymous, 'trash', 404);
  } finally { await anonymous.close(); }
});

test('15 - narrow screen supports labelled keyboard editing, focus and real preview', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 800 });
  await login(page);
  await page.goto(url(assetPath('review')));
  const css = await page.request.get(url('/app.css'));
  expect(css.status()).toBe(200);
  expect(css.headers()['content-type']).toContain('text/css');
  await privatePreview(page);
  await page.getByRole('textbox', { name: 'Titel', exact: true }).focus();
  await page.keyboard.press('Tab');
  const description = page.getByRole('textbox', { name: 'Beschrijving', exact: true });
  await expect(description).toBeFocused();
  expect(await description.evaluate(e => getComputedStyle(e).outlineStyle)).not.toBe('none');
  await page.getByRole('textbox', { name: 'Titel', exact: true }).fill('Browser mobiel toetsenbord');
  await page.getByRole('button', { name: 'Opslaan', exact: true }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { name: 'Browser mobiel toetsenbord', exact: true })).toBeVisible();
  await page.reload();
  await expect(page.getByRole('textbox', { name: 'Titel', exact: true })).toHaveValue('Browser mobiel toetsenbord');
  const dimensions = await page.evaluate(() => ({
    viewport: innerWidth, page: document.documentElement.scrollWidth,
  }));
  expect(dimensions.page).toBeLessThanOrEqual(dimensions.viewport);
});
