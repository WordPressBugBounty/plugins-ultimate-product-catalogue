<?php
/** Authorized bounded product export, preserving the CSV/XLS field contract. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class ewdupcpExport {

	// Set whether a valid nonce is needed before exporting products
	public $nonce_check = true;

	public function __construct() {
		add_action( 'admin_footer-edit.php', array( $this, 'print_button' ) );

		if ( isset( $_POST['ewd_upcp_export'] ) ) {
			add_action( 'admin_init', array( $this, 'export_products' ) ); }
	}

	/**
	 * Adds 'Export' button to products page
	 *
	 * @since 5.3.0
	 */
	public function print_button() {
		global $post_type;
		global $ewd_upcp_controller;

		if ( ! isset( $post_type ) || EWD_UPCP_PRODUCT_POST_TYPE !== $post_type ) {
			return; }

		if ( ! $ewd_upcp_controller->permissions->check_permission( 'export', true ) ) {
			return; }

		?>

		<button type="button" id="ewd-upcp-export-open" class="ewd-upcp-hidden button button-secondary" aria-haspopup="dialog"><?php esc_html_e( 'Export', 'ultimate-product-catalogue' ); ?></button>
		<div id="ewd-upcp-export-modal" class="ewd-upcp-hidden ewd-upcp-transfer-modal">
		<div class="ewd-upcp-transfer-modal-inside" role="dialog" aria-modal="true" aria-labelledby="ewd-upcp-export-title">
		<button type="button" class="ewd-upcp-transfer-modal-close" aria-label="<?php esc_attr_e( 'Close export', 'ultimate-product-catalogue' ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
		<h2 id="ewd-upcp-export-title"><?php esc_html_e( 'Export Products', 'ultimate-product-catalogue' ); ?></h2>
		<form method='post' id='ewd-upcp-export'>
			<input type="hidden" name="ewd_upcp_export" value="1">
			<?php wp_nonce_field( 'EWD_UPCP_Export', 'EWD_UPCP_Export_Nonce' ); ?>
			<label for="ewd-upcp-export-format"><?php esc_html_e( 'File format', 'ultimate-product-catalogue' ); ?></label>
			<select id="ewd-upcp-export-format" name="format_type"><option value="csv">CSV</option><option value="xls">XLS</option></select>
			<p><?php esc_html_e( 'Up to 10,000 products. XLS is also limited to 100,000 cells; use CSV for larger catalogs.', 'ultimate-product-catalogue' ); ?></p>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Export', 'ultimate-product-catalogue' ); ?></button>
		</form>
		</div></div>

		<?php
	}


	public function export_products() {
		global $ewd_upcp_controller;
		if ( ! current_user_can( 'edit_upcp_products' ) || ! $ewd_upcp_controller->permissions->check_permission( 'export', true ) ) {
			wp_die( esc_html__( 'You are not allowed to export products.', 'ultimate-product-catalogue' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'EWD_UPCP_Export', 'EWD_UPCP_Export_Nonce' );
		$format = isset( $_POST['format_type'] ) && is_string( $_POST['format_type'] ) ? sanitize_key( wp_unslash( $_POST['format_type'] ) ) : 'csv';
		if ( ! in_array( $format, array( 'csv', 'xls' ), true ) ) {
			wp_die( esc_html__( 'Choose CSV or XLS for product export.', 'ultimate-product-catalogue' ), '', array( 'response' => 400 ) );
		}
		if ( 'xls' === $format && ! ewdupcpSpreadsheetRuntime::is_trusted() ) {
			wp_die( esc_html__( 'The XLS runtime could not be loaded safely. Resolve the plugin class conflict or use CSV. No product data was changed.', 'ultimate-product-catalogue' ) );
		}
		$path   = wp_tempnam( 'upcp-export' );
		if ( ! $path ) {
			wp_die( esc_html__( 'Temporary export storage is unavailable.', 'ultimate-product-catalogue' ) ); }
		register_shutdown_function(
			static function () use ( $path ) {
				if ( is_file( $path ) ) {
					wp_delete_file( $path );
				} }
		);
		try {
			$this->write_export( $path, $format );
			if ( ! $ewd_upcp_controller->permissions->check_permission( 'export', true ) ) {
				throw new RuntimeException( 'entitlement_lost' ); }
			nocache_headers();
			header( 'Content-Type: ' . ( 'csv' === $format ? 'text/csv; charset=UTF-8' : 'application/vnd.ms-excel' ) );
			header( 'Content-Disposition: attachment; filename="products_export.' . $format . '"' );
			readfile( $path );
		} catch ( Throwable $error ) {
			$message = in_array( $error->getMessage(), array( 'row_limit', 'cell_limit', 'byte_limit', 'column_limit' ), true )
				? __( 'The export could not be completed within its safe limits. Use CSV for larger catalogs. No product data was changed.', 'ultimate-product-catalogue' )
				: __( 'The export could not be completed. Check Premium access, temporary storage and plugin conflicts, then try again. No product data was changed.', 'ultimate-product-catalogue' );
			wp_die( esc_html( $message ) );
		} finally {
			wp_delete_file( $path );
		}
		exit;
	}

	/** Build to a temporary local file before headers, with bounded source pages. */
	public function write_export( $path, $format ) {
		global $ewd_upcp_controller;
		if ( ! current_user_can( 'edit_upcp_products' ) || ! $ewd_upcp_controller->permissions->check_permission( 'export', true ) || ( 'xls' === $format && ! ewdupcpSpreadsheetRuntime::is_trusted() ) ) {
			throw new RuntimeException( 'export_denied' );
		}
		if ( ! in_array( $format, array( 'csv', 'xls' ), true ) || ! is_string( $path ) || false !== strpos( $path, '://' ) ) {
			throw new RuntimeException( 'invalid_export' ); }
		$fields  = $ewd_upcp_controller->settings->get_custom_fields();
		$headers = array( 'ID', 'Name', 'Slug', 'Description', 'Price', 'Sale Price', 'Image', 'Link', 'Category', 'Sub-Category', 'Tags' );
		foreach ( $fields as $field ) {
			$headers[] = $field->name; }
		foreach ( array( 'Additional Image', 'Video URL' ) as $heading ) {
			foreach ( array( 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten' ) as $number ) {
				$headers[] = $heading . ' ' . $number; }
		}
		if ( count( $headers ) > 100 ) {
			throw new RuntimeException( 'column_limit' ); }
		$stream = 'csv' === $format ? fopen( $path, 'wb' ) : false;
		if ( 'csv' === $format && ! $stream ) {
			throw new RuntimeException( 'export_storage' ); }
		$book  = 'xls' === $format ? new \EwdUpcpVendor\PhpOffice\PhpSpreadsheet\Spreadsheet() : null;
		$row   = 1;
		$bytes = 0;
		try {
			$this->write_row( $headers, $stream, $book, $row++, $bytes );
			$last_id     = 0;
			$ceiling_ids = get_posts(
				array(
					'post_type'      => EWD_UPCP_PRODUCT_POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'DESC',
				)
			);
			$ceiling     = $ceiling_ids ? (int) $ceiling_ids[0] : 0;
			while ( $ceiling > $last_id ) {
				if ( ! $ewd_upcp_controller->permissions->check_permission( 'export', true ) ) {
					throw new RuntimeException( 'entitlement_lost' ); }
				$window = static function ( $where, $query ) use ( $last_id, $ceiling ) {
					global $wpdb;
					return $query->get( 'upcp_export_window' ) ? $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d AND {$wpdb->posts}.ID <= %d", $last_id, $ceiling ) : $where;
				};
				add_filter( 'posts_where', $window, 10, 2 );
				try {
					$posts = get_posts(
						array(
							'post_type'          => EWD_UPCP_PRODUCT_POST_TYPE,
							'post_status'        => 'publish',
							'posts_per_page'     => 250,
							'upcp_export_window' => true,
							'suppress_filters'   => false,
							'orderby'            => 'ID',
							'order'              => 'ASC',
							'no_found_rows'      => true,
						)
					);
				} finally {
					remove_filter( 'posts_where', $window, 10 ); }
				foreach ( $posts as $post ) {
					$last_id = (int) $post->ID;
					if ( ! current_user_can( 'read_post', $post->ID ) ) {
						continue; }
					if ( $row > 10001 || ( $book && $row * count( $headers ) > 100000 ) ) {
						throw new RuntimeException( 'row_limit' ); }
					$product = new ewdupcpProduct();
					$product->load_wp_post( $post );
					$values = array( $product->ID, $product->name, $product->slug, $product->description, $product->regular_price, $product->sale_price, $product->get_main_image_url(), $product->link, $product->get_category_names(), $product->get_subcategory_names(), $product->get_tag_names() );
					foreach ( $fields as $field ) {
						$value    = isset( $product->custom_fields[ $field->id ] ) ? $product->custom_fields[ $field->id ] : '';
						$values[] = is_array( $value ) ? implode( ',', $value ) : $value;
					}
					foreach ( array( 'product_images', 'product_videos' ) as $key ) {
						$items = get_post_meta( $product->ID, $key, true );
						$items = is_array( $items ) ? array_values( $items ) : array();
						for ( $index = 0; $index < 10; $index++ ) {
							$values[] = isset( $items[ $index ]->url ) ? $items[ $index ]->url : ''; }
					}
					$this->write_row( $values, $stream, $book, $row++, $bytes );
				}
				if ( count( $posts ) < 250 ) {
					break; }
			}
			if ( $book ) {
				$writer = new \EwdUpcpVendor\PhpOffice\PhpSpreadsheet\Writer\Xls( $book );
				$writer->setPreCalculateFormulas( false );
				$writer->save( $path );
			}
		} finally {
			if ( $stream ) {
				fclose( $stream ); }
			if ( $book ) {
				$book->disconnectWorksheets(); }
		}
		return $row - 2;
	}

	private function write_row( $values, $stream, $book, $row, &$bytes ) {
		$output = array();
		foreach ( $values as $index => $value ) {
			$value = (string) $value;
			if ( strlen( $value ) > ( $book ? 32767 : 65535 ) ) {
				throw new RuntimeException( 'cell_limit' ); }
			$bytes += strlen( $value );
			if ( $bytes > ( $book ? 16777216 : 67108864 ) ) {
				throw new RuntimeException( 'byte_limit' ); }
			if ( $book ) {
				$book->getActiveSheet()->setCellValueExplicitByColumnAndRow( $index + 1, $row, $value, \EwdUpcpVendor\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING );
			} else {
				$trimmed = preg_replace( '/^[\x00-\x20\x7f\x{00a0}]*/u', '', $value );
				if ( null === $trimmed ) {
					throw new RuntimeException( 'invalid_encoding' ); }
				$output[] = '' !== $trimmed && in_array( substr( $trimmed, 0, 1 ), array( '=', '+', '-', '@' ), true ) ? "'" . $value : $value;
			}
		}
		if ( $stream && false === fputcsv( $stream, $output, ',', '"', '' ) ) {
			throw new RuntimeException( 'export_storage' ); }
	}
}


