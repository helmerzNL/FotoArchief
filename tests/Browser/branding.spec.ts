import { expect, test, type Page } from '@playwright/test';
import { readFileSync, realpathSync } from 'node:fs';
import { dirname, join, sep } from 'node:path';
import { tmpdir } from 'node:os';

const manifest = process.env.FOTOARCHIEF_BROWSER_MANIFEST;
if (!manifest) throw new Error('Run through the disposable PHP browser fixture.');
const root = dirname(realpathSync(manifest));
if (!root.startsWith(realpathSync(tmpdir()) + sep + 'fotoarchief-browser-')) {
  throw new Error('Browser fixture must live in the system temporary directory.');
}
readFileSync(join(root, '.disposable-browser-fixture'));
const fixture: { url: string } = JSON.parse(readFileSync(manifest, 'utf8'));
if (new URL(fixture.url).hostname !== '127.0.0.1') throw new Error('Only disposable loopback HTTP is allowed.');

async function noOverflow(page: Page) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
}

test('Vistora theme follows the system, persists explicit preference and respects keyboard/reduced motion', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'dark', reducedMotion: 'reduce' });
  await page.goto(fixture.url);
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Historische beelden. Dichtbij gebracht.');
  await expect(page.locator('meta[name="theme-color"]')).toHaveAttribute('content', '#171c19');
  await page.keyboard.press('Tab');
  await expect(page.getByRole('link', { name: 'Ga naar de inhoud' })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page.locator('#main')).toBeFocused();
  await page.getByLabel('Weergave', { exact: true }).selectOption('light');
  await page.reload();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
  await expect(page.locator('meta[name="theme-color"]')).toHaveAttribute('content', '#f7f3ec');
  await page.getByLabel('Weergave', { exact: true }).selectOption('system');
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  await page.emulateMedia({ colorScheme: 'light' });
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
  const secondTab = await page.context().newPage();
  await secondTab.goto(fixture.url);
  await secondTab.getByLabel('Weergave', { exact: true }).selectOption('dark');
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  await expect(page.getByLabel('Weergave', { exact: true })).toHaveValue('dark');
  await secondTab.close();
  expect(await page.locator('button').first().evaluate(element => getComputedStyle(element).transitionDuration)).toBe('0s, 0s');
  await page.evaluate(() => document.fonts.ready);
  expect(await page.evaluate(() => document.fonts.check('600 32px "Source Serif 4"'))).toBe(true);
  expect(await page.evaluate(() => document.fonts.check('400 16px "Source Sans 3"'))).toBe(true);
  await expect(page.getByLabel('Zoekresultaten')).toBeVisible();
  await page.getByText('Zoeken op betekenis met AI', { exact: true }).click();
  await expect(page.locator('#semantic_consent')).not.toBeChecked();
});

test('storage denial is explained while theme controls remain usable', async ({ page }) => {
  await page.addInitScript(() => {
    Object.defineProperty(window, 'localStorage', { get() { throw new DOMException('blocked', 'SecurityError'); } });
  });
  await page.goto(fixture.url);
  await expect(page.locator('#theme-status')).toContainText('kan in deze browser niet worden bewaard');
  await page.getByLabel('Weergave', { exact: true }).selectOption('dark');
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
});

test('public, login and staff layouts reflow at 320px and desktop in both themes', async ({ page }) => {
  for (const width of [320, 1280]) {
    await page.setViewportSize({ width, height: 900 });
    for (const theme of ['light', 'dark']) {
      await page.goto(fixture.url);
      await page.getByLabel('Weergave', { exact: true }).selectOption(theme);
      await noOverflow(page);
      await page.goto(fixture.url + '/collecties');
      await noOverflow(page);
      await page.goto(fixture.url + '/login');
      await noOverflow(page);
    }
  }
  await page.getByRole('textbox', { name: 'E-mailadres', exact: true }).first().fill('release@example.test');
  await page.getByRole('textbox', { name: 'Wachtwoord', exact: true }).fill('disposable-smoke-password');
  await page.getByRole('button', { name: 'Inloggen', exact: true }).click();
  await expect(page).toHaveURL(fixture.url + '/admin');
  for (const width of [320, 1280]) {
    await page.setViewportSize({ width, height: 900 });
    for (const theme of ['light', 'dark']) {
      await page.getByLabel('Weergave', { exact: true }).selectOption(theme);
      await noOverflow(page);
      await expect(page.getByRole('navigation', { name: 'Beheer', exact: true })).toBeVisible();
    }
  }
});

test('branding stays available without JavaScript and install metadata fetches local assets', async ({ browser, request }) => {
  const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 320, height: 900 } });
  try {
    const page = await context.newPage();
    await page.goto(fixture.url);
    await expect(page.getByRole('link', { name: 'Vistora', exact: true })).toBeVisible();
    await expect(page.locator('#theme-control')).toBeHidden();
    await expect(page.locator('#q')).toBeVisible();
    await noOverflow(page);
  } finally {
    await context.close();
  }
  const response = await request.get(fixture.url + '/manifest.webmanifest');
  expect(response.ok()).toBe(true);
  const data = await response.json();
  expect(data.name).toBe('Vistora');
  for (const icon of data.icons) {
    const asset = await request.get(fixture.url + icon.src);
    expect(asset.ok()).toBe(true);
    expect((await asset.body()).length).toBeGreaterThan(100);
  }
});

test('light and dark semantic text, button and control colors meet AA contrast thresholds', async ({ page }) => {
  await page.goto(fixture.url);
  for (const theme of ['light', 'dark']) {
    await page.getByLabel('Weergave', { exact: true }).selectOption(theme);
    const colors = await page.evaluate(() => {
      const style = getComputedStyle(document.documentElement);
      return Object.fromEntries(['primary', 'on-primary', 'background', 'surface', 'text-primary', 'text-secondary',
        'text-muted', 'control-border', 'focus', 'success', 'warning', 'error', 'info'].map(key =>
        [key, style.getPropertyValue('--color-' + key).trim()]));
    });
    const luminance = (hex: string) => {
      const rgb = hex.slice(1).match(/../g)!.map(part => parseInt(part, 16) / 255)
        .map(value => value <= .04045 ? value / 12.92 : ((value + .055) / 1.055) ** 2.4);
      return .2126 * rgb[0] + .7152 * rgb[1] + .0722 * rgb[2];
    };
    const contrast = (a: string, b: string) => {
      const values = [luminance(colors[a]), luminance(colors[b])].sort((x, y) => y - x);
      return (values[0] + .05) / (values[1] + .05);
    };
    for (const text of ['text-primary', 'text-secondary', 'text-muted', 'success', 'warning', 'error', 'info']) {
      expect(contrast(text, 'surface'), theme + ' ' + text).toBeGreaterThanOrEqual(4.5);
      expect(contrast(text, 'background'), theme + ' ' + text).toBeGreaterThanOrEqual(4.5);
    }
    expect(contrast('on-primary', 'primary')).toBeGreaterThanOrEqual(4.5);
    expect(contrast('control-border', 'surface')).toBeGreaterThanOrEqual(3);
    expect(contrast('focus', 'background')).toBeGreaterThanOrEqual(3);
  }
});
