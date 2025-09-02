<?php
/**
 * Simplified E2E Facebook Sync Validator
 *
 * Streamlined validation for Facebook sync in E2E tests
 * Supports different product types (simple, variable, etc.)
 *
 * Usage: php e2e-facebook-sync-validator-simple.php <product_id> [wait_seconds] [product_type]
 *
 * Returns JSON response with essential validation results
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
class E2EValidator {

    private $product_id;
    private $product;
    private $integration;
    private $result;

    public function __construct($product_id, $wait_seconds = 10, $wp_path = null) {
        $this->product_id = (int)$product_id;
        $this->result = [
            'success' => false,
            'product_id' => $this->product_id,
            'sync_status' => 'unknown',
            'retailer_id' => null,
            'facebook_id' => null,
            'mismatches' => [],
            'error' => null,
            'debug' => []
        ];

        if ($wp_path) {
            $this->result['debug'][] = "WordPress loaded from: " . $wp_path;
        }

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
    }

    private function initializeIntegration() {
        $this->integration = facebook_for_woocommerce()->get_integration();
        if (!$this->integration || !$this->integration->is_configured()) {
            throw new Exception('Facebook integration not configured');
        }
    }

    public function validate($product_type = 'simple') {
        try {
            $this->checkSyncStatus();
            $this->compareFields($product_type);
            $this->result['success'] = true;
        } catch (Exception $e) {
            $this->result['error'] = $e->getMessage();
        }
        return $this->result;
    }

    private function checkSyncStatus() {
        $this->result['retailer_id'] = WC_Facebookcommerce_Utils::get_fb_retailer_id($this->product);

        $fb_product_item_id = $this->integration->get_product_fbid(
            WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID,
            $this->product_id,
            $this->product
        );

        if ($fb_product_item_id) {
            $this->result['facebook_id'] = $fb_product_item_id;
            $this->result['sync_status'] = 'synced';
        } else {
            $this->result['sync_status'] = 'not_synced';
            throw new Exception('Product not found in Facebook catalog');
        }
    }

    private function compareFields($product_type = 'simple') {
        // Get WooCommerce data
        $fb_product = new WC_Facebook_Product($this->product_id);
        $woo_data = $fb_product->prepare_product(
            $this->result['retailer_id'],
            WC_Facebook_Product::PRODUCT_PREP_TYPE_ITEMS_BATCH
        );

        // Get Facebook data with specific fields
        $fields_to_check = $this->getFieldsForProductType($product_type);
        $facebook_data = $this->getFacebookData($fields_to_check);

        // Compare and find mismatches
        $this->findMismatches($woo_data, $facebook_data, $fields_to_check);
    }

    private function getFieldsForProductType($product_type) {
        $base_fields = ['title', 'price', 'description', 'brand', 'condition', 'availability', 'retailer_id'];

        switch ($product_type) {
            case 'variable':
                return array_merge($base_fields, ['color', 'size', 'external_variant_id']);
            case 'simple':
            default:
                return $base_fields;
        }
    }

    private function getFacebookData($fields_to_check) {
        try {
            $api = facebook_for_woocommerce()->get_api();
            $catalog_id = $this->integration->get_product_catalog_id();

            // Build fields string using mapFieldName function
            $facebook_fields = ['id', 'product_group{id}'];
            foreach ($fields_to_check as $field) {
                $facebook_field = $this->mapFieldName($field);
                if (!in_array($facebook_field, $facebook_fields)) {
                    $facebook_fields[] = $facebook_field;
                }
            }
            $fields_string = implode(',', $facebook_fields);

            $response = $api->get_product_facebook_ids($catalog_id, $this->result['retailer_id'], $fields_string);

            if (!empty($response->response_data['data'][0])) {
                return $response->response_data['data'][0];
            }
        } catch (Exception $e) {
            $this->result['debug'][] = "Facebook API error: " . $e->getMessage();
        }

        return [];
    }

    private function findMismatches($woo_data, $facebook_data, $fields_to_check) {
        $mismatches = [];

        foreach ($fields_to_check as $field) {
            $woo_value = $woo_data[$field] ?? '';
            $fb_field = $this->mapFieldName($field);
            $fb_value = $facebook_data[$fb_field] ?? '';

            if (!empty($woo_value) && !empty($fb_value) && $woo_value !== $fb_value) {
                $mismatches[$field] = [
                    'woocommerce' => $woo_value,
                    'facebook' => $fb_value
                ];
            }
        }

        $this->result['mismatches'] = $mismatches;
        $this->result['debug'][] = "Found " . count($mismatches) . " field mismatches";
    }

    private function mapFieldName($woo_field) {
        $field_map = [
            'title' => 'name',
            'retailer_id' => 'retailer_id',
            'price' => 'price',
            'description' => 'description',
            'brand' => 'brand',
            'condition' => 'condition',
            'availability' => 'availability',
            'color' => 'color',
            'size' => 'size',
            'external_variant_id' => 'external_variant_id'
        ];

        return $field_map[$woo_field] ?? $woo_field;
    }

    public function getJsonResult() {
        return json_encode($this->result, JSON_PRETTY_PRINT);
    }
}

// Main execution when called directly
if (php_sapi_name() === 'cli') {
    try {
        $product_id = isset($argv[1]) ? (int)$argv[1] : null;
        $wait_seconds = isset($argv[2]) ? (int)$argv[2] : 10;
        $product_type = isset($argv[3]) ? $argv[3] : 'simple';

        if (!$product_id) {
            echo json_encode(['success' => false, 'error' => 'Product ID required']);
            exit(1);
        }

        $validator = new E2EValidator($product_id, $wait_seconds, $wp_path);
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
