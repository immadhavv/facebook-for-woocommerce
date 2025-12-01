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

  test('Verify Facebook external business ID configuration', async ({ page }) => {
    console.log('🔍 Verifying Facebook external business ID...');

    await page.goto(`${process.env.WORDPRESS_URL}/wp-admin/options.php`);

    const label = page.locator('label[for="wc_facebook_external_business_id"]');
    await expect(label).toBeVisible();

    const input = page.locator('#wc_facebook_external_business_id');
    const value = await input.inputValue();

    expect(value).toBeTruthy();
    expect(value).toBe(process.env.FB_EXTERNAL_BUSINESS_ID);

    console.log('✅ External business ID verification PASSED');
    console.log(`   - Option exists: wc_facebook_external_business_id`);
    console.log(`   - Value is non-null: YES`);
    console.log(`   - Matches expected: YES`);
  });

});
