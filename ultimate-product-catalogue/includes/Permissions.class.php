<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ewdupcpPermissions' ) ) {
	/**
	 * Class to handle plugin permissions for Ultimate Product Catalog
	 *
	 * @since 5.0.0
	 */
	class ewdupcpPermissions {

		private $plugin_permissions;
		private $permission_level;

		public function __construct() {

			add_filter( 'user_has_cap', array( $this, 'filter_create_product_capability' ), 20, 4 );

			$this->plugin_permissions = array(
				'styling'       => 2,
				'premium'       => 2,
				'custom_fields' => 2,
				'product_page'  => 2,
				'woocommerce'   => 2,
				'seo'           => 2,
				'import'        => 2,
				'export'        => 2,
				'labelling'     => 2,
			);
		}

		public function set_permissions() {
			global $ewd_upcp_controller;

			if ( is_array( get_option( 'ewd-upcp-permission-level' ) ) ) {
				return; }

			if ( ! empty( get_option( 'ewd-upcp-permission-level' ) ) ) {

				update_option( 'ewd-upcp-permission-level', array( get_option( 'ewd-upcp-permission-level' ) ) );

				return;
			}

			$this->permission_level = get_option( 'UPCP_Full_Version' ) == 'Yes' ? 2 : 1;

			update_option( 'ewd-upcp-permission-level', array( $this->permission_level ) );
		}

		public function get_permission_level() {

			if ( ! is_array( get_option( 'ewd-upcp-permission-level' ) ) ) {
				$this->set_permissions(); }

			$permissions_array = get_option( 'ewd-upcp-permission-level' );

			$this->permission_level = is_array( $permissions_array ) ? reset( $permissions_array ) : $permissions_array;
		}

		public function check_permission( $permission_type = '', $fresh = false ) {
			if ( ! array_key_exists( $permission_type, $this->plugin_permissions ) || ! defined( 'EWDPH_VERSION' )
				|| ! class_exists( 'ewdphUPCPCompatibility' ) ) {
				return false;
			}
			$compatibility = ewdupcpHelperCompatibility::evaluate( true, true, ewdphUPCPCompatibility::get_descriptor(), false );
			if ( ! in_array( $compatibility['state'], array( 'compatible', 'compatible_premium_inactive' ), true ) ) {
				return false;
			}
			$hook = $fresh ? 'ewd_upcp_entitlement_contract_fresh' : 'ewd_upcp_entitlement_contract';
			return self::evaluate_contract( apply_filters( $hook, array() ), $permission_type, time() );
		}

		/** Evaluate the sanitized family contract locally, including exact deadline boundaries. */
		public static function evaluate_contract( $contract, $capability, $now ) {
			if ( ! is_array( $contract ) || ! isset( $contract['contract_version'], $contract['product'], $contract['helper_api_version'], $contract['helper_version'], $contract['effective_status'], $contract['capabilities'], $contract['key_fingerprint'], $contract['site_fingerprint'], $contract['source'], $contract['reason_code'] )
				|| 1 !== $contract['contract_version'] || 'UPCP' !== $contract['product'] || 2 !== $contract['helper_api_version']
				|| ! is_string( $contract['helper_version'] ) || version_compare( $contract['helper_version'], EWD_UPCP_MIN_HELPER_VERSION, '<' )
				|| ! is_string( $contract['effective_status'] ) || ! is_array( $contract['capabilities'] )
				|| ! is_string( $contract['key_fingerprint'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $contract['key_fingerprint'] )
				|| ! is_string( $contract['site_fingerprint'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $contract['site_fingerprint'] )
				|| ! is_string( $contract['source'] ) || ! is_string( $contract['reason_code'] ) ) {
				return false;
			}
			$state = $contract['effective_status'];
			if ( in_array( $state, array( 'valid', 'valid_cached' ), true ) ) {
				if ( ! isset( $contract['permission_level'], $contract['last_success_at'], $contract['grace_expires_at'] )
					|| ! array_key_exists( 'license_expires_at', $contract ) || null !== $contract['license_expires_at']
					|| 2 !== $contract['permission_level'] || ! isset( $contract['plan'], $contract['site_authorized'] ) || '' !== $contract['plan'] || true !== $contract['site_authorized']
					|| ! is_int( $contract['last_success_at'] ) || $contract['last_success_at'] <= 0 || $contract['last_success_at'] > $now
					|| $contract['grace_expires_at'] !== $contract['last_success_at'] + 604800 || $now >= $contract['grace_expires_at'] ) {
					return false;
				}
			} elseif ( 'trial' === $state ) {
				if ( ! isset( $contract['trial_expires_at'] ) || ! is_int( $contract['trial_expires_at'] ) || $now >= $contract['trial_expires_at'] ) {
					return false;
				}
			} elseif ( 'legacy_transition' === $state ) {
				if ( ! isset( $contract['legacy_transition_started_at'], $contract['legacy_transition_expires_at'] )
					|| ! is_int( $contract['legacy_transition_started_at'] ) || $contract['legacy_transition_started_at'] <= 0
					|| $contract['legacy_transition_started_at'] > $now
					|| $contract['legacy_transition_expires_at'] !== $contract['legacy_transition_started_at'] + 604800
					|| $now >= $contract['legacy_transition_expires_at'] ) {
					return false;
				}
			} else {
				return false;
			}
			$known = array( 'premium', 'styling', 'custom_fields', 'product_page', 'woocommerce', 'seo', 'import', 'export', 'labelling' );
			foreach ( $contract['capabilities'] as $grant ) {
				if ( ! is_string( $grant ) || ! in_array( $grant, $known, true ) ) {
					return false;
				}
			}
			return in_array( $capability, $known, true ) && in_array( $capability, $contract['capabilities'], true );
		}

		public function update_permissions() {

			$this->get_permission_level();
		}

		/**
		 * Whether the current request may create another UPCP product.
		 *
		 * @return bool
		 */
		public function can_create_products() {

			return $this->check_permission( 'premium' ) || ! $this->has_reached_free_product_limit();
		}

		/**
		 * Preserve the existing Free limit without mutating persistent role records.
		 *
		 * @param array   $allcaps Effective capabilities.
		 * @param array   $caps Requested primitive capabilities.
		 * @param array   $args Capability check arguments.
		 * @param WP_User $user User being checked.
		 * @return array
		 */
		public function filter_create_product_capability( $allcaps, $caps, $args, $user ) {

			$requested = isset( $args[0] ) ? $args[0] : '';

			if ( 'create_upcp_products' !== $requested && ! in_array( 'create_upcp_products', $caps, true ) ) {
				return $allcaps;
			}

			if ( ! $this->can_create_products() ) {
				$allcaps['create_upcp_products'] = false;
			}

			return $allcaps;
		}

		/**
		 * Match the existing limit semantics: count up to 100 published products.
		 *
		 * @return bool
		 */
		public function has_reached_free_product_limit() {

			$products = get_posts(
				array(
					'post_type'      => EWD_UPCP_PRODUCT_POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);

			return count( $products ) >= 100;
		}
	}

}
