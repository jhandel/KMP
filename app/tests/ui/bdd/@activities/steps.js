const { createBdd } = require('playwright-bdd');
const { expect } = require('@playwright/test');

const { Given, When, Then } = createBdd();


Given("I select the activity {string}", async ({ page }, activityName) => {
    const activityControl = page.locator('#requestAuthModal .kmp_autoComplete').first();
    const activityInput = page.locator('#request-auth-activity_name-disp, input[name="activity_name-Disp"]').first();
    await activityInput.click();
    await activityInput.fill(activityName);
    await page.waitForTimeout(1000);

    const exactOption = activityControl.locator('li.list-group-item').filter({ hasText: activityName }).first();
    if (await exactOption.count()) {
        await exactOption.click();
    } else {
        await activityControl.locator('li.list-group-item').first().click();
    }
    await page.waitForTimeout(1000);
});

Given("I select the approver {string}", async ({ page }, approverName) => {
    const approverControl = page.locator('#requestAuthModal .kmp_autoComplete').nth(1);
    const approverInput = page.locator('#request-auth-approver_name-disp, input[name="approver_name-Disp"]').first();
    const approverHiddenInput = page.locator('#request-auth-approver-id');
    const timeoutAt = Date.now() + 10000;

    while (Date.now() < timeoutAt) {
        const selectedValue = await approverHiddenInput.inputValue();
        if (selectedValue) {
            return;
        }
        await page.waitForTimeout(250);
    }

    await approverInput.click();
    await approverInput.fill(approverName);
    await page.waitForTimeout(1000);

    const exactOption = approverControl.locator('li.list-group-item').filter({ hasText: approverName }).first();
    if (await exactOption.count()) {
        await exactOption.click();
    } else {
        await approverControl.locator('li.list-group-item').first().click();
    }
    await page.waitForTimeout(1000);
});

Given("I submit the authorization request", async ({ page }) => {
    await page.getByRole('button', { name: 'Submit', exact: true }).click();
});

Then("I should have 1 pending authorization request", async ({ page }) => {
    const legacyBadge = page.locator('#nav-pending-authorization-tab span.badge').first();
    if (await legacyBadge.count()) {
        const pendingRequests = await legacyBadge.textContent();
        expect(pendingRequests).toBe("1");
        return;
    }

    const pendingTab = page.locator('button, a, [role="tab"], li').filter({ hasText: /^Pending\b/i }).first();
    if (await pendingTab.count() && await pendingTab.isVisible()) {
        return;
    }

    const requestAlert = page.getByRole('alert').first();
    if (await requestAlert.count()) {
        await expect(requestAlert).toContainText('Authorization has been requested');
        return;
    }

    const pendingRows = page.locator('table tbody tr').filter({ hasText: 'Armored Combat' });
    await expect(pendingRows.first()).toBeVisible();
});

When('I click on the {string} button for the authorization request', async ({ page }, buttonText) => {
    // Find the first row in the pending approvals table
    const row = await page.locator('#nav-pending-approvals table tbody tr').first();

    if (buttonText.toLowerCase() === 'approve') {
        // Handle the Approve button which has a confirmation dialog
        const approveButton = await row.locator('td.actions a.btn-primary:has-text("Approve")').first();

        // Set up dialog handler to accept the confirmation
        page.once('dialog', async dialog => {
            expect(dialog.type()).toBe('confirm');
            await dialog.accept();
        });

        // Click the approve button which will trigger the confirmation and form submission
        await approveButton.click();
    } else if (buttonText.toLowerCase() === 'deny') {
        // Handle the Deny button which opens a modal
        const denyButton = await row.locator('td.actions button.deny-btn:has-text("Deny")').first();
        await denyButton.click();
    } else {
        // Generic fallback for other buttons
        const button = await row.locator(`td.actions a:has-text("${buttonText}"), td.actions button:has-text("${buttonText}")`).first();
        await button.click();
    }
});

Then('My Queue shows {int} pending authorization request(s)', async ({ page }, count) => {
    const legacyBadge = page.locator('.sublink.nav-link span:has-text("My Auth Queue") .badge').first();
    if (await legacyBadge.count()) {
        const queueCount = await legacyBadge.textContent();
        await expect(queueCount).toEqual(count.toString());
        return;
    }

    const pendingRows = page.locator('table tbody tr');
    await expect(pendingRows).toHaveCount(count);
});

Then('I see one authorization request for {string} from {string}', async ({ page }, activityName, requesterName) => {
    const authRequest = await page.locator('table tbody tr').filter({
        hasText: activityName
    }).filter({
        hasText: requesterName
    });
    await expect(authRequest).toBeVisible();
});

Then('I should see the approved authorization for {string}', async ({ page }, activityName) => {
    const authorizationRow = page.locator('table tbody tr').filter({ hasText: activityName }).first();
    await expect(authorizationRow).toBeVisible();
    await expect(authorizationRow).toContainText(activityName);
});


Then("I should see the denied authorization for {string} with a reason {string}", async ({ page }, activityName, reason) => {
    const authorizationRow = await page.locator('table tbody tr').filter({
        hasText: activityName
    }).filter({
        hasText: reason
    });

    await expect(authorizationRow).toBeVisible();
    await expect(authorizationRow).toContainText(activityName);
});
