<?php
declare( strict_types=1 );

namespace WooCommerce\Facebook\API\ProductCatalog\Products\Id;

use WooCommerce\Facebook\API\Request as ApiRequest;

defined( 'ABSPATH' ) || exit;

/**
 * Request object for Product Catalog > Products > Get Graph Api.
 *
 * @link https://developers.facebook.com/docs/marketing-api/reference/product-catalog/products/
 */
class Request extends ApiRequest {

	/**
	 * @param string $facebook_product_catalog_id Facebook Product Catalog ID.
	 * @param string $facebook_product_retailer_id Facebook Product Retailer ID.
	 * @param bool   $detailed_fields Whether to request detailed fields for comparison (E2E testing only).
	 */
	public function __construct( string $facebook_product_catalog_id, string $facebook_product_retailer_id, bool $detailed_fields = false ) {

		/**
		 * We use the endpoint with filter to get the product id and group id for new products to check if the product is already synced to Facebook.
		 */
		$path = "/{$facebook_product_catalog_id}/products";
		parent::__construct( $path, 'GET' );

		// Choose fields based on parameter - detailed fields for E2E testing, minimal for production
		$fields = $detailed_fields
			? 'id,name,price,description,brand,condition,availability,image_url,retailer_id,color,size,material,pattern,age_group,gender,mpn,gtin,custom_label_0,custom_label_1,custom_label_2,custom_label_3,custom_label_4,product_group{id}'
			: 'id,product_group{id}';  // Original production behavior

		$this->set_params(
			array(
				'filter' => '{"retailer_id":{"eq":"' . $facebook_product_retailer_id . '"}}',
				'fields' => $fields,
			)
		);
	}
}
