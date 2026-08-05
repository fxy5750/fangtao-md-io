<?php
/**
 * ZIP archive importer.
 *
 * @package Fangtao_MD_IO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FTMZI_Importer {

	const MAX_EXTRACTED_SIZE    = 209715200;
	const MAX_ARCHIVE_ENTRIES   = 500;
	const MAX_MARKDOWN_SIZE     = 2097152;
	const MAX_IMAGE_SIZE        = 20971520;
	const DOCUMENT_EXTENSIONS   = array( 'md', 'markdown', 'mdown', 'mkdn', 'mkd', 'mdwn', 'mdtxt', 'mdtext', '文本', 'txt' );

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
	 * @param int    $category_id Destination category ID for posts.
	 * @param bool   $import_remote_images Whether remote HTTP images should be imported.
	 * @param string $markdown_parser Markdown parser key.
	 * @return array|WP_Error
	 */
	public function import( $upload, $post_type, $post_status, $category_id = 0, $import_remote_images = false, $markdown_parser = FTMZI_Markdown::DEFAULT_PARSER ) {
		$this->media_cache = array();
		$extension         = $this->validate_upload( $upload );
		$markdown_parser   = FTMZI_Markdown::sanitize_parser( $markdown_parser );

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

			return $this->import_documents( $archive, $post_type, $post_status, $category_id, $import_remote_images, $markdown_parser );
		}

		$temp_dir = trailingslashit( get_temp_dir() ) . 'ftmzi-' . wp_generate_uuid4();

		if ( ! wp_mkdir_p( $temp_dir ) ) {
			return new WP_Error(
				'ftmzi_temp_directory',
				__( '无法创建临时解压目录，请检查 PHP 临时目录权限。', 'fangtao-md-io' )
			);
		}

		try {
			$archive = $this->extract_archive( $upload['tmp_name'], $temp_dir );

			if ( is_wp_error( $archive ) ) {
				return $archive;
			}

			return $this->import_documents( $archive, $post_type, $post_status, $category_id, $import_remote_images, $markdown_parser );
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
	 * @param int    $category_id Destination category ID for posts.
	 * @param bool   $import_remote_images Whether remote HTTP images should be imported.
	 * @param string $markdown_parser Markdown parser key.
	 * @return array
	 */
	private function import_documents( $archive, $post_type, $post_status, $category_id, $import_remote_images, $markdown_parser ) {
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
				$post_status,
				$category_id,
				$import_remote_images,
				$markdown_parser
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
					__( '%1$s：%2$s', 'fangtao-md-io' ),
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
				__( '请选择一个 Markdown 或 ZIP 文件。', 'fangtao-md-io' )
			);
		}

		if ( UPLOAD_ERR_OK !== (int) $upload['error'] ) {
			return new WP_Error(
				'ftmzi_upload_error',
				sprintf(
					/* translators: %d: PHP upload error code. */
					__( '文件上传失败，错误代码：%d。', 'fangtao-md-io' ),
					(int) $upload['error']
				)
			);
		}

		$extension = strtolower( pathinfo( sanitize_file_name( $upload['name'] ), PATHINFO_EXTENSION ) );

		if ( 'zip' !== $extension && ! in_array( $extension, self::DOCUMENT_EXTENSIONS, true ) ) {
			return new WP_Error(
				'ftmzi_upload_extension',
				__( '仅支持 Markdown 文本文件和 .zip 文件。', 'fangtao-md-io' )
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
				__( '无法打开 ZIP 文件，文件可能已损坏或不是有效压缩包。', 'fangtao-md-io' )
			);
		}

		if ( $zip->numFiles > self::MAX_ARCHIVE_ENTRIES ) {
			$zip->close();

			return new WP_Error(
				'ftmzi_archive_entries',
				__( 'ZIP 内文件数量不能超过 500 个。', 'fangtao-md-io' )
			);
		}

		$files          = array();
		$markdown_files = array();
		$total_size     = 0;
		$allowed_images = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' );
		$allowed_docs   = self::DOCUMENT_EXTENSIONS;

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
					__( 'ZIP 包含不安全的文件路径，导入已停止。', 'fangtao-md-io' )
				);
			}

			if ( $this->is_zip_symlink( $zip, $index ) ) {
				$zip->close();

				return new WP_Error(
					'ftmzi_archive_symlink',
					__( 'ZIP 包含符号链接，导入已停止。', 'fangtao-md-io' )
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
						__( '文件过大，无法导入：%s', 'fangtao-md-io' ),
						$archive_path
					)
				);
			}

			$total_size += $file_size;

			if ( $total_size > self::MAX_EXTRACTED_SIZE ) {
				$zip->close();

				return new WP_Error(
					'ftmzi_extracted_size',
					__( 'ZIP 解压后的文件总量不能超过 200 MB。', 'fangtao-md-io' )
				);
			}

			$destination = trailingslashit( $temp_dir ) . str_replace( '/', DIRECTORY_SEPARATOR, $archive_path );

			if ( ! wp_mkdir_p( dirname( $destination ) ) ) {
				$zip->close();

				return new WP_Error(
					'ftmzi_extract_directory',
					__( '无法创建 ZIP 子目录。', 'fangtao-md-io' )
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
						__( '无法解压文件：%s', 'fangtao-md-io' ),
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
						__( '文件过大，无法导入：%s', 'fangtao-md-io' ),
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
				__( 'ZIP 中没有找到支持的 Markdown 文本文件。', 'fangtao-md-io' )
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
				__( '无法打开 ZIP 文件，文件可能已损坏或不是有效压缩包。', 'fangtao-md-io' )
			);
		}

		if ( count( $entries ) > self::MAX_ARCHIVE_ENTRIES ) {
			return new WP_Error(
				'ftmzi_archive_entries',
				__( 'ZIP 内文件数量不能超过 500 个。', 'fangtao-md-io' )
			);
		}

		$expected_files = array();
		$markdown_files = array();
		$total_size     = 0;
		$allowed_images = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' );
		$allowed_docs   = self::DOCUMENT_EXTENSIONS;

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
					__( 'ZIP 包含不安全的文件路径，导入已停止。', 'fangtao-md-io' )
				);
			}

			if (
				isset( $entry['external'] ) &&
				0120000 === ( ( (int) $entry['external'] >> 16 ) & 0170000 )
			) {
				return new WP_Error(
					'ftmzi_archive_symlink',
					__( 'ZIP 包含符号链接，导入已停止。', 'fangtao-md-io' )
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
						__( '文件过大，无法导入：%s', 'fangtao-md-io' ),
						$archive_path
					)
				);
			}

			$total_size += $file_size;

			if ( $total_size > self::MAX_EXTRACTED_SIZE ) {
				return new WP_Error(
					'ftmzi_extracted_size',
					__( 'ZIP 解压后的文件总量不能超过 200 MB。', 'fangtao-md-io' )
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
				__( 'ZIP 中没有找到支持的 Markdown 文本文件。', 'fangtao-md-io' )
			);
		}

		global $wp_filesystem;

		if ( ! $wp_filesystem && ! WP_Filesystem() ) {
			return new WP_Error(
				'ftmzi_filesystem',
				__( 'WordPress 无法访问临时解压目录。', 'fangtao-md-io' )
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
					__( 'WordPress 无法解压 ZIP：%s', 'fangtao-md-io' ),
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
						__( '无法解压文件：%s', 'fangtao-md-io' ),
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
						__( '文件过大，无法导入：%s', 'fangtao-md-io' ),
						$archive_path
					)
				);
			}

			$actual_total += $actual_size;

			if ( $actual_total > self::MAX_EXTRACTED_SIZE ) {
				return new WP_Error(
					'ftmzi_extracted_size',
					__( 'ZIP 解压后的文件总量不能超过 200 MB。', 'fangtao-md-io' )
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
	 * @param int    $category_id   Destination category ID for posts.
	 * @param bool   $import_remote_images Whether remote HTTP images should be imported.
	 * @param string $markdown_parser Markdown parser key.
	 * @return array|WP_Error
	 */
	private function import_document( $markdown_path, $files, $post_type, $post_status, $category_id, $import_remote_images, $markdown_parser ) {
		if ( empty( $files[ $markdown_path ] ) || ! is_readable( $files[ $markdown_path ] ) ) {
			return new WP_Error(
				'ftmzi_read_markdown',
				__( '无法读取 Markdown 文件。', 'fangtao-md-io' )
			);
		}

		$source = file_get_contents( $files[ $markdown_path ] );

		if ( false === $source ) {
			return new WP_Error(
				'ftmzi_read_markdown',
				__( '无法读取 Markdown 文件。', 'fangtao-md-io' )
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

		$warnings         = array();
		$effective_status = $this->front_matter_status( $meta, $post_type, $post_status );
		$post_data        = array(
			'post_type'    => $post_type,
			'post_status'  => $effective_status,
			'post_title'   => sanitize_text_field( $title ),
			'post_excerpt' => sanitize_textarea_field( $excerpt ),
			'post_content' => '',
		);

		if ( 'post' === $post_type && $category_id ) {
			$post_data['post_category'] = array( $category_id );
		}

		if ( ! empty( $meta['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $meta['slug'] );
		} elseif ( ! empty( $meta['permalink'] ) ) {
			$permalink_path = wp_parse_url( $meta['permalink'], PHP_URL_PATH );
			$post_data['post_name'] = sanitize_title( rawurldecode( basename( untrailingslashit( (string) $permalink_path ) ) ) );
		}

		if ( ! empty( $meta['date'] ) ) {
			$date = date_create( $meta['date'], wp_timezone() );

			if ( false !== $date ) {
				$date->setTimezone( wp_timezone() );
				$post_data['post_date']     = $date->format( 'Y-m-d H:i:s' );
				$post_data['post_date_gmt'] = get_gmt_from_date( $post_data['post_date'] );
			} else {
				$warnings[] = __( 'Front Matter 中的 date 无法识别，已使用当前时间。', 'fangtao-md-io' );
			}
		}

		$post_id = wp_insert_post( wp_slash( $post_data ), true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$first_attachment_id = 0;
		$markdown_content    = $this->replace_image_references(
			$heading['content'],
			$markdown_path,
			$files,
			$post_id,
			$warnings,
			$first_attachment_id,
			$import_remote_images
		);
		$html                = $this->markdown->convert( $markdown_content, $markdown_parser );

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

		$this->apply_front_matter_terms( $post_id, $post_type, $meta, $warnings );

		$featured_reference     = '';
		$featured_attachment_id = ! empty( $meta['featured_image_id'] ) ? absint( $meta['featured_image_id'] ) : 0;

		foreach ( array( 'featured_image', 'cover', 'image' ) as $featured_key ) {
			if ( ! empty( $meta[ $featured_key ] ) ) {
				$featured_reference = $meta[ $featured_key ];
				break;
			}
		}

		if ( ! $featured_attachment_id && ctype_digit( (string) $featured_reference ) ) {
			$featured_attachment_id = absint( $featured_reference );
		}

		if ( $featured_attachment_id && wp_attachment_is_image( $featured_attachment_id ) ) {
			$first_attachment_id = $featured_attachment_id;
		} elseif ( $featured_reference ) {
			$existing_attachment_id = $this->is_remote_http_reference( $featured_reference )
				? attachment_url_to_postid( esc_url_raw( $featured_reference ) )
				: 0;
			$featured = $existing_attachment_id
				? array( 'id' => $existing_attachment_id, 'url' => wp_get_attachment_url( $existing_attachment_id ) )
				: ( $this->is_remote_http_reference( $featured_reference )
					? ( $import_remote_images ? $this->import_remote_image_reference( $featured_reference, $post_id, '' ) : new WP_Error( 'ftmzi_remote_image_disabled', __( '特色图片为远程地址，但远程图片导入尚未开启。', 'fangtao-md-io' ) ) )
					: $this->import_image_reference( $featured_reference, $markdown_path, $files, $post_id, '' ) );

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
	private function replace_image_references( $markdown, $markdown_path, $files, $post_id, &$warnings, &$first_attachment_id, $import_remote_images ) {
		$pattern = '/!\[([^\]]*)\]\(\s*(?:<([^>]+)>|([^\s\)]+))(?:\s+((["\']).*?\5))?\s*\)/u';

		return preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $markdown_path, $files, $post_id, &$warnings, &$first_attachment_id, $import_remote_images ) {
				$alt       = isset( $matches[1] ) ? sanitize_text_field( $matches[1] ) : '';
				$reference = ! empty( $matches[2] ) ? $matches[2] : $matches[3];

				if ( $this->is_external_reference( $reference ) && ! ( $import_remote_images && $this->is_remote_http_reference( $reference ) ) ) {
					return $matches[0];
				}

				$imported = $this->is_remote_http_reference( $reference )
					? $this->import_remote_image_reference( $reference, $post_id, $alt )
					: $this->import_image_reference( $reference, $markdown_path, $files, $post_id, $alt );

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
					__( '未在导入文件中找到图片：%s', 'fangtao-md-io' ),
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
					__( '无法准备图片：%s', 'fangtao-md-io' ),
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
					__( '图片导入失败（%1$s）：%2$s', 'fangtao-md-io' ),
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
				__( '图片已入库，但无法获取媒体 URL。', 'fangtao-md-io' )
			);
		}

		$this->media_cache[ $resolved ] = array(
			'id'  => (int) $attachment_id,
			'url' => esc_url_raw( $url ),
		);

		return $this->media_cache[ $resolved ];
	}

	/**
	 * Import a remote HTTP image through the WordPress media pipeline.
	 *
	 * @param string $reference Remote image URL.
	 * @param int    $post_id   Parent post ID.
	 * @param string $alt       Image alt text.
	 * @return array|WP_Error
	 */
	private function import_remote_image_reference( $reference, $post_id, $alt ) {
		$url       = esc_url_raw( $reference );
		$cache_key = 'remote:' . $url;

		if ( isset( $this->media_cache[ $cache_key ] ) ) {
			return $this->media_cache[ $cache_key ];
		}

		if ( ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'ftmzi_remote_image_url', __( '远程图片地址不安全或无效。', 'fangtao-md-io' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$temp_file = download_url( $url, 20 );

		if ( is_wp_error( $temp_file ) ) {
			return new WP_Error(
				'ftmzi_remote_image_download',
				sprintf(
					/* translators: 1: image URL, 2: download error. */
					__( '远程图片下载失败（%1$s）：%2$s', 'fangtao-md-io' ),
					$url,
					$temp_file->get_error_message()
				)
			);
		}

		if ( filesize( $temp_file ) > self::MAX_IMAGE_SIZE ) {
			@unlink( $temp_file );
			return new WP_Error( 'ftmzi_remote_image_size', __( '远程图片超过 20 MB 限制。', 'fangtao-md-io' ) );
		}

		$mime       = wp_get_image_mime( $temp_file );
		$extensions = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
			'image/avif' => 'avif',
		);

		if ( ! isset( $extensions[ $mime ] ) ) {
			@unlink( $temp_file );
			return new WP_Error( 'ftmzi_remote_image_type', __( '远程文件不是受支持的图片格式。', 'fangtao-md-io' ) );
		}

		$url_path = wp_parse_url( $url, PHP_URL_PATH );
		$basename = sanitize_file_name( basename( (string) $url_path ) );
		$basename = pathinfo( $basename, PATHINFO_FILENAME );
		$filename   = ( $basename ? $basename : 'remote-image' ) . '.' . $extensions[ $mime ];
		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $temp_file,
		);
		$restore_oss_filter = $this->suspend_unconfigured_oss_filter();

		try {
			$attachment_id = media_handle_sideload( $file_array, $post_id, $alt );
		} catch ( Throwable $exception ) {
			@unlink( $temp_file );
			return new WP_Error( 'ftmzi_remote_image_import', $exception->getMessage() );
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

		$attachment_url = wp_get_attachment_url( $attachment_id );

		if ( ! $attachment_url ) {
			return new WP_Error( 'ftmzi_remote_attachment_url', __( '远程图片已入库，但无法获取媒体 URL。', 'fangtao-md-io' ) );
		}

		$this->media_cache[ $cache_key ] = array(
			'id'  => (int) $attachment_id,
			'url' => esc_url_raw( $attachment_url ),
		);

		return $this->media_cache[ $cache_key ];
	}

	/**
	 * Resolve a Front Matter status without bypassing WordPress capabilities.
	 *
	 * @param array  $meta           Front Matter metadata.
	 * @param string $post_type      Destination post type.
	 * @param string $default_status Status selected in the import form.
	 * @return string
	 */
	private function front_matter_status( $meta, $post_type, $default_status ) {
		$status = ! empty( $meta['status'] ) ? sanitize_key( $meta['status'] ) : $default_status;

		if ( ! in_array( $status, array( 'draft', 'pending', 'private', 'publish', 'future' ), true ) ) {
			return $default_status;
		}

		$post_object = get_post_type_object( $post_type );

		if ( in_array( $status, array( 'private', 'publish', 'future' ), true ) && ( ! $post_object || ! current_user_can( $post_object->cap->publish_posts ) ) ) {
			return 'draft';
		}

		return $status;
	}

	/**
	 * Apply post categories and tags from Front Matter.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type.
	 * @param array  $meta      Front Matter metadata.
	 * @param array  $warnings  Import warnings.
	 * @return void
	 */
	private function apply_front_matter_terms( $post_id, $post_type, $meta, &$warnings ) {
		if ( 'post' !== $post_type ) {
			return;
		}

		$category_value = ! empty( $meta['categories'] ) ? $meta['categories'] : ( ! empty( $meta['category'] ) ? $meta['category'] : '' );

		if ( $category_value ) {
			$category_ids = array();

			foreach ( $this->markdown->parse_list( $category_value ) as $category ) {
				$term = ctype_digit( $category ) ? term_exists( absint( $category ), 'category' ) : term_exists( $category, 'category' );

				if ( ! $term && current_user_can( 'manage_categories' ) ) {
					$term = wp_insert_term( $category, 'category' );
				}

				if ( is_wp_error( $term ) ) {
					$warnings[] = $term->get_error_message();
				} elseif ( $term ) {
					$category_ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
				}
			}

			if ( $category_ids ) {
				$result = wp_set_post_categories( $post_id, array_values( array_unique( $category_ids ) ), false );
				if ( is_wp_error( $result ) ) {
					$warnings[] = $result->get_error_message();
				}
			}
		}

		$tag_value = ! empty( $meta['tags'] ) ? $meta['tags'] : ( ! empty( $meta['tag'] ) ? $meta['tag'] : '' );

		if ( $tag_value ) {
			$result = wp_set_post_tags( $post_id, $this->markdown->parse_list( $tag_value ), false );
			if ( is_wp_error( $result ) ) {
				$warnings[] = $result->get_error_message();
			}
		}
	}

	/**
	 * Determine whether a reference is a remote HTTP(S) URL.
	 *
	 * @param string $reference Image reference.
	 * @return bool
	 */
	private function is_remote_http_reference( $reference ) {
		return (bool) preg_match( '~^https?://~i', trim( $reference ) );
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
