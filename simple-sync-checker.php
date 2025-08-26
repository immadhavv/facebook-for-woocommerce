<?php
/**
 * Simple Facebook Sync Checker
 *
 * Usage: php simple-sync-checker.php [product_id]
 * If no product_id provided, creates a new product and checks it
 */

// Bootstrap WordPress
$wp_load_path = dirname(__FILE__) . '/../../../wp-load.php';
if (file_exists($wp_load_path)) {
    require_once($wp_load_path);
} else {
    die('WordPress not found. Please run this script from within WordPress or adjust the path.');
}

function create_test_product() {
    echo "🔨 Creating test product...\n";

    $product = new WC_Product_Simple();
    $product->set_name('Test Sync Product - ' . date('Y-m-d H:i:s'));
    $product->set_regular_price('29.99');
    $product->set_description('Test product for Facebook sync verification');
    $product->set_status('publish');

    // Enable Facebook sync
    $product->update_meta_data('_wc_facebook_sync_enabled', 'yes');
    $product->update_meta_data('fb_visibility', 'yes');

    $product_id = $product->save();

    echo "✅ Created product ID: $product_id\n";
    echo "📝 Product name: " . $product->get_name() . "\n\n";

    return $product_id;
}

function check_local_sync_status($product_id) {
    echo "🔍 LOCAL SYNC STATUS CHECK\n";
    echo "==========================\n";

    $product = wc_get_product($product_id);
    if (!$product) {
        echo "❌ Product not found: $product_id\n";
        return false;
    }

    echo "📦 Product: " . $product->get_name() . " (ID: $product_id)\n";

    // Check sync enabled
    $sync_enabled = get_post_meta($product_id, '_wc_facebook_sync_enabled', true);
    echo "🔄 Sync Enabled: " . ($sync_enabled === 'yes' ? '✅ Yes' : '❌ No') . "\n";

    // Check visibility
    $visibility = get_post_meta($product_id, 'fb_visibility', true);
    echo "👁️  Visibility: " . ($visibility === 'yes' ? '✅ Visible' : '❌ Hidden/Not set') . "\n";

    // Check Facebook product ID (may not be set for Batch API products)
    $facebook_id = get_post_meta($product_id, 'fb_product_item_id', true);
    echo "🆔 Facebook ID: " . ($facebook_id ? "✅ $facebook_id" : '⏳ Not set (normal for Batch API)') . "\n";

    // Check sync errors
    $sync_errors = get_post_meta($product_id, '_facebook_sync_errors', true);
    if ($sync_errors) {
        echo "⚠️  Sync Errors: " . print_r($sync_errors, true) . "\n";
    }

    // For Batch API products, consider it locally configured if sync is enabled and visibility is set
    $is_locally_configured = ($sync_enabled === 'yes' && $visibility === 'yes');

    echo "\n📊 LOCAL CONFIGURATION: " . ($is_locally_configured ? '✅ CONFIGURED FOR SYNC' : '❌ NOT CONFIGURED') . "\n";

    if ($is_locally_configured && !$facebook_id) {
        echo "ℹ️  Note: Facebook ID not stored locally (expected for Batch API sync)\n";
    }

    echo "\n";

    return [
        'sync_enabled' => $sync_enabled === 'yes',
        'visibility_set' => $visibility === 'yes',
        'facebook_id_set' => !empty($facebook_id),
        'locally_configured' => $is_locally_configured
    ];
}

function check_meta_api_status($product_id) {
    echo "🌐 META API VERIFICATION\n";
    echo "========================\n";

    try {
        // Check if Facebook plugin is properly loaded
        if (!function_exists('facebook_for_woocommerce')) {
            echo "❌ Facebook for WooCommerce plugin not loaded\n\n";
            return false;
        }

        $plugin = facebook_for_woocommerce();
        $integration = $plugin->get_integration();
        if (!$integration) {
            echo "❌ Facebook integration not available\n\n";
            return false;
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            echo "❌ Product not found\n";
            return false;
        }

        $retailer_id = WC_Facebookcommerce_Utils::get_fb_retailer_id($product);
        echo "🏪 Retailer ID: $retailer_id\n";

        $catalog_id = $integration->get_product_catalog_id();
        echo "📚 Catalog ID: $catalog_id\n";

        // Check if integration is configured
        if (!$integration->is_configured()) {
            echo "❌ Facebook integration not configured\n\n";
            return false;
        }

        echo "🔍 Checking Meta API...\n";

        try {
            // Use the correct API access pattern
            $api = $plugin->get_api();
            $response = $api->get_product_facebook_ids($catalog_id, $retailer_id);

            $api_verified = false;
            $fb_product_id = null;

            // Handle response based on actual API response structure
            $response_data = $response->response_data ?? null;

            if ($response_data && isset($response_data['data']) && !empty($response_data['data'])) {
                $product_data = $response_data['data'][0];
                if (isset($product_data['id'])) {
                    $api_verified = true;
                    $fb_product_id = $product_data['id'];
                    $fb_product_group_id = $product_data['product_group']['id'] ?? 'N/A';
                    echo "✅ Found in Meta catalog!\n";
                    echo "   📦 Product ID: $fb_product_id\n";
                    echo "   🏷️  Product Group ID: $fb_product_group_id\n";
                } else {
                    echo "❌ Product data found but missing ID\n";
                }
            } elseif ($response && $response->id) {
                $api_verified = true;
                $fb_product_id = $response->id;
                echo "✅ Found in Meta catalog: $fb_product_id\n";
            } else {
                echo "❌ Not found in Meta catalog\n";
                echo "🔍 Debug: Response has " . (isset($response_data['data']) ? count($response_data['data']) : 0) . " products\n";
            }
        } catch (Exception $api_exception) {
            echo "❌ API Request Error: " . $api_exception->getMessage() . "\n";
            $api_verified = false;
        }

        echo "\n📊 META API STATUS: " . ($api_verified ? '✅ VERIFIED' : '❌ NOT FOUND') . "\n\n";

        return $api_verified;

    } catch (Exception $e) {
        echo "❌ API Error: " . $e->getMessage() . "\n\n";
        return false;
    }
}

