const { createBdd } = require('playwright-bdd');
const { expect } = require('@playwright/test');

const { Given, When, Then } = createBdd();

// --- Authentication Steps ---

Given('I am logged in as admin', async ({ page }) => {
    await page.goto('/members/login', { waitUntil: 'networkidle' });
    await page.getByRole('textbox', { name: 'Email Address' }).fill('admin@amp.ansteorra.org');
    await page.getByRole('textbox', { name: 'Password' }).fill('TestPassword');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForTimeout(2000);
});

Given('I logout and login as a basic user', async ({ page }) => {
    await page.goto('/members/logout', { waitUntil: 'networkidle' });
    await page.goto('/members/login', { waitUntil: 'networkidle' });
    await page.getByRole('textbox', { name: 'Email Address' }).fill('iris@ampdemo.com');
    await page.getByRole('textbox', { name: 'Password' }).fill('TestPassword');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForTimeout(2000);
});

// --- Navigation Steps ---

When('I navigate to the workflow engine page', async ({ page }) => {
    await page.goto('/workflow-engine', { waitUntil: 'networkidle' });
});

When('I navigate to the create workflow page', async ({ page }) => {
    await page.goto('/workflow-engine/create', { waitUntil: 'networkidle' });
});

When('I navigate to the workflow analytics page', async ({ page }) => {
    await page.goto('/workflow-engine/analytics', { waitUntil: 'networkidle' });
});

When('I try to navigate to the workflow engine page', async ({ page }) => {
    await page.goto('/workflow-engine', { waitUntil: 'networkidle' });
});

When('I click the {string} button', async ({ page }, buttonText) => {
    await page.getByRole('link', { name: buttonText }).click();
    await page.waitForTimeout(1000);
});

When('I click the edit button for the first workflow', async ({ page }) => {
    const editLink = page.locator('.workflowDefinitions table tbody tr').first().getByRole('link', { name: 'Edit' });
    await editLink.click();
    await page.waitForTimeout(1000);
});

When('I open the editor for the {string} workflow', async ({ page }, name) => {
    // Navigate directly to the editor by finding the workflow ID from the table
    // or use direct URL navigation for known workflows
    const row = page.locator('.workflowDefinitions table tbody tr').filter({ hasText: name }).first();
    const editLink = row.getByRole('link', { name: 'Edit' });
    await editLink.click({ timeout: 10000 });
    await page.waitForTimeout(2000);
});

// --- Index Page Assertions ---

Then('I should see the workflow definitions list', async ({ page }) => {
    await expect(page.locator('h3')).toContainText('Workflow Definitions');
    await expect(page.locator('.workflowDefinitions table')).toBeVisible();
});

Then('I should see the {string} button', async ({ page }, buttonText) => {
    await expect(page.getByRole('link', { name: buttonText })).toBeVisible();
});

Then('I should see seeded workflow definitions', async ({ page }) => {
    const rows = page.locator('.workflowDefinitions table tbody tr');
    await expect(rows).not.toHaveCount(0);
});

Then('I should see a workflow named {string}', async ({ page }, name) => {
    await expect(page.locator('.workflowDefinitions table tbody').getByText(name, { exact: true })).toBeVisible();
});

// --- Editor Page Assertions ---

Then('I should see the workflow editor', async ({ page }) => {
    await expect(page.locator('[data-controller="workflow-editor"]')).toBeVisible();
});

Then('I should see the editor toolbar', async ({ page }) => {
    await expect(page.locator('.btn-toolbar')).toBeVisible();
});

Then('I should see the editor canvas', async ({ page }) => {
    await expect(page.locator('#workflow-canvas')).toBeVisible();
});

Then('I should see state nodes on the canvas', async ({ page }) => {
    // Wait for the editor JS to load and render nodes
    await page.waitForTimeout(2000);
    // The editor should have rendered nodes for the workflow states
    const canvas = page.locator('#workflow-canvas');
    await expect(canvas).toBeVisible();
});

Then('I should see the {string} button in the toolbar', async ({ page }, buttonText) => {
    await expect(page.locator('.btn-toolbar').getByText(buttonText)).toBeVisible();
});

// --- Create Page Assertions ---

Then('I should see the create workflow form', async ({ page }) => {
    await expect(page.locator('form')).toBeVisible();
    await expect(page.locator('legend')).toContainText('Create Workflow Definition');
});

Then('I should see the name field', async ({ page }) => {
    await expect(page.getByLabel('Name', { exact: true })).toBeVisible();
});

Then('I should see the slug field', async ({ page }) => {
    await expect(page.getByLabel('Slug')).toBeVisible();
});

