<?php
/**
 * WordPress admin integration.
 *
 * @package Fangtao_Markdown_Zip_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FTMZI_Admin {

	const MENU_SLUG = 'fangtao-markdown';
	const PAGE_SLUG = 'fangtao-markdown-zip-importer';
	const EXPORT_PAGE_SLUG = 'fangtao-markdown-exporter';
	const DEFAULT_STATUS_OPTION = 'ftmzi_default_post_status';
	const REMOTE_IMAGES_OPTION = 'ftmzi_import_remote_images';

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
		add_action( 'admin_post_ftmzi_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_ftmzi_filtered_export', array( $this, 'handle_filtered_export' ) );
		add_action( 'admin_post_ftmzi_save_settings', array( $this, 'handle_save_settings' ) );
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
			__( 'Markdown 导入', 'fangtao-markdown-zip-importer' ),
			__( 'Markdown', 'fangtao-markdown-zip-importer' ),
			'edit_posts',
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'none',
			81
		);

		$this->screen_id = add_submenu_page(
			self::MENU_SLUG,
			__( 'Markdown 导入', 'fangtao-markdown-zip-importer' ),
			__( 'Markdown 导入', 'fangtao-markdown-zip-importer' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		$this->export_screen_id = add_submenu_page(
			self::MENU_SLUG,
			__( 'Markdown 导出', 'fangtao-markdown-zip-importer' ),
			__( 'Markdown 导出', 'fangtao-markdown-zip-importer' ),
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
		$actions['ftmzi_export'] = __( '导出 Markdown ZIP', 'fangtao-markdown-zip-importer' );
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
			esc_html__( '导出 Markdown', 'fangtao-markdown-zip-importer' )
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
			wp_die( esc_html__( '您没有导出文章的权限。', 'fangtao-markdown-zip-importer' ) );
		}

		check_admin_referer( 'ftmzi_export', 'ftmzi_nonce' );

		$post_ids = isset( $_GET['post_ids'] ) ? (array) wp_unslash( $_GET['post_ids'] ) : array();
		$post_ids = array_map( 'absint', $post_ids );

		$extension = isset( $_GET['extension'] ) ? sanitize_key( wp_unslash( $_GET['extension'] ) ) : 'md';
		$this->download_export( $post_ids, $extension );
	}

	/**
	 * Handle an export assembled from the export-page filters.
	 *
	 * @return void
	 */
	public function handle_filtered_export() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( '您没有导出文章的权限。', 'fangtao-markdown-zip-importer' ) );
		}

		check_admin_referer( 'ftmzi_filtered_export', 'ftmzi_export_nonce' );

		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : 'post';
		$extension = isset( $_POST['extension'] ) ? sanitize_key( wp_unslash( $_POST['extension'] ) ) : 'md';
		$post_types = wp_list_pluck( $this->get_exportable_post_types(), 'name' );

		if ( ! in_array( $post_type, $post_types, true ) ) {
			wp_die( esc_html__( '所选内容类型不可导出。', 'fangtao-markdown-zip-importer' ) );
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
				wp_die( esc_html__( '您没有导出所选内容的权限。', 'fangtao-markdown-zip-importer' ) );
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
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( '您没有导入文章的权限。', 'fangtao-markdown-zip-importer' ) );
		}

		check_admin_referer( 'ftmzi_import_archive', 'ftmzi_nonce' );

		$post_type   = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : 'post';
		$post_status = isset( $_POST['post_status'] ) ? sanitize_key( wp_unslash( $_POST['post_status'] ) ) : 'draft';
		$category_id = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;
		$post_object = get_post_type_object( $post_type );

		if (
			! $post_object ||
			empty( $post_object->show_ui ) ||
			! current_user_can( $post_object->cap->edit_posts )
		) {
			$this->redirect_with_result(
				new WP_Error(
					'ftmzi_post_type',
					__( '无权导入到所选内容类型。', 'fangtao-markdown-zip-importer' )
				)
			);
		}

		if ( 'publish' === $post_status && ! current_user_can( $post_object->cap->publish_posts ) ) {
			$post_status = 'draft';
		}

		if ( ! in_array( $post_status, array( 'draft', 'publish' ), true ) ) {
			$post_status = 'draft';
		}

		if ( 'post' !== $post_type || ( $category_id && ! term_exists( $category_id, 'category' ) ) ) {
			$category_id = 0;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File metadata is validated by the importer before use.
		$upload               = isset( $_FILES['markdown_zip'] ) ? $_FILES['markdown_zip'] : array();
		$importer             = new FTMZI_Importer();
		$import_remote_images = (bool) get_option( self::REMOTE_IMAGES_OPTION, false );
		$result               = $importer->import( $upload, $post_type, $post_status, $category_id, $import_remote_images );

		$this->redirect_with_result( $result );
	}

	/**
	 * Save importer settings.
	 *
	 * @return void
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '您没有修改插件设置的权限。', 'fangtao-markdown-zip-importer' ) );
		}

		check_admin_referer( 'ftmzi_save_settings', 'ftmzi_settings_nonce' );

		$default_status       = isset( $_POST['default_post_status'] ) ? sanitize_key( wp_unslash( $_POST['default_post_status'] ) ) : 'draft';
		$import_remote_images = isset( $_POST['import_remote_images'] ) ? 1 : 0;

		if ( ! in_array( $default_status, array( 'draft', 'publish' ), true ) ) {
			$default_status = 'draft';
		}

		update_option( self::DEFAULT_STATUS_OPTION, $default_status, false );
		update_option( self::REMOTE_IMAGES_OPTION, $import_remote_images, false );

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
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$result               = null;
		$default_status       = get_option( self::DEFAULT_STATUS_OPTION, 'draft' );
		$import_remote_images = (bool) get_option( self::REMOTE_IMAGES_OPTION, false );

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
		<div class="wrap ftmzi-wrap">
			<h1><?php esc_html_e( 'Markdown 导入', 'fangtao-markdown-zip-importer' ); ?></h1>
			<p class="ftmzi-intro">
				<?php esc_html_e( '直接上传单个 Markdown 文件，或上传包含 Markdown 和本地图片的 ZIP。每个 Markdown 文件会创建一篇内容。', 'fangtao-markdown-zip-importer' ); ?>
			</p>

			<?php $this->render_result( $result ); ?>

			<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible inline">
					<p><?php esc_html_e( '导入设置已保存。', 'fangtao-markdown-zip-importer' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( ! class_exists( 'ZipArchive' ) ) : ?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( '服务器未启用 PHP ZIP 扩展，ZIP 导入将使用 WordPress 内置解压机制。', 'fangtao-markdown-zip-importer' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( ! class_exists( 'League\CommonMark\GithubFlavoredMarkdownConverter' ) ) : ?>
				<div class="notice notice-error inline">
					<p><?php esc_html_e( 'Markdown 解析组件缺失，请重新安装本插件。', 'fangtao-markdown-zip-importer' ); ?></p>
				</div>
			<?php else : ?>
				<form class="ftmzi-card" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" enctype="multipart/form-data">
					<input type="hidden" name="action" value="ftmzi_import">
					<?php wp_nonce_field( 'ftmzi_import_archive', 'ftmzi_nonce' ); ?>

					<div class="ftmzi-field">
						<label for="ftmzi-markdown-zip"><?php esc_html_e( 'Markdown 或 ZIP 文件', 'fangtao-markdown-zip-importer' ); ?></label>
						<input id="ftmzi-markdown-zip" name="markdown_zip" type="file" accept=".zip,.md,.markdown,application/zip,text/markdown,text/plain" required>
						<p class="description"><?php esc_html_e( '支持 .md、.markdown 和 .zip 文件。', 'fangtao-markdown-zip-importer' ); ?></p>
					</div>

					<div class="ftmzi-fields-row ftmzi-fields-row--import">
						<div class="ftmzi-field">
							<label for="ftmzi-post-type"><?php esc_html_e( '导入到', 'fangtao-markdown-zip-importer' ); ?></label>
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
							<label for="ftmzi-category-id"><?php esc_html_e( '导入到分类', 'fangtao-markdown-zip-importer' ); ?></label>
							<?php
							wp_dropdown_categories(
								array(
									'hide_empty'        => false,
									'id'                => 'ftmzi-category-id',
									'name'              => 'category_id',
									'option_none_value' => '0',
									'show_option_none'  => __( '不指定分类', 'fangtao-markdown-zip-importer' ),
								)
							);
							?>
						</div>

						<div class="ftmzi-field">
							<label for="ftmzi-post-status"><?php esc_html_e( '文章状态', 'fangtao-markdown-zip-importer' ); ?></label>
							<select id="ftmzi-post-status" name="post_status">
								<option value="draft"<?php selected( 'draft', $default_status ); ?>><?php esc_html_e( '草稿', 'fangtao-markdown-zip-importer' ); ?></option>
								<?php if ( current_user_can( 'publish_posts' ) ) : ?>
									<option value="publish"<?php selected( 'publish', $default_status ); ?>><?php esc_html_e( '立即发布', 'fangtao-markdown-zip-importer' ); ?></option>
								<?php endif; ?>
							</select>
						</div>
					</div>

					<?php submit_button( __( '上传并导入', 'fangtao-markdown-zip-importer' ), 'primary', 'submit', false ); ?>
				</form>

				<div class="ftmzi-guide">
					<h2><?php esc_html_e( '导入说明', 'fangtao-markdown-zip-importer' ); ?></h2>
					<p><?php esc_html_e( '纯 Markdown 文件可直接上传；如正文引用本地图片，请将 Markdown 与图片按相对路径一起打包为 ZIP。', 'fangtao-markdown-zip-importer' ); ?></p>
					<pre><code>articles/
  living-room.md
  images/
    living-room.jpg</code></pre>
					<p>
						<?php esc_html_e( 'Markdown 中使用相对路径：', 'fangtao-markdown-zip-importer' ); ?>
						<code>![客厅](images/living-room.jpg)</code>
					</p>
					<p>
						<?php esc_html_e( '可选 Front Matter 字段：title、slug、permalink、excerpt、date、status、categories、tags、featured_image 和 featured_image_id。未指定封面时，正文首张已导入图片会设为特色图片。', 'fangtao-markdown-zip-importer' ); ?>
					</p>
				</div>

				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<form class="ftmzi-card" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
						<input type="hidden" name="action" value="ftmzi_save_settings">
						<?php wp_nonce_field( 'ftmzi_save_settings', 'ftmzi_settings_nonce' ); ?>

						<h2><?php esc_html_e( '导入设置', 'fangtao-markdown-zip-importer' ); ?></h2>
						<div class="ftmzi-field">
							<label for="ftmzi-default-post-status"><?php esc_html_e( '默认文章状态', 'fangtao-markdown-zip-importer' ); ?></label>
							<select id="ftmzi-default-post-status" name="default_post_status">
								<option value="draft"<?php selected( 'draft', $default_status ); ?>><?php esc_html_e( '草稿', 'fangtao-markdown-zip-importer' ); ?></option>
								<option value="publish"<?php selected( 'publish', $default_status ); ?>><?php esc_html_e( '立即发布', 'fangtao-markdown-zip-importer' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( '用于设置导入表单首次打开时默认选中的文章状态，单次导入仍可临时修改。', 'fangtao-markdown-zip-importer' ); ?></p>
						</div>

						<div class="ftmzi-field ftmzi-field--checkbox">
							<label for="ftmzi-import-remote-images">
								<input id="ftmzi-import-remote-images" name="import_remote_images" type="checkbox" value="1"<?php checked( $import_remote_images ); ?>>
								<?php esc_html_e( '自动导入远程图片', 'fangtao-markdown-zip-importer' ); ?>
							</label>
							<p class="description"><?php esc_html_e( '开启后，正文和特色图片中的 HTTP(S) 图片会下载到媒体库；默认关闭。', 'fangtao-markdown-zip-importer' ); ?></p>
						</div>

						<?php submit_button( __( '保存设置', 'fangtao-markdown-zip-importer' ), 'secondary', 'submit', false ); ?>
					</form>
				<?php endif; ?>
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
		<div class="wrap ftmzi-wrap">
			<h1><?php esc_html_e( 'Markdown 导出', 'fangtao-markdown-zip-importer' ); ?></h1>
			<p class="ftmzi-intro">
				<?php esc_html_e( '从内容列表中单独或批量导出 Markdown ZIP。媒体库图片会写入 images 目录，并在 Markdown 中使用相对路径。', 'fangtao-markdown-zip-importer' ); ?>
			</p>

			<?php if ( ! class_exists( 'ZipArchive' ) ) : ?>
				<div class="notice <?php echo extension_loaded( 'zlib' ) ? 'notice-info' : 'notice-error'; ?> inline">
					<p>
						<?php
						if ( extension_loaded( 'zlib' ) ) {
							esc_html_e( '服务器未启用 PHP ZIP 扩展，ZIP 导出将使用 WordPress 内置 PclZip。', 'fangtao-markdown-zip-importer' );
						} else {
							esc_html_e( '服务器未启用 PHP ZIP 或 zlib 扩展，无法创建压缩包。', 'fangtao-markdown-zip-importer' );
						}
						?>
					</p>
				</div>
			<?php endif; ?>

			<form class="ftmzi-card" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="ftmzi_filtered_export">
				<?php wp_nonce_field( 'ftmzi_filtered_export', 'ftmzi_export_nonce' ); ?>

				<h2><?php esc_html_e( '批量筛选导出', 'fangtao-markdown-zip-importer' ); ?></h2>
				<div class="ftmzi-fields-row ftmzi-fields-row--export">
					<div class="ftmzi-field">
						<label for="ftmzi-export-post-type"><?php esc_html_e( '内容类型', 'fangtao-markdown-zip-importer' ); ?></label>
						<select id="ftmzi-export-post-type" name="post_type">
							<?php foreach ( $post_types as $post_type ) : ?>
								<option value="<?php echo esc_attr( $post_type->name ); ?>"><?php echo esc_html( $post_type->labels->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="ftmzi-field" data-ftmzi-export-post-filter>
						<label for="ftmzi-export-category"><?php esc_html_e( '文章分类', 'fangtao-markdown-zip-importer' ); ?></label>
						<?php
						wp_dropdown_categories(
							array(
								'hide_empty'        => false,
								'id'                => 'ftmzi-export-category',
								'name'              => 'category_id',
								'option_none_value' => '0',
								'show_option_none'  => __( '全部分类', 'fangtao-markdown-zip-importer' ),
							)
						);
						?>
					</div>

					<div class="ftmzi-field" data-ftmzi-export-post-filter>
						<label for="ftmzi-export-tag"><?php esc_html_e( '文章标签', 'fangtao-markdown-zip-importer' ); ?></label>
						<select id="ftmzi-export-tag" name="tag_id">
							<option value="0"><?php esc_html_e( '全部标签', 'fangtao-markdown-zip-importer' ); ?></option>
							<?php foreach ( $tags as $tag ) : ?>
								<option value="<?php echo esc_attr( $tag->term_id ); ?>"><?php echo esc_html( $tag->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="ftmzi-field">
						<label for="ftmzi-export-extension"><?php esc_html_e( '文件扩展名', 'fangtao-markdown-zip-importer' ); ?></label>
						<select id="ftmzi-export-extension" name="extension">
							<option value="md">.md</option>
							<option value="markdown">.markdown</option>
						</select>
					</div>
				</div>

				<?php submit_button( __( '导出筛选结果', 'fangtao-markdown-zip-importer' ), 'primary', 'submit', false ); ?>
			</form>

			<div class="ftmzi-card">
				<h2><?php esc_html_e( '选择要导出的内容', 'fangtao-markdown-zip-importer' ); ?></h2>
				<p><?php esc_html_e( '打开对应内容列表。单篇内容使用标题下方的“导出 Markdown”；多篇内容先勾选，再从“批量操作”中选择“导出 Markdown ZIP”。', 'fangtao-markdown-zip-importer' ); ?></p>

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
									esc_html__( '已发布 %d 篇', 'fangtao-markdown-zip-importer' ),
									$count
								);
								?>
							</span>
						</a>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="ftmzi-guide">
				<h2><?php esc_html_e( 'ZIP 目录结构', 'fangtao-markdown-zip-importer' ); ?></h2>
				<p><?php esc_html_e( '单篇导出：', 'fangtao-markdown-zip-importer' ); ?></p>
				<pre><code>article.md（或 article.markdown）
images/
  article-image.jpg</code></pre>
				<p><?php esc_html_e( '批量导出时，每篇内容会放入独立目录，目录内仍保持相同结构。', 'fangtao-markdown-zip-importer' ); ?></p>
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
		?>
		<div class="notice <?php echo empty( $failed ) ? 'notice-success' : 'notice-warning'; ?> inline ftmzi-result">
			<p>
				<strong>
					<?php
					printf(
						/* translators: 1: created count, 2: failed count. */
						esc_html__( '导入完成：成功 %1$d 篇，失败 %2$d 篇。', 'fangtao-markdown-zip-importer' ),
						count( $created ),
						count( $failed )
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

			<?php if ( ! empty( $result['warnings'] ) ) : ?>
				<details>
					<summary><?php esc_html_e( '查看图片导入警告', 'fangtao-markdown-zip-importer' ); ?></summary>
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
