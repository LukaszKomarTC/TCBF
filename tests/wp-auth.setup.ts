import { test as setup, expect } from '@playwright/test';
import path from 'path';

const authFile = path.join(__dirname, '.auth/wp-session.json');

setup('authenticate with WordPress', async ({ page }) => {
  const baseURL = process.env.WP_BASE_URL || 'https://staging.lukaszkomar.com';
  const user = process.env.WP_ADMIN_USER;
  const pass = process.env.WP_ADMIN_PASS;

  if (!user || !pass) {
    throw new Error(
      'WP_ADMIN_USER and WP_ADMIN_PASS must be set in .env.playwright'
    );
  }

  // Navigate to wp-login
  await page.goto(`${baseURL}/wp-login.php`);

  // Fill login form
  await page.locator('#user_login').fill(user);
  await page.locator('#user_pass').fill(pass);
  await page.locator('#wp-submit').click();

  // Wait for dashboard redirect
  await page.waitForURL(/wp-admin/, { timeout: 15_000 });
  await expect(page.locator('#wpadminbar')).toBeVisible();

  // Save authenticated state
  await page.context().storageState({ path: authFile });
});
