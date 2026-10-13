<?php
/**
 * Bounded row filter for PhpSpreadsheet readers.
 *
 * @package ultimate-product-catalogue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ewdupcpImportReadFilter' ) ) {
	class ewdupcpImportReadFilter implements \EwdUpcpVendor\PhpOffice\PhpSpreadsheet\Reader\IReadFilter {

		private $end_row;
		private $start_row;

		/**
		 * Set the inclusive data-row window. The header row is always retained.
		 *
		 * @param int $start_row First data row.
		 * @param int $end_row   Last data row.
		 */
		public function __construct( $start_row, $end_row ) {

			$this->start_row = max( 1, absint( $start_row ) );
			$this->end_row   = max( $this->start_row, absint( $end_row ) );
		}

		/**
		 * Determine whether PhpSpreadsheet should materialize a cell.
		 *
		 * @param string $column_address Column address.
		 * @param int    $row            Row number.
		 * @param string $worksheet_name Worksheet name.
		 * @return bool
		 */
		public function readCell( $column_address, $row, $worksheet_name = '' ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

			return 1 === absint( $row ) || ( $row >= $this->start_row && $row <= $this->end_row );
		}
	}
}
