// @ts-check
// End-to-end tests for the browser code. OSM services are never contacted:
// place lookups are answered by route handlers, map tiles by a blank image.
const { test, expect } = require('@playwright/test');

const PASSWORD = 'e2e-password-123';
const XSS_NAME = '<img src=x onerror="window.__xss=1">Café <b>Test</b>';
const PNG_1PX = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', 'base64');

function place(overrides = {}) {
  return {
    name: 'Café Müller', lat: 52.5164, lon: 13.3779, osm_type: 'node', osm_id: 1001,
    category: 'Café', address: 'Pariser Platz 1', distance: 18, visits: 0, last_visit: null,
    ...overrides,
  };
}

/** Records CSP violations and page errors; every test asserts there are none. */
test.beforeEach(async ({ page }) => {
  await page.addInitScript(() => {
    // @ts-ignore
    window.__csp = [];
    document.addEventListener('securitypolicyviolation', (e) => {
      // @ts-ignore
      window.__csp.push(e.violatedDirective + ' ' + e.blockedURI);
    });
  });
  page.on('pageerror', (err) => { throw err; });
  await page.route(/tile\.openstreetmap\.org/, (route) => route.fulfill({ contentType: 'image/png', body: PNG_1PX }));
  // Safety net: no test may reach Overpass/Photon through the app. Tests register
  // their own handlers on top (later routes win).
  await page.route(/\/places\/(nearby|search)\?/, (route) => route.fulfill({ json: { source: 'overpass', notice: null, places: [] } }));
});

test.afterEach(async ({ page }) => {
  if (page.isClosed() || page.url() === 'about:blank') return;
  // @ts-ignore
  const violations = await page.evaluate(() => window.__csp || []).catch(() => []);
  expect(violations, 'CSP violations').toEqual([]);
});

/** Logs in and lands on the history, not on the check-in page (which starts a lookup). */
async function login(page) {
  await page.goto('/checkins');
  if (!page.url().includes('/login')) return; // already logged in
  await page.fill('#password', PASSWORD);
  await page.click('button[type=submit]');
  await expect(page).toHaveURL(/\/checkins$/);
}

/** Answers /places/nearby with the given places and records the requested radius. */
async function fakeNearby(page, places) {
  const radii = [];
  await page.route(/\/places\/nearby\?/, (route) => {
    radii.push(Number(new URL(route.request().url()).searchParams.get('radius')));
    route.fulfill({ json: { source: 'overpass', notice: null, places } });
  });
  return radii;
}

async function checkIn(page, name) {
  await fakeNearby(page, [place({ name, osm_id: Math.floor(Math.random() * 1e9) + 1 })]);
  await page.goto('/');
  await page.getByRole('button', { name }).click();
  await expect(page.locator('#confirm')).toBeVisible();
  await page.locator('#checkin-form button[type=submit]').click();
  await expect(page).toHaveURL(/\/checkin\/\d+/);
  return page.url();
}

test('login rejects a wrong password and accepts the right one', async ({ page }) => {
  await page.goto('/checkins');
  await expect(page).toHaveURL(/\/login$/);
  await page.fill('#password', 'wrong password');
  await page.click('button[type=submit]');
  await expect(page.locator('.alert-danger')).toContainText('Passwort ist falsch');
  await login(page);
});

test('check-in: location, nearby list, confirm and save', async ({ page }) => {
  await login(page);
  const radii = await fakeNearby(page, [
    place(),
    place({ name: 'Späti Eck', osm_id: 1002, category: 'Kiosk', address: null, distance: 1250, visits: 3, last_visit: '01.11.2026' }),
  ]);
  await page.goto('/');

  await expect(page.locator('#geo-status')).toHaveText('Standort ±20 m');
  const items = page.locator('#places .place-item');
  await expect(items).toHaveCount(2);
  await expect(items.nth(0)).toContainText('Café · Pariser Platz 1');
  await expect(items.nth(0).locator('.place-distance')).toHaveText('18 m');
  await expect(items.nth(1).locator('.place-distance')).toHaveText('1,3 km');
  await expect(items.nth(1)).toContainText('zuletzt 01.11.2026 · 3×');
  expect(radii).toEqual([150]);

  await items.nth(0).click();
  await expect(page.locator('#confirm-title')).toHaveText('Café Müller');
  await page.fill('#note', 'Erster Kaffee');
  await page.locator('#checkin-form button[type=submit]').click();

  await expect(page).toHaveURL(/\/checkin\/\d+-cafe-mueller$/);
  await expect(page.locator('h1')).toHaveText('Café Müller');
  await expect(page.locator('.note')).toHaveText('Erster Kaffee');
});

test('widening the radius doubles it up to the maximum', async ({ page }) => {
  await login(page);
  const radii = await fakeNearby(page, []);
  await page.goto('/');
  await expect(page.locator('#places-message')).toContainText('Keine Orte im Umkreis von 150 m');

  const widen = page.locator('#widen');
  for (const r of [300, 600, 1000]) {
    await widen.click();
    await expect(page.locator('#places-message')).toContainText(`Umkreis von ${r} m`);
  }
  await expect(widen).toBeHidden();
  expect(radii).toEqual([150, 300, 600, 1000]);
});

test('search sends the query and the position', async ({ page }) => {
  await login(page);
  await fakeNearby(page, []);
  let searched = null;
  await page.route(/\/places\/search\?/, (route) => {
    searched = new URL(route.request().url()).searchParams;
    route.fulfill({ json: { places: [place({ name: 'Reichstag', category: 'Sehenswürdigkeit', distance: 600 })] } });
  });
  await page.goto('/');
  await expect(page.locator('#geo-status')).toHaveText('Standort ±20 m');

  await page.fill('#search-q', 'Reichstag');
  await page.press('#search-q', 'Enter');
  await expect(page.locator('#places .place-item')).toHaveText(/Reichstag/);
  expect(searched?.get('q')).toBe('Reichstag');
  expect(searched?.get('lat')).toBe('52.5163');
});

