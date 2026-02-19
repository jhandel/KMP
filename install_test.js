let chromium;
try {
  ({ chromium } = require('./app/node_modules/@playwright/test'));
} catch (errorFromAppNodeModules) {
  try {
    ({ chromium } = require('@playwright/test'));
  } catch (errorFromPlaywrightTest) {
    ({ chromium } = require('playwright'));
  }
}

const fs = require('fs');
const path = require('path');

function parseEnvFile(filePath) {
  if (!fs.existsSync(filePath)) {
    return {};
  }

  const values = {};
  const lines = fs.readFileSync(filePath, 'utf8').split(/\r?\n/);

  for (const rawLine of lines) {
    const line = rawLine.trim();
    if (!line || line.startsWith('#')) {
      continue;
    }

    const normalized = line.startsWith('export ') ? line.slice(7).trim() : line;
    const separatorIndex = normalized.indexOf('=');
    if (separatorIndex <= 0) {
      continue;
    }

    const key = normalized.slice(0, separatorIndex).trim();
    let value = normalized.slice(separatorIndex + 1).trim();
    if (
      (value.startsWith('"') && value.endsWith('"')) ||
      (value.startsWith("'") && value.endsWith("'"))
    ) {
      value = value.slice(1, -1);
    }

    values[key] = value;
  }

  return values;
}

const envFileValues = parseEnvFile(path.join(__dirname, 'app', 'config', '.env'));
const getInstallSetting = (key, fallback) => process.env[key] ?? envFileValues[key] ?? fallback;

const installSettings = {
  dbHost: getInstallSetting('MYSQL_HOST', 'localhost'),
  dbPort: getInstallSetting('MYSQL_PORT', '3306'),
  dbUser: getInstallSetting('MYSQL_USERNAME', 'KMPSQLDEV'),
  dbPassword: getInstallSetting('MYSQL_PASSWORD', 'P@ssw0rd'),
  dbName: getInstallSetting('MYSQL_DB_NAME', 'KMP_DEV'),
  smtpHost: getInstallSetting('EMAIL_SMTP_HOST', 'localhost'),
  smtpPort: getInstallSetting('EMAIL_SMTP_PORT', '1025'),
  smtpUsername: getInstallSetting('EMAIL_SMTP_USERNAME', 'testuser'),
  smtpPassword: getInstallSetting('EMAIL_SMTP_PASSWORD', 'testpass'),
  systemEmailFrom: getInstallSetting('SYSTEM_EMAIL_FROM', 'admin@kingdom.org'),
  adminEmail: getInstallSetting('KMP_INSTALL_ADMIN_EMAIL', 'admin@test.com'),
  adminPassword: getInstallSetting('KMP_INSTALL_ADMIN_PASSWORD', 'Password123'),
};

