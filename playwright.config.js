// @ts-check
// End-to-end tests for the browser code (public/assets/js) against PHP's built-in server.
// Run: npm ci && npx playwright install chromium && npm run e2e
const { defineConfig, devices } = require('@playwright/test');

const PORT = 8765;

module.exports = defineConfig({
  testDir: './e2e',
  // One shared throwaway database: run tests one after another.
  workers: 1,
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: 0,
  reporter: process.env.CI ? [['list'], ['github']] : 'list',
  use: {
    baseURL: `http://127.0.0.1:${PORT}`,
    trace: 'off',
    locale: 'de-DE',
    timezoneId: 'Europe/Berlin',
    // Brandenburger Tor; the check-in page asks for the position on load.
    geolocation: { latitude: 52.5163, longitude: 13.3777, accuracy: 20 },
    permissions: ['geolocation'],
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    command: `php e2e/bootstrap.php ${PORT} && php -S 127.0.0.1:${PORT} -t public public/index.php`,
    url: `http://127.0.0.1:${PORT}/login`,
    reuseExistingServer: false,
    env: { APP_CONFIG: `${__dirname}/e2e/.run/config.php` },
    stdout: 'ignore',
    stderr: 'ignore', // php -S logs every request there
  },
});
