<?php
/**
 * REST API Customers controller
 *
 * Handles requests to the /customers endpoint.
 *
 * @package Automattic/WooCommerce/RestApi
 */

namespace Automattic\WooCommerce\RestApi\Controllers\Version4;

defined( 'ABSPATH' ) || exit;

use \WP_REST_Server;
use Automattic\WooCommerce\RestApi\Controllers\Version4\Requests\CustomerRequest;
use Automattic\WooCommerce\RestApi\Controllers\Version4\Responses\CustomerResponse;
use Automattic\WooCommerce\RestApi\Controllers\Version4\Utilities\Pagination;

/**
 * REST API Customers controller class.
 */
class Customers extends AbstractController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'customers';

	/**
	 * Permission to check.
	 *
	 * @var string
	 */
	protected $resource_type = 'customers';

	/**
	 * Register the routes for customers.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array_merge(
						$this->get_endpoint_args_for_item_schema( \WP_REST_Server::CREATABLE ),
						array(
							'email' => array(
								'required'    => true,
								'type'        => 'string',
								'description' => __( 'New user email address.', 'woocommerce-rest-api' ),
							),
							'username' => array(
								'required'    => 'no' === get_option( 'woocommerce_registration_generate_username', 'yes' ),
								'description' => __( 'New user username.', 'woocommerce-rest-api' ),
								'type'        => 'string',
							),
							'password' => array(
								'required'    => 'no' === get_option( 'woocommerce_registration_generate_password', 'no' ),
								'description' => __( 'New user password.', 'woocommerce-rest-api' ),
								'type'        => 'string',
							),
						)
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			),
			true
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'args' => array(
					'id' => array(
						'description' => __( 'Unique identifier for the resource.', 'woocommerce-rest-api' ),
						'type'        => 'integer',
					),
				),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'context' => $this->get_context_param( array( 'default' => 'view' ) ),
					),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( \WP_REST_Server::EDITABLE ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
					'args'                => array(
						'force' => array(
							'default'     => false,
							'type'        => 'boolean',
							'description' => __( 'Required to be true, as resource does not support trashing.', 'woocommerce-rest-api' ),
						),
						'reassign' => array(
							'default'     => 0,
							'type'        => 'integer',
							'description' => __( 'ID to reassign posts to.', 'woocommerce-rest-api' ),
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			),
			true
		);

		$this->register_batch_route();
	}

	/**
	 * Get all customers.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_items( $request ) {
		$prepared_args = array(
			'exclude' => $request['exclude'],
			'include' => $request['include'],
			'order'   => $request['order'],
			'number'  => $request['per_page'],
		);

		if ( ! empty( $request['offset'] ) ) {
			$prepared_args['offset'] = $request['offset'];
		} else {
			$prepared_args['offset'] = ( $request['page'] - 1 ) * $prepared_args['number'];
		}

		$orderby_possibles = array(
			'id'              => 'ID',
			'include'         => 'include',
			'name'            => 'display_name',
			'registered_date' => 'registered',
		);
		$prepared_args['orderby'] = $orderby_possibles[ $request['orderby'] ];
		$prepared_args['search']  = $request['search'];

		if ( '' !== $prepared_args['search'] ) {
			$prepared_args['search'] = '*' . $prepared_args['search'] . '*';
		}

		// Filter by email.
		if ( ! empty( $request['email'] ) ) {
			$prepared_args['search']         = $request['email'];
			$prepared_args['search_columns'] = array( 'user_email' );
		}

		$prepared_args['date_query'] = array();
		if ( isset( $request[ 'created_since' ] ) || isset( $request[ 'created_before' ] ) ) {
			$date = [
				'column' => 'user_registered',
			];

			if (isset( $request[ 'created_since' ] ) && !empty( $request[ 'created_since' ] )) {
				$date['after'] = $request[ 'created_since' ];
			}

			if (isset( $request[ 'created_before' ] ) && !empty( $request[ 'created_before' ] )) {
				$date['before'] = $request[ 'created_before' ];
			}
			$prepared_args['date_query'][] = $date;
		}

		$prepared_args['meta_query'] = array();
		if ( isset( $request[ 'updated_since' ] ) || isset( $request[ 'updated_before' ] ) ) {
			$date = [
				'key' => 'last_update',
				'type'  => 'NUMERIC',
			];

			if (isset( $request[ 'updated_since' ] ) && !empty( $request[ 'updated_since' ] )) {
				$updated_since = $date;
				$updated_since['value'] = strtotime( $request[ 'updated_since' ] );
				$updated_since['compare'] = '>';
				$prepared_args['meta_query'][] = $updated_since;
			}

			if (isset( $request[ 'updated_before' ] ) && !empty( $request[ 'updated_before' ] )) {
				$updated_before = $date;
				$updated_before['value'] = strtotime( $request[ 'updated_before' ] );
				$updated_before['compare'] = '<';
				$prepared_args['meta_query'][] = $updated_before;
			}
		}

		// Filter by role.
		if ( 'all' !== $request['role'] ) {
			$prepared_args['role'] = $request['role'];
		}

		/**
		 * Filter arguments, before passing to \ WP_User_Query, when querying users via the REST API.
		 *
		 * @see https://developer.wordpress.org/reference/classes/\ WP_User_Query/
		 *
		 * @param array           $prepared_args Array of arguments for \ WP_User_Query.
		 * @param \WP_REST_Request $request       The current request.
		 */
		$prepared_args = apply_filters( 'woocommerce_rest_customer_query', $prepared_args, $request );

		$query = new \WP_User_Query( $prepared_args );

		$users = array();
		foreach ( $query->results as $user ) {
			$customer = new \WC_Customer( $user->ID );
			$data     = $this->prepare_item_for_response( $customer, $request );
			$users[]  = $this->prepare_response_for_collection( $data );
		}

		// Store pagination values for headers then unset for count query.
		$per_page = (int) $prepared_args['number'];
		$page     = ceil( ( ( (int) $prepared_args['offset'] ) / $per_page ) + 1 );

		$prepared_args['fields'] = 'ID';

		$total_users = $query->get_total();

		if ( $total_users < 1 ) {
			// Out-of-bounds, run the query again without LIMIT for total count.
			unset( $prepared_args['number'] );
			unset( $prepared_args['offset'] );
			$count_query = new \ WP_User_Query( $prepared_args );
			$total_users = $count_query->get_total();
		}

		$response = rest_ensure_response( $users );
		$response = Pagination::add_pagination_headers( $response, $request, $total_users, ceil( $total_users / $per_page ) );

		return $response;
	}

	/**
	 * Create a single customer.
	 *
	 * @throws \WC_REST_Exception On invalid params.
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function create_item( $request ) {
		try {
			if ( ! empty( $request['id'] ) ) {
				throw new \WC_REST_Exception( 'woocommerce_rest_customer_exists', __( 'Cannot create existing resource.', 'woocommerce-rest-api' ), 400 );
			}

			$customer_request = new CustomerRequest( $request );
			$customer         = $customer_request->prepare_object();
			$customer->save();

			if ( ! $customer->get_id() ) {
				throw new \WC_REST_Exception( 'woocommerce_rest_cannot_create', __( 'This resource cannot be created.', 'woocommerce-rest-api' ), 400 );
			}

			$this->update_additional_fields_for_object( $customer, $request );

			/**
			 * Fires after a customer is created or updated via the REST API.
			 *
			 * @param \WC_Customer     $customer Customer object.
			 * @param \WP_REST_Request $request   Request object.
			 * @param boolean          $creating  True when creating customer, false when updating customer.
			 */
			do_action( 'woocommerce_rest_insert_customer_object', $customer, $request, true );

			$request->set_param( 'context', 'edit' );
			$response = $this->prepare_item_for_response( $customer, $request );
			$response = rest_ensure_response( $response );
			$response->set_status( 201 );
			$response->header( 'Location', rest_url( sprintf( '/%s/%s/%d', $this->namespace, $this->rest_base, $customer->get_id() ) ) );

			return $response;
		} catch ( \Exception $e ) {
			return new \WP_Error( $e->getErrorCode(), $e->getMessage(), array( 'status' => $e->getCode() ) );
		}
	}

	/**
	 * Get a single customer.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_item( $request ) {
		$id       = (int) $request['id'];
		$customer = new \WC_Customer( $id );

		if ( empty( $id ) || ! $customer->get_id() ) {
			return new \WP_Error( 'woocommerce_rest_invalid_id', __( 'Invalid resource ID.', 'woocommerce-rest-api' ), array( 'status' => 404 ) );
		}

		$response = $this->prepare_item_for_response( $customer, $request );
		$response = rest_ensure_response( $response );

		return $response;
	}

	/**
	 * Update a single user.
	 *
	 * @throws \WC_REST_Exception On invalid params.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function update_item( $request ) {
		try {
			$customer_request = new CustomerRequest( $request );
			$customer         = $customer_request->prepare_object();
			$customer->save();

			$this->update_additional_fields_for_object( $customer, $request );

			if ( is_multisite() && ! is_user_member_of_blog( $customer->get_id() ) ) {
				add_user_to_blog( get_current_blog_id(), $customer->get_id(), 'customer' );
			}

			/**
			 * Fires after a customer is created or updated via the REST API.
			 *
			 * @param \WC_Customer     $customer  Data used to create the customer.
			 * @param \WP_REST_Request $request   Request object.
			 * @param boolean         $creating  True when creating customer, false when updating customer.
			 */
			do_action( 'woocommerce_rest_insert_customer_object', $customer, $request, false );

			$request->set_param( 'context', 'edit' );
			$response = $this->prepare_item_for_response( $customer, $request );
			$response = rest_ensure_response( $response );
			return $response;
		} catch ( Exception $e ) {
			return new \WP_Error( $e->getErrorCode(), $e->getMessage(), array( 'status' => $e->getCode() ) );
		}
	}

	/**
	 * Delete a single customer.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function delete_item( $request ) {
		$id       = (int) $request['id'];
		$reassign = isset( $request['reassign'] ) ? absint( $request['reassign'] ) : null;
		$force    = isset( $request['force'] ) ? (bool) $request['force'] : false;

		// We don't support trashing for this type, error out.
		if ( ! $force ) {
			return new \WP_Error( 'woocommerce_rest_trash_not_supported', __( 'Customers do not support trashing.', 'woocommerce-rest-api' ), array( 'status' => 501 ) );
		}

		if ( ! get_userdata( $id ) ) {
			return new \WP_Error( 'woocommerce_rest_invalid_id', __( 'Invalid resource id.', 'woocommerce-rest-api' ), array( 'status' => 400 ) );
		}

		if ( ! empty( $reassign ) ) {
			if ( $reassign === $id || ! get_userdata( $reassign ) ) {
				return new \WP_Error( 'woocommerce_rest_customer_invalid_reassign', __( 'Invalid resource id for reassignment.', 'woocommerce-rest-api' ), array( 'status' => 400 ) );
			}
		}

		/** Include admin customer functions to get access to wp_delete_user() */
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$customer = new \WC_Customer( $id );

		$request->set_param( 'context', 'edit' );
		$response = $this->prepare_item_for_response( $customer, $request );

		if ( ! is_null( $reassign ) ) {
			$result = $customer->delete_and_reassign( $reassign );
		} else {
			$result = $customer->delete();
		}

		if ( ! $result ) {
			return new \WP_Error( 'woocommerce_rest_cannot_delete', __( 'The resource cannot be deleted.', 'woocommerce-rest-api' ), array( 'status' => 500 ) );
		}

		/**
		 * Fires after a customer is deleted via the REST API.
		 *
		 * @param \WC_Customer      $customer User data.
		 * @param \WP_REST_Response $response  The response returned from the API.
		 * @param \WP_REST_Request  $request   The request sent to the API.
		 */
		do_action( 'woocommerce_rest_delete_customer_object', $customer, $response, $request );

		return $response;
	}

	/**
	 * Get data for this object in the format of this endpoint's schema.
	 *
	 * @param \WC_Customer     $object Object to prepare.
	 * @param \WP_REST_Request $request Request object.
	 * @return array Array of data in the correct format.
	 */
	protected function get_data_for_response( $object, $request ) {
		$formatter = new CustomerResponse();

		return $formatter->prepare_response( $object, $this->get_request_context( $request ) );
	}

	/**
	 * Prepare links for the request.
	 *
	 * @param mixed            $item Object to prepare.
	 * @param \WP_REST_Request $request Request object.
	 * @return array
	 */
	protected function prepare_links( $item, $request ) {
		$links = array(
			'self' => array(
				'href' => rest_url( sprintf( '/%s/%s/%d', $this->namespace, $this->rest_base, $item->get_id() ) ),
			),
			'collection' => array(
				'href' => rest_url( sprintf( '/%s/%s', $this->namespace, $this->rest_base ) ),
			),
		);
		return $links;
	}

	/**
	 * Get the Customer's schema, conforming to JSON Schema.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'customer',
			'type'       => 'object',
			'properties' => array(
				'id'                 => array(
					'description' => __( 'Unique identifier for the resource.', 'woocommerce-rest-api' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_created'       => array(
					'description' => __( "The date the customer was created, in the site's timezone.", 'woocommerce-rest-api' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_created_gmt'   => array(
					'description' => __( 'The date the customer was created, as GMT.', 'woocommerce-rest-api' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_modified'      => array(
					'description' => __( "The date the customer was last modified, in the site's timezone.", 'woocommerce-rest-api' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'date_modified_gmt'  => array(
					'description' => __( 'The date the customer was last modified, as GMT.', 'woocommerce-rest-api' ),
					'type'        => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'email'              => array(
					'description' => __( 'The email address for the customer.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'format'      => 'email',
					'context'     => array( 'view', 'edit' ),
				),
				'first_name'         => array(
					'description' => __( 'Customer first name.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'last_name'          => array(
					'description' => __( 'Customer last name.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'role'               => array(
					'description' => __( 'Customer role.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'username'           => array(
					'description' => __( 'Customer login name.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_user',
					),
				),
				'password'           => array(
					'description' => __( 'Customer password.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'edit' ),
				),
				'billing'            => array(
					'description' => __( 'List of billing address data.', 'woocommerce-rest-api' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
					'properties'  => array(
						'first_name' => array(
							'description' => __( 'First name.', 'woocommerce-rest-api' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
						'last_name'  => array(
							'description' => __( 'Last name.', 'woocommerce-rest-api' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
						'company'    => array(
							'description' => __( 'Company name.', 'woocommerce-rest-api' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
						'address_1'  => array(
							'description' => __( 'Address line 1', 'woocommerce-rest-api' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
						'address_2'  => array(
							'description' => __( 'Address line 2', 'woocommerce-rest-api' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
						'city'       => array(
							'description' => __( 'City name.', 'woocommerce-rest-api' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
						'state'      => array(
							'description' => __( 'ISO code or name of the state, province or district.', 'woocommerce-rest-api' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
						'postcode'   => array(
							'description' => __( 'Postal code.', 'woocommerce-rest-api' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
						'country'    => array(
							'description' => __( 'ISO code of the country.', 'woocommerce-rest-api' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
						'email'      => array(
							'description' => __( 'Email address.', 'woocommerce-rest-api' ),
							'type'        => 'string',
							'format'      => 'email',
							'context'     => array( 'view', 'edit' ),
						),
						'phone'      => array(
							'description' => __( 'Phone number.', 'woocommerce-rest-api' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
					),
				),
				'shipping'           => array(
					'description' => __( 'List of shipping address data.', 'woocommerce-rest-api' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
					'properties'  => array(
						'first_name' => array(
							'description' => __( 'First name.', 'woocommerce-rest-api' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
						'last_name'  => array(
							'description' => __( 'Last name.', 'woocommerce-rest-api' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
						'company'    => array(
							'description' => __( 'Company name.', 'woocommerce-rest-api' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
						'address_1'  => array(
							'description' => __( 'Address line 1', 'woocommerce-rest-api' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
						'address_2'  => array(
							'description' => __( 'Address line 2', 'woocommerce-rest-api' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
						'city'       => array(
							'description' => __( 'City name.', 'woocommerce-rest-api' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
						'state'      => array(
							'description' => __( 'ISO code or name of the state, province or district.', 'woocommerce-rest-api' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
						'postcode'   => array(
							'description' => __( 'Postal code.', 'woocommerce-rest-api' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
						'country'    => array(
							'description' => __( 'ISO code of the country.', 'woocommerce-rest-api' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
						),
					),
				),
				'is_paying_customer' => array(
					'description' => __( 'Is the customer a paying customer?', 'woocommerce-rest-api' ),
					'type'        => 'bool',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'avatar_url'         => array(
					'description' => __( 'Avatar URL.', 'woocommerce-rest-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'meta_data'          => array(
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
	 * Get role names.
	 *
	 * @return array
	 */
	protected function get_role_names() {
		global $wp_roles;

		return array_keys( $wp_roles->role_names );
	}

	/**
	 * Get the query params for collections.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();

		$params['context']['default'] = 'view';

		$params['exclude'] = array(
			'description'       => __( 'Ensure result set excludes specific IDs.', 'woocommerce-rest-api' ),
			'type'              => 'array',
			'items'             => array(
				'type'          => 'integer',
			),
			'default'           => array(),
			'sanitize_callback' => 'wp_parse_id_list',
		);
		$params['include'] = array(
			'description'       => __( 'Limit result set to specific IDs.', 'woocommerce-rest-api' ),
			'type'              => 'array',
			'items'             => array(
				'type'          => 'integer',
			),
			'default'           => array(),
			'sanitize_callback' => 'wp_parse_id_list',
		);
		$params['offset'] = array(
			'description'        => __( 'Offset the result set by a specific number of items.', 'woocommerce-rest-api' ),
			'type'               => 'integer',
			'sanitize_callback'  => 'absint',
			'validate_callback'  => 'rest_validate_request_arg',
		);
		$params['order'] = array(
			'default'            => 'asc',
			'description'        => __( 'Order sort attribute ascending or descending.', 'woocommerce-rest-api' ),
			'enum'               => array( 'asc', 'desc' ),
			'sanitize_callback'  => 'sanitize_key',
			'type'               => 'string',
			'validate_callback'  => 'rest_validate_request_arg',
		);
		$params['orderby'] = array(
			'default'            => 'name',
			'description'        => __( 'Sort collection by object attribute.', 'woocommerce-rest-api' ),
			'enum'               => array(
				'id',
				'include',
				'name',
				'registered_date',
			),
			'sanitize_callback'  => 'sanitize_key',
			'type'               => 'string',
			'validate_callback'  => 'rest_validate_request_arg',
		);
		$params['email'] = array(
			'description'        => __( 'Limit result set to resources with a specific email.', 'woocommerce-rest-api' ),
			'type'               => 'string',
			'format'             => 'email',
			'validate_callback'  => 'rest_validate_request_arg',
		);
		$params['role'] = array(
			'description'        => __( 'Limit result set to resources with a specific role.', 'woocommerce-rest-api' ),
			'type'               => 'string',
			'default'            => 'customer',
			'enum'               => array_merge( array( 'all' ), $this->get_role_names() ),
			'validate_callback'  => 'rest_validate_request_arg',
		);

		$params['updated_since'] = array(
			'description'       => __( 'Filter customers by since last updated date.', 'woocommerce-rest-api' ),
			'type'              => 'string',
			'format'            => 'date-time',
			'validate_callback' => 'rest_validate_request_arg',
		);
		$params['created_since'] = array(
			'description'       => __( 'Filter customers by since created date.', 'woocommerce-rest-api' ),
			'type'              => 'string',
			'format'            => 'date-time',
			'validate_callback' => 'rest_validate_request_arg',
		);
		$params['updated_before'] = array(
			'description'       => __( 'Filter customers by before last updated date.', 'woocommerce-rest-api' ),
			'type'              => 'string',
			'format'            => 'date-time',
			'validate_callback' => 'rest_validate_request_arg',
		);
		$params['created_before'] = array(
			'description'       => __( 'Filter customers by before created date.', 'woocommerce-rest-api' ),
			'type'              => 'string',
			'format'            => 'date-time',
			'validate_callback' => 'rest_validate_request_arg',
		);

		return $params;
	}

	/**
	 * Check if a given ID is valid.
	 *
	 * @param  \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|boolean
	 */
	protected function check_valid_customer_id( $request ) {
		$id   = $request->get_param( 'id' );
		$user = get_userdata( $id );

		if ( false === $user ) {
			return new \WP_Error( 'woocommerce_rest_customer_invalid_id', __( 'Invalid ID.', 'woocommerce-rest-api' ), array( 'status' => 404 ) );
		}
		return true;
	}

	/**
	 * Check if a given request has access to read a webhook.
	 *
	 * @param  \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|boolean
	 */
	public function get_item_permissions_check( $request ) {
		$check_valid = $this->check_valid_customer_id( $request );

		if ( is_wp_error( $check_valid ) ) {
			return $check_valid;
		}

		return parent::get_item_permissions_check( $request );
	}

	/**
	 * Check if a given request has access to delete an item.
	 *
	 * @param  \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|boolean
	 */
	public function delete_item_permissions_check( $request ) {
		$check_valid = $this->check_valid_customer_id( $request );

		if ( is_wp_error( $check_valid ) ) {
			return $check_valid;
		}

		return parent::delete_item_permissions_check( $request );
	}

	/**
	 * Check if a given request has access to update an item.
	 *
	 * @param  \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|boolean
	 */
	public function update_item_permissions_check( $request ) {
		$check_valid = $this->check_valid_customer_id( $request );

		if ( is_wp_error( $check_valid ) ) {
			return $check_valid;
		}

		return parent::update_item_permissions_check( $request );
	}
}
