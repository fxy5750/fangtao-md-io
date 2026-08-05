<?php
/**
 * ZIP archive importer.
 *
 * @package Fangtao_Markdown_Zip_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FTMZI_Importer {

	const MAX_EXTRACTED_SIZE    = 209715200;
	const MAX_ARCHIVE_ENTRIES   = 500;
	const MAX_MARKDOWN_SIZE     = 2097152;
	const MAX_IMAGE_SIZE        = 20971520;

	/**
	 * Markdown helper.
	 *
	 * @var FTMZI_Markdown
	 */
	private $markdown;

	/**
	 * Imported media cache.
	 *
	 * @var array<string, array{id: int, url: string}>
	 */
	private $media_cache = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->markdown = new FTMZI_Markdown();
	}

	/**
	 * Import an uploaded Markdown file or ZIP archive.
	 *
	 * @param array  $upload      Uploaded file data.
	 * @param string $post_type   Destination post type.
	 * @param string $post_status Destination post status.
	 * @return array|WP_Error
	 */
	public function import( $upload, $post_type, $post_status ) {
		$this->media_cache = array();
		$extension         = $this->validate_upload( $upload );

		if ( is_wp_error( $extension ) ) {
			return $extension;
		}

		if ( 'zip' !== $extension ) {
			$markdown_path = sanitize_file_name( basename( $upload['name'] ) );
			$archive       = array(
				'files'    => array(
					$markdown_path => $upload['tmp_name'],
				),
				'markdown' => array( $markdown_path ),
			);

			return $this->import_documents( $archive, $post_type, $post_status );
		}

		$temp_dir = trailingslashit( get_temp_dir() ) . 'ftmzi-' . wp_generate_uuid4();

		if ( ! wp_mkdir_p( $temp_dir ) ) {
			return new WP_Error(
				'ftmzi_temp_directory',
				__( '无法创建临时解压目录，请检查 PHP 临时目录权限。', 'fangtao-markdown-zip-importer' )
			);
		}

		try {
			$archive = $this->extract_archive( $upload['tmp_name'], $temp_dir );

			if ( is_wp_error( $archive ) ) {
				return $archive;
			}

			return $this->import_documents( $archive, $post_type, $post_status );
		} finally {
			$this->remove_directory( $temp_dir );
		}
	}

	/**
	 * Import all Markdown documents from a prepared file map.
	 *
	 * @param array  $archive     Prepared file map and Markdown paths.
	 * @param string $post_type   Destination post type.
	 * @param string $post_status Destination post status.
	 * @return array
	 */
	private function import_documents( $archive, $post_type, $post_status ) {
		$results = array(
			'created'  => array(),
			'failed'   => array(),
			'warnings' => array(),
		);

		foreach ( $archive['markdown'] as $markdown_path ) {
			$document = $this->import_document(
				$markdown_path,
				$archive['files'],
				$post_type,
				$post_status
			);

			if ( is_wp_error( $document ) ) {
				$results['failed'][] = array(
					'file'    => $markdown_path,
					'message' => $document->get_error_message(),
				);
				continue;
			}

			$results['created'][] = $document;

			foreach ( $document['warnings'] as $warning ) {
				$results['warnings'][] = sprintf(
					/* translators: 1: Markdown filename, 2: warning. */
					__( '%1$s：%2$s', 'fangtao-markdown-zip-importer' ),
					$markdown_path,
					$warning
				);
			}
		}

		return $results;
	}

	/**
	 * Validate uploaded file metadata.
	 *
	 * @param array $upload Uploaded file data.
	 * @return string|WP_Error
	 */
	private function validate_upload( $upload ) {
		if ( empty( $upload['tmp_name'] ) || ! isset( $upload['error'], $upload['size'], $upload['name'] ) ) {
			return new WP_Error(
				'ftmzi_missing_upload',
				__( '请选择一个 Markdown 或 ZIP 文件。', 'fangtao-markdown-zip-importer' )
			);
		}

		if ( UPLOAD_ERR_OK !== (int) $upload['error'] ) {
			return new WP_Error(
				'ftmzi_upload_error',
				sprintf(
					/* translators: %d: PHP upload error code. */
					__( '文件上传失败，错误代码：%d。', 'fangtao-markdown-zip-importer' ),
					(int) $upload['error']
				)
			);
		}

		$extension = strtolower( pathinfo( sanitize_file_name( $upload['name'] ), PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, array( 'zip', 'md', 'markdown' ), true ) ) {
			return new WP_Error(
				'ftmzi_upload_extension',
				__( '仅支持 .md、.markdown 和 .zip 文件。', 'fangtao-markdown-zip-importer' )
			);
		}

		return $extension;
	}

	/**
	 * Safely extract supported archive entries.
	 *
	 * @param string $zip_path ZIP file path.
	 * @param string $temp_dir Temporary extraction directory.
	 * @return array|WP_Error
	 */
	private function extract_archive( $zip_path, $temp_dir ) {
		if ( class_exists( 'ZipArchive' ) ) {
			return $this->extract_archive_ziparchive( $zip_path, $temp_dir );
		}

		return $this->extract_archive_wordpress( $zip_path, $temp_dir );
	}

	/**
	 * Extract supported entries with the PHP ZIP extension.
	 *
	 * @param string $zip_path ZIP file path.
	 * @param string $temp_dir Temporary extraction directory.
	 * @return array|WP_Error
	 */
	private function extract_archive_ziparchive( $zip_path, $temp_dir ) {
		$zip  = new ZipArchive();
		$open = $zip->open( $zip_path );

		if ( true !== $open ) {
			return new WP_Error(
				'ftmzi_invalid_archive',
				__( '无法打开 ZIP 文件，文件可能已损坏或不是有效压缩包。', 'fangtao-markdown-zip-importer' )
			);
		}

		if ( $zip->numFiles > self::MAX_ARCHIVE_ENTRIES ) {
			$zip->close();

			return new WP_Error(
				'ftmzi_archive_entries',
				__( 'ZIP 内文件数量不能超过 500 个。', 'fangtao-markdown-zip-importer' )
			);
		}

		$files          = array();
		$markdown_files = array();
		$total_size     = 0;
		$allowed_images = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' );
		$allowed_docs   = array( 'md', 'markdown' );

		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$stat = $zip->statIndex( $index );

			if ( false === $stat || empty( $stat['name'] ) ) {
				continue;
			}

			$original_path = str_replace( '\\', '/', $stat['name'] );

			if ( '/' === substr( $original_path, -1 ) || 0 === strpos( $original_path, '__MACOSX/' ) ) {
				continue;
			}

			$archive_path = $this->normalize_archive_path( $original_path );

			if ( false === $archive_path || $archive_path !== ltrim( $original_path, './' ) ) {
				$zip->close();

				return new WP_Error(
					'ftmzi_unsafe_path',
					__( 'ZIP 包含不安全的文件路径，导入已停止。', 'fangtao-markdown-zip-importer' )
				);
			}

			if ( $this->is_zip_symlink( $zip, $index ) ) {
				$zip->close();

				return new WP_Error(
					'ftmzi_archive_symlink',
					__( 'ZIP 包含符号链接，导入已停止。', 'fangtao-markdown-zip-importer' )
				);
			}

			$extension = strtolower( pathinfo( $archive_path, PATHINFO_EXTENSION ) );

			if ( ! in_array( $extension, array_merge( $allowed_docs, $allowed_images ), true ) ) {
				continue;
			}

			$file_size = isset( $stat['size'] ) ? (int) $stat['size'] : 0;
			$size_limit = in_array( $extension, $allowed_docs, true ) ? self::MAX_MARKDOWN_SIZE : self::MAX_IMAGE_SIZE;

			if ( $file_size > $size_limit ) {
				$zip->close();

				return new WP_Error(
					'ftmzi_entry_size',
					sprintf(
						/* translators: %s: archive path. */
						__( '文件过大，无法导入：%s', 'fangtao-markdown-zip-importer' ),
						$archive_path
					)
				);
			}

			$total_size += $file_size;

			if ( $total_size > self::MAX_EXTRACTED_SIZE ) {
				$zip->close();

				return new WP_Error(
					'ftmzi_extracted_size',
					__( 'ZIP 解压后的文件总量不能超过 200 MB。', 'fangtao-markdown-zip-importer' )
				);
			}

			$destination = trailingslashit( $temp_dir ) . str_replace( '/', DIRECTORY_SEPARATOR, $archive_path );

			if ( ! wp_mkdir_p( dirname( $destination ) ) ) {
				$zip->close();

				return new WP_Error(
					'ftmzi_extract_directory',
					__( '无法创建 ZIP 子目录。', 'fangtao-markdown-zip-importer' )
				);
			}

			$source_stream      = $zip->getStream( $stat['name'] );
			$destination_stream = fopen( $destination, 'wb' );

			if ( false === $source_stream || false === $destination_stream ) {
				if ( is_resource( $source_stream ) ) {
					fclose( $source_stream );
				}
				if ( is_resource( $destination_stream ) ) {
					fclose( $destination_stream );
				}

				$zip->close();

				return new WP_Error(
					'ftmzi_extract_file',
					sprintf(
						/* translators: %s: archive path. */
						__( '无法解压文件：%s', 'fangtao-markdown-zip-importer' ),
						$archive_path
					)
				);
			}

			$copied_size = stream_copy_to_stream( $source_stream, $destination_stream, $size_limit + 1 );
			fclose( $source_stream );
			fclose( $destination_stream );

			if ( false === $copied_size || $copied_size > $size_limit ) {
				@unlink( $destination );
				$zip->close();

				return new WP_Error(
					'ftmzi_entry_size',
					sprintf(
						/* translators: %s: archive path. */
						__( '文件过大，无法导入：%s', 'fangtao-markdown-zip-importer' ),
						$archive_path
					)
				);
			}

			$files[ $archive_path ] = $destination;

			if ( in_array( $extension, $allowed_docs, true ) ) {
				$markdown_files[] = $archive_path;
			}
		}

		$zip->close();
		natcasesort( $markdown_files );

		if ( empty( $markdown_files ) ) {
			return new WP_Error(
				'ftmzi_no_markdown',
				__( 'ZIP 中没有找到 .md 或 .markdown 文件。', 'fangtao-markdown-zip-importer' )
			);
		}

		return array(
			'files'    => $files,
			'markdown' => array_values( $markdown_files ),
		);
	}

	/**
	 * Extract an archive through WordPress and its bundled PclZip fallback.
	 *
	 * @param string $zip_path ZIP file path.
	 * @param string $temp_dir Temporary extraction directory.
	 * @return array|WP_Error
	 */
	private function extract_archive_wordpress( $zip_path, $temp_dir ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';

		$zip     = new PclZip( $zip_path );
		$entries = $zip->listContent();

		if ( ! is_array( $entries ) || empty( $entries ) ) {
			return new WP_Error(
				'ftmzi_invalid_archive',
				__( '无法打开 ZIP 文件，文件可能已损坏或不是有效压缩包。', 'fangtao-markdown-zip-importer' )
			);
		}

		if ( count( $entries ) > self::MAX_ARCHIVE_ENTRIES ) {
			return new WP_Error(
				'ftmzi_archive_entries',
				__( 'ZIP 内文件数量不能超过 500 个。', 'fangtao-markdown-zip-importer' )
			);
		}

		$expected_files = array();
		$markdown_files = array();
		$total_size     = 0;
		$allowed_images = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' );
		$allowed_docs   = array( 'md', 'markdown' );

		foreach ( $entries as $entry ) {
			if ( empty( $entry['filename'] ) || ! empty( $entry['folder'] ) ) {
				continue;
			}

			$original_path = str_replace( '\\', '/', $entry['filename'] );

			if ( 0 === strpos( $original_path, '__MACOSX/' ) ) {
				continue;
			}

			$archive_path = $this->normalize_archive_path( $original_path );

			if ( false === $archive_path || $archive_path !== ltrim( $original_path, './' ) ) {
				return new WP_Error(
					'ftmzi_unsafe_path',
					__( 'ZIP 包含不安全的文件路径，导入已停止。', 'fangtao-markdown-zip-importer' )
				);
			}

			if (
				isset( $entry['external'] ) &&
				0120000 === ( ( (int) $entry['external'] >> 16 ) & 0170000 )
			) {
				return new WP_Error(
					'ftmzi_archive_symlink',
					__( 'ZIP 包含符号链接，导入已停止。', 'fangtao-markdown-zip-importer' )
				);
			}

			$extension = strtolower( pathinfo( $archive_path, PATHINFO_EXTENSION ) );

			if ( ! in_array( $extension, array_merge( $allowed_docs, $allowed_images ), true ) ) {
				continue;
			}

			$file_size  = isset( $entry['size'] ) ? (int) $entry['size'] : 0;
			$size_limit = in_array( $extension, $allowed_docs, true ) ? self::MAX_MARKDOWN_SIZE : self::MAX_IMAGE_SIZE;

			if ( $file_size > $size_limit ) {
				return new WP_Error(
					'ftmzi_entry_size',
					sprintf(
						/* translators: %s: archive path. */
						__( '文件过大，无法导入：%s', 'fangtao-markdown-zip-importer' ),
						$archive_path
					)
				);
			}

			$total_size += $file_size;

			if ( $total_size > self::MAX_EXTRACTED_SIZE ) {
				return new WP_Error(
					'ftmzi_extracted_size',
					__( 'ZIP 解压后的文件总量不能超过 200 MB。', 'fangtao-markdown-zip-importer' )
				);
			}

			$expected_files[ $archive_path ] = trailingslashit( $temp_dir ) . str_replace( '/', DIRECTORY_SEPARATOR, $archive_path );

			if ( in_array( $extension, $allowed_docs, true ) ) {
				$markdown_files[] = $archive_path;
			}
		}

		if ( empty( $markdown_files ) ) {
			return new WP_Error(
				'ftmzi_no_markdown',
				__( 'ZIP 中没有找到 .md 或 .markdown 文件。', 'fangtao-markdown-zip-importer' )
			);
		}

		global $wp_filesystem;

		if ( ! $wp_filesystem && ! WP_Filesystem() ) {
			return new WP_Error(
				'ftmzi_filesystem',
				__( 'WordPress 无法访问临时解压目录。', 'fangtao-markdown-zip-importer' )
			);
		}

		$disable_ziparchive = static function () {
			return false;
		};

		add_filter( 'unzip_file_use_ziparchive', $disable_ziparchive, PHP_INT_MAX );

		try {
			$unzipped = unzip_file( $zip_path, $temp_dir );
		} finally {
			remove_filter( 'unzip_file_use_ziparchive', $disable_ziparchive, PHP_INT_MAX );
		}

		if ( is_wp_error( $unzipped ) ) {
			return new WP_Error(
				'ftmzi_extract_file',
				sprintf(
					/* translators: %s: WordPress unzip error. */
					__( 'WordPress 无法解压 ZIP：%s', 'fangtao-markdown-zip-importer' ),
					$unzipped->get_error_message()
				)
			);
		}

		$actual_total = 0;

		foreach ( $expected_files as $archive_path => $destination ) {
			if ( ! is_readable( $destination ) ) {
				return new WP_Error(
					'ftmzi_extract_file',
					sprintf(
						/* translators: %s: archive path. */
						__( '无法解压文件：%s', 'fangtao-markdown-zip-importer' ),
						$archive_path
					)
				);
			}

			$extension   = strtolower( pathinfo( $archive_path, PATHINFO_EXTENSION ) );
			$size_limit  = in_array( $extension, $allowed_docs, true ) ? self::MAX_MARKDOWN_SIZE : self::MAX_IMAGE_SIZE;
			$actual_size = filesize( $destination );

			if ( false === $actual_size || $actual_size > $size_limit ) {
				return new WP_Error(
					'ftmzi_entry_size',
					sprintf(
						/* translators: %s: archive path. */
						__( '文件过大，无法导入：%s', 'fangtao-markdown-zip-importer' ),
						$archive_path
					)
				);
			}

			$actual_total += $actual_size;

			if ( $actual_total > self::MAX_EXTRACTED_SIZE ) {
				return new WP_Error(
					'ftmzi_extracted_size',
					__( 'ZIP 解压后的文件总量不能超过 200 MB。', 'fangtao-markdown-zip-importer' )
				);
			}
		}

		natcasesort( $markdown_files );

		return array(
			'files'    => $expected_files,
			'markdown' => array_values( $markdown_files ),
		);
	}

	/**
	 * Import one Markdown document.
	 *
	 * @param string $markdown_path Archive-relative Markdown path.
	 * @param array  $files         Extracted file map.
	 * @param string $post_type     Destination post type.
	 * @param string $post_status   Destination post status.
	 * @return array|WP_Error
	 */
	private function import_document( $markdown_path, $files, $post_type, $post_status ) {
		if ( empty( $files[ $markdown_path ] ) || ! is_readable( $files[ $markdown_path ] ) ) {
			return new WP_Error(
				'ftmzi_read_markdown',
				__( '无法读取 Markdown 文件。', 'fangtao-markdown-zip-importer' )
			);
		}

		$source = file_get_contents( $files[ $markdown_path ] );

		if ( false === $source ) {
			return new WP_Error(
				'ftmzi_read_markdown',
				__( '无法读取 Markdown 文件。', 'fangtao-markdown-zip-importer' )
			);
		}

		$front_matter = $this->markdown->extract_front_matter( $source );
		$heading      = $this->markdown->extract_first_heading( $front_matter['content'] );
		$meta         = $front_matter['meta'];
		$title        = ! empty( $meta['title'] ) ? $meta['title'] : $heading['title'];
		$title        = $title ? $title : pathinfo( basename( $markdown_path ), PATHINFO_FILENAME );
		$excerpt      = '';

		if ( ! empty( $meta['excerpt'] ) ) {
			$excerpt = $meta['excerpt'];
		} elseif ( ! empty( $meta['description'] ) ) {
			$excerpt = $meta['description'];
		}

		$post_data = array(
			'post_type'    => $post_type,
			'post_status'  => $post_status,
			'post_title'   => sanitize_text_field( $title ),
			'post_excerpt' => sanitize_textarea_field( $excerpt ),
			'post_content' => '',
		);

		if ( ! empty( $meta['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $meta['slug'] );
		}

		$post_id = wp_insert_post( wp_slash( $post_data ), true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$warnings            = array();
		$first_attachment_id = 0;
		$markdown_content    = $this->replace_image_references(
			$heading['content'],
			$markdown_path,
			$files,
			$post_id,
			$warnings,
			$first_attachment_id
		);
		$html                = $this->markdown->convert( $markdown_content );

		if ( is_wp_error( $html ) ) {
			wp_delete_post( $post_id, true );
			return $html;
		}

		$updated = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post_id,
					'post_content' => $html,
				)
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			wp_delete_post( $post_id, true );
			return $updated;
		}

		$featured_reference = '';

		foreach ( array( 'featured_image', 'cover', 'image' ) as $featured_key ) {
			if ( ! empty( $meta[ $featured_key ] ) ) {
				$featured_reference = $meta[ $featured_key ];
				break;
			}
		}

		if ( $featured_reference ) {
			$featured = $this->import_image_reference(
				$featured_reference,
				$markdown_path,
				$files,
				$post_id,
				''
			);

			if ( is_wp_error( $featured ) ) {
				$warnings[] = $featured->get_error_message();
			} else {
				$first_attachment_id = $featured['id'];
			}
		}

		if ( $first_attachment_id && post_type_supports( $post_type, 'thumbnail' ) ) {
			set_post_thumbnail( $post_id, $first_attachment_id );
		}

		update_post_meta( $post_id, '_ftmzi_source_file', sanitize_text_field( $markdown_path ) );

		return array(
			'id'       => $post_id,
			'title'    => get_the_title( $post_id ),
			'file'     => $markdown_path,
			'warnings' => $warnings,
		);
	}

	/**
	 * Replace local Markdown image references with media-library URLs.
	 *
	 * @param string $markdown            Markdown source.
	 * @param string $markdown_path       Archive-relative Markdown path.
	 * @param array  $files               Extracted file map.
	 * @param int    $post_id             Parent post ID.
	 * @param array  $warnings            Import warnings.
	 * @param int    $first_attachment_id First imported attachment ID.
	 * @return string
	 */
	private function replace_image_references( $markdown, $markdown_path, $files, $post_id, &$warnings, &$first_attachment_id ) {
		$pattern = '/!\[([^\]]*)\]\(\s*(?:<([^>]+)>|([^\s\)]+))(?:\s+((["\']).*?\5))?\s*\)/u';

		return preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $markdown_path, $files, $post_id, &$warnings, &$first_attachment_id ) {
				$alt       = isset( $matches[1] ) ? sanitize_text_field( $matches[1] ) : '';
				$reference = ! empty( $matches[2] ) ? $matches[2] : $matches[3];

				if ( $this->is_external_reference( $reference ) ) {
					return $matches[0];
				}

				$imported = $this->import_image_reference(
					$reference,
					$markdown_path,
					$files,
					$post_id,
					$alt
				);

				if ( is_wp_error( $imported ) ) {
					$warnings[] = $imported->get_error_message();
					return $matches[0];
				}

				if ( ! $first_attachment_id ) {
					$first_attachment_id = $imported['id'];
				}

				$title = ! empty( $matches[4] ) ? ' ' . $matches[4] : '';

				return sprintf(
					'![%1$s](%2$s%3$s)',
					$matches[1],
					$imported['url'],
					$title
				);
			},
			$markdown
		);
	}

	/**
	 * Import one archive image reference.
	 *
	 * @param string $reference     Markdown image reference.
	 * @param string $markdown_path Archive-relative Markdown path.
	 * @param array  $files         Extracted file map.
	 * @param int    $post_id       Parent post ID.
	 * @param string $alt           Image alt text.
	 * @return array|WP_Error
	 */
	private function import_image_reference( $reference, $markdown_path, $files, $post_id, $alt ) {
		$reference = rawurldecode( preg_replace( '/[?#].*$/', '', $reference ) );
		$base_dir  = dirname( $markdown_path );
		$base_dir  = '.' === $base_dir ? '' : $base_dir;
		$resolved  = $this->normalize_archive_path( $reference, $base_dir );

		if ( false === $resolved || empty( $files[ $resolved ] ) ) {
			$root_path = $this->normalize_archive_path( ltrim( $reference, '/' ) );
			$resolved  = $root_path && ! empty( $files[ $root_path ] ) ? $root_path : $resolved;
		}

		if ( false === $resolved || empty( $files[ $resolved ] ) ) {
			return new WP_Error(
				'ftmzi_missing_image',
				sprintf(
					/* translators: %s: image reference. */
					__( '未在导入文件中找到图片：%s', 'fangtao-markdown-zip-importer' ),
					sanitize_text_field( $reference )
				)
			);
		}

		if ( isset( $this->media_cache[ $resolved ] ) ) {
			return $this->media_cache[ $resolved ];
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$temp_file = wp_tempnam( basename( $resolved ) );

		if ( ! $temp_file || ! copy( $files[ $resolved ], $temp_file ) ) {
			return new WP_Error(
				'ftmzi_prepare_image',
				sprintf(
					/* translators: %s: image path. */
					__( '无法准备图片：%s', 'fangtao-markdown-zip-importer' ),
					$resolved
				)
			);
		}

		$file_array = array(
			'name'     => sanitize_file_name( basename( $resolved ) ),
			'tmp_name' => $temp_file,
		);
		$restore_oss_filter = $this->suspend_unconfigured_oss_filter();

		try {
			$attachment_id = media_handle_sideload( $file_array, $post_id, $alt );
		} catch ( Throwable $exception ) {
			@unlink( $temp_file );

			return new WP_Error(
				'ftmzi_media_import',
				sprintf(
					/* translators: 1: image path, 2: media import error. */
					__( '图片导入失败（%1$s）：%2$s', 'fangtao-markdown-zip-importer' ),
					$resolved,
					$exception->getMessage()
				)
			);
		} finally {
			if ( $restore_oss_filter ) {
				add_filter( 'wp_generate_attachment_metadata', 'oss_upload_thumbs', 100 );
			}
		}

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $temp_file );
			return $attachment_id;
		}

		if ( $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		}

		$url = wp_get_attachment_url( $attachment_id );

		if ( ! $url ) {
			return new WP_Error(
				'ftmzi_attachment_url',
				__( '图片已入库，但无法获取媒体 URL。', 'fangtao-markdown-zip-importer' )
			);
		}

		$this->media_cache[ $resolved ] = array(
			'id'  => (int) $attachment_id,
			'url' => esc_url_raw( $url ),
		);

		return $this->media_cache[ $resolved ];
	}

	/**
	 * Prevent an enabled but incomplete OSS setup from breaking local imports.
	 *
	 * @return bool Whether the filter should be restored after sideloading.
	 */
	private function suspend_unconfigured_oss_filter() {
		if (
			! function_exists( 'oss_upload_thumbs' ) ||
			false === has_filter( 'wp_generate_attachment_metadata', 'oss_upload_thumbs' )
		) {
			return false;
		}

		$options        = get_option( 'oss_options', array() );
		$has_access_key = ! empty( $options['accessKeyId'] ) && ! empty( $options['accessKeySecret'] );
		$has_role       = ! empty( $options['role_name'] );
		$has_bucket     = ! empty( $options['bucket'] );

		if ( $has_bucket && ( $has_access_key || $has_role ) ) {
			return false;
		}

		remove_filter( 'wp_generate_attachment_metadata', 'oss_upload_thumbs', 100 );

		return true;
	}

	/**
	 * Normalize a path while preventing traversal outside the archive root.
	 *
	 * @param string $path     Path to normalize.
	 * @param string $base_dir Optional archive-relative base directory.
	 * @return string|false
	 */
	private function normalize_archive_path( $path, $base_dir = '' ) {
		$path = str_replace( '\\', '/', $path );

		if ( false !== strpos( $path, "\0" ) || preg_match( '#^(?:/|[A-Za-z]:/)#', $path ) ) {
			return false;
		}

		$combined = $base_dir ? trailingslashit( $base_dir ) . $path : $path;
		$segments = explode( '/', $combined );
		$clean    = array();

		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				if ( empty( $clean ) ) {
					return false;
				}
				array_pop( $clean );
				continue;
			}

			$clean[] = $segment;
		}

		return implode( '/', $clean );
	}

	/**
	 * Determine whether a reference should remain remote.
	 *
	 * @param string $reference Image reference.
	 * @return bool
	 */
	private function is_external_reference( $reference ) {
		return (bool) preg_match( '~^(?:[a-z][a-z0-9+.-]*:|//|\#)~i', trim( $reference ) );
	}

	/**
	 * Detect symbolic links stored in ZIP metadata.
	 *
	 * @param ZipArchive $zip   ZIP instance.
	 * @param int        $index Entry index.
	 * @return bool
	 */
	private function is_zip_symlink( $zip, $index ) {
		$operations = 0;
		$attributes = 0;

		if ( ! $zip->getExternalAttributesIndex( $index, $operations, $attributes ) ) {
			return false;
		}

		return 0120000 === ( ( $attributes >> 16 ) & 0170000 );
	}

	/**
	 * Remove an importer-owned temporary directory.
	 *
	 * @param string $directory Temporary directory.
	 * @return void
	 */
	private function remove_directory( $directory ) {
		if ( ! is_dir( $directory ) || 0 !== strpos( basename( $directory ), 'ftmzi-' ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() );
			} else {
				@unlink( $item->getPathname() );
			}
		}

		@rmdir( $directory );
	}
}
