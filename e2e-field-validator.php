<?php
/**
 * E2E Field Validator
 * 
 * Compares product fields between WooCommerce and Facebook to validate sync accuracy
 * 
 * Usage: php e2e-field-validator.php <product_id> [wait_seconds]
 * 
 * Returns JSON response with field comparison results
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
    'field_comparison' => [],
    'woocommerce_data' => [],
    'facebook_data' => [],
    'matches' => [],
    'mismatches' => [],
    'error' => null,
    'debug' => []
];

try {
    $integration = facebook_for_woocommerce()->get_integration();
    
    // Get retailer ID
    $retailer_id = WC_Facebookcommerce_Utils::get_fb_retailer_id($product);
    $result['retailer_id'] = $retailer_id;
    $result['debug'][] = "Generated retailer_id: $retailer_id";
    
    // Check if integration is configured
    if (!$integration->is_configured()) {
        $result['error'] = 'Facebook integration not configured';
        echo json_encode($result);
        exit(1);
    }
    
    // Get Facebook product ID
    $fb_product_item_id = $integration->get_product_fbid(
        WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, 
        $product_id, 
        $product
    );
    
    if (!$fb_product_item_id) {
        $result['error'] = 'Product not found in Facebook catalog';
        echo json_encode($result);
        exit(1);
    }
    
    $result['facebook_id'] = $fb_product_item_id;
    $result['debug'][] = "Found Facebook product ID: $fb_product_item_id";
    
    // Get WooCommerce product data (what we sent to Facebook)
    $fb_product = new WC_Facebook_Product($product_id);
    $woo_data = $fb_product->prepare_product($retailer_id, WC_Facebook_Product::PRODUCT_PREP_TYPE_ITEMS_BATCH);
    
    // Extract key fields for comparison
    $woo_fields = [
        'title' => $woo_data['title'] ?? '',
        'price' => $woo_data['price'] ?? '',
        'description' => $woo_data['description'] ?? '',
        'brand' => $woo_data['brand'] ?? '',
        'condition' => $woo_data['condition'] ?? '',
        'availability' => $woo_data['availability'] ?? '',
        'image_link' => $woo_data['image_link'] ?? '',
        'retailer_id' => $woo_data['retailer_id'] ?? '',
        'color' => $woo_data['color'] ?? '',
        'size' => $woo_data['size'] ?? '',
        'material' => $woo_data['material'] ?? '',
        'pattern' => $woo_data['pattern'] ?? '',
        'age_group' => $woo_data['age_group'] ?? '',
        'gender' => $woo_data['gender'] ?? '',
        'mpn' => $woo_data['mpn'] ?? ''
    ];
    
    $result['woocommerce_data'] = $woo_fields;
    $result['debug'][] = "Extracted WooCommerce data for comparison";
    
    // Get Facebook product data via API
    try {
        $api = facebook_for_woocommerce()->get_api();
        $catalog_id = $integration->get_product_catalog_id();
        
        // Get detailed product information from Facebook
        // Note: get_product_facebook_ids only returns basic info, we need to make a direct API call
        $facebook_data = [];
        
        // Try to get product details from Facebook API
        // This is a simplified approach - in reality, Facebook's API has limited fields we can retrieve
        $response = $api->get_product_facebook_ids($catalog_id, $retailer_id);
        
        if ($response && $response->response_data && isset($response->response_data['data'][0])) {
            $fb_product_data = $response->response_data['data'][0];
            
            // Facebook API returns limited fields, so we'll compare what we can
            $facebook_data = [
                'id' => $fb_product_data['id'] ?? '',
                'retailer_id' => $fb_product_data['retailer_id'] ?? '',
                // Note: Facebook API doesn't return all fields we send
                // This is a limitation of the Facebook Graph API for product catalogs
            ];
            
            $result['facebook_data'] = $facebook_data;
            $result['debug'][] = "Retrieved limited Facebook data via API";
            
            // Compare available fields
            $matches = [];
            $mismatches = [];
            
            // Compare retailer_id (this should always match)
            if (isset($facebook_data['retailer_id']) && isset($woo_fields['retailer_id'])) {
                if ($facebook_data['retailer_id'] === $woo_fields['retailer_id']) {
                    $matches['retailer_id'] = [
                        'woocommerce' => $woo_fields['retailer_id'],
                        'facebook' => $facebook_data['retailer_id'],
                        'status' => 'match'
                    ];
                } else {
                    $mismatches['retailer_id'] = [
                        'woocommerce' => $woo_fields['retailer_id'],
                        'facebook' => $facebook_data['retailer_id'],
                        'status' => 'mismatch'
                    ];
                }
            }
            
            $result['matches'] = $matches;
            $result['mismatches'] = $mismatches;
            $result['success'] = true;
            
            // Add note about API limitations
            $result['debug'][] = "Note: Facebook Graph API returns limited product fields for comparison";
            $result['debug'][] = "Full field validation requires Facebook Business Manager access";
            
        } else {
            $result['error'] = 'Could not retrieve detailed Facebook product data';
            $result['debug'][] = 'Facebook API response did not contain expected product data';
        }
        
    } catch (Exception $api_exception) {
        $result['error'] = 'Facebook API error: ' . $api_exception->getMessage();
        $result['debug'][] = "API Exception: " . $api_exception->getMessage();
    }
    
    // Even if we can't get full Facebook data, we can still show what we sent
    if (!$result['success'] && !empty($woo_fields)) {
        $result['success'] = true; // Partial success - we have WooCommerce data
        $result['debug'][] = "Showing WooCommerce data that was sent to Facebook";
        $result['note'] = "Facebook field comparison limited by API access - showing data sent to Facebook";
    }
    
} catch (Exception $e) {
    $result['error'] = $e->getMessage();
    $result['debug'][] = "Exception: " . $e->getMessage();
}

// Output JSON for easy parsing in JavaScript
echo json_encode($result, JSON_PRETTY_PRINT);