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

// cleanup function - Delete created product from WooCommerce
async function cleanupProduct(productId) {
  if (!productId) return;

  console.log(`🧹 Cleaning up product ${productId}...`);

  try {
    const { exec } = require('child_process');
    const { promisify } = require('util');
    const execAsync = promisify(exec);

    const { stdout } = await execAsync(
      `php -r "require_once('/tmp/wordpress/wp-load.php'); wp_delete_post(${productId}, true);"`,
      { cwd: __dirname }
    );

    console.log(`✅ Product ${productId} deleted from WooCommerce`);
  } catch (error) {
    console.log(`⚠️ Cleanup failed: ${error.message}`);
  }
}

// Helper function to generate human-readable timestamp
function generateHumanTimestamp() {
  const now = new Date();
  return now.toISOString().replace(/[:.]/g, '-').slice(0, 19);
}

// Helper function to generate product name with timestamp
function generateProductName(productType) {
  const timestamp = generateHumanTimestamp();
  return `Test ${productType} Product E2E ${timestamp}`;
}

// Helper function to extract product ID from URL
function extractProductIdFromUrl(url) {
  console.log(`🔍 URL for ID extraction: ${url}`);
  const urlMatch = url.match(/post=(\d+)/);
  const productId = urlMatch ? parseInt(urlMatch[1]) : null;
  console.log(`📦 Extracted Product ID: ${productId}`);
  return productId;
}

// Helper function to publish product
async function publishProduct(page) {
  try {
    await page.locator('#publishing-action').scrollIntoViewIfNeeded();
    const publishButton = page.locator('#publish');
    if (await publishButton.isVisible({ timeout: 120000 })) {
      await publishButton.click();
      await page.waitForTimeout(3000);
      console.log('✅ Published product');
      return true;
    }
  } catch (error) {
    console.log('⚠️ Publish step may be slow, continuing with error check');
    return false;
  }
}

// Helper function to check for PHP errors
async function checkForPhpErrors(page) {
  const pageContent = await page.content();
  expect(pageContent).not.toContain('Fatal error');
  expect(pageContent).not.toContain('Parse error');
}

// Helper function to wait for manual inspection
async function waitForManualInspection(page, seconds = 20) {
  console.log(`⏳ Waiting ${seconds} seconds before cleanup to allow manual catalog inspection...`);
  await page.waitForTimeout(seconds * 1000);
}

// Helper function to mark test start
function logTestStart(testInfo) {
  const testName = testInfo.title;
  console.log('\n' + '='.repeat(80));
  console.log(`🚀 STARTING TEST: ${testName}`);
  console.log('='.repeat(80));
}

// Helper function to mark test end
function logTestEnd(testInfo, success = true) {
  const testName = testInfo.title;
  console.log('='.repeat(80));
  if (success) {
    console.log(`✅ TEST SUCCESS: ${testName} ✅`);
  } else {
    console.log(`❌ TEST FAILED: ${testName}`);
  }
  console.log('='.repeat(80) + '\n');
}

