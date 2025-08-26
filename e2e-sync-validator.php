<?php
/**
 * E2E Sync Validator
 *
 * Called from Playwright E2E tests to validate Facebook sync after product creation
 *
 * Usage: php e2e-sync-validator.php <product_id> [wait_seconds]
 *
 * Returns JSON response for easy parsing in JavaScript
 */

// Bootstrap WordPress
$wp_load_path = dirname(__FILE__) . '/../../../wp-load.php';
if (file_exists($wp_load_path)) {
    require_once($wp_load_path);
} else {
    echo json_encode(['success' => false, 'error' => 'WordPress not found']);
    exit(1);
}

// Check dependencies
if (!function_exists('wc_get_product')) {
    echo json_encode(['success' => false, 'error' => 'WooCommerce not active']);
    exit(1);
}

if (!function_exists('facebook_for_woocommerce')) {
    echo json_encode(['success' => false, 'error' => 'Facebook plugin not loaded']);
    exit(1);
}

// Get parameters
$product_id = isset($argv[1]) ? (int)$argv[1] : null;
$wait_seconds = isset($argv[2]) ? (int)$argv[2] : 5;

if (!$product_id) {
    echo json_encode(['success' => false, 'error' => 'Product ID required']);
    exit(1);
}

// Validate product exists
$product = wc_get_product($product_id);
if (!$product) {
    echo json_encode(['success' => false, 'error' => "Product $product_id not found"]);
    exit(1);
}

// Wait for Facebook processing
if ($wait_seconds > 0) {
    sleep($wait_seconds);
}

$result = [
    'success' => false,
    'product_id' => $product_id,
    'product_name' => $product->get_name(),
    'retailer_id' => null,
    'facebook_id' => null,
    'sync_status' => 'unknown',
    'error' => null,
    'debug' => []
];

try {
    $integration = facebook_for_woocommerce()->get_integration();

    // Get retailer ID (this is what Facebook uses to identify the product)
    $retailer_id = WC_Facebookcommerce_Utils::get_fb_retailer_id($product);
    $result['retailer_id'] = $retailer_id;
    $result['debug'][] = "Generated retailer_id: $retailer_id";

    // Check if integration is configured
    if (!$integration->is_configured()) {
        $result['error'] = 'Facebook integration not configured';
        echo json_encode($result);
        exit(1);
    }

    $result['debug'][] = 'Facebook integration is configured';

    // This is the exact same method that runs when admin views product edit page
    // It checks local DB first, then Facebook API if not found locally
    $fb_product_item_id = $integration->get_product_fbid(
        WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID,
        $product_id,
        $product
    );

    if ($fb_product_item_id) {
        $result['success'] = true;
        $result['facebook_id'] = $fb_product_item_id;
        $result['sync_status'] = 'synced';
        $result['debug'][] = "Found Facebook product ID: $fb_product_item_id";

        // Check if it was stored in local DB
        $stored_id = get_post_meta($product_id, 'fb_product_item_id', true);
        if ($stored_id) {
            $result['debug'][] = "Facebook ID stored in local DB: $stored_id";
        } else {
            $result['debug'][] = "Facebook ID not stored locally (API lookup only)";
        }

    } else {
        $result['sync_status'] = 'not_synced';
        $result['error'] = 'Product not found in Facebook catalog';
        $result['debug'][] = 'Product not found via Facebook API';
    }

    // Additional debug info
    $catalog_id = $integration->get_product_catalog_id();
    $result['debug'][] = "Catalog ID: $catalog_id";

    // Check if product should be synced (validation)
    $should_sync = $integration->product_should_be_synced($product);
    $result['debug'][] = "Should sync: " . ($should_sync ? 'yes' : 'no');

} catch (Exception $e) {
    $result['error'] = $e->getMessage();
    $result['debug'][] = "Exception: " . $e->getMessage();
}

// Output JSON for easy parsing in JavaScript
echo json_encode($result, JSON_PRETTY_PRINT);
