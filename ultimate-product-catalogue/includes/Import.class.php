<?php

/**
 * Class to handle importing products into the plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ewdupcpImport {

	public $status;
	public $message;

	public function __construct() {
		add_action( 'admin_footer-edit.php', array( $this, 'print_button_and_modal' ) );
		add_action( 'admin_post_ewd_upcp_import', array( $this, 'handle_import' ) );
		add_action( 'admin_notices', array( $this, 'display_saved_notice' ) );

		if ( isset( $_POST['ewdupcpImport'] ) && ( ! isset( $_GET['action'] ) || 'ewd_upcp_import' !== $_GET['action'] ) ) {
			add_action( 'admin_init', array( $this, 'import_products' ) ); }
	}

	/**
	 * Adds 'Export' button to products page
	 *
	 * @since 5.3.0
	 */
	public function print_button_and_modal() {
		global $post_type;
		global $ewd_upcp_controller;

		if ( ! isset( $post_type ) or $post_type != EWD_UPCP_PRODUCT_POST_TYPE ) {
			return; }

		if ( ! $ewd_upcp_controller->permissions->check_permission( 'import', true ) ) {
			return; }

		?>

		<button type="button" id='ewd-upcp-import-open' class='ewd-upcp-hidden button button-secondary'>
			<?php _e( 'Import', 'ultimate-product-catalogue' ); ?>
		</button>

		<div id='ewd-upcp-import-modal' class='ewd-upcp-hidden'>

			<div id='ewd-upcp-import-modal-inside'>

				<div class='ewd-upcp-import-modal-close'>
					<div class='ewd-upcp-import-modal-close-inside'><span class="dashicons dashicons-no-alt"></span></div>
				</div>

				<h2><?php _e( 'Import Products', 'ultimate-product-catalogue' ); ?></h2>

				<form method='post' action="<?php echo esc_url( admin_url( 'admin-post.php?action=ewd_upcp_import' ) ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="ewdupcpImport" value="1">
					
					<?php wp_nonce_field( 'EWD_UPCP_Import', 'EWD_UPCP_Import_Nonce' ); ?>
		
					<p>
						<label for="ewd_upcp_products_spreadsheet"><?php _e( 'Spreadsheet Containing Products', 'ultimate-product-catalogue' ); ?></label><br />
						<input name="ewd_upcp_products_spreadsheet" type="file" value=""/>
					</p>
		
					<input type='submit' name='ewdupcpImport' value='Import Products' class='button button-primary' />
		
				</form>

			</div>

		</div>
		
		<?php
	}

	public function display_import_screen() {
		global $ewd_upcp_controller;

		$import_permission = $ewd_upcp_controller->permissions->check_permission( 'import', true );
		?>
		<div class='wrap'>
			<h2>Import</h2>
			<?php if ( $import_permission ) { ?> 
				<form method='post' action="<?php echo esc_url( admin_url( 'admin-post.php?action=ewd_upcp_import' ) ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="ewdupcpImport" value="1">
					
					<?php wp_nonce_field( 'EWD_UPCP_Import', 'EWD_UPCP_Import_Nonce' ); ?>

					<p>
						<label for="ewd_upcp_products_spreadsheet"><?php _e( 'Spreadsheet Containing Products', 'ultimate-product-catalogue' ); ?></label><br />
						<input name="ewd_upcp_products_spreadsheet" type="file" value=""/>
					</p>
					<input type='submit' name='ewdupcpImport' value='Import Products' class='button button-primary' />
				</form>
			<?php } else { ?>
				<div class='ewd-upcp-premium-locked'>
					<a href="https://www.etoilewebdesign.com/license-payment/?Selected=UPCP&Quantity=1&utm_source=upcp_import" target="_blank">Upgrade</a> to the premium version to use this feature
				</div>
			<?php } ?>
		</div>
		<?php
	}

	/** Dedicated POST/redirect/result path, independent of core bulk-list actions. */
	public function handle_import() {
		$this->import_products();
		$token = wp_generate_uuid4();
		set_transient(
			'ewd_upcp_import_' . get_current_user_id() . '_' . $token,
			array(
				'status'  => (bool) $this->status,
				'message' => (string) $this->message,
			),
			5 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'              => EWD_UPCP_PRODUCT_POST_TYPE,
					'ewd_upcp_import_result' => $token,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/** A one-use result belongs only to the administrator who submitted it. */
	public function display_saved_notice() {
		if ( ! current_user_can( 'edit_upcp_products' ) || ! isset( $_GET['ewd_upcp_import_result'] ) || ! is_string( $_GET['ewd_upcp_import_result'] ) ) {
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_GET['ewd_upcp_import_result'] ) );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/D', $token ) ) {
			return;
		}
		$key    = 'ewd_upcp_import_' . get_current_user_id() . '_' . $token;
		$result = get_transient( $key );
		if ( ! is_array( $result ) || ! isset( $result['status'], $result['message'] ) || ! is_string( $result['message'] ) ) {
			return;
		}
		delete_transient( $key );
		$this->status  = (bool) $result['status'];
		$this->message = $result['message'];
		$this->display_notice();
	}

	public function import_products() {
		global $ewd_upcp_controller;

		if ( ! current_user_can( 'edit_upcp_products' ) ) {
			$this->reject_request( __( 'You are not allowed to import products.', 'ultimate-product-catalogue' ) );
			return; }

		if ( ! $ewd_upcp_controller->permissions->check_permission( 'import', true ) ) {
			$this->reject_request( __( 'Premium import access is unavailable. Restore access and try again. No products were changed.', 'ultimate-product-catalogue' ) );
			return; }

		if ( ! isset( $_POST['EWD_UPCP_Import_Nonce'] ) || ! is_string( $_POST['EWD_UPCP_Import_Nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['EWD_UPCP_Import_Nonce'] ) ), 'EWD_UPCP_Import' ) ) {
			$this->reject_request( __( 'The import request expired or could not be verified. Reload this page and try again. No products were changed.', 'ultimate-product-catalogue' ) );
			return; }

		if ( ! ewdupcpSpreadsheetRuntime::is_trusted() ) {
			$this->reject_request( __( 'The spreadsheet runtime could not be loaded safely. Resolve the plugin class conflict before importing. No products were changed.', 'ultimate-product-catalogue' ) );
			return;
		}

		require_once EWD_UPCP_PLUGIN_DIR . '/includes/SpreadsheetImport.class.php';
		require_once EWD_UPCP_PLUGIN_DIR . '/includes/ImportReadFilter.class.php';
		$boundary = new ewdupcpSpreadsheetImport();
		$upload   = $boundary->prepare_upload();
		$update   = is_wp_error( $upload ) ? array(
			'message_type' => 'Error',
			'message'      => $upload->get_error_message(),
		) : array(
			'message_type' => 'Success',
			'path'         => $upload['path'],
		);

		$custom_fields = $ewd_upcp_controller->settings->get_custom_fields();

		if ( $update['message_type'] != 'Success' ) :
			$this->status  = false;
			$this->message = $update['message'];

			add_action( 'admin_notices', array( $this, 'display_notice' ) );

			return;
		endif;

		$excel_path = $update['path'];
		register_shutdown_function(
			static function () use ( $excel_path ) {
				if ( is_file( $excel_path ) ) {
					wp_delete_file( $excel_path );
				} }
		);
		$counts          = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'failed'  => 0,
		);
		$row_errors      = array();
		$failure_message = __( 'Check the file format, required Name header and safe limits, then try again.', 'ultimate-product-catalogue' );
		// The same file/row is an update on retry, never a fuzzy title match.
		$import_hash = hash_file( 'sha256', $excel_path );
		global $wpdb;
		$import_lock = 'upcp-import-' . substr( hash( 'sha256', $wpdb->prefix . $import_hash ), 0, 40 );
		// Connection-owned locks also disappear on fatal exit or worker termination.
		$lock_held = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $import_lock ) );

		try {
			if ( ! $lock_held ) {
				$failure_message = __( 'Another import of this file is already running. Wait for it to finish before retrying.', 'ultimate-product-catalogue' );
				throw new RuntimeException( 'import_in_progress' ); }
			// Build the workbook object out of the uploaded spreadsheet
			$reader = \EwdUpcpVendor\PhpOffice\PhpSpreadsheet\IOFactory::createReader( $upload['reader'] );
			$reader->setReadDataOnly( true );
			$inspection = $boundary->inspect_file( $excel_path, $upload['reader'], $reader );
			if ( is_wp_error( $inspection ) ) {
				$failure_message = $inspection->get_error_message();
				throw new RuntimeException( 'unsafe_file' ); }
			$reader->setLoadSheetsOnly( $inspection['sheet_name'] );
			$reader->setReadFilter( new ewdupcpImportReadFilter( 1, 1 ) );
			$spreadsheet = $reader->load( $excel_path );

			// Create a worksheet object out of the product sheet in the workbook
			$sheet = $spreadsheet->getActiveSheet();

			$allowable_custom_fields = array();
			foreach ( $custom_fields as $custom_field ) {
				$allowable_custom_fields[] = $custom_field->name; }

			// Get column names
			$additional_image_columns = array();
			$product_video_columns    = array();

			$highest_column       = $sheet->getHighestColumn();
			$highest_column_index = \EwdUpcpVendor\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString( $highest_column );
			for ( $column = 1; $column <= $highest_column_index; $column++ ) {

				$heading = $boundary->cell_value( $sheet, $column, 1 );
				if ( is_wp_error( $heading ) ) {
					throw new RuntimeException( 'invalid_header' ); }
				$heading = trim( (string) $heading );
				if ( '' === $heading ) {
					continue; }

				if ( 'ID' === $heading ) {
					$id_column = $column; }
				if ( 'Name' === $heading ) {
					$name_column = $column; }
				if ( 'Slug' === $heading ) {
					$slug_column = $column; }
				if ( 'Description' === $heading ) {
					$description_column = $column; }
				if ( 'Price' === $heading ) {
					$price_column = $column; }
				if ( 'Sale Price' === $heading ) {
					$sale_price_column = $column; }
				if ( 'Image' === $heading ) {
					$image_column = $column; }
				if ( 'Link' === $heading ) {
					$link_column = $column; }
				if ( 'Category' === $heading ) {
					$category_column = $column; }
				if ( 'Sub-Category' === $heading ) {
					$subcategory_column = $column; }
				if ( 'Tags' === $heading ) {
					$tags_column = $column; }
				if ( 'SEO Description' === $heading ) {
					$seo_description_column = $column; }
				if ( 'Display' === $heading ) {
					$display_column = $column; }
				if ( 'Catalogue ID' === $heading ) {
					$catalog_id_column = $column; }

				foreach ( $custom_fields as $custom_field ) {

					if ( $heading === (string) $custom_field->name ) {
						$custom_field->column = $column; }
				}

				if ( strpos( $sheet->getCellByColumnAndRow( $column, 1 )->getValue(), 'Additional Image' ) !== false ) {

					$additional_image_columns[] = $column;
				}

				if ( strpos( $sheet->getCellByColumnAndRow( $column, 1 )->getValue(), 'Video URL' ) !== false ) {

					$product_video_columns[] = $column;
				}
			}

			$id_column             = ! empty( $id_column ) ? $id_column : -1;
			$name_column           = ! empty( $name_column ) ? $name_column : -1;
			$slug_column           = ! empty( $slug_column ) ? $slug_column : -1;
			$description_column    = ! empty( $description_column ) ? $description_column : -1;
			$price_column          = ! empty( $price_column ) ? $price_column : -1;
			$sale_price_column     = ! empty( $sale_price_column ) ? $sale_price_column : -1;
			$image_column          = ! empty( $image_column ) ? $image_column : -1;
			$link_column           = ! empty( $link_column ) ? $link_column : -1;
			$category_column       = ! empty( $category_column ) ? $category_column : -1;
			$subcategory_column    = ! empty( $subcategory_column ) ? $subcategory_column : -1;
			$tags_column           = ! empty( $tags_column ) ? $tags_column : -1;
			$seo_description_column = ! empty( $seo_description_column ) ? $seo_description_column : -1;
			$display_column        = ! empty( $display_column ) ? $display_column : -1;
			$catalog_id_column     = ! empty( $catalog_id_column ) ? $catalog_id_column : -1;

			if ( $name_column < 1 ) {
				throw new RuntimeException( 'missing_name_header' ); }
			$highest_row = $inspection['rows'];
			$batch_rows  = min( ewdupcpSpreadsheetImport::BATCH_ROWS, max( 1, intdiv( 10000, $inspection['columns'] ) ) );
			for ( $row_number = 2; $row_number <= $highest_row; $row_number++ ) {
				$row_errors = array_slice( $row_errors, 0, 20 );
				if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $import_lock ) ) ) {
					throw new RuntimeException( 'import_lease_lost' ); }
				if ( ! $ewd_upcp_controller->permissions->check_permission( 'import', true ) ) {
					$failure_message = __( 'Premium access is currently unavailable. Restore access and retry the same file to continue safely.', 'ultimate-product-catalogue' );
					throw new RuntimeException( 'entitlement_lost' ); }
				if ( 0 === ( $row_number - 2 ) % $batch_rows ) {
					$spreadsheet->disconnectWorksheets();
					$reader->setReadFilter( new ewdupcpImportReadFilter( $row_number, min( $highest_row, $row_number + $batch_rows - 1 ) ) );
					$spreadsheet = $reader->load( $excel_path );
					$sheet       = $spreadsheet->getActiveSheet();
				}
				$product_data = array();
				$invalid_row  = false;
				for ( $column = 1; $column <= $highest_column_index; $column++ ) {
					$value = $boundary->cell_value( $sheet, $column, $row_number );
					if ( is_wp_error( $value ) ) {
						$invalid_row = true;
						break; }
					$product_data[ $column ] = $value;
				}
				if ( $invalid_row ) {
					++$counts['failed'];
					$row_errors[] = $row_number;
					continue; }
				if ( ! $this->valid_row( $product_data, $id_column, $catalog_id_column, array( $price_column, $sale_price_column ), array_merge( array( $image_column, $link_column ), $additional_image_columns ) ) ) {
					++$counts['failed'];
					$row_errors[] = $row_number;
					continue;
				}

				// Save the data into an array, so that an product can be updated based on the product
				// number if it exists already
				$product_custom_fields = array();

				$post_data = array(
					'post_type'   => EWD_UPCP_PRODUCT_POST_TYPE,
					'post_status' => 'publish',
				);

				$post_meta = array(
					'display' => true,
				);

				$product_images = array();
				$product_videos = array();

				foreach ( $product_data as $col_index => $value ) {
					if ( $col_index === $id_column ) {
						$post_data['ID'] = absint( $value );
					} elseif ( $col_index === $name_column ) {
						$post_data['post_title'] = sanitize_text_field( $value );
					} elseif ( $col_index === $slug_column ) {
						$post_data['post_name'] = sanitize_text_field( $value );
					} elseif ( $col_index === $description_column ) {
						$post_data['post_content'] = wp_kses_post( $value );
					} elseif ( $col_index === $price_column ) {
						$post_meta['price'] = sanitize_text_field( $value );
					} elseif ( $col_index === $sale_price_column ) {
						$post_meta['sale_price'] = sanitize_text_field( $value );
					} elseif ( $col_index === $image_column ) {
						$post_meta['image'] = sanitize_text_field( $value );
					} elseif ( $col_index === $link_column ) {
						$post_meta['link'] = sanitize_text_field( $value );
					} elseif ( $col_index === $category_column ) {
						$post_meta['category'] = sanitize_text_field( $value );
					} elseif ( $col_index === $subcategory_column ) {
						$post_meta['subcategory'] = sanitize_text_field( $value );
					} elseif ( $col_index === $tags_column ) {
						$post_meta['tags'] = sanitize_text_field( $value );
					} elseif ( $col_index === $seo_description_column ) {
						$post_meta['seo_description'] = sanitize_text_field( $value );
					} elseif ( $col_index === $display_column ) {
						$post_meta['display'] = sanitize_text_field( $value );
					} elseif ( $col_index === $catalog_id_column ) {
						$post_meta['catalog_id'] = intval( $value );
					} elseif ( in_array( $col_index, $additional_image_columns, true ) ) {
						if ( empty( $value ) ) {
							continue;
						}
						$product_images[] = (object) array(
							'url'         => sanitize_text_field( $value ),
							'description' => '',
						);
					} elseif ( in_array( $col_index, $product_video_columns, true ) ) {
						if ( empty( $value ) ) {
							continue;
						}
						if ( 'http' === strtolower( substr( $value, 0, 4 ) ) ) {
							$parsed = wp_parse_url( $value );
							$params = array();
							parse_str( isset( $parsed['query'] ) ? $parsed['query'] : '', $params );
							$video_id = sanitize_text_field( isset( $params['v'] ) ? $params['v'] : '' );
						} else {
							$video_id = sanitize_text_field( $value );
						}
						$product_videos[] = (object) array(
							'url'  => $video_id,
							'type' => 'youtube',
						);
					} else {
						foreach ( $custom_fields as $custom_field ) {
							if ( isset( $custom_field->column ) && $col_index === (int) $custom_field->column ) {
								$product_custom_fields[ $custom_field->id ] = wp_kses_post( $value );
							}
						}
					}
				}

				if ( empty( $post_data['post_title'] ) ) {
					++$counts['skipped'];
					continue;
				}
				$identity = hash( 'sha256', $import_hash . ':' . $row_number );
				if ( empty( $post_data['ID'] ) ) {
					$previous = get_posts(
						array(
							'post_type'      => EWD_UPCP_PRODUCT_POST_TYPE,
							'post_status'    => array_keys( get_post_stati() ),
							'posts_per_page' => 2,
							'fields'         => 'ids',
							'meta_key'       => '_ewd_upcp_import_identity',
							'meta_value'     => $identity,
						)
					);
					if ( count( $previous ) > 1 ) {
						++$counts['failed'];
						$row_errors[] = $row_number;
						continue; }
					if ( $previous ) {
						$post_data['ID'] = (int) $previous[0]; }
				}

				if ( ! empty( $post_data['ID'] ) ) {
					$target_post = get_post( $post_data['ID'] );

					if ( ! $target_post || 'trash' === $target_post->post_status || EWD_UPCP_PRODUCT_POST_TYPE !== $target_post->post_type || ! current_user_can( 'edit_post', $post_data['ID'] ) ) {
						++$counts['failed'];
						$row_errors[] = sprintf(
						/* translators: %d: spreadsheet row number. */
							__( 'Row %d was rejected because its product ID is not an editable UPCP product.', 'ultimate-product-catalogue' ),
							$row_number
						);
						continue;
					}
					$post_data['post_status'] = $target_post->post_status;
				} elseif ( ! current_user_can( 'create_upcp_products' ) || ! current_user_can( 'publish_upcp_products' ) ) {
					++$counts['failed'];
					$row_errors[] = sprintf(
					/* translators: %d: spreadsheet row number. */
						__( 'Row %d was rejected because you cannot create another published UPCP product.', 'ultimate-product-catalogue' ),
						$row_number
					);
					continue;
				}

				if ( ! empty( $post_meta['catalog_id'] ) ) {
					$catalog = get_post( $post_meta['catalog_id'] );
					if ( ! $catalog || EWD_UPCP_CATALOG_POST_TYPE !== $catalog->post_type || ! current_user_can( 'edit_post', $catalog->ID ) ) {
						++$counts['failed'];
						$row_errors[] = sprintf(
						/* translators: %d: spreadsheet row number. */
							__( 'Row %d was rejected because its catalog ID is not an editable UPCP catalog.', 'ultimate-product-catalogue' ),
							$row_number
						);
						continue;
					}
				}

				$post_data['post_content'] = ! empty( $post_data['post_content'] ) ? $post_data['post_content'] : '';

				$is_update = ! empty( $post_data['ID'] );
				if ( ! $is_update ) {
					$post_data['meta_input'] = array( '_ewd_upcp_import_identity' => $identity ); }
				$post_id = wp_insert_post( $post_data, true );

				if ( is_wp_error( $post_id ) || ! $post_id ) {
					++$counts['failed'];
					$row_errors[] = sprintf(
					/* translators: %d: spreadsheet row number. */
						__( 'Row %d could not be imported.', 'ultimate-product-catalogue' ),
						$row_number
					);
					continue;
				}

				if ( $post_id ) {
					++$counts[ $is_update ? 'updated' : 'created' ];

					if ( isset( $post_meta['price'] ) ) {
						$this->write_import_meta( $post_id, 'price', $post_meta['price'] ); }
					if ( isset( $post_meta['sale_price'] ) ) {
						$this->write_import_meta( $post_id, 'sale_price', $post_meta['sale_price'] ); }
					if ( ! empty( $post_meta['link'] ) ) {
						$this->write_import_meta( $post_id, 'link', $post_meta['link'] ); }
					if ( isset( $post_meta['display'] ) ) {
						$this->write_import_meta( $post_id, 'display', $post_meta['display'] ); }

					if ( ! empty( $post_meta['seo_description'] ) ) {
						$this->write_import_meta( $post_id, '_yoast_wpseo_metadesc', $post_meta['seo_description'] ); }

					if ( ! empty( $post_meta['image'] ) ) {

						$thumbnail_id = attachment_url_to_postid( $post_meta['image'] );

						if ( $thumbnail_id ) {

							set_post_thumbnail( $post_id, $thumbnail_id );
						} else {

							$this->write_import_meta( $post_id, 'external_image', true );
							$this->write_import_meta( $post_id, 'external_image_url', $post_meta['image'] );
						}
					}

					if ( ! empty( $post_meta['category'] ) or ! empty( $post_meta['subcategory'] ) ) {

						$subcategories = ! empty( $post_meta['subcategory'] ) ? explode( ',', $post_meta['subcategory'] ) : array();

						$categories = ! empty( $post_meta['category'] ) ? explode( ',', $post_meta['category'] ) : array();

						$submitted_categories = array_merge( $subcategories, $categories );

						$filtered_categories = array_filter( $submitted_categories );

						$category_ids = array();

						foreach ( $filtered_categories as $category_name ) {

							$category_term = get_term_by( 'name', $category_name, EWD_UPCP_PRODUCT_CATEGORY_TAXONOMY );

							if ( $category_term ) {
								$category_ids[] = $category_term->term_id; } elseif ( $ewd_upcp_controller->settings->get_setting( 'import-category-creation' ) and in_array( $category_name, $categories ) ) {

														$new_term = wp_insert_term( $category_name, EWD_UPCP_PRODUCT_CATEGORY_TAXONOMY );

								if ( ! is_wp_error( $new_term ) && isset( $new_term['term_id'] ) ) {
									$category_ids[] = $new_term['term_id'];
								}
								if ( is_wp_error( $new_term ) ) {
									throw new RuntimeException( 'taxonomy_write_failed' );
								}
								}
						}

						if ( ! empty( $category_ids ) ) {
							if ( is_wp_error( wp_set_post_terms( $post_id, $category_ids, EWD_UPCP_PRODUCT_CATEGORY_TAXONOMY, true ) ) ) {
								throw new RuntimeException( 'taxonomy_write_failed' );
							}
						}
					}

					if ( ! empty( $post_meta['tags'] ) ) {

						if ( is_wp_error( wp_set_post_terms( $post_id, explode( ',', $post_meta['tags'] ), EWD_UPCP_PRODUCT_TAG_TAXONOMY ) ) ) {
							throw new RuntimeException( 'taxonomy_write_failed' );
						}
					}

					if ( ! empty( $post_meta['catalog_id'] ) ) {

						$items = is_array( get_post_meta( $post_meta['catalog_id'], 'items', true ) ) ? get_post_meta( $post_meta['catalog_id'], 'items', true ) : array();

						$items   = array_filter(
							$items,
							static function ( $item ) use ( $post_id ) {
								return ! ( is_object( $item ) && 'product' === $item->type && $post_id === (int) $item->id );
							}
						);
						$items[] = (object) array(
							'type' => 'product',
							'id'   => $post_id,
						);

						$this->write_import_meta( $post_meta['catalog_id'], 'items', $items );
					}

					if ( ! empty( $product_images ) ) {

						$this->write_import_meta( $post_id, 'product_images', $product_images );
					}

					if ( ! empty( $product_videos ) ) {

						$this->write_import_meta( $post_id, 'product_videos', $product_videos );
					}

					foreach ( $product_custom_fields as $field_id => $field_value ) {

						foreach ( $custom_fields as $custom_field ) {

							if ( $custom_field->id != $field_id ) {
								continue; }

							if ( $custom_field->type == 'checkbox' ) {

								$this->write_import_meta( $post_id, 'custom_field_' . $field_id, explode( ',', $field_value ) );
							} else {

								$this->write_import_meta( $post_id, 'custom_field_' . $field_id, $field_value );
							}
						}
					}

					$this->write_import_meta( $post_id, 'order', 9999 );
				}

				$row_errors = array_slice( $row_errors, 0, 20 );
				do_action( 'ewd_upcp_product_saved', $post_id );
			}
		} catch ( Throwable $exception ) {
			if ( in_array( $exception->getMessage(), array( 'metadata_write_failed', 'taxonomy_write_failed' ), true ) ) {
				++$counts['failed'];
			}
			$this->status  = false;
			$this->message = sprintf(
				/* translators: 1: created rows, 2: updated rows, 3: skipped rows, 4: failed rows. */
				__( 'Import stopped safely: %1$d created, %2$d updated, %3$d skipped, %4$d failed. Completed changes were kept. Retry the same file to update its previously created products.', 'ultimate-product-catalogue' ),
				$counts['created'],
				$counts['updated'],
				$counts['skipped'],
				$counts['failed']
			);
			$this->message .= ' ' . $failure_message;
			add_action( 'admin_notices', array( $this, 'display_notice' ) );
			return;
		} finally {
			if ( $lock_held ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $import_lock ) ); }
			if ( isset( $spreadsheet ) ) {
				$spreadsheet->disconnectWorksheets(); }
			wp_delete_file( $excel_path );
		}

		$this->status  = 0 === $counts['failed'];
		$this->message = sprintf(
			/* translators: 1: created, 2: updated, 3: skipped, 4: failed rows. */
			__( 'Import complete: %1$d created, %2$d updated, %3$d skipped, %4$d failed.', 'ultimate-product-catalogue' ),
			$counts['created'],
			$counts['updated'],
			$counts['skipped'],
			$counts['failed']
		);
		if ( $row_errors ) {
			/* translators: %s: first rejected row numbers or safe row explanations. */
			$this->message .= ' ' . sprintf( __( 'First rejected rows: %s', 'ultimate-product-catalogue' ), implode( '; ', array_slice( $row_errors, 0, 20 ) ) );
		}

		add_action( 'admin_notices', array( $this, 'display_notice' ) );
	}

	/** Detect failed writes without treating an unchanged value as failure. */
	private function write_import_meta( $post_id, $key, $value ) {
		$result = update_post_meta( $post_id, $key, $value );
		if ( false === $result && (string) maybe_serialize( get_post_meta( $post_id, $key, true ) ) !== (string) maybe_serialize( $value ) ) {
			throw new RuntimeException( 'metadata_write_failed' );
		}
		return $result;
	}

	public function valid_row( $values, $id_column, $catalog_column, $price_columns, $url_columns ) {
		foreach ( array( $id_column, $catalog_column ) as $column ) {
			$value = isset( $values[ $column ] ) ? trim( (string) $values[ $column ] ) : '';
			if ( '' !== $value && ( ! ctype_digit( $value ) || (int) $value < 1 || strlen( $value ) > 18 ) ) {
				return false; }
		}
		foreach ( $price_columns as $column ) {
			$value = isset( $values[ $column ] ) ? trim( (string) $values[ $column ] ) : '';
			if ( ! $this->valid_price( $value ) ) {
				return false; }
		}
		foreach ( $url_columns as $column ) {
			$value = isset( $values[ $column ] ) ? trim( (string) $values[ $column ] ) : '';
			if ( '' !== $value && ( ! wp_http_validate_url( $value ) || ! in_array( wp_parse_url( $value, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) ) {
				return false; }
		}
		return true;
	}

	/** Validate localized prices without changing the text stored/displayed by UPCP. */
	private function valid_price( $value ) {
		if ( '' === $value ) {
			return true;
		}
		if ( false !== strpos( $value, ',' ) ) {
			if ( preg_match( '/^[0-9]{1,3}(?:,[0-9]{3})+(?:\.[0-9]+)?$/D', $value ) ) {
				$value = str_replace( ',', '', $value );
			} elseif ( preg_match( '/^(?:[0-9]+|[0-9]{1,3}(?:\.[0-9]{3})+),[0-9]+$/D', $value ) ) {
				$value = str_replace( ',', '.', str_replace( '.', '', $value ) );
			} else {
				return false;
			}
		}
		return is_numeric( $value ) && is_finite( (float) $value ) && (float) $value >= 0;
	}

	/** Backward-compatible wrapper around the single maintained upload boundary. */
	public function handle_spreadsheet_upload() {
		global $ewd_upcp_controller;
		if ( ! current_user_can( 'edit_upcp_products' ) || ! $ewd_upcp_controller->permissions->check_permission( 'import', true ) ) {
			return array(
				'message_type' => 'Error',
				'message'      => __( 'Import is currently unavailable.', 'ultimate-product-catalogue' ),
			);
		}
		require_once EWD_UPCP_PLUGIN_DIR . '/includes/SpreadsheetImport.class.php';
		$boundary = new ewdupcpSpreadsheetImport();
		$upload   = $boundary->prepare_upload();
		if ( is_wp_error( $upload ) ) {
			return array(
				'message_type' => 'Error',
				'message'      => $upload->get_error_message(),
			);
		}
		$path = $upload['path'];
		register_shutdown_function(
			static function () use ( $path ) {
				if ( is_file( $path ) ) {
					wp_delete_file( $path );
				}
			}
		);
		return array(
			'message_type' => 'Success',
			'path'         => $path,
		);
	}

	private function reject_request( $message ) {
		$this->status  = false;
		$this->message = $message;
		add_action( 'admin_notices', array( $this, 'display_notice' ) );
	}

	public function display_notice() {

		if ( $this->status ) {

			echo "<div class='updated'><p>" . esc_html( $this->message ) . '</p></div>';
		} else {

			echo "<div class='error'><p>" . esc_html( $this->message ) . '</p></div>';
		}
	}
}