test('errors from the place services are shown', async ({ page }) => {
  await login(page);
  await page.route(/\/places\/nearby\?/, (route) => route.fulfill({
    status: 502, json: { error: 'Gerade ist kein Ortsdienst erreichbar.', detail: 'Overpass: HTTP 504' },
  }));
  await page.goto('/');
  await expect(page.locator('#places-message')).toHaveText('Gerade ist kein Ortsdienst erreichbar. (Overpass: HTTP 504)');
});

test('manual place uses the current position and no OSM reference', async ({ page }) => {
  await login(page);
  await fakeNearby(page, []);
  await page.goto('/');
  await expect(page.locator('#geo-status')).toHaveText('Standort ±20 m');

  await page.getByRole('button', { name: 'Eigener Ort' }).click();
  await page.fill('#manual-name', 'Bei Oma im Garten');
  await page.click('#manual-use');
  await expect(page.locator('#confirm-meta')).toHaveText('Eigener Ort · aktueller Standort');
  await expect(page.locator('#checkin-form input[name=osm_type]')).toHaveValue('');
  await expect(page.locator('#checkin-form input[name=lat]')).toHaveValue('52.5163');
  await page.locator('#checkin-form button[type=submit]').click();
  await expect(page.locator('h1')).toHaveText('Bei Oma im Garten');
});

test.describe('without location permission', () => {
  test.use({ permissions: [] });

  test('denied geolocation points to the search', async ({ page }) => {
    await login(page);
    await page.goto('/');
    await expect(page.locator('#geo-status')).toContainText('Standortzugriff verweigert');
  });
});

test('place names stay plain text in the map tooltip', async ({ page }) => {
  await login(page);
  await checkIn(page, XSS_NAME);

  await expect(page.locator('h1')).toHaveText(XSS_NAME);
  await page.locator('[data-map-point] path.leaflet-interactive').hover();
  const tooltip = page.locator('.leaflet-tooltip');
  await expect(tooltip).toHaveText(XSS_NAME);
  await expect(tooltip.locator('img, b')).toHaveCount(0);
  // @ts-ignore
  expect(await page.evaluate(() => window.__xss)).toBeUndefined();
});

test('map of all check-ins shows popups with links', async ({ page }) => {
  await login(page);
  const detail = new URL(await checkIn(page, 'Kartenpunkt')).pathname;
  const data = page.waitForResponse('**/map/data');
  await page.goto('/map');
  const rows = await (await data).json();
  expect(rows.map((r) => r[4])).toContain(detail);
  await expect(page.locator('.map-loading')).toHaveCount(0);

  // Markers are drawn on a canvas (preferCanvas); all test check-ins sit near the
  // centre of the fitted bounds, so a click there hits one of them.
  const box = await page.locator('#map-all').boundingBox();
  if (!box) throw new Error('map not rendered');
  await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
  await expect(page.locator('.leaflet-popup-content a')).toHaveAttribute('href', /^\/checkin\/\d+/);
});

test('delete asks for confirmation', async ({ page }) => {
  await login(page);
  const url = await checkIn(page, 'Zum Löschen');

  page.once('dialog', (d) => d.dismiss());
  await page.getByRole('button', { name: 'Löschen' }).click();
  await expect(page).toHaveURL(url);

  page.once('dialog', (d) => { expect(d.message()).toContain('Zum Löschen'); d.accept(); });
  await page.getByRole('button', { name: 'Löschen' }).click();
  await expect(page).toHaveURL(/\/checkins$/);
  await expect(page.locator('.alert')).toContainText('gelöscht');
});

test('history, settings and login load without CSP violations', async ({ page }) => {
  await login(page);
  for (const path of ['/checkins', '/settings', '/map']) {
    await page.goto(path);
    await expect(page.locator('body')).toBeVisible();
    // @ts-ignore
    expect(await page.evaluate(() => window.__csp), path).toEqual([]);
  }
  // Fonts and icons are self-hosted: the icon font must actually load.
  const fontOk = await page.evaluate(async () => {
    await document.fonts.ready;
    return document.fonts.check('16px bootstrap-icons');
  });
  expect(fontOk).toBe(true);
});

test('service worker serves the offline page without network', async ({ page, context }) => {
  await login(page);
  await page.goto('/checkins');
  await page.evaluate(() => navigator.serviceWorker.ready);
  // Reload once so the page is controlled by the worker.
  await page.reload();
  await expect.poll(() => page.evaluate(() => !!navigator.serviceWorker.controller)).toBe(true);

  await context.setOffline(true);
  await page.goto('/checkins').catch(() => {});
  await expect(page).toHaveTitle(/Offline/);
  await context.setOffline(false);
});

test('new API token is selected on focus for copying', async ({ page }) => {
  await login(page);
  await page.goto('/settings');
  await page.fill('form[action="/settings/tokens"] input[name=name]', 'E2E');
  await page.locator('form[action="/settings/tokens"] button').click();
  const field = page.locator('[data-select-on-focus]');
  await expect(field).toHaveValue(/^svm_/);
  await field.focus();
  const selected = await page.evaluate(() => String(window.getSelection()) || (() => {
    const el = /** @type {HTMLInputElement} */ (document.querySelector('[data-select-on-focus]'));
    return el.value.substring(el.selectionStart ?? 0, el.selectionEnd ?? 0);
  })());
  expect(selected).toMatch(/^svm_/);
});
