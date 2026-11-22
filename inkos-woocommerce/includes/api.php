<?php
namespace InkOS\Woo;

defined( 'ABSPATH' ) || exit;

final class ApiError extends \RuntimeException {
	public $reason;
	public $retry_at;

	public function __construct( $reason, $message, $status = 0, $retry_at = 0 ) {
		parent::__construct( $message, $status );
		$this->reason = $reason;
		$this->retry_at = $retry_at;
	}

	public function retryable() {
		$status = $this->getCode();
		return 0 === $status || 408 === $status || 429 === $status || $status >= 500;
	}
}

final class Api {
	public static function settings() {
		$config = get_option( 'inkos_woo_connection', array() );
		if ( defined( 'INKOS_ECOMMERCE_URL' ) ) {
			$config['url'] = INKOS_ECOMMERCE_URL;
		}
		if ( defined( 'INKOS_ECOMMERCE_TOKEN' ) ) {
			$config['token'] = INKOS_ECOMMERCE_TOKEN;
		}
		return $config;
	}

	public static function request( $method, $path, $body = null, $key = '', $settings = null ) {
		$config = $settings ?? self::settings();
		$url = untrailingslashit( $config['url'] ?? '' );
		$parts = wp_parse_url( $url );
		$local = 'local' === wp_get_environment_type() && in_array( $parts['host'] ?? '', array( '127.0.0.1', '[::1]' ), true );
		if ( empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] )
			|| ! in_array( $parts['path'] ?? '', array( '', '/' ), true )
			|| ( 'https' !== ( $parts['scheme'] ?? '' ) && ! ( $local && 'http' === $parts['scheme'] ) )
			|| empty( $config['token'] ) || preg_match( '/[\x00-\x20\x7f]/', $config['token'] ) ) {
			throw new ApiError( 'configuration', 'Check the InkOS connection URL and storefront credential.', 400 );
		}
		if ( null === $settings && ! hash_equals( $config['binding'] ?? '', self::binding( $url, $config['token'] ) ) ) {
			throw new ApiError( 'connection_changed', 'Test and save the InkOS connection after changing its credentials.', 409 );
		}
		$headers = array( 'Authorization' => 'Bearer ' . $config['token'], 'Accept' => 'application/json' );
		if ( '' !== $key ) {
			$headers['Idempotency-Key'] = $key;
		}
		$args = array( 'method' => $method, 'timeout' => 10, 'redirection' => 0, 'headers' => $headers, 'reject_unsafe_urls' => ! $local );
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( $url . '/api/ecommerce/v1' . $path, $args );
		if ( is_wp_error( $response ) ) {
			throw new ApiError( 'connection_failed', 'InkOS is currently unreachable. Try again shortly.' );
		}
		$status = wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 ) {
			$reason = sanitize_key( $json['code'] ?? 'request_failed' );
			$message = in_array( $reason, array( 'quote_expired', 'quote_unavailable', 'not_found' ), true )
				? 'These print terms are no longer available. Remove the item and add it again to review a new price.'
				: 'InkOS could not verify this request (' . $reason . ').';
			$header = (string) wp_remote_retrieve_header( $response, 'retry-after' );
			$retry_at = ctype_digit( $header ) ? time() + (int) $header : (int) strtotime( $header );
			throw new ApiError( $reason, $message, $status, $retry_at );
		}
		if ( ! is_array( $json ) ) {
			throw new ApiError( 'invalid_response', 'InkOS returned an unreadable response.', 502 );
		}
		return $json;
	}

	public static function binding( $url, $token ) {
		return hash_hmac( 'sha256', untrailingslashit( $url ) . "\n" . $token, wp_salt( 'auth' ) );
	}

	public static function storefront() {
		return (string) ( self::settings()['storefront'] ?? '' );
	}

	public static function quote( $configuration, $quantity, $id = '' ) {
		if ( $quantity < 1 || $quantity > 1000000 || (float) (int) $quantity !== (float) $quantity ) {
			throw new ApiError( 'quantity', 'Choose a whole quantity between 1 and 1,000,000.', 400 );
		}
		$q = '' === $id
			? self::request( 'POST', '/quotes', array( 'configurationId' => $configuration, 'quantity' => (int) $quantity ) )
			: self::request( 'GET', '/quotes/' . rawurlencode( $id ) );
		if ( empty( $q['quoteId'] ) || ( '' !== $id && $id !== $q['quoteId'] ) || $configuration !== ( $q['configurationId'] ?? null )
			|| (int) $quantity !== ( $q['quantity'] ?? null ) || ! is_int( $q['totalMinor'] ?? null ) || $q['totalMinor'] < 0
			|| get_woocommerce_currency() !== ( $q['currency'] ?? null ) || 2 !== wc_get_price_decimals()
			|| empty( $q['expiresAt'] ) || strtotime( $q['expiresAt'] ) <= time() ) {
			throw new ApiError( 'terms_mismatch', 'The print terms could not be verified for this configuration, quantity and store currency.', 409 );
		}
		return $q;
	}
}
