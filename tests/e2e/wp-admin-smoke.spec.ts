import { test, expect } from '@playwright/test';

test.describe('WordPress Admin Smoke Tests', () => {
  test('can access dashboard', async ({ page }) => {
    await page.goto('/wp-admin/');
    await expect(page.locator('#wpadminbar')).toBeVisible();
    await expect(page).toHaveTitle(/Dashboard/);
  });

  test('can access plugins page', async ({ page }) => {
    await page.goto('/wp-admin/plugins.php');
    // Verify TCBF plugin is listed
    await expect(page.locator('text=TC Booking Flow')).toBeVisible();
  });

  test('can access WooCommerce settings', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=wc-settings');
    await expect(page.locator('.woocommerce')).toBeVisible();
  });

  test('can list sc_event posts', async ({ page }) => {
    await page.goto('/wp-admin/edit.php?post_type=sc_event');
    await expect(page.locator('.wp-list-table')).toBeVisible();
  });
});
