<?php
/** Bounded spreadsheet boundary reused from the maintained family importer. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class ewdupcpSpreadsheetImport {
		const BATCH_ROWS                    = 1000;
		const MAX_ARCHIVE_COMPRESSION_RATIO = 100;
		const MAX_ARCHIVE_ENTRIES           = 2048;
		const MAX_ARCHIVE_ENTRY_BYTES       = 33554432;
		const MAX_ARCHIVE_TOTAL_BYTES       = 67108864;
		const MAX_BYTES                     = 10485760;
		const MAX_CELL_CHARACTERS           = 32767;
		const MAX_COLUMNS                   = 100;
		const MAX_ROWS                      = 10000;
		const MAX_SHARED_STRINGS            = 20000;
		const MAX_RICH_TEXT_RUNS            = 5000;
		const MAX_PARSER_BYTES              = 33554432;
		const PARSER_RESERVE_BYTES          = 67108864;
		private $parser_estimate            = 0;
		private $parser_budget              = 0;
		private $rich_text_nodes            = 0;

	public function prepare_upload() {
		global $ewd_upcp_controller;
		if ( ! current_user_can( 'edit_upcp_products' ) || ! $ewd_upcp_controller->permissions->check_permission( 'import' )
			|| ! isset( $_POST['EWD_UPCP_Import_Nonce'] ) || ! is_string( $_POST['EWD_UPCP_Import_Nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['EWD_UPCP_Import_Nonce'] ) ), 'EWD_UPCP_Import' ) ) {
			return new WP_Error( 'import_denied', __( 'This import request is not authorized.', 'ultimate-product-catalogue' ) );
		}

		if ( empty( $_FILES['ewd_upcp_products_spreadsheet'] ) || ! is_array( $_FILES['ewd_upcp_products_spreadsheet'] ) ) {
			return new WP_Error( 'missing_upload', __( 'No spreadsheet was uploaded.', 'ultimate-product-catalogue' ) );
		}

		$file = $_FILES['ewd_upcp_products_spreadsheet'];
		foreach ( array( 'error', 'size', 'tmp_name', 'name' ) as $field ) {
			if ( ! isset( $file[ $field ] ) || ! is_scalar( $file[ $field ] ) ) {
				return new WP_Error( 'invalid_upload', __( 'The uploaded file could not be verified.', 'ultimate-product-catalogue' ) );
			}
		}
		$error     = isset( $file['error'] ) ? absint( $file['error'] ) : UPLOAD_ERR_NO_FILE;
		$size      = isset( $file['size'] ) ? absint( $file['size'] ) : 0;
		$tmp       = isset( $file['tmp_name'] ) ? $file['tmp_name'] : '';
		$name      = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '';
		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$readers   = array(
			'csv'  => 'Csv',
			'xls'  => 'Xls',
			'xlsx' => 'Xlsx',
		);

		if ( UPLOAD_ERR_OK !== $error ) {
			return new WP_Error(
				'upload_error',
				sprintf(
					/* translators: %d: PHP upload error code. */
					__( 'The upload failed with error code %d.', 'ultimate-product-catalogue' ),
					$error
				)
			);
		}

		if ( ! isset( $readers[ $extension ] ) ) {
			return new WP_Error( 'invalid_extension', __( 'File must be CSV, XLS, or XLSX.', 'ultimate-product-catalogue' ) );
		}

		if ( ! $size || $size > min( self::MAX_BYTES, wp_max_upload_size() ) ) {
			return new WP_Error( 'invalid_size', __( 'The spreadsheet must be larger than zero bytes and no larger than 10 MiB.', 'ultimate-product-catalogue' ) );
		}

		if ( ! $tmp || ! is_uploaded_file( $tmp ) ) {
			return new WP_Error( 'invalid_upload', __( 'The uploaded file could not be verified.', 'ultimate-product-catalogue' ) );
		}

		if ( ! $this->valid_mime( $tmp, $extension ) ) {
			return new WP_Error( 'invalid_mime', __( 'The uploaded file MIME type does not match an allowed spreadsheet format.', 'ultimate-product-catalogue' ) );
		}

		if ( ! $this->valid_signature( $tmp, $extension ) ) {
			return new WP_Error( 'invalid_signature', __( 'The file contents do not match the selected spreadsheet format.', 'ultimate-product-catalogue' ) );
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Use WordPress's upload handling without publishing a customer spreadsheet.
		$temporary_upload = static function ( $directory ) {
			$directory['path']    = untrailingslashit( get_temp_dir() );
			$directory['basedir'] = $directory['path'];
			$directory['subdir']  = '';
			$directory['url']     = '';
			$directory['baseurl'] = '';
			return $directory;
		};
			add_filter( 'upload_dir', $temporary_upload, PHP_INT_MAX );
		try {
			$handled = wp_handle_upload(
				$file,
				array(
					'test_form' => false,
					'test_type' => false,
				)
			);
		} finally {
			remove_filter( 'upload_dir', $temporary_upload, PHP_INT_MAX );
		}

		if ( ! is_array( $handled ) || empty( $handled['file'] ) || ! empty( $handled['error'] ) ) {
			return new WP_Error( 'temporary_upload', __( 'The spreadsheet could not be moved to secure temporary storage.', 'ultimate-product-catalogue' ) );
		}

			return array(
				'path'   => $handled['file'],
				'reader' => $readers[ $extension ],
			);
	}

	private function valid_mime( $path, $extension ) {

		if ( ! function_exists( 'finfo_open' ) ) {
			return false;
		}

		$finfo = finfo_open( FILEINFO_MIME_TYPE );

		if ( ! $finfo ) {
			return false;
		}

		$mime = finfo_file( $finfo, $path );
		// finfo objects release resources automatically, including on PHP 8.5.
		unset( $finfo );

		$allowed = array(
			'csv'  => array( 'text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel' ),
			'xls'  => array( 'application/vnd.ms-excel', 'application/x-ole-storage' ),
			'xlsx' => array( 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip' ),
		);

		return isset( $allowed[ $extension ] ) && in_array( strtolower( (string) $mime ), $allowed[ $extension ], true );
	}

	private function valid_signature( $path, $extension ) {

		$handle = fopen( $path, 'rb' );

		if ( ! $handle ) {
			return false;
		}

		$bytes = fread( $handle, 8192 );
		fclose( $handle );

		if ( 'xlsx' === $extension ) {
			return 0 === strncmp( $bytes, "PK\x03\x04", 4 );
		}

		if ( 'xls' === $extension ) {
			return 0 === strncmp( $bytes, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8 );
		}

		return false === strpos( $bytes, "\0" );
	}

		/**
		 * Validate container resources and worksheet dimensions before loading.
		 *
		 * @param string $path        Temporary file path.
		 * @param string $reader_type Explicit reader type.
		 * @param object $reader      PhpSpreadsheet reader.
		 * @return array|WP_Error
		 */
	public function inspect_file( $path, $reader_type, $reader ) {
		$this->parser_estimate = 0;
		$this->rich_text_nodes = 0;
		$memory_limit          = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		$this->parser_budget   = $memory_limit > 0
			? min( self::MAX_PARSER_BYTES, max( 0, $memory_limit - memory_get_usage( true ) - self::PARSER_RESERVE_BYTES ) )
			: self::MAX_PARSER_BYTES;

		if ( 'Xlsx' === $reader_type ) {
			$container = $this->validate_xlsx_archive( $path );
		} elseif ( 'Xls' === $reader_type ) {
			$container = $this->validate_xls_container( $path );
		} else {
			$container = $this->validate_csv_stream( $path );
		}

		if ( is_wp_error( $container ) ) {
			return $container;
		}

		$worksheets = $reader->listWorksheetInfo( $path );

		if ( empty( $worksheets[0] ) ) {
			return new WP_Error( 'empty_workbook', __( 'The spreadsheet does not contain a readable worksheet.', 'ultimate-product-catalogue' ) );
		}

		if ( count( $worksheets ) > self::MAX_COLUMNS ) {
			return new WP_Error( 'worksheet_count', __( 'The spreadsheet contains too many worksheets.', 'ultimate-product-catalogue' ) );
		}

		$active_name = isset( $worksheets[0]['worksheetName'] ) ? $worksheets[0]['worksheetName'] : '';

		if ( count( $worksheets ) > 1 ) {
			$reader->setReadFilter( new ewdupcpImportReadFilter( 1, 1 ) );
			$metadata_workbook = $reader->load( $path );
			$active_name       = $metadata_workbook->getActiveSheet()->getTitle();
			$metadata_workbook->disconnectWorksheets();
			unset( $metadata_workbook );
		}

		$worksheet = $worksheets[0];

		foreach ( $worksheets as $candidate ) {
			if ( isset( $candidate['worksheetName'] ) && $active_name === $candidate['worksheetName'] ) {
				$worksheet = $candidate;
				break;
			}
		}

		$rows    = absint( isset( $worksheet['totalRows'] ) ? $worksheet['totalRows'] : 0 );
		$columns = absint( isset( $worksheet['totalColumns'] ) ? $worksheet['totalColumns'] : 0 );

		if ( $rows < 2 ) {
			return new WP_Error( 'empty_sheet', __( 'The spreadsheet contains no product rows.', 'ultimate-product-catalogue' ) );
		}

		if ( $rows - 1 > self::MAX_ROWS || $columns > self::MAX_COLUMNS ) {
			return new WP_Error( 'sheet_dimensions', __( 'The spreadsheet exceeds the limit of 10,000 data rows or 100 columns; nothing was imported.', 'ultimate-product-catalogue' ) );
		}

		return array(
			'columns'    => $columns,
			'rows'       => $rows,
			'sheet_name' => $active_name,
		);
	}

	private function validate_csv_stream( $path ) {

		$handle = fopen( $path, 'rb' );

		if ( ! $handle ) {
			return new WP_Error( 'csv_unreadable', __( 'The CSV file could not be read safely.', 'ultimate-product-catalogue' ) );
		}

		$row = 0;

		try {
			while ( true ) {
				$values = fgetcsv( $handle, 0, ',', '"', '' );

				if ( false === $values ) {
					break;
				}

				++$row;

				if ( $row > self::MAX_ROWS + 1 || count( $values ) > self::MAX_COLUMNS ) {
					return new WP_Error( 'sheet_dimensions', __( 'The spreadsheet exceeds the limit of 10,000 data rows or 100 columns; nothing was imported.', 'ultimate-product-catalogue' ) );
				}

				foreach ( $values as $value ) {
					$valid = $this->validate_raw_cell( $value, false );

					if ( is_wp_error( $valid ) ) {
						return $valid;
					}
				}
			}
		} finally {
			fclose( $handle );
		}

		return true;
	}

	private function validate_xls_container( $path ) {

		$contents = file_get_contents( $path );

		if ( false === $contents || strlen( $contents ) > self::MAX_BYTES ) {
			return new WP_Error( 'xls_unreadable', __( 'The XLS file could not be read safely.', 'ultimate-product-catalogue' ) );
		}

		$blocked = array( 'EncryptedPackage', 'EncryptionInfo', '_VBA_PROJECT_CUR', 'Macros' );

		foreach ( $blocked as $marker ) {
			$utf16_marker = implode( "\0", str_split( $marker ) ) . "\0";

			if ( false !== stripos( $contents, $marker ) || false !== strpos( $contents, $utf16_marker ) ) {
				return new WP_Error( 'unsupported_xls_feature', __( 'Encrypted spreadsheets and spreadsheets containing macros are not supported.', 'ultimate-product-catalogue' ) );
			}
		}

		return true;
	}

	private function validate_xlsx_archive( $path ) {

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip_unavailable', __( 'XLSX import requires the PHP ZIP extension.', 'ultimate-product-catalogue' ) );
		}

		$zip      = new ZipArchive();
		$readonly = defined( 'ZipArchive::RDONLY' ) ? ZipArchive::RDONLY : 0;
		$opened   = $zip->open( $path, $readonly );

		if ( true !== $opened ) {
			return new WP_Error( 'invalid_xlsx_archive', __( 'The XLSX archive is malformed or unsupported.', 'ultimate-product-catalogue' ) );
		}

		$required = array(
			'[content_types].xml' => false,
			'xl/workbook.xml'     => false,
		);
		$total    = 0;

		try {
			$num_files = $zip->numFiles; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Vendor API property.

			if ( $num_files > self::MAX_ARCHIVE_ENTRIES ) {
				return new WP_Error( 'archive_entries', __( 'The XLSX archive contains too many entries.', 'ultimate-product-catalogue' ) );
			}

			for ( $index = 0; $index < $num_files; $index++ ) {
				$stat = $zip->statIndex( $index );

				if ( false === $stat || ! isset( $stat['name'], $stat['size'], $stat['comp_size'] ) ) {
					return new WP_Error( 'archive_entry', __( 'The XLSX archive contains an unreadable entry.', 'ultimate-product-catalogue' ) );
				}

				$name       = str_replace( '\\', '/', $stat['name'] );
				$lower_name = strtolower( $name );
				$size       = absint( $stat['size'] );
				$compressed = absint( $stat['comp_size'] );

				if (
					'' === $name
					|| '/' === substr( $name, 0, 1 )
					|| preg_match( '#(^|/)\.\.(/|$)#', $name )
					|| false !== strpos( $name, "\0" )
				) {
					return new WP_Error( 'archive_path', __( 'The XLSX archive contains an unsafe path.', 'ultimate-product-catalogue' ) );
				}

				if ( $size > self::MAX_ARCHIVE_ENTRY_BYTES || $total + $size > self::MAX_ARCHIVE_TOTAL_BYTES ) {
					return new WP_Error( 'archive_expansion', __( 'The XLSX archive expands beyond the safe import limit.', 'ultimate-product-catalogue' ) );
				}

				if (
					$size > 0
					&& ( 0 === $compressed || $size / $compressed > self::MAX_ARCHIVE_COMPRESSION_RATIO )
				) {
					return new WP_Error( 'archive_ratio', __( 'The XLSX archive has an unsafe compression ratio.', 'ultimate-product-catalogue' ) );
				}

				if (
					( isset( $stat['encryption_method'] ) && 0 !== absint( $stat['encryption_method'] ) )
					|| ( isset( $stat['flags'] ) && ( absint( $stat['flags'] ) & 1 ) )
				) {
					return new WP_Error( 'encrypted_archive', __( 'Encrypted spreadsheets are not supported.', 'ultimate-product-catalogue' ) );
				}

				$blocked_prefixes = array(
					'xl/activex/',
					'xl/charts/',
					'xl/ctrlprops/',
					'xl/drawings/',
					'xl/embeddings/',
					'xl/externallinks/',
					'xl/macrosheets/',
				);

				foreach ( $blocked_prefixes as $prefix ) {
					if ( 0 === strpos( $lower_name, $prefix ) ) {
						return new WP_Error( 'unsupported_xlsx_feature', __( 'Drawings, charts, macros, embedded files, and external workbook links are not supported.', 'ultimate-product-catalogue' ) );
					}
				}

				if ( false !== strpos( $lower_name, 'vbaproject' ) ) {
					return new WP_Error( 'unsupported_xlsx_feature', __( 'Drawings, charts, macros, embedded files, and external workbook links are not supported.', 'ultimate-product-catalogue' ) );
				}

				if ( isset( $required[ $lower_name ] ) ) {
					$required[ $lower_name ] = true;
				}

				$total += $size;

				if ( preg_match( '/\.(xml|rels)$/i', $name ) ) {
					// SimpleXML and vendor metadata/readers materialize XML even with row filters.
					$this->parser_estimate += $size * 8;
					if ( $this->parser_estimate > $this->parser_budget ) {
						return $this->parser_memory_error();
					}
					$xml_check = $this->validate_archive_xml_entry( $zip, $index );

					if ( is_wp_error( $xml_check ) ) {
						return $xml_check;
					}
					// Relationship targets need not use the conventional worksheet directory.
					$text_check = $this->validate_xlsx_text( $zip->getFromIndex( $index ), 'xl/sharedstrings.xml' === $lower_name );
					if ( is_wp_error( $text_check ) ) {
						return $text_check; }
				}
			}

			if ( in_array( false, $required, true ) ) {
				return new WP_Error( 'invalid_xlsx_structure', __( 'The XLSX archive is missing required workbook data.', 'ultimate-product-catalogue' ) );
			}
		} finally {
			$zip->close();
		}

		return true;
	}

	/** Reject strings before the vendor reader can silently truncate them. */
	private function validate_xlsx_text( $xml, $shared_strings = false ) {
		if ( ! is_string( $xml ) || ! class_exists( 'XMLReader' ) ) {
			return new WP_Error( 'xml_unavailable', __( 'XLSX import requires the XMLReader extension.', 'ultimate-product-catalogue' ) ); }
		$reader          = new XMLReader();
		$previous_errors = libxml_use_internal_errors( true );
		try {
			if ( ! $reader->XML( $xml, null, LIBXML_NONET ) ) {
				return new WP_Error( 'invalid_xml', __( 'The spreadsheet XML is malformed.', 'ultimate-product-catalogue' ) ); }
			$reader->setParserProperty( XMLReader::LOADDTD, false );
			$reader->setParserProperty( XMLReader::SUBST_ENTITIES, false );
			$length       = 0;
			$string_count = 0;
			while ( $reader->read() ) {
				$node_type  = $reader->nodeType; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native XMLReader API.
				$local_name = $reader->localName; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native XMLReader API.
				if ( XMLReader::ELEMENT === $node_type && 'sst' === $local_name ) {
					$shared_strings = true;
				}
				if ( XMLReader::DOC_TYPE === $node_type || XMLReader::ENTITY_REF === $node_type ) {
					return new WP_Error( 'unsafe_xml', __( 'The spreadsheet contains unsupported XML declarations.', 'ultimate-product-catalogue' ) ); }
				if ( XMLReader::ELEMENT !== $node_type ) {
					continue; }
				if ( $shared_strings ) {
					if ( 'si' === $local_name ) {
						++$string_count;
						$this->parser_estimate += 256;
					}
					if ( $string_count > self::MAX_SHARED_STRINGS || $this->parser_estimate > $this->parser_budget ) {
						return $this->parser_memory_error();
					}
				}
				// The reader materializes rich text in both shared strings and inline cells.
				if ( 'r' === $local_name || 'rPr' === $local_name ) {
					++$this->rich_text_nodes;
					$this->parser_estimate += 4096;
					if ( $this->rich_text_nodes > self::MAX_RICH_TEXT_RUNS || $this->parser_estimate > $this->parser_budget ) {
						return $this->parser_memory_error();
					}
				}
				if ( in_array( $local_name, array( 'si', 'is', 'c' ), true ) ) {
					$length = 0; }
				if ( 'c' === $local_name ) {
					$address = $reader->getAttribute( 'r' );
					if ( $address && ( ! preg_match( '/^([A-Z]{1,3})([1-9][0-9]{0,4})$/D', $address, $parts ) || (int) $parts[2] > self::MAX_ROWS + 1 || \EwdUpcpVendor\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString( $parts[1] ) > self::MAX_COLUMNS ) ) {
						return new WP_Error( 'sheet_dimensions', __( 'The spreadsheet exceeds the safe row or column limits.', 'ultimate-product-catalogue' ) );
					}
				}
				if ( in_array( $local_name, array( 't', 'v' ), true ) ) {
					$text    = $reader->readString();
					$length += mb_strlen( $text, 'UTF-8' );
					if ( $shared_strings ) {
						$this->parser_estimate += strlen( $text ) * 4;
						if ( $this->parser_estimate > $this->parser_budget ) {
							return $this->parser_memory_error();
						}
					}
					if ( $length > self::MAX_CELL_CHARACTERS ) {
						return new WP_Error( 'cell_too_large', __( 'A spreadsheet cell exceeds the safe limit of 32,767 characters.', 'ultimate-product-catalogue' ) ); }
				}
			}
			if ( libxml_get_errors() ) {
				return new WP_Error( 'invalid_xml', __( 'The spreadsheet XML is malformed.', 'ultimate-product-catalogue' ) ); }
			return true;
		} finally {
			$reader->close();
			libxml_clear_errors();
			libxml_use_internal_errors( $previous_errors );
		}
	}

	private function parser_memory_error() {
		return new WP_Error( 'parser_memory_budget', __( 'The workbook contains too much XML, shared text, or rich-text formatting for a safe import. Save a smaller plain-text workbook or CSV and retry; nothing was imported.', 'ultimate-product-catalogue' ) );
	}

	private function validate_archive_xml_entry( $zip, $index ) {

		$stream = $zip->getStream( $zip->getNameIndex( $index ) );

		if ( ! is_resource( $stream ) ) {
			return new WP_Error( 'archive_xml', __( 'The XLSX archive contains unreadable XML.', 'ultimate-product-catalogue' ) );
		}

		$carry    = '';
		$patterns = array( '<!DOCTYPE', '<!ENTITY' );

		try {
			while ( ! feof( $stream ) ) {
				$chunk = fread( $stream, 8192 );

				if ( false === $chunk ) {
					return new WP_Error( 'archive_xml', __( 'The XLSX archive contains unreadable XML.', 'ultimate-product-catalogue' ) );
				}

				$scan = strtoupper( $carry . $chunk );

				foreach ( $patterns as $pattern ) {
					if ( false !== strpos( $scan, $pattern ) ) {
						return new WP_Error( 'unsafe_archive_xml', __( 'The XLSX archive contains unsupported XML declarations or external relationships.', 'ultimate-product-catalogue' ) );
					}
				}

				if ( preg_match( '/TARGETMODE\s*=\s*["\']EXTERNAL["\']/i', $scan ) ) {
					return new WP_Error( 'unsafe_archive_xml', __( 'The XLSX archive contains unsupported XML declarations or external relationships.', 'ultimate-product-catalogue' ) );
				}

				$carry = substr( $scan, -64 );
			}
		} finally {
			fclose( $stream );
		}

		return true;
	}

	public function validate_sheet_cells( $sheet, $start, $end, $highest_column_index ) {

		for ( $row = $start; $row <= $end; $row++ ) {
			for ( $column = 1; $column <= $highest_column_index; $column++ ) {
				$value = $this->cell_value( $sheet, $column, $row );

				if ( is_wp_error( $value ) ) {
					return $value;
				}
			}
		}

		return true;
	}

	public function cell_value( $sheet, $column, $row ) {

		if ( empty( $column ) ) {
			return '';
		}

		$cell  = $sheet->getCellByColumnAndRow( $column, $row );
		$value = $cell->getValue();

		if ( is_object( $value ) || is_array( $value ) ) {
			return new WP_Error( 'unsupported_cell', __( 'The spreadsheet contains an unsupported cell value.', 'ultimate-product-catalogue' ) );
		}

		return $this->validate_raw_cell(
			(string) $value,
			\EwdUpcpVendor\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_FORMULA === $cell->getDataType()
		);
	}

	private function validate_raw_cell( $value, $is_formula ) {

		$value  = (string) $value;
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );

		if ( false === $length ) {
			return new WP_Error( 'invalid_cell_encoding', __( 'The spreadsheet contains invalid text encoding.', 'ultimate-product-catalogue' ) );
		}

		if ( $length > self::MAX_CELL_CHARACTERS ) {
			return new WP_Error( 'cell_too_large', __( 'A spreadsheet cell exceeds the limit of 32,767 characters; nothing was imported.', 'ultimate-product-catalogue' ) );
		}

		$trimmed = preg_replace( '/^[\x00-\x20\x7f\x{00a0}]*/u', '', $value );

		if ( null === $trimmed ) {
			return new WP_Error( 'invalid_cell_encoding', __( 'The spreadsheet contains invalid text encoding.', 'ultimate-product-catalogue' ) );
		}

		if ( $is_formula || ( '' !== $trimmed && in_array( substr( $trimmed, 0, 1 ), array( '=', '+', '-', '@' ), true ) ) ) {
			return new WP_Error( 'formula_cell', __( 'Spreadsheet formulas and formula-like cell values are not imported.', 'ultimate-product-catalogue' ) );
		}

		return $value;
	}
}
