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

		public function check_permission( $permission_type = '' ) {

			if ( ! $this->permission_level ) {
				$this->get_permission_level(); }

			return ( array_key_exists( $permission_type, $this->plugin_permissions ) ? ( $this->permission_level >= $this->plugin_permissions[ $permission_type ] ? true : false ) : false );
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
