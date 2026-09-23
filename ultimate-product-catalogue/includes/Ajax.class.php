<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ewdupcpAJAX' ) ) {
	/**
	 * Class to handle AJAX interactions for Ultimate Product Catalog
	 *
	 * @since 5.0.0
	 */
	class ewdupcpAJAX {
		/** Hard ceiling for a public catalog fragment response. */
		const MAX_PRODUCTS_PER_PAGE = 100;

		public function __construct() {

			add_action( 'wp_ajax_ewd_upcp_record_view', array( $this, 'record_view' ) );
			add_action( 'wp_ajax_nopriv_ewd_upcp_record_view', array( $this, 'record_view' ) );

			add_action( 'wp_ajax_ewd_upcp_update_catalog', array( $this, 'update_catalog' ) );
			add_action( 'wp_ajax_nopriv_ewd_upcp_update_catalog', array( $this, 'update_catalog' ) );

			add_action( 'wp_ajax_ewd_upcp_clear_cart', array( $this, 'clear_cart' ) );
			add_action( 'wp_ajax_nopriv_ewd_upcp_clear_cart', array( $this, 'clear_cart' ) );

			add_action( 'wp_ajax_ewd_upcp_add_to_cart', array( $this, 'add_to_cart' ) );
			add_action( 'wp_ajax_nopriv_ewd_upcp_add_to_cart', array( $this, 'add_to_cart' ) );
		}

		/**
		 * Record the viewing of a product
		 * @since 5.0.0
		 */
		public function record_view() {
			global $ewd_upcp_controller;

			// Authenticate request
			if ( ! check_ajax_referer( 'ewd-upcp-js', 'nonce' ) ) {
				ewdupcpHelper::bad_nonce_ajax();
			}

			$product_id = intval( $_POST['product_id'] );

			update_post_meta( $product_id, 'views', get_option( $product_id, 'views', true ) + 1 );
		}

		/**
		 * Returns the output for a single order, given its tracking number and (optionally) email
		 * @since 5.0.0
		 */
		public function update_catalog() {
			global $ewd_upcp_controller;

			// Authenticate request
			if ( ! check_ajax_referer( 'ewd-upcp-js', 'nonce' ) ) {
				ewdupcpHelper::bad_nonce_ajax();
			}

			$request = wp_unslash( $_POST );
			$state   = ewdupcpViewCatalog::normalize_request_state( $request );
			$id      = isset( $request['id'] ) && is_scalar( $request['id'] ) ? absint( $request['id'] ) : 0;
			if ( ! $id || EWD_UPCP_CATALOG_POST_TYPE !== get_post_type( $id ) ) {
				wp_send_json_error( array( 'code' => 'invalid_catalog' ), 400 );
			}

			$current_page        = isset( $request['current_page'] ) && is_scalar( $request['current_page'] ) ? max( 1, absint( $request['current_page'] ) ) : 1;
			$requested_per_page  = isset( $request['products_per_page'] ) && is_scalar( $request['products_per_page'] ) ? absint( $request['products_per_page'] ) : 0;
			$configured_per_page = absint( $ewd_upcp_controller->settings->get_setting( 'products-per-page' ) );
			$products_per_page   = $requested_per_page ? $requested_per_page : $configured_per_page;
			$products_per_page   = max( 1, min( self::MAX_PRODUCTS_PER_PAGE, $products_per_page ) );

			$excluded_layouts = array();
			if ( isset( $request['excluded_views'] ) && is_scalar( $request['excluded_views'] ) ) {
				$excluded_layouts = array_intersect(
					array_map( 'sanitize_key', explode( ',', (string) $request['excluded_views'] ) ),
					array( 'thumbnail', 'list', 'detail' )
				);
			}
			$ajax_url = isset( $request['ajax_url'] ) && is_scalar( $request['ajax_url'] ) ? esc_url_raw( (string) $request['ajax_url'] ) : '';
			$ajax_url = wp_validate_redirect( $ajax_url, home_url( '/' ) );

			$args = array(
				'sidebar'           => 'no',
				'starting_layout'   => '',
				'id'                => $id,
				'current_page'      => $current_page,
				'products_per_page' => $products_per_page,
				'excluded_layouts'  => $excluded_layouts ? implode( ',', $excluded_layouts ) : 'none',
				'ajax_url'          => $ajax_url,
				'prod_name'         => $state['prod_name'],
				'min_price'         => $state['min_price'],
				'max_price'         => $state['max_price'],
				'category'          => implode( ',', $state['categories'] ),
				'subcategory'       => implode( ',', $state['subcategories'] ),
				'tags'              => implode( ',', $state['tags'] ),
				'custom_fields'     => implode( ',', $state['custom_fields'] ),
				'orderby'           => $state['orderby'],
				'order'             => $state['order'],
			);

			$catalog_view = new ewdupcpViewCatalog( $args );

			$catalog_view->set_variables();

			$catalog_view->set_items();

			$catalog_view->set_pagination_data();

			ob_start();

			$catalog_view->print_view( 'thumbnail' );

			$thumbnail_view = ob_get_contents();

			ob_clean();

			$catalog_view->print_view( 'list' );

			$list_view = ob_get_contents();

			ob_clean();

			$catalog_view->print_view( 'detail' );

			$detail_view = ob_get_clean();

			$filtering_data = $catalog_view->get_filtering_data();

			wp_send_json_success(
				array(
					'request_count'  => isset( $request['request_count'] ) && is_scalar( $request['request_count'] ) ? absint( $request['request_count'] ) : 0,
					'thumbnail_view' => $thumbnail_view,
					'list_view'      => $list_view,
					'detail_view'    => $detail_view,
					'filters'        => $filtering_data,
				)
			);

			die();
		}

		/**
		 * Clear out the inquiry and WC carts, if enabled
		 * @since 5.0.0
		 */
		public function clear_cart() {
			global $ewd_upcp_controller;
			global $woocommerce;

			// Authenticate request
			if ( ! check_ajax_referer( 'ewd-upcp-js', 'nonce' ) ) {
				ewdupcpHelper::bad_nonce_ajax();
			}

			setcookie( 'upcp_cart_products', '', time() - 3600, '/' );

			if ( ! empty( $ewd_upcp_controller->settings->get_setting( 'woocommerce-checkout' ) ) and ! empty( $ewd_upcp_controller->settings->get_setting( 'woocommerce-sync' ) ) and is_object( $woocommerce ) ) {

				$woocommerce->cart->empty_cart();
			}

			die();
		}

		/**
		 * Add products to the inquiry and WC carts, if enabled
		 * @since 5.0.0
		 */
		public function add_to_cart() {
			global $ewd_upcp_controller;
			global $woocommerce;

			// Authenticate request
			if ( ! check_ajax_referer( 'ewd-upcp-js', 'nonce' ) ) {
				ewdupcpHelper::bad_nonce_ajax();
			}

			$product_id = isset( $_POST['product_id'] ) && is_scalar( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
			$quantity = isset( $_POST['quantity'] ) && is_scalar( $_POST['quantity'] ) ? filter_var( wp_unslash( $_POST['quantity'] ), FILTER_VALIDATE_INT ) : false;
			if ( ! $product_id || EWD_UPCP_PRODUCT_POST_TYPE !== get_post_type( $product_id ) || 'publish' !== get_post_status( $product_id ) || false === $quantity || $quantity < 1 ) {
				wp_send_json_error( array( 'message' => __( 'Please select a valid product and quantity.', 'ultimate-product-catalogue' ) ) );
			}

			if ( ! empty( $ewd_upcp_controller->settings->get_setting( 'woocommerce-checkout' ) ) && ! empty( $ewd_upcp_controller->settings->get_setting( 'woocommerce-sync' ) ) ) {

				$woocommerce_id = absint( get_post_meta( $product_id, 'woocommerce_id', true ) );
				$added = false;
				try {
					if ( is_object( $woocommerce ) && $woocommerce_id ) {
						if ( ! $woocommerce->cart && function_exists( 'wc_load_cart' ) ) {
							wc_load_cart();
						}
						$added = $woocommerce->cart && $woocommerce->cart->add_to_cart( $woocommerce_id, $quantity );
					}
				} catch ( Exception $exception ) {
					$added = false;
				}
				if ( ! $added ) {
					wp_send_json_error( array( 'message' => __( 'This product could not be added to the cart. Check its availability and required options in the shop.', 'ultimate-product-catalogue' ) ) );
				}
			}

			$products = ! empty( $_COOKIE['upcp_cart_products'] ) ? explode( ',', sanitize_text_field( $_COOKIE['upcp_cart_products'] ) ) : array();

			$products[] = $product_id;

			$products = array_unique( $products );

			setcookie( 'upcp_cart_products', implode( ',', $products ), time() + 3600 * 24 * 3, '/' );

			wp_send_json_success();
		}
	}
}
