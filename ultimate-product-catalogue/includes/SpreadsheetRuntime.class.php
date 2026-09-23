<?php
/**
 * Verify that imports use UPCP's pinned PhpSpreadsheet runtime.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ewdupcpSpreadsheetRuntime' ) ) {
	class ewdupcpSpreadsheetRuntime {

		private static $trusted;

		public static function is_trusted() {

			if ( null !== self::$trusted ) {
				return self::$trusted;
			}

			$autoload = EWD_UPCP_PLUGIN_DIR . '/lib/PHPSpreadsheet/vendor/autoload.php';
			if ( ! is_file( $autoload ) ) {
				self::$trusted = false;
				return false;
			}

			$composer_initializer = 'ComposerAutoloaderInitEwdUpcpSpreadsheet1306';
			$spreadsheet_loaded   = class_exists( '\\PhpOffice\\PhpSpreadsheet\\IOFactory' );

			if ( ! $spreadsheet_loaded && ! class_exists( $composer_initializer, false ) ) {
				require_once $autoload;
			}

			if ( ! class_exists( '\\PhpOffice\\PhpSpreadsheet\\IOFactory' ) ) {
				self::$trusted = false;
				return false;
			}

			$expected   = realpath( EWD_UPCP_PLUGIN_DIR . '/lib/PHPSpreadsheet/vendor/phpoffice/phpspreadsheet' );
			$reflection = new ReflectionClass( '\\PhpOffice\\PhpSpreadsheet\\IOFactory' );
			$loaded     = realpath( $reflection->getFileName() );

			if ( ! $expected || ! $loaded || 0 !== strpos( strtolower( $loaded ), strtolower( $expected . DIRECTORY_SEPARATOR ) ) ) {
				self::$trusted = false;
				return false;
			}

			$installed_path = EWD_UPCP_PLUGIN_DIR . '/lib/PHPSpreadsheet/vendor/composer/installed.json';
			$installed      = is_file( $installed_path ) ? json_decode( file_get_contents( $installed_path ), true ) : array();
			$packages       = isset( $installed['packages'] ) ? $installed['packages'] : $installed;

			foreach ( is_array( $packages ) ? $packages : array() as $package ) {
				if ( isset( $package['name'], $package['version'] ) && 'phpoffice/phpspreadsheet' === $package['name'] && '1.30.6' === $package['version'] ) {
					self::$trusted = true;
					return true;
				}
			}

			self::$trusted = false;
			return false;
		}

		public static function notice() {

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			echo '<div class="notice notice-error"><p>' . esc_html__( 'Ultimate Product Catalog import is disabled because the pinned PhpSpreadsheet 1.30.6 runtime could not be loaded safely. Resolve the plugin class conflict before importing files.', 'ultimate-product-catalogue' ) . '</p></div>';
		}
	}
}
