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

    public function __construct($product_id, $wait_seconds = 5) {
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
        $this->product = wc_get_product($this->product_id);
        // Fail fast if the product ID doesn't exist in WooCommerce
        if (!$this->product) {
            throw new Exception("Product {$this->product_id} not found");
        }

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

    public function validate($product_type = 'auto') {
        try {
            // Auto-detect product type if not specified
            $actual_type = $product_type === 'auto' ? $this->product->get_type() : $product_type;

            // Step 1: Get both platform data (WooCommerce + Facebook)
            $data = $this->getBothPlatformData($actual_type);

            // Step 2: Check sync status using fetched Facebook data
            $this->checkSyncStatus($data);

            // Step 3: Compare fields between platforms
            $this->compareFields($data);

            $this->result['success'] = true;
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

        // Get Facebook data
        $facebook_data = $this->fetchFacebookData($retailer_id, 'simple');

        return [
            'type' => 'simple',
            'woo_data' => $woo_data,
            'facebook_data' => $facebook_data,
            'products' => [
                [
                    'id' => $this->product_id,
                    'retailer_id' => $retailer_id,
                    'woo_data' => $woo_data,
                    'facebook_data' => $facebook_data
                ]
            ]
        ];
    }

    /**
     * Get data for variable products (parent + all variations)
     */
    private function getVariableProductData() {
        $products = [];
        $failed_variations = [];

        // Parent product group
        $parent_retailer_id = WC_Facebookcommerce_Utils::get_fb_retailer_id($this->product);
        $this->result['retailer_id'] = $parent_retailer_id;

        $parent_woo_data = $this->extractWooCommerceFields($this->product, $parent_retailer_id);
        $parent_facebook_data = $this->fetchFacebookData($parent_retailer_id, 'variable_parent');

        $products[] = [
            'id' => $this->product_id,
            'type' => 'parent_group',
            'retailer_id' => $parent_retailer_id,
            'woo_data' => $parent_woo_data,
            'facebook_data' => $parent_facebook_data
        ];

        $this->result['debug'][] = "Extracted parent group data";

        // All variations
        $variations = $this->product->get_children();
        $this->result['debug'][] = "Found " . count($variations) . " variations to validate";

        foreach ($variations as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation) {
                $failed_variations[] = $variation_id;
                $this->result['debug'][] = "Variation {$variation_id} not found in WooCommerce";
                continue;
            }

            try {
                $var_retailer_id = WC_Facebookcommerce_Utils::get_fb_retailer_id($variation);
                $var_woo_data = $this->extractWooCommerceFields($variation, $var_retailer_id);
                $var_facebook_data = $this->fetchFacebookData($var_retailer_id, 'variable_item');

                // Add variation attributes for display
                $attributes = $variation->get_variation_attributes();
                $attr_display = [];
                foreach ($attributes as $key => $value) {
                    $clean_key = str_replace('attribute_', '', $key);
                    $attr_display[] = "{$clean_key}: {$value}";
                }

                $products[] = [
                    'id' => $variation_id,
                    'type' => 'variation',
                    'attributes' => implode(', ', $attr_display),
                    'retailer_id' => $var_retailer_id,
                    'woo_data' => $var_woo_data,
                    'facebook_data' => $var_facebook_data
                ];

                $this->result['debug'][] = "Extracted variation {$variation_id} data: " . implode(', ', $attr_display);
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
            'woo_data' => $parent_woo_data, // For main result compatibility
            'facebook_data' => $parent_facebook_data, // For main result compatibility
            'products' => $products
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

            // Define fields we want to fetch for validation - note product_group{id} for nested structure
            $fields = 'id,name,price,description,availability,retailer_id,condition,brand,color,size,product_group{id}';

            $this->result['debug'][] = "Fetching Facebook data for retailer_id: {$retailer_id} (context: {$context})";

            $response = $api->get_product_facebook_ids($catalog_id, $retailer_id, $fields);

            if ($response && $response->response_data && isset($response->response_data['data'][0])) {
                $fb_data = $response->response_data['data'][0];
                $this->result['debug'][] = "Successfully fetched Facebook data for {$retailer_id}";

                $result_data = [
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
                    'product_group_id' => isset($fb_data['product_group']['id']) ? $fb_data['product_group']['id'] : null,
                    'found' => true
                ];

                // Log product group info for variable product items
                if ($context === 'variable_item' && isset($fb_data['product_group']['id'])) {
                    $this->result['debug'][] = "Variation {$retailer_id} belongs to Facebook product group: {$fb_data['product_group']['id']}";
                }

                return $result_data;
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
     * UNIFIED: Check sync status for any product type
     */
    private function checkSyncStatus($data) {
        if ($data['type'] === 'variable') {
            // For variable products, check parent group status
            $parent_product = $data['products'][0]; // First is always parent
            if ($parent_product['facebook_data']['found'] ?? false) {
                $this->result['sync_status'] = 'synced';
                $this->result['facebook_id'] = $parent_product['facebook_data']['id'] ?? null;
                $this->result['debug'][] = "Variable product group is synced with Facebook ID: " . ($this->result['facebook_id'] ?? 'unknown');
            } else {
                $this->result['sync_status'] = 'not_synced';
                $this->result['debug'][] = "Variable product group is not synced to Facebook";
            }
        } else {
            // For simple products
            if ($data['facebook_data']['found'] ?? false) {
                $this->result['sync_status'] = 'synced';
                $this->result['facebook_id'] = $data['facebook_data']['id'] ?? null;
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

        foreach ($data['products'] as $product_data) {
            if (!($product_data['facebook_data']['found'] ?? false)) {
                continue; // Skip products not found in Facebook
                // TODO should we log these as mismatches or is it already being done?
            }

            $compared_products++;
            $product_mismatches = $this->compareProductFields(
                $product_data['woo_data'],
                $product_data['facebook_data'],
                $product_data['id']
            );

            if (count($product_mismatches) > 0) {
                $mismatches = array_merge($mismatches, $product_mismatches);

                if ($product_data['type'] === 'variation') {
                    $this->result['debug'][] = "Field mismatches found for variation {$product_data['id']} ({$product_data['attributes']})";
                } else {
                    $this->result['debug'][] = "Field mismatches found for product {$product_data['id']}";
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

            if ($this->normalizeValue($woo_value) !== $this->normalizeValue($fb_value)) {
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

    private function normalizeValue($value) {
        return trim(strtolower((string)$value));
    }

    public function getJsonResult() {
        return json_encode($this->result, JSON_PRETTY_PRINT);
    }

    public static function validateProduct($product_id, $wait_seconds = 5, $product_type = 'auto') {
        $validator = new self($product_id, $wait_seconds);
        return $validator->validate($product_type);
    }
}

// Main execution when called directly
if (php_sapi_name() === 'cli') {
    try {
        $product_id = isset($argv[1]) ? (int)$argv[1] : null;
        $wait_seconds = isset($argv[2]) ? (int)$argv[2] : 10;
        $product_type = isset($argv[3]) ? $argv[3] : 'auto';

        if (!$product_id) {
            echo json_encode(['success' => false, 'error' => 'Product ID required']);
            exit(1);
        }

        $validator = new FacebookSyncValidator($product_id, $wait_seconds);
        $result = $validator->validate($product_type);
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
