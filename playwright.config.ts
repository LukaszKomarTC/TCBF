import { defineConfig, devices } from '@playwright/test';
import dotenv from 'dotenv';
import path from 'path';

// Load environment variables from .env.playwright
dotenv.config({ path: path.resolve(__dirname, '.env.playwright') });

export default defineConfig({
  testDir: './tests',
  fullyParallel: false, // Sequential for WordPress to avoid session conflicts
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: [
    ['html', { open: 'never' }],
    ['list'],
  ],
  timeout: 60_000,

  use: {
    baseURL: process.env.WP_BASE_URL || 'https://staging.lukaszkomar.com',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    // Accept self-signed certs on staging
    ignoreHTTPSErrors: true,
  },

  projects: [
    // Auth setup - runs first, saves login state
    {
      name: 'wp-auth',
      testMatch: /wp-auth\.setup\.ts/,
    },

    // E2E tests - depend on auth
    {
      name: 'e2e',
      testDir: './tests/e2e',
      dependencies: ['wp-auth'],
      use: {
        ...devices['Desktop Chrome'],
        storageState: './tests/.auth/wp-session.json',
      },
    },

    // SEO audit tests - no auth needed (public pages)
    {
      name: 'seo',
      testDir: './tests/seo',
      use: {
        ...devices['Desktop Chrome'],
      },
    },

    // Mobile viewport for responsive SEO checks
    {
      name: 'seo-mobile',
      testDir: './tests/seo',
      use: {
        ...devices['Pixel 5'],
      },
    },
  ],
});
