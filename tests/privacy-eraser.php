<?php
// Run with wp eval-file in a local WooCommerce test site with the plugin active.
if ( 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Use a local WooCommerce test site.' );
}

foreach ( array( false, true ) as $accepted ) {
	$order = wc_create_order();
	$id = $order->get_id();
	$outbox = 'inkos_woo_submission_' . $id;
	$customer = array( 'name' => 'Privacy Test', 'email' => 'privacy@example.test' );
	$draft = array( 'body' => array( 'externalOrderId' => 'wc-privacy-test', 'customer' => $customer ) );
	$receipt = array( 'externalOrderId' => 'wc-privacy-test', 'items' => array() );
	try {
		$order->set_billing_email( $customer['email'] );
		$order->update_meta_data( '_inkos_draft', $draft );
		$order->update_meta_data( '_inkos_state', $accepted ? 'Synchronized' : 'Retry scheduled' );
		if ( $accepted ) {
			$order->update_meta_data( '_inkos_receipt', $receipt );
		}
		$order->save();
		add_option( $outbox, wp_json_encode( $draft ), '', false );
		WC_Privacy_Erasers::remove_order_personal_data( $order );
		$order = wc_get_order( $id );
		if ( $order->get_meta( '_inkos_draft' ) || false !== strpos( (string) get_option( $outbox ), $customer['email'] ) ) {
			throw new RuntimeException( 'The WooCommerce eraser retained the InkOS submission contact.' );
		}
		if ( $accepted && $receipt !== $order->get_meta( '_inkos_receipt' ) ) {
			throw new RuntimeException( 'Anonymization lost the accepted work order references.' );
		}
		// A stale callback attempting to create its immutable submission must lose to erasure.
		global $wpdb;
		$stored = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no')", $outbox, wp_json_encode( $draft ) ) );
		if ( 0 !== $stored ) {
			throw new RuntimeException( 'An in-flight submission could restore erased contact data.' );
		}
	} finally {
		$order->delete( true );
		delete_option( $outbox );
	}
}
echo "Erased pending and accepted checkout data, retained receipts and blocked stale writes.\n";
