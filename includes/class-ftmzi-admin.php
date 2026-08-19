<?php
/**
 * WordPress admin integration.
 *
 * @package Fangtao_MD_IO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FTMZI_Admin {

	const MENU_SLUG = 'fangtao-md-io';
	const PAGE_SLUG = 'fangtao-md-io-importer';
	const EXPORT_PAGE_SLUG = 'fangtao-md-io-exporter';
	const DEFAULT_STATUS_OPTION = 'ftmzi_default_post_status';
	const DEFAULT_PARSER_OPTION = 'ftmzi_default_markdown_parser';
	const REMOTE_IMAGES_OPTION = 'ftmzi_import_remote_images';
	const IMPORT_LOG_OPTION = 'ftmzi_import_log';
	const IMPORT_LOG_LIMIT = 100;

	/**
	 * Admin screen ID.
	 *
	 * @var string
	 */
	private $screen_id = '';

	/**
	 * Export screen ID.
	 *
	 * @var string
	 */
	private $export_screen_id = '';

	/**
	 * Singleton instance.
	 *
	 * @var FTMZI_Admin|null
	 */
	private static $instance;

	/**
	 * Get singleton instance.
	 *
	 * @return FTMZI_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_ftmzi_import', array( $this, 'handle_import' ) );
		add_action( 'wp_ajax_ftmzi_import_file', array( $this, 'handle_ajax_import_file' ) );
		add_action( 'admin_post_ftmzi_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_ftmzi_filtered_export', array( $this, 'handle_filtered_export' ) );
		add_action( 'admin_post_ftmzi_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_ftmzi_clear_import_log', array( $this, 'handle_clear_import_log' ) );
		add_action( 'admin_post_ftmzi_export_diagnostic_log', array( $this, 'handle_export_diagnostic_log' ) );
		add_action( 'admin_init', array( $this, 'register_export_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_menu_icon_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'post_row_actions', array( $this, 'add_export_row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'add_export_row_action' ), 10, 2 );
	}

	/**
	 * Register admin menu page.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Markdown Import', 'fangtao-md-io' ),
			__( 'Markdown', 'fangtao-md-io' ),
			'edit_posts',
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'none',
			81
		);

		$this->screen_id = add_submenu_page(
			self::MENU_SLUG,
			__( 'Markdown Import', 'fangtao-md-io' ),
			__( 'Markdown Import', 'fangtao-md-io' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		$this->export_screen_id = add_submenu_page(
			self::MENU_SLUG,
			__( 'Markdown Export', 'fangtao-md-io' ),
			__( 'Markdown Export', 'fangtao-md-io' ),
			'edit_posts',
			self::EXPORT_PAGE_SLUG,
			array( $this, 'render_export_page' )
		);

		remove_submenu_page( self::MENU_SLUG, self::MENU_SLUG );
	}

	/**
	 * Enqueue the theme-aware Markdown menu icon.
	 *
	 * @return void
	 */
	public function enqueue_menu_icon_assets() {
		wp_enqueue_style(
			'ftmzi-admin-menu-icon',
			FTMZI_URL . 'assets/menu-icon.css',
			array(),
			FTMZI_VERSION
		);
	}

	/**
	 * Enqueue page assets.
	 *
	 * @param string $hook_suffix Current admin hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( $this->screen_id, $this->export_screen_id ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'ftmzi-admin',
			FTMZI_URL . 'assets/admin.css',
			array(),
			FTMZI_VERSION
		);

		wp_enqueue_script(
			'ftmzi-admin',
			FTMZI_URL . 'assets/admin.js',
			array(),
			FTMZI_VERSION,
			true
		);

		wp_localize_script(
			'ftmzi-admin',
			'ftmziAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'importStats' => $this->get_import_log_summary(),
				'strings' => array(
					'preparing'     => __( 'Preparing %d files.', 'fangtao-md-io' ),
					'importing'     => __( 'Importing: %s', 'fangtao-md-io' ),
					'waiting'       => __( 'Waiting to start import.', 'fangtao-md-io' ),
					'pending'       => __( 'Waiting', 'fangtao-md-io' ),
					'processing'    => __( 'Processing', 'fangtao-md-io' ),
					'success'       => __( 'Imported %d content items.', 'fangtao-md-io' ),
					'partial'       => __( 'Imported %1$d content items; %2$d failed.', 'fangtao-md-io' ),
					'skipped'       => __( 'Skipped: no supported Markdown document was found in the archive.', 'fangtao-md-io' ),
					'failed'        => __( 'Import failed: %s', 'fangtao-md-io' ),
					'networkFailed' => __( 'Request failed. Check the network connection or server log.', 'fangtao-md-io' ),
					'completed'     => __( 'Import complete: %1$d created, %2$d files failed, %3$d files skipped.', 'fangtao-md-io' ),
					'buttonLoading' => __( 'Importing in batches…', 'fangtao-md-io' ),
					'buttonDefault' => __( 'Upload and Import', 'fangtao-md-io' ),
					'clearLogsConfirmation' => __( 'Clear all import statistics and logs? This will not delete posts, media files, or settings.', 'fangtao-md-io' ),
				),
			)
		);
	}

	/**
	 * Register bulk export actions for visible content types.
	 *
	 * @return void
	 */
	public function register_export_actions() {
		foreach ( $this->get_exportable_post_types() as $post_type ) {
			add_filter( 'bulk_actions-edit-' . $post_type->name, array( $this, 'add_bulk_export_action' ) );
			add_filter( 'handle_bulk_actions-edit-' . $post_type->name, array( $this, 'handle_bulk_export' ), 10, 3 );
		}
	}

	/**
	 * Add the Markdown ZIP bulk action.
	 *
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public function add_bulk_export_action( $actions ) {
		$actions['ftmzi_export'] = __( 'Export Markdown ZIP', 'fangtao-md-io' );
		return $actions;
	}

	/**
	 * Handle a Markdown ZIP bulk action.
	 *
	 * @param string     $redirect_to Redirect URL.
	 * @param string     $action      Selected action.
	 * @param array<int> $post_ids    Selected post IDs.
	 * @return string
	 */
	public function handle_bulk_export( $redirect_to, $action, $post_ids ) {
		if ( 'ftmzi_export' !== $action ) {
			return $redirect_to;
		}

		$this->download_export( $post_ids );

		return $redirect_to;
	}

	/**
	 * Add a single-item Markdown export action.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Post object.
	 * @return array
	 */
	public function add_export_row_action( $actions, $post ) {
		$post_types = wp_list_pluck( $this->get_exportable_post_types(), 'name' );

		if (
			! in_array( $post->post_type, $post_types, true ) ||
			'trash' === $post->post_status ||
			! current_user_can( 'edit_post', $post->ID )
		) {
			return $actions;
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'ftmzi_export',
					'post_ids' => array( $post->ID ),
				),
				admin_url( 'admin-post.php' )
			),
			'ftmzi_export',
			'ftmzi_nonce'
		);

		$actions['ftmzi_export'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $url ),
			esc_html__( 'Export Markdown', 'fangtao-md-io' )
		);

		return $actions;
	}

	/**
	 * Handle a single-item export request.
	 *
	 * @return void
	 */
	public function handle_export() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to export content.', 'fangtao-md-io' ) );
		}

		check_admin_referer( 'ftmzi_export', 'ftmzi_nonce' );

		$post_ids = isset( $_GET['post_ids'] ) ? (array) wp_unslash( $_GET['post_ids'] ) : array();
		$post_ids = array_map( 'absint', $post_ids );

		$extension = isset( $_GET['extension'] ) ? strtolower( sanitize_text_field( wp_unslash( $_GET['extension'] ) ) ) : 'md';
		$this->download_export( $post_ids, $extension );
	}

	/**
	 * Handle an export assembled from the export-page filters.
	 *
	 * @return void
	 */
	public function handle_filtered_export() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to export content.', 'fangtao-md-io' ) );
		}

		check_admin_referer( 'ftmzi_filtered_export', 'ftmzi_export_nonce' );

		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : 'post';
		$extension = isset( $_POST['extension'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['extension'] ) ) ) : 'md';
		$post_types = wp_list_pluck( $this->get_exportable_post_types(), 'name' );

		if ( ! in_array( $post_type, $post_types, true ) ) {
			wp_die( esc_html__( 'The selected content type cannot be exported.', 'fangtao-md-io' ) );
		}

		$query_args = array(
			'post_type'              => $post_type,
			'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		if ( 'post' === $post_type ) {
			$category_id = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;
			$tag_id      = isset( $_POST['tag_id'] ) ? absint( $_POST['tag_id'] ) : 0;

			if ( $category_id ) {
				$query_args['cat'] = $category_id;
			}

			if ( $tag_id ) {
				$query_args['tag_id'] = $tag_id;
			}
		}

		$post_ids = get_posts( $query_args );
		$post_ids = array_values(
			array_filter(
				$post_ids,
				static function ( $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				}
			)
		);

		$this->download_export( $post_ids, $extension );
	}

	/**
	 * Build and stream a Markdown ZIP archive.
	 *
	 * @param array<int> $post_ids Post IDs.
	 * @param string     $extension Markdown filename extension.
	 * @return void
	 */
	private function download_export( $post_ids, $extension = 'md' ) {
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) );

		foreach ( $post_ids as $post_id ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				wp_die( esc_html__( 'You do not have permission to export the selected content.', 'fangtao-md-io' ) );
			}
		}

		$exporter = new FTMZI_Exporter();
		$result   = $exporter->create_archive( $post_ids, $extension );

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $result['filename'] ) . '"' );
		header( 'Content-Length: ' . filesize( $result['path'] ) );
		readfile( $result['path'] );
		@unlink( $result['path'] );
		exit;
	}

	/**
	 * Handle import request.
	 *
	 * @return void
	 */
	public function handle_import() {
		if ( ! current_user_can( 'edit_posts' ) || ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You do not have permission to import content.', 'fangtao-md-io' ) );
		}

		check_admin_referer( 'ftmzi_import_archive', 'ftmzi_nonce' );

		$options = $this->get_import_options();

		if ( is_wp_error( $options ) ) {
			$this->redirect_with_result( $options );
		}

		$uploads = $this->get_import_uploads();

		if ( empty( $uploads ) ) {
			$this->redirect_with_result( new WP_Error( 'ftmzi_missing_upload', __( 'Please choose at least one Markdown or ZIP file.', 'fangtao-md-io' ) ) );
		}

		$importer             = new FTMZI_Importer();
		$import_remote_images = (bool) get_option( self::REMOTE_IMAGES_OPTION, false );
		$result               = array(
			'created'  => array(),
			'failed'   => array(),
			'warnings' => array(),
			'skipped'  => array(),
		);

		foreach ( $uploads as $upload ) {
			$started_at    = microtime( true );
			$file_name     = sanitize_file_name( wp_basename( (string) $upload['name'] ) );
			$import_result = $importer->import( $upload, $options['post_type'], $options['post_status'], $options['category_id'], $import_remote_images, $options['markdown_parser'], $options['post_date'], $options['post_password'] );
			$this->record_import_log(
				$file_name,
				$import_result,
				$this->get_import_log_context( $upload, $options, 'form', $import_remote_images, $started_at )
			);

			if ( is_wp_error( $import_result ) ) {
				if ( 'ftmzi_no_markdown' === $import_result->get_error_code() ) {
					$result['skipped'][] = $file_name;
				} else {
					$result['failed'][] = array(
						'file'    => $file_name,
						'message' => $import_result->get_error_message(),
					);
				}

				continue;
			}

			foreach ( array( 'created', 'failed', 'warnings' ) as $key ) {
				if ( ! empty( $import_result[ $key ] ) ) {
					$result[ $key ] = array_merge( $result[ $key ], $import_result[ $key ] );
				}
			}
		}

		$this->redirect_with_result( $result );
	}

	/**
	 * Import one queued file through an authenticated admin request.
	 *
	 * @return void
	 */
	public function handle_ajax_import_file() {
		if ( ! current_user_can( 'edit_posts' ) || ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import content.', 'fangtao-md-io' ) ), 403 );
		}

		check_ajax_referer( 'ftmzi_import_archive', 'ftmzi_nonce' );

		$options = $this->get_import_options();

		if ( is_wp_error( $options ) ) {
			wp_send_json_error( array( 'message' => $options->get_error_message() ), 400 );
		}

		$uploads = $this->get_import_uploads();

		if ( 1 !== count( $uploads ) ) {
			wp_send_json_error( array( 'message' => __( 'Please choose a Markdown or ZIP file.', 'fangtao-md-io' ) ), 400 );
		}

		$upload               = $uploads[0];
		$file_name            = sanitize_file_name( wp_basename( (string) $upload['name'] ) );
		$importer             = new FTMZI_Importer();
		$import_remote_images = (bool) get_option( self::REMOTE_IMAGES_OPTION, false );
		$started_at           = microtime( true );
		$import_result        = $importer->import(
			$upload,
			$options['post_type'],
			$options['post_status'],
			$options['category_id'],
			$import_remote_images,
			$options['markdown_parser'],
			$options['post_date'],
			$options['post_password']
		);
		$this->record_import_log(
			$file_name,
			$import_result,
			$this->get_import_log_context( $upload, $options, 'batch', $import_remote_images, $started_at )
		);

		if ( is_wp_error( $import_result ) ) {
			wp_send_json_success(
				array(
					'status'  => 'ftmzi_no_markdown' === $import_result->get_error_code() ? 'skipped' : 'failed',
					'file'    => $file_name,
					'message' => $import_result->get_error_message(),
				)
			);
		}

		wp_send_json_success(
			array(
				'status'   => empty( $import_result['failed'] ) ? 'success' : 'partial',
				'file'     => $file_name,
				'created'  => isset( $import_result['created'] ) ? count( $import_result['created'] ) : 0,
				'failed'   => isset( $import_result['failed'] ) ? count( $import_result['failed'] ) : 0,
				'warnings' => isset( $import_result['warnings'] ) ? count( $import_result['warnings'] ) : 0,
			)
		);
	}

	/**
	 * Validate the destination options shared by regular and queued imports.
	 *
	 * @return array|WP_Error
	 */
	private function get_import_options() {
		$post_type      = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : 'post';
		$post_status    = isset( $_POST['post_status'] ) ? sanitize_key( wp_unslash( $_POST['post_status'] ) ) : 'draft';
		$category_id    = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;
		$post_date      = $this->sanitize_import_date( isset( $_POST['post_date'] ) ? wp_unslash( $_POST['post_date'] ) : '' );
		$use_password   = isset( $_POST['use_post_password'] );
		$post_password  = $use_password && isset( $_POST['post_password'] )
			? substr( sanitize_text_field( wp_unslash( $_POST['post_password'] ) ), 0, 255 )
			: '';
		$is_private     = isset( $_POST['post_private'] );
		$markdown_parser = isset( $_POST['markdown_parser'] )
			? FTMZI_Markdown::sanitize_parser( wp_unslash( $_POST['markdown_parser'] ) )
			: FTMZI_Markdown::sanitize_parser( get_option( self::DEFAULT_PARSER_OPTION, FTMZI_Markdown::DEFAULT_PARSER ) );
		$post_object    = get_post_type_object( $post_type );

		if ( ! $post_object || empty( $post_object->show_ui ) || ! current_user_can( $post_object->cap->edit_posts ) ) {
			return new WP_Error( 'ftmzi_post_type', __( 'You do not have permission to import into the selected content type.', 'fangtao-md-io' ) );
		}

		if ( is_wp_error( $post_date ) ) {
			return $post_date;
		}

		if ( $use_password && '' === $post_password ) {
			return new WP_Error( 'ftmzi_post_password', __( 'Please enter an access password.', 'fangtao-md-io' ) );
		}

		if ( $is_private && $use_password ) {
			return new WP_Error( 'ftmzi_private_password', __( 'Private posts cannot use an access password at the same time.', 'fangtao-md-io' ) );
		}

		if ( 'publish' === $post_status && ! current_user_can( $post_object->cap->publish_posts ) ) {
			$post_status = 'draft';
		}

		if ( ! in_array( $post_status, array( 'draft', 'publish' ), true ) ) {
			$post_status = 'draft';
		}

		if ( $is_private ) {
			if ( ! current_user_can( $post_object->cap->publish_posts ) ) {
				return new WP_Error( 'ftmzi_private_post', __( 'You do not have permission to make posts private.', 'fangtao-md-io' ) );
			}

			$post_status = 'private';
		}

		if ( 'post' !== $post_type || ( $category_id && ! term_exists( $category_id, 'category' ) ) ) {
			$category_id = 0;
		}

		return array(
			'post_type'       => $post_type,
			'post_status'     => $post_status,
			'category_id'     => $category_id,
			'post_date'       => $post_date,
			'post_password'   => $post_password,
			'markdown_parser' => $markdown_parser,
		);
	}

	/**
	 * Normalize one or more files submitted by the import form.
	 *
	 * @return array<int, array>
	 */
	private function get_import_uploads() {
		if ( ! isset( $_FILES['markdown_zip'] ) || ! is_array( $_FILES['markdown_zip'] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File metadata is validated by the importer before use.
		$uploads = $_FILES['markdown_zip'];

		if ( ! isset( $uploads['name'] ) || ! is_array( $uploads['name'] ) ) {
			return array( $uploads );
		}

		$normalized = array();

		foreach ( $uploads['name'] as $index => $name ) {
			$error = isset( $uploads['error'][ $index ] ) ? (int) $uploads['error'][ $index ] : UPLOAD_ERR_NO_FILE;

			if ( UPLOAD_ERR_NO_FILE === $error && '' === (string) $name ) {
				continue;
			}

			$normalized[] = array(
				'name'     => $name,
				'type'     => isset( $uploads['type'][ $index ] ) ? $uploads['type'][ $index ] : '',
				'tmp_name' => isset( $uploads['tmp_name'][ $index ] ) ? $uploads['tmp_name'][ $index ] : '',
				'error'    => $error,
				'size'     => isset( $uploads['size'][ $index ] ) ? $uploads['size'][ $index ] : 0,
			);
		}

		return $normalized;
	}

	/**
	 * Normalize an optional import date submitted by the admin form.
	 *
	 * @param mixed $value Submitted local date and time.
	 * @return string|WP_Error
	 */
	private function sanitize_import_date( $value ) {
		$value = sanitize_text_field( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		$date = date_create_from_format( '!Y-m-d\\TH:i:s', $value, wp_timezone() );

		if ( false === $date || $date->format( 'Y-m-d\\TH:i:s' ) !== $value ) {
			return new WP_Error( 'ftmzi_post_date', __( 'The post date format is invalid.', 'fangtao-md-io' ) );
		}

		return $date->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Save importer settings.
	 *
	 * @return void
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change plugin settings.', 'fangtao-md-io' ) );
		}

		check_admin_referer( 'ftmzi_save_settings', 'ftmzi_settings_nonce' );

		$default_status       = isset( $_POST['default_post_status'] ) ? sanitize_key( wp_unslash( $_POST['default_post_status'] ) ) : 'draft';
		$default_parser       = isset( $_POST['default_markdown_parser'] )
			? FTMZI_Markdown::sanitize_parser( wp_unslash( $_POST['default_markdown_parser'] ) )
			: FTMZI_Markdown::DEFAULT_PARSER;
		$import_remote_images = isset( $_POST['import_remote_images'] ) ? 1 : 0;
		$ignore_xmp_metadata  = isset( $_POST['ignore_xmp_metadata'] ) ? 1 : 0;
		$asset_extensions     = isset( $_POST['allowed_asset_extensions'] )
			? FTMZI_Importer::sanitize_asset_extensions( wp_unslash( $_POST['allowed_asset_extensions'] ) )
			: array();
		$size_options         = array(
			FTMZI_Importer::MAX_ARCHIVE_SIZE_OPTION   => 'max_archive_size_mb',
			FTMZI_Importer::MAX_EXTRACTED_SIZE_OPTION => 'max_extracted_size_mb',
			FTMZI_Importer::MAX_MARKDOWN_SIZE_OPTION  => 'max_markdown_size_mb',
			FTMZI_Importer::MAX_ASSET_SIZE_OPTION     => 'max_asset_size_mb',
		);

		if ( ! in_array( $default_status, array( 'draft', 'publish' ), true ) ) {
			$default_status = 'draft';
		}

		update_option( self::DEFAULT_STATUS_OPTION, $default_status, false );
		update_option( self::DEFAULT_PARSER_OPTION, $default_parser, false );
		update_option( self::REMOTE_IMAGES_OPTION, $import_remote_images, false );
		update_option( FTMZI_Importer::IGNORE_XMP_METADATA_OPTION, $ignore_xmp_metadata, false );
		update_option( FTMZI_Importer::ALLOWED_ASSET_EXTENSIONS_OPTION, $asset_extensions, false );

		foreach ( $size_options as $option_name => $field_name ) {
			$value = isset( $_POST[ $field_name ] ) ? absint( $_POST[ $field_name ] ) : 0;
			update_option( $option_name, min( $value, 102400 ), false );
		}

		$max_entries = isset( $_POST['max_archive_entries'] ) ? absint( $_POST['max_archive_entries'] ) : 0;
		update_option( FTMZI_Importer::MAX_ARCHIVE_ENTRIES_OPTION, min( $max_entries, 10000 ), false );

		wp_safe_redirect(
			add_query_arg(
				'settings-updated',
				'1',
				admin_url( 'admin.php?page=' . self::PAGE_SLUG )
			)
		);
		exit;
	}

	/**
	 * Clear only the persistent import statistics and recent import records.
	 *
	 * @return void
	 */
	public function handle_clear_import_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change plugin settings.', 'fangtao-md-io' ) );
		}

		check_admin_referer( 'ftmzi_clear_import_log', 'ftmzi_clear_import_log_nonce' );
		delete_option( self::IMPORT_LOG_OPTION );

		wp_safe_redirect(
			add_query_arg(
				'logs-cleared',
				'1',
				admin_url( 'admin.php?page=' . self::PAGE_SLUG )
			)
		);
		exit;
	}

	/**
	 * Download a privacy-conscious diagnostic report for import troubleshooting.
	 *
	 * @return void
	 */
	public function handle_export_diagnostic_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view diagnostic information.', 'fangtao-md-io' ) );
		}

		check_admin_referer( 'ftmzi_export_diagnostic_log', 'ftmzi_diagnostic_nonce' );

		$report = wp_json_encode(
			$this->get_diagnostic_report(),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		if ( false === $report ) {
			wp_die( esc_html__( 'The diagnostic report could not be generated.', 'fangtao-md-io' ) );
		}

		$filename = 'fangtao-md-io-diagnostics-' . wp_date( 'Ymd-His' ) . '.json';
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $report ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download is encoded above and is not rendered as HTML.
		echo $report;
		exit;
	}

	/**
	 * Record a completed import without retaining imported content or media data.
	 *
	 * @param string         $file_name     Submitted filename.
	 * @param array|WP_Error $import_result Import result.
	 * @param array          $context       Sanitized request context.
	 * @return void
	 */
	private function record_import_log( $file_name, $import_result, $context = array() ) {
		$status        = 'success';
		$created       = 0;
		$failed        = 0;
		$message       = '';
		$error_code    = '';
		$warning_count = 0;
		$first_warning = '';

		if ( is_wp_error( $import_result ) ) {
			$status     = 'ftmzi_no_markdown' === $import_result->get_error_code() ? 'skipped' : 'failed';
			$failed     = 'failed' === $status ? 1 : 0;
			$message    = $import_result->get_error_message();
			$error_code = sanitize_key( $import_result->get_error_code() );
		} else {
			$created       = ! empty( $import_result['created'] ) ? count( $import_result['created'] ) : 0;
			$failed        = ! empty( $import_result['failed'] ) ? count( $import_result['failed'] ) : 0;
			$status        = $failed ? ( $created ? 'partial' : 'failed' ) : 'success';
			$warning_count = ! empty( $import_result['warnings'] ) ? count( $import_result['warnings'] ) : 0;

			if ( $failed && ! empty( $import_result['failed'][0]['message'] ) ) {
				$message    = $import_result['failed'][0]['message'];
				$error_code = ! empty( $import_result['failed'][0]['code'] ) ? sanitize_key( $import_result['failed'][0]['code'] ) : '';
			}

			if ( $warning_count ) {
				$warning       = is_array( $import_result['warnings'][0] ) ? $import_result['warnings'][0] : array( 'message' => $import_result['warnings'][0] );
				$first_warning = isset( $warning['message'] ) ? $warning['message'] : '';
			}
		}

		$logs = $this->get_import_logs();
		array_unshift(
			$logs,
			array(
				'file'           => sanitize_file_name( (string) $file_name ),
				'status'         => $status,
				'created'        => $created,
				'failed'         => $failed,
				'error_code'     => $error_code,
				'message'        => $this->redact_diagnostic_text( $message ),
				'warning_count'  => $warning_count,
				'first_warning'  => $this->redact_diagnostic_text( $first_warning ),
				'context'        => $this->sanitize_diagnostic_context( $context ),
				'plugin_version' => FTMZI_VERSION,
				'time'           => current_time( 'mysql' ),
			)
		);

		update_option( self::IMPORT_LOG_OPTION, array_slice( $logs, 0, self::IMPORT_LOG_LIMIT ), false );
	}

	/**
	 * Get normalized import log entries stored for this site.
	 *
	 * @return array<int, array>
	 */
	private function get_import_logs() {
		$logs = get_option( self::IMPORT_LOG_OPTION, array() );

		if ( ! is_array( $logs ) ) {
			return array();
		}

		$valid_statuses = array( 'success', 'partial', 'failed', 'skipped' );
		$normalized     = array();

		foreach ( $logs as $log ) {
			if ( ! is_array( $log ) || empty( $log['file'] ) || ! isset( $log['status'] ) || ! in_array( $log['status'], $valid_statuses, true ) ) {
				continue;
			}

			$normalized[] = array(
				'file'           => sanitize_file_name( (string) $log['file'] ),
				'status'         => $log['status'],
				'created'        => absint( isset( $log['created'] ) ? $log['created'] : 0 ),
				'failed'         => absint( isset( $log['failed'] ) ? $log['failed'] : 0 ),
				'error_code'     => sanitize_key( isset( $log['error_code'] ) ? $log['error_code'] : '' ),
				'message'        => $this->redact_diagnostic_text( isset( $log['message'] ) ? $log['message'] : '' ),
				'warning_count'  => absint( isset( $log['warning_count'] ) ? $log['warning_count'] : 0 ),
				'first_warning'  => $this->redact_diagnostic_text( isset( $log['first_warning'] ) ? $log['first_warning'] : '' ),
				'context'        => $this->sanitize_diagnostic_context( isset( $log['context'] ) ? $log['context'] : array() ),
				'plugin_version' => sanitize_text_field( isset( $log['plugin_version'] ) ? $log['plugin_version'] : '' ),
				'time'           => sanitize_text_field( isset( $log['time'] ) ? $log['time'] : '' ),
			);
		}

		return $normalized;
	}

	/**
	 * Build non-sensitive context for one completed import attempt.
	 *
	 * @param array  $upload               Uploaded file metadata.
	 * @param array  $options              Validated import options.
	 * @param string $request_mode         Import request mode.
	 * @param bool   $import_remote_images Whether remote images are imported.
	 * @param float  $started_at           Request start time.
	 * @return array<string, mixed>
	 */
	private function get_import_log_context( $upload, $options, $request_mode, $import_remote_images, $started_at ) {
		return array(
			'file_size'            => isset( $upload['size'] ) ? absint( $upload['size'] ) : 0,
			'upload_error'         => isset( $upload['error'] ) ? absint( $upload['error'] ) : UPLOAD_ERR_NO_FILE,
			'extension'            => sanitize_key( pathinfo( isset( $upload['name'] ) ? (string) $upload['name'] : '', PATHINFO_EXTENSION ) ),
			'request_mode'         => 'batch' === $request_mode ? 'batch' : 'form',
			'post_type'            => sanitize_key( isset( $options['post_type'] ) ? $options['post_type'] : '' ),
			'post_status'          => sanitize_key( isset( $options['post_status'] ) ? $options['post_status'] : '' ),
			'markdown_parser'      => sanitize_key( isset( $options['markdown_parser'] ) ? $options['markdown_parser'] : '' ),
			'import_remote_images' => (bool) $import_remote_images,
			'ignore_xmp_metadata'  => (bool) get_option( FTMZI_Importer::IGNORE_XMP_METADATA_OPTION, false ),
			'duration_ms'          => max( 0, (int) round( ( microtime( true ) - (float) $started_at ) * 1000 ) ),
		);
	}

	/**
	 * Keep only the known, non-sensitive diagnostic context keys.
	 *
	 * @param mixed $context Context data.
	 * @return array<string, mixed>
	 */
	private function sanitize_diagnostic_context( $context ) {
		if ( ! is_array( $context ) || empty( $context ) ) {
			return array();
		}

		return array(
			'file_size'            => absint( isset( $context['file_size'] ) ? $context['file_size'] : 0 ),
			'upload_error'         => absint( isset( $context['upload_error'] ) ? $context['upload_error'] : 0 ),
			'extension'            => sanitize_key( isset( $context['extension'] ) ? $context['extension'] : '' ),
			'request_mode'         => 'batch' === ( isset( $context['request_mode'] ) ? $context['request_mode'] : '' ) ? 'batch' : 'form',
			'post_type'            => sanitize_key( isset( $context['post_type'] ) ? $context['post_type'] : '' ),
			'post_status'          => sanitize_key( isset( $context['post_status'] ) ? $context['post_status'] : '' ),
			'markdown_parser'      => sanitize_key( isset( $context['markdown_parser'] ) ? $context['markdown_parser'] : '' ),
			'import_remote_images' => ! empty( $context['import_remote_images'] ),
			'ignore_xmp_metadata'  => ! empty( $context['ignore_xmp_metadata'] ),
			'duration_ms'          => absint( isset( $context['duration_ms'] ) ? $context['duration_ms'] : 0 ),
		);
	}

	/**
	 * Build a diagnostic report without secrets, site URLs, content, or paths.
	 *
	 * @return array<string, mixed>
	 */
	private function get_diagnostic_report() {
		global $wp_version;

		$uploads = wp_upload_dir();
		$limits  = FTMZI_Importer::get_limits();
		$theme   = wp_get_theme();

		return array(
			'report'       => array(
				'schema_version'   => 1,
				'generated_at_utc' => gmdate( 'c' ),
				'privacy'          => 'Excludes passwords, credentials, post content, site URLs, and absolute filesystem paths. Filenames and sanitized import messages are included.',
			),
			'plugin'       => array(
				'name'    => 'Fangtao MD IO',
				'version' => FTMZI_VERSION,
			),
			'wordpress'    => array(
				'version'         => sanitize_text_field( $wp_version ),
				'locale'          => function_exists( 'determine_locale' ) ? determine_locale() : get_locale(),
				'timezone'        => wp_timezone_string(),
				'multisite'       => is_multisite(),
				'debug'           => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'max_upload_size' => (int) wp_max_upload_size(),
			),
			'php'          => array(
				'version'             => PHP_VERSION,
				'sapi'                => PHP_SAPI,
				'os_family'           => PHP_OS_FAMILY,
				'file_uploads'        => (bool) filter_var( ini_get( 'file_uploads' ), FILTER_VALIDATE_BOOLEAN ),
				'memory_limit'        => sanitize_text_field( (string) ini_get( 'memory_limit' ) ),
				'post_max_size'        => sanitize_text_field( (string) ini_get( 'post_max_size' ) ),
				'upload_max_filesize' => sanitize_text_field( (string) ini_get( 'upload_max_filesize' ) ),
				'max_file_uploads'    => absint( ini_get( 'max_file_uploads' ) ),
				'max_execution_time'  => absint( ini_get( 'max_execution_time' ) ),
				'max_input_time'      => (int) ini_get( 'max_input_time' ),
			),
			'capabilities' => array(
				'zip_backend'       => class_exists( 'ZipArchive' ) ? 'ZipArchive' : 'PclZip',
				'zip_ready'         => class_exists( 'ZipArchive' ) || ( file_exists( ABSPATH . 'wp-admin/includes/class-pclzip.php' ) && extension_loaded( 'zlib' ) ),
				'ziparchive'        => class_exists( 'ZipArchive' ),
				'wordpress_pclzip'  => file_exists( ABSPATH . 'wp-admin/includes/class-pclzip.php' ),
				'zlib'              => extension_loaded( 'zlib' ),
				'mbstring'          => extension_loaded( 'mbstring' ),
				'dom'               => extension_loaded( 'dom' ),
				'fileinfo'          => extension_loaded( 'fileinfo' ),
			),
			'storage'      => array(
				'uploads_available' => empty( $uploads['error'] ),
				'uploads_exists'    => empty( $uploads['basedir'] ) ? false : is_dir( $uploads['basedir'] ),
				'uploads_writable'  => empty( $uploads['basedir'] ) ? false : wp_is_writable( $uploads['basedir'] ),
				'temp_exists'       => is_dir( get_temp_dir() ),
				'temp_writable'     => wp_is_writable( get_temp_dir() ),
			),
			'import'       => array(
				'default_status'       => sanitize_key( get_option( self::DEFAULT_STATUS_OPTION, 'draft' ) ),
				'default_parser'       => sanitize_key( get_option( self::DEFAULT_PARSER_OPTION, FTMZI_Markdown::DEFAULT_PARSER ) ),
				'import_remote_images' => (bool) get_option( self::REMOTE_IMAGES_OPTION, false ),
				'ignore_xmp_metadata'  => (bool) get_option( FTMZI_Importer::IGNORE_XMP_METADATA_OPTION, false ),
				'asset_extensions'     => FTMZI_Importer::get_allowed_asset_extensions(),
				'limits'               => array_map( 'absint', $limits ),
			),
			'theme'        => array(
				'slug'    => sanitize_key( $theme->get_stylesheet() ),
				'version' => sanitize_text_field( $theme->get( 'Version' ) ),
			),
			'active_plugins' => $this->get_active_plugin_diagnostics(),
			'import_logs'    => $this->get_import_logs(),
		);
	}

	/**
	 * Return active plugin slugs and versions for compatibility diagnosis.
	 *
	 * @return array<int, array{plugin:string,version:string}>
	 */
	private function get_active_plugin_diagnostics() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = get_plugins();
		$network_active = (array) get_site_option( 'active_sitewide_plugins', array() );
		$active = array_unique(
			array_merge(
				(array) get_option( 'active_plugins', array() ),
				array_keys( $network_active )
			)
		);
		$result = array();

		foreach ( $active as $plugin_file ) {
			$plugin_file = plugin_basename( (string) $plugin_file );
			$result[]    = array(
				'plugin'  => sanitize_text_field( $plugin_file ),
				'version' => isset( $installed[ $plugin_file ]['Version'] ) ? sanitize_text_field( $installed[ $plugin_file ]['Version'] ) : '',
			);
		}

		return $result;
	}

	/**
	 * Remove paths and URLs from diagnostic messages.
	 *
	 * @param mixed $message Diagnostic message.
	 * @return string
	 */
	private function redact_diagnostic_text( $message ) {
		$message = sanitize_text_field( wp_strip_all_tags( (string) $message ) );
		$paths   = array_filter(
			array(
				defined( 'ABSPATH' ) ? ABSPATH : '',
				defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '',
				defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '',
				get_temp_dir(),
			)
		);

		foreach ( $paths as $path ) {
			$message = str_ireplace( array( $path, wp_normalize_path( $path ) ), '[redacted-path]/', $message );
		}

		$message = (string) preg_replace( '#https?://[^\s<]+#i', '[redacted-url]', $message );

		if ( strlen( $message ) > 500 ) {
			$message = substr( $message, 0, 500 );

			while ( '' !== $message && '' === wp_check_invalid_utf8( $message ) ) {
				$message = substr( $message, 0, -1 );
			}
		}

		return $message;
	}

	/**
	 * Summarize persistent import logs for the dashboard chart.
	 *
	 * @return array{success:int,failed:int,invalid:int}
	 */
	private function get_import_log_summary() {
		$summary = array(
			'success' => 0,
			'failed'  => 0,
			'invalid' => 0,
		);

		foreach ( $this->get_import_logs() as $log ) {
			$summary['success'] += $log['created'];
			$summary['failed']  += $log['failed'];

			if ( 'skipped' === $log['status'] ) {
				$summary['invalid']++;
			} elseif ( 'failed' === $log['status'] && 0 === $log['failed'] ) {
				$summary['failed']++;
			}
		}

		return $summary;
	}

	/**
	 * Render recent persistent import records in the dashboard.
	 *
	 * @return void
	 */
	private function render_import_log_entries() {
		$logs = array_slice( $this->get_import_logs(), 0, 3 );

		if ( empty( $logs ) ) {
			?>
			<p class="ftmzi-import-log__empty"><?php esc_html_e( 'No import logs yet.', 'fangtao-md-io' ); ?></p>
			<?php
			return;
		}

		$status_labels = array(
			'success' => __( 'Import successful', 'fangtao-md-io' ),
			'partial' => __( 'Partially imported', 'fangtao-md-io' ),
			'failed'  => __( 'Import failed', 'fangtao-md-io' ),
			'skipped' => __( 'Invalid content', 'fangtao-md-io' ),
		);
		?>
		<ol class="ftmzi-import-log__list">
			<?php foreach ( $logs as $log ) : ?>
				<li class="ftmzi-import-log__item is-<?php echo esc_attr( $log['status'] ); ?>">
					<span class="ftmzi-import-log__file" title="<?php echo esc_attr( $log['file'] ); ?>"><?php echo esc_html( $log['file'] ); ?></span>
					<span class="ftmzi-import-log__meta">
						<?php echo esc_html( $status_labels[ $log['status'] ] ); ?>
						<?php if ( $log['time'] ) : ?>
							<span aria-hidden="true">&middot;</span>
							<?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $log['time'] ) ); ?>
						<?php endif; ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
	}

	/**
	 * Redirect back to the importer with a one-time result.
	 *
	 * @param array|WP_Error $result Import result.
	 * @return void
	 */
	private function redirect_with_result( $result ) {
		$transient_key = 'ftmzi_result_' . get_current_user_id();

		if ( is_wp_error( $result ) ) {
			$result = array(
				'error' => $result->get_error_message(),
			);
		}

		set_transient( $transient_key, $result, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect(
			add_query_arg(
				'imported',
				'1',
				admin_url( 'admin.php?page=' . self::PAGE_SLUG )
			)
		);
		exit;
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'edit_posts' ) || ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$result               = null;
		$default_status       = get_option( self::DEFAULT_STATUS_OPTION, 'draft' );
		$default_parser       = FTMZI_Markdown::sanitize_parser( get_option( self::DEFAULT_PARSER_OPTION, FTMZI_Markdown::DEFAULT_PARSER ) );
		$import_remote_images = (bool) get_option( self::REMOTE_IMAGES_OPTION, false );
		$ignore_xmp_metadata  = (bool) get_option( FTMZI_Importer::IGNORE_XMP_METADATA_OPTION, false );
		$asset_groups         = FTMZI_Importer::get_asset_groups();
		$allowed_assets       = FTMZI_Importer::get_allowed_asset_extensions();
		$import_limits        = FTMZI_Importer::get_limits();
		$stored_limits        = array(
			'max_archive_size_mb'   => absint( get_option( FTMZI_Importer::MAX_ARCHIVE_SIZE_OPTION, 0 ) ),
			'max_extracted_size_mb' => absint( get_option( FTMZI_Importer::MAX_EXTRACTED_SIZE_OPTION, 0 ) ),
			'max_markdown_size_mb'  => absint( get_option( FTMZI_Importer::MAX_MARKDOWN_SIZE_OPTION, 0 ) ),
			'max_asset_size_mb'     => absint( get_option( FTMZI_Importer::MAX_ASSET_SIZE_OPTION, 0 ) ),
			'max_archive_entries'   => absint( get_option( FTMZI_Importer::MAX_ARCHIVE_ENTRIES_OPTION, 0 ) ),
		);
		$markdown_parsers     = FTMZI_Markdown::get_parsers();
		$missing_parsers      = FTMZI_Markdown::get_missing_parsers();
		$import_log_summary   = $this->get_import_log_summary();
		$import_log_total     = $import_log_summary['success'] + $import_log_summary['failed'] + $import_log_summary['invalid'];

		if ( ! in_array( $default_status, array( 'draft', 'publish' ), true ) || ! current_user_can( 'publish_posts' ) ) {
			$default_status = 'draft';
		}

		if ( isset( $_GET['imported'] ) ) {
			$transient_key = 'ftmzi_result_' . get_current_user_id();
			$result        = get_transient( $transient_key );
			delete_transient( $transient_key );
		}

		$post_types = get_post_types(
			array(
				'show_ui' => true,
			),
			'objects'
		);
		?>
		<div class="wrap ftmzi-wrap ftmzi-wrap--import">
			<div class="ftmzi-page-hero">
			<h1><?php esc_html_e( 'Markdown Import', 'fangtao-md-io' ); ?></h1>
			<p class="ftmzi-intro">
				<?php esc_html_e( 'Upload multiple Markdown files or ZIP archives at once. Each Markdown file creates one content item; ZIP archives without Markdown are skipped.', 'fangtao-md-io' ); ?>
			</p>

			</div>

			<?php $this->render_result( $result ); ?>

			<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible inline">
					<p><?php esc_html_e( 'Import settings saved.', 'fangtao-md-io' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['logs-cleared'] ) ) : ?>
				<div class="notice notice-success is-dismissible inline">
					<p><?php esc_html_e( 'Import statistics and logs have been cleared.', 'fangtao-md-io' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( ! class_exists( 'ZipArchive' ) ) : ?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'The PHP ZIP extension is unavailable. ZIP import will use WordPress built-in extraction.', 'fangtao-md-io' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $missing_parsers ) : ?>
				<div class="notice notice-error inline">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: parser names. */
								__( 'The following Markdown parser components are missing, so their options are unavailable: %s. Please reinstall this plugin.', 'fangtao-md-io' ),
								implode( '、', $missing_parsers )
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

				<div class="ftmzi-import-workspace">
				<form class="ftmzi-card" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" enctype="multipart/form-data" data-ftmzi-import-form>
					<input type="hidden" name="action" value="ftmzi_import">
					<?php wp_nonce_field( 'ftmzi_import_archive', 'ftmzi_nonce' ); ?>

					<div class="ftmzi-field">
						<label for="ftmzi-markdown-zip"><?php esc_html_e( 'Markdown or ZIP file', 'fangtao-md-io' ); ?></label>
						<div class="ftmzi-file-control">
							<input id="ftmzi-markdown-zip" name="markdown_zip[]" type="file" accept=".zip,.md,.markdown,.mdown,.mkdn,.mkd,.mdwn,.mdtxt,.mdtext,.文本,.txt,application/zip,text/markdown,text/plain" multiple required>
							<button class="ftmzi-file-clear" type="button" data-ftmzi-file-clear hidden aria-label="<?php esc_attr_e( 'Clear selected files', 'fangtao-md-io' ); ?>" title="<?php esc_attr_e( 'Clear selected files', 'fangtao-md-io' ); ?>">
								<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
								<span class="screen-reader-text"><?php esc_html_e( 'Clear selected files', 'fangtao-md-io' ); ?></span>
							</button>
						</div>
						<p class="description"><?php esc_html_e( 'Select multiple .md, .markdown, .mdown, .mkdn, .mkd, .mdwn, .mdtxt, .mdtext, .text, .txt, and .zip files, case-insensitively. ZIP archives without Markdown are skipped automatically.', 'fangtao-md-io' ); ?></p>
					</div>

					<div class="ftmzi-fields-row ftmzi-fields-row--import">
						<div class="ftmzi-field">
							<label for="ftmzi-post-type"><?php esc_html_e( 'Import to', 'fangtao-md-io' ); ?></label>
							<select id="ftmzi-post-type" name="post_type">
								<?php foreach ( $post_types as $post_type ) : ?>
									<?php
									if (
										'attachment' === $post_type->name ||
										( $post_type->_builtin && ! in_array( $post_type->name, array( 'post', 'page' ), true ) ) ||
										( ! $post_type->_builtin && ! $post_type->public ) ||
										! current_user_can( $post_type->cap->edit_posts )
									) {
										continue;
									}
									?>
									<option value="<?php echo esc_attr( $post_type->name ); ?>"<?php selected( 'post', $post_type->name ); ?>>
										<?php echo esc_html( $post_type->labels->name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>

						<div class="ftmzi-field" data-ftmzi-category-field>
							<label for="ftmzi-category-id"><?php esc_html_e( 'Import category', 'fangtao-md-io' ); ?></label>
							<?php
							wp_dropdown_categories(
								array(
									'hide_empty'        => false,
									'id'                => 'ftmzi-category-id',
									'name'              => 'category_id',
									'option_none_value' => '0',
									'show_option_none'  => __( 'No category', 'fangtao-md-io' ),
								)
							);
							?>
						</div>

						<div class="ftmzi-field">
							<label for="ftmzi-post-status"><?php esc_html_e( 'Post status', 'fangtao-md-io' ); ?></label>
							<select id="ftmzi-post-status" name="post_status">
								<option value="draft"<?php selected( 'draft', $default_status ); ?>><?php esc_html_e( 'Draft', 'fangtao-md-io' ); ?></option>
								<?php if ( current_user_can( 'publish_posts' ) ) : ?>
									<option value="publish"<?php selected( 'publish', $default_status ); ?>><?php esc_html_e( 'Publish immediately', 'fangtao-md-io' ); ?></option>
								<?php endif; ?>
							</select>
						</div>
					</div>

					<div class="ftmzi-field">
						<label for="ftmzi-markdown-parser"><?php esc_html_e( 'Markdown parser', 'fangtao-md-io' ); ?></label>
						<select id="ftmzi-markdown-parser" name="markdown_parser" data-ftmzi-parser-select data-flavor-target="ftmzi-parser-flavor">
							<?php foreach ( $markdown_parsers as $parser_key => $parser ) : ?>
								<option
									value="<?php echo esc_attr( $parser_key ); ?>"
									data-flavor="<?php echo esc_attr( $parser['flavor'] ); ?>"
									<?php selected( $default_parser, $parser_key ); ?>
									<?php disabled( ! class_exists( $parser['class'] ) ); ?>
								>
									<?php echo esc_html( $parser['label'] . '（' . $parser['flavor'] . '）' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Current Markdown style:', 'fangtao-md-io' ); ?>
							<strong id="ftmzi-parser-flavor"><?php echo esc_html( $markdown_parsers[ $default_parser ]['flavor'] ); ?></strong>
							<?php esc_html_e( '. Choose between Traditional, GitHub, and Extra styles.', 'fangtao-md-io' ); ?>
						</p>
					</div>

					<div class="ftmzi-fields-row ftmzi-fields-row--import-options">
						<div class="ftmzi-field ftmzi-field--post-date" data-ftmzi-date-picker>
							<label for="ftmzi-post-date"><?php esc_html_e( 'Optional publication date', 'fangtao-md-io' ); ?></label>
							<input id="ftmzi-post-date-value" name="post_date" type="hidden">
							<div class="ftmzi-date-picker__trigger">
								<input id="ftmzi-post-date" type="text" inputmode="numeric" autocomplete="off" placeholder="YYYY-MM-DD HH:MM:SS" aria-describedby="ftmzi-post-date-help" aria-expanded="false" aria-controls="ftmzi-post-date-popover" data-ftmzi-invalid-message="<?php esc_attr_e( 'Use the YYYY-MM-DD HH:MM:SS format.', 'fangtao-md-io' ); ?>">
								<button class="ftmzi-date-picker__toggle" type="button" data-ftmzi-date-toggle aria-label="<?php esc_attr_e( 'Choose publication date', 'fangtao-md-io' ); ?>" title="<?php esc_attr_e( 'Choose publication date', 'fangtao-md-io' ); ?>" aria-expanded="false" aria-controls="ftmzi-post-date-popover">
									<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
								</button>
							</div>
							<div id="ftmzi-post-date-popover" class="ftmzi-date-picker__popover" data-ftmzi-date-popover role="dialog" aria-label="<?php esc_attr_e( 'Choose publication date', 'fangtao-md-io' ); ?>" aria-hidden="true">
								<div class="ftmzi-date-picker__input">
									<label for="ftmzi-post-datetime-picker"><?php esc_html_e( 'Choose publication date', 'fangtao-md-io' ); ?></label>
									<input id="ftmzi-post-datetime-picker" type="datetime-local" step="1">
								</div>
								<div class="ftmzi-date-picker__actions">
									<button class="button" type="button" data-ftmzi-date-now>
										<span class="dashicons dashicons-clock" aria-hidden="true"></span>
										<?php esc_html_e( 'Current time', 'fangtao-md-io' ); ?>
									</button>
									<button class="button" type="button" data-ftmzi-date-clear>
										<span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
										<?php esc_html_e( 'Clear', 'fangtao-md-io' ); ?>
									</button>
									<button class="button button-primary" type="button" data-ftmzi-date-close><?php esc_html_e( 'Done', 'fangtao-md-io' ); ?></button>
								</div>
							</div>
							<p id="ftmzi-post-date-help" class="description"><?php esc_html_e( 'Leave blank to use each Markdown document\'s ZIP modification time; set an exact manual time when needed.', 'fangtao-md-io' ); ?></p>
						</div>

						<div class="ftmzi-field">
							<label><?php esc_html_e( 'Visibility', 'fangtao-md-io' ); ?></label>
							<label class="ftmzi-option-label" for="ftmzi-post-private">
								<input id="ftmzi-post-private" name="post_private" type="checkbox" value="1">
								<?php esc_html_e( 'Make private', 'fangtao-md-io' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Only administrators and authorized users can view it.', 'fangtao-md-io' ); ?></p>
						</div>

						<div class="ftmzi-field">
							<label><?php esc_html_e( 'Access password', 'fangtao-md-io' ); ?></label>
							<label class="ftmzi-option-label" for="ftmzi-use-post-password">
								<input id="ftmzi-use-post-password" name="use_post_password" type="checkbox" value="1">
								<?php esc_html_e( 'Set an access password', 'fangtao-md-io' ); ?>
							</label>
							<input id="ftmzi-post-password" name="post_password" type="password" maxlength="255" autocomplete="new-password" disabled>
							<p class="description"><?php esc_html_e( 'Private posts cannot use an access password. When no password is set, the post remains accessible without a password.', 'fangtao-md-io' ); ?></p>
						</div>
					</div>

					<?php submit_button( __( 'Upload and Import', 'fangtao-md-io' ), 'primary', 'submit', false ); ?>
				</form>

					<aside class="ftmzi-import-sidebar">
						<section class="ftmzi-import-dashboard" data-ftmzi-import-dashboard aria-live="polite">
							<div class="ftmzi-import-dashboard__heading">
								<h2><?php esc_html_e( 'Import statistics', 'fangtao-md-io' ); ?></h2>
								<button class="ftmzi-import-dashboard__refresh" type="button" data-ftmzi-import-reset aria-label="<?php esc_attr_e( 'Refresh import statistics', 'fangtao-md-io' ); ?>" title="<?php esc_attr_e( 'Refresh import statistics', 'fangtao-md-io' ); ?>">
									<span class="dashicons dashicons-update" aria-hidden="true"></span>
									<span class="screen-reader-text"><?php esc_html_e( 'Refresh import statistics', 'fangtao-md-io' ); ?></span>
								</button>
							</div>
							<div class="ftmzi-import-dashboard__body">
								<div class="ftmzi-import-dashboard__chart-stage">
									<span class="ftmzi-import-dashboard__chart-label is-success" data-ftmzi-import-success-percent><?php echo esc_html( $import_log_total ? round( $import_log_summary['success'] / $import_log_total * 100 ) . '%' : '0%' ); ?></span>
									<span class="ftmzi-import-dashboard__chart-label is-failed" data-ftmzi-import-failed-percent><?php echo esc_html( $import_log_total ? round( $import_log_summary['failed'] / $import_log_total * 100 ) . '%' : '0%' ); ?></span>
									<span class="ftmzi-import-dashboard__chart-label is-invalid" data-ftmzi-import-invalid-percent><?php echo esc_html( $import_log_total ? round( $import_log_summary['invalid'] / $import_log_total * 100 ) . '%' : '0%' ); ?></span>
									<div class="ftmzi-import-dashboard__chart<?php echo $import_log_total ? '' : ' is-empty'; ?>" data-ftmzi-import-dashboard-chart role="progressbar" aria-valuemin="0" aria-valuemax="<?php echo esc_attr( $import_log_total ? $import_log_total : 1 ); ?>" aria-valuenow="<?php echo esc_attr( $import_log_total ); ?>">
										<div class="ftmzi-import-dashboard__chart-center">
											<strong data-ftmzi-import-total><?php echo esc_html( $import_log_total ); ?></strong>
											<span><?php esc_html_e( 'Processed', 'fangtao-md-io' ); ?></span>
										</div>
									</div>
								</div>
								<div class="ftmzi-import-dashboard__metrics">
									<div class="is-success" data-ftmzi-import-legend="success" tabindex="0">
										<span><?php esc_html_e( 'Import successful', 'fangtao-md-io' ); ?></span>
										<strong data-ftmzi-import-success><?php echo esc_html( $import_log_summary['success'] ); ?></strong>
									</div>
									<div class="is-failed" data-ftmzi-import-legend="failed" tabindex="0">
										<span><?php esc_html_e( 'Import failed', 'fangtao-md-io' ); ?></span>
										<strong data-ftmzi-import-failed><?php echo esc_html( $import_log_summary['failed'] ); ?></strong>
									</div>
									<div class="is-invalid" data-ftmzi-import-legend="invalid" tabindex="0">
										<span><?php esc_html_e( 'Invalid content', 'fangtao-md-io' ); ?></span>
										<strong data-ftmzi-import-invalid><?php echo esc_html( $import_log_summary['invalid'] ); ?></strong>
									</div>
								</div>
							</div>
							<div class="ftmzi-import-log" aria-label="<?php esc_attr_e( 'Recent import logs', 'fangtao-md-io' ); ?>">
								<div class="ftmzi-import-log__heading">
									<h3><?php esc_html_e( 'Recent import logs', 'fangtao-md-io' ); ?></h3>
									<span><?php esc_html_e( 'Stores the latest 100 entries', 'fangtao-md-io' ); ?></span>
								</div>
								<?php $this->render_import_log_entries(); ?>
							</div>
						</section>

						<section class="ftmzi-import-queue" data-ftmzi-import-queue aria-live="polite">
							<div class="ftmzi-import-queue__heading">
								<h2><?php esc_html_e( 'Batch import tasks', 'fangtao-md-io' ); ?></h2>
								<span data-ftmzi-import-count>0 / 0</span>
							</div>
							<p class="description" data-ftmzi-import-summary><?php esc_html_e( 'Waiting to start import.', 'fangtao-md-io' ); ?></p>
							<ol class="ftmzi-import-queue__list" data-ftmzi-import-list></ol>
						</section>
					</aside>
				</div>

				<div class="ftmzi-guide">
					<h2><?php esc_html_e( 'Import Guide', 'fangtao-md-io' ); ?></h2>
					<p><?php esc_html_e( 'A standalone Markdown file can be uploaded directly. If content references local assets, package the Markdown file and assets in a ZIP with relative paths. Only assets referenced in content are added to the Media Library.', 'fangtao-md-io' ); ?></p>
					<pre><code>articles/
  living-room.md
  images/
    living-room.jpg
  media/
    tour.mp4</code></pre>
					<p>
						<?php esc_html_e( 'Use relative paths in Markdown:', 'fangtao-md-io' ); ?>
						<code>![Living room](images/living-room.jpg)</code>
					</p>
					<p>
						<?php esc_html_e( 'Use WordPress shortcodes for video. Use normal Markdown links for other assets:', 'fangtao-md-io' ); ?>
						<code>[video src="media/tour.mp4"]</code>
						<code>[Download catalog](media/catalog.pdf)</code>
					</p>
					<p>
						<?php esc_html_e( 'Optional Front Matter fields: title, slug, permalink, excerpt, date, status, categories, tags, featured_image, and featured_image_id. If no featured image is specified, the first imported image in the content becomes the featured image.', 'fangtao-md-io' ); ?>
					</p>
				</div>

				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<form class="ftmzi-card" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
						<input type="hidden" name="action" value="ftmzi_save_settings">
						<?php wp_nonce_field( 'ftmzi_save_settings', 'ftmzi_settings_nonce' ); ?>

						<h2><?php esc_html_e( 'Import Settings', 'fangtao-md-io' ); ?></h2>
						<div class="ftmzi-field">
							<label for="ftmzi-default-post-status"><?php esc_html_e( 'Default post status', 'fangtao-md-io' ); ?></label>
							<select id="ftmzi-default-post-status" name="default_post_status">
								<option value="draft"<?php selected( 'draft', $default_status ); ?>><?php esc_html_e( 'Draft', 'fangtao-md-io' ); ?></option>
								<option value="publish"<?php selected( 'publish', $default_status ); ?>><?php esc_html_e( 'Publish immediately', 'fangtao-md-io' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Sets the initially selected post status on the import form. It can still be changed for an individual import.', 'fangtao-md-io' ); ?></p>
						</div>

						<div class="ftmzi-field">
							<label for="ftmzi-default-markdown-parser"><?php esc_html_e( 'Default Markdown parser', 'fangtao-md-io' ); ?></label>
							<select id="ftmzi-default-markdown-parser" name="default_markdown_parser" data-ftmzi-parser-select data-flavor-target="ftmzi-default-parser-flavor">
								<?php foreach ( $markdown_parsers as $parser_key => $parser ) : ?>
									<option
										value="<?php echo esc_attr( $parser_key ); ?>"
										data-flavor="<?php echo esc_attr( $parser['flavor'] ); ?>"
										<?php selected( $default_parser, $parser_key ); ?>
										<?php disabled( ! class_exists( $parser['class'] ) ); ?>
									>
										<?php echo esc_html( $parser['label'] . '（' . $parser['flavor'] . '）' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Default style:', 'fangtao-md-io' ); ?>
								<strong id="ftmzi-default-parser-flavor"><?php echo esc_html( $markdown_parsers[ $default_parser ]['flavor'] ); ?></strong>
								<?php esc_html_e( '. You can still choose another parser for an individual import.', 'fangtao-md-io' ); ?>
							</p>
						</div>

						<div class="ftmzi-field ftmzi-field--checkbox">
							<label for="ftmzi-import-remote-images">
								<input id="ftmzi-import-remote-images" name="import_remote_images" type="checkbox" value="1"<?php checked( $import_remote_images ); ?>>
								<?php esc_html_e( 'Import remote images automatically', 'fangtao-md-io' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When enabled, HTTP(S) images in content and featured images are downloaded to the Media Library. Disabled by default.', 'fangtao-md-io' ); ?></p>
						</div>

						<div class="ftmzi-field ftmzi-field--checkbox">
							<label for="ftmzi-ignore-xmp-metadata">
								<input id="ftmzi-ignore-xmp-metadata" name="ignore_xmp_metadata" type="checkbox" value="1"<?php checked( $ignore_xmp_metadata ); ?>>
								<?php esc_html_e( 'Ignore JPEG XMP metadata', 'fangtao-md-io' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Removes XMP APP1 segments from temporary JPEG copies before Media Library import. Source ZIP files and EXIF metadata are not changed. Disabled by default.', 'fangtao-md-io' ); ?></p>
						</div>

						<fieldset class="ftmzi-field">
							<legend><?php esc_html_e( 'ZIP asset formats', 'fangtao-md-io' ); ?></legend>
							<p class="description"><?php esc_html_e( 'Only selected safe media formats are extracted and imported. Unselected files are ignored, and executable formats such as PHP are never allowed.', 'fangtao-md-io' ); ?></p>
							<div class="ftmzi-extension-groups">
								<?php
								$asset_group_labels = array(
									'image'    => __( 'Images', 'fangtao-md-io' ),
									'video'    => __( 'Video', 'fangtao-md-io' ),
									'audio'    => __( 'Audio', 'fangtao-md-io' ),
									'document' => __( 'Documents', 'fangtao-md-io' ),
								);
								?>
								<?php foreach ( $asset_groups as $group_name => $extensions ) : ?>
									<div class="ftmzi-extension-group">
										<strong><?php echo esc_html( $asset_group_labels[ $group_name ] ); ?></strong>
										<div class="ftmzi-extension-options">
											<?php foreach ( $extensions as $extension ) : ?>
												<label>
													<input name="allowed_asset_extensions[]" type="checkbox" value="<?php echo esc_attr( $extension ); ?>"<?php checked( in_array( $extension, $allowed_assets, true ) ); ?>>
													.<?php echo esc_html( $extension ); ?>
												</label>
											<?php endforeach; ?>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						</fieldset>

						<div class="ftmzi-field">
							<h3><?php esc_html_e( 'Import size limits', 'fangtao-md-io' ); ?></h3>
							<p class="description">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: effective PHP upload limit. */
										__( 'Leave blank or enter 0 to follow the PHP/WordPress upload limit (currently %s). Manual values remain constrained by server upload limits.', 'fangtao-md-io' ),
										size_format( $import_limits['php_upload_size'] )
									)
								);
								?>
							</p>
						</div>

						<div class="ftmzi-limit-grid">
							<div class="ftmzi-field">
								<label for="ftmzi-max-archive-size"><?php esc_html_e( 'ZIP file limit (MB)', 'fangtao-md-io' ); ?></label>
								<input id="ftmzi-max-archive-size" name="max_archive_size_mb" type="number" min="0" max="102400" value="<?php echo esc_attr( $stored_limits['max_archive_size_mb'] ); ?>" placeholder="<?php echo esc_attr( floor( $import_limits['php_upload_size'] / MB_IN_BYTES ) ); ?>">
							</div>
							<div class="ftmzi-field">
								<label for="ftmzi-max-extracted-size"><?php esc_html_e( 'Extracted content limit (MB)', 'fangtao-md-io' ); ?></label>
								<input id="ftmzi-max-extracted-size" name="max_extracted_size_mb" type="number" min="0" max="102400" value="<?php echo esc_attr( $stored_limits['max_extracted_size_mb'] ); ?>" placeholder="<?php echo esc_attr( floor( $import_limits['php_upload_size'] / MB_IN_BYTES ) ); ?>">
							</div>
							<div class="ftmzi-field">
								<label for="ftmzi-max-markdown-size"><?php esc_html_e( 'Single Markdown limit (MB)', 'fangtao-md-io' ); ?></label>
								<input id="ftmzi-max-markdown-size" name="max_markdown_size_mb" type="number" min="0" max="102400" value="<?php echo esc_attr( $stored_limits['max_markdown_size_mb'] ); ?>" placeholder="<?php echo esc_attr( floor( $import_limits['php_upload_size'] / MB_IN_BYTES ) ); ?>">
							</div>
							<div class="ftmzi-field">
								<label for="ftmzi-max-asset-size"><?php esc_html_e( 'Single asset limit (MB)', 'fangtao-md-io' ); ?></label>
								<input id="ftmzi-max-asset-size" name="max_asset_size_mb" type="number" min="0" max="102400" value="<?php echo esc_attr( $stored_limits['max_asset_size_mb'] ); ?>" placeholder="<?php echo esc_attr( floor( $import_limits['php_upload_size'] / MB_IN_BYTES ) ); ?>">
							</div>
							<div class="ftmzi-field">
								<label for="ftmzi-max-archive-entries"><?php esc_html_e( 'ZIP file count limit', 'fangtao-md-io' ); ?></label>
								<input id="ftmzi-max-archive-entries" name="max_archive_entries" type="number" min="0" max="10000" value="<?php echo esc_attr( $stored_limits['max_archive_entries'] ); ?>" placeholder="<?php echo esc_attr( FTMZI_Importer::DEFAULT_ARCHIVE_ENTRIES ); ?>">
								<p class="description"><?php esc_html_e( 'Leave blank or enter 0 to use the default of 500.', 'fangtao-md-io' ); ?></p>
							</div>
						</div>

						<?php submit_button( __( 'Save Settings', 'fangtao-md-io' ), 'secondary', 'submit', false ); ?>
					</form>

					<div class="ftmzi-card ftmzi-card--advanced">
						<div class="ftmzi-panel-heading">
							<h2><?php esc_html_e( 'Advanced', 'fangtao-md-io' ); ?></h2>
						</div>
						<div class="ftmzi-advanced-action">
							<div>
								<h3><?php esc_html_e( 'Clear statistics and logs', 'fangtao-md-io' ); ?></h3>
								<p class="description"><?php esc_html_e( 'Only clears this plugin\'s import statistics and recent records. Posts, media files, and import settings are not deleted.', 'fangtao-md-io' ); ?></p>
							</div>
							<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" data-ftmzi-clear-import-log-form>
								<input type="hidden" name="action" value="ftmzi_clear_import_log">
								<?php wp_nonce_field( 'ftmzi_clear_import_log', 'ftmzi_clear_import_log_nonce' ); ?>
								<button class="button button-secondary" type="submit"><?php esc_html_e( 'Clear statistics and logs', 'fangtao-md-io' ); ?></button>
							</form>
						</div>
						<div class="ftmzi-advanced-action">
							<div>
								<h3><?php esc_html_e( 'Export diagnostic log', 'fangtao-md-io' ); ?></h3>
								<p class="description"><?php esc_html_e( 'Downloads plugin, server capability, import setting, active plugin, and recent import log data as JSON. Passwords, credentials, post content, site URLs, and absolute filesystem paths are excluded. Review filenames and sanitized error messages before sharing.', 'fangtao-md-io' ); ?></p>
							</div>
							<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
								<input type="hidden" name="action" value="ftmzi_export_diagnostic_log">
								<?php wp_nonce_field( 'ftmzi_export_diagnostic_log', 'ftmzi_diagnostic_nonce' ); ?>
								<button class="button button-secondary" type="submit"><?php esc_html_e( 'Download diagnostic log', 'fangtao-md-io' ); ?></button>
							</form>
						</div>
					</div>
				<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the Markdown export guide.
	 *
	 * @return void
	 */
	public function render_export_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$post_types = $this->get_exportable_post_types();
		$tags       = get_terms(
			array(
				'taxonomy'   => 'post_tag',
				'hide_empty' => false,
			)
		);
		$tags       = is_wp_error( $tags ) ? array() : $tags;
		?>
		<div class="wrap ftmzi-wrap ftmzi-wrap--export">
			<div class="ftmzi-page-hero">
			<h1><?php esc_html_e( 'Markdown Export', 'fangtao-md-io' ); ?></h1>
			<p class="ftmzi-intro">
				<?php esc_html_e( 'Export Markdown ZIP files individually or in bulk from content lists. Media Library images are written to the images directory and referenced with relative paths.', 'fangtao-md-io' ); ?>
			</p>

			</div>

			<?php if ( ! class_exists( 'ZipArchive' ) ) : ?>
				<div class="notice <?php echo extension_loaded( 'zlib' ) ? 'notice-info' : 'notice-error'; ?> inline">
					<p>
						<?php
						if ( extension_loaded( 'zlib' ) ) {
							esc_html_e( 'The PHP ZIP extension is unavailable. ZIP export will use WordPress built-in PclZip.', 'fangtao-md-io' );
						} else {
							esc_html_e( 'Neither PHP ZIP nor zlib is enabled, so an archive cannot be created.', 'fangtao-md-io' );
						}
						?>
					</p>
				</div>
			<?php endif; ?>

			<form class="ftmzi-card" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="ftmzi_filtered_export">
				<?php wp_nonce_field( 'ftmzi_filtered_export', 'ftmzi_export_nonce' ); ?>

				<div class="ftmzi-panel-heading">
				<h2><?php esc_html_e( 'Bulk filtered export', 'fangtao-md-io' ); ?></h2>
				</div>
				<div class="ftmzi-fields-row ftmzi-fields-row--export">
					<div class="ftmzi-field">
						<label for="ftmzi-export-post-type"><?php esc_html_e( 'Content Type', 'fangtao-md-io' ); ?></label>
						<select id="ftmzi-export-post-type" name="post_type">
							<?php foreach ( $post_types as $post_type ) : ?>
								<option value="<?php echo esc_attr( $post_type->name ); ?>"><?php echo esc_html( $post_type->labels->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="ftmzi-field" data-ftmzi-export-post-filter>
						<label for="ftmzi-export-category"><?php esc_html_e( 'Post category', 'fangtao-md-io' ); ?></label>
						<?php
						wp_dropdown_categories(
							array(
								'hide_empty'        => false,
								'id'                => 'ftmzi-export-category',
								'name'              => 'category_id',
								'option_none_value' => '0',
								'show_option_none'  => __( 'All Categories', 'fangtao-md-io' ),
							)
						);
						?>
					</div>

					<div class="ftmzi-field" data-ftmzi-export-post-filter>
						<label for="ftmzi-export-tag"><?php esc_html_e( 'Post tags', 'fangtao-md-io' ); ?></label>
						<select id="ftmzi-export-tag" name="tag_id">
							<option value="0"><?php esc_html_e( 'All Tags', 'fangtao-md-io' ); ?></option>
							<?php foreach ( $tags as $tag ) : ?>
								<option value="<?php echo esc_attr( $tag->term_id ); ?>"><?php echo esc_html( $tag->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="ftmzi-field">
						<label for="ftmzi-export-extension"><?php esc_html_e( 'File extension', 'fangtao-md-io' ); ?></label>
						<select id="ftmzi-export-extension" name="extension">
							<?php foreach ( FTMZI_Importer::DOCUMENT_EXTENSIONS as $extension ) : ?>
								<option value="<?php echo esc_attr( $extension ); ?>">.<?php echo esc_html( $extension ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<?php submit_button( __( 'Export Filtered Results', 'fangtao-md-io' ), 'primary', 'submit', false ); ?>
			</form>

			<div class="ftmzi-card ftmzi-card--content-links">
				<h2><?php esc_html_e( 'Select content to export', 'fangtao-md-io' ); ?></h2>
				<p><?php esc_html_e( 'Open the relevant content list. For one item, use Export Markdown below its title. For multiple items, select them and choose Export Markdown ZIP from Bulk actions.', 'fangtao-md-io' ); ?></p>

				<div class="ftmzi-export-types">
					<?php foreach ( $post_types as $post_type ) : ?>
						<?php
						$list_url = 'post' === $post_type->name
							? admin_url( 'edit.php' )
							: add_query_arg( 'post_type', $post_type->name, admin_url( 'edit.php' ) );
						$counts   = wp_count_posts( $post_type->name );
						$count    = isset( $counts->publish ) ? (int) $counts->publish : 0;
						?>
						<a class="ftmzi-export-type" href="<?php echo esc_url( $list_url ); ?>">
							<strong><?php echo esc_html( $post_type->labels->name ); ?></strong>
							<span>
								<?php
								printf(
									/* translators: %d: published content count. */
									esc_html__( '%d published', 'fangtao-md-io' ),
									$count
								);
								?>
							</span>
						</a>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="ftmzi-guide">
				<h2><?php esc_html_e( 'ZIP structure', 'fangtao-md-io' ); ?></h2>
				<p><?php esc_html_e( 'Single export:', 'fangtao-md-io' ); ?></p>
				<pre><code>article.md (or article.markdown)
images/
  article-image.jpg</code></pre>
				<p><?php esc_html_e( 'For bulk exports, each item is placed in its own directory with the same internal structure.', 'fangtao-md-io' ); ?></p>
				<p><code>![Image description](images/article-image.jpg)</code></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Return content types available to the current user.
	 *
	 * @return array<WP_Post_Type>
	 */
	private function get_exportable_post_types() {
		$post_types = get_post_types(
			array(
				'show_ui' => true,
			),
			'objects'
		);

		return array_values(
			array_filter(
				$post_types,
				static function ( $post_type ) {
					if ( 'attachment' === $post_type->name ) {
						return false;
					}

					if ( $post_type->_builtin && ! in_array( $post_type->name, array( 'post', 'page' ), true ) ) {
						return false;
					}

					return current_user_can( $post_type->cap->edit_posts );
				}
			)
		);
	}

	/**
	 * Render a one-time import result.
	 *
	 * @param array|null $result Import result.
	 * @return void
	 */
	private function render_result( $result ) {
		if ( ! is_array( $result ) ) {
			return;
		}

		if ( ! empty( $result['error'] ) ) {
			?>
			<div class="notice notice-error inline">
				<p><?php echo esc_html( $result['error'] ); ?></p>
			</div>
			<?php
			return;
		}

		$created = isset( $result['created'] ) ? $result['created'] : array();
		$failed  = isset( $result['failed'] ) ? $result['failed'] : array();
		$skipped = isset( $result['skipped'] ) ? $result['skipped'] : array();
		?>
		<div class="notice <?php echo empty( $failed ) && empty( $skipped ) ? 'notice-success' : 'notice-warning'; ?> inline ftmzi-result">
			<p>
				<strong>
					<?php
					printf(
						/* translators: 1: created count, 2: failed count, 3: skipped file count. */
						esc_html__( 'Import complete: %1$d succeeded, %2$d failed, and %3$d files were skipped.', 'fangtao-md-io' ),
						count( $created ),
						count( $failed ),
						count( $skipped )
					);
					?>
				</strong>
			</p>

			<?php if ( $created ) : ?>
				<ul>
					<?php foreach ( $created as $post ) : ?>
						<li>
							<a href="<?php echo esc_url( get_edit_post_link( $post['id'], 'raw' ) ); ?>">
								<?php echo esc_html( $post['title'] ); ?>
							</a>
							<span><?php echo esc_html( $post['file'] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( $failed ) : ?>
				<ul>
					<?php foreach ( $failed as $failure ) : ?>
						<li><?php echo esc_html( $failure['file'] . '：' . $failure['message'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( $skipped ) : ?>
				<details>
					<summary><?php esc_html_e( 'View skipped ZIP archives without Markdown', 'fangtao-md-io' ); ?></summary>
					<ul>
						<?php foreach ( $skipped as $file ) : ?>
							<li><?php echo esc_html( $file ); ?></li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>

			<?php if ( ! empty( $result['warnings'] ) ) : ?>
				<details>
					<summary><?php esc_html_e( 'View image import warnings', 'fangtao-md-io' ); ?></summary>
					<ul>
						<?php foreach ( $result['warnings'] as $warning ) : ?>
							<li><?php echo esc_html( $warning ); ?></li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
		</div>
		<?php
	}
}
