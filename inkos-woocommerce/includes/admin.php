<?php
namespace InkOS\Woo;

defined( 'ABSPATH' ) || exit;

final class Admin {
	public static function hooks() {
		add_action( 'admin_menu', static function () {
			add_submenu_page( 'woocommerce', 'InkOS storefront', 'InkOS', 'manage_woocommerce', 'inkos-storefront', array( __CLASS__, 'page' ) );
		} );
		add_action( 'admin_post_inkos_connect', array( __CLASS__, 'save_connection' ) );
		add_action( 'admin_post_inkos_map', array( __CLASS__, 'save_mapping' ) );
	}

	public static function connect( $url, $token ) {
		$settings = array( 'url' => untrailingslashit( $url ), 'token' => $token );
		$result = Api::request( 'GET', '/connection', null, '', $settings );
		if ( empty( $result['storefrontId'] ) || empty( $result['currency'] ) ) {
			throw new ApiError( 'invalid_connection', 'The server did not identify the storefront.', 502 );
		}
		$settings['binding'] = Api::binding( $settings['url'], $token );
		$settings['storefront'] = (string) $result['storefrontId'];
		$settings['name'] = (string) $result['name'];
		$settings['currency'] = (string) $result['currency'];
		if ( defined( 'INKOS_ECOMMERCE_TOKEN' ) ) {
			unset( $settings['token'] );
		}
		update_option( 'inkos_woo_connection', $settings, false );
		return $result;
	}

	private static function check_admin( $action, $capability ) {
		if ( ! current_user_can( $capability ) ) {
			wp_die( 'Insufficient permission.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	private static function complete( $message ) {
		wp_safe_redirect( add_query_arg( array( 'page' => 'inkos-storefront', 'inkos_notice' => $message ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function save_connection() {
		self::check_admin( 'inkos_connect', 'manage_options' );
		$old = Api::settings();
		$url = defined( 'INKOS_ECOMMERCE_URL' ) ? INKOS_ECOMMERCE_URL : untrailingslashit( esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) ) );
		$token = defined( 'INKOS_ECOMMERCE_TOKEN' ) ? INKOS_ECOMMERCE_TOKEN : trim( wp_unslash( $_POST['token'] ?? '' ) );
		if ( '' === $token && ( $old['url'] ?? '' ) === $url ) {
			$token = $old['token'] ?? '';
		}
		if ( defined( 'INKOS_ECOMMERCE_TOKEN' ) && ! defined( 'INKOS_ECOMMERCE_URL' ) ) {
			self::complete( 'Set both InkOS constants in wp-config.php to bind the credential to its server.' );
		}
		try {
			self::connect( $url, $token );
			self::complete( 'Connection verified.' );
		} catch ( ApiError $error ) {
			self::complete( $error->getMessage() );
		}
	}

	public static function save_mapping() {
		self::check_admin( 'inkos_map', 'manage_woocommerce' );
		$id = absint( $_POST['product'] ?? 0 );
		$product = wc_get_product( $id );
		if ( ! $product || ! $product->is_type( array( 'simple', 'variation' ) ) || ! current_user_can( 'edit_post', $id ) ) {
			self::complete( 'Choose an editable simple product or a specific variation ID.' );
		}
		$config = sanitize_text_field( wp_unslash( $_POST['configuration'] ?? '' ) );
		if ( '' !== $config ) {
			try {
				$catalog = Api::request( 'GET', '/configurations' );
				if ( ! in_array( $config, array_column( $catalog['configurations'], 'configurationId' ), true ) ) {
					self::complete( 'This configuration is unavailable on the connected storefront.' );
				}
			} catch ( ApiError $error ) {
				self::complete( $error->getMessage() );
			}
		}
		$product->update_meta_data( '_inkos_configuration', $config );
		$product->update_meta_data( '_inkos_storefront', '' === $config ? '' : Api::storefront() );
		$product->save();
		self::complete( 'Saved mapping for product #' . $id . '.' );
	}

	public static function page() {
		$settings = Api::settings();
		echo '<div class="wrap"><h1>InkOS storefront</h1>';
		if ( isset( $_GET['inkos_notice'] ) ) {
			echo '<p role="status">' . esc_html( sanitize_text_field( wp_unslash( $_GET['inkos_notice'] ) ) ) . '</p>';
		}
		if ( current_user_can( 'manage_options' ) ) {
			?>
			<h2>Connection</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="inkos_connect">
				<?php wp_nonce_field( 'inkos_connect' ); ?>
				<p><label>InkOS origin <input required class="regular-text" type="url" name="url" placeholder="https://print.example.com" value="<?php echo esc_attr( $settings['url'] ?? '' ); ?>" <?php disabled( defined( 'INKOS_ECOMMERCE_URL' ) ); ?>></label></p>
				<p><label>Storefront credential <input type="password" name="token" autocomplete="new-password" <?php disabled( defined( 'INKOS_ECOMMERCE_TOKEN' ) ); ?>></label> Leave blank to test the existing connection.</p>
				<?php submit_button( 'Test and save connection' ); ?>
			</form>
			<?php
		}
		if ( Api::storefront() ) {
			echo '<h2>Product mapping</h2><p>Connected to ' . esc_html( $settings['name'] . ' (' . $settings['currency'] . ')' ) . '.</p>';
			try {
				$catalog = Api::request( 'GET', '/configurations' );
				?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="inkos_map">
					<?php wp_nonce_field( 'inkos_map' ); ?>
					<p><label>Simple product or variation ID <input type="number" min="1" required name="product"></label></p>
					<p><label>InkOS configuration <select name="configuration"><option value="">Remove mapping</option>
					<?php foreach ( $catalog['configurations'] as $entry ) : ?>
						<option value="<?php echo esc_attr( $entry['configurationId'] ); ?>"><?php echo esc_html( $entry['name'] . ' ' . wp_json_encode( $entry['options'], JSON_UNESCAPED_UNICODE ) . ' · ' . $entry['configurationId'] ); ?></option>
					<?php endforeach; ?>
					</select></label></p>
					<?php submit_button( 'Save mapping' ); ?>
				</form>
				<?php
			} catch ( ApiError $error ) {
				echo '<p>' . esc_html( $error->getMessage() ) . '</p>';
			}
		}
		echo '</div>';
	}
}
