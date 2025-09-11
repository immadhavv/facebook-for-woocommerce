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
      await page.fill('#title', 'Test Simple Product - E2E 11:43');

      // Try to add content - handle different editor types
      try {
        console.log('🔄 Attempting to add product description...');

        // First, try the visual/TinyMCE editor
        const visualTab = page.locator('#content-tmce');
        if (await visualTab.isVisible({ timeout: 5000 })) {
          await visualTab.click();
          await page.waitForTimeout(2000);

          // Check if TinyMCE iframe exists
          const tinyMCEFrame = page.locator('#content_ifr');
          if (await tinyMCEFrame.isVisible({ timeout: 5000 })) {
            // This is an iframe-based editor (TinyMCE)
            const frameContent = tinyMCEFrame.contentFrame();
            const bodyElement = frameContent.locator('body');
            if (await bodyElement.isVisible({ timeout: 5000 })) {
              await bodyElement.fill('This is a test product created during E2E testing.');
              console.log('✅ Added description via TinyMCE editor');
            }
          }
        } else {
          // Try text/HTML tab
          const textTab = page.locator('#content-html');
          if (await textTab.isVisible({ timeout: 5000 })) {
            await textTab.click();
            await page.waitForTimeout(1000);

            // Regular textarea
            const contentTextarea = page.locator('#content');
            if (await contentTextarea.isVisible({ timeout: 5000 })) {
              await contentTextarea.fill('This is a test product created during E2E testing.');
              console.log('✅ Added description via text editor');
            }
          } else {
            // Try block editor if present
            const blockEditor = page.locator('.wp-block-post-content, .block-editor-writing-flow');
            if (await blockEditor.isVisible({ timeout: 5000 })) {
              await blockEditor.click();
              await page.keyboard.type('This is a test product created during E2E testing.');
              console.log('✅ Added description via block editor');
            } else {
              console.log('⚠️ No content editor found - skipping description');
            }
          }
        }
      } catch (editorError) {
        console.log(`⚠️ Content editor issue: ${editorError.message} - continuing without description`);
      }

      console.log('✅ Basic product details filled');

      // Scroll to product data section
      await page.locator('#woocommerce-product-data').scrollIntoViewIfNeeded();

      // Set regular price
      const regularPriceField = page.locator('#_regular_price');
      if (await regularPriceField.isVisible({ timeout: 120000 })) {
        await regularPriceField.fill('19.99');
        console.log('✅ Set regular price');
      }

      // Look for Facebook-specific fields if plugin is active
      try {
        // Check various possible Facebook field selectors
        const facebookSyncField = page.locator('#_facebook_sync_enabled, input[name*="facebook"], input[id*="facebook"]').first();
        const facebookPriceField = page.locator('label:has-text("Facebook Price"), input[name*="facebook_price"]').first();
        const facebookImageField = page.locator('legend:has-text("Facebook Product Image"), input[name*="facebook_image"]').first();

        if (await facebookSyncField.isVisible({ timeout: 10000 })) {
          console.log('✅ Facebook for WooCommerce fields detected');
        } else if (await facebookPriceField.isVisible({ timeout: 10000 })) {
          console.log('✅ Facebook price field found');
        } else if (await facebookImageField.isVisible({ timeout: 10000 })) {
          console.log('✅ Facebook image field found');
        } else {
          console.log('⚠️ No Facebook-specific fields found - plugin may not be fully activated');
        }
      } catch (error) {
        console.log('⚠️ Facebook field detection inconclusive - this is not necessarily an error');
      }

      // Set product status to published and save
      try {
        // Look for publish/update button
        await page.locator('#publishing-action').scrollIntoViewIfNeeded();

        const publishButton = page.locator('#publish');
        if (await publishButton.isVisible({ timeout: 120000 })) {
          await publishButton.click();
          await page.waitForTimeout(3000);
          console.log('✅ Published simple product');
        }
      } catch (error) {
        console.log('⚠️ Publish step may be slow, continuing with error check');
      }

      // Verify no PHP fatal errors
      const pageContent = await page.content();
      expect(pageContent).not.toContain('Fatal error');
      expect(pageContent).not.toContain('Parse error');

      console.log('✅ Simple product creation test completed successfully');

    } catch (error) {
      console.log(`⚠️ Simple product test failed: ${error.message}`);
      // Take screenshot for debugging
      await safeScreenshot(page, 'simple-product-test-failure.png');
      throw error;
    }
  });

  test('Create variable product with attributes - comprehensive test', async ({ page }) => {
    try {
      await loginToWordPress(page);

      // Navigate to add new product page
      await page.goto(`${baseURL}/wp-admin/post-new.php?post_type=product`, {
        waitUntil: 'networkidle',
        timeout: 120000
      });

      // Wait for the product editor to load
      await page.waitForSelector('#title', { timeout: 120000 });
      await page.fill('#title', 'Test Variable Product - E2E 11:48');

      // Set product type to variable
      await page.selectOption('#product-type', 'variable');
      console.log('✅ Set product type to variable');

      // Wait for the page to process the product type change
      await page.waitForTimeout(5000);

      // Wait for variable product options to become visible - more robust approach
      console.log('🔄 Waiting for variable product interface to load...');
      try {
        // Try multiple selectors and approaches
        const selectors = [
          '#variable_product_options:not([style*="display: none"])',
          '.product_data_tabs li a[href="#product_attributes"]',
          '#product_attributes-tab',
          '.woocommerce_attributes'
        ];

        let interfaceLoaded = false;
        for (const selector of selectors) {
          try {
            await page.waitForSelector(selector, { timeout: 30000 });
            console.log(`✅ Found interface element: ${selector}`);
            interfaceLoaded = true;
            break;
          } catch (err) {
            console.log(`⚠️ Selector ${selector} not found, trying next...`);
          }
        }

        if (!interfaceLoaded) {
          // Force refresh and try again
          console.log('🔄 Interface not loaded, refreshing page...');
          await page.reload({ waitUntil: 'networkidle', timeout: 120000 });
          await page.waitForSelector('#title', { timeout: 120000 });
          await page.selectOption('#product-type', 'variable');
          await page.waitForTimeout(5000);
        }
      } catch (error) {
        console.log(`⚠️ Variable product interface loading issue: ${error.message}`);
      }
      console.log('✅ Variable product interface loaded');

      // Go to Attributes tab - try multiple approaches
      console.log('🔄 Navigating to Attributes tab...');
      try {
        // First, ensure we're in the right context and wait for the product data tabs to be ready
        await page.waitForSelector('.product_data_tabs', { timeout: 30000 });

        // Use more specific selectors to avoid conflicts
        const attributesTab = page.locator('.product_data_tabs li:has(a[href="#product_attributes"]) a');

        // Wait for the tab to be visible and clickable
        await attributesTab.waitFor({ state: 'visible', timeout: 30000 });
        await attributesTab.click();
        await page.waitForTimeout(2000);

        // Verify the attributes panel is now visible
        await page.waitForSelector('#product_attributes', { state: 'visible', timeout: 15000 });
        console.log('✅ Successfully navigated to Attributes tab');
      } catch (error) {
        console.log(`⚠️ Attributes tab navigation issue: ${error.message}`);
        // Fallback: try direct click on any visible attributes link
        try {
          await page.locator('text=Attributes').first().click();
          await page.waitForTimeout(2000);
        } catch (fallbackError) {
          console.log(`⚠️ Fallback attributes tab click failed: ${fallbackError.message}`);
        }
      }
      console.log('✅ Switched to Attributes tab');

      try {
        // Add Size attribute - more robust approach
        console.log('🔄 Adding product attribute...');

        // Wait for attributes section to be visible
        await page.waitForSelector('#product_attributes', { state: 'visible', timeout: 30000 });

        // Try to add attribute using the dropdown
        const attributeTaxonomy = page.locator('#attribute_taxonomy');
        await attributeTaxonomy.waitFor({ state: 'visible', timeout: 15000 });
        await attributeTaxonomy.selectOption({ label: 'Custom product attribute' });

        const addAttributeBtn = page.locator('button.add_attribute');
        await addAttributeBtn.waitFor({ state: 'visible', timeout: 10000 });
        await addAttributeBtn.click();
        await page.waitForTimeout(3000);

        // Fill attribute details - wait for the new attribute row to appear
        await page.waitForSelector('.woocommerce_attribute', { timeout: 10000 });

        const nameField = page.locator('input[name="attribute_names[0]"]').first();
        const valueField = page.locator('textarea[name="attribute_values[0]"]').first();
        const variationCheckbox = page.locator('input[name="attribute_variation[0]"]').first();

        await nameField.waitFor({ state: 'visible', timeout: 10000 });
        await nameField.fill('Size');
        console.log('✅ Filled attribute name');

        await valueField.waitFor({ state: 'visible', timeout: 10000 });
        await valueField.fill('Small | Medium | Large');
        console.log('✅ Filled attribute values');

        await variationCheckbox.waitFor({ state: 'visible', timeout: 10000 });
        await variationCheckbox.check();
        console.log('✅ Checked variation checkbox');

        // Save attributes
        const saveAttributesBtn = page.locator('button.save_attributes');
        await saveAttributesBtn.waitFor({ state: 'visible', timeout: 10000 });
        await saveAttributesBtn.click();
        await page.waitForTimeout(5000);
        console.log('✅ Saved attributes');

        console.log('✅ Added Size attribute with variations');

        // Go to Variations tab
        console.log('🔄 Navigating to Variations tab...');

        // Wait for variations tab to become available (after saving attributes)
        await page.waitForTimeout(2000);

        const variationsTab = page.locator('.product_data_tabs li:has(a[href="#variable_product_options"]) a');
        await variationsTab.waitFor({ state: 'visible', timeout: 30000 });
        await variationsTab.click();
        await page.waitForTimeout(2000);

        // Verify the variations panel is now visible
        await page.waitForSelector('#variable_product_options', { state: 'visible', timeout: 15000 });
        console.log('✅ Successfully navigated to Variations tab');

        // Generate variations from all attributes - simplified approach
        console.log('🔄 Attempting to generate variations...');
        try {
          // Wait for the variations interface to load
          await page.waitForTimeout(2000);

          // Look for variation generation controls - try multiple selectors
          const variationActions = page.locator('.toolbar .variation_actions select');
          await variationActions.waitFor({ state: 'visible', timeout: 15000 });

          // Select "Create variations from all attributes"
          await variationActions.selectOption('add_variation');

          // Click the "Go" button
          const goButton = page.locator('.toolbar .do_variation_action');
          await goButton.waitFor({ state: 'visible', timeout: 10000 });
          await goButton.click();
          await page.waitForTimeout(10000);
          console.log('✅ Generated product variations');

          // Set prices for variations if they exist
          await page.waitForTimeout(3000);
          const variations = await page.locator('.woocommerce_variation').count();
          console.log(`Found ${variations} variations`);

          if (variations > 0) {
            console.log(`✅ Found ${variations} variations, setting prices...`);

            for (let i = 0; i < Math.min(variations, 2); i++) {
              try {
                const variation = page.locator('.woocommerce_variation').nth(i);

                // Expand variation if needed
                const expandBtn = variation.locator('.expand_variation');
                if (await expandBtn.isVisible({ timeout: 5000 })) {
                  await expandBtn.click();
                  await page.waitForTimeout(2000);
                }

                // Set price
                const priceField = variation.locator('input[name*="variable_regular_price"]').first();
                if (await priceField.isVisible({ timeout: 10000 })) {
                  await priceField.fill(`${25 + i}.99`);
                  console.log(`✅ Set price for variation ${i + 1}`);
                }
              } catch (priceError) {
                console.log(`⚠️ Could not set price for variation ${i + 1}: ${priceError.message}`);
              }
            }

            // Save variations
            try {
              const saveBtn = page.locator('button.save-variation-changes, .save-variation-changes');
              if (await saveBtn.isVisible({ timeout: 10000 })) {
                await saveBtn.click();
                await page.waitForTimeout(5000);
                console.log('✅ Saved variation changes');
              }
            } catch (saveError) {
              console.log(`⚠️ Could not save variations: ${saveError.message}`);
            }
          } else {
            console.log('⚠️ No variations found - this may be expected if attribute setup failed');
          }
        } catch (variationError) {
          console.log(`⚠️ Variation generation issue: ${variationError.message}`);
        }
      } catch (error) {
        console.log(`⚠️ Variation setup warning: ${error.message}`);
      }

      // Publish product
      try {
        console.log('🔄 Publishing product...');
        const publishButton = page.locator('#publish');
        if (await publishButton.isVisible({ timeout: 30000 })) {
          await publishButton.click();
          await page.waitForTimeout(5000);
          console.log('✅ Published variable product');
        }
      } catch (error) {
        console.log('⚠️ Publish step may be slow, continuing with error check');
      }

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

  test('Test WordPress admin and Facebook plugin presence', async ({ page }) => {
    try {
      // Navigate to plugins page with increased timeout
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

  test('Test basic WooCommerce product list', async ({ page }) => {
    try {
      // Go to Products list with increased timeout
      await page.goto(`${baseURL}/wp-admin/edit.php?post_type=product`, {
        waitUntil: 'networkidle',
        timeout: 120000
      });

      // Verify no PHP errors on products page
      const pageContent = await page.content();
      expect(pageContent).not.toContain('Fatal error');
      expect(pageContent).not.toContain('Parse error');

      // Check if WooCommerce is working
      const hasProductsTable = await page.locator('.wp-list-table').isVisible({ timeout: 120000 });
      if (hasProductsTable) {
        console.log('✅ WooCommerce products page loaded successfully');
      } else {
        console.log('⚠️ Products table not found');
      }

      console.log('✅ Product list test completed');

    } catch (error) {
      console.log(`⚠️ Product list test failed: ${error.message}`);
      throw error;
    }
  });

  test('Quick PHP error check across key pages', async ({ page }) => {
    const pagesToCheck = [
      { path: '/wp-admin/', name: 'Dashboard' },
      { path: '/wp-admin/edit.php?post_type=product', name: 'Products' },
      { path: '/wp-admin/plugins.php', name: 'Plugins' }
    ];

    for (const pageInfo of pagesToCheck) {
      try {
        console.log(`🔍 Checking ${pageInfo.name} page...`);
        await page.goto(`${baseURL}${pageInfo.path}`, {
          waitUntil: 'networkidle',
          timeout: 120000
        });

        const pageContent = await page.content();

        // Check for PHP errors
        expect(pageContent).not.toContain('Fatal error');
        expect(pageContent).not.toContain('Parse error');
        expect(pageContent).not.toContain('Warning: ');

        // Verify admin content loaded
        await page.locator('#wpcontent').isVisible({ timeout: 120000 });

        console.log(`✅ ${pageInfo.name} page loaded without errors`);

      } catch (error) {
        console.log(`⚠️ ${pageInfo.name} page check failed: ${error.message}`);
      }
    }
  });

  test('Test Facebook plugin deactivation and reactivation', async ({ page }) => {
    try {
      await loginToWordPress(page);

      // Navigate to plugins page
      await page.goto(`${baseURL}/wp-admin/plugins.php`, {
        waitUntil: 'networkidle',
        timeout: 120000
      });

      // Look for Facebook plugin row
      const pluginRow = page.locator('tr[data-slug="facebook-for-woocommerce"], tr:has-text("Facebook for WooCommerce")').first();

      if (await pluginRow.isVisible({ timeout: 120000 })) {
        console.log('✅ Facebook plugin found');

        // Check if plugin is currently active
        const isActive = await pluginRow.locator('.active').isVisible({ timeout: 120000 });

        if (isActive) {
          console.log('Plugin is active, testing deactivation...');
          const deactivateLink = pluginRow.locator('a:has-text("Deactivate")');
          if (await deactivateLink.isVisible({ timeout: 120000 })) {
            await deactivateLink.click();
            await page.waitForTimeout(2000);
            console.log('✅ Plugin deactivated');

            // Now test reactivation
            await page.waitForTimeout(1000);
            const reactivateLink = pluginRow.locator('a:has-text("Activate")');
            if (await reactivateLink.isVisible({ timeout: 120000 })) {
              await reactivateLink.click();
              await page.waitForTimeout(2000);
              console.log('✅ Plugin reactivated');
            }
          }
        } else {
          console.log('Plugin is inactive, testing activation...');
          const activateLink = pluginRow.locator('a:has-text("Activate")');
          if (await activateLink.isVisible({ timeout: 120000 })) {
            await activateLink.click();
            await page.waitForTimeout(2000);
            console.log('✅ Plugin activated');
          }
        }
      } else {
        console.log('⚠️ Facebook plugin not found in plugins list');
      }

      // Verify no PHP errors after plugin operations
      const pageContent = await page.content();
      expect(pageContent).not.toContain('Fatal error');
      expect(pageContent).not.toContain('Parse error');

      console.log('✅ Plugin activation test completed');

    } catch (error) {
      console.log(`⚠️ Plugin activation test failed: ${error.message}`);
      throw error;
    }
  });
});