function trigger_sync($product_id) {
    echo "🚀 TRIGGERING SYNC\n";
    echo "==================\n";

    try {
        $product = wc_get_product($product_id);
        if (!$product) {
            echo "❌ Product not found\n";
            return false;
        }

        // Enable sync if not already enabled
        $product->update_meta_data('_wc_facebook_sync_enabled', 'yes');
        $product->update_meta_data('fb_visibility', 'yes');
        $product->save();

        echo "✅ Sync settings updated\n";

        // Check Facebook integration status first
        $integration = facebook_for_woocommerce()->get_integration();
        if (!$integration) {
            echo "❌ Facebook integration not available\n";
            return false;
        }

        echo "🔧 Integration configured: " . ($integration->is_configured() ? '✅ Yes' : '❌ No') . "\n";
        echo "📚 Catalog ID: " . $integration->get_product_catalog_id() . "\n";

        // Debug what's missing for configuration
        $page_id = $integration->get_facebook_page_id();
        echo "📄 Facebook Page ID: " . ($page_id ? "✅ $page_id" : '❌ Not set') . "\n";

        // Check connection handler safely
        $connection_handler = facebook_for_woocommerce()->get_connection_handler();
        if ($connection_handler) {
            $is_connected = $connection_handler->is_connected();
            echo "🔗 Connection Handler: " . ($is_connected ? '✅ Connected' : '❌ Not connected') . "\n";
        } else {
            echo "🔗 Connection Handler: ❌ Not available\n";
        }

        // Check if product should be synced
        $should_sync = $integration->product_should_be_synced($product);
        echo "🔍 Should sync: " . ($should_sync ? '✅ Yes' : '❌ No') . "\n";

        if (!$should_sync) {
            echo "⚠️  Product validation failed - sync will not proceed\n";
        }

        // Set the POST data that on_product_save expects
        $_POST['wc_facebook_sync_mode'] = 'sync_and_show'; // This corresponds to Admin::SYNC_MODE_SYNC_AND_SHOW

        // Trigger the sync process
        echo "🚀 Calling on_product_save() with proper sync mode...\n";
        $integration->on_product_save($product_id);

        // Clean up POST data
        unset($_POST['wc_facebook_sync_mode']);

        echo "✅ Sync triggered\n";
        echo "⏳ Wait a moment for sync to complete...\n\n";

        sleep(3); // Wait 3 seconds

        return true;

    } catch (Exception $e) {
        echo "❌ Sync Error: " . $e->getMessage() . "\n\n";
        return false;
    }
}

// Main execution
echo "🔍 Simple Facebook Sync Checker\n";
echo "================================\n\n";

// Check if WooCommerce is active
if (!function_exists('wc_get_product')) {
    die("❌ WooCommerce is not active or not found.\n");
}

// Get product ID from command line or create new product
$product_id = isset($argv[1]) ? (int)$argv[1] : null;

if (!$product_id) {
    $product_id = create_test_product();
}

// Check local sync status
$local_status = check_local_sync_status($product_id);

// If not configured locally, try to trigger sync
if (!$local_status['locally_configured']) {
    echo "🔄 Product not configured for sync. Attempting to trigger sync...\n\n";
    trigger_sync($product_id);

    // Check again after sync attempt
    echo "🔍 Checking local status after sync attempt...\n\n";
    $local_status = check_local_sync_status($product_id);
}

// Check Meta API status
$api_verified = check_meta_api_status($product_id);

// Final summary
echo "📋 FINAL SUMMARY\n";
echo "================\n";
echo "Product ID: $product_id\n";
echo "Local Configuration: " . ($local_status['locally_configured'] ? '✅ CONFIGURED' : '❌ NOT CONFIGURED') . "\n";
echo "Facebook ID Stored: " . ($local_status['facebook_id_set'] ? '✅ YES' : '⏳ NO (normal for Batch API)') . "\n";
echo "Meta API Status: " . ($api_verified ? '✅ VERIFIED' : '❌ NOT FOUND') . "\n";

// Determine overall sync status
if ($local_status['locally_configured'] && $api_verified) {
    echo "\n🎉 SUCCESS: Product is fully synced via Batch API!\n";
    echo "ℹ️  Note: This is the expected behavior for Batch API sync\n";
} elseif ($local_status['locally_configured'] && !$api_verified) {
    echo "\n⏳ PENDING: Product configured for sync but not yet in Meta catalog\n";
    echo "ℹ️  Note: Batch API sync may take a few minutes to complete\n";
} elseif (!$local_status['locally_configured'] && $api_verified) {
    echo "\n🔧 REPAIR NEEDED: Product exists in Meta but local configuration is missing\n";
    echo "ℹ️  Suggestion: Run the repair-sync-status.php script to fix this\n";
} else {
    echo "\n❌ FAILED: Product is not synced\n";
}

echo "\nEdit product: http://wooc-auto-mbe-site.local/wp-admin/post.php?post=$product_id&action=edit\n";
