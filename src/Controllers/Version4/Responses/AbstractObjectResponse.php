<?php
/**
 * Convert an object to the product schema format.
 *
 * @package Automattic/WooCommerce/RestApi
 */

namespace Automattic\WooCommerce\RestApi\Controllers\Version4\Responses;

use phpDocumentor\Reflection\Types\Object_;

defined( 'ABSPATH' ) || exit;

/**
 * AbstractObjectResponse class.
 */
abstract class AbstractObjectResponse {

	/**
	 * Convert object to match data in the schema.
	 *
	 * @param mixed $object Object.
	 * @param string $context Request context. Options: 'view' and 'edit'.
	 * @return array
	 */
	abstract public function prepare_response( $object, $context );

	/**
	 * Get fields for an object if getter is defined.
	 *
	 * @param object $object  Object we are fetching response for.
	 * @param string $context Context of the request. Can be `view` or `edit`.
	 * @param array  $fields  List of fields to fetch.

	 * @return array Data fetched from getters.
	 */
	public function fetch_fields_using_getters( $object, $context, $fields ) {
		$data = array();
		foreach ( $fields as $field ) {
			if ( method_exists( $this, "get_$field" ) ) {
				$data[ $field ] = $this->{"get_$field"}( $object, $context );
			}
		}
		return $data;
	}
}
