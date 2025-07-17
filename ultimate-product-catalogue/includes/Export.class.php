<?php

/**
 * Class to export products created by the plugin
 */

if ( !defined( 'ABSPATH' ) )
	exit;

if (!class_exists('ComposerAutoloaderInit4618f5c41cf5e27cc7908556f031e4d4')) { require_once EWD_UPCP_PLUGIN_DIR . '/lib/PHPSpreadsheet/vendor/autoload.php'; }
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
class ewdupcpExport {

	// Set whether a valid nonce is needed before exporting products
	public $nonce_check = true;

	public function __construct() {
		add_action( 'admin_footer-edit.php', array( $this, 'print_button' ) );

		if ( isset( $_POST['ewd_upcp_export'] ) ) { add_action( 'admin_menu', array($this, 'export_products' )); }
	}

	/**
	 * Adds 'Export' button to products page
	 * 
	 * @since 5.3.0
	 */
	public function print_button() {
		global $post_type;
		global $ewd_upcp_controller;

		if ( ! isset( $post_type ) or $post_type != EWD_UPCP_PRODUCT_POST_TYPE ) { return; }

		if ( ! $ewd_upcp_controller->permissions->check_permission( 'export' ) ) { return; }

		?>

		<form method='post' id='ewd-upcp-export'>
			<?php wp_nonce_field( 'EWD_UPCP_Export', 'EWD_UPCP_Export_Nonce' );  ?>
			<input type='submit' name='ewd_upcp_export' value='Export' class='button button-primary' />
		</form>

		<?php 

	}

	public function export_products() {
		global $ewd_upcp_controller;

		if ( $this->nonce_check and ! isset( $_POST['EWD_UPCP_Export_Nonce'] ) ) { return; }

		if ( $this->nonce_check and ! wp_verify_nonce( $_POST['EWD_UPCP_Export_Nonce'], 'EWD_UPCP_Export' ) ) { return; }

		$custom_fields = $ewd_upcp_controller->settings->get_custom_fields();

		// Instantiate a new PHPExcel object
		$spreadsheet = new Spreadsheet();
		// Set the active Excel worksheet to sheet 0
		$spreadsheet->setActiveSheetIndex(0);

		// Print out the regular product field labels
		$spreadsheet->getActiveSheet()->setCellValue( 'A1', 'ID' );
		$spreadsheet->getActiveSheet()->setCellValue( 'B1', 'Name' );
		$spreadsheet->getActiveSheet()->setCellValue( 'C1', 'Slug' );
		$spreadsheet->getActiveSheet()->setCellValue( 'D1', 'Description' );
		$spreadsheet->getActiveSheet()->setCellValue( 'E1', 'Price' );
		$spreadsheet->getActiveSheet()->setCellValue( 'F1', 'Sale Price' );
		$spreadsheet->getActiveSheet()->setCellValue( 'G1', 'Image' );
		$spreadsheet->getActiveSheet()->setCellValue( 'H1', 'Link' );
		$spreadsheet->getActiveSheet()->setCellValue( 'I1', 'Category' );
		$spreadsheet->getActiveSheet()->setCellValue( 'J1', 'Sub-Category' );
		$spreadsheet->getActiveSheet()->setCellValue( 'K1', 'Tags' );

		$column = 'L';
		foreach ( $custom_fields as $custom_field ) {

			$spreadsheet->getActiveSheet()->setCellValue( $column . '1', $custom_field->name );

			$column++;
		}

		$number_names = array( 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten' );
		foreach ( $number_names as $number ) {

			$spreadsheet->getActiveSheet()->setCellValue( $column . '1', 'Additional Image ' . $number );

			$column++;
		}

		//start while loop to get data
		$row_count = 2;

		$args = array(
			'posts_per_page'	=> -1,
			'post_type'			=> EWD_UPCP_PRODUCT_POST_TYPE,
		);

		$product_posts = get_posts( $args );

		foreach ( $product_posts as $product_post ) {

			$product = new ewdupcpProduct();

			$product->load_wp_post( $product_post );

			$spreadsheet->getActiveSheet()->setCellValue( 'A' . $row_count, $product->ID );
			$spreadsheet->getActiveSheet()->setCellValue( 'B' . $row_count, $product->name );
			$spreadsheet->getActiveSheet()->setCellValue( 'C' . $row_count, $product->slug );
			$spreadsheet->getActiveSheet()->setCellValue( 'D' . $row_count, $product->description );
			$spreadsheet->getActiveSheet()->setCellValue( 'E' . $row_count, $product->regular_price );
			$spreadsheet->getActiveSheet()->setCellValue( 'F' . $row_count, $product->sale_price );
			$spreadsheet->getActiveSheet()->setCellValue( 'G' . $row_count, $product->get_main_image_url() );
			$spreadsheet->getActiveSheet()->setCellValue( 'H' . $row_count, $product->link );
			$spreadsheet->getActiveSheet()->setCellValue( 'I' . $row_count, $product->get_category_names() );
			$spreadsheet->getActiveSheet()->setCellValue( 'J' . $row_count, $product->get_subcategory_names() );
			$spreadsheet->getActiveSheet()->setCellValue( 'K' . $row_count, $product->get_tag_names() );

			$column = 'L';
			foreach ( $custom_fields as $custom_field ) {

				$value = is_array( $product->custom_fields[ $custom_field->id ] )
					? implode( ',', $product->custom_fields[ $custom_field->id ] )
					: $product->custom_fields[ $custom_field->id ];

				$spreadsheet->getActiveSheet()->setCellValue( $column . $row_count, $value );

				$column++;
			}

			$product_images = is_array( get_post_meta( $product->ID, 'product_images', true ) ) ? get_post_meta( $product->ID, 'product_images', true ) : array();
			$product_images = array_slice( $product_images, 0, 5 );

			foreach ( $product_images as $product_image ) {

				$spreadsheet->getActiveSheet()->setCellValue( $column . $row_count, $product_image->url );

				$column++;
			}

			$row_count++;
		}

		// Redirect output to a client’s web browser (Excel5)
		if ( ! isset( $format_type ) == 'csv' ) {

			ob_clean();

			header('Content-Type: application/vnd.ms-excel');
			header('Content-Disposition: attachment;filename="products_export.csv"');
			header('Cache-Control: max-age=0');
			$objWriter = new Csv($spreadsheet);
			$objWriter->save('php://output');
			die();
		}
		else {

			ob_clean();

			header('Content-Type: application/vnd.ms-excel');
			header('Content-Disposition: attachment;filename="products_export.xls"');
			header('Cache-Control: max-age=0');
			$objWriter = new Xls($spreadsheet);
			$objWriter->save('php://output');
			die();
		}
	}

}


