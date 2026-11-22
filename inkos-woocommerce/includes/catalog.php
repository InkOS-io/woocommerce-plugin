<?php
namespace InkOS\Woo;

defined( 'ABSPATH' ) || exit;

final class Catalog {
	public static function hooks() {
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'product_prices' ), 10, 3 );
		add_filter( 'woocommerce_structured_data_product', static function ( $data, $product ) {
			if ( Cart::has_mapping( $product ) ) {
				// Search engines cannot express quantity-dependent, expiring print terms here.
				unset( $data['offers'] );
			}
			return $data;
		}, 20, 2 );
	}

	// The product Store API has mandatory numeric price fields independent of the HTML price label.
	// Give it an InkOS one-unit price. The shopper's selected quantity gets its own private quote.
	private static function unit_minor( $product ) {
		$config = $product->get_meta( '_inkos_configuration' );
		if ( ! $config ) {
			return (int) round( wc_get_price_to_display( $product ) * 100 );
		}
		$settings = Api::settings();
		if ( empty( $settings['storefront'] ) || $settings['storefront'] !== $product->get_meta( '_inkos_storefront' )
			|| empty( $settings['token'] ) || empty( $settings['url'] ) || ! hash_equals( $settings['binding'] ?? '', Api::binding( $settings['url'], $settings['token'] ) ) ) {
			throw new ApiError( 'mapping', 'Check the storefront connection and product mapping.', 409 );
		}
		$key = 'inkos_unit_' . md5( $settings['binding'] . '|' . $config . '|' . get_woocommerce_currency() );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		$q = Api::quote( $config, 1 );
		set_transient( $key, $q['totalMinor'], max( 1, min( 300, strtotime( $q['expiresAt'] ) - time() ) ) );
		return $q['totalMinor'];
	}

	public static function product_prices( $reply, $handler, $request ) {
		$route = $request->get_route();
		if ( 'GET' !== $request->get_method() || ! preg_match( '#^/wc/store/(?:v1/)?products(?:/[^/]+)?/?$#', $route )
			|| ! $reply instanceof \WP_REST_Response || $reply->get_status() !== 200 ) {
			return $reply;
		}
		$data = $reply->get_data();
		$single = isset( $data['id'] );
		$rows = $single ? array( $data ) : $data;
		if ( ! is_array( $rows ) ) {
			return $reply;
		}
		try {
			foreach ( $rows as $index => $row ) {
				if ( ! is_array( $row ) || empty( $row['id'] ) || ! isset( $row['prices'] ) ) {
					continue;
				}
				$product = wc_get_product( $row['id'] );
				if ( ! $product || ! Cart::has_mapping( $product ) ) {
					continue;
				}
				$amounts = array();
				if ( $product->is_type( 'variable' ) ) {
					foreach ( $product->get_visible_children() as $id ) {
						$variation = wc_get_product( $id );
						if ( $variation ) {
							$amounts[] = self::unit_minor( $variation );
						}
					}
				} else {
					$amounts[] = self::unit_minor( $product );
				}
				if ( ! $amounts ) {
					continue;
				}
				$prices = (array) $row['prices'];
				$min = (string) min( $amounts );
				$max = (string) max( $amounts );
				$prices['price'] = $min;
				$prices['regular_price'] = $min;
				$prices['sale_price'] = $min;
				$prices['price_range'] = $min === $max ? null : (object) array( 'min_amount' => $min, 'max_amount' => $max );
				$rows[ $index ]['prices'] = (object) $prices;
				$rows[ $index ]['on_sale'] = false;
			}
		} catch ( ApiError $error ) {
			return new \WP_Error( 'inkos_catalog_price', $error->getMessage(), array( 'status' => 503 ) );
		}
		$reply->set_data( $single ? $rows[0] : $rows );
		return $reply;
	}
}
