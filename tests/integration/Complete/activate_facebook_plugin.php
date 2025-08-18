#!/bin/bash

# Navigate to WordPress directory
cd "/Users/nmadhav/Local Sites/wooc-auto-mbe-site/app/public"

echo "🔄 Activating Facebook for WooCommerce plugin..."

# Check if WP-CLI is available
if ! command -v wp &> /dev/null; then
    echo "❌ WP-CLI not found. Please install WP-CLI first."
    exit 1
fi

# Activate the plugin if not already active
wp plugin activate facebook-for-woocommerce

echo "🔄 Checking connection status..."

# Check if plugin recognizes connection
wp eval "
if (function_exists('facebook_for_woocommerce')) {
    \$connection = facebook_for_woocommerce()->get_connection_handler();
    echo 'Connected: ' . (\$connection->is_connected() ? 'YES' : 'NO') . PHP_EOL;
    echo 'Access Token: ' . (\$connection->get_access_token() ? 'Present' : 'Missing') . PHP_EOL;
    echo 'External Business ID: ' . \$connection->get_external_business_id() . PHP_EOL;
    echo 'Business Manager ID: ' . \$connection->get_business_manager_id() . PHP_EOL;
} else {
    echo 'Facebook plugin not loaded properly';
}"

echo "🔄 Triggering plugin activation steps..."

# Force plugin to recognize connection and run activation steps
wp eval "
if (function_exists('facebook_for_woocommerce')) {
    \$connection = facebook_for_woocommerce()->get_connection_handler();

    if (\$connection->is_connected()) {
        echo 'Connection detected! Running activation steps...' . PHP_EOL;

        // Force refresh installation data
        try {
            \$connection->refresh_installation_data();
            echo '✅ Installation data refreshed' . PHP_EOL;
        } catch (Exception \$e) {
            echo '⚠️ Installation data refresh failed: ' . \$e->getMessage() . PHP_EOL;
        }

        // Trigger product sync (optional)
        if (facebook_for_woocommerce()->get_integration()->allow_full_batch_api_sync()) {
            echo '🔄 Starting product sync...' . PHP_EOL;
            facebook_for_woocommerce()->get_products_sync_handler()->create_or_update_all_products();
            echo '✅ Product sync initiated' . PHP_EOL;
        } else {
            echo '⚠️ Full batch sync disabled' . PHP_EOL;
        }

        echo '🎉 Plugin activation completed!' . PHP_EOL;
    } else {
        echo '❌ Connection not detected - check access token' . PHP_EOL;
    }
} else {
    echo '❌ Facebook plugin not available' . PHP_EOL;
}"

echo "✅ Done!"
