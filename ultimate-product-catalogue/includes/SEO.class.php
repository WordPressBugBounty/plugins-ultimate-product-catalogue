<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ewdupcpSEO' ) ) {
	/**
	 * Class to handle interactions with the Yoast SEO platform
	 *
	 * @since 5.0.0
	 */
	class ewdupcpSEO {

		public function __construct() {

			add_action( 'init', array( $this, 'add_hooks' ) );
		}

		/**
		 * Adds in the necessary hooks to handle WooCommerce integration
		 * @since 5.0.0
		 */
		public function add_hooks() {
			global $ewd_upcp_controller;

			add_filter( 'wp_sitemaps_post_types', array( $this, 'filter_core_sitemap_post_types' ) );
			add_filter( 'wp_sitemaps_taxonomies', array( $this, 'filter_core_sitemap_taxonomies' ) );
			add_filter( 'wp_robots', array( $this, 'filter_robots' ) );

			if ( 'yoast' !== $ewd_upcp_controller->settings->get_setting( 'seo-plugin' ) ) {
				return; }

			if ( $this->catalog_only_enabled() ) {
				add_filter( 'wpseo_robots', array( $this, 'filter_yoast_robots' ) );
				add_filter( 'wpseo_sitemap_exclude_post_type', array( $this, 'filter_yoast_post_type' ), 10, 2 );
				add_filter( 'wpseo_sitemap_exclude_taxonomy', array( $this, 'filter_yoast_taxonomy' ), 10, 2 );

				return;
			}

			// @to-do: Deprecated, see https://github.com/Yoast/wordpress-seo/issues/14698 for how to replace
			//add_filter( 'wpseo_opengraph_image',  array( $this, 'add_open_graph_image' ) );

			add_filter( 'wpseo_canonical', array( $this, 'change_canonical_url' ) );

			add_filter( 'wpseo_metadesc', array( $this, 'change_description' ) );
			add_filter( 'wpseo_opengraph_desc', array( $this, 'change_description' ) );

			add_filter( 'wpseo_title', array( $this, 'change_title' ) );
			add_filter( 'wpseo_opengraph_title', array( $this, 'change_title' ) );
		}

		private function catalog_only_enabled() {
			global $ewd_upcp_controller;

			return (bool) $ewd_upcp_controller->settings->get_setting( 'catalog-only-visibility' );
		}

		public function filter_core_sitemap_post_types( $post_types ) {
			if ( $this->catalog_only_enabled() ) {
				unset( $post_types[ EWD_UPCP_PRODUCT_POST_TYPE ] );
			}

			return $post_types;
		}

		public function filter_core_sitemap_taxonomies( $taxonomies ) {
			if ( $this->catalog_only_enabled() ) {
				unset( $taxonomies[ EWD_UPCP_PRODUCT_CATEGORY_TAXONOMY ] );
				unset( $taxonomies[ EWD_UPCP_PRODUCT_TAG_TAXONOMY ] );
			}

			return $taxonomies;
		}

		public function filter_robots( $robots ) {
			if ( $this->catalog_only_enabled() && $this->is_upcp_public_route() ) {
				$robots['noindex'] = true;
				$robots['follow']  = true;
			}

			return $robots;
		}

		public function filter_yoast_robots( $robots ) {
			return $this->is_upcp_public_route() ? 'noindex, follow' : $robots;
		}

		public function filter_yoast_post_type( $excluded, $post_type ) {
			return EWD_UPCP_PRODUCT_POST_TYPE === $post_type ? true : $excluded;
		}

		public function filter_yoast_taxonomy( $excluded, $taxonomy ) {
			return in_array( $taxonomy, array( EWD_UPCP_PRODUCT_CATEGORY_TAXONOMY, EWD_UPCP_PRODUCT_TAG_TAXONOMY ), true ) ? true : $excluded;
		}

		private function is_upcp_public_route() {
			if ( is_singular( EWD_UPCP_PRODUCT_POST_TYPE ) || is_tax( array( EWD_UPCP_PRODUCT_CATEGORY_TAXONOMY, EWD_UPCP_PRODUCT_TAG_TAXONOMY ) ) ) {
				return true;
			}

			$query_product = get_query_var( 'single_product' );
			if ( is_scalar( $query_product ) && '' !== trim( (string) $query_product ) ) {
				if ( ! $this->queried_page_contains_catalog() ) {
					return false;
				}
				$product = get_page_by_path( sanitize_title( trim( (string) $query_product, '/? ' ) ), OBJECT, EWD_UPCP_PRODUCT_POST_TYPE );

				return ! empty( $product );
			}

			if ( empty( $_GET['singleproduct'] ) || ! is_scalar( $_GET['singleproduct'] ) || ! $this->queried_page_contains_catalog() ) {
				return false;
			}

			$product_id = absint( wp_unslash( $_GET['singleproduct'] ) );
			$product    = $product_id ? get_post( $product_id ) : false;

			return $product && EWD_UPCP_PRODUCT_POST_TYPE === $product->post_type;
		}

		/** Confirm that a query-string product is being displayed by an UPCP catalog page. */
		private function queried_page_contains_catalog() {
			$page = get_queried_object();
			if ( ! $page instanceof WP_Post || empty( $page->post_content ) ) {
				return false;
			}

			return has_shortcode( $page->post_content, 'product-catalog' )
			|| has_shortcode( $page->post_content, 'product-catalogue' )
			|| has_block( 'ultimate-product-catalogue/ewd-upcp-display-catalog-block', $page->post_content );
		}

		/**
		 * Adds the product's image to the open graph information (used by Facebook)
		 * @since 5.0.0
		 */
		public function add_open_graph_image( $image ) {
			global $ewd_upcp_controller;

			if ( empty( get_query_var( 'single_product' ) ) and empty( $_GET['singleproduct'] ) ) {
				return $image; }

			if ( ! empty( $_GET['singleproduct'] ) ) {
				$selected_product_id = intval( $_GET['singleproduct'] ); }
			if ( ! empty( get_query_var( 'single_product' ) ) ) {

				$post = get_page_by_path( sanitize_text_field( trim( get_query_var( 'single_product' ), '/? ' ) ), OBJECT, EWD_UPCP_PRODUCT_POST_TYPE );

				$selected_product_id = ! empty( $post ) ? $post->ID : 0;
			}

			if ( empty( $selected_product_id ) ) {
				return $image; }

			$product = new ewdupcpProduct();

			$product->load_post( $selected_product_id );

			return $product->get_main_image_url();

			//$GLOBALS['wpseo_og']->image_output( $product->get_main_image_url() );
		}

		/**
		 * Updates the canonical URL of the product, so that it points to the correct place
		 * @since 5.0.0
		 */
		public function change_canonical_url( $str ) {
			global $ewd_upcp_controller;

			if ( ! empty( get_query_var( 'single_product' ) ) ) {

				return $str . $ewd_upcp_controller->settings->get_setting( 'permalink-base' ) . '/' . get_query_var( 'single_product' ) . '/';
			} elseif ( ! empty( $_GET['singleproduct'] ) ) {

				return $str . '?singleproduct=' . intval( $_GET['singleproduct'] );
			}

			return $str;
		}

		/**
		 * Updates the SEO title of the page, when using the Yoast SEO plugin
		 * @since 5.0.0
		 */
		public function change_description( $str ) {
			global $ewd_upcp_controller;

			if ( ! empty( $_GET['singleproduct'] ) ) {
				$selected_product_id = intval( $_GET['singleproduct'] ); }
			if ( ! empty( get_query_var( 'single_product' ) ) ) {

				$post = get_page_by_path( sanitize_text_field( trim( get_query_var( 'single_product' ), '/? ' ) ), OBJECT, EWD_UPCP_PRODUCT_POST_TYPE );

				$selected_product_id = ! empty( $post ) ? $post->ID : 0;
			}

			if ( empty( $selected_product_id ) ) {
				return $str; }

			$str = 'replace' === $ewd_upcp_controller->settings->get_setting( 'seo-integration' ) ? get_post_meta( $selected_product_id, '_yoast_wpseo_metadesc', true ) : $str . get_post_meta( $selected_product_id, '_yoast_wpseo_metadesc', true );

			return $str;
		}

		/**
		 * Updates the SEO title of the page, when using the Yoast SEO plugin
		 * @since 5.0.0
		 */
		public function change_title( $str ) {
			global $ewd_upcp_controller;

			if ( ! empty( $_GET['singleproduct'] ) ) {
				$selected_product_id = intval( $_GET['singleproduct'] ); }
			if ( ! empty( get_query_var( 'single_product' ) ) ) {

				$post = get_page_by_path( sanitize_text_field( trim( get_query_var( 'single_product' ), '/? ' ) ), OBJECT, EWD_UPCP_PRODUCT_POST_TYPE );

				$selected_product_id = ! empty( $post ) ? $post->ID : 0;
			}

			if ( empty( $selected_product_id ) ) {
				return $str; }

			$product = new ewdupcpProduct();

			$product->load_post( $selected_product_id );

			$search = array(
				'[page-title]',
				'[product-name]',
				'[category-name]',
				'[subcategory-name]',
			);

			$replace = array(
				$str,
				$product->name,
				$product->get_category_names(),
				$product->get_subcategory_names(),
			);

			return str_replace( $search, $replace, $ewd_upcp_controller->settings->get_setting( 'seo-title' ) );
		}
	}

}
