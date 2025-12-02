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

  test('Verify Facebook for WooCommerce plugin connection', async ({ page }) => {
    console.log('🔍 Verifying Facebook plugin connection...');

    const wpRoot = process.env.WORDPRESS_PATH;
    const expectedAccessToken = process.env.FB_ACCESS_TOKEN;
    const expectedPixelId = process.env.FB_PIXEL_ID;

    // Verify connection via WP-CLI (without --skip-plugins since we need the plugin loaded)
    let connectionCheck;
    try {
      connectionCheck = execSync(
        `wp eval "
          if (function_exists('facebook_for_woocommerce')) {
            \\$connection = facebook_for_woocommerce()->get_connection_handler();
            echo json_encode([
              'connected' => \\$connection->is_connected(),
              'access_token' => \\$connection->get_access_token(),
              'pixel_id' => get_option('wc_facebook_pixel_id'),
              'business_manager_id' => \\$connection->get_business_manager_id(),
            ]);
          } else {
            echo json_encode(['error' => 'Plugin not loaded']);
          }
        " --path="${wpRoot}" --allow-root 2>&1 | grep -v "^PHP Warning" | grep "^{"`,
        { encoding: 'utf8' }
      );
    } catch (error) {
      throw new Error(`Failed to check plugin connection: ${error.message}`);
    }

    const connection = JSON.parse(connectionCheck.trim());

    if (connection.error) {
      throw new Error(`Plugin check failed: ${connection.error}`);
    }

    // Verify connection status
    expect(connection.connected).toBe(true);
    console.log('✅ Plugin is connected');

    // Verify access token
    if (expectedAccessToken) {
      expect(connection.access_token).toBe(expectedAccessToken);
      console.log('✅ Access token matches expected value');
    } else {
      expect(connection.access_token).toBeTruthy();
      console.log('✅ Access token is present');
    }

    // Verify Pixel ID
    if (expectedPixelId) {
      expect(connection.pixel_id).toBe(expectedPixelId);
      console.log('✅ Pixel ID matches expected value');
    } else {
      expect(connection.pixel_id).toBeTruthy();
      console.log('✅ Pixel ID is present');
    }

    // Check Facebook settings page loads without errors
    console.log('🔍 Checking Marketing > Facebook page...');

    const errors = [];
    page.on('pageerror', error => {
      errors.push(`JS Error: ${error.message}`);
    });

    page.on('console', msg => {
      if (msg.type() === 'error') {
        errors.push(`Console Error: ${msg.text()}`);
      }
    });

    await page.goto(`${process.env.WORDPRESS_URL}/wp-admin/admin.php?page=wc-facebook`, {
      waitUntil: 'networkidle',
      timeout: 30000
    });

    // Verify no fatal PHP errors
    const content = await page.content();
    const hasPHPError = content.includes('Fatal error') ||
                        content.includes('Parse error') ||
                        content.includes('There has been a critical error');

    if (hasPHPError) {
      errors.push('PHP errors detected on page');
    }

    // Verify page loaded properly (look for Facebook branding or settings)
    const pageLoaded = await page.locator('.wc-facebook-settings, #wc-facebook-settings-page, .facebook-for-woocommerce').count() > 0;

    if (!pageLoaded) {
      errors.push('Facebook settings page did not load properly');
    }

    if (errors.length > 0) {
      console.log('❌ Errors found:');
      errors.forEach(err => console.log(`   - ${err}`));
      throw new Error(`Facebook settings page validation failed: ${errors.join('; ')}`);
    }

    console.log('✅ Facebook settings page loaded without errors');
    console.log('✅ All connection checks passed');
  });

  test('Verify Storefront theme is active', async ({ page }) => {
    console.log('🔍 Checking active theme...');

    const errors = [];
    page.on('pageerror', error => {
      errors.push(`JS Error: ${error.message}`);
    });

    page.on('console', msg => {
      if (msg.type() === 'error') {
        errors.push(`Console Error: ${msg.text()}`);
      }
    });

    await page.goto(`${process.env.WORDPRESS_URL}/wp-admin/themes.php`, {
      waitUntil: 'networkidle',
      timeout: 30000
    });

    // Check for PHP errors
    const content = await page.content();
    const hasPHPError = content.includes('Fatal error') ||
                        content.includes('Parse error') ||
                        content.includes('There has been a critical error');

    if (hasPHPError) {
      errors.push('PHP errors detected on themes page');
    }

    // Verify Storefront theme is active
    const storefrontActive = await page.locator('.theme.active[data-slug="storefront"]').count();

    if (storefrontActive === 0) {
      const activeTheme = await page.locator('.theme.active').getAttribute('data-slug');
      errors.push(`Storefront theme is not active. Active theme: ${activeTheme || 'unknown'}`);
    } else {
      console.log('✅ Storefront theme is active');
    }

    if (errors.length > 0) {
      console.log('❌ Errors found:');
      errors.forEach(err => console.log(`   - ${err}`));
      throw new Error(`Theme check failed: ${errors.join('; ')}`);
    }

    console.log('✅ Themes page loaded without errors');
  });

  test('Verify WooCommerce is active and endpoints exist', async ({ page }) => {
    console.log('🔍 Checking WooCommerce status...');

    // Check if WooCommerce is active using filtered plugins page
    await page.goto(`${process.env.WORDPRESS_URL}/wp-admin/plugins.php?plugin_status=active`);
    
    const wooActive = await page.locator('tr[data-slug="woocommerce"]').count();
    if (wooActive === 0) {
      throw new Error('❌ WooCommerce is not active');
    }
    console.log('✅ WooCommerce is active');

    // Verify WooCommerce endpoints
    const endpoints = ['shop', 'cart', 'checkout'];
    for (const endpoint of endpoints) {
      const response = await page.goto(`${process.env.WORDPRESS_URL}/${endpoint}`, {
        waitUntil: 'domcontentloaded',
        timeout: 30000
      });

      if (!response || !response.ok()) {
        throw new Error(`❌ /${endpoint} endpoint not accessible (status: ${response?.status()})`);
      }
      console.log(`✅ /${endpoint} endpoint exists`);
    }

    console.log('✅ All WooCommerce checks passed');
  });

});
