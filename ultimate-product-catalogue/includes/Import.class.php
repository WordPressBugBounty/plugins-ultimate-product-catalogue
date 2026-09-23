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

		if ( isset( $_POST['ewdupcpImport'] ) ) {
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

		if ( ! $ewd_upcp_controller->permissions->check_permission( 'export' ) ) {
			return; }

		?>

		<button id='ewd-upcp-import-open' class='ewd-upcp-hidden button button-secondary'>
			<?php _e( 'Import', 'ultimate-product-catalogue' ); ?>
		</button>

		<div id='ewd-upcp-import-modal' class='ewd-upcp-hidden'>

			<div id='ewd-upcp-import-modal-inside'>

				<div class='ewd-upcp-import-modal-close'>
					<div class='ewd-upcp-import-modal-close-inside'><span class="dashicons dashicons-no-alt"></span></div>
				</div>

				<h2><?php _e( 'Import Products', 'ultimate-product-catalogue' ); ?></h2>

				<form method='post' enctype="multipart/form-data">
					
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

		$import_permission = $ewd_upcp_controller->permissions->check_permission( 'import' );
		?>
		<div class='wrap'>
			<h2>Import</h2>
			<?php if ( $import_permission ) { ?> 
				<form method='post' enctype="multipart/form-data">
					
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

	public function import_products() {
		global $ewd_upcp_controller;

		if ( ! current_user_can( 'edit_upcp_products' ) ) {
			return; }

		if ( ! $ewd_upcp_controller->permissions->check_permission( 'import' ) ) {
			return; }

		if ( ! isset( $_POST['EWD_UPCP_Import_Nonce'] ) ) {
			return; }

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['EWD_UPCP_Import_Nonce'] ) ), 'EWD_UPCP_Import' ) ) {
			return; }

		if ( ! ewdupcpSpreadsheetRuntime::is_trusted() ) {
			$this->status  = false;
			$this->message = __( 'The spreadsheet runtime could not be loaded safely.', 'ultimate-product-catalogue' );
			add_action( 'admin_notices', array( 'ewdupcpSpreadsheetRuntime', 'notice' ) );
			return;
		}

		$update = $this->handle_spreadsheet_upload();

		$custom_fields = $ewd_upcp_controller->settings->get_custom_fields();

		if ( $update['message_type'] != 'Success' ) :
			$this->status  = false;
			$this->message = $update['message'];

			add_action( 'admin_notices', array( $this, 'display_notice' ) );

			return;
		endif;

		$excel_path = $update['path'];

		try {
			// Build the workbook object out of the uploaded spreadsheet
			$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load( $excel_path );

			// Create a worksheet object out of the product sheet in the workbook
			$sheet = $spreadsheet->getActiveSheet();

			$allowable_custom_fields = array();
			foreach ( $custom_fields as $custom_field ) {
				$allowable_custom_fields[] = $custom_field->name; }

			// Get column names
			$additional_image_columns = array();
			$product_video_columns    = array();

			$highest_column       = $sheet->getHighestColumn();
			$highest_column_index = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString( $highest_column );
			for ( $column = 1; $column <= $highest_column_index; $column++ ) {

				$heading = trim( (string) $sheet->getCellByColumnAndRow( $column, 1 )->getValue() );
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
					$seo_decription_column = $column; }
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
			$seo_decription_column = ! empty( $seo_decription_column ) ? $seo_decription_column : -1;
			$display_column        = ! empty( $display_column ) ? $display_column : -1;
			$catalog_id_column     = ! empty( $catalog_id_column ) ? $catalog_id_column : -1;

			// Put the spreadsheet data into a multi-dimensional array to facilitate processing
			$data        = array();
			$highest_row = $sheet->getHighestRow();
			for ( $row = 2; $row <= $highest_row; $row++ ) {
				for ( $column = 1; $column <= $highest_column_index; $column++ ) {
					$data[ $row ][ $column ] = $sheet->getCellByColumnAndRow( $column, $row )->getValue();
				}
			}

			// Create the query to insert the products one at a time into the database and then run it
			$row_errors = array();
			foreach ( $data as $row_number => $product_data ) {

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
					} elseif ( $col_index === $seo_decription_column ) {
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
					continue;
				}

				if ( ! empty( $post_data['ID'] ) ) {
					$target_post = get_post( $post_data['ID'] );

					if ( ! $target_post || EWD_UPCP_PRODUCT_POST_TYPE !== $target_post->post_type || ! current_user_can( 'edit_post', $post_data['ID'] ) ) {
						$row_errors[] = sprintf(
						/* translators: %d: spreadsheet row number. */
							__( 'Row %d was rejected because its product ID is not an editable UPCP product.', 'ultimate-product-catalogue' ),
							$row_number
						);
						continue;
					}
					$post_data['post_status'] = $target_post->post_status;
				} elseif ( ! current_user_can( 'create_upcp_products' ) || ! current_user_can( 'publish_upcp_products' ) ) {
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
						$row_errors[] = sprintf(
						/* translators: %d: spreadsheet row number. */
							__( 'Row %d was rejected because its catalog ID is not an editable UPCP catalog.', 'ultimate-product-catalogue' ),
							$row_number
						);
						continue;
					}
				}

				$post_data['post_content'] = ! empty( $post_data['post_content'] ) ? $post_data['post_content'] : '';

				$post_id = wp_insert_post( $post_data, true );

				if ( is_wp_error( $post_id ) || ! $post_id ) {
					$row_errors[] = sprintf(
					/* translators: %d: spreadsheet row number. */
						__( 'Row %d could not be imported.', 'ultimate-product-catalogue' ),
						$row_number
					);
					continue;
				}

				if ( $post_id ) {

					if ( isset( $post_meta['price'] ) ) {
						update_post_meta( $post_id, 'price', $post_meta['price'] ); }
					if ( isset( $post_meta['sale_price'] ) ) {
						update_post_meta( $post_id, 'sale_price', $post_meta['sale_price'] ); }
					if ( ! empty( $post_meta['link'] ) ) {
						update_post_meta( $post_id, 'link', $post_meta['link'] ); }
					if ( isset( $post_meta['display'] ) ) {
						update_post_meta( $post_id, 'display', $post_meta['display'] ); }

					if ( ! empty( $post_meta['seo_description'] ) ) {
						update_post_meta( $post_id, '_yoast_wpseo_metadesc', $post_meta['seo_description'] ); }

					if ( ! empty( $post_meta['image'] ) ) {

						$thumbnail_id = attachment_url_to_postid( $post_meta['image'] );

						if ( $thumbnail_id ) {

							set_post_thumbnail( $post_id, $thumbnail_id );
						} else {

							update_post_meta( $post_id, 'external_image', true );
							update_post_meta( $post_id, 'external_image_url', $post_meta['image'] );
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
								}
						}

						if ( ! empty( $category_ids ) ) {
							wp_set_post_terms( $post_id, $category_ids, EWD_UPCP_PRODUCT_CATEGORY_TAXONOMY, true ); }
					}

					if ( ! empty( $post_meta['tags'] ) ) {

						wp_set_post_terms( $post_id, explode( ',', $post_meta['tags'] ), EWD_UPCP_PRODUCT_TAG_TAXONOMY );
					}

					if ( ! empty( $post_meta['catalog_id'] ) ) {

						$items = is_array( get_post_meta( $post_meta['catalog_id'], 'items', true ) ) ? get_post_meta( $post_meta['catalog_id'], 'items', true ) : array();

						$items[] = (object) array(
							'type' => 'product',
							'id'   => $post_id,
						);

						update_post_meta( $post_meta['catalog_id'], 'items', $items );
					}

					if ( ! empty( $product_images ) ) {

						update_post_meta( $post_id, 'product_images', $product_images );
					}

					if ( ! empty( $product_videos ) ) {

						update_post_meta( $post_id, 'product_videos', $product_videos );
					}

					foreach ( $product_custom_fields as $field_id => $field_value ) {

						foreach ( $custom_fields as $custom_field ) {

							if ( $custom_field->id != $field_id ) {
								continue; }

							if ( $custom_field->type == 'checkbox' ) {

								update_post_meta( $post_id, 'custom_field_' . $field_id, explode( ',', $field_value ) );
							} else {

								update_post_meta( $post_id, 'custom_field_' . $field_id, $field_value );
							}
						}
					}

					update_post_meta( $post_id, 'order', 9999 );
				}

				do_action( 'ewd_upcp_product_saved', $post_id );
			}
		} catch ( Throwable $exception ) {
			$this->status  = false;
			$this->message = __( 'The spreadsheet could not be imported safely.', 'ultimate-product-catalogue' );
			add_action( 'admin_notices', array( $this, 'display_notice' ) );
			return;
		} finally {
			wp_delete_file( $excel_path );
		}

		$this->status  = true;
		$this->message = empty( $row_errors )
			? __( 'Products added successfully.', 'ultimate-product-catalogue' )
			: sprintf(
				/* translators: %d: number of rejected spreadsheet rows. */
				__( 'Products imported with %d rejected row(s).', 'ultimate-product-catalogue' ),
				count( $row_errors )
			);

		add_action( 'admin_notices', array( $this, 'display_notice' ) );
	}

	public function handle_spreadsheet_upload() {

		$file_key = 'ewd_upcp_products_spreadsheet';
		if ( empty( $_FILES[ $file_key ] ) || ! is_array( $_FILES[ $file_key ] ) ) {
			return array(
				'message_type' => 'Error',
				'message'      => __( 'No file was uploaded.', 'ultimate-product-catalogue' ),
			);
		}

		$file = $_FILES[ $file_key ];
		if ( ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return array(
				'message_type' => 'Error',
				'message'      => __( 'The spreadsheet upload did not complete successfully.', 'ultimate-product-catalogue' ),
			);
		}

		$allowed_mimes = array(
			'csv'  => 'text/csv',
			'xls'  => 'application/vnd.ms-excel',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		);
		$filename      = sanitize_file_name( wp_unslash( $file['name'] ) );
		$checked       = wp_check_filetype_and_ext( $file['tmp_name'], $filename, $allowed_mimes );

		if ( empty( $checked['ext'] ) || ! isset( $allowed_mimes[ $checked['ext'] ] ) ) {
			return array(
				'message_type' => 'Error',
				'message'      => __( 'File must be .csv, .xls or .xlsx.', 'ultimate-product-catalogue' ),
			);
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$upload = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'test_type' => true,
				'mimes'     => $allowed_mimes,
			)
		);

		if ( empty( $upload['file'] ) || ! empty( $upload['error'] ) ) {
			return array(
				'message_type' => 'Error',
				'message'      => __( 'There was an error uploading the spreadsheet. Please try again.', 'ultimate-product-catalogue' ),
			);
		}

		return array(
			'message_type' => 'Success',
			'path'         => $upload['file'],
		);
	}

	public function display_notice() {

		if ( $this->status ) {

			echo "<div class='updated'><p>" . esc_html( $this->message ) . '</p></div>';
		} else {

			echo "<div class='error'><p>" . esc_html( $this->message ) . '</p></div>';
		}
	}
}
