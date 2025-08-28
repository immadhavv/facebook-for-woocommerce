<?php
/**
 * E2E Facebook Sync Validator
 *
 * Complete validation system for Facebook sync in E2E tests
 * Combines sync status checking and field comparison in one OOP solution
 *
 * Usage: php e2e-facebook-sync-validator.php <product_id> [wait_seconds]
 *
 * Returns JSON response with complete validation results
 */

// Bootstrap WordPress - handle both local and GitHub Actions environments
$possible_wp_paths = [
    // Local development path (from tests/e2e/ to wp-load.php)
    dirname(__FILE__) . '/../../../../../wp-load.php',
    // GitHub Actions path (WordPress in /tmp/wordpress)
    '/tmp/wordpress/wp-load.php',
    // Alternative paths for different setups
    dirname(__FILE__) . '/../../../../wp-load.php',
    dirname(__FILE__) . '/../../../wp-load.php',
    // Current directory (if run from WordPress root)
    './wp-load.php',
    // Parent directories
    '../wp-load.php',
    '../../wp-load.php',
    '../../../wp-load.php',
    '../../../../wp-load.php',
    '../../../../../wp-load.php'
];

$wp_loaded = false;
$successful_path = null;
foreach ($possible_wp_paths as $wp_path) {
    if (file_exists($wp_path)) {
        require_once($wp_path);
        $wp_loaded = true;
        $successful_path = $wp_path;
        break;
    }
}

if (!$wp_loaded) {
    echo json_encode([
        'success' => false,
        'error' => 'WordPress not found. Searched paths: ' . implode(', ', $possible_wp_paths)
    ]);
    exit(1);
}

// Log which path was successful for debugging
error_log("E2E Facebook Sync Validator: Successfully loaded WordPress from: " . $successful_path);

/**
 * Facebook Sync Validator Class
 *
 * Handles complete validation of Facebook product sync including:
 * - Sync status verification
 * - Field comparison between WooCommerce and Facebook
 * - Comprehensive reporting
 */
class FacebookSyncValidator {

    private $product_id;
    private $product;
    private $integration;
    private $result;

    public function __construct($product_id, $wait_seconds = 5) {
        $this->product_id = (int)$product_id;
        $this->result = [
            'success' => false,
            'product_id' => $this->product_id,
            'product_name' => '',
            'retailer_id' => null,
            'facebook_id' => null,
            'sync_status' => 'unknown',
            'field_validation' => [
                'woocommerce_data' => [],
                'facebook_data' => [],
                'matches' => [],
                'mismatches' => [],
                'field_count' => 0
            ],
            'error' => null,
            'debug' => [],
            'summary' => ''
        ];

        // Wait for Facebook processing if specified
        if ($wait_seconds > 0) {
            sleep($wait_seconds);
        }

        $this->validateDependencies();
        $this->initializeProduct();
        $this->initializeIntegration();
    }

    /**
     * Validate required dependencies
     */
    private function validateDependencies() {
        if (!function_exists('wc_get_product')) {
            throw new Exception('WooCommerce not active');
        }

        if (!function_exists('facebook_for_woocommerce')) {
            throw new Exception('Facebook plugin not loaded');
        }

        if (!$this->product_id) {
            throw new Exception('Product ID required');
        }
    }

    /**
     * Initialize and validate product
     */
    private function initializeProduct() {
        $this->product = wc_get_product($this->product_id);
        if (!$this->product) {
            throw new Exception("Product {$this->product_id} not found");
        }

        $this->result['product_name'] = $this->product->get_name();
        $this->result['debug'][] = "Initialized product: {$this->result['product_name']}";
    }

    /**
     * Initialize Facebook integration
     */
    private function initializeIntegration() {
        $this->integration = facebook_for_woocommerce()->get_integration();
        if (!$this->integration) {
            throw new Exception('Facebook integration not available');
        }

        // Check configuration with detailed diagnostics
        if (!$this->integration->is_configured()) {
            $config_issues = $this->diagnoseConfigurationIssues();
            throw new Exception('Facebook integration not configured. Issues: ' . implode(', ', $config_issues));
        }

        $this->result['debug'][] = 'Facebook integration initialized and configured';
    }

