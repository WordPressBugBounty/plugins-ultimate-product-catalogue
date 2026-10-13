<?php
/**
 * Evaluate compatibility between Ultimate Product Catalog and EWD Premium Helper.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ewdupcpHelperCompatibility' ) ) {
	class ewdupcpHelperCompatibility {

		private $state   = 'missing';
		private $details = array();

		public function __construct() {

			add_action( 'plugins_loaded', array( $this, 'refresh' ), 100 );
			add_action( 'admin_notices', array( $this, 'display_admin_notice' ) );

			$this->refresh();
		}

		public static function get_descriptor() {

			return array(
				'plugin_version'         => EWD_UPCP_VERSION,
				'api_version'            => EWD_UPCP_HELPER_API_VERSION,
				'min_helper_version'     => EWD_UPCP_MIN_HELPER_VERSION,
				'max_helper_api_version' => EWD_UPCP_MAX_HELPER_API_VERSION,
				'entitlement_contract_version' => EWD_UPCP_ENTITLEMENT_CONTRACT_VERSION,
			);
		}

		public function refresh() {

			global $ewd_upcp_controller;

			$helper_active     = defined( 'EWDPH_VERSION' );
			$helper_installed  = $helper_active || file_exists( WP_PLUGIN_DIR . '/ewd-premium-helper/ewd-premium-helper.php' );
			$helper_descriptor = array();

			if ( class_exists( 'ewdphUPCPCompatibility' ) ) {
				$helper_descriptor = ewdphUPCPCompatibility::get_descriptor();
			}

			$premium_active = is_object( $ewd_upcp_controller )
				&& is_object( $ewd_upcp_controller->permissions )
				&& $ewd_upcp_controller->permissions->check_permission( 'premium' );

			$result                        = self::evaluate( $helper_active, $helper_installed, $helper_descriptor, $premium_active );
			$result['presence_state']      = $helper_active ? 'active' : ( $helper_installed ? 'installed_inactive' : 'not_installed' );
			$result['compatibility_state'] = $this->normalize_compatibility_state( $result['state'], $helper_active );
			$result['entitlement_state']   = $this->resolve_entitlement_state( $premium_active );

			$this->state   = $result['state'];
			$this->details = $result;

			do_action( 'ewd_upcp_helper_compatibility_state', $this->state, $this->details );
		}

		public static function evaluate( $helper_active, $helper_installed, $helper_descriptor, $premium_active ) {

			if ( ! $helper_active ) {
				return array( 'state' => $helper_installed ? 'installed_inactive' : 'missing' );
			}

			if ( empty( $helper_descriptor ) || ! is_array( $helper_descriptor ) ) {
				return array( 'state' => 'active_legacy_no_descriptor' );
			}

			$helper_version = isset( $helper_descriptor['plugin_version'] ) ? $helper_descriptor['plugin_version'] : '0';
			$helper_api     = isset( $helper_descriptor['api_version'] ) ? absint( $helper_descriptor['api_version'] ) : 0;
			$free_api_min   = isset( $helper_descriptor['supported_free_api_min'] ) ? absint( $helper_descriptor['supported_free_api_min'] ) : 0;
			$free_api_max   = isset( $helper_descriptor['supported_free_api_max'] ) ? absint( $helper_descriptor['supported_free_api_max'] ) : 0;

			if ( ! isset( $helper_descriptor['entitlement_contract_version'] )
				|| EWD_UPCP_ENTITLEMENT_CONTRACT_VERSION !== $helper_descriptor['entitlement_contract_version']
				|| version_compare( $helper_version, EWD_UPCP_MIN_HELPER_VERSION, '<' )
				|| $helper_api < EWD_UPCP_HELPER_API_VERSION
				|| EWD_UPCP_HELPER_API_VERSION < $free_api_min ) {
				return array(
					'state'          => 'too_old',
					'helper_version' => $helper_version,
					'helper_api'     => $helper_api,
				);
			}

			if ( $helper_api > EWD_UPCP_MAX_HELPER_API_VERSION
				|| EWD_UPCP_HELPER_API_VERSION > $free_api_max ) {
				return array(
					'state'          => 'unsupported_newer_api',
					'helper_version' => $helper_version,
					'helper_api'     => $helper_api,
				);
			}

			return array(
				'state'          => $premium_active ? 'compatible' : 'compatible_premium_inactive',
				'helper_version' => $helper_version,
				'helper_api'     => $helper_api,
			);
		}

		/** Historical evidence is notice scope only, never permission to run paid features. */
		public function has_prior_access() {
			$key = get_option( 'EWD_UPCP_License_Key', '' );
			if ( is_string( $key ) && '' !== trim( $key ) && 'EWD Trial' !== trim( $key ) ) {
				return true;
			}
			$state = get_option( 'ewdph-upcp-entitlement-v1', array() );
			return is_array( $state ) && ! empty( $state['last_success_at'] );
		}

		public function get_state() {
			return $this->state;
		}

		public function get_details() {
			return $this->details;
		}

		/** Return the three independent administrator lifecycle dimensions. */
		public function get_lifecycle_status() {
			return array(
				'presence'      => isset( $this->details['presence_state'] ) ? $this->details['presence_state'] : 'not_installed',
				'compatibility' => isset( $this->details['compatibility_state'] ) ? $this->details['compatibility_state'] : 'not_applicable',
				'entitlement'   => isset( $this->details['entitlement_state'] ) ? $this->details['entitlement_state'] : 'free_no_entitlement',
			);
		}

		/** Human-readable lifecycle labels for the UPCP dashboard. */
		public function get_lifecycle_labels() {
			$status = $this->get_lifecycle_status();
			$labels = array(
				'presence'      => array(
					'not_installed'      => __( 'Not installed', 'ultimate-product-catalogue' ),
					'installed_inactive' => __( 'Installed but inactive', 'ultimate-product-catalogue' ),
					'active'             => __( 'Active', 'ultimate-product-catalogue' ),
				),
				'compatibility' => array(
					'not_applicable'       => __( 'Not applicable', 'ultimate-product-catalogue' ),
					'legacy_compatible'    => __( 'Legacy compatible', 'ultimate-product-catalogue' ),
					'compatible'           => __( 'Compatible', 'ultimate-product-catalogue' ),
					'helper_too_old'       => __( 'Helper needs updating', 'ultimate-product-catalogue' ),
					'free_api_unsupported' => __( 'Ultimate Product Catalog needs updating', 'ultimate-product-catalogue' ),
					'legacy_unsupported'   => __( 'Legacy Helper unsupported', 'ultimate-product-catalogue' ),
				),
				'entitlement'   => array(
					'valid'               => __( 'Premium active', 'ultimate-product-catalogue' ),
					'valid_cached'        => __( 'Premium active using bounded cached validation', 'ultimate-product-catalogue' ),
					'trial'               => __( 'Trial active', 'ultimate-product-catalogue' ),
					'legacy_transition'   => __( 'Temporary legacy transition', 'ultimate-product-catalogue' ),
					'missing'             => __( 'No license saved', 'ultimate-product-catalogue' ),
					'expired'             => __( 'Entitlement expired', 'ultimate-product-catalogue' ),
					'invalid'             => __( 'License rejected', 'ultimate-product-catalogue' ),
					'unauthorized'        => __( 'Site not authorized', 'ultimate-product-catalogue' ),
					'unverifiable'        => __( 'Entitlement unverifiable', 'ultimate-product-catalogue' ),
					'free_no_entitlement' => __( 'Free features', 'ultimate-product-catalogue' ),
					'trial_active'        => __( 'Trial active', 'ultimate-product-catalogue' ),
					'paid_active'         => __( 'Paid access active', 'ultimate-product-catalogue' ),
					'key_missing'         => __( 'Product key not activated', 'ultimate-product-catalogue' ),
				),
			);

			return array(
				'presence'      => isset( $labels['presence'][ $status['presence'] ] ) ? $labels['presence'][ $status['presence'] ] : $status['presence'],
				'compatibility' => isset( $labels['compatibility'][ $status['compatibility'] ] ) ? $labels['compatibility'][ $status['compatibility'] ] : $status['compatibility'],
				'entitlement'   => isset( $labels['entitlement'][ $status['entitlement'] ] ) ? $labels['entitlement'][ $status['entitlement'] ] : $status['entitlement'],
			);
		}

		private function normalize_compatibility_state( $state, $helper_active ) {
			if ( ! $helper_active ) {
				return 'not_applicable'; }
			if ( in_array( $state, array( 'compatible', 'compatible_premium_inactive' ), true ) ) {
				return 'compatible'; }
			if ( 'too_old' === $state ) {
				return 'helper_too_old'; }
			if ( 'unsupported_newer_api' === $state ) {
				return 'free_api_unsupported'; }
			return 'legacy_unsupported';
		}

		private function resolve_entitlement_state( $premium_active ) {
			$contract = apply_filters( 'ewd_upcp_entitlement_contract', array() );
			if ( isset( $contract['effective_status'] ) && is_string( $contract['effective_status'] ) ) {
				return $contract['effective_status'];
			}
			return 'free_no_entitlement';
		}

		public function is_compatible() {
			return in_array( $this->state, array( 'compatible', 'compatible_premium_inactive' ), true );
		}

		public function guard_incompatible_helper_callback() {

			global $ewd_premium_helper;

			$this->refresh();

			if ( $this->is_compatible() || ! is_object( $ewd_premium_helper ) ) {
				return;
			}

			remove_action( 'ewd_upcp_initialized', array( $ewd_premium_helper, 'extend_ewd_upcp_plugin' ) );
		}

		public function display_admin_notice() {

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			if ( ! in_array( $this->state, array( 'active_legacy_no_descriptor', 'too_old', 'unsupported_newer_api' ), true ) ) {
				return;
			}

			if ( 'unsupported_newer_api' === $this->state ) {
				$message = __( 'EWD Premium Helper uses a newer Ultimate Product Catalog compatibility API. Update Ultimate Product Catalog before premium catalog features can load.', 'ultimate-product-catalogue' );
			} else {
				$message = sprintf(
					/* translators: %s: minimum compatible Premium Helper version. */
					esc_html__( 'EWD Premium Helper is not compatible with this version of Ultimate Product Catalog. Update Premium Helper to version %s or newer. Free catalog features remain available.', 'ultimate-product-catalogue' ),
					esc_html( EWD_UPCP_MIN_HELPER_VERSION )
				);
			}

			?>
		<div class="notice notice-error"><p><?php echo wp_kses_post( $message ); ?></p></div>
			<?php
		}
	}
}
