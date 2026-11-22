<?php
namespace InkOS\Woo;

defined( 'ABSPATH' ) || exit;

final class Cart {
	public static function hooks() {
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'product_widget' ) );
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 20, 2 );
		add_action( 'wc_ajax_inkos_price', array( __CLASS__, 'preview' ) );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_terms' ), 20, 4 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'prices' ), 50 );
		add_action( 'woocommerce_store_api_cart_errors', array( __CLASS__, 'cart_errors' ), 10, 2 );
		add_action( 'woocommerce_check_cart_items', static function () {
			if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
				return;
			}
			$errors = new \WP_Error();
			self::cart_errors( $errors, WC()->cart );
			foreach ( $errors->get_error_messages() as $message ) {
				if ( ! wc_has_notice( $message, 'error' ) ) {
					wc_add_notice( $message, 'error' );
				}
			}
		} );
		add_action( 'woocommerce_checkout_create_order_line_item', static function ( $item, $key, $row ) {
			if ( isset( $row['inkos_terms'] ) ) {
				$item->add_meta_data( '_inkos_terms', $row['inkos_terms'], true );
				$item->add_meta_data( '_inkos_storefront', $row['inkos_storefront'], true );
			}
		}, 10, 3 );
	}

	public static function has_mapping( $product ) {
		if ( $product->get_meta( '_inkos_configuration' ) ) {
			return true;
		}
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $id ) {
				if ( get_post_meta( $id, '_inkos_configuration', true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	public static function price_html( $html, $product ) {
		return ! is_admin() && self::has_mapping( $product ) ? 'Calculated for your selection' : $html;
	}

	public static function product_widget() {
		global $product;
		if ( ! $product || ! self::has_mapping( $product ) ) {
			return;
		}
		wp_enqueue_script( 'inkos-woo-price', plugins_url( '../assets/price.js', __FILE__ ), array(), '1.0.0', true );
		printf( '<p class="inkos-price" aria-live="polite" data-product="%s" data-variable="%d" data-url="%s">Choose a quantity to see your print price.</p>', esc_attr( $product->get_id() ), $product->is_type( 'variable' ), esc_url( \WC_AJAX::get_endpoint( 'inkos_price' ) ) );
		echo '<input type="hidden" name="inkos_quote_id" value="">';
	}

	public static function preview() {
		$product = wc_get_product( absint( $_POST['product'] ?? 0 ) );
		if ( ! $product || 'publish' !== $product->get_status() || post_password_required( $product->get_parent_id() ?: $product->get_id() ) || ! $product->is_purchasable()
			|| ( $product->get_parent_id() && 'publish' !== get_post_status( $product->get_parent_id() ) ) ) {
			wp_send_json_error( array( 'message' => 'This selection is unavailable.' ), 404 );
		}
		if ( ! $product->get_meta( '_inkos_configuration' ) ) {
			wp_send_json_success( array( 'mapped' => false ) );
		}
		try {
			$q = self::new_terms( $product, wc_stock_amount( wp_unslash( $_POST['quantity'] ?? 1 ) ) );
			wp_send_json_success( array(
				'mapped' => true, 'quoteId' => $q['quoteId'], 'expiresAt' => $q['expiresAt'],
				'formatted' => html_entity_decode( wp_strip_all_tags( wc_price( $q['totalMinor'] / 100, array( 'currency' => $q['currency'] ) ) ), ENT_QUOTES, 'UTF-8' ) . ' ' . $q['currency'],
				'quantity' => $q['quantity'], 'totalMinor' => $q['totalMinor'], 'currency' => $q['currency'],
			) );
		} catch ( ApiError $error ) {
			wp_send_json_error( array( 'message' => $error->getMessage() ), 422 );
		}
	}

	private static function new_terms( $product, $quantity, $id = '' ) {
		if ( ! Api::storefront() || Api::storefront() !== $product->get_meta( '_inkos_storefront' ) ) {
			throw new ApiError( 'mapping', 'This product needs a mapping to the connected InkOS storefront.', 409 );
		}
		return Api::quote( $product->get_meta( '_inkos_configuration' ), $quantity, $id );
	}

	public static function add_terms( $data, $product_id, $variation_id, $quantity ) {
		$product = wc_get_product( $variation_id ?: $product_id );
		if ( $product && $product->get_meta( '_inkos_configuration' ) ) {
			$id = sanitize_text_field( wp_unslash( $_POST['inkos_quote_id'] ?? '' ) );
			try {
				$data['inkos_terms'] = self::new_terms( $product, $quantity ?? 1, $id );
				$data['inkos_storefront'] = Api::storefront();
			} catch ( ApiError $error ) {
				if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
					throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'inkos_price', $error->getMessage(), 409 );
				}
				throw $error;
			}
		}
		return $data;
	}

	public static function prices( $cart ) {
		foreach ( $cart->get_cart() as $key => $row ) {
			$q = $row['inkos_terms'] ?? null;
			if ( ! $q ) {
				continue;
			}
			// A shopper's quantity edit needs new terms. An unchanged line retains its original quote.
			if ( (float) $q['quantity'] !== (float) $row['quantity'] ) {
				try {
					$q = self::new_terms( $row['data'], $row['quantity'] );
					$cart->cart_contents[ $key ]['inkos_terms'] = $q;
				} catch ( ApiError $error ) {
					$cart->cart_contents[ $key ]['inkos_error'] = $error->getMessage();
					continue;
				}
			}
			unset( $cart->cart_contents[ $key ]['inkos_error'] );
			// WooCommerce rounds after multiplying the unit price by quantity.
			$unit = sprintf( '%.14F', $q['totalMinor'] / ( 100 * $q['quantity'] ) );
			$row['data']->set_price( $unit );
			$row['data']->set_regular_price( $unit );
			$row['data']->set_sale_price( '' );
		}
	}

	public static function verify( $q, $product, $quantity, $storefront ) {
		if ( ! $q || ! $product || $storefront !== Api::storefront() || $q['configurationId'] !== $product->get_meta( '_inkos_configuration' ) ) {
			throw new ApiError( 'mapping', 'The print configuration changed. Remove the item and add it again to review the current terms.', 409 );
		}
		$verified = self::new_terms( $product, $quantity, $q['quoteId'] );
		foreach ( array( 'totalMinor', 'currency', 'quantity', 'options' ) as $key ) {
			if ( $verified[ $key ] !== $q[ $key ] ) {
				throw new ApiError( 'terms_mismatch', 'InkOS could not confirm the agreed print terms. Please review the item again.', 409 );
			}
		}
	}

	public static function cart_errors( $errors, $cart ) {
		$rows = $cart->get_cart();
		$mapped = array_filter( $rows, static function ( $row ) {
			return isset( $row['inkos_terms'] ) || $row['data']->get_meta( '_inkos_configuration' );
		} );
		if ( ! $mapped ) {
			return;
		}
		if ( count( $mapped ) !== count( $rows ) || count( $rows ) > 50 ) {
			$errors->add( 'inkos_cart', 'Check out up to 50 InkOS print lines together. Purchase other products separately.' );
			return;
		}
		foreach ( $rows as $row ) {
			try {
				self::verify( $row['inkos_terms'] ?? null, $row['data'], $row['quantity'], $row['inkos_storefront'] ?? '' );
			} catch ( ApiError $error ) {
				$errors->add( 'inkos_price', $error->getMessage() );
				return;
			}
		}
	}
}
