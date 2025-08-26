<?php
/**
 * Facebook Sync Status Checker
 *
 * This script checks if WooCommerce products have been synced with Meta's catalog
 * Based on the Facebook for WooCommerce plugin metadata
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    // If running standalone, bootstrap WordPress
    $wp_load_path = dirname(__FILE__) . '/../../../../../wp-load.php';
    if (file_exists($wp_load_path)) {
        require_once($wp_load_path);
    } else {
        die('WordPress not found. Please run this script from within WordPress or adjust the path.');
    }
}

class FacebookSyncStatusChecker {

    /**
     * Check Facebook sync status for a specific product
     *
     * @param int $product_id WooCommerce product ID
     * @return array Sync status information
     */
    public static function check_facebook_sync_status($product_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return [
                'error' => 'Product not found',
                'product_id' => $product_id
            ];
        }

        // Check if sync is enabled
        $sync_enabled = get_post_meta($product_id, '_wc_facebook_sync_enabled', true);

        // Get Facebook product ID (indicates successful sync)
        $facebook_id = get_post_meta($product_id, '_wc_facebook_product_id', true);

        // Check for validation errors
        $sync_errors = get_post_meta($product_id, '_facebook_sync_errors', true);

        // Get sync status
        $sync_status = get_post_meta($product_id, '_wc_facebook_sync_status', true);

        // Additional meta fields that might be useful
        $facebook_visibility = get_post_meta($product_id, '_wc_facebook_visibility', true);
        $facebook_description = get_post_meta($product_id, '_wc_facebook_description', true);

        return [
            'product_id' => $product_id,
            'product_name' => $product->get_name(),
            'product_type' => $product->get_type(),
            'sync_enabled' => $sync_enabled === 'yes',
            'is_synced' => !empty($facebook_id),
            'facebook_id' => $facebook_id,
            'sync_status' => $sync_status,
            'has_errors' => !empty($sync_errors),
            'errors' => $sync_errors ?: [],
            'facebook_visibility' => $facebook_visibility,
            'facebook_description' => $facebook_description,
            'product_url' => get_edit_post_link($product_id),
            'last_modified' => get_post_modified_time('Y-m-d H:i:s', false, $product_id)
        ];
    }

    // Add this method to your FacebookSyncStatusChecker class
    public static function verify_with_meta_api($product_id) {
        // $local_status = self::check_facebook_sync_status($product_id);

        if ($local_status['facebook_id']) {
            // Make actual API call to verify product still exists in Meta catalog
            try {
                $integration = facebook_for_woocommerce()->get_integration();
                $response = $integration->facebook_for_woocommerce->get_api()->get_product_facebook_ids(
                    $integration->get_product_catalog_id(),
                    WC_Facebookcommerce_Utils::get_fb_retailer_id(wc_get_product($product_id))
                );

                $local_status['meta_api_verified'] = !empty($response->data) || !empty($response->id);
                $local_status['api_verification_time'] = current_time('mysql');
            } catch (Exception $e) {
                $local_status['meta_api_verified'] = false;
                $local_status['api_error'] = $e->getMessage();
            }
        }

    return $local_status;
}

    /**
     * Check sync status for multiple products
     *
     * @param array $product_ids Array of product IDs
     * @return array Array of sync status information
     */
    public static function check_multiple_products($product_ids) {
        $results = [];
        foreach ($product_ids as $product_id) {
            $results[] = self::check_facebook_sync_status($product_id);
        }
        return $results;
    }

    /**
     * Get all products with Facebook sync enabled
     *
     * @param int $limit Maximum number of products to return
     * @return array Array of sync status information
     */
    public static function get_all_synced_products($limit = 50) {
        $args = [
            'post_type' => 'product',
            'post_status' => 'any',
            'posts_per_page' => $limit,
            'meta_query' => [
                [
                    'key' => '_wc_facebook_sync_enabled',
                    'value' => 'yes',
                    'compare' => '='
                ]
            ]
        ];

        $products = get_posts($args);
        $results = [];

        foreach ($products as $product) {
            $results[] = self::check_facebook_sync_status($product->ID);
        }

        return $results;
    }

    /**
     * Get products created in the last N hours
     *
     * @param int $hours Number of hours to look back
     * @return array Array of sync status information
     */
    public static function get_recent_products($hours = 24) {
        $date_query = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));

        $args = [
            'post_type' => 'product',
            'post_status' => 'any',
            'posts_per_page' => 50,
            'date_query' => [
                [
                    'after' => $date_query,
                    'inclusive' => true
                ]
            ]
        ];

        $products = get_posts($args);
        $results = [];

        foreach ($products as $product) {
            $results[] = self::check_facebook_sync_status($product->ID);
        }

        return $results;
    }

    /**
     * Monitor background sync operations
     *
     * @return array Background sync status
     */
    public static function monitor_sync_operations() {
        // Check background sync queue
        $bg_sync = get_option('wc_facebook_background_product_sync', []);

        // Check debug mode status
        $debug_enabled = get_option('wc_facebook_debug_mode', false);

        // Check if request headers are logged
        $headers_logged = get_option('wc_facebook_request_headers_in_debug_log', false);

        return [
            'queue_count' => is_array($bg_sync) ? count($bg_sync) : 0,
            'debug_mode' => $debug_enabled,
            'headers_in_debug' => $headers_logged,
            'has_pending_syncs' => !empty($bg_sync),
            'queue_items' => $bg_sync
        ];
    }

    /**
     * Format sync status for display
     *
     * @param array $status_data Sync status data
     * @return string Formatted output
     */
    public static function format_status_output($status_data) {
        if (isset($status_data['error'])) {
            return "❌ Error: {$status_data['error']} (Product ID: {$status_data['product_id']})\n";
        }

        $output = "📦 Product: {$status_data['product_name']} (ID: {$status_data['product_id']})\n";
        $output .= "   Type: {$status_data['product_type']}\n";
        $output .= "   Sync Enabled: " . ($status_data['sync_enabled'] ? '✅ Yes' : '❌ No') . "\n";
        $output .= "   Is Synced: " . ($status_data['is_synced'] ? '✅ Yes' : '❌ No') . "\n";

        if ($status_data['facebook_id']) {
            $output .= "   Facebook ID: {$status_data['facebook_id']}\n";
        }

        if ($status_data['sync_status']) {
            $output .= "   Sync Status: {$status_data['sync_status']}\n";
        }

        if ($status_data['has_errors']) {
            $output .= "   ⚠️ Errors: " . implode(', ', $status_data['errors']) . "\n";
        }

        $output .= "   Last Modified: {$status_data['last_modified']}\n";
        $output .= "   Edit URL: {$status_data['product_url']}\n\n";

        return $output;
    }
}

