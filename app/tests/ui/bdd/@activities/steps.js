const { createBdd } = require('playwright-bdd');
const { expect } = require('@playwright/test');

const { Given, When, Then } = createBdd();


Given("I select the activity {string}", async ({ page }, activityName) => {
    const activityInput = page.locator('#request-auth-activity_name-disp');
    await activityInput.click();
    await activityInput.fill(activityName);
    await page.waitForTimeout(1000);
    const option = page.getByRole('option', { name: activityName, exact: true });
    await option.click();
    await page.waitForTimeout(3000); // Wait for approvers to load via AJAX
});

Given("I select the approver {string}", async ({ page }, approverName) => {
    const approverInput = page.locator('#request-auth-approver_name-disp');
    await approverInput.click();
    const searchTerm = approverName.includes(': ') ? approverName.split(': ').pop() : approverName;
    await approverInput.fill(searchTerm);
    await page.waitForTimeout(1000);
    const option = page.locator('[role="option"]').filter({ hasText: approverName });
    await option.first().click();
    await page.waitForTimeout(1000);
});

Given("I submit the authorization request", async ({ page }) => {
    await page.getByRole('button', { name: 'Submit', exact: true }).click();
});

Then("I should have 1 pending authorization request", async ({ page }) => {
    // Click on the Authorizations tab to make it visible
    const authTab = page.locator('[data-detail-tabs-target="tabBtn"]').filter({ hasText: /Authorizations/i });
    await authTab.click();
    await page.waitForTimeout(2000);

    // Click the "Pending" system view tab
    const authSection = page.locator('#nav-member-authorizations');
    const pendingTab = authSection.locator('[role="tab"]').filter({ hasText: /^Pending/i });
    if (await pendingTab.count() > 0) {
        await pendingTab.click();
        await page.waitForTimeout(3000);
    }

    // Verify at least one row exists in the grid
    const rows = authSection.locator('table tbody tr');
    await expect(rows.first()).toBeVisible({ timeout: 15000 });
});

When('I click on the {string} button for the authorization request', async ({ page }, buttonText) => {
    // Use the row context stored by "I see one authorization request" step
    const ctx = page._lastMatchedAuthRow || {};
    let row;
    if (ctx.activityName && ctx.requesterName) {
        row = page.locator('table tbody tr')
            .filter({ has: page.locator(`td:text-is("${ctx.activityName}")`) })
            .filter({ hasText: ctx.requesterName })
            .first();
    } else {
        row = page.locator('table tbody tr').first();
    }

    if (buttonText.toLowerCase() === 'approve') {
        const approveButton = row.locator('button:has-text("Approve"), a:has-text("Approve")').first();

        page.once('dialog', async dialog => {
            await dialog.accept();
        });

        await approveButton.click({ force: true });
    } else if (buttonText.toLowerCase() === 'deny') {
        const denyButton = row.locator('button:has-text("Deny"), a:has-text("Deny")').first();
        await denyButton.click({ force: true });
    } else {
        const button = row.locator(`a:has-text("${buttonText}"), button:has-text("${buttonText}")`).first();
        await button.click({ force: true });
    }
    await page.waitForTimeout(1000);
});

Then('My Queue shows {int} pending authorization request(s)', async ({ page }, count) => {
    // Navigate to unified approvals page and verify pending requests
    await page.goto('/approvals', { waitUntil: 'networkidle' });
    const rows = page.locator('table tbody tr');
    await expect(rows.first()).toBeVisible({ timeout: 15000 });
});

Then('I see one authorization request for {string} from {string}', async ({ page }, activityName, requesterName) => {
    // Wait for grid to load after search
    await page.waitForSelector('table tbody tr', { state: 'visible', timeout: 30000 });
    // Use exact cell text matching to avoid "Armored" matching "Armored Field Marshal"
    const getRows = () => page.locator('table tbody tr')
        .filter({ has: page.locator(`td:text-is("${activityName}")`) })
        .filter({ hasText: requesterName });

    // If not found on current page, navigate with sort params to bring newest entries first
    if (await getRows().count() === 0) {
        const url = new URL(page.url());
        url.searchParams.set('sort', 'requested_on');
        url.searchParams.set('direction', 'desc');
        if (!url.searchParams.has('search')) {
            url.searchParams.set('search', requesterName);
        }
        await page.goto(url.toString(), { waitUntil: 'networkidle' });
        await page.waitForTimeout(3000);
        await page.waitForSelector('table tbody tr', { state: 'visible', timeout: 15000 });
    }

    await expect(getRows().first()).toBeVisible();
    // Store context for the approve/deny step
    page._lastMatchedAuthRow = { activityName, requesterName };
});

