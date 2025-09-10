<?php
/**
 * WordPress Environment Configuration Dumper
 *
 * Dumps all relevant WordPress/Facebook configuration for comparison
 * Usage: php dump-wp-config.php
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

function dumpWordPressConfig() {
    $dump = [];
    $dump['timestamp'] = date('Y-m-d H:i:s');
    $dump['environment'] = $wp_path;

    // Basic WordPress info
    $dump['wordpress'] = [
        'version' => defined('WP_VERSION') ? WP_VERSION : 'Unknown',
        'abspath' => defined('ABSPATH') ? ABSPATH : 'Unknown',
        'wp_debug' => defined('WP_DEBUG') ? (WP_DEBUG ? 'true' : 'false') : 'not_defined',
        'wp_debug_log' => defined('WP_DEBUG_LOG') ? (WP_DEBUG_LOG ? 'true' : 'false') : 'not_defined',
        'home_url' => function_exists('home_url') ? home_url() : 'Unknown',
        'admin_url' => function_exists('admin_url') ? admin_url() : 'Unknown',
    ];

    // WooCommerce info
    $dump['woocommerce'] = [
        'version' => defined('WC_VERSION') ? WC_VERSION : 'Unknown',
        'active' => function_exists('wc_get_product') ? 'yes' : 'no',
    ];

    // Facebook plugin info
    $dump['facebook_plugin'] = [
        'version' => defined('WC_FACEBOOKCOMMERCE_VERSION') ? WC_FACEBOOKCOMMERCE_VERSION : 'Unknown',
        'active' => function_exists('facebook_for_woocommerce') ? 'yes' : 'no',
        'path' => defined('WC_FACEBOOKCOMMERCE_PLUGIN_FILE') ? WC_FACEBOOKCOMMERCE_PLUGIN_FILE : 'Unknown',
    ];

    // Facebook configuration (if available)
    if (function_exists('facebook_for_woocommerce')) {
        try {
            $integration = facebook_for_woocommerce()->get_integration();
            if ($integration) {
                $dump['facebook_config'] = [
                    'configured' => $integration->is_configured() ? 'yes' : 'no',
                    'catalog_id' => $integration->get_product_catalog_id() ?: 'not_set',
                    'page_id' => $integration->get_facebook_page_id() ?: 'not_set',
                    'pixel_id' => $integration->get_facebook_pixel_id() ?: 'not_set',
                ];

                // API info (be careful with sensitive data)
                $api = facebook_for_woocommerce()->get_api();
                if ($api) {
                    $token = $api->get_access_token();
                    $dump['facebook_api'] = [
                        'token_present' => !empty($token) ? 'yes' : 'no',
                        'token_length' => strlen($token),
                        'token_prefix' => !empty($token) ? substr($token, 0, 10) . '...' : 'none',
                        'api_version' => defined('WooCommerce\Facebook\API::API_VERSION') ?
                                       constant('WooCommerce\Facebook\API::API_VERSION') : 'Unknown',
                    ];
                }
            } else {
                $dump['facebook_config'] = ['error' => 'Integration not available'];
            }
        } catch (Exception $e) {
            $dump['facebook_config'] = ['error' => $e->getMessage()];
        }
    } else {
        $dump['facebook_config'] = ['error' => 'Facebook plugin not active'];
    }

    // WordPress options related to Facebook
    $facebook_options = [
        'wc_facebook_access_token',
        'wc_facebook_page_id',
        'wc_facebook_pixel_id',
        'wc_facebook_product_catalog_id',
        'wc_facebook_external_business_id',
        'wc_facebook_business_manager_id',
    ];

    $dump['wp_options'] = [];
    foreach ($facebook_options as $option) {
        $value = get_option($option, 'not_found');
        if ($value !== 'not_found') {
            if (strpos($option, 'token') !== false) {
                // Mask sensitive token data
                $dump['wp_options'][$option] = !empty($value) ?
                    substr($value, 0, 10) . '...[masked]' : 'empty';
            } else {
                $dump['wp_options'][$option] = $value;
            }
        } else {
            $dump['wp_options'][$option] = 'not_found';
        }
    }

    // Plugin file comparison - get file checksums for key files to see if they're different
    $key_files = [
        'API.php' => WP_CONTENT_DIR . '/plugins/facebook-for-woocommerce/includes/API.php',
        'Request.php' => WP_CONTENT_DIR . '/plugins/facebook-for-woocommerce/includes/API/ProductCatalog/Products/Id/Request.php',
        'Integration.php' => WP_CONTENT_DIR . '/plugins/facebook-for-woocommerce/includes/Integration.php',
    ];

    $dump['file_checksums'] = [];
    foreach ($key_files as $name => $path) {
        if (file_exists($path)) {
            $dump['file_checksums'][$name] = [
                'exists' => 'yes',
                'size' => filesize($path),
                'modified' => date('Y-m-d H:i:s', filemtime($path)),
                'md5' => md5_file($path),
            ];
        } else {
            $dump['file_checksums'][$name] = ['exists' => 'no'];
        }
    }

    // System info
    $dump['system'] = [
        'php_version' => PHP_VERSION,
        'operating_system' => php_uname(),
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
    ];

    // Environment variables that might affect Facebook
    $env_vars = ['FACEBOOK_APP_ID', 'FACEBOOK_APP_SECRET', 'WP_ENVIRONMENT_TYPE'];
    $dump['environment_vars'] = [];
    foreach ($env_vars as $var) {
        $value = getenv($var);
        $dump['environment_vars'][$var] = $value !== false ?
            (strpos($var, 'SECRET') !== false ? 'present[masked]' : $value) : 'not_set';
    }

    return $dump;
}

// Main execution
try {
    $dump = dumpWordPressConfig();
    echo json_encode($dump, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