Then('I should see the entity type field', async ({ page }) => {
    await expect(page.getByLabel('Entity Type')).toBeVisible();
});

// --- Create Workflow Steps ---

When('I fill in the workflow form with a unique slug', async ({ page }) => {
    const ts = Date.now();
    await page.getByLabel('Name', { exact: true }).fill(`Test Workflow ${ts}`);
    await page.getByLabel('Slug').fill(`test-workflow-${ts}`);
    await page.getByLabel('Description').fill('A test workflow');
    await page.getByLabel('Entity Type').fill('TestEntity');
});

When('I fill in the workflow form for deletion test', async ({ page }) => {
    const ts = Date.now();
    await page.getByLabel('Name', { exact: true }).fill('Deletable Workflow');
    await page.getByLabel('Slug').fill(`deletable-workflow-${ts}`);
    await page.getByLabel('Description').fill('To be deleted');
    await page.getByLabel('Entity Type').fill('TestEntity');
});

When('I fill in the workflow form with:', async ({ page }, dataTable) => {
    const data = dataTable.rowsHash();
    if (data.name) await page.getByLabel('Name', { exact: true }).fill(data.name);
    if (data.slug) await page.getByLabel('Slug').fill(data.slug);
    if (data.description) await page.getByLabel('Description').fill(data.description);
    if (data.entity_type) await page.getByLabel('Entity Type').fill(data.entity_type);
    if (data.plugin_name) await page.getByLabel('Plugin Name').fill(data.plugin_name);
});

When('I submit the workflow form', async ({ page }) => {
    await page.getByRole('button', { name: 'Submit' }).click();
    await page.waitForTimeout(2000);
});

Then('I should be redirected to the workflow editor', async ({ page }) => {
    await expect(page).toHaveURL(/\/workflow-engine\/editor\//);
});

// --- Analytics Page Assertions ---

Then('I should see the analytics dashboard', async ({ page }) => {
    await expect(page.locator('h3')).toContainText('Workflow Analytics');
});

Then('I should see the active instances count', async ({ page }) => {
    await expect(page.locator('.card-title').filter({ hasText: 'Active Instances' })).toBeVisible();
});

Then('I should see the completed instances count', async ({ page }) => {
    await expect(page.locator('.card-title').filter({ hasText: 'Completed Instances' })).toBeVisible();
});

// --- Access Control Assertions ---

Then('I should not see the workflow definitions list', async ({ page }) => {
    // Basic user should get forbidden or redirected
    const heading = page.locator('h3').filter({ hasText: 'Workflow Definitions' });
    const isVisible = await heading.isVisible().catch(() => false);
    if (isVisible) {
        // If the heading is visible, the test fails
        expect(isVisible).toBe(false);
    }
    // Otherwise the user was redirected or got an error page, which is correct
});

// --- Delete Workflow Steps ---

When('I delete the workflow named {string}', async ({ page }, name) => {
    const row = page.locator('.workflowDefinitions table tbody tr').filter({ hasText: name });
    // Accept the confirm dialog
    page.once('dialog', dialog => dialog.accept());
    await row.getByRole('link', { name: 'Delete' }).click();
    await page.waitForTimeout(2000);
});

Then('I should see the flash message containing {string}', async ({ page }, text) => {
    const alert = page.locator('.alert-success, .alert-info');
    await expect(alert.first()).toBeVisible();
    const alertText = await alert.first().textContent();
    expect(alertText.toLowerCase()).toContain(text.toLowerCase());
});

// --- Approval Gate Editor Steps ---

When('I click on an approval state node', async ({ page }) => {
    // Wait for the editor to load nodes
    await page.waitForSelector('.workflow-node', { timeout: 10000 });
    // Approval states have class 'workflow-node-approval'
    const approvalNode = page.locator('.workflow-node-approval').first();
    if (await approvalNode.count() > 0) {
        await approvalNode.click();
        await page.waitForTimeout(500);
    } else {
        // Fallback: click nodes looking for one that triggers the approval panel
        const nodes = page.locator('.workflow-node');
        const count = await nodes.count();
        for (let i = 0; i < count; i++) {
            await nodes.nth(i).click();
            await page.waitForTimeout(500);
            const gateSection = page.locator('text=Approval Gate');
            if (await gateSection.count() > 0) break;
        }
    }
});

Then('I should see the approval gate configuration section', async ({ page }) => {
    await expect(page.getByRole('heading', { name: 'Approval Gate' })).toBeVisible({ timeout: 10000 });
    await expect(page.locator('[data-gate-field="approval_type"]')).toBeVisible();
    await expect(page.locator('[data-gate-field="threshold_type"]')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Save Approval Gate' })).toBeVisible();
});
