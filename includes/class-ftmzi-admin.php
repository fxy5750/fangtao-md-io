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
			__( 'Markdown 导入', 'fangtao-md-io' ),
			__( 'Markdown', 'fangtao-md-io' ),
			'edit_posts',
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'none',
			81
		);

		$this->screen_id = add_submenu_page(
			self::MENU_SLUG,
			__( 'Markdown 导入', 'fangtao-md-io' ),
			__( 'Markdown 导入', 'fangtao-md-io' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		$this->export_screen_id = add_submenu_page(
			self::MENU_SLUG,
			__( 'Markdown 导出', 'fangtao-md-io' ),
			__( 'Markdown 导出', 'fangtao-md-io' ),
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
					'preparing'     => __( '正在准备 %d 个文件。', 'fangtao-md-io' ),
					'importing'     => __( '正在导入：%s', 'fangtao-md-io' ),
					'waiting'       => __( '等待开始导入。', 'fangtao-md-io' ),
					'pending'       => __( '等待处理', 'fangtao-md-io' ),
					'processing'    => __( '处理中', 'fangtao-md-io' ),
					'success'       => __( '已导入 %d 篇内容。', 'fangtao-md-io' ),
					'partial'       => __( '已导入 %1$d 篇内容，失败 %2$d 篇。', 'fangtao-md-io' ),
					'skipped'       => __( '已跳过：压缩包内未找到受支持的 Markdown 文件。', 'fangtao-md-io' ),
					'failed'        => __( '导入失败：%s', 'fangtao-md-io' ),
					'networkFailed' => __( '请求失败，请检查网络或服务器日志。', 'fangtao-md-io' ),
					'completed'     => __( '导入完成：成功 %1$d 篇，失败 %2$d 个文件，跳过 %3$d 个文件。', 'fangtao-md-io' ),
					'buttonLoading' => __( '正在分批导入…', 'fangtao-md-io' ),
					'buttonDefault' => __( '上传并导入', 'fangtao-md-io' ),
					'clearLogsConfirmation' => __( '确定要清除所有导入统计和日志吗？此操作不会删除文章、媒体文件或设置。', 'fangtao-md-io' ),
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
		$actions['ftmzi_export'] = __( '导出 Markdown ZIP', 'fangtao-md-io' );
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
			esc_html__( '导出 Markdown', 'fangtao-md-io' )
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
			wp_die( esc_html__( '您没有导出文章的权限。', 'fangtao-md-io' ) );
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
			wp_die( esc_html__( '您没有导出文章的权限。', 'fangtao-md-io' ) );
		}

		check_admin_referer( 'ftmzi_filtered_export', 'ftmzi_export_nonce' );

		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : 'post';
		$extension = isset( $_POST['extension'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['extension'] ) ) ) : 'md';
		$post_types = wp_list_pluck( $this->get_exportable_post_types(), 'name' );

		if ( ! in_array( $post_type, $post_types, true ) ) {
			wp_die( esc_html__( '所选内容类型不可导出。', 'fangtao-md-io' ) );
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
				wp_die( esc_html__( '您没有导出所选内容的权限。', 'fangtao-md-io' ) );
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
			wp_die( esc_html__( '您没有导入文章的权限。', 'fangtao-md-io' ) );
		}

		check_admin_referer( 'ftmzi_import_archive', 'ftmzi_nonce' );

		$options = $this->get_import_options();

		if ( is_wp_error( $options ) ) {
			$this->redirect_with_result( $options );
		}

		$uploads = $this->get_import_uploads();

		if ( empty( $uploads ) ) {
			$this->redirect_with_result( new WP_Error( 'ftmzi_missing_upload', __( '请选择至少一个 Markdown 或 ZIP 文件。', 'fangtao-md-io' ) ) );
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
			$file_name    = sanitize_file_name( wp_basename( (string) $upload['name'] ) );
			$import_result = $importer->import( $upload, $options['post_type'], $options['post_status'], $options['category_id'], $import_remote_images, $options['markdown_parser'], $options['post_date'], $options['post_password'] );
			$this->record_import_log( $file_name, $import_result );

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
			wp_send_json_error( array( 'message' => __( '您没有导入文章的权限。', 'fangtao-md-io' ) ), 403 );
		}

		check_ajax_referer( 'ftmzi_import_archive', 'ftmzi_nonce' );

		$options = $this->get_import_options();

		if ( is_wp_error( $options ) ) {
			wp_send_json_error( array( 'message' => $options->get_error_message() ), 400 );
		}

		$uploads = $this->get_import_uploads();

		if ( 1 !== count( $uploads ) ) {
			wp_send_json_error( array( 'message' => __( '请选择一个 Markdown 或 ZIP 文件。', 'fangtao-md-io' ) ), 400 );
		}

		$upload        = $uploads[0];
		$file_name     = sanitize_file_name( wp_basename( (string) $upload['name'] ) );
		$importer      = new FTMZI_Importer();
		$import_result = $importer->import(
			$upload,
			$options['post_type'],
			$options['post_status'],
			$options['category_id'],
			(bool) get_option( self::REMOTE_IMAGES_OPTION, false ),
			$options['markdown_parser'],
			$options['post_date'],
			$options['post_password']
		);
		$this->record_import_log( $file_name, $import_result );

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
			return new WP_Error( 'ftmzi_post_type', __( '无权导入到所选内容类型。', 'fangtao-md-io' ) );
		}

		if ( is_wp_error( $post_date ) ) {
			return $post_date;
		}

		if ( $use_password && '' === $post_password ) {
			return new WP_Error( 'ftmzi_post_password', __( '请输入访问密码。', 'fangtao-md-io' ) );
		}

		if ( $is_private && $use_password ) {
			return new WP_Error( 'ftmzi_private_password', __( '私密文章不能同时设置访问密码。', 'fangtao-md-io' ) );
		}

		if ( 'publish' === $post_status && ! current_user_can( $post_object->cap->publish_posts ) ) {
			$post_status = 'draft';
		}

		if ( ! in_array( $post_status, array( 'draft', 'publish' ), true ) ) {
			$post_status = 'draft';
		}

		if ( $is_private ) {
			if ( ! current_user_can( $post_object->cap->publish_posts ) ) {
				return new WP_Error( 'ftmzi_private_post', __( '您没有将文章设为私密的权限。', 'fangtao-md-io' ) );
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
			return new WP_Error( 'ftmzi_post_date', __( '文章日期格式无效。', 'fangtao-md-io' ) );
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
			wp_die( esc_html__( '您没有修改插件设置的权限。', 'fangtao-md-io' ) );
		}

		check_admin_referer( 'ftmzi_save_settings', 'ftmzi_settings_nonce' );

		$default_status       = isset( $_POST['default_post_status'] ) ? sanitize_key( wp_unslash( $_POST['default_post_status'] ) ) : 'draft';
		$default_parser       = isset( $_POST['default_markdown_parser'] )
			? FTMZI_Markdown::sanitize_parser( wp_unslash( $_POST['default_markdown_parser'] ) )
			: FTMZI_Markdown::DEFAULT_PARSER;
		$import_remote_images = isset( $_POST['import_remote_images'] ) ? 1 : 0;
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
			wp_die( esc_html__( '您没有修改插件设置的权限。', 'fangtao-md-io' ) );
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
	 * Record a completed import without retaining imported content or media data.
	 *
	 * @param string         $file_name     Submitted filename.
	 * @param array|WP_Error $import_result Import result.
	 * @return void
	 */
	private function record_import_log( $file_name, $import_result ) {
		$status  = 'success';
		$created = 0;
		$failed  = 0;
		$message = '';

		if ( is_wp_error( $import_result ) ) {
			$status  = 'ftmzi_no_markdown' === $import_result->get_error_code() ? 'skipped' : 'failed';
			$failed  = 'failed' === $status ? 1 : 0;
			$message = $import_result->get_error_message();
		} else {
			$created = ! empty( $import_result['created'] ) ? count( $import_result['created'] ) : 0;
			$failed  = ! empty( $import_result['failed'] ) ? count( $import_result['failed'] ) : 0;
			$status  = $failed ? ( $created ? 'partial' : 'failed' ) : 'success';

			if ( $failed && ! empty( $import_result['failed'][0]['message'] ) ) {
				$message = $import_result['failed'][0]['message'];
			}
		}

		$logs = $this->get_import_logs();
		array_unshift(
			$logs,
			array(
				'file'    => sanitize_file_name( (string) $file_name ),
				'status'  => $status,
				'created' => $created,
				'failed'  => $failed,
				'message' => substr( sanitize_text_field( wp_strip_all_tags( (string) $message ) ), 0, 240 ),
				'time'    => current_time( 'mysql' ),
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
				'file'    => sanitize_file_name( (string) $log['file'] ),
				'status'  => $log['status'],
				'created' => absint( isset( $log['created'] ) ? $log['created'] : 0 ),
				'failed'  => absint( isset( $log['failed'] ) ? $log['failed'] : 0 ),
				'message' => sanitize_text_field( isset( $log['message'] ) ? $log['message'] : '' ),
				'time'    => sanitize_text_field( isset( $log['time'] ) ? $log['time'] : '' ),
			);
		}

		return $normalized;
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
			<p class="ftmzi-import-log__empty"><?php esc_html_e( '暂无导入日志。', 'fangtao-md-io' ); ?></p>
			<?php
			return;
		}

		$status_labels = array(
			'success' => __( '导入成功', 'fangtao-md-io' ),
			'partial' => __( '部分导入', 'fangtao-md-io' ),
			'failed'  => __( '导入失败', 'fangtao-md-io' ),
			'skipped' => __( '无效内容', 'fangtao-md-io' ),
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
			<h1><?php esc_html_e( 'Markdown 导入', 'fangtao-md-io' ); ?></h1>
			<p class="ftmzi-intro">
				<?php esc_html_e( '可一次上传多个 Markdown 文件或 ZIP。每个 Markdown 文件会创建一篇内容；不含 Markdown 的 ZIP 会被跳过。', 'fangtao-md-io' ); ?>
			</p>

			</div>

			<?php $this->render_result( $result ); ?>

			<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible inline">
					<p><?php esc_html_e( '导入设置已保存。', 'fangtao-md-io' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['logs-cleared'] ) ) : ?>
				<div class="notice notice-success is-dismissible inline">
					<p><?php esc_html_e( '导入统计日志已清除。', 'fangtao-md-io' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( ! class_exists( 'ZipArchive' ) ) : ?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( '服务器未启用 PHP ZIP 扩展，ZIP 导入将使用 WordPress 内置解压机制。', 'fangtao-md-io' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $missing_parsers ) : ?>
				<div class="notice notice-error inline">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: parser names. */
								__( '以下 Markdown 解析组件缺失，对应选项暂不可用：%s。请重新安装本插件。', 'fangtao-md-io' ),
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
						<label for="ftmzi-markdown-zip"><?php esc_html_e( 'Markdown 或 ZIP 文件', 'fangtao-md-io' ); ?></label>
						<div class="ftmzi-file-control">
							<input id="ftmzi-markdown-zip" name="markdown_zip[]" type="file" accept=".zip,.md,.markdown,.mdown,.mkdn,.mkd,.mdwn,.mdtxt,.mdtext,.文本,.txt,application/zip,text/markdown,text/plain" multiple required>
							<button class="ftmzi-file-clear" type="button" data-ftmzi-file-clear hidden aria-label="<?php esc_attr_e( '清空所选文件', 'fangtao-md-io' ); ?>" title="<?php esc_attr_e( '清空所选文件', 'fangtao-md-io' ); ?>">
								<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
								<span class="screen-reader-text"><?php esc_html_e( '清空所选文件', 'fangtao-md-io' ); ?></span>
							</button>
						</div>
						<p class="description"><?php esc_html_e( '支持一次选择多个 .md、.markdown、.mdown、.mkdn、.mkd、.mdwn、.mdtxt、.mdtext、.文本、.txt 和 .zip 文件，不区分扩展名大小写。未包含 Markdown 的 ZIP 会自动跳过。', 'fangtao-md-io' ); ?></p>
					</div>

					<div class="ftmzi-fields-row ftmzi-fields-row--import">
						<div class="ftmzi-field">
							<label for="ftmzi-post-type"><?php esc_html_e( '导入到', 'fangtao-md-io' ); ?></label>
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
							<label for="ftmzi-category-id"><?php esc_html_e( '导入到分类', 'fangtao-md-io' ); ?></label>
							<?php
							wp_dropdown_categories(
								array(
									'hide_empty'        => false,
									'id'                => 'ftmzi-category-id',
									'name'              => 'category_id',
									'option_none_value' => '0',
									'show_option_none'  => __( '不指定分类', 'fangtao-md-io' ),
								)
							);
							?>
						</div>

						<div class="ftmzi-field">
							<label for="ftmzi-post-status"><?php esc_html_e( '文章状态', 'fangtao-md-io' ); ?></label>
							<select id="ftmzi-post-status" name="post_status">
								<option value="draft"<?php selected( 'draft', $default_status ); ?>><?php esc_html_e( '草稿', 'fangtao-md-io' ); ?></option>
								<?php if ( current_user_can( 'publish_posts' ) ) : ?>
									<option value="publish"<?php selected( 'publish', $default_status ); ?>><?php esc_html_e( '立即发布', 'fangtao-md-io' ); ?></option>
								<?php endif; ?>
							</select>
						</div>
					</div>

					<div class="ftmzi-field">
						<label for="ftmzi-markdown-parser"><?php esc_html_e( 'Markdown 解析器', 'fangtao-md-io' ); ?></label>
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
							<?php esc_html_e( '当前 Markdown 风格：', 'fangtao-md-io' ); ?>
							<strong id="ftmzi-parser-flavor"><?php echo esc_html( $markdown_parsers[ $default_parser ]['flavor'] ); ?></strong>
							<?php esc_html_e( '。可在传统、GitHub 和 Extra 三种风格之间选择。', 'fangtao-md-io' ); ?>
						</p>
					</div>

					<div class="ftmzi-fields-row ftmzi-fields-row--import-options">
						<div class="ftmzi-field ftmzi-field--post-date" data-ftmzi-date-picker>
							<label for="ftmzi-post-date"><?php esc_html_e( '文章发布日期（可选）', 'fangtao-md-io' ); ?></label>
							<input id="ftmzi-post-date-value" name="post_date" type="hidden">
							<div class="ftmzi-date-picker__trigger">
								<input id="ftmzi-post-date" type="text" inputmode="numeric" autocomplete="off" placeholder="YYYY-MM-DD HH:MM:SS" aria-describedby="ftmzi-post-date-help" aria-expanded="false" aria-controls="ftmzi-post-date-popover" data-ftmzi-invalid-message="<?php esc_attr_e( '请使用 YYYY-MM-DD HH:MM:SS 格式。', 'fangtao-md-io' ); ?>">
								<button class="ftmzi-date-picker__toggle" type="button" data-ftmzi-date-toggle aria-label="<?php esc_attr_e( '选择发布日期', 'fangtao-md-io' ); ?>" title="<?php esc_attr_e( '选择发布日期', 'fangtao-md-io' ); ?>" aria-expanded="false" aria-controls="ftmzi-post-date-popover">
									<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
								</button>
							</div>
							<div id="ftmzi-post-date-popover" class="ftmzi-date-picker__popover" data-ftmzi-date-popover role="dialog" aria-label="<?php esc_attr_e( '选择发布日期', 'fangtao-md-io' ); ?>" aria-hidden="true">
								<div class="ftmzi-date-picker__input">
									<label for="ftmzi-post-datetime-picker"><?php esc_html_e( '选择发布日期', 'fangtao-md-io' ); ?></label>
									<input id="ftmzi-post-datetime-picker" type="datetime-local" step="1">
								</div>
								<div class="ftmzi-date-picker__actions">
									<button class="button" type="button" data-ftmzi-date-now>
										<span class="dashicons dashicons-clock" aria-hidden="true"></span>
										<?php esc_html_e( '当前时间', 'fangtao-md-io' ); ?>
									</button>
									<button class="button" type="button" data-ftmzi-date-clear>
										<span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
										<?php esc_html_e( '清空', 'fangtao-md-io' ); ?>
									</button>
									<button class="button button-primary" type="button" data-ftmzi-date-close><?php esc_html_e( '完成', 'fangtao-md-io' ); ?></button>
								</div>
							</div>
							<p id="ftmzi-post-date-help" class="description"><?php esc_html_e( '留空时，ZIP 内的每篇 Markdown 使用压缩包记录的最后修改时间；可手动精确到秒。', 'fangtao-md-io' ); ?></p>
						</div>

						<div class="ftmzi-field">
							<label><?php esc_html_e( '可见性', 'fangtao-md-io' ); ?></label>
							<label class="ftmzi-option-label" for="ftmzi-post-private">
								<input id="ftmzi-post-private" name="post_private" type="checkbox" value="1">
								<?php esc_html_e( '设为私密', 'fangtao-md-io' ); ?>
							</label>
							<p class="description"><?php esc_html_e( '仅管理员和有权限的用户可查看。', 'fangtao-md-io' ); ?></p>
						</div>

						<div class="ftmzi-field">
							<label><?php esc_html_e( '访问密码', 'fangtao-md-io' ); ?></label>
							<label class="ftmzi-option-label" for="ftmzi-use-post-password">
								<input id="ftmzi-use-post-password" name="use_post_password" type="checkbox" value="1">
								<?php esc_html_e( '设置访问密码', 'fangtao-md-io' ); ?>
							</label>
							<input id="ftmzi-post-password" name="post_password" type="password" maxlength="255" autocomplete="new-password" disabled>
							<p class="description"><?php esc_html_e( '私密文章不能使用访问密码；密码未设置时文章保持无密码访问。', 'fangtao-md-io' ); ?></p>
						</div>
					</div>

					<?php submit_button( __( '上传并导入', 'fangtao-md-io' ), 'primary', 'submit', false ); ?>
				</form>

					<aside class="ftmzi-import-sidebar">
						<section class="ftmzi-import-dashboard" data-ftmzi-import-dashboard aria-live="polite">
							<div class="ftmzi-import-dashboard__heading">
								<h2><?php esc_html_e( '导入统计', 'fangtao-md-io' ); ?></h2>
								<button class="ftmzi-import-dashboard__refresh" type="button" data-ftmzi-import-reset aria-label="<?php esc_attr_e( '刷新导入统计', 'fangtao-md-io' ); ?>" title="<?php esc_attr_e( '刷新导入统计', 'fangtao-md-io' ); ?>">
									<span class="dashicons dashicons-update" aria-hidden="true"></span>
									<span class="screen-reader-text"><?php esc_html_e( '刷新导入统计', 'fangtao-md-io' ); ?></span>
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
											<span><?php esc_html_e( '已处理', 'fangtao-md-io' ); ?></span>
										</div>
									</div>
								</div>
								<div class="ftmzi-import-dashboard__metrics">
									<div class="is-success" data-ftmzi-import-legend="success" tabindex="0">
										<span><?php esc_html_e( '导入成功', 'fangtao-md-io' ); ?></span>
										<strong data-ftmzi-import-success><?php echo esc_html( $import_log_summary['success'] ); ?></strong>
									</div>
									<div class="is-failed" data-ftmzi-import-legend="failed" tabindex="0">
										<span><?php esc_html_e( '导入失败', 'fangtao-md-io' ); ?></span>
										<strong data-ftmzi-import-failed><?php echo esc_html( $import_log_summary['failed'] ); ?></strong>
									</div>
									<div class="is-invalid" data-ftmzi-import-legend="invalid" tabindex="0">
										<span><?php esc_html_e( '无效内容', 'fangtao-md-io' ); ?></span>
										<strong data-ftmzi-import-invalid><?php echo esc_html( $import_log_summary['invalid'] ); ?></strong>
									</div>
								</div>
							</div>
							<div class="ftmzi-import-log" aria-label="<?php esc_attr_e( '最近导入日志', 'fangtao-md-io' ); ?>">
								<div class="ftmzi-import-log__heading">
									<h3><?php esc_html_e( '最近导入日志', 'fangtao-md-io' ); ?></h3>
									<span><?php esc_html_e( '保存最近 100 条', 'fangtao-md-io' ); ?></span>
								</div>
								<?php $this->render_import_log_entries(); ?>
							</div>
						</section>

						<section class="ftmzi-import-queue" data-ftmzi-import-queue aria-live="polite">
							<div class="ftmzi-import-queue__heading">
								<h2><?php esc_html_e( '分批导入任务', 'fangtao-md-io' ); ?></h2>
								<span data-ftmzi-import-count>0 / 0</span>
							</div>
							<p class="description" data-ftmzi-import-summary><?php esc_html_e( '等待开始导入。', 'fangtao-md-io' ); ?></p>
							<ol class="ftmzi-import-queue__list" data-ftmzi-import-list></ol>
						</section>
					</aside>
				</div>

				<div class="ftmzi-guide">
					<h2><?php esc_html_e( '导入说明', 'fangtao-md-io' ); ?></h2>
					<p><?php esc_html_e( '纯 Markdown 文件可直接上传；如正文引用本地素材，请将 Markdown 与素材按相对路径一起打包为 ZIP。只有正文实际引用的素材会进入媒体库。', 'fangtao-md-io' ); ?></p>
					<pre><code>articles/
  living-room.md
  images/
    living-room.jpg
  media/
    tour.mp4</code></pre>
					<p>
						<?php esc_html_e( 'Markdown 中使用相对路径：', 'fangtao-md-io' ); ?>
						<code>![客厅](images/living-room.jpg)</code>
					</p>
					<p>
						<?php esc_html_e( '视频可使用 WordPress 短代码，其他素材可使用普通 Markdown 链接：', 'fangtao-md-io' ); ?>
						<code>[video src="media/tour.mp4"]</code>
						<code>[下载资料](media/catalog.pdf)</code>
					</p>
					<p>
						<?php esc_html_e( '可选 Front Matter 字段：title、slug、permalink、excerpt、date、status、categories、tags、featured_image 和 featured_image_id。未指定封面时，正文首张已导入图片会设为特色图片。', 'fangtao-md-io' ); ?>
					</p>
				</div>

				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<form class="ftmzi-card" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
						<input type="hidden" name="action" value="ftmzi_save_settings">
						<?php wp_nonce_field( 'ftmzi_save_settings', 'ftmzi_settings_nonce' ); ?>

						<h2><?php esc_html_e( '导入设置', 'fangtao-md-io' ); ?></h2>
						<div class="ftmzi-field">
							<label for="ftmzi-default-post-status"><?php esc_html_e( '默认文章状态', 'fangtao-md-io' ); ?></label>
							<select id="ftmzi-default-post-status" name="default_post_status">
								<option value="draft"<?php selected( 'draft', $default_status ); ?>><?php esc_html_e( '草稿', 'fangtao-md-io' ); ?></option>
								<option value="publish"<?php selected( 'publish', $default_status ); ?>><?php esc_html_e( '立即发布', 'fangtao-md-io' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( '用于设置导入表单首次打开时默认选中的文章状态，单次导入仍可临时修改。', 'fangtao-md-io' ); ?></p>
						</div>

						<div class="ftmzi-field">
							<label for="ftmzi-default-markdown-parser"><?php esc_html_e( '默认 Markdown 解析器', 'fangtao-md-io' ); ?></label>
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
								<?php esc_html_e( '默认风格：', 'fangtao-md-io' ); ?>
								<strong id="ftmzi-default-parser-flavor"><?php echo esc_html( $markdown_parsers[ $default_parser ]['flavor'] ); ?></strong>
								<?php esc_html_e( '。单次导入仍可临时选择其他解析器。', 'fangtao-md-io' ); ?>
							</p>
						</div>

						<div class="ftmzi-field ftmzi-field--checkbox">
							<label for="ftmzi-import-remote-images">
								<input id="ftmzi-import-remote-images" name="import_remote_images" type="checkbox" value="1"<?php checked( $import_remote_images ); ?>>
								<?php esc_html_e( '自动导入远程图片', 'fangtao-md-io' ); ?>
							</label>
							<p class="description"><?php esc_html_e( '开启后，正文和特色图片中的 HTTP(S) 图片会下载到媒体库；默认关闭。', 'fangtao-md-io' ); ?></p>
						</div>

						<fieldset class="ftmzi-field">
							<legend><?php esc_html_e( 'ZIP 素材格式', 'fangtao-md-io' ); ?></legend>
							<p class="description"><?php esc_html_e( '只解压并导入勾选的安全媒体格式。未勾选的文件会被忽略，PHP 等可执行格式始终不允许。', 'fangtao-md-io' ); ?></p>
							<div class="ftmzi-extension-groups">
								<?php
								$asset_group_labels = array(
									'image'    => __( '图片', 'fangtao-md-io' ),
									'video'    => __( '视频', 'fangtao-md-io' ),
									'audio'    => __( '音频', 'fangtao-md-io' ),
									'document' => __( '文档', 'fangtao-md-io' ),
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
							<h3><?php esc_html_e( '导入大小限制', 'fangtao-md-io' ); ?></h3>
							<p class="description">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: effective PHP upload limit. */
										__( '留空或填写 0 时跟随 PHP/WordPress 上传限制（当前 %s）。手动值仍受服务器自身上传限制约束。', 'fangtao-md-io' ),
										size_format( $import_limits['php_upload_size'] )
									)
								);
								?>
							</p>
						</div>

						<div class="ftmzi-limit-grid">
							<div class="ftmzi-field">
								<label for="ftmzi-max-archive-size"><?php esc_html_e( 'ZIP 文件上限（MB）', 'fangtao-md-io' ); ?></label>
								<input id="ftmzi-max-archive-size" name="max_archive_size_mb" type="number" min="0" max="102400" value="<?php echo esc_attr( $stored_limits['max_archive_size_mb'] ); ?>" placeholder="<?php echo esc_attr( floor( $import_limits['php_upload_size'] / MB_IN_BYTES ) ); ?>">
							</div>
							<div class="ftmzi-field">
								<label for="ftmzi-max-extracted-size"><?php esc_html_e( '解压总量上限（MB）', 'fangtao-md-io' ); ?></label>
								<input id="ftmzi-max-extracted-size" name="max_extracted_size_mb" type="number" min="0" max="102400" value="<?php echo esc_attr( $stored_limits['max_extracted_size_mb'] ); ?>" placeholder="<?php echo esc_attr( floor( $import_limits['php_upload_size'] / MB_IN_BYTES ) ); ?>">
							</div>
							<div class="ftmzi-field">
								<label for="ftmzi-max-markdown-size"><?php esc_html_e( '单个 Markdown 上限（MB）', 'fangtao-md-io' ); ?></label>
								<input id="ftmzi-max-markdown-size" name="max_markdown_size_mb" type="number" min="0" max="102400" value="<?php echo esc_attr( $stored_limits['max_markdown_size_mb'] ); ?>" placeholder="<?php echo esc_attr( floor( $import_limits['php_upload_size'] / MB_IN_BYTES ) ); ?>">
							</div>
							<div class="ftmzi-field">
								<label for="ftmzi-max-asset-size"><?php esc_html_e( '单个素材上限（MB）', 'fangtao-md-io' ); ?></label>
								<input id="ftmzi-max-asset-size" name="max_asset_size_mb" type="number" min="0" max="102400" value="<?php echo esc_attr( $stored_limits['max_asset_size_mb'] ); ?>" placeholder="<?php echo esc_attr( floor( $import_limits['php_upload_size'] / MB_IN_BYTES ) ); ?>">
							</div>
							<div class="ftmzi-field">
								<label for="ftmzi-max-archive-entries"><?php esc_html_e( 'ZIP 文件数量上限', 'fangtao-md-io' ); ?></label>
								<input id="ftmzi-max-archive-entries" name="max_archive_entries" type="number" min="0" max="10000" value="<?php echo esc_attr( $stored_limits['max_archive_entries'] ); ?>" placeholder="<?php echo esc_attr( FTMZI_Importer::DEFAULT_ARCHIVE_ENTRIES ); ?>">
								<p class="description"><?php esc_html_e( '留空或 0 时默认为 500 个。', 'fangtao-md-io' ); ?></p>
							</div>
						</div>

						<?php submit_button( __( '保存设置', 'fangtao-md-io' ), 'secondary', 'submit', false ); ?>
					</form>

					<form class="ftmzi-card ftmzi-card--advanced" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" data-ftmzi-clear-import-log-form>
						<input type="hidden" name="action" value="ftmzi_clear_import_log">
						<?php wp_nonce_field( 'ftmzi_clear_import_log', 'ftmzi_clear_import_log_nonce' ); ?>

						<div class="ftmzi-panel-heading">
							<h2><?php esc_html_e( '高级功能', 'fangtao-md-io' ); ?></h2>
						</div>
						<div class="ftmzi-advanced-action">
							<div>
								<h3><?php esc_html_e( '清除统计日志', 'fangtao-md-io' ); ?></h3>
								<p class="description"><?php esc_html_e( '仅清除本插件的导入统计与最近记录，不会删除文章、媒体文件或导入设置。', 'fangtao-md-io' ); ?></p>
							</div>
							<button class="button button-secondary" type="submit"><?php esc_html_e( '清除统计日志', 'fangtao-md-io' ); ?></button>
						</div>
					</form>
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
			<h1><?php esc_html_e( 'Markdown 导出', 'fangtao-md-io' ); ?></h1>
			<p class="ftmzi-intro">
				<?php esc_html_e( '从内容列表中单独或批量导出 Markdown ZIP。媒体库图片会写入 images 目录，并在 Markdown 中使用相对路径。', 'fangtao-md-io' ); ?>
			</p>

			</div>

			<?php if ( ! class_exists( 'ZipArchive' ) ) : ?>
				<div class="notice <?php echo extension_loaded( 'zlib' ) ? 'notice-info' : 'notice-error'; ?> inline">
					<p>
						<?php
						if ( extension_loaded( 'zlib' ) ) {
							esc_html_e( '服务器未启用 PHP ZIP 扩展，ZIP 导出将使用 WordPress 内置 PclZip。', 'fangtao-md-io' );
						} else {
							esc_html_e( '服务器未启用 PHP ZIP 或 zlib 扩展，无法创建压缩包。', 'fangtao-md-io' );
						}
						?>
					</p>
				</div>
			<?php endif; ?>

			<form class="ftmzi-card" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="ftmzi_filtered_export">
				<?php wp_nonce_field( 'ftmzi_filtered_export', 'ftmzi_export_nonce' ); ?>

				<div class="ftmzi-panel-heading">
				<h2><?php esc_html_e( '批量筛选导出', 'fangtao-md-io' ); ?></h2>
				</div>
				<div class="ftmzi-fields-row ftmzi-fields-row--export">
					<div class="ftmzi-field">
						<label for="ftmzi-export-post-type"><?php esc_html_e( '内容类型', 'fangtao-md-io' ); ?></label>
						<select id="ftmzi-export-post-type" name="post_type">
							<?php foreach ( $post_types as $post_type ) : ?>
								<option value="<?php echo esc_attr( $post_type->name ); ?>"><?php echo esc_html( $post_type->labels->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="ftmzi-field" data-ftmzi-export-post-filter>
						<label for="ftmzi-export-category"><?php esc_html_e( '文章分类', 'fangtao-md-io' ); ?></label>
						<?php
						wp_dropdown_categories(
							array(
								'hide_empty'        => false,
								'id'                => 'ftmzi-export-category',
								'name'              => 'category_id',
								'option_none_value' => '0',
								'show_option_none'  => __( '全部分类', 'fangtao-md-io' ),
							)
						);
						?>
					</div>

					<div class="ftmzi-field" data-ftmzi-export-post-filter>
						<label for="ftmzi-export-tag"><?php esc_html_e( '文章标签', 'fangtao-md-io' ); ?></label>
						<select id="ftmzi-export-tag" name="tag_id">
							<option value="0"><?php esc_html_e( '全部标签', 'fangtao-md-io' ); ?></option>
							<?php foreach ( $tags as $tag ) : ?>
								<option value="<?php echo esc_attr( $tag->term_id ); ?>"><?php echo esc_html( $tag->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="ftmzi-field">
						<label for="ftmzi-export-extension"><?php esc_html_e( '文件扩展名', 'fangtao-md-io' ); ?></label>
						<select id="ftmzi-export-extension" name="extension">
							<?php foreach ( FTMZI_Importer::DOCUMENT_EXTENSIONS as $extension ) : ?>
								<option value="<?php echo esc_attr( $extension ); ?>">.<?php echo esc_html( $extension ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<?php submit_button( __( '导出筛选结果', 'fangtao-md-io' ), 'primary', 'submit', false ); ?>
			</form>

			<div class="ftmzi-card ftmzi-card--content-links">
				<h2><?php esc_html_e( '选择要导出的内容', 'fangtao-md-io' ); ?></h2>
				<p><?php esc_html_e( '打开对应内容列表。单篇内容使用标题下方的“导出 Markdown”；多篇内容先勾选，再从“批量操作”中选择“导出 Markdown ZIP”。', 'fangtao-md-io' ); ?></p>

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
									esc_html__( '已发布 %d 篇', 'fangtao-md-io' ),
									$count
								);
								?>
							</span>
						</a>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="ftmzi-guide">
				<h2><?php esc_html_e( 'ZIP 目录结构', 'fangtao-md-io' ); ?></h2>
				<p><?php esc_html_e( '单篇导出：', 'fangtao-md-io' ); ?></p>
				<pre><code>article.md（或 article.markdown）
images/
  article-image.jpg</code></pre>
				<p><?php esc_html_e( '批量导出时，每篇内容会放入独立目录，目录内仍保持相同结构。', 'fangtao-md-io' ); ?></p>
				<p><code>![图片说明](images/article-image.jpg)</code></p>
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
						esc_html__( '导入完成：成功 %1$d 篇，失败 %2$d 篇，跳过 %3$d 个文件。', 'fangtao-md-io' ),
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
					<summary><?php esc_html_e( '查看已跳过的不含 Markdown 的 ZIP 文件', 'fangtao-md-io' ); ?></summary>
					<ul>
						<?php foreach ( $skipped as $file ) : ?>
							<li><?php echo esc_html( $file ); ?></li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>

			<?php if ( ! empty( $result['warnings'] ) ) : ?>
				<details>
					<summary><?php esc_html_e( '查看图片导入警告', 'fangtao-md-io' ); ?></summary>
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
