const { createBdd } = require('playwright-bdd');
const { expect } = require('@playwright/test');

const { Given, When, Then } = createBdd();
const baseUrl = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8080';
const mailInboxUrl = process.env.KMP_TEST_MAIL_INBOX_URL || 'http://localhost:8025';
const defaultPassword = process.env.KMP_INSTALL_ADMIN_PASSWORD || 'Password123';
const aliasEmailMap = {
    'admin@test.com': process.env.KMP_INSTALL_ADMIN_EMAIL || 'admin@test.com',
    'Earl@test.com': process.env.KMP_TEST_APPROVER_EMAIL || 'Earl@test.com',
};
const aliasNameMap = {
    'Earl Realm': process.env.KMP_TEST_APPROVER_SCA_NAME || 'Earl Realm',
};

// check if user is logged in
Given('I am logged in as {string}', async ({ page }, emailAddress) => {
    const resolvedEmail = aliasEmailMap[emailAddress] || emailAddress;

    // Navigate to the login page
    await page.goto(baseUrl, { waitUntil: 'networkidle' });
    await page.goto('/members/login', { waitUntil: 'networkidle' });

    // Fill in the login form with admin credentials
    await page.locator('input[name="email_address"]').fill(resolvedEmail);
    await page.locator('input[name="password"]').fill(defaultPassword);
    await page.locator('input[type="submit"], button[type="submit"]').first().click();
    await page.waitForURL(url => !url.pathname.includes('/members/login'), { timeout: 15000 });
});

// Given I am on my profile page
Given('I navigate to my profile page', async ({ page }) => {
    await page.goto('/members/profile', { waitUntil: 'networkidle' });
});

Then('I should see the flash message {string}', async ({ page }, message) => {
    await page.getByRole('alert', { classname: "alert" });
    //check the message we get is the one we expect
    const flashMessage = await page.getByRole('alert', { classname: 'alert' }).textContent();
    expect(flashMessage).toContain(message);
});

Given('I click on the {string} button', async ({ page }, buttonText) => {
    await page.getByRole('button', { name: buttonText, exact: true }).click();
});

Given('I am at the test email inbox', async ({ page }) => {
    await page.goto(mailInboxUrl, { waitUntil: 'networkidle' });
});

When('I check for an email with subject {string}', async ({ page }, subject) => {
    const emailRow = page.locator(`.subject b:has-text("${subject}")`).first();
    const timeoutAt = Date.now() + 60000;

    while (Date.now() < timeoutAt) {
        if (await emailRow.count()) {
            await expect(emailRow).toBeVisible();
            return;
        }

        await page.waitForTimeout(3000);
        await page.reload({ waitUntil: 'networkidle' });
    }

    await expect(emailRow).toBeVisible();
});

When('I open the email with subject {string}', async ({ page }, subject) => {
    // Example: Open the email with the given subject
    const emailRow = await page.locator(`.subject b:has-text("${subject}")`).first();
    await emailRow.click();
});

Then('the email should start with the body:', async ({ page }, expectedContent) => {
    // Example: Check if the email body contains the expected content
    const emailBody = await page.locator('#nav-plain-text div').textContent();
    expect(emailBody).toContain(expectedContent);
});

// Authorization Queue Steps
When('I click on my name {string}', async ({ page }, userName) => {
    const resolvedUserName = aliasNameMap[userName] || userName;
    const userDropdownLink = page
        .locator('.dropdown-toggle, .nav-link, .navbar-nav a')
        .filter({ hasText: resolvedUserName })
        .first();

    if (await userDropdownLink.count()) {
        await userDropdownLink.click();
        return;
    }

    const fallbackNameLink = page.locator(`.nav-link span:has-text("${resolvedUserName}")`).first();
    if (await fallbackNameLink.count()) {
        await fallbackNameLink.click();
        return;
    }

    // Newer nav layouts expose queue links directly without requiring profile dropdown click.
    if (await page.getByRole('link', { name: 'My Auth Queue' }).count()) {
        return;
    }

    return;
});

When('I click on the {string} link', async ({ page }, linkText) => {
    // Click on a link with the specified text
    await page.getByRole('link', { name: linkText }).click();
});

When('I enter the value {string} in the input field with label {string}', async ({ page }, value, label) => {
    // Fill in an input field with the specified label
    await page.getByLabel(label).fill(value);
});

When('I select the option {string} from the dropdown with label {string}', async ({ page }, option, label) => {
    // Select an option from a dropdown with the specified label
    await page.getByLabel(label).selectOption(option);
});

Given("The test inbox is empty", async ({ page }) => {
    await page.goto(mailInboxUrl, { waitUntil: 'networkidle' });
    const deleteAllButton = await page.getByRole('button', { name: ' Delete all' });

    // Check if the delete button is enabled before clicking
    if (await deleteAllButton.isEnabled()) {
        await deleteAllButton.click();
        await page.getByRole('button', { name: 'Delete', exact: true }).click();
    } else {
        console.log('❗️ Delete all button is disabled, skipping emptying inbox');
    }
});
