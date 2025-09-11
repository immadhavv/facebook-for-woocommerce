<?php
/**
 *  E2E Facebook Sync Validator - Unified for Simple & Variable Products
 *
 * Validates product sync between WooCommerce and Facebook with comprehensive debugging
 * Follows same flow pattern for both product types: getData -> checkSync -> compareFields
 *
 * Usage: php e2e-facebook-sync-validator-simple.php <product_id> [wait_seconds] [product_type]
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

/**
 * Facebook Sync Validator Class
 */
class FacebookSyncValidator {

    private $product_id;
    private $product;
    private $integration;
    private $result;

    public function __construct($product_id, $wait_seconds = 10) {
        $this->product_id = (int)$product_id;
        $this->result = [
            'success' => false,
            'product_id' => $this->product_id,
            'product_type' => 'unknown',
            'sync_status' => 'unknown',
            'retailer_id' => null,
            'facebook_id' => null,
            'mismatches' => [],
            'summary' => [],
            'debug' => [],
            'error' => null
        ];

        // Wait for Facebook processing
        if ($wait_seconds > 0) {
            sleep($wait_seconds);
            $this->result['debug'][] = "Waited {$wait_seconds} seconds before validation";
        }

        $this->validateDependencies();
        $this->initializeProduct();
        $this->initializeIntegration();
    }

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

    // Initialize product
    private function initializeProduct() {
        $this->result['debug'][] = "Initializing product: {$this->product_id}";
        $this->product = wc_get_product($this->product_id);

        // Fail fast if the product ID doesn't exist in WooCommerce
        if (!$this->product) {
            throw new Exception("Product {$this->product_id} not found");
        }

        // Get and log retailer ID
        $retailer_id = WC_Facebookcommerce_Utils::get_fb_retailer_id($this->product);
        $this->result['debug'][] = "Product retailer ID: {$retailer_id} and type: {$this->product->get_type()}";

        // Debug product object in one line
        $this->result['debug'][] = "Product object: " . json_encode($this->product);

        $this->result['product_type'] = $this->product->get_type();
        $this->result['debug'][] = "Initialized {$this->result['product_type']} product: {$this->product->get_name()}";
    }

    private function initializeIntegration() {
        $this->integration = facebook_for_woocommerce()->get_integration();
        if (!$this->integration) {
            throw new Exception('Facebook integration not available');
        }
        if (!$this->integration->is_configured()) {
            throw new Exception('Facebook integration not configured');
        }
        $this->result['debug'][] = 'Facebook integration initialized and configured';
    }

    public function validate() {
        try {
            $actual_type = $this->product->get_type();

            // Step 1: Get both platform data (WooCommerce + Facebook)
            $data = $this->getBothPlatformData($actual_type);

            // Step 2: Check sync status using fetched Facebook data
            $this->checkSyncStatus($data);

            // Step 3: Compare fields between platforms
            $this->compareFields($data);

            // Set success based on sync status and no mismatches
            $this->result['success'] = ($this->result['sync_status'] === 'synced' && count($this->result['mismatches']) === 0);
        } catch (Exception $e) {
            $this->result['error'] = $e->getMessage();
            $this->result['debug'][] = "Validation failed: " . $e->getMessage();
        }
        return $this->result;
    }

    /**
     * Get both WooCommerce and Facebook data for any product type
     */
    private function getBothPlatformData($product_type) {
        $this->result['debug'][] = "Fetching both platform data for {$product_type} product";

        if ($product_type === 'variable') {
            return $this->getVariableProductData();
        } else {
            return $this->getSimpleProductData();
        }
    }

    /**
     * Get data for simple products
     */
    private function getSimpleProductData() {
        // Get WooCommerce data
        $retailer_id = WC_Facebookcommerce_Utils::get_fb_retailer_id($this->product);
        $this->result['retailer_id'] = $retailer_id;

        $woo_data = $this->extractWooCommerceFields($this->product, $retailer_id);
        $this->result['debug'][] = "Extracted WooCommerce data for simple product";
        $this->result['debug'][] = "WooCommerce data: " . json_encode($woo_data, JSON_PRETTY_PRINT);

        // Get Facebook data
        $facebook_data = $this->fetchFacebookData($retailer_id, 'simple');

        return [
            'type' => 'simple',
            'woo_data' => [$woo_data],
            'facebook_data' => [$facebook_data]
        ];
    }

