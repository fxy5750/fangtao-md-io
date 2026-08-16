<?php
/**
 * Markdown conversion and Front Matter helpers.
 *
 * @package Fangtao_MD_IO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FTMZI_Markdown {

	const DEFAULT_PARSER = 'parsedown';

	/**
	 * Get supported Markdown parsers and their syntax flavors.
	 *
	 * @return array<string, array{label: string, flavor: string, class: string}>
	 */
	public static function get_parsers() {
		return array(
			'parsedown'          => array(
				'label'  => 'Parsedown',
				'flavor' => __( 'GitHub style', 'fangtao-md-io' ),
				'class'  => 'Parsedown',
			),
			'parsedown_extra'    => array(
				'label'  => 'Parsedown Extra',
				'flavor' => __( 'Extra style', 'fangtao-md-io' ),
				'class'  => 'ParsedownExtra',
			),
			'cebe_markdown'      => array(
				'label'  => 'Cebe Markdown',
				'flavor' => __( 'Traditional style', 'fangtao-md-io' ),
				'class'  => 'cebe\\markdown\\Markdown',
			),
			'cebe_github'        => array(
				'label'  => 'Cebe Markdown GitHub',
				'flavor' => __( 'GitHub style', 'fangtao-md-io' ),
				'class'  => 'cebe\\markdown\\GithubMarkdown',
			),
			'cebe_extra'         => array(
				'label'  => 'Cebe Markdown Extra',
				'flavor' => __( 'Extra style', 'fangtao-md-io' ),
				'class'  => 'cebe\\markdown\\MarkdownExtra',
			),
		);
	}

	/**
	 * Validate a parser key.
	 *
	 * @param string $parser Parser key.
	 * @return string
	 */
	public static function sanitize_parser( $parser ) {
		$parser = sanitize_key( $parser );

		return isset( self::get_parsers()[ $parser ] ) ? $parser : self::DEFAULT_PARSER;
	}

	/**
	 * Get unavailable parser labels.
	 *
	 * @return array<int, string>
	 */
	public static function get_missing_parsers() {
		$missing = array();

		foreach ( self::get_parsers() as $parser ) {
			if ( ! class_exists( $parser['class'] ) ) {
				$missing[] = $parser['label'];
			}
		}

		return $missing;
	}

	/**
	 * Convert Markdown to sanitized HTML.
	 *
	 * @param string $markdown Markdown source.
	 * @param string $parser   Parser key.
	 * @return string|WP_Error
	 */
	public function convert( $markdown, $parser = self::DEFAULT_PARSER ) {
		$parser  = self::sanitize_parser( $parser );
		$parsers = self::get_parsers();

		if ( ! class_exists( $parsers[ $parser ]['class'] ) ) {
			return new WP_Error(
				'ftmzi_markdown_dependency',
				sprintf(
					/* translators: %s: parser name. */
					__( 'Markdown parser component is missing: %s. Please reinstall this plugin.', 'fangtao-md-io' ),
					$parsers[ $parser ]['label']
				)
			);
		}

		try {
			switch ( $parser ) {
				case 'parsedown_extra':
					$converter = new ParsedownExtra();
					$converter->setSafeMode( true );
					$html = $converter->text( $markdown );
					break;

				case 'cebe_markdown':
					$converter = new cebe\markdown\Markdown();
					$html      = $converter->parse( $markdown );
					break;

				case 'cebe_github':
					$converter = new cebe\markdown\GithubMarkdown();
					$html      = $converter->parse( $markdown );
					break;

				case 'cebe_extra':
					$converter = new cebe\markdown\MarkdownExtra();
					$html      = $converter->parse( $markdown );
					break;

				case 'parsedown':
				default:
					$converter = new Parsedown();
					$converter->setSafeMode( true );
					$html = $converter->text( $markdown );
					break;
			}

			return wp_kses_post( (string) $html );
		} catch ( Throwable $exception ) {
			return new WP_Error(
				'ftmzi_markdown_conversion',
				sprintf(
					/* translators: %s: parser error. */
					__( 'Markdown conversion failed: %s', 'fangtao-md-io' ),
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

	/**
	 * Parse a comma-separated Front Matter list.
	 *
	 * @param string $value Front Matter scalar value.
	 * @return array<int, string>
	 */
	public function parse_list( $value ) {
		$value = trim( str_replace( '，', ',', (string) $value ) );

		if ( '[' === substr( $value, 0, 1 ) && ']' === substr( $value, -1 ) ) {
			$value = substr( $value, 1, -1 );
		}

		$items = str_getcsv( $value );
		$items = array_map( 'sanitize_text_field', $items );

		return array_values( array_unique( array_filter( array_map( 'trim', $items ) ) ) );
	}
}