// If running this script directly (not included)
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {

    echo "🔍 Facebook Sync Status Checker\n";
    echo "================================\n\n";

    // Check if WooCommerce is active
    if (!function_exists('wc_get_product')) {
        die("❌ WooCommerce is not active or not found.\n");
    }

    // Get command line arguments or use defaults
    $action = isset($argv[1]) ? $argv[1] : 'recent';
    $param = isset($argv[2]) ? $argv[2] : null;

    switch ($action) {
        case 'product':
            if (!$param) {
                echo "❌ Please provide a product ID. Usage: php facebook-sync-status-checker.php product 123\n";
                exit(1);
            }
            $status = FacebookSyncStatusChecker::check_facebook_sync_status((int)$param);
            echo FacebookSyncStatusChecker::format_status_output($status);
            break;

        case 'recent':
            $hours = $param ? (int)$param : 24;
            echo "📅 Checking products created in the last {$hours} hours...\n\n";
            $products = FacebookSyncStatusChecker::get_recent_products($hours);

            if (empty($products)) {
                echo "ℹ️ No products found in the last {$hours} hours.\n";
            } else {
                foreach ($products as $product) {
                    echo FacebookSyncStatusChecker::format_status_output($product);
                }
            }
            break;

        case 'synced':
            $limit = $param ? (int)$param : 50;
            echo "🔄 Checking products with Facebook sync enabled (limit: {$limit})...\n\n";
            $products = FacebookSyncStatusChecker::get_all_synced_products($limit);

            if (empty($products)) {
                echo "ℹ️ No products found with Facebook sync enabled.\n";
            } else {
                foreach ($products as $product) {
                    echo FacebookSyncStatusChecker::format_status_output($product);
                }
            }
            break;

        case 'queue':
            echo "🔄 Checking background sync operations...\n\n";
            $sync_ops = FacebookSyncStatusChecker::monitor_sync_operations();

            echo "Queue Count: {$sync_ops['queue_count']}\n";
            echo "Debug Mode: " . ($sync_ops['debug_mode'] ? 'Enabled' : 'Disabled') . "\n";
            echo "Headers in Debug: " . ($sync_ops['headers_in_debug'] ? 'Yes' : 'No') . "\n";
            echo "Has Pending Syncs: " . ($sync_ops['has_pending_syncs'] ? 'Yes' : 'No') . "\n";

            if (!empty($sync_ops['queue_items'])) {
                echo "\nQueue Items:\n";
                print_r($sync_ops['queue_items']);
            }
            break;

        default:
            echo "Usage: php facebook-sync-status-checker.php [action] [parameter]\n\n";
            echo "Actions:\n";
            echo "  product [id]     - Check specific product by ID\n";
            echo "  recent [hours]   - Check products created in last N hours (default: 24)\n";
            echo "  synced [limit]   - Check products with sync enabled (default: 50)\n";
            echo "  queue           - Check background sync queue status\n\n";
            echo "Examples:\n";
            echo "  php facebook-sync-status-checker.php product 123\n";
            echo "  php facebook-sync-status-checker.php recent 6\n";
            echo "  php facebook-sync-status-checker.php synced 10\n";
            echo "  php facebook-sync-status-checker.php queue\n";
            break;
    }
}
