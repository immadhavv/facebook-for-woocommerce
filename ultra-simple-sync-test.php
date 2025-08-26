<?php
/**
 * Ultra Simple Facebook Sync Test
 *
 * One-step end-to-end sync verification:
 * 1. Create product
 * 2. Trigger sync (mimics clicking Publish)
 * 3. Wait for processing
 * 4. Verify via Meta API (mimics page refresh)
 *
 * Usage: php ultra-simple-sync-test.php
 */

// Bootstrap WordPress
$wp_load_path = dirname(__FILE__) . '/../../../wp-load.php';
if (file_exists($wp_load_path)) {
    require_once($wp_load_path);
} else {
    die('❌ WordPress not found. Please run this script from within WordPress.');
}

// Check dependencies
if (!function_exists('wc_get_product')) {
    die("❌ WooCommerce is not active.\n");
}

if (!function_exists('facebook_for_woocommerce')) {
    die("❌ Facebook for WooCommerce plugin not loaded.\n");
}

echo "🚀 Ultra Simple Facebook Sync Test\n";
echo "===================================\n\n";

// STEP 1: Create Product
echo "STEP 1: Creating test product...\n";
$product = new WC_Product_Simple();
$product->set_name('Ultra Test Product - ' . date('H:i:s'));
$product->set_regular_price('19.99');
$product->set_description('Ultra simple sync test product');
$product->set_status('publish');
$product_id = $product->save();

echo "✅ Created product ID: $product_id\n";
echo "📝 Product: " . $product->get_name() . "\n\n";

// STEP 2: Trigger Sync (Mimic WordPress Publish Action)
echo "STEP 2: Triggering sync (mimicking WordPress publish)...\n";

try {
    $integration = facebook_for_woocommerce()->get_integration();

    // Set the sync mode (this is what the admin form would send)
    $_POST['wc_facebook_sync_mode'] = 'sync_and_show';

    // Call the exact same method WordPress calls when you click "Publish"
    $integration->on_product_save($product_id);

    // Clean up
    unset($_POST['wc_facebook_sync_mode']);

    echo "✅ Sync triggered via on_product_save()\n";

} catch (Exception $e) {
    echo "❌ Sync failed: " . $e->getMessage() . "\n";
    exit(1);
}

// STEP 3: Wait for Processing
echo "\nSTEP 3: Waiting for Facebook to process...\n";
echo "⏳ Sleeping for 10 seconds (Facebook Batch API processing time)...\n";
sleep(10);

// STEP 4: Verify via Meta API (Mimic Page Refresh)
echo "\nSTEP 4: Verifying sync status (mimicking page refresh)...\n";

try {
    $product = wc_get_product($product_id);
    $retailer_id = WC_Facebookcommerce_Utils::get_fb_retailer_id($product);

    echo "🏪 Retailer ID: $retailer_id\n";

    // This is exactly what display_batch_api_completed() does
    $fb_product_item_id = $integration->get_product_fbid(
        WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID,
        $product_id,
        $product
    );

    if ($fb_product_item_id) {
        echo "🎉 SUCCESS! Product synced to Facebook!\n";
        echo "🆔 Facebook Product ID: $fb_product_item_id\n";

        // Check if it was stored in local DB (this happens during get_product_fbid)
        $stored_id = get_post_meta($product_id, 'fb_product_item_id', true);
        echo "💾 Stored in local DB: " . ($stored_id ? "✅ Yes ($stored_id)" : "❌ No") . "\n";

        echo "\n✨ SYNC VERIFICATION COMPLETE ✨\n";
        echo "The product is now visible in Facebook catalog!\n";

    } else {
        echo "❌ Product not found in Facebook catalog\n";
        echo "⏳ This could mean:\n";
        echo "   - Batch API is still processing (try again in a few minutes)\n";
        echo "   - Sync failed due to validation errors\n";
        echo "   - Facebook integration not properly configured\n";
    }

} catch (Exception $e) {
    echo "❌ Verification failed: " . $e->getMessage() . "\n";
}

echo "\n📋 SUMMARY\n";
echo "==========\n";
echo "Product ID: $product_id\n";
echo "Edit URL: http://wooc-auto-mbe-site.local/wp-admin/post.php?post=$product_id&action=edit\n";
echo "\n💡 TIP: Refresh the product edit page to see the 'View product on Meta catalog' link!\n";
