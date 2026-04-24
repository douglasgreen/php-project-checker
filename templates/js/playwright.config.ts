import { defineConfig, devices } from '@playwright/test';

const isCI = !!process.env.CI;

export default defineConfig({
    testDir: './tests/e2e',
    timeout: 30_000,
    expect: { timeout: 10_000 },

    fullyParallel: true,
    forbidOnly: isCI,
    retries: isCI ? 2 : 0,
    workers: isCI ? 2 : undefined,

    reporter: [
        ['html', { open: 'never' }],
        ['list'],
        ...(isCI ? [['github' as const]] : []),
    ],

    use: {
        baseURL: process.env.BASE_URL ?? 'http://localhost:3000',
        headless: isCI,
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        actionTimeout: 10_000,
        navigationTimeout: 20_000,
    },

    projects: [
        { name: 'setup', testMatch: /.*\.setup\.ts/ },
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
            dependencies: ['setup'],
        },
        {
            name: 'firefox',
            use: { ...devices['Desktop Firefox'] },
            dependencies: ['setup'],
        },
        {
            name: 'webkit',
            use: { ...devices['Desktop Safari'] },
            dependencies: ['setup'],
        },
        {
            name: 'mobile-chrome',
            use: { ...devices['Pixel 7'] },
            dependencies: ['setup'],
        },
        {
            name: 'mobile-safari',
            use: { ...devices['iPhone 14'] },
            dependencies: ['setup'],
        },
    ],

    webServer: isCI
        ? undefined
        : {
              command: 'npm run dev',
              url: 'http://localhost:3000',
              reuseExistingServer: true,
              timeout: 120_000,
          },
});
