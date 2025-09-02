# Facebook Sync Validation for E2E Testing

## Overview

We needed a way to automatically verify that products created in WooCommerce are properly syncing to Facebook's catalog during our end-to-end tests. Previously, this was a manual process that was both time-consuming and prone to human error. This document explains how we built an automated solution that integrates seamlessly with our existing test infrastructure.

## The Problem We Were Solving

When running E2E tests for the Facebook for WooCommerce plugin, we create products in WooCommerce and expect them to sync to Facebook. But how do we know if the sync actually worked? And more importantly, how do we verify that the product data is consistent between both platforms?

Before this implementation, we had to:
- Manually check Facebook's catalog after each test
- Compare field values by hand
- Hope we didn't miss any discrepancies
- Deal with inconsistent results across different environments

This wasn't sustainable, especially as we scaled up our testing efforts.

## Our Solution

We built a PHP-based validator that plugs directly into our Playwright E2E tests. Here's how it works at a high level:

1. **Test creates a product** in WooCommerce (like it always did)
2. **Extract the product ID** from the WordPress URL after creation
3. **Call our validator** with the product ID and wait for sync
4. **Validator checks Facebook** to see if the product exists and compares field data
5. **Report back** with detailed results about sync status and any mismatches
6. **Clean up** the test product when we're done

## Implementation Details

### The E2E Test Side

In our `product-creation.spec.js` file, we added a helper function that makes validation super simple:

```javascript
// After creating a product and getting its ID from the URL
const urlMatch = currentUrl.match(/post=(\d+)/);
productId = parseInt(urlMatch[1]);

// Validate the sync with Facebook
await validateFacebookSync(productId, 10, 'simple');
```

The `validateFacebookSync` function handles all the complexity of calling our PHP validator and processing the results. It shows clear output about what happened:

```
🎉 Facebook Sync Validation Results:
✅ Sync Status: synced
📦 Product ID: 123
🏷️  Retailer ID: wc_post_123
🔗 Facebook ID: 1234567890
✅ No field mismatches detected
```

### The Validator Module

The heart of our solution is `e2e-facebook-sync-validator-simple.php`. It's a standalone PHP script that can be run from the command line or directly from a test.

The validator follows a straightforward pipeline:

```php
class E2EValidator {
    public function validate($product_type = 'simple') {
        try {
            $this->checkSyncStatus();      // Is the product in Facebook?
            $this->compareFields($product_type);  // Do the fields match?
            $this->result['success'] = true;
        } catch (Exception $e) {
            $this->result['error'] = $e->getMessage();
        }
        return $this->result;
    }
}
```

#### Checking Sync Status

First, we need to know if the product even made it to Facebook:

```php
private function checkSyncStatus() {
    // Generate the retailer ID (WooCommerce's internal identifier)
    $this->result['retailer_id'] = WC_Facebookcommerce_Utils::get_fb_retailer_id($this->product);

    // Check if Facebook has this product
    $fb_product_item_id = $this->integration->get_product_fbid(
        WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID,
        $this->product_id,
        $this->product
    );

    if ($fb_product_item_id) {
        $this->result['sync_status'] = 'synced';
        $this->result['facebook_id'] = $fb_product_item_id;
    } else {
        throw new Exception('Product not found in Facebook catalog');
    }
}
```

#### Comparing Fields

Once we know the product exists, we compare the actual data:

```php
private function compareFields($product_type = 'simple') {
    // Get the list of fields we care about for this product type
    $fields_to_check = $this->getFieldsForProductType($product_type);

    // Get WooCommerce data (what we sent to Facebook)
    $fb_product = new WC_Facebook_Product($this->product_id);
    $woo_data = $fb_product->prepare_product(
        $this->result['retailer_id'],
        WC_Facebook_Product::PRODUCT_PREP_TYPE_ITEMS_BATCH
    );

    // Get Facebook data (what Facebook actually has)
    $facebook_data = $this->getFacebookData($fields_to_check);

    // Find any mismatches
    $this->findMismatches($woo_data, $facebook_data, $fields_to_check);
}
```

### Supporting Different Product Types

We designed the system to handle different types of products. Simple products need basic fields like title, price, and description. Variable products also need color, size, and variant information.

```php
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
```

### API Optimizations

One thing we discovered early on was that we were making too many calls to Facebook's API. The original approach was:

1. Call Facebook to check if product exists
2. Call Facebook again to get product data for comparison

We optimized this down to a single call by being smarter about what we request:

```php
private function getFacebookData($fields_to_check) {
    // Build a targeted request with only the fields we need
    $facebook_fields = ['id', 'product_group{id}'];  // Always need these

    foreach ($fields_to_check as $field) {
        $facebook_field = $this->mapFieldName($field);
        if (!in_array($facebook_field, $facebook_fields)) {
            $facebook_fields[] = $facebook_field;
        }
    }

    $fields_string = implode(',', $facebook_fields);

    // Single API call with exactly what we need
    $response = $api->get_product_facebook_ids($catalog_id, $retailer_id, $fields_string);

    return $response->response_data['data'][0] ?? [];
}
```

