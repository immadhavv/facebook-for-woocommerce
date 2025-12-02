const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');

const {loginToWordPress,logTestStart} = require('./test-helpers');

test.describe('WooCommerce Plugin level tests', () => {

  test.beforeEach(async ({ page }, testInfo) => {
    // Log test start first for proper chronological order
    logTestStart(testInfo);

    // Ensure browser stability
    await page.setViewportSize({ width: 1280, height: 720 });
    await loginToWordPress(page);
  });

  test('Check WooCommerce logs for fatal errors and non-200 responses', async () => {
    console.log('🔍 Checking WooCommerce logs for errors...');

    const today = new Date().toISOString().split('T')[0];

    const logsDir = process.env.LOG_PATH || '../../../wp-content/uploads/wc-logs';

    // Find today's log file
    const logFile = execSync(
      `find ${logsDir} -name "facebook_for_woocommerce-${today}*.log" 2>/dev/null | head -1`,
      { encoding: 'utf8' }
    ).trim();
    if (!logFile) {
      console.log(`⚠️ No log file found for today (${today}) - plugin may not have logged yet`);
      return;
    }

    console.log(`📄 Checking: ${logFile}`);
    const errors = [];

    // Check for fatal errors (case insensitive)
    const fatalCount = execSync(
      `grep -ic "fatal" ${logFile} || echo 0`,
      { encoding: 'utf8' }
    ).trim();
    if (parseInt(fatalCount) > 0) {
      const fatalLines = execSync(`grep -i "fatal" ${logFile}`, { encoding: 'utf8' });
      errors.push(`❌ Found ${fatalCount} fatal error(s):\n${fatalLines}`);
    }

    // Check for non-200 response codes
    const nonOkCodes = execSync(
      `grep "^code: " ${logFile} | grep -v "^code: 200" || true`,
      { encoding: 'utf8' }
    ).trim();
    if (nonOkCodes) {
      errors.push(`❌ Found non-200 response codes:\n${nonOkCodes}`);
    }

    if (errors.length > 0) {
      console.log('\n' + errors.join('\n\n'));
      throw new Error('Log validation failed');
    }

      console.log('✅ Log validation PASSED');
      console.log('   - No fatal errors');
      console.log('   - All response codes are 200 OK');
    });


  test('Verify Debug mode and options visibility', async ({ page }) => {
    console.log('🔍 Debug mode is enabled already. Checking options visibility...');
    await page.goto(`${process.env.WORDPRESS_URL}/wp-admin/options.php`);

    const label = page.locator('label[for="wc_facebook_external_business_id"]');
    await expect(label).toBeVisible();

    const input = page.locator('#wc_facebook_external_business_id');
    const value = await input.inputValue();

    expect(value).toBeTruthy();
    expect(value).toBe(process.env.FB_EXTERNAL_BUSINESS_ID);

    console.log('✅ WooCommerce Debug log checks passed');
    console.log(`   - Option exists: wc_facebook_external_business_id`);
    console.log(`   - Value is non-null: YES`);
    console.log(`   - Matches expected: YES`);
  });

  test('Check WordPress and WooCommerce are up to date', async ({ page }) => {
    await page.goto(`${process.env.WORDPRESS_URL}/wp-admin/update-core.php`);

    // Check WordPress
    const wpUpToDate = await page.locator('h2.response:has-text("You have the latest version of WordPress")').count();
    if (wpUpToDate > 0) {
      console.log('✅ WordPress up to date');
    } else {
      console.log('❌ WordPress needs update');
    }

    // Check WooCommerce
    const allPluginsUpToDate = await page.locator('p:has-text("Your plugins are all up to date.")').count();
    const wooInUpdateTable = await page.locator('#update-plugins-table tr:has-text("WooCommerce")').count();

    if (allPluginsUpToDate > 0 || wooInUpdateTable === 0) {
      console.log('✅ WooCommerce up to date');
    } else {
      console.log('❌ WooCommerce needs update');
    }

    expect(wpUpToDate).toBeGreaterThan(0);
    expect(allPluginsUpToDate > 0 || wooInUpdateTable === 0).toBe(true);
    console.log('✅ Wordpress and WooCommerce are up to date');
  });

});
