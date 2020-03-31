<?php
/**
 * Convert an order object to the order schema format.
 *
 * @package Automattic/WooCommerce/RestApi
 */

namespace Automattic\WooCommerce\RestApi\Controllers\Version4\Responses;

defined( 'ABSPATH' ) || exit;

/**
 * OrderResponse class.
 */
class OrderResponse extends AbstractObjectResponse {

	/**
	 * Decimal places to round to.
	 *
	 * @var int
	 */
	protected $dp;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->dp = wc_get_price_decimals();
	}

	/**
	 * Set decimal places.
	 *
	 * @param int $dp Decimals.
	 */
	public function set_dp( $dp ) {
		$this->dp = (int) $dp;
	}

	/**
	 * Get refund items for object.
	 *
	 * @param \WC_Order $order   Order object.
	 * @param string    $context Access context. Could be `view` or `edit`.
	 *
	 * @return array
	 */
	public function get_refunds( $order, $context ) {
		// Refunds.
		$refunds = array();
		foreach ( $order->get_refunds() as $refund ) {
			$refunds[] = array(
				'id'     => $refund->get_id(),
				'reason' => $refund->get_reason() ? $refund->get_reason() : '',
				'total'  => '-' . wc_format_decimal( $refund->get_amount(), $this->dp ),
			);
		}
		return $refunds;
	}

	/**
	 * Get line items for object.
	 *
	 * @param \WC_Order $order   Order object.
	 * @param string    $context Access context. Could be `view` or `edit`.
	 *
	 * @return array
	 */
	public function get_line_items( $order, $context ) {
		return $order->get_items( 'line_item' );
	}

	/**
	 * Get tax line items.
	 *
	 * @param \WC_Order $order   Order object.
	 * @param string    $context Access context. Could be `view` or `edit`.
	 *
	 * @return array
	 */
	public function get_tax_lines( $order, $context ) {
		return $order->get_items( 'tax' );
	}

	/**
	 * Get shipping line items.
	 *
	 * @param \WC_Order $order   Order object.
	 * @param string    $context Access context. Could be `view` or `edit`.
	 *
	 * @return array
	 */
	public function get_shipping_lines( $order, $context ) {
		return $order->get_items( 'shipping' );
	}

	/**
	 * Get fee lines.
	 * @param \WC_Order $order   Order object.
	 * @param string    $context Access context. Could be `view` or `edit`.
	 *
	 * @return array
	 */
	public function get_fee_lines( $order, $context ) {
		return $order->get_items( 'fee' );
	}

	/**
	 * Get coupon line items.
	 *
	 * @param \WC_Order $order   Order object.
	 * @param string    $context Access context. Could be `view` or `edit`.
	 *
	 * @return array
	 */
	public function get_coupon_lines( $order, $context ) {
		return $order->get_items( 'coupon' );
	}

	/**
	 * Get meta data.
	 *
	 * @param \WC_Order $order   Order object.
	 * @param string    $context Access context. Could be `view` or `edit`.
	 *
	 * @return array
	 */
	public function get_meta_data( $order, $context ) {
		return $order->get_meta_data();
	}

	/**
	 * Convert object to match data in the schema.
	 *
	 * @param \WC_Order $order  Order data.
	 * @param string    $context Request context. Options: 'view' and 'edit'.
	 * @param array     $fields  List of fields to return.
	 * @return array
	 */
	public function prepare_response( $order, $context, $fields = array() ) {
		if ( method_exists( $order, 'get_base_data' ) ) {
			$data = array_merge(
				$order->get_base_data(),
				$this->fetch_fields_using_getters( $order, $context, $fields )
			);
		} else {
			$data = $order->get_data();
			// Fields not returned from `get_data`.
			$additional_fields = array( 'refunds' );
			$data = array_merge(
				$data,
				$this->fetch_fields_using_getters(
					$order,
					$context,
					array_intersect(
						$additional_fields,
						$fields
					)
				)
			);
		}

		$format_decimal    = array( 'discount_total', 'discount_tax', 'shipping_total', 'shipping_tax', 'shipping_total', 'shipping_tax', 'cart_tax', 'total', 'total_tax' );
		$format_date       = array( 'date_created', 'date_modified', 'date_completed', 'date_paid' );
		$format_line_items = array( 'line_items', 'tax_lines', 'shipping_lines', 'fee_lines', 'coupon_lines' );

		// Format decimal values.
		foreach ( $format_decimal as $key ) {
			if ( ! in_array( $key, $fields ) ) {
				continue;
			}
			$data[ $key ] = wc_format_decimal( $data[ $key ], $this->dp );
		}

		// Format date values.
		foreach ( $format_date as $key ) {
			if ( ! in_array( $key, $fields ) ) {
				continue;
			}
			$datetime              = $data[ $key ];
			$data[ $key ]          = wc_rest_prepare_date_response( $datetime, false );
			$data[ $key . '_gmt' ] = wc_rest_prepare_date_response( $datetime );
		}

		// Format the order status.
		$data['status'] = 'wc-' === substr( $data['status'], 0, 3 ) ? substr( $data['status'], 3 ) : $data['status'];

		// Format line items.
		foreach ( $format_line_items as $key ) {
			if ( ! in_array( $key, $fields ) ) {
				continue;
			}
			$data[ $key ] = array_values( array_map( array( $this, 'prepare_order_item_data' ), $data[ $key ] ) );
		}

		// Currency symbols.
		$currency_symbol         = get_woocommerce_currency_symbol( $data['currency'] );
		$data['currency_symbol'] = html_entity_decode( $currency_symbol );
		return $data;
	}

	/**
	 * Expands an order item to get its data.
	 *
	 * @param \WC_Order_item $item Order item data.
	 * @return array
	 */
	protected function prepare_order_item_data( $item ) {
		$data           = $item->get_data();
		$format_decimal = array( 'subtotal', 'subtotal_tax', 'total', 'total_tax', 'tax_total', 'shipping_tax_total' );

		// Format decimal values.
		foreach ( $format_decimal as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$data[ $key ] = wc_format_decimal( $data[ $key ], $this->dp );
			}
		}

		// Add SKU and PRICE to products.
		if ( is_callable( array( $item, 'get_product' ) ) ) {
			$data['sku']   = $item->get_product() ? $item->get_product()->get_sku() : null;
			$data['price'] = $item->get_quantity() ? $item->get_total() / $item->get_quantity() : 0;
		}

		// Format taxes.
		if ( ! empty( $data['taxes']['total'] ) ) {
			$taxes = array();

			foreach ( $data['taxes']['total'] as $tax_rate_id => $tax ) {
				$taxes[] = array(
					'id'       => $tax_rate_id,
					'total'    => $tax,
					'subtotal' => isset( $data['taxes']['subtotal'][ $tax_rate_id ] ) ? $data['taxes']['subtotal'][ $tax_rate_id ] : '',
				);
			}
			$data['taxes'] = $taxes;
		} elseif ( isset( $data['taxes'] ) ) {
			$data['taxes'] = array();
		}

		// Remove names for coupons, taxes and shipping.
		if ( isset( $data['code'] ) || isset( $data['rate_code'] ) || isset( $data['method_title'] ) ) {
			unset( $data['name'] );
		}

		// Remove props we don't want to expose.
		unset( $data['order_id'] );
		unset( $data['type'] );

		return $data;
	}
}
