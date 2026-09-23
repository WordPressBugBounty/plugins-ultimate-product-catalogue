<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ewdupcpWooCommerce' ) ) {
	/** Safe WooCommerce synchronization controller. */
	class ewdupcpWooCommerce {

		const CONTRACT_VERSION = 1;
		const BATCH_SIZE       = 50;
		const SUMMARY_OPTION   = 'ewd-upcp-woocommerce-sync-last-run';
		const RUN_OPTION       = 'ewd-upcp-woocommerce-sync-run';
		const REQUEST_LOCK     = 'ewd-upcp-woocommerce-sync-request-lock';
		const IDENTITY_LOCK    = 'ewd-upcp-woocommerce-sync-identity-';
		const RUN_TTL          = 900;
		const CRON_HOOK        = 'ewd_upcp_woocommerce_sync_tick';

		private $sync_guards     = array();
		private $active_entities = array();

		public function __construct() {
			add_action( 'plugins_loaded', array( $this, 'add_hooks' ), 110 );
			add_action( 'init', array( $this, 'ensure_automatic_sync' ), 20 );
			add_action( 'shutdown', array( $this, 'ensure_automatic_sync' ) );
			add_action( self::CRON_HOOK, array( $this, 'run_automatic_sync' ) );
			add_action( 'wp_ajax_ewd_upcp_run_woocommerce_sync_batch', array( $this, 'run_sync_batch' ) );
			add_filter( 'pre_update_option_ewd-upcp-settings', array( $this, 'validate_contract_setting' ), 10, 2 );
		}

		public function add_hooks() {
			global $ewd_upcp_controller;

			if ( ! $this->sync_enabled() || ! $ewd_upcp_controller->permissions->check_permission( 'woocommerce' ) || ! $ewd_upcp_controller->helper_compatibility->is_compatible() ) {
				return;
			}
			if ( ! $this->woocommerce_available() ) {
				add_action( 'admin_notices', array( $this, 'display_woocommerce_inactive_notice' ) );
				return;
			}

			add_action( 'woocommerce_cart_emptied', array( $this, 'clear_product_catalog_cart' ) );
			add_action( 'woocommerce_before_single_product', array( $this, 'maybe_add_back_to_catalog_link' ) );
			add_action( 'edited_product_cat', array( $this, 'import_category_into_upcp' ) );
			add_action( 'edited_product_tag', array( $this, 'import_tag_into_upcp' ) );
			add_action( 'woocommerce_attribute_added', array( $this, 'import_attributes_into_upcp' ) );
			add_action( 'woocommerce_attribute_updated', array( $this, 'import_attributes_into_upcp' ) );
			add_action( 'woocommerce_update_product', array( $this, 'import_product_into_upcp' ) );
			add_action( 'edited_' . EWD_UPCP_PRODUCT_CATEGORY_TAXONOMY, array( $this, 'import_category_from_upcp' ) );
			add_action( 'edited_' . EWD_UPCP_PRODUCT_TAG_TAXONOMY, array( $this, 'import_tag_from_upcp' ) );
			add_action( 'ewd_upcp_product_saved', array( $this, 'import_product_from_upcp' ) );
		}

		/** Legacy entry point retained as a safe no-op; full sync is automatically scheduled in batches. */
		public function run_sync() {
			$this->log( 'synchronous_sync_blocked' );
			return false;
		}

		/** Retain the public filter callback, but acknowledgement no longer gates enabling. */
		public function validate_contract_setting( $new_value, $old_value ) {
			// Capture the pre-save legacy state, including a first enable/disable request.
			$lock = $this->acquire_request_lock( 'migration' );
			if ( $lock ) {
				try {
					$this->initialize_sync_state( is_array( $old_value ) && ! empty( $old_value['woocommerce-sync'] ) );
				} finally {
					$this->release_request_lock( $lock );
				}
			}
			return $new_value;
		}

		public function clear_product_catalog_cart() {
			global $ewd_upcp_controller;
			$ewd_upcp_controller->ajax->clear_cart();
		}

		public function display_woocommerce_inactive_notice() {
			?>
		<div class="notice notice-warning"><p><?php esc_html_e( 'Ultimate Product Catalog synchronization is enabled, but WooCommerce is not active. No synchronization was attempted.', 'ultimate-product-catalogue' ); ?></p></div>
			<?php
		}

		public function maybe_add_back_to_catalog_link() {
			global $ewd_upcp_controller;
			if ( ! $ewd_upcp_controller->settings->get_setting( 'woocommerce-back-link' ) || empty( $_GET['ewd_upcp_catalog_url'] ) ) {
				return;
			}
			$url = esc_url( wp_unslash( $_GET['ewd_upcp_catalog_url'] ) );
			?>
		<style>.woocommerce-breadcrumb{display:none;}</style>
		<a class="ewd-upcp-catalogue-link" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $ewd_upcp_controller->settings->get_setting( 'label-back-to-catalog' ) ); ?></a>
			<?php
		}

		/** Woo attributes are authoritative; project them into linked UPCP fields only. */
		public function import_attributes_into_upcp( $attribute_id = 0 ) {
			if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
				return;
			}
			foreach ( wc_get_attribute_taxonomies() as $attribute ) {
				if ( $attribute_id && absint( $attribute->attribute_id ) !== absint( $attribute_id ) ) {
					continue;
				}
				$this->import_attribute_into_upcp( $attribute );
			}
		}

		/** UPCP custom fields never mutate Woo global attributes in contract v1. */
		public function import_custom_fields_from_upcp() {
			$this->log( 'upcp_attribute_reverse_sync_skipped' );
			return false;
		}

		private function import_attribute_into_upcp( $attribute ) {
			global $ewd_upcp_controller;
			if ( ! is_object( $attribute ) || empty( $attribute->attribute_id ) ) {
				return false;
			}

			$taxonomy = function_exists( 'wc_attribute_taxonomy_name' ) ? wc_attribute_taxonomy_name( $attribute->attribute_name ) : 'pa_' . $attribute->attribute_name;
			$terms    = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);
			$options  = is_wp_error( $terms ) ? array() : wp_list_pluck( $terms, 'name' );
			$fields   = $ewd_upcp_controller->settings->get_custom_fields();
			$max_id   = 0;
			$found    = false;

			foreach ( $fields as $key => $field ) {
				$max_id = max( $max_id, absint( $field->id ) );
				if ( absint( $field->woocommerce_id ) !== absint( $attribute->attribute_id ) ) {
					continue;
				}
				$found                   = true;
				$fields[ $key ]->name    = $attribute->attribute_label;
				$fields[ $key ]->slug    = $attribute->attribute_name;
				$fields[ $key ]->options = implode( ',', $options );
			}

			if ( ! $found ) {
				$fields[] = (object) array(
					'id'                  => $max_id + 1,
					'name'                => $attribute->attribute_label,
					'slug'                => $attribute->attribute_name,
					'type'                => 'select',
					'options'             => implode( ',', $options ),
					'displays'            => array(),
					'filter_control_type' => 'checkbox',
					'searchable'          => false,
					'tabbed_display'      => false,
					'comparison_display'  => false,
					'woocommerce_id'      => absint( $attribute->attribute_id ),
				);
			}

			$ewd_upcp_controller->settings->update_custom_fields( $fields );
			return true;
		}

		public function import_category_into_upcp( $term_id ) {
			return $this->sync_term_into_upcp( $term_id, 'product_cat', EWD_UPCP_PRODUCT_CATEGORY_TAXONOMY, true );
		}
		public function import_tag_into_upcp( $term_id ) {
			return $this->sync_term_into_upcp( $term_id, 'product_tag', EWD_UPCP_PRODUCT_TAG_TAXONOMY, false );
		}
		public function import_category_from_upcp( $term_id ) {
			return $this->sync_term_from_upcp( $term_id, EWD_UPCP_PRODUCT_CATEGORY_TAXONOMY, 'product_cat', true );
		}
		public function import_tag_from_upcp( $term_id ) {
			return $this->sync_term_from_upcp( $term_id, EWD_UPCP_PRODUCT_TAG_TAXONOMY, 'product_tag', false );
		}

		private function sync_term_into_upcp( $source_id, $source_taxonomy, $destination_taxonomy, $hierarchical ) {
			if ( ! empty( $this->active_entities['term'] ) ) {
				return false;
			}
			$source = get_term( absint( $source_id ), $source_taxonomy );
			if ( ! $source || is_wp_error( $source ) ) {
				$this->log( 'stale_linked_term', array( 'source_id' => absint( $source_id ) ) );
				return false;
			}
			$lookup = $this->find_linked_term( $source->term_id, $destination_taxonomy );
			if ( in_array( $lookup['status'], array( 'ambiguous', 'error' ), true ) ) {
				return false;
			}
			$destination    = 'unique' === $lookup['status'] ? $lookup['term'] : false;
			$destination_id = $destination ? $destination->term_id : 0;
			if ( $this->reverse_guarded( 'term', 'woo_to_upcp', $source->term_id, $destination_id ) ) {
				return false;
			}

			$args = array( 'description' => $source->description );
			if ( $hierarchical ) {
				$parent = $this->linked_upcp_parent( $source );
				if ( ! $parent['success'] ) {
					return false;
				}
				$args['parent'] = $parent['id'];
			}
			$identity_lock = false;
			if ( ! $destination ) {
				$identity_lock = $this->acquire_identity_lock( 'woo-term', $source->term_id );
				if ( ! $identity_lock ) {
					$this->log( 'identity_sync_in_progress', array( 'source_id' => $source->term_id ) );
					return false;
				}
				$lookup = $this->find_linked_term( $source->term_id, $destination_taxonomy );
				if ( 'unique' === $lookup['status'] ) {
					$destination    = $lookup['term'];
					$destination_id = $destination->term_id;
				} elseif ( 'missing' !== $lookup['status'] ) {
					$this->release_identity_lock( $identity_lock );
					return false;
				}
			}
			$key                           = $this->guard_key( 'term', 'woo_to_upcp', $source->term_id, $destination_id );
			$this->sync_guards[ $key ]     = true;
			$this->active_entities['term'] = true;
			try {
				$result = $destination
				? wp_update_term( $destination->term_id, $destination_taxonomy, array_merge( $args, array( 'name' => $source->name ) ) )
				: wp_insert_term( $source->name, $destination_taxonomy, $args );
				if ( is_wp_error( $result ) ) {
					$this->log( 'term_crud_error', array( 'source_id' => $source->term_id ) );
					return false;
				}
				$destination_id = $destination ? $destination->term_id : absint( $result['term_id'] );
				update_term_meta( $destination_id, 'woocommerce_id', $source->term_id );
				if ( 'product_cat' === $source_taxonomy ) {
					update_term_meta( $destination_id, 'image', get_term_meta( $source->term_id, 'thumbnail_id', true ) );
				}
				return true;
			} finally {
				unset( $this->sync_guards[ $key ] );
				unset( $this->active_entities['term'] );
				$this->release_identity_lock( $identity_lock );
			}
		}

		private function sync_term_from_upcp( $source_id, $source_taxonomy, $destination_taxonomy, $hierarchical ) {
			if ( ! empty( $this->active_entities['term'] ) ) {
				return false;
			}
			$source = get_term( absint( $source_id ), $source_taxonomy );
			if ( ! $source || is_wp_error( $source ) ) {
				return false;
			}
			$link        = absint( get_term_meta( $source->term_id, 'woocommerce_id', true ) );
			$destination = $link ? get_term( $link, $destination_taxonomy ) : false;
			if ( $link ) {
				$ownership = $this->find_linked_term( $link, $source_taxonomy );
				if ( 'unique' !== $ownership['status'] || absint( $ownership['term']->term_id ) !== absint( $source->term_id ) ) {
					$this->log(
						'ambiguous_term_link',
						array(
							'source_id'      => $source->term_id,
							'destination_id' => $link,
						)
					);
					return false;
				}
			}
			if ( $link && ( ! $destination || is_wp_error( $destination ) ) ) {
				$this->log(
					'stale_linked_term',
					array(
						'source_id'      => $source->term_id,
						'destination_id' => $link,
					)
				);
				return false;
			}
			if ( $this->reverse_guarded( 'term', 'upcp_to_woo', $source->term_id, $link ) ) {
				return false;
			}

			$args = array( 'description' => $source->description );
			if ( $hierarchical ) {
				$parent = $this->linked_woo_parent( $source );
				if ( ! $parent['success'] ) {
					return false;
				}
				$args['parent'] = $parent['id'];
			}
			$identity_lock = false;
			if ( ! $destination ) {
				$identity_lock = $this->acquire_identity_lock( 'upcp-term', $source->term_id );
				if ( ! $identity_lock ) {
					$this->log( 'identity_sync_in_progress', array( 'source_id' => $source->term_id ) );
					return false;
				}
				$claimed_link = absint( get_term_meta( $source->term_id, 'woocommerce_id', true ) );
				if ( $claimed_link ) {
					$this->release_identity_lock( $identity_lock );
					return false;
				}
			}
			$key                           = $this->guard_key( 'term', 'upcp_to_woo', $source->term_id, $link );
			$this->sync_guards[ $key ]     = true;
			$this->active_entities['term'] = true;
			try {
				$result = $destination
				? wp_update_term( $destination->term_id, $destination_taxonomy, array_merge( $args, array( 'name' => $source->name ) ) )
				: wp_insert_term( $source->name, $destination_taxonomy, $args );
				if ( is_wp_error( $result ) ) {
					$this->log( 'term_crud_error', array( 'source_id' => $source->term_id ) );
					return false;
				}
				$destination_id = $destination ? $destination->term_id : absint( $result['term_id'] );
				if ( ! $link ) {
					update_term_meta( $source->term_id, 'woocommerce_id', $destination_id );
				}
				return true;
			} finally {
				unset( $this->sync_guards[ $key ] );
				unset( $this->active_entities['term'] );
				$this->release_identity_lock( $identity_lock );
			}
		}

		public function import_product_into_upcp( $wc_product_id ) {
			global $ewd_upcp_controller;
			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return false;
			}
			if ( ! empty( $this->active_entities['product'] ) ) {
				return false;
			}
			$wc_product = wc_get_product( absint( $wc_product_id ) );
			if ( ! $wc_product ) {
				$this->log( 'stale_linked_product', array( 'source_id' => absint( $wc_product_id ) ) );
				return false;
			}

			$linked = $this->find_linked_upcp_products( $wc_product->get_id() );
			if ( count( $linked ) > 1 ) {
				$this->log( 'ambiguous_product_link', array( 'source_id' => $wc_product->get_id() ) );
				return false;
			}
			if ( $linked && 'trash' === get_post_status( reset( $linked )->ID ) ) {
				$this->log(
					'trashed_linked_product',
					array(
						'source_id'      => $wc_product->get_id(),
						'destination_id' => reset( $linked )->ID,
					)
				);
				return false;
			}
			$upcp_id = $linked ? reset( $linked )->ID : 0;
			if ( $this->reverse_guarded( 'product', 'woo_to_upcp', $wc_product->get_id(), $upcp_id ) ) {
				return false;
			}
			$identity_lock = false;
			if ( ! $upcp_id ) {
				$identity_lock = $this->acquire_identity_lock( 'woo-product', $wc_product->get_id() );
				if ( ! $identity_lock ) {
					$this->log( 'identity_sync_in_progress', array( 'source_id' => $wc_product->get_id() ) );
					return false;
				}
				$linked = $this->find_linked_upcp_products( $wc_product->get_id() );
				if ( $linked ) {
					$this->release_identity_lock( $identity_lock );
					$this->log( 'identity_claimed_during_sync', array( 'source_id' => $wc_product->get_id() ) );
					return false;
				}
			}

			$key                              = $this->guard_key( 'product', 'woo_to_upcp', $wc_product->get_id(), $upcp_id );
			$this->sync_guards[ $key ]        = true;
			$this->active_entities['product'] = true;
			try {
				$term_projection = $this->prepare_product_terms_to_upcp( $wc_product, $upcp_id );
				if ( ! $term_projection['success'] ) {
					return false;
				}
				$args = array(
					'post_type'    => EWD_UPCP_PRODUCT_POST_TYPE,
					'post_title'   => $wc_product->get_name(),
					'post_content' => $wc_product->get_description(),
					'post_status'  => 'publish' === $wc_product->get_status() ? 'publish' : 'draft',
					'meta_input'   => array(
						'price'          => $wc_product->get_regular_price(),
						'sale_price'     => $wc_product->get_sale_price(),
						'sale_mode'      => '' !== $wc_product->get_sale_price(),
						'display'        => 'publish' === $wc_product->get_status(),
						'woocommerce_id' => $wc_product->get_id(),
					),
				);
				if ( $upcp_id ) {
					$args['ID'] = $upcp_id;
				}
				$upcp_id = wp_insert_post( $args, true );
				if ( is_wp_error( $upcp_id ) ) {
					$this->log( 'upcp_product_crud_error', array( 'source_id' => $wc_product->get_id() ) );
					return false;
				}
				set_post_thumbnail( $upcp_id, $wc_product->get_image_id() );
				$this->project_gallery_to_upcp( $wc_product, $upcp_id );
				if ( ! $this->apply_product_terms_to_upcp( $upcp_id, $term_projection ) ) {
					return false;
				}
				$this->project_attributes_to_upcp( $wc_product, $upcp_id );
				return true;
			} finally {
				unset( $this->sync_guards[ $key ] );
				unset( $this->active_entities['product'] );
				$this->release_identity_lock( $identity_lock );
			}
		}

		public function import_product_from_upcp( $upcp_id ) {
			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return false;
			}
			if ( ! empty( $this->active_entities['product'] ) ) {
				return false;
			}
			if ( 'trash' === get_post_status( $upcp_id ) ) {
				return false;
			}
			$upcp = new ewdupcpProduct();
			if ( ! $upcp->load_post( absint( $upcp_id ) ) ) {
				return false;
			}

			$link = absint( $upcp->woocommerce_id );
			$woo  = $link ? wc_get_product( $link ) : false;
			if ( $link ) {
				$owners = $this->find_linked_upcp_products( $link );
				if ( 1 !== count( $owners ) || absint( reset( $owners )->ID ) !== absint( $upcp->id ) ) {
					$this->log(
						'ambiguous_product_link',
						array(
							'source_id'      => $upcp->id,
							'destination_id' => $link,
						)
					);
					return false;
				}
			}
			if ( $link && ! $woo ) {
				$this->log(
					'stale_linked_product',
					array(
						'source_id'      => $upcp->id,
						'destination_id' => $link,
					)
				);
				return false;
			}
			if ( $woo && ! $woo->is_type( 'simple' ) ) {
				$this->log(
					'unsupported_product_type',
					array(
						'source_id'      => $upcp->id,
						'destination_id' => $link,
					)
				);
				return false;
			}
			if ( $this->reverse_guarded( 'product', 'upcp_to_woo', $upcp->id, $link ) ) {
				return false;
			}
			$identity_lock = false;
			if ( ! $link ) {
				$identity_lock = $this->acquire_identity_lock( 'upcp-product', $upcp->id );
				if ( ! $identity_lock ) {
					$this->log( 'identity_sync_in_progress', array( 'source_id' => $upcp->id ) );
					return false;
				}
				if ( absint( get_post_meta( $upcp->id, 'woocommerce_id', true ) ) ) {
					$this->release_identity_lock( $identity_lock );
					$this->log( 'identity_claimed_during_sync', array( 'source_id' => $upcp->id ) );
					return false;
				}
			}

			$woo                              = $woo ? $woo : new WC_Product_Simple();
			$key                              = $this->guard_key( 'product', 'upcp_to_woo', $upcp->id, $link );
			$this->sync_guards[ $key ]        = true;
			$this->active_entities['product'] = true;
			try {
				$woo->set_name( $upcp->name );
				$woo->set_description( $upcp->description );
				$woo->set_status( $upcp->display ? 'publish' : 'draft' );
				$woo->set_image_id( get_post_thumbnail_id( $upcp->id ) );
				$woo->set_gallery_image_ids( $this->get_upcp_gallery_ids( $upcp ) );
				$categories = $this->get_merged_woo_term_ids( $woo->get_category_ids(), array_merge( $upcp->categories, $upcp->subcategories ), 'product_cat' );
				$tags       = $this->get_merged_woo_term_ids( $woo->get_tag_ids(), $upcp->tags, 'product_tag' );
				if ( ! $categories['success'] || ! $tags['success'] ) {
					return false;
				}
				$woo->set_category_ids( $categories['ids'] );
				$woo->set_tag_ids( $tags['ids'] );

				$regular = $this->normalize_price( $upcp->regular_price );
				if ( false === $regular ) {
					$this->log( 'unsupported_price_format', array( 'source_id' => $upcp->id ) );
				} else {
					$woo->set_regular_price( $regular );
				}
				$sale = $this->normalize_price( $upcp->sale_price, true );
				if ( false === $sale ) {
					$this->log( 'unsupported_sale_price_format', array( 'source_id' => $upcp->id ) );
				} else {
					$woo->set_sale_price( $sale );
				}

				$woo_id = $woo->save();
				if ( ! $woo_id ) {
					$this->log( 'woocommerce_product_crud_error', array( 'source_id' => $upcp->id ) );
					return false;
				}
				if ( ! $link ) {
					update_post_meta( $upcp->id, 'woocommerce_id', $woo_id );
				}
				return true;
			} catch ( Throwable $exception ) {
				$this->log( 'woocommerce_product_crud_error', array( 'source_id' => $upcp->id ) );
				return false;
			} finally {
				unset( $this->sync_guards[ $key ] );
				unset( $this->active_entities['product'] );
				$this->release_identity_lock( $identity_lock );
			}
		}

		private function project_gallery_to_upcp( $woo, $upcp_id ) {
			$images = array();
			foreach ( $woo->get_gallery_image_ids() as $attachment_id ) {
				$url = wp_get_attachment_url( $attachment_id );
				if ( $url ) {
					$images[] = (object) array(
						'url'         => $url,
						'description' => get_the_title( $attachment_id ),
					);
				}
			}
			update_post_meta( $upcp_id, 'product_images', $images );
		}

		private function prepare_product_terms_to_upcp( $woo, $upcp_id ) {
			$categories = $this->get_merged_upcp_term_ids( $upcp_id, $woo->get_category_ids(), EWD_UPCP_PRODUCT_CATEGORY_TAXONOMY, true );
			$tags       = $this->get_merged_upcp_term_ids( $upcp_id, $woo->get_tag_ids(), EWD_UPCP_PRODUCT_TAG_TAXONOMY, false );
			if ( ! $categories['success'] || ! $tags['success'] ) {
				return array( 'success' => false );
			}

			return array(
				'success'    => true,
				'categories' => $categories['ids'],
				'tags'       => $tags['ids'],
			);
		}

		private function apply_product_terms_to_upcp( $upcp_id, $projection ) {
			$category_result = wp_set_object_terms( $upcp_id, $projection['categories'], EWD_UPCP_PRODUCT_CATEGORY_TAXONOMY, false );
			$tag_result      = wp_set_object_terms( $upcp_id, $projection['tags'], EWD_UPCP_PRODUCT_TAG_TAXONOMY, false );

			return ! is_wp_error( $category_result ) && ! is_wp_error( $tag_result );
		}

		private function project_attributes_to_upcp( $woo, $upcp_id ) {
			global $ewd_upcp_controller;
			$fields = $ewd_upcp_controller->settings->get_custom_fields();
			foreach ( $woo->get_attributes() as $attribute ) {
				if ( ! is_object( $attribute ) || ! method_exists( $attribute, 'get_id' ) || ! $attribute->get_id() ) {
					continue;
				}
				$values = array();
				foreach ( $attribute->get_options() as $term_id ) {
					$term = get_term( $term_id, $attribute->get_name() );
					if ( $term && ! is_wp_error( $term ) ) {
						$values[] = $term->name;
					}
				}
				foreach ( $fields as $field ) {
					if ( absint( $field->woocommerce_id ) === absint( $attribute->get_id() ) ) {
						update_post_meta( $upcp_id, 'custom_field_' . absint( $field->id ), implode( ',', $values ) );
					}
				}
			}
		}

		private function get_upcp_gallery_ids( $upcp ) {
			$ids = array();
			foreach ( $upcp->get_all_images() as $image ) {
				if ( empty( $image->url ) ) {
					continue;
				}
				$id = attachment_url_to_postid( $image->url );
				if ( $id && get_post_thumbnail_id( $upcp->id ) !== $id ) {
					$ids[] = absint( $id );
				}
			}
			return array_values( array_unique( $ids ) );
		}

		private function normalize_price( $value, $allow_empty = false ) {
			$value = trim( (string) $value );
			if ( '' === $value && $allow_empty ) {
				return '';
			}
			if ( ! preg_match( '/^\d+(?:[\.,]\d+)?$/', $value ) ) {
				return false;
			}
			return wc_format_decimal( str_replace( ',', '.', $value ), false );
		}

		private function find_linked_term( $woo_id, $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'number'     => 2,
					'meta_key'   => 'woocommerce_id',
					'meta_value' => absint( $woo_id ),
				)
			);
			if ( is_wp_error( $terms ) ) {
				$this->log( 'term_link_query_error', array( 'source_id' => absint( $woo_id ) ) );
				return array(
					'status' => 'error',
					'term'   => false,
				);
			}
			if ( count( $terms ) > 1 ) {
				$this->log( 'ambiguous_term_link', array( 'source_id' => absint( $woo_id ) ) );
				return array(
					'status' => 'ambiguous',
					'term'   => false,
				);
			}
			if ( ! $terms ) {
				return array(
					'status' => 'missing',
					'term'   => false,
				);
			}

			return array(
				'status' => 'unique',
				'term'   => reset( $terms ),
			);
		}

		private function linked_upcp_parent( $woo_term ) {
			if ( empty( $woo_term->parent ) ) {
				return array(
					'success' => true,
					'id'      => 0,
				);
			}
			$parent = $this->find_linked_term( $woo_term->parent, EWD_UPCP_PRODUCT_CATEGORY_TAXONOMY );
			if ( 'missing' === $parent['status'] && $this->sync_term_into_upcp( $woo_term->parent, 'product_cat', EWD_UPCP_PRODUCT_CATEGORY_TAXONOMY, true ) ) {
				$parent = $this->find_linked_term( $woo_term->parent, EWD_UPCP_PRODUCT_CATEGORY_TAXONOMY );
			}
			if ( 'unique' !== $parent['status'] ) {
				$this->log(
					'parent_link_unresolved',
					array(
						'source_id'      => $woo_term->term_id,
						'destination_id' => $woo_term->parent,
					)
				);
				return array(
					'success' => false,
					'id'      => 0,
				);
			}

			return array(
				'success' => true,
				'id'      => absint( $parent['term']->term_id ),
			);
		}

		private function linked_woo_parent( $upcp_term ) {
			if ( empty( $upcp_term->parent ) ) {
				return array(
					'success' => true,
					'id'      => 0,
				);
			}
			$linked = absint( get_term_meta( $upcp_term->parent, 'woocommerce_id', true ) );
			if ( ! $linked && $this->sync_term_from_upcp( $upcp_term->parent, EWD_UPCP_PRODUCT_CATEGORY_TAXONOMY, 'product_cat', true ) ) {
				$linked = absint( get_term_meta( $upcp_term->parent, 'woocommerce_id', true ) );
			}
			$ownership = $linked ? $this->find_linked_term( $linked, EWD_UPCP_PRODUCT_CATEGORY_TAXONOMY ) : array( 'status' => 'missing' );
			$term      = $linked ? get_term( $linked, 'product_cat' ) : false;
			if (
				'unique' !== $ownership['status']
				|| absint( $ownership['term']->term_id ) !== absint( $upcp_term->parent )
				|| ! $term
				|| is_wp_error( $term )
			) {
				$this->log(
					'parent_link_unresolved',
					array(
						'source_id'      => $upcp_term->term_id,
						'destination_id' => $linked,
					)
				);
				return array(
					'success' => false,
					'id'      => 0,
				);
			}

			return array(
				'success' => true,
				'id'      => absint( $term->term_id ),
			);
		}

		private function get_merged_upcp_term_ids( $upcp_id, $woo_ids, $taxonomy, $hierarchical ) {
			$incoming = array();
			foreach ( $woo_ids as $woo_id ) {
				$lookup = $this->find_linked_term( $woo_id, $taxonomy );
				if ( 'missing' === $lookup['status'] ) {
					$source_taxonomy = $hierarchical ? 'product_cat' : 'product_tag';
					if ( ! $this->sync_term_into_upcp( $woo_id, $source_taxonomy, $taxonomy, $hierarchical ) ) {
						return array(
							'success' => false,
							'ids'     => array(),
						);
					}
					$lookup = $this->find_linked_term( $woo_id, $taxonomy );
				}
				if ( 'unique' !== $lookup['status'] ) {
					return array(
						'success' => false,
						'ids'     => array(),
					);
				}
				$incoming[] = absint( $lookup['term']->term_id );
			}
			$current = wp_get_object_terms( $upcp_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $current ) ) {
				$this->log( 'destination_term_read_error', array( 'source_id' => absint( $upcp_id ) ) );
				return array(
					'success' => false,
					'ids'     => array(),
				);
			}
			$current   = array_map( 'absint', $current );
			$preserved = array_filter(
				$current,
				static function ( $term_id ) {
					return ! absint( get_term_meta( $term_id, 'woocommerce_id', true ) );
				}
			);

			return array(
				'success' => true,
				'ids'     => array_values( array_unique( array_merge( $preserved, $incoming ) ) ),
			);
		}

		private function get_merged_woo_term_ids( $current_ids, $upcp_terms, $taxonomy ) {
			$incoming = array();
			foreach ( $upcp_terms as $term ) {
				$link     = absint( get_term_meta( $term->term_id, 'woocommerce_id', true ) );
				$woo_term = $link ? get_term( $link, $taxonomy ) : false;
				if ( $woo_term && ! is_wp_error( $woo_term ) ) {
					$ownership = $this->find_linked_term( $link, $term->taxonomy );
					if ( 'unique' !== $ownership['status'] || absint( $ownership['term']->term_id ) !== absint( $term->term_id ) ) {
						return array(
							'success' => false,
							'ids'     => array(),
						);
					}
					$incoming[] = $link;
				} elseif ( $link ) {
					$this->log(
						'stale_linked_term',
						array(
							'source_id'      => $term->term_id,
							'destination_id' => $link,
						)
					);
					return array(
						'success' => false,
						'ids'     => array(),
					);
				}
			}
			$preserved = array();
			foreach ( $current_ids as $current_id ) {
				$lookup = $this->find_linked_term( $current_id, EWD_UPCP_PRODUCT_CATEGORY_TAXONOMY );
				if ( 'product_tag' === $taxonomy ) {
					$lookup = $this->find_linked_term( $current_id, EWD_UPCP_PRODUCT_TAG_TAXONOMY );
				}
				if ( in_array( $lookup['status'], array( 'ambiguous', 'error' ), true ) ) {
					return array(
						'success' => false,
						'ids'     => array(),
					);
				}
				if ( 'missing' === $lookup['status'] ) {
					$preserved[] = absint( $current_id );
				}
			}

			return array(
				'success' => true,
				'ids'     => array_values( array_unique( array_merge( $preserved, $incoming ) ) ),
			);
		}

		private function find_linked_upcp_products( $woo_id ) {
			return get_posts(
				array(
					'post_type'      => EWD_UPCP_PRODUCT_POST_TYPE,
					'post_status'    => array_keys( get_post_stati() ),
					'posts_per_page' => 2,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'meta_key'       => 'woocommerce_id',
					'meta_value'     => absint( $woo_id ),
				)
			);
		}

		private function guard_key( $entity, $direction, $source_id, $destination_id ) {
			return implode( ':', array( $entity, $direction, absint( $source_id ), absint( $destination_id ) ) );
		}
		private function reverse_guarded( $entity, $direction, $source_id, $destination_id ) {
			$reverse = 'woo_to_upcp' === $direction ? 'upcp_to_woo' : 'woo_to_upcp';
			return ! empty( $this->sync_guards[ $this->guard_key( $entity, $reverse, $destination_id, $source_id ) ] );
		}
		/** The old authenticated endpoint now returns progress; clients cannot supply a cursor. */
		public function run_sync_batch() {
			global $ewd_upcp_controller;
			if ( ! check_ajax_referer( 'ewd-upcp-admin-js', 'nonce', false ) ) {
				wp_send_json_error( array( 'code' => 'invalid_nonce' ), 403 );
			}
			$capability = $ewd_upcp_controller->settings->get_setting( 'access-role' );
			if ( ! current_user_can( 'manage_options' ) && ! current_user_can( $capability ) ) {
				wp_send_json_error( array( 'code' => 'forbidden' ), 403 );
			}
			if ( ! $ewd_upcp_controller->permissions->check_permission( 'woocommerce' ) ) {
				wp_send_json_error( array( 'code' => 'entitlement_required' ), 403 );
			}
			$this->ensure_automatic_sync();
			wp_send_json_success( array( 'message' => $this->get_sync_status_message() ) );
		}

		private function sync_enabled() {
			$settings = get_option( 'ewd-upcp-settings', array() );
			return is_array( $settings ) && ! empty( $settings['woocommerce-sync'] );
		}

		private function can_sync() {
			global $ewd_upcp_controller;
			return $this->sync_enabled() && $this->woocommerce_available() && $ewd_upcp_controller->permissions->check_permission( 'woocommerce' ) && $ewd_upcp_controller->helper_compatibility->is_compatible();
		}

		private function sync_phases() {
			return array( 'woo_categories', 'upcp_categories', 'woo_tags', 'upcp_tags', 'woo_attributes', 'woo_products', 'upcp_products' );
		}

		/** Called only under REQUEST_LOCK. Preserve ambiguous enabled legacy integrations. */
		private function initialize_sync_state( $was_enabled ) {
			wp_cache_delete( self::SUMMARY_OPTION, 'options' );
			wp_cache_delete( self::RUN_OPTION, 'options' );
			$summary = get_option( self::SUMMARY_OPTION, array() );
			$run     = get_option( self::RUN_OPTION, array() );
			$summary = is_array( $summary ) ? $summary : array();
			if ( ! empty( $summary['state'] ) || ! empty( $summary['finished_at'] ) || ! empty( $run ) || ! empty( $summary['started_at'] ) || ! empty( $summary['last_phase'] ) ) {
				return;
			}
			$summary['state'] = $was_enabled ? 'legacy_preserved' : 'not_started';
			update_option( self::SUMMARY_OPTION, $summary, false );
		}

		private function initial_sync_complete( $summary ) {
			return ! empty( $summary['finished_at'] ) || ( isset( $summary['state'] ) && 'legacy_preserved' === $summary['state'] );
		}

		/** Native WP-Cron, one bounded batch per event; no browser must remain open. */
		public function ensure_automatic_sync() {
			$lock = $this->acquire_request_lock( 'scheduler' );
			if ( ! $lock ) {
				return;
			}
			try {
				$this->initialize_sync_state( $this->sync_enabled() );
				$summary = get_option( self::SUMMARY_OPTION, array() );
				if ( ! $this->can_sync() || $this->initial_sync_complete( $summary ) ) {
					wp_clear_scheduled_hook( self::CRON_HOOK );
					return;
				}
				$run = get_option( self::RUN_OPTION, array() );
				$this->schedule_sync( max( time() + 1, isset( $run['next_at'] ) ? absint( $run['next_at'] ) : 0 ) );
			} finally {
				$this->release_request_lock( $lock );
			}
		}

		/** Scheduling and rescheduling are serialized by REQUEST_LOCK. */
		private function schedule_sync( $when ) {
			$scheduled = wp_next_scheduled( self::CRON_HOOK );
			if ( $scheduled && $scheduled <= $when ) {
				return;
			}
			if ( $scheduled ) {
				wp_clear_scheduled_hook( self::CRON_HOOK );
			}
			wp_schedule_single_event( $when, self::CRON_HOOK );
		}

		public function run_automatic_sync() {
			if ( ! $this->can_sync() ) {
				return;
			}
			$request_lock = $this->acquire_request_lock( 'automatic' );
			if ( ! $request_lock ) {
				return;
			}
			try {
				$this->initialize_sync_state( true );
				$summary = get_option( self::SUMMARY_OPTION, array() );
				if ( $this->initial_sync_complete( $summary ) ) {
					wp_clear_scheduled_hook( self::CRON_HOOK );
					return;
				}
				$phases = $this->sync_phases();
				$run    = get_option( self::RUN_OPTION, array() );
				if ( ! is_array( $run ) || empty( $run['run_id'] ) ) {
					// If an older failed run expired, replay its last safe batch, not the whole store.
					$run = array(
						'run_id' => wp_generate_uuid4(),
						'phase'  => isset( $summary['last_phase'] ) && in_array( $summary['last_phase'], $phases, true ) ? $summary['last_phase'] : $phases[0],
						'page'   => max( 1, isset( $summary['last_page'] ) ? absint( $summary['last_page'] ) : 1 ),
					);
				}
				if ( ! isset( $run['phase'] ) || ! in_array( $run['phase'], $phases, true ) ) {
					$run['phase'] = $phases[0];
					$run['page']  = 1;
				}
				if ( ! empty( $run['next_at'] ) && $run['next_at'] > time() ) {
					$this->schedule_sync( $run['next_at'] );
					return;
				}
				$phase                  = $run['phase'];
				$page                   = max( 1, isset( $run['page'] ) ? absint( $run['page'] ) : 1 );
				$run['page']            = $page;
				$run['next_at']         = time() + self::RUN_TTL + 1;
				$summary['state']       = 'running';
				$summary['started_at']  = ! empty( $summary['started_at'] ) ? $summary['started_at'] : time();
				$summary['finished_at'] = 0;
				update_option( self::RUN_OPTION, $run, false );
				if ( get_option( self::RUN_OPTION ) !== $run ) {
					return;
				}
				update_option( self::SUMMARY_OPTION, $summary, false );
				// Persist a watchdog before work: even a fatal leaves a future retry and its cursor.
				wp_clear_scheduled_hook( self::CRON_HOOK );
				$this->schedule_sync( $run['next_at'] );
				try {
					$result = $this->process_batch( $phase, $page );
				} catch ( Throwable $exception ) {
					$this->log( 'batch_unhandled_error', array( 'phase' => $phase ) );
					$result = $this->batch_result( 0, 0, 0, false, true );
				}
				// A stale request may not commit over a newer lease owner.
				wp_cache_delete( self::REQUEST_LOCK, 'options' );
				if ( get_option( self::REQUEST_LOCK ) !== $request_lock ) {
					return;
				}
				$summary               = get_option( self::SUMMARY_OPTION, $summary );
				$summary['last_phase'] = $phase;
				$summary['last_page']  = $page;
				if ( $result['query_error'] || $result['failed'] ) {
					$run['attempts']    = min( 7, 1 + ( isset( $run['attempts'] ) ? absint( $run['attempts'] ) : 0 ) );
					$run['next_at']     = time() + min( HOUR_IN_SECONDS, 60 * pow( 2, $run['attempts'] - 1 ) );
					$summary['state']   = 'failed';
					$summary['failure'] = array(
						'phase' => $phase,
						'page'  => $page,
						'code'  => $result['query_error'] ? 'batch_query_failed' : 'batch_records_failed',
						'at'    => time(),
					);
					$summary['errors']  = ( isset( $summary['errors'] ) ? absint( $summary['errors'] ) : 0 ) + max( 1, $result['failed'] );
				} else {
					$run['attempts'] = 0;
					$run['next_at']  = time() + 1;
					unset( $summary['failure'] );
					$summary['processed']           = isset( $summary['processed'] ) && is_array( $summary['processed'] ) ? $summary['processed'] : array();
					$summary['processed'][ $phase ] = ( isset( $summary['processed'][ $phase ] ) ? absint( $summary['processed'][ $phase ] ) : 0 ) + $result['processed'];
					$index                          = array_search( $phase, $phases, true );
					if ( $result['end'] && count( $phases ) - 1 === $index ) {
						$summary['state']       = 'complete';
						$summary['finished_at'] = time();
					} else {
						$run['phase'] = $result['end'] ? $phases[ $index + 1 ] : $phase;
						$run['page']  = $result['end'] ? 1 : $page + 1;
					}
				}
				// Cursor first; a crash between writes can replay safely, never skip failed work.
				update_option( self::RUN_OPTION, $run, false );
				if ( get_option( self::RUN_OPTION ) !== $run ) {
					return;
				}
				update_option( self::SUMMARY_OPTION, $summary, false );
				wp_clear_scheduled_hook( self::CRON_HOOK );
				if ( $this->initial_sync_complete( $summary ) ) {
					$this->release_sync_run( $run['run_id'] );
				} elseif ( $this->can_sync() ) {
					$this->schedule_sync( $run['next_at'] );
				}
			} finally {
				$this->release_request_lock( $request_lock );
			}
		}

		/** Safe translated status only; no exception, SQL or internal phase identifiers. */
		public function get_sync_status_message() {
			if ( ! $this->sync_enabled() ) {
				return __( 'Synchronization is disabled. Existing products and progress are preserved.', 'ultimate-product-catalogue' );
			}
			if ( ! $this->can_sync() ) {
				return __( 'Synchronization is paused. Activate WooCommerce and a supported, entitled Premium Helper to resume automatically.', 'ultimate-product-catalogue' );
			}
			$summary = get_option( self::SUMMARY_OPTION, array() );
			if ( isset( $summary['state'] ) && 'legacy_preserved' === $summary['state'] ) {
				return __( 'Existing integration preserved. Ongoing synchronization remains active; no full initial resynchronization is scheduled.', 'ultimate-product-catalogue' );
			}
			if ( ! empty( $summary['finished_at'] ) ) {
				return __( 'Initial synchronization is complete. Ongoing synchronization remains active.', 'ultimate-product-catalogue' );
			}
			$run    = get_option( self::RUN_OPTION, array() );
			$labels = array(
				'woo_categories'  => __( 'WooCommerce categories', 'ultimate-product-catalogue' ),
				'upcp_categories' => __( 'catalog categories', 'ultimate-product-catalogue' ),
				'woo_tags'        => __( 'WooCommerce tags', 'ultimate-product-catalogue' ),
				'upcp_tags'       => __( 'catalog tags', 'ultimate-product-catalogue' ),
				'woo_attributes'  => __( 'WooCommerce attributes', 'ultimate-product-catalogue' ),
				'woo_products'    => __( 'WooCommerce products', 'ultimate-product-catalogue' ),
				'upcp_products'   => __( 'catalog products', 'ultimate-product-catalogue' ),
			);
			$label  = isset( $run['phase'], $labels[ $run['phase'] ] ) ? $labels[ $run['phase'] ] : $labels['woo_categories'];
			$page   = isset( $run['page'] ) ? absint( $run['page'] ) : 1;
			if ( ! empty( $summary['failure'] ) ) {
				/* translators: 1: synchronization stage, 2: batch number. */
				return sprintf( __( 'Synchronization did not finish at %1$s, batch %2$d. It will retry automatically, preserving completed batches. If this persists, check conflicting or missing product/term links and WooCommerce synchronization logs.', 'ultimate-product-catalogue' ), $label, $page );
			}
			/* translators: 1: synchronization stage, 2: batch number. */
			return sprintf( __( 'Automatic synchronization is pending or running: %1$s, batch %2$d. You may leave this page. Progress resumes automatically through WordPress scheduled tasks.', 'ultimate-product-catalogue' ), $label, $page );
		}

		private function process_batch( $phase, $page ) {
			global $wpdb;
			$offset = ( $page - 1 ) * self::BATCH_SIZE;
			if ( in_array( $phase, array( 'woo_categories', 'upcp_categories', 'woo_tags', 'upcp_tags' ), true ) ) {
				$map   = array(
					'woo_categories'  => array( 'product_cat', 'import_category_into_upcp' ),
					'upcp_categories' => array( EWD_UPCP_PRODUCT_CATEGORY_TAXONOMY, 'import_category_from_upcp' ),
					'woo_tags'        => array( 'product_tag', 'import_tag_into_upcp' ),
					'upcp_tags'       => array( EWD_UPCP_PRODUCT_TAG_TAXONOMY, 'import_tag_from_upcp' ),
				);
				$terms = get_terms(
					array(
						'taxonomy'   => $map[ $phase ][0],
						'hide_empty' => false,
						'number'     => self::BATCH_SIZE,
						'offset'     => $offset,
						'orderby'    => 'term_id',
						'order'      => 'ASC',
					)
				);
				if ( is_wp_error( $terms ) ) {
					$this->log( 'batch_term_query_error', array( 'phase' => $phase ) );
					return $this->batch_result( 0, 0, 0, false, true );
				}
				$processed = 0;
				$failed    = 0;
				foreach ( $terms as $term ) {
					if ( call_user_func( array( $this, $map[ $phase ][1] ), $term->term_id ) ) {
						++$processed;
					} else {
						++$failed;
					}
				}
				return $this->batch_result( count( $terms ), $processed, $failed, count( $terms ) < self::BATCH_SIZE );
			}
			if ( 'woo_attributes' === $phase ) {
				$attributes = $this->get_woo_attribute_batch( $offset );
				if ( is_wp_error( $attributes ) ) {
					$this->log( 'batch_attribute_query_error', array( 'phase' => $phase ) );
					return $this->batch_result( 0, 0, 0, false, true );
				}
				$processed = 0;
				$failed    = 0;
				foreach ( $attributes as $attribute ) {
					if ( $this->import_attribute_into_upcp( $attribute ) ) {
						++$processed;
					} else {
						++$failed;
					}
				}
				return $this->batch_result( count( $attributes ), $processed, $failed, count( $attributes ) < self::BATCH_SIZE );
			}
			if ( 'woo_products' === $phase ) {
				try {
					$wpdb->last_error = '';
					$ids              = wc_get_products(
						array(
							'limit'   => self::BATCH_SIZE,
							'page'    => $page,
							'return'  => 'ids',
							'status'  => array_values( array_diff( array_keys( get_post_stati() ), array( 'trash', 'auto-draft', 'inherit' ) ) ),
							'orderby' => 'ID',
							'order'   => 'ASC',
						)
					);
					if ( '' !== $wpdb->last_error || ! is_array( $ids ) ) {
						throw new RuntimeException( 'product_query_failed' );
					}
				} catch ( Throwable $exception ) {
					$this->log( 'batch_product_query_error', array( 'phase' => $phase ) );
					return $this->batch_result( 0, 0, 0, false, true );
				}
				$processed = 0;
				$failed    = 0;
				foreach ( $ids as $id ) {
					if ( $this->import_product_into_upcp( $id ) ) {
						++$processed;
					} else {
						++$failed;
					}
				}
				return $this->batch_result( count( $ids ), $processed, $failed, count( $ids ) < self::BATCH_SIZE );
			}
			$ids = $this->get_upcp_product_batch( $page );
			if ( is_wp_error( $ids ) ) {
				$this->log( 'batch_product_query_error', array( 'phase' => $phase ) );
				return $this->batch_result( 0, 0, 0, false, true );
			}
			$processed = 0;
			$failed    = 0;
			foreach ( $ids as $id ) {
				$link = absint( get_post_meta( $id, 'woocommerce_id', true ) );
				$woo  = $link ? wc_get_product( $link ) : false;
				if ( $woo && ! $woo->is_type( 'simple' ) ) {
					// Complex products are Woo-owned projections, not failed reverse updates.
					$this->log( 'unsupported_product_type', array( 'source_id' => $id ) );
					continue;
				}
				if ( $this->import_product_from_upcp( $id ) ) {
					++$processed;
				} else {
					++$failed;
				}
			}
			return $this->batch_result( count( $ids ), $processed, $failed, count( $ids ) < self::BATCH_SIZE );
		}

		/** Read one Woo attribute page while preserving database query failures. */
		private function get_woo_attribute_batch( $offset ) {
			global $wpdb;
			$table            = $wpdb->prefix . 'woocommerce_attribute_taxonomies';
			$wpdb->last_error = '';
			$attributes       = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted Woo table identifier; WordPress 6.0 has no %i placeholder.
					"SELECT * FROM {$table} ORDER BY attribute_id ASC LIMIT %d OFFSET %d",
					self::BATCH_SIZE,
					absint( $offset )
				)
			);
			return '' !== $wpdb->last_error ? new WP_Error( 'attribute_query_failed' ) : $attributes;
		}

		/** Read one UPCP product page while preserving database query failures. */
		private function get_upcp_product_batch( $page ) {
			global $wpdb;
			$wpdb->last_error = '';
			$query            = new WP_Query(
				array(
					'post_type'      => EWD_UPCP_PRODUCT_POST_TYPE,
					'post_status'    => array_values( array_diff( array_keys( get_post_stati() ), array( 'trash', 'auto-draft', 'inherit' ) ) ),
					'posts_per_page' => self::BATCH_SIZE,
					'paged'          => max( 1, absint( $page ) ),
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'no_found_rows'  => true,
				)
			);
			return '' !== $wpdb->last_error ? new WP_Error( 'upcp_product_query_failed' ) : $query->posts;
		}

		private function batch_result( $selected, $processed, $failed, $end, $query_error = false ) {
			return array(
				'selected'    => absint( $selected ),
				'processed'   => absint( $processed ),
				'failed'      => absint( $failed ),
				'end'         => (bool) $end,
				'query_error' => (bool) $query_error,
			);
		}

		private function prepare_sync_run( $requested_run_id, $phase, $page ) {
			$current = get_option( self::RUN_OPTION, array() );
			$current = is_array( $current ) ? $current : array();

			if ( ! $requested_run_id ) {
				if ( $current && ( empty( $current['expires'] ) || absint( $current['expires'] ) >= time() ) ) {
					return new WP_Error( 'sync_locked' );
				}
				if ( 'woo_categories' !== $phase || 1 !== $page ) {
					return new WP_Error( 'run_id_required' );
				}
				$run      = array(
					'run_id'  => wp_generate_uuid4(),
					'owner'   => get_current_user_id(),
					'phase'   => $phase,
					'page'    => $page,
					'expires' => time() + self::RUN_TTL,
				);
				$acquired = $current
					? $this->replace_option_if_matches( self::RUN_OPTION, $current, $run )
					: add_option( self::RUN_OPTION, $run, '', false );
				if ( ! $acquired ) {
					return new WP_Error( 'sync_locked' );
				}
				$run['new'] = true;
				return $run;
			}

			if (
				! $current
				|| ( ! empty( $current['expires'] ) && absint( $current['expires'] ) < time() )
				|| ! hash_equals( (string) $current['run_id'], (string) $requested_run_id )
				|| absint( $current['owner'] ) !== get_current_user_id()
			) {
				return new WP_Error( 'invalid_sync_run' );
			}
			if ( $current['phase'] !== $phase || absint( $current['page'] ) !== $page ) {
				return new WP_Error( 'stale_sync_cursor' );
			}
			$current['new'] = false;
			return $current;
		}

		private function acquire_request_lock( $run_id ) {
			$current  = get_option( self::REQUEST_LOCK, array() );
			$current  = is_array( $current ) ? $current : array();
			$lock     = array(
				'run_id'  => $run_id,
				'token'   => wp_generate_uuid4(),
				'expires' => time() + self::RUN_TTL,
			);
			$acquired = $current && ! empty( $current['expires'] ) && absint( $current['expires'] ) < time()
				? $this->replace_option_if_matches( self::REQUEST_LOCK, $current, $lock )
				: ( ! $current && add_option( self::REQUEST_LOCK, $lock, '', false ) );

			return $acquired ? $lock : false;
		}

		private function release_request_lock( $lock ) {
			if ( is_array( $lock ) && ! empty( $lock['token'] ) ) {
				$this->delete_option_if_matches( self::REQUEST_LOCK, $lock );
			}
		}

		/** Reserve creation of one cross-system identity across PHP requests. */
		private function acquire_identity_lock( $entity, $source_id ) {
			$name    = self::IDENTITY_LOCK . sanitize_key( $entity ) . '-' . absint( $source_id );
			$current = get_option( $name, array() );
			$current = is_array( $current ) ? $current : array();

			$lock     = array(
				'name'    => $name,
				'token'   => wp_generate_uuid4(),
				'expires' => time() + self::RUN_TTL,
			);
			$acquired = $current && ! empty( $current['expires'] ) && absint( $current['expires'] ) < time()
				? $this->replace_option_if_matches( $name, $current, $lock )
				: ( ! $current && add_option( $name, $lock, '', false ) );
			if ( ! $acquired ) {
				return false;
			}

			return $lock;
		}

		private function release_identity_lock( $lock ) {
			if ( ! is_array( $lock ) || empty( $lock['name'] ) || empty( $lock['token'] ) ) {
				return;
			}

			$this->delete_option_if_matches( $lock['name'], $lock );
		}

		private function advance_sync_run( $run_id, $phase, $page ) {
			$run = get_option( self::RUN_OPTION, array() );
			if ( is_array( $run ) && ! empty( $run['run_id'] ) && hash_equals( (string) $run['run_id'], (string) $run_id ) ) {
				$next            = $run;
				$next['phase']   = $phase;
				$next['page']    = $page;
				$next['expires'] = time() + self::RUN_TTL;
				$this->replace_option_if_matches( self::RUN_OPTION, $run, $next );
			}
		}

		private function release_sync_run( $run_id ) {
			$run = get_option( self::RUN_OPTION, array() );
			if ( is_array( $run ) && ! empty( $run['run_id'] ) && hash_equals( (string) $run['run_id'], (string) $run_id ) ) {
				$this->delete_option_if_matches( self::RUN_OPTION, $run );
			}
		}

		/** Atomically replace an option only while it still contains the observed value. */
		private function replace_option_if_matches( $name, $expected, $replacement ) {
			global $wpdb;
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					maybe_serialize( $replacement ),
					$name,
					maybe_serialize( $expected )
				)
			);
			if ( 1 === $updated ) {
				wp_cache_delete( $name, 'options' );
				return true;
			}
			return false;
		}

		/** Atomically delete an option only while the caller still owns its exact token/value. */
		private function delete_option_if_matches( $name, $expected ) {
			global $wpdb;
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
					$name,
					maybe_serialize( $expected )
				)
			);
			if ( 1 === $deleted ) {
				wp_cache_delete( $name, 'options' );
				return true;
			}
			return false;
		}

		private function woocommerce_available() {
			return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
		}

		private function log( $code, $context = array() ) {
			$safe = array_intersect_key( $context, array_flip( array( 'phase', 'source_id', 'destination_id' ) ) );
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->warning( sanitize_key( $code ) . ( $safe ? ' ' . wp_json_encode( $safe ) : '' ), array( 'source' => 'ewd-upcp-sync' ) );
			}
			$summary             = get_option( self::SUMMARY_OPTION, array() );
			$summary             = is_array( $summary ) ? $summary : array();
			$summary['warnings'] = absint( isset( $summary['warnings'] ) ? $summary['warnings'] : 0 ) + 1;
			update_option( self::SUMMARY_OPTION, $summary, false );
		}
	}
}
