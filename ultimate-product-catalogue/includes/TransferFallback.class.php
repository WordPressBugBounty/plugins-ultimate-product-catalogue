<?php
/** Shared denial adapter only; transfer algorithms and vendor code belong to Helper. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class ewdupcpTransferFallback {
	public function __construct() {
		add_action( 'admin_post_ewd_upcp_import', array( $this, 'unavailable' ), 0 );
		add_action( 'admin_init', array( $this, 'legacy_request' ), 0 );
	}

	public function legacy_request() {
		if ( isset( $_POST['ewdupcpImport'] ) || isset( $_POST['ewd_upcp_export'] ) ) {
			$this->unavailable();
		}
	}

	public function unavailable() {
		global $ewd_upcp_controller;
		if ( ! empty( $ewd_upcp_controller->premium_runtime_modules['transfers'] ) ) {
			return;
		}
		$message = current_user_can( 'edit_upcp_products' )
			? __( 'Product import/export is unavailable. Install and activate a compatible EWD Premium Helper version 0.3.0 or newer. If it is already active, resolve conflicting plugin class definitions. Saved products and settings have not been changed.', 'ultimate-product-catalogue' )
			: __( 'You are not allowed to import or export products.', 'ultimate-product-catalogue' );
		wp_die( esc_html( $message ), '', array( 'response' => 403 ) );
	}

	public function write_export( $path, $format ) {
		throw new RuntimeException( 'transfer_runtime_unavailable' );
	}
}