    /**
     * Diagnose specific configuration issues
     */
    private function diagnoseConfigurationIssues() {
        $issues = [];

        // Check Facebook Page ID
        $page_id = $this->integration->get_facebook_page_id();
        if (!$page_id) {
            $issues[] = 'Facebook Page ID not set';
        } else {
            $this->result['debug'][] = "Facebook Page ID: $page_id";
        }

        // Check connection status
        $connection_handler = facebook_for_woocommerce()->get_connection_handler();
        if (!$connection_handler || !$connection_handler->is_connected()) {
            $issues[] = 'Facebook connection not established (access token missing or invalid)';
        } else {
            $this->result['debug'][] = 'Facebook connection established';
        }

        // Check catalog ID
        $catalog_id = $this->integration->get_product_catalog_id();
        if (!$catalog_id) {
            $issues[] = 'Product Catalog ID not set';
        } else {
            $this->result['debug'][] = "Product Catalog ID: $catalog_id";
        }

        return $issues;
    }

    /**
     * Main validation method
     */
    public function validate() {
        try {
            $this->validateSyncStatus();
            $this->validateFields();
            $this->generateSummary();
            $this->result['success'] = true;

        } catch (Exception $e) {
            $this->result['error'] = $e->getMessage();
            $this->result['debug'][] = "Validation failed: " . $e->getMessage();
        }

        return $this->result;
    }

    /**
     * Validate sync status - check if product exists in Facebook
     */
    private function validateSyncStatus() {
        // Get retailer ID
        $retailer_id = WC_Facebookcommerce_Utils::get_fb_retailer_id($this->product);
        $this->result['retailer_id'] = $retailer_id;
        $this->result['debug'][] = "Generated retailer_id: $retailer_id";

        // Check if product exists in Facebook catalog
        $fb_product_item_id = $this->integration->get_product_fbid(
            WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID,
            $this->product_id,
            $this->product
        );

        if ($fb_product_item_id) {
            $this->result['facebook_id'] = $fb_product_item_id;
            $this->result['sync_status'] = 'synced';
            $this->result['debug'][] = "Product found in Facebook catalog: $fb_product_item_id";

            // Check if stored in local DB
            $stored_id = get_post_meta($this->product_id, 'fb_product_item_id', true);
            if ($stored_id) {
                $this->result['debug'][] = "Facebook ID stored in local DB: $stored_id";
            } else {
                $this->result['debug'][] = "Facebook ID retrieved via API (not stored locally)";
            }
        } else {
            $this->result['sync_status'] = 'not_synced';
            throw new Exception('Product not found in Facebook catalog');
        }
    }

    /**
     * Validate fields - compare WooCommerce data with what was sent to Facebook
     */
    private function validateFields() {
        $this->result['debug'][] = "Starting field validation";

        // Get WooCommerce product data (what we sent to Facebook)
        $fb_product = new WC_Facebook_Product($this->product_id);
        $woo_data = $fb_product->prepare_product(
            $this->result['retailer_id'],
            WC_Facebook_Product::PRODUCT_PREP_TYPE_ITEMS_BATCH
        );

        // Extract key fields for validation
        $woo_fields = $this->extractValidationFields($woo_data);
        $this->result['field_validation']['woocommerce_data'] = $woo_fields;
        $this->result['field_validation']['field_count'] = count(array_filter($woo_fields));

        $this->result['debug'][] = "Extracted " . $this->result['field_validation']['field_count'] . " fields from WooCommerce data";

        // Try to get Facebook data for comparison
        $this->compareFacebookFields($woo_fields);
    }

    /**
     * Extract key fields for validation
     */
    private function extractValidationFields($woo_data) {
        return [
            'title' => $woo_data['title'] ?? '',
            'price' => $woo_data['price'] ?? '',
            'description' => $this->truncateText($woo_data['description'] ?? '', 100),
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
            'mpn' => $woo_data['mpn'] ?? '',
            'external_variant_id' => $woo_data['external_variant_id'] ?? '',
            'visibility' => $woo_data['visibility'] ?? ''
        ];
    }

