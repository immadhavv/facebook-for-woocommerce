<?php
/**
 * Facebook Product Creation Logs Endpoint
 *
 * This file provides an AJAX endpoint to retrieve product creation logs
 * for browser developer mode visibility.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Add AJAX handlers for both logged-in and non-logged-in users
add_action('wp_ajax_get_fb_product_logs', 'handle_get_fb_product_logs');
add_action('wp_ajax_nopriv_get_fb_product_logs', 'handle_get_fb_product_logs');

// Add admin footer script to inject JavaScript
add_action('admin_footer', 'inject_fb_product_logs_script');

/**
 * AJAX handler to get Facebook product creation logs
 */
function handle_get_fb_product_logs() {
    // Check permissions
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Insufficient permissions', 403);
    }

    // Get logs from transient
    $logs = get_transient('fb_product_creation_logs') ?: [];

    // Return JSON response
    wp_send_json_success([
        'logs' => $logs,
        'count' => count($logs),
        'timestamp' => current_time('mysql')
    ]);
}

/**
 * Inject JavaScript to fetch and display logs in browser console
 */
function inject_fb_product_logs_script() {
    // Only load on admin pages
    if (!is_admin()) {
        return;
    }

    $ajax_url = admin_url('admin-ajax.php');
    $nonce = wp_create_nonce('fb_product_logs_nonce');

    ?>
    <script type="text/javascript">
    (function() {
        // Facebook Product Logs Console Logger
        window.FBProductLogs = {
            logs: [],

            // Fetch logs from server
            fetchLogs: function() {
                fetch('<?php echo esc_url($ajax_url); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'get_fb_product_logs',
                        _wpnonce: '<?php echo esc_js($nonce); ?>'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        this.logs = data.data.logs;
                        this.displayLogs();
                    } else {
                        console.error('Failed to fetch FB product logs:', data);
                    }
                })
                .catch(error => {
                    console.error('Error fetching FB product logs:', error);
                });
            },

            // Display logs in console
            displayLogs: function() {
                if (this.logs.length === 0) {
                    console.log('📦 No Facebook product creation logs found');
                    return;
                }

                console.group('📦 Facebook Product Creation Logs (' + this.logs.length + ')');
                this.logs.forEach((log, index) => {
                    console.group('🔹 Product #' + (index + 1) + ' - ' + log.product_name);
                    console.log('WC Product ID:', log.wc_product_id);
                    console.log('FB Product Item ID:', log.fb_product_item_id);
                    console.log('Retailer ID:', log.retailer_id);
                    console.log('Product Group ID:', log.product_group_id);
                    console.log('Created:', log.timestamp);
                    console.log('---');
                    console.groupEnd();
                });
                console.groupEnd();
            },

            // Auto-refresh logs every 30 seconds
            startAutoRefresh: function() {
                this.fetchLogs(); // Initial fetch
                setInterval(() => {
                    this.fetchLogs();
                }, 30000); // Refresh every 30 seconds
            },

            // Get latest log
            getLatest: function() {
                return this.logs.length > 0 ? this.logs[this.logs.length - 1] : null;
            },

            // Clear logs (server-side)
            clearLogs: function() {
                // This would require another AJAX endpoint to clear the transient
                console.log('Clear logs functionality not implemented yet');
            }
        };

        // Auto-start when page loads
        document.addEventListener('DOMContentLoaded', function() {
            console.log('🚀 Facebook Product Logs initialized. Use FBProductLogs.fetchLogs() to refresh manually.');
            window.FBProductLogs.startAutoRefresh();
        });

        // Also make it available globally for manual access
        window.fbLogs = window.FBProductLogs;
    })();
    </script>
    <?php
}
