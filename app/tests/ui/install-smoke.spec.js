const { test, expect } = require('@playwright/test');

const ADMIN_EMAIL = process.env.KMP_INSTALL_ADMIN_EMAIL || 'admin@test.com';
const ADMIN_PASSWORD = process.env.KMP_INSTALL_ADMIN_PASSWORD || 'Password123';

async function loginAsAdmin(page) {
    await page.goto('/members/login', { waitUntil: 'networkidle' });
    await page.fill('input[name="email_address"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASSWORD);
    await page.click('input[type="submit"]');
    await page.waitForURL(/\/members\//, { timeout: 15000 });
}

test.describe('Fresh install smoke', () => {
    test('admin can sign in and reach profile', async ({ page }) => {
        await loginAsAdmin(page);

        await page.goto('/members/profile', { waitUntil: 'networkidle' });
        await expect(page).toHaveURL(/\/members\/profile/);
        await expect(page.locator('body')).toContainText('Admin von Admin');
    });

    test('admin can access updates and email templates', async ({ page }) => {
        await loginAsAdmin(page);

        await page.goto('/admin/updates', { waitUntil: 'networkidle' });
        await expect(page).toHaveURL(/\/admin\/updates/);
        await expect(page.locator('body')).toContainText('Updates');

        await page.goto('/email-templates', { waitUntil: 'networkidle' });
        await expect(page).toHaveURL(/\/email-templates/);
        await expect(page.locator('body')).toContainText('Email Templates');
    });

    test('installer endpoint is locked after successful install', async ({ page }) => {
        await page.goto('/install', { waitUntil: 'networkidle' });
        await expect(page).toHaveURL(/\/members\/login/);
    });
});