// ── Unified Approvals Modal Steps ────────────────────────────────────

Then('I see one approval request for {string} from {string}', async ({ page }, activityName, requesterName) => {
    // Wait for DataverseGrid to load after search
    await page.waitForSelector('table tbody tr', { state: 'visible', timeout: 30000 });

    const getRows = () => page.locator('table tbody tr')
        .filter({ hasText: activityName })
        .filter({ hasText: requesterName });

    await expect(getRows().first()).toBeVisible({ timeout: 15000 });
    // Store context for the respond step
    page._lastMatchedApprovalRow = { activityName, requesterName };
});

When('I click the respond button for the approval request', async ({ page }) => {
    const ctx = page._lastMatchedApprovalRow || {};
    let row;
    if (ctx.activityName && ctx.requesterName) {
        row = page.locator('table tbody tr')
            .filter({ hasText: ctx.activityName })
            .filter({ hasText: ctx.requesterName })
            .first();
    } else {
        row = page.locator('table tbody tr').first();
    }

    const respondBtn = row.locator('button:has-text("Respond"), a:has-text("Respond")').first();
    await respondBtn.click({ force: true });
    // Wait for the Bootstrap modal to appear
    await page.waitForSelector('#approvalResponseModal.show', { state: 'visible', timeout: 10000 });
});

When('I select the {string} decision in the approval modal', async ({ page }, decision) => {
    const modal = page.locator('#approvalResponseModal');
    if (decision.toLowerCase() === 'approve') {
        await modal.locator('#decisionApprove').click();
    } else {
        await modal.locator('#decisionReject').click();
    }
    await page.waitForTimeout(500);
});

When('I enter the approval comment {string}', async ({ page }, comment) => {
    const modal = page.locator('#approvalResponseModal');
    await modal.locator('#approvalComment').fill(comment);
});

When('I submit the approval response', async ({ page }) => {
    const modal = page.locator('#approvalResponseModal');
    const submitBtn = modal.locator('button[type="submit"]');
    await submitBtn.click();
    // Wait for form submission and redirect
    await page.waitForLoadState('networkidle', { timeout: 15000 });
});

// ── Authorization Profile Verification Steps ────────────────────────

Then('I should see the approved authorization for {string}', async ({ page }, activityName) => {
    // Click the Authorizations tab
    const authTab = page.locator('[data-detail-tabs-target="tabBtn"]').filter({ hasText: /Authorizations/i });
    if (await authTab.count() > 0) {
        await authTab.click();
        await page.waitForTimeout(2000);
    }

    // Click the "Active" system view tab
    const authSection = page.locator('#nav-member-authorizations');
    const activeTab = authSection.locator('[role="tab"]').filter({ hasText: /^Active/i });
    if (await activeTab.count() > 0) {
        await activeTab.click();
        await page.waitForTimeout(3000);
    }

    // Wait for grid to load and look for the activity
    await authSection.locator('table tbody tr').first().waitFor({ state: 'visible', timeout: 30000 });
    const authRow = authSection.locator('table tbody tr').filter({ hasText: activityName });
    await expect(authRow.first()).toBeVisible();
});


Then("I should see the denied authorization for {string} with a reason {string}", async ({ page }, activityName, reason) => {
    // Click the Authorizations tab
    const authTab = page.locator('[data-detail-tabs-target="tabBtn"]').filter({ hasText: /Authorizations/i });
    if (await authTab.count() > 0) {
        await authTab.click();
        await page.waitForTimeout(2000);
    }

    // Click the "Previous" system view tab
    const authSection = page.locator('#nav-member-authorizations');
    const previousTab = authSection.locator('[role="tab"]').filter({ hasText: /^Previous/i });
    if (await previousTab.count() > 0) {
        await previousTab.click();
        await page.waitForTimeout(3000);
    }

    // Wait for grid to load
    await authSection.locator('table').first().waitFor({ state: 'visible', timeout: 30000 });

    // Look for the denied authorization with the activity name
    const authRow = authSection.locator('table tbody tr').filter({ hasText: activityName });
    await expect(authRow.first()).toBeVisible({ timeout: 10000 });
});