(async () => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();
  
  page.on('console', msg => {
    if (msg.type() === 'error') console.log('BROWSER ERROR:', msg.text());
  });

  async function screenshot(name) {
    await page.screenshot({ path: `/tmp/install_${name}.png`, fullPage: true });
    console.log(`📸 /tmp/install_${name}.png`);
  }

  async function checkForError() {
    const bodyText = await page.locator('body').textContent();
    if ((bodyText.includes('Exception') && bodyText.includes('Error')) || bodyText.includes('Stack trace')) {
      await screenshot('ERROR_' + Date.now());
      console.log('❌ ERROR ON PAGE:\n', bodyText.substring(0, 800));
      return true;
    }
    // Check for flash errors
    const flashErrors = await page.locator('.alert-danger, .alert-error, .message.error').count();
    if (flashErrors > 0) {
      const msg = await page.locator('.alert-danger, .alert-error, .message.error').first().textContent();
      console.log('⚠️  Flash error:', msg?.trim());
    }
    return false;
  }

  try {
    // ── STEP 1: Preflight ──
    console.log('\n=== STEP 1: Preflight ===');
    await page.goto('http://localhost:8080/install', { waitUntil: 'networkidle' });
    await screenshot('01_preflight');
    if (await checkForError()) { await browser.close(); process.exit(1); }
    console.log('URL:', page.url(), '| Title:', await page.title());

    // Show preflight results
    const rows = await page.locator('.installer-preflight-table tr').count();
    console.log('Preflight rows:', rows);
    const fails = await page.locator('[class*="fail"], .bg-danger').count();
    console.log('Fail indicators:', fails);

    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');

    // ── STEP 2: Database ──
    console.log('\n=== STEP 2: Database ===');
    await screenshot('02_database');
    if (await checkForError()) { await browser.close(); process.exit(1); }
    console.log('URL:', page.url());

    await page.fill('[name="db_host"]', installSettings.dbHost);
    await page.fill('[name="db_port"]', String(installSettings.dbPort));
    await page.fill('[name="db_user"]', installSettings.dbUser);
    await page.fill('[name="db_password"]', installSettings.dbPassword);
    await page.fill('[name="db_name"]', installSettings.dbName);
    await screenshot('03_database_filled');

    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');

    // ── STEP 3: Communications ──
    console.log('\n=== STEP 3: Communications ===');
    await screenshot('04_communications');
    if (await checkForError()) { await browser.close(); process.exit(1); }
    console.log('URL:', page.url());

    await page.fill('[name="email_smtp_host"]', installSettings.smtpHost);
    await page.fill('[name="email_smtp_port"]', String(installSettings.smtpPort));
    await page.fill('[name="email_smtp_username"]', installSettings.smtpUsername);
    await page.fill('[name="email_smtp_password"]', installSettings.smtpPassword);
    await page.fill('[name="system_email_from"]', installSettings.systemEmailFrom);
    // storage_adapter defaults to local - leave as-is
    await screenshot('05_comms_filled');

    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');

    // ── STEP 4: Branding ──
    console.log('\n=== STEP 4: Branding ===');
    await screenshot('06_branding');
    if (await checkForError()) { await browser.close(); process.exit(1); }
    console.log('URL:', page.url());

    await page.fill('[name="kingdom_name"]', 'Ansteorra');
    await page.fill('[name="long_site_title"]', 'Kingdom of Ansteorra Management Portal');
    await page.fill('[name="short_site_title"]', 'KMP');
    const tzSelect = await page.locator('[name="default_timezone"]');
    if (await tzSelect.count() > 0) await tzSelect.selectOption('America/Chicago');
    await screenshot('07_branding_filled');

    // ── Finalize ──
    console.log('\n=== FINALIZE ===');
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle', { timeout: 120000 });
    await screenshot('08_after_finalize');
    const finalUrl = page.url();
    console.log('URL after finalize:', finalUrl);
    if (await checkForError()) { await browser.close(); process.exit(1); }

    if (finalUrl.includes('login') || finalUrl.includes('members')) {
      console.log('\n✅ INSTALL SUCCEEDED — redirected to login!');
    } else {
      const bodyText = await page.locator('body').textContent();
      console.log('Page body:', bodyText.substring(0, 1000));
    }

    // ── Post-install login check ──
    console.log('\n=== POST-INSTALL LOGIN ===');
    await page.goto('http://localhost:8080/members/login');
    await page.fill('[name="email_address"]', installSettings.adminEmail);
    await page.fill('[name="password"]', installSettings.adminPassword);
    await page.click('input[type="submit"]');
    await page.waitForURL('**/members/**', { timeout: 10000 }).catch(() => {});
    await screenshot('09_post_install_login');
    const loginUrl = page.url();
    console.log('After login URL:', loginUrl);
    if (!loginUrl.includes('login')) {
      console.log('✅ LOGIN SUCCEEDED');
    } else {
      console.log('❌ LOGIN FAILED — still on login page');
    }

  } catch (err) {
    console.error('❌ Test crashed:', err.message);
    await page.screenshot({ path: `/tmp/install_CRASH.png`, fullPage: true }).catch(() => {});
    await browser.close();
    process.exit(1);
  }
  await browser.close();
})();