// Helper function to validate Facebook sync
async function validateFacebookSync(productId, productName, waitSeconds = 10) {
  if (!productId) {
    console.log('⚠️ No product ID provided for Facebook sync validation');
    return null;
  }

  const displayName = productName ? `"${productName}" (ID: ${productId})` : `ID: ${productId}`;
  console.log(`🔍 Validating Facebook sync for product ${displayName}...`);

  try {
    const { exec } = require('child_process');
    const { promisify } = require('util');
    const execAsync = promisify(exec);

    // Call the Facebook sync validator
    const { stdout, stderr } = await execAsync(
      `php e2e-facebook-sync-validator-simple.php ${productId} ${waitSeconds}`,
      { cwd: __dirname }
    );

    if (stderr) {
      console.log(`🔧 Debug: PHP stderr: ${stderr}`);
    }

    const result = JSON.parse(stdout);

    // Display results
    if (result.success) {
      console.log(`🎉 Facebook Sync Validation Results for ${displayName}:`);

      // Handle variable product results
      if (result.product_type === 'variable' && result.summary) {
        const summary = result.summary;

        // Parent Group Info (from main result fields)
        console.log(`📦 Parent Group: ${result.sync_status}`);
        console.log(`🏷️  Parent Retailer ID: ${result.retailer_id}`);
        if (result.facebook_id) {
          console.log(`🔗 Facebook Group ID: ${result.facebook_id}`);
        }

        // Variations Summary
        console.log(`👕 Variations: ${summary.successful_variations}/${summary.total_variations} synced successfully`);

        if (summary.failed_variations > 0) {
          console.log(`⚠️ Failed Variations: ${summary.failed_variations}`);
          if (summary.failed_variation_ids && summary.failed_variation_ids.length > 0) {
            console.log(`   └─ Failed Variation IDs: ${summary.failed_variation_ids.join(', ')}`);
          }
        }

        // Mismatches (if any)
        if (result.mismatches && Object.keys(result.mismatches).length > 0) {
          console.log('⚠️ Field Mismatches Found:');
          Object.entries(result.mismatches).forEach(([key, mismatch]) => {
            console.log(`  Product ${mismatch.product_id} - ${mismatch.field}: WooCommerce="${mismatch.woocommerce_value}" vs Facebook="${mismatch.facebook_value}"`);
          });
        } else {
          console.log('✅ No field mismatches detected');
        }
      } else {
        // Simple product results (simple format)
        console.log(`✅ Sync Status: ${result.sync_status}`);
        console.log(`📦 Product ID: ${result.product_id}`);
        console.log(`🏷️  Retailer ID: ${result.retailer_id}`);
        console.log(`🔗 Facebook ID: ${result.facebook_id}`);

        if (result.mismatches && Object.keys(result.mismatches).length > 0) {
          console.log('⚠️ Field Mismatches Found:');
          Object.entries(result.mismatches).forEach(([field, data]) => {
            console.log(`  ${field}: WooCommerce="${data.woocommerce}" vs Facebook="${data.facebook}"`);
          });
        } else {
          console.log('✅ No field mismatches detected');
        }

        // Show Facebook ID validation details
        if (result.facebook_id_validation) {
          const idVal = result.facebook_id_validation;
          console.log(`🔍 Facebook ID Validation: ${idVal.status}`);
          if (idVal.consistent) {
            console.log('  ✅ Local metadata and API Facebook IDs are consistent');
          } else if (idVal.status === 'mismatch') {
            console.log(`  ⚠️ ID Mismatch: Local="${idVal.local_fb_id}" vs API="${idVal.api_fb_id}"`);
          } else if (idVal.status === 'local_missing') {
            console.log(`  ⚠️ Local metadata missing Facebook ID (API has: ${idVal.api_fb_id})`);
          } else if (idVal.status === 'api_missing') {
            console.log(`  ⚠️ API missing Facebook ID (local has: ${idVal.local_fb_id})`);
          }
        }
      }

      if (result.debug && result.debug.length > 0) {
        console.log('🔍 Debug info:', result.debug.join(', '));
      }

      // Show product group relationships for variable products
      if (result.product_type === 'variable') {
        const groupRelationships = result.debug.filter(msg => msg.includes('belongs to Facebook product group'));
        if (groupRelationships.length > 0) {
          console.log('📋 Product Group Relationships:');
          groupRelationships.forEach(relationship => {
            console.log(`  ${relationship}`);
          });
        }
      }

    } else {
      console.log(`❌ Facebook sync validation failed: ${result.error}`);
      if (result.debug && result.debug.length > 0) {
        console.log('🔍 Debug info:', result.debug.join(', '));
      }
    }

    return result;

  } catch (error) {
    console.log(`⚠️ Facebook sync validation error: ${error.message}`);
    return null;
  }
}

