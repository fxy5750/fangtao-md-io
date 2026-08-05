<?php
/**
 * WordPress content to Markdown ZIP exporter.
 *
 * @package Fangtao_Markdown_Zip_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FTMZI_Exporter {

	/**
	 * Active ZIP archive.
	 *
	 * @var ZipArchive|PclZip|null
	 */
	private $zip;

	/**
	 * Active archive engine.
	 *
	 * @var string
	 */
	private $archive_engine = '';

	/**
	 * Entries queued for the WordPress PclZip fallback.
	 *
	 * @var array<int, array>
	 */
	private $pclzip_entries = array();

	/**
	 * Current article directory inside the archive.
	 *
	 * @var string
	 */
	private $archive_prefix = '';

	/**
	 * Image URL to relative path map for the current article.
	 *
	 * @var array<string, string>
	 */
	private $image_map = array();

	/**
	 * Used image names for the current article.
	 *
	 * @var array<string, bool>
	 */
	private $image_names = array();

	/**
	 * Create a ZIP containing one or more Markdown articles.
	 *
	 * @param array<int> $post_ids Post IDs.
	 * @return array{path: string, filename: string}|WP_Error
	 */
	public function create_archive( $post_ids ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return new WP_Error(
				'ftmzi_export_dom_extension',
				__( '服务器未启用 PHP DOM 扩展，无法转换文章内容。', 'fangtao-markdown-zip-importer' )
			);
		}

		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) );
		$posts    = array();

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );

			if ( $post && 'attachment' !== $post->post_type && 'trash' !== $post->post_status ) {
				$posts[] = $post;
			}
		}

		if ( empty( $posts ) ) {
			return new WP_Error(
				'ftmzi_export_empty',
				__( '没有可导出的内容。', 'fangtao-markdown-zip-importer' )
			);
		}

		$temp_file = wp_tempnam( 'fangtao-markdown-export.zip' );

		if ( ! $temp_file ) {
			return new WP_Error(
				'ftmzi_export_temp_file',
				__( '无法创建临时导出文件，请检查 PHP 临时目录权限。', 'fangtao-markdown-zip-importer' )
			);
		}

		$opened = $this->open_archive( $temp_file );

		if ( is_wp_error( $opened ) ) {
			@unlink( $temp_file );
			return $opened;
		}

		$is_batch = count( $posts ) > 1;

		foreach ( $posts as $post ) {
			$prefix = $is_batch ? $this->article_directory( $post ) . '/' : '';
			$result = $this->add_post( $post, $prefix );

			if ( is_wp_error( $result ) ) {
				$this->discard_archive();
				@unlink( $temp_file );
				return $result;
			}
		}

		if ( ! $this->close_archive() ) {
			@unlink( $temp_file );

			return new WP_Error(
				'ftmzi_export_close',
				__( 'ZIP 压缩包写入失败。', 'fangtao-markdown-zip-importer' )
			);
		}

		$filename = $is_batch
			? 'markdown-export-' . gmdate( 'Ymd-His' ) . '.zip'
			: $this->article_directory( $posts[0] ) . '.zip';

		return array(
			'path'     => $temp_file,
			'filename' => sanitize_file_name( $filename ),
		);
	}

	/**
	 * Open the preferred ZIP writer.
	 *
	 * @param string $temp_file Temporary archive path.
	 * @return true|WP_Error
	 */
	private function open_archive( $temp_file ) {
		$this->archive_engine = '';
		$this->pclzip_entries = array();

		if ( class_exists( 'ZipArchive' ) ) {
			$this->zip = new ZipArchive();
			$opened    = $this->zip->open( $temp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );

			if ( true !== $opened ) {
				return new WP_Error(
					'ftmzi_export_open',
					__( '无法创建 ZIP 压缩包。', 'fangtao-markdown-zip-importer' )
				);
			}

			$this->archive_engine = 'ziparchive';
			return true;
		}

		if ( ! extension_loaded( 'zlib' ) ) {
			return new WP_Error(
				'ftmzi_export_zlib_extension',
				__( '服务器未启用 PHP ZIP 或 zlib 扩展，无法创建压缩包。', 'fangtao-markdown-zip-importer' )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';

		@unlink( $temp_file );
		$this->zip            = new PclZip( $temp_file );
		$this->archive_engine = 'pclzip';

		return true;
	}

	/**
	 * Add an empty directory to the active archive.
	 *
	 * @param string $archive_path Archive-relative directory path.
	 * @return bool
	 */
	private function add_archive_directory( $archive_path ) {
		$archive_path = trailingslashit( $archive_path );

		if ( 'ziparchive' === $this->archive_engine ) {
			return $this->zip->addEmptyDir( $archive_path );
		}

		$this->pclzip_entries[] = array(
			PCLZIP_ATT_FILE_NAME    => $archive_path,
			PCLZIP_ATT_FILE_CONTENT => '',
		);

		return true;
	}

	/**
	 * Add string content to the active archive.
	 *
	 * @param string $archive_path Archive-relative file path.
	 * @param string $content      File content.
	 * @return bool
	 */
	private function add_archive_string( $archive_path, $content ) {
		if ( 'ziparchive' === $this->archive_engine ) {
			return $this->zip->addFromString( $archive_path, $content );
		}

		$this->pclzip_entries[] = array(
			PCLZIP_ATT_FILE_NAME    => $archive_path,
			PCLZIP_ATT_FILE_CONTENT => $content,
		);

		return true;
	}

	/**
	 * Add a local file to the active archive.
	 *
	 * @param string $source_path  Local source path.
	 * @param string $archive_path Archive-relative file path.
	 * @return bool
	 */
	private function add_archive_file( $source_path, $archive_path ) {
		if ( 'ziparchive' === $this->archive_engine ) {
			return $this->zip->addFile( $source_path, $archive_path );
		}

		$this->pclzip_entries[] = array(
			PCLZIP_ATT_FILE_NAME          => $source_path,
			PCLZIP_ATT_FILE_NEW_FULL_NAME => $archive_path,
		);

		return true;
	}

	/**
	 * Finish writing the active archive.
	 *
	 * @return bool
	 */
	private function close_archive() {
		if ( 'ziparchive' === $this->archive_engine ) {
			$result = $this->zip->close();
		} else {
			$result = ! empty( $this->pclzip_entries ) && 0 !== $this->zip->create( $this->pclzip_entries );
		}

		$this->zip = null;
		$this->archive_engine = '';
		$this->pclzip_entries = array();

		return (bool) $result;
	}

	/**
	 * Discard the active archive after a content conversion error.
	 *
	 * @return void
	 */
	private function discard_archive() {
		if ( 'ziparchive' === $this->archive_engine && $this->zip ) {
			$this->zip->close();
		}

		$this->zip            = null;
		$this->archive_engine = '';
		$this->pclzip_entries = array();
	}

	/**
	 * Add one post and its images to the ZIP.
	 *
	 * @param WP_Post $post   Post object.
	 * @param string  $prefix Article directory inside the archive.
	 * @return true|WP_Error
	 */
	private function add_post( $post, $prefix ) {
		$this->archive_prefix = $prefix;
		$this->image_map      = array();
		$this->image_names    = array();

		$this->add_archive_directory( $prefix . 'images' );

		$html     = has_blocks( $post->post_content ) ? do_blocks( $post->post_content ) : wpautop( $post->post_content );
		$html     = do_shortcode( $html );
		$markdown = $this->html_to_markdown( $html );

		if ( is_wp_error( $markdown ) ) {
			return $markdown;
		}

		$featured_image = '';
		$thumbnail_id   = get_post_thumbnail_id( $post );

		if ( $thumbnail_id ) {
			$thumbnail_url = wp_get_attachment_url( $thumbnail_id );

			if ( $thumbnail_url ) {
				$featured_image = $this->archive_image( $thumbnail_url );
			}
		}

		$front_matter = array(
			'---',
			'title: ' . $this->front_matter_value( get_the_title( $post ) ),
			'slug: ' . $this->front_matter_value( $post->post_name ),
			'excerpt: ' . $this->front_matter_value( $post->post_excerpt ),
			'date: ' . $this->front_matter_value( mysql2date( DATE_ATOM, $post->post_date_gmt ?: $post->post_date, false ) ),
			'post_type: ' . $this->front_matter_value( $post->post_type ),
			'status: ' . $this->front_matter_value( $post->post_status ),
		);

		if ( $featured_image && $featured_image !== $thumbnail_url ) {
			$front_matter[] = 'featured_image: ' . $featured_image;
		}

		$front_matter[] = '---';
		$document       = implode( "\n", $front_matter ) . "\n\n" . trim( $markdown ) . "\n";

		if ( ! $this->add_archive_string( $prefix . 'article.md', $document ) ) {
			return new WP_Error(
				'ftmzi_export_markdown',
				sprintf(
					/* translators: %s: post title. */
					__( '无法写入文章：%s', 'fangtao-markdown-zip-importer' ),
					get_the_title( $post )
				)
			);
		}

		return true;
	}

	/**
	 * Convert HTML content to Markdown using DOM traversal.
	 *
	 * @param string $html HTML content.
	 * @return string|WP_Error
	 */
	private function html_to_markdown( $html ) {
		$document = new DOMDocument( '1.0', 'UTF-8' );
		$previous = libxml_use_internal_errors( true );
		$loaded   = $document->loadHTML(
			'<?xml encoding="utf-8" ?><div id="ftmzi-export-root">' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return new WP_Error(
				'ftmzi_export_html',
				__( '文章 HTML 内容无法解析。', 'fangtao-markdown-zip-importer' )
			);
		}

		$root = $document->getElementById( 'ftmzi-export-root' );

		if ( ! $root ) {
			return new WP_Error(
				'ftmzi_export_html_root',
				__( '文章 HTML 内容缺少根节点。', 'fangtao-markdown-zip-importer' )
			);
		}

		$markdown = $this->convert_children( $root );
		$markdown = preg_replace( "/[ \t]+\n/u", "\n", $markdown );
		$markdown = preg_replace( "/\n{3,}/u", "\n\n", $markdown );

		return trim( $markdown );
	}

	/**
	 * Convert all child nodes.
	 *
	 * @param DOMNode $node Parent node.
	 * @return string
	 */
	private function convert_children( $node ) {
		$output = '';

		foreach ( $node->childNodes as $child ) {
			$output .= $this->convert_node( $child );
		}

		return $output;
	}

	/**
	 * Convert one DOM node.
	 *
	 * @param DOMNode $node DOM node.
	 * @return string
	 */
	private function convert_node( $node ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			$text = preg_replace( '/\s+/u', ' ', $node->nodeValue );
			return $this->escape_text( $text );
		}

		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return '';
		}

		$tag      = strtolower( $node->nodeName );
		$children = $this->convert_children( $node );

		if ( preg_match( '/^h([1-6])$/', $tag, $heading ) ) {
			return "\n\n" . str_repeat( '#', (int) $heading[1] ) . ' ' . trim( $children ) . "\n\n";
		}

		switch ( $tag ) {
			case 'p':
				return "\n\n" . trim( $children ) . "\n\n";
			case 'strong':
			case 'b':
				return '**' . trim( $children ) . '**';
			case 'em':
			case 'i':
				return '*' . trim( $children ) . '*';
			case 'del':
			case 's':
			case 'strike':
				return '~~' . trim( $children ) . '~~';
			case 'br':
				return "  \n";
			case 'hr':
				return "\n\n---\n\n";
			case 'a':
				$href = $node->getAttribute( 'href' );
				return $href ? '[' . trim( $children ) . '](' . $this->escape_url( $href ) . ')' : $children;
			case 'img':
				$src = $node->getAttribute( 'src' );
				$alt = $this->escape_alt( $node->getAttribute( 'alt' ) );
				return $src ? '![' . $alt . '](' . $this->archive_image( $src ) . ')' : '';
			case 'ul':
			case 'ol':
				return "\n\n" . $this->convert_list( $node ) . "\n";
			case 'blockquote':
				$quote = trim( $children );
				return "\n\n> " . str_replace( "\n", "\n> ", $quote ) . "\n\n";
			case 'pre':
				return $this->convert_preformatted( $node );
			case 'code':
				return '`' . str_replace( '`', '\`', trim( $node->textContent ) ) . '`';
			case 'table':
				return $this->convert_table( $node );
			case 'figcaption':
				return "\n\n*" . trim( $children ) . "*\n\n";
			case 'video':
			case 'audio':
			case 'iframe':
				$src = $node->getAttribute( 'src' );
				return $src ? '[' . $this->media_label( $tag ) . '](' . $this->escape_url( $src ) . ')' : $children;
			case 'script':
			case 'style':
			case 'noscript':
				return '';
			case 'div':
			case 'section':
			case 'article':
			case 'header':
			case 'footer':
			case 'figure':
				return "\n" . $children . "\n";
			default:
				return $children;
		}
	}

	/**
	 * Convert an ordered or unordered list.
	 *
	 * @param DOMElement $list  List element.
	 * @param int        $level Nesting level.
	 * @return string
	 */
	private function convert_list( $list, $level = 0 ) {
		$output  = '';
		$ordered = 'ol' === strtolower( $list->nodeName );
		$index   = 1;

		foreach ( $list->childNodes as $item ) {
			if ( XML_ELEMENT_NODE !== $item->nodeType || 'li' !== strtolower( $item->nodeName ) ) {
				continue;
			}

			$text   = '';
			$nested = '';

			foreach ( $item->childNodes as $child ) {
				$child_tag = XML_ELEMENT_NODE === $child->nodeType ? strtolower( $child->nodeName ) : '';

				if ( in_array( $child_tag, array( 'ul', 'ol' ), true ) ) {
					$nested .= $this->convert_list( $child, $level + 1 );
				} else {
					$text .= $this->convert_node( $child );
				}
			}

			$text   = trim( preg_replace( '/\s+/u', ' ', $text ) );
			$prefix = $ordered ? $index . '. ' : '- ';
			$output .= str_repeat( '  ', $level ) . $prefix . $text . "\n" . $nested;
			$index++;
		}

		return $output;
	}

	/**
	 * Convert a preformatted code block.
	 *
	 * @param DOMElement $node Pre element.
	 * @return string
	 */
	private function convert_preformatted( $node ) {
		$code     = $node->textContent;
		$language = '';
		$child    = $node->firstChild;

		if ( $child && XML_ELEMENT_NODE === $child->nodeType && $child->hasAttribute( 'class' ) ) {
			if ( preg_match( '/(?:language|lang)-([A-Za-z0-9_-]+)/', $child->getAttribute( 'class' ), $matches ) ) {
				$language = $matches[1];
			}
		}

		return "\n\n```" . $language . "\n" . rtrim( $code ) . "\n```\n\n";
	}

	/**
	 * Convert a simple HTML table to a GFM table.
	 *
	 * @param DOMElement $table Table element.
	 * @return string
	 */
	private function convert_table( $table ) {
		$rows = array();

		foreach ( $table->getElementsByTagName( 'tr' ) as $row ) {
			$cells = array();

			foreach ( $row->childNodes as $cell ) {
				if (
					XML_ELEMENT_NODE === $cell->nodeType &&
					in_array( strtolower( $cell->nodeName ), array( 'th', 'td' ), true )
				) {
					$cells[] = $this->escape_table_cell( trim( $this->convert_children( $cell ) ) );
				}
			}

			if ( $cells ) {
				$rows[] = $cells;
			}
		}

		if ( empty( $rows ) ) {
			return '';
		}

		$columns = count( $rows[0] );
		$output  = "\n\n| " . implode( ' | ', $rows[0] ) . " |\n";
		$output .= '| ' . implode( ' | ', array_fill( 0, $columns, '---' ) ) . " |\n";

		foreach ( array_slice( $rows, 1 ) as $row ) {
			$row     = array_pad( array_slice( $row, 0, $columns ), $columns, '' );
			$output .= '| ' . implode( ' | ', $row ) . " |\n";
		}

		return $output . "\n";
	}

	/**
	 * Add a local image to the archive and return its Markdown reference.
	 *
	 * @param string $source Image URL.
	 * @return string
	 */
	private function archive_image( $source ) {
		$source = html_entity_decode( trim( $source ), ENT_QUOTES, 'UTF-8' );

		if ( isset( $this->image_map[ $source ] ) ) {
			return $this->image_map[ $source ];
		}

		$file = $this->local_image_path( $source );

		if ( ! $file || ! is_readable( $file ) ) {
			return $this->escape_url( $source );
		}

		$name      = sanitize_file_name( basename( $file ) );
		$name      = $name ? $name : 'image.jpg';
		$extension = pathinfo( $name, PATHINFO_EXTENSION );
		$basename  = pathinfo( $name, PATHINFO_FILENAME );
		$candidate = $name;
		$suffix    = 2;

		while ( isset( $this->image_names[ strtolower( $candidate ) ] ) ) {
			$candidate = $basename . '-' . $suffix . ( $extension ? '.' . $extension : '' );
			$suffix++;
		}

		$this->image_names[ strtolower( $candidate ) ] = true;
		$relative_path = 'images/' . $candidate;

		if ( ! $this->add_archive_file( $file, $this->archive_prefix . $relative_path ) ) {
			return $this->escape_url( $source );
		}

		$this->image_map[ $source ] = $relative_path;

		return $relative_path;
	}

	/**
	 * Resolve an uploads URL to a local file.
	 *
	 * @param string $source Image URL.
	 * @return string
	 */
	private function local_image_path( $source ) {
		$url = $source;

		if ( 0 === strpos( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		} elseif ( 0 === strpos( $url, '/' ) ) {
			$url = home_url( $url );
		}

		$url = preg_replace( '/[?#].*$/', '', $url );

		$attachment_id = attachment_url_to_postid( $url );

		if ( $attachment_id ) {
			$file = get_attached_file( $attachment_id );

			if ( $file && is_file( $file ) ) {
				return $file;
			}
		}

		$uploads = wp_upload_dir();
		$baseurl = isset( $uploads['baseurl'] ) ? $uploads['baseurl'] : '';
		$basedir = isset( $uploads['basedir'] ) ? $uploads['basedir'] : '';

		if ( $baseurl && $basedir && 0 === strpos( $url, $baseurl . '/' ) ) {
			$relative = rawurldecode( substr( $url, strlen( $baseurl ) + 1 ) );
			$file     = trailingslashit( $basedir ) . str_replace( '/', DIRECTORY_SEPARATOR, $relative );

			if ( is_file( $file ) ) {
				return $file;
			}
		}

		return '';
	}

	/**
	 * Build a stable article directory name.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private function article_directory( $post ) {
		$slug = sanitize_title( $post->post_name );

		if ( ! $slug || false !== strpos( $slug, '%' ) || ! preg_match( '/[a-z0-9]/i', $slug ) ) {
			$slug = 'article';
		}

		return sanitize_file_name( $slug . '-' . $post->ID );
	}

	/**
	 * Normalize a Front Matter scalar value.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function front_matter_value( $value ) {
		return sanitize_text_field( str_replace( array( "\r", "\n" ), ' ', (string) $value ) );
	}

	/**
	 * Escape Markdown text punctuation.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function escape_text( $text ) {
		return preg_replace( '/([\\\\`*_\[\]])/u', '\\\\$1', $text );
	}

	/**
	 * Escape image alt text.
	 *
	 * @param string $text Alt text.
	 * @return string
	 */
	private function escape_alt( $text ) {
		return str_replace( array( '\\', '[', ']' ), array( '\\\\', '\[', '\]' ), $text );
	}

	/**
	 * Escape a URL for Markdown.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function escape_url( $url ) {
		return str_replace( array( ' ', '(', ')' ), array( '%20', '%28', '%29' ), $url );
	}

	/**
	 * Escape a GFM table cell.
	 *
	 * @param string $text Cell text.
	 * @return string
	 */
	private function escape_table_cell( $text ) {
		$text = preg_replace( '/\s+/u', ' ', $text );
		return str_replace( '|', '\|', $text );
	}

	/**
	 * Return a readable label for embedded media.
	 *
	 * @param string $tag Element name.
	 * @return string
	 */
	private function media_label( $tag ) {
		if ( 'audio' === $tag ) {
			return __( '音频', 'fangtao-markdown-zip-importer' );
		}

		if ( 'iframe' === $tag ) {
			return __( '嵌入内容', 'fangtao-markdown-zip-importer' );
		}

		return __( '视频', 'fangtao-markdown-zip-importer' );
	}
}
