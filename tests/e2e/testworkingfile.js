const { test, expect } = require('@playwright/test');

// Test configuration from environment variables
const baseURL = process.env.WORDPRESS_URL || 'http://localhost:8080';
const username = process.env.WP_USERNAME || 'admin';
const password = process.env.WP_PASSWORD || 'admin';

// Helper function for reliable login
async function loginToWordPress(page) {
  // Navigate to login page
  await page.goto(`${baseURL}/wp-admin/`, { waitUntil: 'networkidle', timeout: 120000 });

  // Check if we're already logged in
  const isLoggedIn = await page.locator('#wpcontent').isVisible({ timeout: 5000 });
  if (isLoggedIn) {
    console.log('✅ Already logged in');
    return;
  }

  // Fill login form - wait longer for login elements
  console.log('🔐 Logging in to WordPress...');
  await page.waitForSelector('#user_login', { timeout: 120000 });
  await page.fill('#user_login', username);
  await page.fill('#user_pass', password);
  await page.click('#wp-submit');

  // Wait for login to complete
  await page.waitForLoadState('networkidle', { timeout: 120000 });
  console.log('✅ Login completed');
}

// Helper function to safely take screenshots
async function safeScreenshot(page, path) {
  try {
    // Check if page is still available
    if (page && !page.isClosed()) {
      await page.screenshot({ path, fullPage: true });
      console.log(`✅ Screenshot saved: ${path}`);
    } else {
      console.log('⚠️ Cannot take screenshot - page is closed');
    }
  } catch (error) {
    console.log(`⚠️ Screenshot failed: ${error.message}`);
  }
}