    /**
     * Get data for variable products (variations only)
     */
    private function getVariableProductData() {
        $failed_variations = [];
        $woo_data_array = [];
        $facebook_data_array = [];

        // Set parent retailer_id for result tracking
        $this->result['retailer_id']  = WC_Facebookcommerce_Utils::get_fb_retailer_id($this->product);

        // All variations
        $variations = $this->product->get_children();
        $this->result['debug'][] = "Processing " . count($variations) . " variations: [" . implode(', ', $variations) . "]";
        $this->result['debug'][] = "Parent product ID: " . $this->product->get_id() . " (should not be in variations list above)";

        foreach ($variations as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation) {
                $failed_variations[] = $variation_id;
                $this->result['debug'][] = "Variation {$variation_id} not found in WooCommerce";
                continue;
            }

            try {
                $var_retailer_id = WC_Facebookcommerce_Utils::get_fb_retailer_id($variation);
                $woo_data_array[] = $this->extractWooCommerceFields($variation, $var_retailer_id);
                $facebook_data_array[] = $this->fetchFacebookData($var_retailer_id, 'variable');

                $this->result['debug'][] = "Extracted variation {$variation_id} data successfully";
            } catch (Exception $e) {
                $failed_variations[] = $variation_id;
                $this->result['debug'][] = "Variation {$variation_id} data extraction failed: " . $e->getMessage();
            }
        }

        // Summary for variable products
        $total_variations = count($variations);
        $successful_variations = $total_variations - count($failed_variations);
        $this->result['summary'] = [
            'total_variations' => $total_variations,
            'successful_variations' => $successful_variations,
            'failed_variations' => count($failed_variations),
            'failed_variation_ids' => $failed_variations
        ];

        if (count($failed_variations) > 0) {
            $this->result['debug'][] = "Failed to process variations: " . implode(', ', $failed_variations);
        }