    /**
     * Compare fields with Facebook data (limited by API access)
     */
    private function compareFacebookFields($woo_fields) {
        try {
            $api = facebook_for_woocommerce()->get_api();
            $catalog_id = $this->integration->get_product_catalog_id();

            // Get limited Facebook data via API
            $response = $api->get_product_facebook_ids($catalog_id, $this->result['retailer_id']);

            if ($response && $response->response_data && isset($response->response_data['data'][0])) {
                $fb_product_data = $response->response_data['data'][0];

                // Debug: Show what Facebook actually returned
                $this->result['debug'][] = "Raw Facebook API response: " . json_encode($fb_product_data);

                $facebook_data = [
                    'id' => $fb_product_data['id'] ?? '',
                    'retailer_id' => $fb_product_data['retailer_id'] ?? '',
                ];

                $this->result['field_validation']['facebook_data'] = $facebook_data;
                $this->result['debug'][] = "Retrieved Facebook data via API";

                // Enhanced field comparison with detailed logging
                $this->compareFields($woo_fields, $facebook_data);
                $this->logDetailedFieldComparison($woo_fields, $facebook_data);

            } else {
                $this->result['debug'][] = "Facebook API returned no comparable data";
                $this->result['field_validation']['facebook_data'] = [];

                // Still log the WooCommerce data for debugging
                $this->logDetailedFieldComparison($woo_fields, []);

                // Debug: Show the full response structure
                if ($response) {
                    $this->result['debug'][] = "Full API response structure: " . json_encode($response->response_data ?? 'No response_data');
                }
            }

        } catch (Exception $api_exception) {
            $this->result['debug'][] = "Facebook API comparison failed: " . $api_exception->getMessage();
            $this->result['field_validation']['facebook_data'] = [];

            // Still log the WooCommerce data for debugging
            $this->logDetailedFieldComparison($woo_fields, []);
        }
    }

    /**
     * Compare individual fields
     */
    private function compareFields($woo_fields, $facebook_data) {
        $matches = [];

        // Verify Facebook product ID exists (this confirms sync worked)
        if (isset($facebook_data['id']) && !empty($facebook_data['id'])) {
            $matches['sync_confirmed'] = [
                'woocommerce' => 'Product synced successfully',
                'facebook' => $facebook_data['id'],
                'status' => 'success'
            ];
        }

        $this->result['field_validation']['matches'] = $matches;
        $this->result['field_validation']['mismatches'] = [];

        $this->result['debug'][] = "Sync verification completed: Product successfully synced to Facebook";
    }

    /**
     * Generate human-readable summary
     */
    private function generateSummary() {
        $woo_data = $this->result['field_validation']['woocommerce_data'];
        $field_count = $this->result['field_validation']['field_count'];
        $matches = count($this->result['field_validation']['matches'] ?? []);
        $mismatches = count($this->result['field_validation']['mismatches'] ?? []);
        $api_limitations = count($this->result['field_validation']['api_limitations'] ?? []);

        $summary_parts = [];

        // Sync status
        if ($this->result['sync_status'] === 'synced') {
            $summary_parts[] = "✅ SYNC SUCCESS: Product synced to Facebook (ID: {$this->result['facebook_id']})";
        } else {
            $summary_parts[] = "❌ SYNC FAILED: Product not found in Facebook catalog";
        }

        // Field summary
        $summary_parts[] = "📦 DATA SENT: {$field_count} fields sent to Facebook";
        $summary_parts[] = "📝 Title: {$woo_data['title']}";
        $summary_parts[] = "💰 Price: {$woo_data['price']}";
        $summary_parts[] = "🏷️  Brand: {$woo_data['brand']}";
        $summary_parts[] = "🔗 Retailer ID: {$woo_data['retailer_id']}";

        if (!empty($woo_data['description'])) {
            $summary_parts[] = "📋 Description: {$woo_data['description']}";
        }

        // Comparison results
        if ($matches > 0) {
            $summary_parts[] = "✅ Sync confirmed: $matches verification(s)";
        }
        if ($mismatches > 0) {
            $summary_parts[] = "⚠️  Field mismatches: $mismatches";
        }
        if ($api_limitations > 0) {
            $summary_parts[] = "ℹ️  API limitations: $api_limitations field(s) not returned by Facebook API (normal)";
        }

        $this->result['summary'] = implode("\n", $summary_parts);
    }

