<?php
/** The one-time UPCP Black Friday 2026 upgrade notice. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ewdupcpBlackFriday2026Notice {
	// Fixed EST (UTC-5), independent of the WordPress site's timezone.
	const START = 1795669260; // November 26, 2026 at 00:01:00 EST.
	const END   = 1796101200; // December 1, 2026 at 00:00:00 EST (exclusive).

	const HIDDEN  = 'ewd-upcp-bf2026-hidden';
	const SNOOZED = 'ewd-upcp-bf2026-snoozed';
	const ACTION  = 'ewd_upcp_bf2026_dismiss';
	const HANDLE  = 'ewd-upcp-bf2026-notice';

	public function __construct() {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'dismiss' ) );
	}

	protected function now() {
		return time();
	}

	private function in_window( $now ) {
		return $now >= self::START && $now < self::END;
	}

	private function should_show() {
		// Match admin_notices: every site admin screen, but not Network or User Admin.
		if ( ! $this->in_window( $this->now() ) || ! is_blog_admin() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( false !== get_transient( self::HIDDEN ) || false !== get_transient( self::SNOOZED ) ) {
			return false;
		}

		$entitlement = apply_filters( 'ewd_upcp_entitlement_contract', array() );
		$status      = is_array( $entitlement ) ? ( $entitlement['effective_status'] ?? '' ) : '';
		// Current, cached and transitional paid access suppress the offer. Trials remain eligible.
		return ! in_array( $status, array( 'valid', 'valid_cached', 'legacy_transition' ), true );
	}

	public function enqueue_assets() {
		if ( ! $this->should_show() ) {
			return;
		}
		wp_enqueue_style( self::HANDLE, EWD_UPCP_PLUGIN_URL . '/assets/css/black-friday-2026-notice.css', array(), EWD_UPCP_VERSION );
		wp_enqueue_script( self::HANDLE, EWD_UPCP_PLUGIN_URL . '/assets/js/black-friday-2026-notice.js', array( 'jquery' ), EWD_UPCP_VERSION, true );
		wp_localize_script(
			self::HANDLE,
			'ewd_upcp_bf2026_notice',
			array(
				'url'    => admin_url( 'admin-ajax.php' ),
				'nonce'  => wp_create_nonce( self::ACTION ),
				'action' => self::ACTION,
				'error'  => __( 'Could not hide this notice. Please try again.', 'ultimate-product-catalogue' ),
			)
		);
	}

	public function render() {
		if ( ! $this->should_show() ) {
			return;
		}
		?>
		<div class="notice notice-info ewd-upcp-bf2026-notice">
			<img class="ewd-upcp-bf2026-icon" src="<?php echo esc_url( EWD_UPCP_PLUGIN_URL . '/assets/img/ewd-upcp-icon.png' ); ?>" width="100" height="100" alt="">
			<div class="ewd-upcp-bf2026-content">
				<p><?php esc_html_e( 'Take advantage of our Black Friday sale to upgrade to Ultimate Product Catalog Premium! Use code BF2026 at checkout to get 20% off the regular price. The sale ends at 11:59:59 PM EST on November 30.', 'ultimate-product-catalogue' ); ?></p>
				<p><a class="button button-primary" href="<?php echo esc_url( 'https://www.etoilewebdesign.com/license-payment/?Selected=UPCP&Quantity=1&utm_source=plugin_admin_bf2026' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Upgrade to Premium', 'ultimate-product-catalogue' ); ?></a></p>
				<p class="ewd-upcp-bf2026-actions">
					<button type="button" class="button ewd-upcp-bf2026-dismiss" data-dismissal="temporary"><?php esc_html_e( 'Hide Temporarily', 'ultimate-product-catalogue' ); ?></button>
					<button type="button" class="button ewd-upcp-bf2026-dismiss" data-dismissal="permanent"><?php esc_html_e( "Don't Show Again", 'ultimate-product-catalogue' ); ?></button>
					<span class="ewd-upcp-bf2026-status" role="status" aria-live="polite"></span>
				</p>
			</div>
		</div>
		<?php
	}

	public function dismiss() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( self::ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not authorized to hide this notice.', 'ultimate-product-catalogue' ) ), 403 );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only the two exact values below are accepted.
		$mode = isset( $_POST['dismissal'] ) && is_string( $_POST['dismissal'] ) ? wp_unslash( $_POST['dismissal'] ) : '';
		if ( ! in_array( $mode, array( 'temporary', 'permanent' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid dismissal choice.', 'ultimate-product-catalogue' ) ), 400 );
		}

		$now = $this->now();
		if ( $this->in_window( $now ) ) {
			$remaining = self::END - $now;
			$key       = 'permanent' === $mode ? self::HIDDEN : self::SNOOZED;
			$ttl       = 'permanent' === $mode ? $remaining : min( HOUR_IN_SECONDS, $remaining );
			// A stale tab's snooze cannot overwrite the permanent dismissal.
			if ( ! set_transient( $key, 1, $ttl ) && false === get_transient( $key ) ) {
				wp_send_json_error( array( 'message' => __( 'Could not hide this notice. Please try again.', 'ultimate-product-catalogue' ) ), 500 );
			}
		}
		wp_send_json_success();
	}
}
