<?php
/**
 * REST API Coupons controller
 *
 * Handles requests to the /coupons endpoint.
 *
 * @package Automattic/WooCommerce/RestApi
 */

namespace Automattic\WooCommerce\RestApi\Controllers\Version4;

defined( 'ABSPATH' ) || exit;

/**
 * REST API Coupons controller class.
 */
class Coupons extends AbstractObjectsController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'coupons';

	/**
	 * Post type.
	 *
	 * @var string
	 */
	protected $post_type = 'shop_coupon';

	/**
	 * Get object.
	 *
	 * @since  3.0.0
	 * @param  int $id Object ID.
	 * @return \WC_Data
	 */
	protected function get_object( $id ) {
		return new \WC_Coupon( $id );
	}

	/**
	 * Get data for this object in the format of this endpoint's schema.
	 *
	 * @param \WC_Coupon       $object Object to prepare.
	 * @param \WP_REST_Request $request Request object.
	 * @return array Array of data in the correct format.
	 */
	protected function get_data_for_response( $object, $request ) {
		$data = $object->get_data();

		$format_decimal = array( 'amount', 'minimum_amount', 'maximum_amount' );
		$format_date    = array( 'date_created', 'date_modified', 'date_expires' );
		$format_null    = array( 'usage_limit', 'usage_limit_per_user', 'limit_usage_to_x_items' );

		// Format decimal values.
		foreach ( $format_decimal as $key ) {
			$data[ $key ] = wc_format_decimal( $data[ $key ], 2 );
		}

		// Format date values.
		foreach ( $format_date as $key ) {
			$datetime              = $data[ $key ];
			$data[ $key ]          = wc_rest_prepare_date_response( $datetime, false );
			$data[ $key . '_gmt' ] = wc_rest_prepare_date_response( $datetime );
		}

		// Format null values.
		foreach ( $format_null as $key ) {
			$data[ $key ] = $data[ $key ] ? $data[ $key ] : null;
		}

		return array(
			'id'                          => $object->get_id(),
			'code'                        => $data['code'],
			'amount'                      => $data['amount'],
			'date_created'                => $data['date_created'],
			'date_created_gmt'            => $data['date_created_gmt'],
			'date_modified'               => $data['date_modified'],
			'date_modified_gmt'           => $data['date_modified_gmt'],
			'discount_type'               => $data['discount_type'],
			'description'                 => $data['description'],
			'date_expires'                => $data['date_expires'],
			'date_expires_gmt'            => $data['date_expires_gmt'],
			'usage_count'                 => $data['usage_count'],
			'individual_use'              => $data['individual_use'],
			'product_ids'                 => $data['product_ids'],
			'excluded_product_ids'        => $data['excluded_product_ids'],
			'usage_limit'                 => $data['usage_limit'],
			'usage_limit_per_user'        => $data['usage_limit_per_user'],
			'limit_usage_to_x_items'      => $data['limit_usage_to_x_items'],
			'free_shipping'               => $data['free_shipping'],
			'product_categories'          => $data['product_categories'],
			'excluded_product_categories' => $data['excluded_product_categories'],
			'exclude_sale_items'          => $data['exclude_sale_items'],
			'minimum_amount'              => $data['minimum_amount'],
			'maximum_amount'              => $data['maximum_amount'],
			'email_restrictions'          => $data['email_restrictions'],
			'used_by'                     => $data['used_by'],
			'meta_data'                   => $data['meta_data'],
		);
	}

	/**
	 * Prepare objects query.
	 *
	 * @since  3.0.0
	 * @param  \WP_REST_Request $request Full details about the request.
	 * @return array
	 */
	protected function prepare_objects_query( $request ) {
		$args = parent::prepare_objects_query( $request );

		if ( ! empty( $request['code'] ) ) {
			$id               = wc_get_coupon_id_by_code( $request['code'] );
			$args['post__in'] = array( $id );
		}

		if ( ! empty( $request['search'] ) ) {
			$args['search'] = $request['search'];
			$args['s']      = false;
		}

		// Set custom args to handle later during clauses.
		$custom_keys = array(
			'updated_since',
			'created_since',
			'updated_before',
			'created_before',
		);

		foreach ( $custom_keys as $key ) {
			if ( ! empty( $request[ $key ] ) ) {
				$args[ $key ] = $request[ $key ];
			}
		}

		return $args;
	}

	/**
	 * Prepare a single coupon for create or update.
	 *
	 * @param  \WP_REST_Request $request Request object.
	 * @param  bool             $creating If is creating a new object.
	 * @return \WP_Error|\WC_Data
	 */
	protected function prepare_object_for_database( $request, $creating = false ) {
		$id        = isset( $request['id'] ) ? absint( $request['id'] ) : 0;
		$coupon    = new \WC_Coupon( $id );
		$schema    = $this->get_item_schema();
		$data_keys = array_keys( array_filter( $schema['properties'], array( $this, 'filter_writable_props' ) ) );

		// Validate required POST fields.
		if ( $creating && empty( $request['code'] ) ) {
			return new \WP_Error( 'woocommerce_rest_empty_coupon_code', sprintf( __( 'The coupon code cannot be empty.', 'woocommerce-rest-api' ), 'code' ), array( 'status' => 400 ) );
		}

		// Handle all writable props.
		foreach ( $data_keys as $key ) {
			$value = $request[ $key ];

			if ( ! is_null( $value ) ) {
				switch ( $key ) {
					case 'code':
						$coupon_code  = wc_format_coupon_code( $value );
						$id           = $coupon->get_id() ? $coupon->get_id() : 0;
						$id_from_code = wc_get_coupon_id_by_code( $coupon_code, $id );

						if ( $id_from_code ) {
							return new \WP_Error( 'woocommerce_rest_coupon_code_already_exists', __( 'The coupon code already exists', 'woocommerce-rest-api' ), array( 'status' => 400 ) );
						}

						$coupon->set_code( $coupon_code );
						break;
					case 'meta_data':
						if ( is_array( $value ) ) {
							foreach ( $value as $meta ) {
								$coupon->update_meta_data( $meta['key'], $meta['value'], isset( $meta['id'] ) ? $meta['id'] : 0 );
							}
						}
						break;
					case 'description':
						$coupon->set_description( wp_filter_post_kses( $value ) );
						break;
					default:
						if ( is_callable( array( $coupon, "set_{$key}" ) ) ) {
							$coupon->{"set_{$key}"}( $value );
						}
						break;
				}
			}
		}

		/**
		 * Filters an object before it is inserted via the REST API.
		 *
		 * The dynamic portion of the hook name, `$this->post_type`,
		 * refers to the object type slug.
		 *
		 * @param \WC_Data         $coupon   Object object.
		 * @param \WP_REST_Request $request  Request object.
		 * @param bool            $creating If is creating a new object.
		 */
		return apply_filters( "woocommerce_rest_pre_insert_{$this->post_type}_object", $coupon, $request, $creating );
	}

	/**
	 * Get the Coupon's schema, conforming to JSON Schema.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => $this->post_type,
			'type'       => 'object',
			'properties' => array(
				'id'                          => array(
					'description' => __( 'Unique identifier for the object.', 'woocommerce-rest-api' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'code'                        => array(
					'description' => __( 'Coupon code.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'required'    => true,
				),
				'amount'                      => array(
					'description' => __( 'The amount of discount. Should always be numeric, even if setting a percentage.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'date_created'                => array(
					'description' => __( "The date the coupon was created, in the site's timezone.", 'woocommerce-rest-api' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_created_gmt'            => array(
					'description' => __( 'The date the coupon was created, as GMT.', 'woocommerce-rest-api' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_modified'               => array(
					'description' => __( "The date the coupon was last modified, in the site's timezone.", 'woocommerce-rest-api' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_modified_gmt'           => array(
					'description' => __( 'The date the coupon was last modified, as GMT.', 'woocommerce-rest-api' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'discount_type'               => array(
					'description' => __( 'Determines the type of discount that will be applied.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'default'     => 'fixed_cart',
					'enum'        => array_keys( wc_get_coupon_types() ),
					'context'     => array( 'view', 'edit' ),
				),
				'description'                 => array(
					'description' => __( 'Coupon description.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'date_expires'                => array(
					'description' => __( "The date the coupon expires, in the site's timezone.", 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'date_expires_gmt'            => array(
					'description' => __( 'The date the coupon expires, as GMT.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'usage_count'                 => array(
					'description' => __( 'Number of times the coupon has been used already.', 'woocommerce-rest-api' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'individual_use'              => array(
					'description' => __( 'If true, the coupon can only be used individually. Other applied coupons will be removed from the cart.', 'woocommerce-rest-api' ),
					'type'        => 'boolean',
					'default'     => false,
					'context'     => array( 'view', 'edit' ),
				),
				'product_ids'                 => array(
					'description' => __( 'List of product IDs the coupon can be used on.', 'woocommerce-rest-api' ),
					'type'        => 'array',
					'items'       => array(
						'type' => 'integer',
					),
					'context'     => array( 'view', 'edit' ),
				),
				'excluded_product_ids'        => array(
					'description' => __( 'List of product IDs the coupon cannot be used on.', 'woocommerce-rest-api' ),
					'type'        => 'array',
					'items'       => array(
						'type' => 'integer',
					),
					'context'     => array( 'view', 'edit' ),
				),
				'usage_limit'                 => array(
					'description' => __( 'How many times the coupon can be used in total.', 'woocommerce-rest-api' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
				),
				'usage_limit_per_user'        => array(
					'description' => __( 'How many times the coupon can be used per customer.', 'woocommerce-rest-api' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
				),
				'limit_usage_to_x_items'      => array(
					'description' => __( 'Max number of items in the cart the coupon can be applied to.', 'woocommerce-rest-api' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
				),
				'free_shipping'               => array(
					'description' => __( 'If true and if the free shipping method requires a coupon, this coupon will enable free shipping.', 'woocommerce-rest-api' ),
					'type'        => 'boolean',
					'default'     => false,
					'context'     => array( 'view', 'edit' ),
				),
				'product_categories'          => array(
					'description' => __( 'List of category IDs the coupon applies to.', 'woocommerce-rest-api' ),
					'type'        => 'array',
					'items'       => array(
						'type' => 'integer',
					),
					'context'     => array( 'view', 'edit' ),
				),
				'excluded_product_categories' => array(
					'description' => __( 'List of category IDs the coupon does not apply to.', 'woocommerce-rest-api' ),
					'type'        => 'array',
					'items'       => array(
						'type' => 'integer',
					),
					'context'     => array( 'view', 'edit' ),
				),
				'exclude_sale_items'          => array(
					'description' => __( 'If true, this coupon will not be applied to items that have sale prices.', 'woocommerce-rest-api' ),
					'type'        => 'boolean',
					'default'     => false,
					'context'     => array( 'view', 'edit' ),
				),
				'minimum_amount'              => array(
					'description' => __( 'Minimum order amount that needs to be in the cart before coupon applies.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'maximum_amount'              => array(
					'description' => __( 'Maximum order amount allowed when using the coupon.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
				'email_restrictions'          => array(
					'description' => __( 'List of email addresses that can use this coupon.', 'woocommerce-rest-api' ),
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
					),
					'context'     => array( 'view', 'edit' ),
				),
				'used_by'                     => array(
					'description' => __( 'List of user IDs (or guest email addresses) that have used the coupon.', 'woocommerce-rest-api' ),
					'type'        => 'array',
					'items'       => array(
						'type' => 'integer',
					),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'meta_data'                   => array(
					'description' => __( 'Meta data.', 'woocommerce-rest-api' ),
					'type'        => 'array',
					'context'     => array( 'view', 'edit' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'    => array(
								'description' => __( 'Meta ID.', 'woocommerce-rest-api' ),
								'type'        => 'integer',
								'context'     => array( 'view', 'edit' ),
								'readonly'    => true,
							),
							'key'   => array(
								'description' => __( 'Meta key.', 'woocommerce-rest-api' ),
								'type'        => 'string',
								'context'     => array( 'view', 'edit' ),
							),
							'value' => array(
								'description' => __( 'Meta value.', 'woocommerce-rest-api' ),
								'type'        => 'mixed',
								'context'     => array( 'view', 'edit' ),
							),
						),
					),
				),
			),
		);
		return $this->add_additional_fields_schema( $schema );
	}

	/**
	 * Get the query params for collections of attachments.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();

		$params['code'] = array(
			'description'       => __( 'Limit result set to resources with a specific code.', 'woocommerce-rest-api' ),
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['search'] = array(
			'description'       => __( 'Limit results to coupons with codes matching a given string.', 'woocommerce-rest-api' ),
			'type'              => 'string',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['updated_since'] = array(
			'description'       => __( 'Filter coupons by since last updated date.', 'woocommerce-rest-api' ),
			'type'              => 'string',
			'format'            => 'date-time',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['created_since'] = array(
			'description'       => __( 'Filter coupons by since created date.', 'woocommerce-rest-api' ),
			'type'              => 'string',
			'format'            => 'date-time',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['updated_before'] = array(
			'description'       => __( 'Filter coupons by before last updated date.', 'woocommerce-rest-api' ),
			'type'              => 'string',
			'format'            => 'date-time',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['created_before'] = array(
			'description'       => __( 'Filter coupons by before created date.', 'woocommerce-rest-api' ),
			'type'              => 'string',
			'format'            => 'date-time',
			'validate_callback' => 'rest_validate_request_arg',
		);

		return $params;
	}

	/**
	 * Only return writable props from schema.
	 *
	 * @param  array $schema Schema array.
	 * @return bool
	 */
	protected function filter_writable_props( $schema ) {
		return empty( $schema['readonly'] );
	}

	/**
	 * Get a collection of posts and add the code search option to \WP_Query.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_items( $request ) {
		add_filter( 'posts_where', array( $this, 'add_wp_query_search_code_filter' ), 10, 2 );
		$response = parent::get_items( $request );
		remove_filter( 'posts_where', array( $this, 'add_wp_query_search_code_filter' ), 10 );
		return $response;
	}

	/**
	 * Add code searching to the WP Query
	 *
	 * @param string $where Where clause used to search posts.
	 * @param object $wp_query \WP_Query object.
	 * @return string
	 */
	public function add_wp_query_search_code_filter( $where, $wp_query ) {
		global $wpdb;

		$search = $wp_query->get( 'search' );
		if ( $search ) {
			$search = $wpdb->esc_like( $search );
			$search = "'%" . $search . "%'";
			$where .= ' AND ' . $wpdb->posts . '.post_title LIKE ' . $search;
		}

		if ( $wp_query->get( 'updated_since' ) ) {
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.post_modified > %s", $wp_query->get( 'updated_since' ) );
		}

		if ( $wp_query->get( 'created_since' ) ) {
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.post_date > %s", $wp_query->get( 'created_since' ) );
		}

		if ( $wp_query->get( 'updated_before' ) ) {
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.post_modified < %s", $wp_query->get( 'updated_before' ) );
		}

		if ( $wp_query->get( 'created_before' ) ) {
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.post_date < %s", $wp_query->get( 'created_before' ) );
		}

		return $where;
	}
}
