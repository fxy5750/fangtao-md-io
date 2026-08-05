<?php
/**
 * Markdown conversion and Front Matter helpers.
 *
 * @package Fangtao_Markdown_Zip_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use League\CommonMark\GithubFlavoredMarkdownConverter;

final class FTMZI_Markdown {

	/**
	 * Convert Markdown to sanitized HTML.
	 *
	 * @param string $markdown Markdown source.
	 * @return string|WP_Error
	 */
	public function convert( $markdown ) {
		if ( ! class_exists( GithubFlavoredMarkdownConverter::class ) ) {
			return new WP_Error(
				'ftmzi_markdown_dependency',
				__( 'Markdown 解析组件未安装，请重新安装本插件。', 'fangtao-markdown-zip-importer' )
			);
		}

		try {
			$converter = new GithubFlavoredMarkdownConverter(
				array(
					'html_input'         => 'strip',
					'allow_unsafe_links' => false,
					'max_nesting_level'  => 50,
				)
			);

			return wp_kses_post( (string) $converter->convert( $markdown ) );
		} catch ( Throwable $exception ) {
			return new WP_Error(
				'ftmzi_markdown_conversion',
				sprintf(
					/* translators: %s: parser error. */
					__( 'Markdown 转换失败：%s', 'fangtao-markdown-zip-importer' ),
					$exception->getMessage()
				)
			);
		}
	}

	/**
	 * Extract a small, safe subset of YAML-style Front Matter.
	 *
	 * Supported values are single-line scalar values. This covers the fields
	 * used by the importer without accepting executable or complex YAML types.
	 *
	 * @param string $markdown Markdown source.
	 * @return array{meta: array<string, string>, content: string}
	 */
	public function extract_front_matter( $markdown ) {
		$result = array(
			'meta'    => array(),
			'content' => $markdown,
		);

		if ( ! preg_match( '/\A---\R(.*?)\R---\R?/s', $markdown, $matches ) ) {
			return $result;
		}

		$lines = preg_split( '/\R/', $matches[1] );

		foreach ( $lines as $line ) {
			if ( ! preg_match( '/^([A-Za-z][A-Za-z0-9_-]*):\s*(.*?)\s*$/', $line, $parts ) ) {
				continue;
			}

			$key   = strtolower( $parts[1] );
			$value = trim( $parts[2] );

			if ( strlen( $value ) >= 2 ) {
				$first = substr( $value, 0, 1 );
				$last  = substr( $value, -1 );

				if ( ( '"' === $first && '"' === $last ) || ( "'" === $first && "'" === $last ) ) {
					$value = substr( $value, 1, -1 );
				}
			}

			$result['meta'][ $key ] = sanitize_text_field( $value );
		}

		$result['content'] = substr( $markdown, strlen( $matches[0] ) );

		return $result;
	}

	/**
	 * Extract and remove the first level-one heading.
	 *
	 * @param string $markdown Markdown source.
	 * @return array{title: string, content: string}
	 */
	public function extract_first_heading( $markdown ) {
		$result = array(
			'title'   => '',
			'content' => $markdown,
		);

		if ( ! preg_match( '/^\s*#\s+(.+?)\s*#*\s*$/m', $markdown, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $result;
		}

		$result['title'] = sanitize_text_field( $matches[1][0] );
		$start           = $matches[0][1];
		$length          = strlen( $matches[0][0] );
		$result['content'] = ltrim( substr_replace( $markdown, '', $start, $length ) );

		return $result;
	}
}