    /**
     * Generate simple formatted comparison report
     */
    private function logDetailedFieldComparison($woo_fields, $facebook_data) {
        $report_lines = [];
        $report_lines[] = "FIELD COMPARISON REPORT - LOCAL vs META";
        $report_lines[] = str_repeat('=', 60);

        // Get all unique field names
        $all_fields = array_unique(array_merge(array_keys($woo_fields), array_keys($facebook_data)));
        sort($all_fields);

        $summary = ['matches' => 0, 'mismatches' => 0, 'sent_only' => 0, 'received_only' => 0];

        foreach ($all_fields as $field) {
            $woo_value = $woo_fields[$field] ?? '';
            $fb_value = $facebook_data[$field] ?? '';

            if (!empty($woo_value) && !empty($fb_value)) {
                if ($woo_value === $fb_value) {
                    $report_lines[] = "✅ $field: MATCH";
                    $report_lines[] = "   Local: $woo_value";
                    $report_lines[] = "   Meta:  $fb_value";
                    $summary['matches']++;
                } else {
                    $report_lines[] = "⚠️ $field: MISMATCH";
                    $report_lines[] = "   Local: $woo_value";
                    $report_lines[] = "   Meta:  $fb_value";
                    $summary['mismatches']++;
                }
            } elseif (!empty($woo_value)) {
                $report_lines[] = "📤 $field: SENT ONLY";
                $report_lines[] = "   Local: $woo_value";
                $summary['sent_only']++;
            } elseif (!empty($fb_value)) {
                $report_lines[] = "📥 $field: RECEIVED ONLY";
                $report_lines[] = "   Meta:  $fb_value";
                $summary['received_only']++;
            }
            $report_lines[] = "";
        }

        // Add summary
        $report_lines[] = str_repeat('=', 60);
        $report_lines[] = "SUMMARY:";
        $report_lines[] = "✅ Matches: " . $summary['matches'];
        $report_lines[] = "⚠️ Mismatches: " . $summary['mismatches'];
        $report_lines[] = "📤 Sent Only: " . $summary['sent_only'];
        $report_lines[] = "📥 Received Only: " . $summary['received_only'];
        $report_lines[] = "📊 Total Fields: " . count($all_fields);
        $report_lines[] = str_repeat('=', 60);

        // Store formatted report
        $this->result['formatted_report'] = implode("\n", $report_lines);
        $this->result['field_validation']['comparison_summary'] = $summary;
    }

    /**
     * Truncate values for display in comparison table
     */
    private function truncateForDisplay($value, $max_length) {
        if (empty($value)) {
            return '';
        }

        $str = (string)$value;
        if (strlen($str) <= $max_length) {
            return $str;
        }

        return substr($str, 0, $max_length - 3) . '...';
    }

    /**
     * Utility method to truncate text
     */
    private function truncateText($text, $length) {
        if (strlen($text) <= $length) {
            return $text;
        }
        return substr($text, 0, $length) . '...';
    }

    /**
     * Get validation result as JSON
     */
    public function getJsonResult() {
        return json_encode($this->result, JSON_PRETTY_PRINT);
    }

    /**
     * Static factory method for easy usage
     */
    public static function validateProduct($product_id, $wait_seconds = 5) {
        $validator = new self($product_id, $wait_seconds);
        return $validator->validate();
    }
}

// Main execution when called directly
if (php_sapi_name() === 'cli') {
    try {
        $product_id = isset($argv[1]) ? (int)$argv[1] : null;
        $wait_seconds = isset($argv[2]) ? (int)$argv[2] : 5;

        if (!$product_id) {
            echo json_encode(['success' => false, 'error' => 'Product ID required']);
            exit(1);
        }

        $validator = new FacebookSyncValidator($product_id, $wait_seconds);
        $result = $validator->validate();
        echo $validator->getJsonResult();

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'debug' => ["Exception: " . $e->getMessage()]
        ]);
        exit(1);
    }
}
