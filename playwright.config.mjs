import {defineConfig, devices} from '@playwright/test';

const port = Number(process.env.FORM_GUARD_TEST_PORT || 9473);
if (!Number.isInteger(port) || port < 1024 || port > 65535) throw new Error('Invalid test port.');

export default defineConfig({
  testDir: './tests/e2e',
  timeout: 30_000,
  expect: {timeout: 5_000},
  fullyParallel: false,
  workers: 1,
  retries: 0,
  forbidOnly: !!process.env.CI,
  outputDir: 'build/playwright-results',
  reporter: [['list'], ['json', {outputFile: 'build/playwright-results.json'}]],
  use: {baseURL: `http://127.0.0.1:${port}`, trace: 'retain-on-failure', screenshot: 'only-on-failure'},
  projects: [
    {name: 'chromium', use: {...devices['Desktop Chrome']}},
    {name: 'firefox', use: {...devices['Desktop Firefox']}},
    {name: 'webkit', use: {...devices['Desktop Safari']}},
  ],
  webServer: {
    command: 'node tools/browser-server.mjs',
    url: `http://127.0.0.1:${port}`,
    reuseExistingServer: false,
    timeout: 15_000,
    stdout: 'ignore',
    stderr: 'ignore',
    env: {FORM_GUARD_TEST_PORT: String(port)},
  },
});