This cut our API usage in half and made the tests run faster.

### Field Mapping

WooCommerce and Facebook don't always use the same field names, so we need to translate between them:

```php
private function mapFieldName($woo_field) {
    $field_map = [
        'title' => 'name',                    // WooCommerce calls it 'title', Facebook calls it 'name'
        'retailer_id' => 'retailer_id',       // These match
        'price' => 'price',                   // These match
        'description' => 'description',       // These match
        'brand' => 'brand',                   // These match
        'condition' => 'condition',           // These match
        'availability' => 'availability',     // These match
        'color' => 'color',                   // Variable product fields
        'size' => 'size',
        'external_variant_id' => 'external_variant_id'
    ];

    return $field_map[$woo_field] ?? $woo_field;
}
```

### Changes to Core API Files

To support our optimized approach, we had to make minimal changes to two core files:

**`includes/API.php`** - Updated the method signature to accept a field string:
```php
public function get_product_facebook_ids(
    string $facebook_product_catalog_id,
    string $facebook_retailer_id,
    string $fields_string = 'id,product_group{id}'
): API\ProductCatalog\Products\Id\Response
```

**`includes/API/ProductCatalog/Products/Id/Request.php`** - just pass through the field string:
```php
public function __construct(
    string $facebook_product_catalog_id,
    string $facebook_product_retailer_id,
    string $fields_string = 'id,product_group{id}'
)
```

We kept these changes as minimal as possible to avoid breaking existing functionality.


### MBE Automation

We leverage the existing connected test setup to automatically configure the Meta Business Extension (MBE) during plugin installation. This means our tests don't need to worry about Facebook connection setup - it's already handled.

## Test Cleanup

One important aspect is cleaning up after ourselves. We don't want to leave test products lying around:

```javascript
// In the test's finally block
if (productId) {
    await cleanupProduct(productId);
}

async function cleanupProduct(productId) {
    try {
        const { execAsync } = require('util').promisify(require('child_process').exec);
        await execAsync(
            `php -r "require_once('/tmp/wordpress/wp-load.php'); wp_delete_post(${productId}, true);"`,
            { cwd: __dirname }
        );
        console.log(`✅ Product ${productId} deleted from WooCommerce`);
    } catch (error) {
        console.log(`⚠️ Cleanup failed: ${error.message}`);
    }
}
```

This runs regardless of whether the test passed or failed, ensuring we don't accumulate test data.

## Debugging and Monitoring

The validator provides comprehensive debug information:

```json
{
    "success": true,
    "product_id": 123,
    "sync_status": "synced",
    "retailer_id": "wc_post_123",
    "facebook_id": "1234567890",
    "mismatches": {},
    "error": null,
    "debug": [
        "WordPress loaded from: /tmp/wordpress/wp-load.php",
        "Waited 10 seconds before validation",
        "Generated retailer_id: wc_post_123",
        "Product found in Facebook catalog: 1234567890",
        "Found 0 field mismatches"
    ]
}
```

When there are mismatches, they're clearly reported:

```json
"mismatches": {
    "price": {
        "woocommerce": "19.99",
        "facebook": "20.00"
    }
}
```

This makes it easy to spot and debug sync issues.

## Performance Improvements

The optimizations we made resulted in significant performance gains:

- **50% reduction in Facebook API calls** (from 2 calls to 1)
- **Faster test execution** due to targeted field requests
- **Reduced bandwidth usage** by only requesting needed fields
- **More reliable results** with direct WordPress path loading

## What This Enables

With this system in place, we can now:

- **Run comprehensive sync validation** as part of our regular E2E test suite
- **Catch sync issues immediately** instead of discovering them later
- **Validate different product types** with appropriate field sets
- **Get detailed reports** about what's working and what isn't
- **Scale our testing** without manual verification overhead

## Future Enhancements

We've built this with extensibility in mind. Some areas for future improvement:

- **Support for more product types** (grouped, external, etc.)
- **Batch validation** for testing multiple products at once
- **Performance metrics** to track sync timing
- **Advanced reporting** for trend analysis
- **Configurable field mappings** for different use cases

## Conclusion

This implementation solves a real problem we were facing with manual sync verification. It's built to be reliable, fast, and maintainable. The modular design means we can easily extend it as our testing needs evolve.

The key insight was realizing that we needed to validate not just that sync happened, but that the data is actually correct. By automating this process and integrating it seamlessly with our existing tests, we've significantly improved our confidence in the Facebook integration functionality.

Most importantly, it's designed to be developer-friendly. Adding sync validation to a new test is as simple as calling one function with a product ID. The system handles all the complexity behind the scenes and provides clear, actionable feedback about what happened.
