<?php
/**
 * Simple API Access Test
 *
 * Usage: php test-api-access.php
 */

// Bootstrap WordPress
$wp_load_path = dirname(__FILE__) . '/../../../wp-load.php';
if (file_exists($wp_load_path)) {
    require_once($wp_load_path);
} else {
    die('WordPress not found. Please run this script from within WordPress or adjust the path.');
}

echo "🔍 Facebook API Access Test\n";
echo "===========================\n\n";

// Check if WooCommerce is active
if (!function_exists('wc_get_product')) {
    die("❌ WooCommerce is not active or not found.\n");
}

// Check if Facebook plugin is loaded
if (!function_exists('facebook_for_woocommerce')) {
    die("❌ Facebook for WooCommerce plugin not loaded\n");
}

$plugin = facebook_for_woocommerce();
echo "✅ Plugin loaded: " . get_class($plugin) . "\n";

// Check connection handler
$connection_handler = $plugin->get_connection_handler();
if (!$connection_handler) {
    die("❌ Connection handler not available\n");
}
echo "✅ Connection handler available\n";

// Check if connected
$is_connected = $connection_handler->is_connected();
echo "🔗 Connected: " . ($is_connected ? '✅ Yes' : '❌ No') . "\n";

if (!$is_connected) {
    die("❌ Not connected to Facebook. Cannot test API.\n");
}

// Get access token
$access_token = $connection_handler->get_access_token();
echo "🔑 Access token: " . ($access_token ? '✅ Available (' . substr($access_token, 0, 10) . '...)' : '❌ Missing') . "\n";

if (!$access_token) {
    die("❌ No access token available\n");
}

// Try to get API instance
try {
    $api = $plugin->get_api();
    echo "✅ API instance created: " . get_class($api) . "\n";
} catch (Exception $e) {
    die("❌ Failed to create API instance: " . $e->getMessage() . "\n");
}

// Test a simple API call
echo "\n🧪 Testing API Call\n";
echo "==================\n";

$integration = $plugin->get_integration();
$external_business_id = $connection_handler->get_external_business_id();
$catalog_id = $integration->get_product_catalog_id();

echo "🏢 External Business ID: $external_business_id\n";
echo "📚 Catalog ID: $catalog_id\n";

if (!$catalog_id) {
    die("❌ No catalog ID configured\n");
}

try {
    // Test getting user info (simple API call)
    echo "🔍 Testing get_user() API call...\n";
    $user_response = $api->get_user();
    echo "✅ User API call successful\n";
    echo "👤 User ID: " . ($user_response->id ?? 'N/A') . "\n";

    // Test getting catalog info
    echo "\n🔍 Testing get_catalog() API call...\n";
    $catalog_response = $api->get_catalog($catalog_id);
    echo "✅ Catalog API call successful\n";
    echo "📚 Catalog Name: " . ($catalog_response->name ?? 'N/A') . "\n";

} catch (Exception $e) {
    echo "❌ API call failed: " . $e->getMessage() . "\n";
    echo "🔍 Error details:\n";
    echo "   - Error code: " . $e->getCode() . "\n";
    echo "   - Error class: " . get_class($e) . "\n";

    if (method_exists($e, 'getTraceAsString')) {
        echo "   - Stack trace: " . $e->getTraceAsString() . "\n";
    }
}

echo "\n✅ API Access Test Complete\n";
