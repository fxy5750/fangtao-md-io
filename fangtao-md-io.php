<?php
/**
 * Plugin Name: Fangtao MD IO
 * Description: Import and export Markdown documents, ZIP archives, and local media assets.
 * Version: 1.9.17
 * Author: Fangtao
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fangtao-md-io
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FTMZI_VERSION', '1.9.17' );
define( 'FTMZI_FILE', __FILE__ );
define( 'FTMZI_DIR', plugin_dir_path( __FILE__ ) );
define( 'FTMZI_URL', plugin_dir_url( __FILE__ ) );

$ftmzi_autoload = FTMZI_DIR . 'vendor/autoload.php';

if ( file_exists( $ftmzi_autoload ) ) {
	require_once $ftmzi_autoload;
}

require_once FTMZI_DIR . 'includes/class-ftmzi-markdown.php';
require_once FTMZI_DIR . 'includes/class-ftmzi-importer.php';
require_once FTMZI_DIR . 'includes/class-ftmzi-exporter.php';
require_once FTMZI_DIR . 'includes/class-ftmzi-admin.php';

add_filter(
	'plugin_action_links_' . plugin_basename( FTMZI_FILE ),
	static function ( $links ) {
		$settings_link = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( admin_url( 'admin.php?page=' . FTMZI_Admin::PAGE_SLUG ) ),
			esc_html__( 'Settings', 'fangtao-md-io' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}
);

add_action(
	'plugins_loaded',
	static function () {
		FTMZI_Admin::instance();
	}
);