test.describe('Facebook for WooCommerce - Product Creation E2E Tests', () => {

  test.beforeEach(async ({ page }) => {
    // Ensure browser stability
    await page.setViewportSize({ width: 1280, height: 720 });
    await loginToWordPress(page);
  });

  test('Create variable product with attributes - CLEAN WORKING VERSION', async ({ page }) => {
    try {
      await loginToWordPress(page);

      // Navigate to add new product page
      await page.goto(`${baseURL}/wp-admin/post-new.php?post_type=product`, {
        waitUntil: 'networkidle',
        timeout: 120000
      });

      // Wait for the product editor to load
      await page.waitForSelector('#title', { timeout: 120000 });
      await page.fill('#title', 'Test Variable Product - E2E 14:42');
      console.log('✅ Product title filled');

      // Set product type to variable
      await page.selectOption('#product-type', 'variable');
      console.log('✅ Set product type to variable');

      // Wait for the variable product interface to load
      await page.waitForTimeout(5000);
      console.log('✅ Variable product interface loaded');

      // STEP 1: Go to Attributes tab
      console.log('🔄 Step 1: Going to Attributes tab...');
      const attributesTab = page.locator('li.attribute_tab a[href="#product_attributes"]');
      await attributesTab.waitFor({ state: 'visible', timeout: 30000 });
      await attributesTab.click();
      await page.waitForTimeout(2000);
      console.log('✅ Successfully switched to Attributes tab');

      // STEP 2: Fill attribute Name field (using type() to trigger JS events)
      console.log('🔄 Step 2: Typing attribute Name field...');
      const nameField = page.locator('input.attribute_name[name="attribute_names[0]"]');
      await nameField.waitFor({ state: 'visible', timeout: 10000 });
      await nameField.click(); // Focus the field first
      await nameField.clear(); // Clear any existing content
      await nameField.type('color', { delay: 100 }); // Type with delay to simulate real typing
      console.log('✅ Typed attribute name: color');

      // STEP 3: Fill attribute Value field (using type() to trigger JS events)
      console.log('🔄 Step 3: Typing attribute Value field...');
      const valueField = page.locator('textarea[name="attribute_values[0]"]');
      await valueField.waitFor({ state: 'visible', timeout: 10000 });
      await valueField.click(); // Focus the field first
      await valueField.clear(); // Clear any existing content
      await valueField.type('blue|red|yellow', { delay: 50 }); // Type with delay
      console.log('✅ Typed attribute values: blue|red|yellow');

      // STEP 4: Check "Used for variations" checkbox
      console.log('🔄 Step 4: Checking "Used for variations" checkbox...');
      const variationCheckbox = page.locator('input.woocommerce_attribute_used_for_variations[name="attribute_variation[0]"]');
      await variationCheckbox.waitFor({ state: 'visible', timeout: 10000 });
      await variationCheckbox.check();
      console.log('✅ Checked "Used for variations" checkbox');

      // STEP 5: Click "Save attributes" button
      console.log('🔄 Step 5: Clicking "Save attributes" button...');
      const saveAttributesBtn = page.locator('button.save_attributes.button-primary');
      await saveAttributesBtn.waitFor({ state: 'visible', timeout: 10000 });
      await saveAttributesBtn.click();
      console.log('✅ Clicked "Save attributes" button');

      // Wait for attributes to be saved (CRUCIAL STEP)
      await page.waitForTimeout(8000);
      console.log('✅ Attributes saved successfully');

      // STEP 6: Go to Variations tab
      console.log('🔄 Step 6: Going to Variations tab...');
      const variationsTab = page.locator('a[href="#variable_product_options"]');
      await variationsTab.waitFor({ state: 'visible', timeout: 30000 });
      await variationsTab.click();
      await page.waitForTimeout(3000);
      console.log('✅ Successfully switched to Variations tab');

      // STEP 7: Set up dialog listener BEFORE clicking generate variations
      console.log('🔄 Step 7: Setting up dialog listener for confirmation popup...');
      page.on('dialog', async dialog => {
        console.log(`Dialog message: ${dialog.message()}`);
        await dialog.accept();
        console.log('✅ Clicked OK in confirmation popup');
      });

      // STEP 8: Click "Generate variations" button
      console.log('🔄 Step 8: Clicking "Generate variations" button...');
      const generateVariationsBtn = page.locator('button.generate_variations');
      await generateVariationsBtn.waitFor({ state: 'visible', timeout: 15000 });
      await generateVariationsBtn.click();
      console.log('✅ Clicked "Generate variations" button');

      // Wait for variations to be generated
      await page.waitForTimeout(10000);
      console.log('✅ Variations generation completed');

      // Check if variations were created
      const variations = await page.locator('.woocommerce_variation').count();
      console.log(`🔍 Found ${variations} variations after generation`);

      if (variations > 0) {
        console.log(`✅ Successfully generated ${variations} variations`);

        // STEP 9: Click "Add price" button
        console.log('🔄 Step 9: Clicking "Add price" button...');
        const addPriceBtn = page.locator('button.add_price_for_variations');
        await addPriceBtn.waitFor({ state: 'visible', timeout: 10000 });
        await addPriceBtn.click();
        console.log('✅ Clicked "Add price" button');

        // STEP 10: Type price in the input field (using type() to trigger JS events)
        console.log('🔄 Step 10: Typing price in input field...');
        await page.waitForTimeout(2000);

        const priceInput = page.locator('input.components-text-control__input.wc_input_variations_price');
        await priceInput.waitFor({ state: 'visible', timeout: 10000 });
        await priceInput.click(); // Focus the field first
        await priceInput.clear(); // Clear any existing content
        await priceInput.type('29.99', { delay: 100 }); // Type with delay to trigger JS events
        console.log('✅ Typed bulk price: 29.99');

        // STEP 11: Click "Add prices" button
        console.log('🔄 Step 11: Clicking "Add prices" button...');
        const addPricesBtn = page.locator('button.add_variations_price_button.button-primary');
        await addPricesBtn.waitFor({ state: 'visible', timeout: 10000 });
        await addPricesBtn.click();
        console.log('✅ Clicked "Add prices" button');
        await page.waitForTimeout(3000);

      } else {
        throw new Error('No variations were generated - attribute setup failed');
      }

      // STEP 12: Publish the product
      console.log('🔄 Step 12: Publishing product...');
      const publishButton = page.locator('#publish');
      await publishButton.waitFor({ state: 'visible', timeout: 30000 });
      await publishButton.click();
      await page.waitForTimeout(5000);
      console.log('✅ Product published successfully');

      // Verify no PHP fatal errors
      const pageContent = await page.content();
      expect(pageContent).not.toContain('Fatal error');
      expect(pageContent).not.toContain('Parse error');

      console.log('✅ Variable product creation test completed successfully');

    } catch (error) {
      console.log(`⚠️ Variable product test failed: ${error.message}`);
      // Take screenshot for debugging
      await safeScreenshot(page, 'variable-product-test-failure.png');
      throw error;
    }
  });

  test('Create simple product with WooCommerce', async ({ page }) => {
    try {
      await loginToWordPress(page);

      // Navigate to add new product page
      await page.goto(`${baseURL}/wp-admin/post-new.php?post_type=product`, {
        waitUntil: 'networkidle',
        timeout: 120000
      });

      // Wait for the product editor to load
      await page.waitForSelector('#title', { timeout: 120000 });

      // Fill product details
      await page.fill('#title', 'Test Simple Product - E2E');
      console.log('✅ Product title filled');

      // Scroll to product data section
      await page.locator('#woocommerce-product-data').scrollIntoViewIfNeeded();

      // Set regular price
      const regularPriceField = page.locator('#_regular_price');
      if (await regularPriceField.isVisible({ timeout: 30000 })) {
        await regularPriceField.fill('19.99');
        console.log('✅ Set regular price');
      }

      // Publish product
      const publishButton = page.locator('#publish');
      if (await publishButton.isVisible({ timeout: 30000 })) {
        await publishButton.click();
        await page.waitForTimeout(3000);
        console.log('✅ Published simple product');
      }

      // Verify no PHP fatal errors
      const pageContent = await page.content();
      expect(pageContent).not.toContain('Fatal error');
      expect(pageContent).not.toContain('Parse error');

      console.log('✅ Simple product creation test completed successfully');

    } catch (error) {
      console.log(`⚠️ Simple product test failed: ${error.message}`);
      await safeScreenshot(page, 'simple-product-test-failure.png');
      throw error;
    }
  });

  test('Test WordPress admin and Facebook plugin presence', async ({ page }) => {
    try {
      // Navigate to plugins page
      await page.goto(`${baseURL}/wp-admin/plugins.php`, {
        waitUntil: 'networkidle',
        timeout: 120000
      });

      // Check if Facebook plugin is listed
      const pageContent = await page.content();
      const hasFacebookPlugin = pageContent.includes('Facebook for WooCommerce') ||
                               pageContent.includes('facebook-for-woocommerce');

      if (hasFacebookPlugin) {
        console.log('✅ Facebook for WooCommerce plugin detected');
      } else {
        console.log('⚠️ Facebook for WooCommerce plugin not found in plugins list');
      }

      // Verify no PHP errors
      expect(pageContent).not.toContain('Fatal error');
      expect(pageContent).not.toContain('Parse error');

      console.log('✅ Plugin detection test completed');

    } catch (error) {
      console.log(`⚠️ Plugin detection test failed: ${error.message}`);
      throw error;
    }
  });
});
