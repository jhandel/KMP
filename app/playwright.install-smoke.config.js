// @ts-check
const { defineConfig, devices } = require('@playwright/test');

const baseUrl = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8080';
const webServerCommand = process.env.PLAYWRIGHT_WEB_SERVER_COMMAND || '';

module.exports = defineConfig({
  testDir: './tests/ui',
  testMatch: ['install-smoke.spec.js'],
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: 1,
  reporter: [
    ['html', {
      outputFolder: 'tests/ui-reports/html-install-smoke', host: '0.0.0.0',
      port: 9325, open: 'never'
    }],
    ['json', { outputFile: 'tests/ui-reports/install-smoke-results.json' }],
    ['junit', { outputFile: 'tests/ui-reports/install-smoke-results.xml' }]
  ],
  use: {
    baseURL: baseUrl,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    actionTimeout: 30000,
    navigationTimeout: 30000,
    ignoreHTTPSErrors: true,
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  ...(webServerCommand ? {
    webServer: {
      command: webServerCommand,
      url: baseUrl,
      reuseExistingServer: true,
      ignoreHTTPSErrors: true,
    },
  } : {}),
  globalSetup: require.resolve('./tests/ui/global-setup.js'),
  globalTeardown: require.resolve('./tests/ui/global-teardown.js'),
  timeout: 600000,
  expect: {
    timeout: 10000,
  },
  outputDir: 'tests/ui-results/',
});