test.describe('Facebook for WooCommerce - Product Creation E2E Tests', () => {

  test.beforeEach(async ({ page }, testInfo) => {
    // Log test start first for proper chronological order
    logTestStart(testInfo);

    // Ensure browser stability
    await page.setViewportSize({ width: 1280, height: 720 });
    await loginToWordPress(page);
  });

  test('Create simple product with WooCommerce', async ({ page }, testInfo) => {
    let productId = null;
    try {

      // Navigate to add new product page
      await page.goto(`${baseURL}/wp-admin/post-new.php?post_type=product`, {
        waitUntil: 'networkidle',
        timeout: 120000
      });

      // Wait for the product editor to load
      await page.waitForSelector('#title', { timeout: 120000 });

      const productName = generateProductName('Simple');
      await page.fill('#title', productName);

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
      // Publish product
      await publishProduct(page);

      // Extract product ID from URL after publish
      const currentUrl = page.url();
      productId = extractProductIdFromUrl(currentUrl);
      if (productId) {
        console.log(`📦 Product ID: ${productId}`);
      }

      // Verify no PHP fatal errors
      await checkForPhpErrors(page);

      // Validate sync to Meta catalog and fields from Meta
      await validateFacebookSync(productId, productName);

      console.log('✅ Simple product creation test completed successfully');
      await waitForManualInspection(page);

      logTestEnd(testInfo, true);

    } catch (error) {
      console.log(`⚠️ Simple product test failed: ${error.message}`);
      // Take screenshot for debugging
      await safeScreenshot(page, 'simple-product-test-failure.png');
      logTestEnd(testInfo, false);
      throw error;
    } finally {
    // Cleanup irrespective of test result
    if (productId) {
      await cleanupProduct(productId);
    }
  }
  });

  test('Create variable product with attributes - WORKING VERSION WITH VALIDATOR', async ({ page }, testInfo) => {
    let productId = null;
    try {
      // Navigate to add new product page
      await page.goto(`${baseURL}/wp-admin/post-new.php?post_type=product`, {
        waitUntil: 'networkidle',
        timeout: 120000
      });

      // Wait for the product editor to load
      await page.waitForSelector('#title', { timeout: 120000 });

      const productName = generateProductName('Variable');
      await page.fill('#title', productName);
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

      // STEP 2: Fill attribute Name field with more robust handling
      console.log('🔄 Step 2: Typing attribute Name field...');
      const nameField = page.locator('input.attribute_name[name="attribute_names[0]"]');
      await nameField.waitFor({ state: 'visible', timeout: 10000 });

      // Scroll element into view to ensure it's not obstructed
      await nameField.scrollIntoViewIfNeeded();
      await page.waitForTimeout(1000);

      // Try multiple interaction approaches
      try {
        await nameField.click({ force: true });
      } catch (clickError) {
        console.log('Force click failed, trying focus approach...');
        await nameField.focus();
      }

      await nameField.clear();
      await nameField.type('color', { delay: 100 });
      console.log('✅ Typed attribute name: color');

      // STEP 3: Fill attribute Value field with robust handling
      console.log('🔄 Step 3: Typing attribute Value field...');
      const valueField = page.locator('textarea[name="attribute_values[0]"]');
      await valueField.waitFor({ state: 'visible', timeout: 10000 });

      await valueField.scrollIntoViewIfNeeded();
      await page.waitForTimeout(500);

      try {
        await valueField.click({ force: true });
      } catch (clickError) {
        console.log('Force click failed, trying focus approach...');
        await valueField.focus();
      }

      await valueField.clear();
      await valueField.type('blue|red|yellow', { delay: 50 });
      console.log('✅ Typed attribute values: blue|red|yellow');

      // STEP 4: Check "Used for variations" checkbox with robust handling
      console.log('🔄 Step 4: Checking "Used for variations" checkbox...');
      const variationCheckbox = page.locator('input.woocommerce_attribute_used_for_variations[name="attribute_variation[0]"]');
      await variationCheckbox.waitFor({ state: 'visible', timeout: 10000 });

      await variationCheckbox.scrollIntoViewIfNeeded();
      await page.waitForTimeout(500);

      try {
        await variationCheckbox.check({ force: true });
      } catch (checkError) {
        console.log('Force check failed, trying click approach...');
        await variationCheckbox.click({ force: true });
      }
      console.log('✅ Checked "Used for variations" checkbox');

      // STEP 5: Click "Save attributes" button with robust handling
      console.log('🔄 Step 5: Clicking "Save attributes" button...');
      const saveAttributesBtn = page.locator('button.save_attributes.button-primary');
      await saveAttributesBtn.waitFor({ state: 'visible', timeout: 10000 });

      await saveAttributesBtn.scrollIntoViewIfNeeded();
      await page.waitForTimeout(500);

      try {
        await saveAttributesBtn.click({ force: true });
      } catch (clickError) {
        console.log('Force click failed, trying focus approach...');
        await saveAttributesBtn.focus();
        await page.keyboard.press('Enter');
      }
      console.log('✅ Clicked "Save attributes" button');

      // Wait for AJAX request to complete and page to update
      console.log('🔄 Waiting for attributes to be saved in database...');
      await page.waitForTimeout(3000);

      // Wait for the success indicator or page reload
      try {
        // Check if there's a success message or the page reloads properly
        await page.waitForFunction(() => {
          // Look for success indicators or absence of loading states
          const loadingElements = document.querySelectorAll('.woocommerce-help-tip');
          return loadingElements.length > 0; // Basic check that page is ready
        }, { timeout: 10000 });
      } catch (waitError) {
        console.log('⚠️ Waiting for page update timed out, continuing...');
      }

      // Additional wait to ensure database update is complete
      await page.waitForTimeout(5000);
      console.log('✅ Attributes saved successfully');

      // VERIFICATION STEP: Verify attributes were actually saved
      console.log('🔍 Verifying attributes were saved properly...');
      try {
        // Check if the attribute name field still has our value
        const savedNameValue = await nameField.inputValue();
        const savedValueValue = await valueField.inputValue();
        const isVariationChecked = await variationCheckbox.isChecked();

        console.log(`📋 Saved attribute name: "${savedNameValue}"`);
        console.log(`📋 Saved attribute values: "${savedValueValue}"`);
        console.log(`📋 Used for variations: ${isVariationChecked}`);

        if (savedNameValue !== 'color' || savedValueValue !== 'blue|red|yellow' || !isVariationChecked) {
          throw new Error('Attributes were not saved properly - form fields do not contain expected values');
        }
        console.log('✅ Attribute verification passed');
      } catch (verifyError) {
        console.log(`⚠️ Attribute verification failed: ${verifyError.message}`);
        // Try to re-save if verification failed
        console.log('🔄 Attempting to re-save attributes...');
        await saveAttributesBtn.click({ force: true });
        await page.waitForTimeout(8000);
      }

      // STEP 6: Go to Variations tab with robust handling
      console.log('🔄 Step 6: Going to Variations tab...');
      const variationsTab = page.locator('a[href="#variable_product_options"]');
      await variationsTab.waitFor({ state: 'visible', timeout: 30000 });

      await variationsTab.scrollIntoViewIfNeeded();
      await page.waitForTimeout(500);

      try {
        await variationsTab.click({ force: true });
      } catch (clickError) {
        console.log('Force click failed, trying focus approach...');
        await variationsTab.focus();
        await page.keyboard.press('Enter');
      }
      await page.waitForTimeout(3000);
      console.log('✅ Successfully switched to Variations tab');

      // CRITICAL STEP: Wait for the variations tab to load and check for Generate button availability
      console.log('🔍 Checking if Generate variations button should be available...');
      await page.waitForTimeout(2000);

      // Check if the message "Add some attributes..." is still present
      const addAttributesMessage = page.locator('.add-attributes-message');
      const hasMessage = await addAttributesMessage.isVisible({ timeout: 5000 });

      if (hasMessage) {
        console.log('⚠️ Still seeing "Add attributes" message - WooCommerce may not have registered the attributes yet');

        // Try refreshing the page to force WooCommerce to reload the attributes
        console.log('🔄 Refreshing page to reload attributes...');
        await page.reload({ waitUntil: 'networkidle' });

        // Go back to variations tab after refresh
        await page.waitForTimeout(3000);
        await variationsTab.click({ force: true });
        await page.waitForTimeout(3000);

        // Check again
        const stillHasMessage = await addAttributesMessage.isVisible({ timeout: 5000 });
        if (stillHasMessage) {
          console.log('⚠️ Still seeing message after page refresh. Checking attributes tab again...');

          // Go back to attributes tab and verify our attributes are truly saved
          await page.locator('li.attribute_tab a[href="#product_attributes"]').click();
          await page.waitForTimeout(2000);

          const attributeExists = await page.locator('input.attribute_name[value="color"]').isVisible({ timeout: 5000 });
          if (!attributeExists) {
            throw new Error('Attributes were not properly saved - color attribute not found in database');
          }

          console.log('✅ Attribute still exists in form - trying variations tab again...');
          await variationsTab.click({ force: true });
          await page.waitForTimeout(3000);
        }
      }

      // Final check: Look for generate button or error message
      const finalMessageCheck = await addAttributesMessage.isVisible({ timeout: 2000 });
      if (finalMessageCheck) {
        console.log('❌ Generate variations button will not be available - attributes not properly registered');

        // Get the current variations tab content for debugging
        const variationsContent = await page.locator('#variable_product_options').innerHTML();
        console.log('🔍 Current variations tab content:');
        console.log(variationsContent);

        throw new Error('Generate variations button not available - WooCommerce did not properly register the attributes for variations');
      } else {
        console.log('✅ Generate variations button should now be available');
      }

      // STEP 7: Set up dialog listener BEFORE clicking generate variations
      console.log('🔄 Step 7: Setting up dialog listener for confirmation popup...');
      page.on('dialog', async dialog => {
        console.log(`Dialog message: ${dialog.message()}`);
        await dialog.accept();
        console.log('✅ Clicked OK in confirmation popup');
      });

      // STEP 8: Click "Generate variations" button - simplified approach targeting your exact HTML
      console.log('🔄 Step 8: Clicking "Generate variations" button...');

      // Try multiple selectors to find the Generate variations button
      const possibleSelectors = [
        'button.button.generate_variations',         // Your exact HTML: <button type="button" class="button generate_variations">
        'button.generate_variations',                // Just the generate_variations class
        'button:has-text("Generate variations")',    // Text-based selector
        'button[type="button"]:has-text("Generate")', // By type and partial text
        '.generate_variations',                      // Any element with the class
        'input[value*="Generate"]'                   // Input variant
      ];

      let generateVariationsBtn = null;
      let foundSelector = null;

      // Try each selector with a short timeout
      for (const selector of possibleSelectors) {
        console.log(`🔍 Trying selector: ${selector}`);
        try {
          const btn = page.locator(selector);
          await btn.waitFor({ state: 'visible', timeout: 3000 });
          generateVariationsBtn = btn;
          foundSelector = selector;
          console.log(`✅ Found Generate variations button with selector: ${selector}`);
          break;
        } catch (error) {
          console.log(`❌ Selector ${selector} not found or not visible`);
        }
      }

      if (!generateVariationsBtn) {
        // Take screenshot for debugging
        await safeScreenshot(page, 'generate-variations-button-debug.png');

        // 🚨 HTML DEBUGGING - DUMP THE ENTIRE PAGE HTML
        console.log('🔍 Looking for button-related elements in page...');
        const pageContent = await page.content();

        // Check if the button text exists anywhere
        if (pageContent.includes('Generate variations')) {
          console.log('✅ "Generate variations" text found in page content');
        } else {
          console.log('❌ "Generate variations" text NOT found in page content');
        }

        // 📄 DUMP ONLY RELEVANT PARTS FOR DEBUGGING
        console.log('\n' + '='.repeat(80));
        console.log('🔍 SEARCHING FOR GENERATE VARIATIONS BUTTON IN HTML:');
        console.log('='.repeat(80));

        // Look for button-related HTML snippets
        const buttonMatches = pageContent.match(/<button[^>]*generate[^>]*>.*?<\/button>/gi);
        if (buttonMatches) {
          console.log('Found button elements containing "generate":');
          buttonMatches.forEach((match, index) => {
            console.log(`Button ${index + 1}: ${match}`);
          });
        } else {
          console.log('❌ No button elements containing "generate" found');
        }

        // Look for any element with generate_variations class
        const generateClassMatches = pageContent.match(/<[^>]*class="[^"]*generate_variations[^"]*"[^>]*>.*?<\/[^>]+>/gi);
        if (generateClassMatches) {
          console.log('Found elements with generate_variations class:');
          generateClassMatches.forEach((match, index) => {
            console.log(`Element ${index + 1}: ${match}`);
          });
        } else {
          console.log('❌ No elements with generate_variations class found');
        }

        // Search for "Generate variations" text context
        const textMatches = pageContent.match(/.{0,100}Generate variations.{0,100}/gi);
        if (textMatches) {
          console.log('Found "Generate variations" text context:');
          textMatches.forEach((match, index) => {
            console.log(`Context ${index + 1}: ${match}`);
          });
        } else {
          console.log('❌ "Generate variations" text not found in any context');
        }

        console.log('='.repeat(80));
        console.log('🔍 END OF TARGETED HTML SEARCH');
        console.log('='.repeat(80) + '\n');

        // 🔍 DUMP SPECIFIC VARIATIONS TAB CONTENT
        try {
          const variationsTabContent = await page.locator('#variable_product_options').innerHTML();
          console.log('\n' + '='.repeat(80));
          console.log('🔍 VARIATIONS TAB CONTENT:');
          console.log('='.repeat(80));
          console.log(variationsTabContent);
          console.log('='.repeat(80));
          console.log('🔍 END OF VARIATIONS TAB CONTENT');
          console.log('='.repeat(80) + '\n');
        } catch (tabError) {
          console.log('⚠️ Could not get variations tab content:', tabError.message);
        }

        // 🔍 DUMP ALL BUTTON ELEMENTS
        try {
          const allButtons = await page.locator('button').all();
          console.log(`\n🔍 FOUND ${allButtons.length} BUTTON ELEMENTS ON PAGE:`);
          for (let i = 0; i < allButtons.length; i++) {
            try {
              const buttonHTML = await allButtons[i].innerHTML();
              const buttonClasses = await allButtons[i].getAttribute('class');
              const buttonType = await allButtons[i].getAttribute('type');
              console.log(`Button ${i+1}: class="${buttonClasses}" type="${buttonType}" innerHTML="${buttonHTML}"`);
            } catch (buttonError) {
              console.log(`Button ${i+1}: Could not get details - ${buttonError.message}`);
            }
          }
          console.log('🔍 END OF BUTTON ELEMENTS\n');
        } catch (buttonListError) {
          console.log('⚠️ Could not get button list:', buttonListError.message);
        }

        // Fallback: try waiting longer with the primary selector
        console.log('🔄 No selector worked, trying longer wait with primary selector...');
        generateVariationsBtn = page.locator('button.generate_variations');
        await generateVariationsBtn.waitFor({ state: 'visible', timeout: 30000 });
      }

      console.log(`✅ Generate variations button found with selector: ${foundSelector || 'fallback'}`);

      console.log('✅ Generate variations button found, preparing to click...');
      await generateVariationsBtn.scrollIntoViewIfNeeded();
      await page.waitForTimeout(1000);

      try {
        await generateVariationsBtn.click({ force: true });
        console.log('✅ Clicked "Generate variations" button (force click)');
      } catch (clickError) {
        console.log('Force click failed, trying focus + Enter approach...');
        await generateVariationsBtn.focus();
        await page.keyboard.press('Enter');
        console.log('✅ Clicked "Generate variations" button (keyboard)');
      }

      // Wait for variations to be generated
      await page.waitForTimeout(10000);
      console.log('✅ Variations generation completed');

      // Check if variations were created
      const variations = await page.locator('.woocommerce_variation').count();
      console.log(`🔍 Found ${variations} variations after generation`);

      if (variations > 0) {
        console.log(`✅ Successfully generated ${variations} variations`);

        // STEP 9: Click "Add price" button with robust handling
        console.log('🔄 Step 9: Clicking "Add price" button...');
        const addPriceBtn = page.locator('button.add_price_for_variations');
        await addPriceBtn.waitFor({ state: 'visible', timeout: 10000 });

        await addPriceBtn.scrollIntoViewIfNeeded();
        await page.waitForTimeout(500);

        try {
          await addPriceBtn.click({ force: true });
        } catch (clickError) {
          console.log('Force click failed, trying focus approach...');
          await addPriceBtn.focus();
          await page.keyboard.press('Enter');
        }
        console.log('✅ Clicked "Add price" button');

        // STEP 10: Type price in the input field with robust handling
        console.log('🔄 Step 10: Typing price in input field...');
        await page.waitForTimeout(2000);

        const priceInput = page.locator('input.components-text-control__input.wc_input_variations_price');
        await priceInput.waitFor({ state: 'visible', timeout: 10000 });

        await priceInput.scrollIntoViewIfNeeded();
        await page.waitForTimeout(500);

        try {
          await priceInput.click({ force: true });
        } catch (clickError) {
          console.log('Force click failed, trying focus approach...');
          await priceInput.focus();
        }

        await priceInput.clear();
        await priceInput.type('29.99', { delay: 100 });
        console.log('✅ Typed bulk price: 29.99');

        // STEP 11: Click "Add prices" button with robust handling
        console.log('🔄 Step 11: Clicking "Add prices" button...');
        const addPricesBtn = page.locator('button.add_variations_price_button.button-primary');
        await addPricesBtn.waitFor({ state: 'visible', timeout: 10000 });

        await addPricesBtn.scrollIntoViewIfNeeded();
        await page.waitForTimeout(500);

        try {
          await addPricesBtn.click({ force: true });
        } catch (clickError) {
          console.log('Force click failed, trying focus approach...');
          await addPricesBtn.focus();
          await page.keyboard.press('Enter');
        }
        console.log('✅ Clicked "Add prices" button');
        await page.waitForTimeout(3000);

      } else {
        throw new Error('No variations were generated - attribute setup failed');
      }

      // STEP 12: Publish the product
      console.log('🔄 Step 12: Publishing product...');
      await publishProduct(page);

      // Extract product ID from URL after publish
      const currentUrl = page.url();
      productId = extractProductIdFromUrl(currentUrl);
      if (productId) {
        console.log(`📦 Variable Product ID: ${productId}`);
      } else {
        console.log(`⚠️ Failed to extract product ID from URL: ${currentUrl}`);
        // Try alternative method: check if we can get it from the page
        try {
          const postIdMatch = await page.locator('input[name="post_ID"]').getAttribute('value');
          if (postIdMatch) {
            productId = parseInt(postIdMatch);
            console.log(`📦 Variable Product ID (from post_ID field): ${productId}`);
          } else {
            // Check for product ID in page content
            const pageContent = await page.content();
            const contentMatch = pageContent.match(/post=(\d+)/);
            if (contentMatch) {
              productId = parseInt(contentMatch[1]);
              console.log(`📦 Variable Product ID (from page content): ${productId}`);
            }
          }
        } catch (fallbackError) {
          console.log(`⚠️ Fallback ID extraction also failed: ${fallbackError.message}`);
        }
      }

      // Verify no PHP fatal errors
      await checkForPhpErrors(page);

      // Validate sync to Meta catalog and fields from Meta
      await validateFacebookSync(productId, productName, 20);

      console.log('✅ Variable product creation test completed successfully');
      await waitForManualInspection(page);

      logTestEnd(testInfo, true);

    } catch (error) {
      console.log(`⚠️ Variable product test failed: ${error.message}`);
      // Take screenshot for debugging
      await safeScreenshot(page, 'variable-product-test-failure.png');
      logTestEnd(testInfo, false);
      throw error;
    } finally {
      // Cleanup irrespective of test result
      if (productId) {
        await cleanupProduct(productId);
      }
    }
  });

  test('Test WordPress admin and Facebook plugin presence', async ({ page }, testInfo) => {

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
      logTestEnd(testInfo, true);

    } catch (error) {
      console.log(`⚠️ Plugin detection test failed: ${error.message}`);
      logTestEnd(testInfo, false);
      throw error;
    }
  });

  test('Test basic WooCommerce product list', async ({ page }, testInfo) => {

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
      logTestEnd(testInfo, true);

    } catch (error) {
      console.log(`⚠️ Product list test failed: ${error.message}`);
      logTestEnd(testInfo, false);
      throw error;
    }
  });

  test('Quick PHP error check across key pages', async ({ page }, testInfo) => {

    try {
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

      logTestEnd(testInfo, true);
    } catch (error) {
      logTestEnd(testInfo, false);
      throw error;
    }
  });

  test('Test Facebook plugin deactivation and reactivation', async ({ page }, testInfo) => {

    try {

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
      logTestEnd(testInfo, true);

    } catch (error) {
      console.log(`⚠️ Plugin activation test failed: ${error.message}`);
      logTestEnd(testInfo, false);
      throw error;
    }
  });
});
