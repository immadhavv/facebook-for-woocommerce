<?php
/**
 * DEBUG FUNCTION - Simple Facebook API Call
 *
 * Just initialize product, get API, get retailer ID, make API call
 * Usage: php debug-facebook-api.php <product_id>
 */

// Bootstrap WordPress
$wp_path = '/tmp/wordpress/wp-load.php';
// $wp_path = '/Users/nmadhav/Local Sites/wooc-auto-mbe-site/app/public/wp-load.php';

if (!file_exists($wp_path)) {
    echo json_encode([
        'success' => false,
        'error' => 'WordPress not found at: ' . $wp_path
    ]);
    exit(1);
}

require_once($wp_path);

function debugFacebookAPI($product_id) {
    $debug = [];
    $debug[] = "=== FACEBOOK API DEBUG SESSION ===";
    $debug[] = "Product ID: {$product_id}";
    $debug[] = "WordPress Path: " . (defined('ABSPATH') ? ABSPATH : 'Not found');

    try {
        // Step 1: Check dependencies
        $debug[] = "--- STEP 1: Checking Dependencies ---";

        if (!function_exists('wc_get_product')) {
            throw new Exception('WooCommerce not active');
        }
        $debug[] = "✅ WooCommerce is active";

        if (!function_exists('facebook_for_woocommerce')) {
            throw new Exception('Facebook plugin not loaded');
        }
        $debug[] = "✅ Facebook plugin is loaded";

        // Step 2: Initialize product
        $debug[] = "--- STEP 2: Initialize Product ---";
        $product = wc_get_product($product_id);
        if (!$product) {
            throw new Exception("Product {$product_id} not found in WooCommerce");
        }
        $debug[] = "✅ Product found: " . $product->get_name();
        $debug[] = "Product Type: " . $product->get_type();
        $debug[] = "Product Status: " . $product->get_status();

        // Step 3: Initialize Facebook integration
        $debug[] = "--- STEP 3: Initialize Facebook Integration ---";
        $integration = facebook_for_woocommerce()->get_integration();
        if (!$integration) {
            throw new Exception('Facebook integration not available');
        }
        $debug[] = "✅ Facebook integration available";

        if (!$integration->is_configured()) {
            throw new Exception('Facebook integration not configured');
        }
        $debug[] = "✅ Facebook integration is configured";

        $catalog_id = $integration->get_product_catalog_id();
        $debug[] = "Catalog ID: " . ($catalog_id ?: 'Not set');

        // Step 4: Get retailer ID
        $debug[] = "--- STEP 4: Get Retailer ID ---";
        $retailer_id = WC_Facebookcommerce_Utils::get_fb_retailer_id($product);
        $debug[] = "✅ Retailer ID: {$retailer_id}";

        // Step 5: Initialize API
        $debug[] = "--- STEP 5: Initialize Facebook API ---";
        $api = facebook_for_woocommerce()->get_api();
        if (!$api) {
            throw new Exception('Facebook API not available');
        }
        $debug[] = "✅ Facebook API initialized";

        $access_token = $api->get_access_token();
        $debug[] = "Access Token: " . (strlen($access_token) > 0 ? 'Present (' . strlen($access_token) . ' chars)' : 'Missing');

        // Step 6: Make API call with different field combinations
        $debug[] = "--- STEP 6: Facebook API Calls ---";

        // Test 1: Basic fields (what GitHub is getting)
        $debug[] = "TEST 1: Basic fields only";
        $basic_fields = 'id,product_group{id}';
        $debug[] = "Fields requested: {$basic_fields}";

        try {
            $response1 = $api->get_product_facebook_ids($catalog_id, $retailer_id, $basic_fields);
            $debug[] = "✅ Basic API call succeeded";
            $debug[] = "Response data count: " . (isset($response1->response_data['data']) ? count($response1->response_data['data']) : 0);
            if (isset($response1->response_data['data'][0])) {
                $data = $response1->response_data['data'][0];
                $debug[] = "Facebook Product ID: " . ($data['id'] ?? 'Missing');
                $debug[] = "Product Group ID: " . ($data['product_group']['id'] ?? 'Missing');
                $debug[] = "Available fields in response: " . implode(', ', array_keys($data));
            }
        } catch (Exception $e) {
            $debug[] = "❌ Basic API call failed: " . $e->getMessage();
        }

        // Test 2: Full fields (what local should be getting)
        $debug[] = "\nTEST 2: Full fields";
        $full_fields = 'id,name,price,description,availability,retailer_id,condition,brand,color,size,product_group{id}';
        $debug[] = "Fields requested: {$full_fields}";

        try {
            $response2 = $api->get_product_facebook_ids($catalog_id, $retailer_id, $full_fields);
            $debug[] = "✅ Full API call succeeded";
            $debug[] = "Response data count: " . (isset($response2->response_data['data']) ? count($response2->response_data['data']) : 0);
            if (isset($response2->response_data['data'][0])) {
                $data = $response2->response_data['data'][0];
                $debug[] = "Facebook Product ID: " . ($data['id'] ?? 'Missing');
                $debug[] = "Product Name: " . ($data['name'] ?? 'Missing');
                $debug[] = "Product Price: " . ($data['price'] ?? 'Missing');
                $debug[] = "Product Description: " . (isset($data['description']) ? substr($data['description'], 0, 50) . '...' : 'Missing');
                $debug[] = "Product Availability: " . ($data['availability'] ?? 'Missing');
                $debug[] = "Product Retailer ID: " . ($data['retailer_id'] ?? 'Missing');
                $debug[] = "Product Condition: " . ($data['condition'] ?? 'Missing');
                $debug[] = "Product Brand: " . ($data['brand'] ?? 'Missing');
                $debug[] = "Available fields in response: " . implode(', ', array_keys($data));
            }

            // Show the raw URLs being called
            if (isset($response2->response_data['paging']['next'])) {
                $debug[] = "API URL being called: " . $response2->response_data['paging']['next'];
            }

        } catch (Exception $e) {
            $debug[] = "❌ Full API call failed: " . $e->getMessage();
        }

        // Test 3: Check if there are any differences in the requests
        $debug[] = "\n--- STEP 7: Environment Info ---";
        $debug[] = "PHP Version: " . PHP_VERSION;
        $debug[] = "WordPress Version: " . (defined('WP_VERSION') ? WP_VERSION : 'Unknown');
        $debug[] = "WooCommerce Version: " . (defined('WC_VERSION') ? WC_VERSION : 'Unknown');
        $debug[] = "Facebook Plugin Version: " . (defined('WC_FACEBOOKCOMMERCE_VERSION') ? WC_FACEBOOKCOMMERCE_VERSION : 'Unknown');
        $debug[] = "Current URL: " . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'CLI');

        $result = [
            'success' => true,
            'product_id' => $product_id,
            'retailer_id' => $retailer_id,
            'catalog_id' => $catalog_id,
            'debug' => $debug
        ];

    } catch (Exception $e) {
        $debug[] = "❌ FATAL ERROR: " . $e->getMessage();
        $result = [
            'success' => false,
            'error' => $e->getMessage(),
            'debug' => $debug
        ];
    }

    return $result;
}

// Main execution when called directly
if (php_sapi_name() === 'cli') {
    $product_id = isset($argv[1]) ? (int)$argv[1] : null;

    if (!$product_id) {
        echo json_encode(['success' => false, 'error' => 'Product ID required']);
        exit(1);
    }

    $result = debugFacebookAPI($product_id);
    echo json_encode($result, JSON_PRETTY_PRINT);
}