        return [
            'type' => 'variable',
            'woo_data' => $woo_data_array,
            'facebook_data' => $facebook_data_array
        ];
    }

    /**
     * Extract WooCommerce product fields
     */
    private function extractWooCommerceFields($product, $retailer_id) {
        // Create Facebook product wrapper to get the prepared data; variations will have parent product
        $fb_product = $product->get_parent_id() ?
            new WC_Facebook_Product($product, new WC_Facebook_Product(wc_get_product($product->get_parent_id()))) :
            new WC_Facebook_Product($product);

        $product_data = $fb_product->prepare_product($retailer_id, WC_Facebook_Product::PRODUCT_PREP_TYPE_ITEMS_BATCH);
        return [
            'id' => $product->get_id(),  // Always include id for both simple and variable products
            'title' => $product_data['title'] ?? $product->get_name(),
            'price' => $product_data['price'] ?? $product->get_regular_price(),
            'description' => $this->truncateText($product_data['description'] ?? '', 100),
            'availability' => $product_data['availability'] ?? '',
            'retailer_id' => $retailer_id,
            'condition' => $product_data['condition'] ?? '',
            'brand' => $product_data['brand'] ?? '',
            'color' => $product_data['color'] ?? '',
            'size' => $product_data['size'] ?? ''
        ];
    }

    /**
     * Fetch Facebook data via API
     */
    private function fetchFacebookData($retailer_id, $context = 'simple') {
        try {
            $api = facebook_for_woocommerce()->get_api();
            $catalog_id = $this->integration->get_product_catalog_id();

            // Use get_product_facebook_fields with full fields string
            $fields = 'id,name,price,description,availability,retailer_id,condition,brand,color,size,product_group{id}';
            $response = $api->get_product_facebook_fields($catalog_id, $retailer_id, $fields);

            if ($response && $response->response_data && isset($response->response_data['data'][0])) {
                $fb_data = $response->response_data['data'][0];
                $this->result['debug'][] = "Successfully fetched Facebook data for {$retailer_id}";

                return [
                    'id' => $fb_data['id'] ?? null,
                    'name' => $fb_data['name'] ?? '',
                    'price' => $fb_data['price'] ?? '',
                    'description' => $fb_data['description'] ?? '',
                    'availability' => $fb_data['availability'] ?? '',
                    'retailer_id' => $fb_data['retailer_id'] ?? '',
                    'condition' => $fb_data['condition'] ?? '',
                    'brand' => $fb_data['brand'] ?? '',
                    'color' => $fb_data['color'] ?? '',
                    'size' => $fb_data['size'] ?? '',
                    'product_group_id' => $context === 'variable' ? $fb_data['product_group']['id'] : null, // TODO Or use retailed ID itself instead of null for simple products??
                    'found' => true
                ];

            } else {
                $this->result['debug'][] = "No Facebook data found for retailer_id: {$retailer_id}";
                return ['found' => false];
            }

        } catch (Exception $e) {
            $this->result['debug'][] = "Facebook API error for {$retailer_id}: " . $e->getMessage();
            return ['found' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     *  Check sync status for any product type
     */
    private function checkSyncStatus($data) {
        if ($data['type'] === 'variable') {
            // For variable products, check if all variations are synced
            $total_variations = count($data['woo_data']);
            $synced_variations = array_filter($data['facebook_data'], function($fb_data) {
                return $fb_data['found'] ?? false;
            });
            $synced_count = count($synced_variations);

            // Check if all variations have the same product group ID
            $product_group_ids = array_unique(array_filter(array_map(function($fb_data) {
                return $fb_data['product_group_id'] ?? null;
            }, $synced_variations)));

            // Variable product is synced if:
            // 1. ALL variations exist in Facebook
            // 2. All variations belong to the same product group
            if ($total_variations > 0 && $synced_count === $total_variations && count($product_group_ids) === 1) {
                $this->result['sync_status'] = 'synced';
                $this->result['facebook_id'] = reset($product_group_ids); // Use the common group ID
                $this->result['debug'][] = "Variable product fully synced - all {$total_variations} variations found in Facebook with same product group: " . $this->result['facebook_id'];
            } else {
                $this->result['sync_status'] = 'not_synced';

                if ($synced_count < $total_variations) {
                    // Find missing variations using consistent id field
                    $missing_variations = [];
                    for ($i = 0; $i < $total_variations; $i++) {
                        if (!($data['facebook_data'][$i]['found'] ?? false)) {
                            // cos we can't just loop on $data['facebook_data'] as it does not have retailer_id during failure fetches
                            $variation_id = $data['woo_data'][$i]['id'] ?? "unknown_{$i}";
                            $retailer_id = $data['woo_data'][$i]['retailer_id'] ?? "unknown_retailer_{$i}";
                            $missing_variations[] = "ID:{$variation_id} (retailer:{$retailer_id})";
                        }
                    }

                    $this->result['debug'][] = "Variable product not fully synced - only {$synced_count}/{$total_variations} variations found in Facebook";
                    $this->result['debug'][] = "Missing variations from Facebook: " . implode(', ', $missing_variations);
                } elseif (count($product_group_ids) > 1) {
                    $this->result['debug'][] = "Variable product not synced - variations belong to different product groups: " . implode(', ', $product_group_ids);
                }
            }
        } else {
            // For simple products
            $facebook_data = $data['facebook_data'][0] ?? [];
            if ($facebook_data['found'] ?? false) {
                $this->result['sync_status'] = 'synced';
                $this->result['facebook_id'] = $facebook_data['id'] ?? null;
                $this->result['debug'][] = "Simple product is synced with Facebook ID: " . ($this->result['facebook_id'] ?? 'unknown');
            } else {
                $this->result['sync_status'] = 'not_synced';
                $this->result['debug'][] = "Simple product is not synced to Facebook";
            }
        }
    }

    /**
     * Compare fields for any product type
     */
    private function compareFields($data) {
        $mismatches = [];
        $compared_products = 0;

        // Loop through each product (simple = 1 item, variable = N items)
        for ($i = 0; $i < count($data['woo_data']); $i++) {
            $woo_data = $data['woo_data'][$i];
            $facebook_data = $data['facebook_data'][$i];

            if (!($facebook_data['found'] ?? false)) {
                continue; // Skip products not found in Facebook
                // TODO should we log these as mismatches or is it already being done?
            }

            $compared_products++;

            // Use the consistent id field from woo_data for both simple and variable products
            $product_id = $woo_data['id'] ?? $this->product_id;

            $product_mismatches = $this->compareProductFields(
                $woo_data,
                $facebook_data,
                $product_id
            );

            if (count($product_mismatches) > 0) {
                $mismatches = array_merge($mismatches, $product_mismatches);

                if ($data['type'] === 'variable') {
                    $this->result['debug'][] = "Field mismatches found for variation {$product_id}";
                } else {
                    $this->result['debug'][] = "Field mismatches found for product {$product_id}";
                }
            }
        }

        $this->result['mismatches'] = $mismatches;
        $this->result['debug'][] = "Compared fields for {$compared_products} products, found " . count($mismatches) . " total mismatches";
    }

    /**
     * Compare fields for a single product
     */
    private function compareProductFields($woo_data, $facebook_data, $product_id) {
        $fields_to_check = [
            'title' => 'name',
            'price' => 'price',
            'retailer_id' => 'retailer_id',
            'availability' => 'availability',
            'description' => 'description',
            'brand' => 'brand',
            'condition' => 'condition'
        ];
        $mismatches = [];

        foreach ($fields_to_check as $woo_field => $fb_field) {
            $woo_value = $woo_data[$woo_field] ?? '';
            $fb_value = $facebook_data[$fb_field] ?? '';

            $normalized_woo = $this->normalizeValue($woo_value, $woo_field);
            $normalized_fb = $this->normalizeValue($fb_value, $woo_field);

            if ($normalized_woo !== $normalized_fb) {
                $this->result['debug'][] = "MISMATCH {$woo_field}: WooCommerce='{$woo_value}' (normalized='{$normalized_woo}') vs Facebook='{$fb_value}' (normalized='{$normalized_fb}')";

                $mismatches["{$product_id}_{$woo_field}"] = [
                    'product_id' => $product_id,
                    'field' => $woo_field,
                    'woocommerce_value' => $woo_value,
                    'facebook_value' => $fb_value
                ];
            }
        }

        return $mismatches;
    }

    /**
     * Helper function to truncate text with ellipsis
     */
    private function truncateText($text, $length) {
        if (strlen($text) <= $length) {
            return $text;
        }
        return substr($text, 0, $length) . '...';
    }

    private function normalizeValue($value, $field = '') {
        $normalized = trim(strtolower((string)$value));

        // Special handling for price fields
        if ($field === 'price') {
            return $this->normalizePrice($normalized);
        }

        return $normalized;
    }

    /**
     * Normalize price values to handle different currency formats
     * Examples:
     * "34 GBP" -> "34.00"
     * "£34.00" -> "34.00"
     * "$25.99" -> "25.99"
     * "19.99 USD" -> "19.99"
     */
    private function normalizePrice($price) {
        if (empty($price)) {
            return '';
        }

        // Convert to string if it's not already
        $price = (string)$price;

        // Remove common currency symbols
        $price = preg_replace('/[£$€¥₹₽¢]/u', '', $price);

        // Remove currency codes (USD, GBP, EUR, etc.)
        $price = preg_replace('/\b(usd|gbp|eur|jpy|aud|cad|chf|cny|inr|rub|brl|krw|sgd|hkd|nok|sek|dkk|pln|czk|huf|ils|php|thb|myr|idr|vnd|zar|try|mxn|nzd|aed|sar)\b/i', '', $price);

        // Remove extra whitespace and non-numeric characters except dots and commas
        $price = preg_replace('/[^\d.,]/', '', trim($price));

        // Handle different decimal separators (convert comma to dot for standardization)
        if (preg_match('/,\d{1,2}$/', $price)) {
            // Comma as decimal separator (e.g., "1234,56")
            $price = str_replace(',', '.', $price);
        } else {
            // Remove thousands separators (commas) but keep decimal dots
            $price = str_replace(',', '', $price);
        }

        // Convert to float and back to ensure consistent decimal places
        if (is_numeric($price)) {
            return number_format((float)$price, 2, '.', '');
        }

        return $price;
    }

    public function getJsonResult() {
        return json_encode($this->result, JSON_PRETTY_PRINT);
    }

    public static function validateProduct($product_id, $wait_seconds = 5) {
        $validator = new self($product_id, $wait_seconds);
        return $validator->validate();
    }
}

// Main execution when called directly
if (php_sapi_name() === 'cli') {
    try {
        $product_id = isset($argv[1]) ? (int)$argv[1] : null;
        $wait_seconds = isset($argv[2]) ? (int)$argv[2] : 10;

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
