<?php
/**
 * Cross-post ID references: collect on export, resolve on import.
 *
 * Use case: ACF PostObject / Relationship fields, Gutenberg blocks that
 * point at other posts by ID (e.g. acf/main-services-grid →
 * manual_services: ["960","957",…]). Source and target sites typically
 * have different auto-increment IDs, so we capture title + slug +
 * language hints alongside the ID and let the importer find the real
 * target ID via lookup.
 *
 * @package Simple_Export_Import
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEI_References {

	/* ----------------------------------------------------------------------
	 * EXPORT SIDE
	 * -------------------------------------------------------------------- */

	/**
	 * Scan post_content (block attrs) and meta_fields for integer / numeric-
	 * string values; resolve those to actual posts on this site and return
	 * their metadata so the importer can remap.
	 *
	 * Attachments are skipped — they ride in embedded_attachments instead.
	 *
	 * @return array<int,array{id:int,post_type:string,post_title:string,post_name:string,language_code:string}>
	 */
	public static function collect( array $post_data ) {
		$candidates = array();

		if ( ! empty( $post_data['post_content'] ) ) {
			self::scan_content( (string) $post_data['post_content'], $candidates );
		}

		if ( ! empty( $post_data['meta_fields'] ) && is_array( $post_data['meta_fields'] ) ) {
			self::scan_value( $post_data['meta_fields'], $candidates );
		}

		if ( empty( $candidates ) ) {
			return array();
		}

		$candidates = array_values( array_unique( array_map( 'intval', $candidates ) ) );
		$candidates = array_filter( $candidates, function ( $id ) {
			return $id > 0;
		} );

		if ( empty( $candidates ) ) {
			return array();
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $candidates ), '%d' ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_type, post_title, post_name FROM {$wpdb->posts}
			 WHERE ID IN ($placeholders)
			   AND post_type != 'attachment'
			   AND post_status IN ('publish','draft','pending','private','future')",
			...$candidates
		) );

		$multilingual = sei_is_multilingual_active();
		$references   = array();

		foreach ( $rows as $row ) {
			$lang = '';
			if ( $multilingual ) {
				$lang = (string) ( apply_filters( 'wpml_element_language_code', null, array(
					'element_id'   => (int) $row->ID,
					'element_type' => 'post_' . $row->post_type,
				) ) ?: '' );
			}
			$references[] = array(
				'id'            => (int) $row->ID,
				'post_type'     => (string) $row->post_type,
				'post_title'    => (string) $row->post_title,
				'post_name'     => (string) $row->post_name,
				'language_code' => $lang,
			);
		}

		return $references;
	}

	/**
	 * Read block attrs through parse_blocks() — READ ONLY, never serialized
	 * back, so the v1.3 encoding bug cannot recur here.
	 */
	private static function scan_content( $content, array &$candidates ) {
		if ( ! function_exists( 'parse_blocks' ) || ! has_blocks( $content ) ) {
			return;
		}
		self::walk_blocks( parse_blocks( $content ), $candidates );
	}

	private static function walk_blocks( $blocks, array &$candidates ) {
		foreach ( $blocks as $block ) {
			if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				self::scan_value( $block['attrs'], $candidates );
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::walk_blocks( $block['innerBlocks'], $candidates );
			}
		}
	}

	private static function scan_value( $value, array &$candidates ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $v ) {
				self::scan_value( $v, $candidates );
			}
			return;
		}
		if ( is_int( $value ) && $value > 0 ) {
			$candidates[] = $value;
			return;
		}
		if ( is_string( $value ) && ctype_digit( $value ) && $value !== '0' ) {
			$candidates[] = (int) $value;
		}
	}

	/* ----------------------------------------------------------------------
	 * IMPORT SIDE
	 * -------------------------------------------------------------------- */

	/**
	 * Resolve each reference to a real ID on the target site.
	 * Strategy per reference:
	 *   1. Direct ID match (+ post_type, + language) — no remap needed
	 *   2. Lookup by post_name + post_type + language
	 *   3. Lookup by post_title + post_type + language
	 *   4. Not found → no entry in id_map (original ID survives, may 404)
	 *
	 * Language is only applied as a filter when a multilingual backend is
	 * active AND the reference carried a language_code.
	 *
	 * @return array<int,int> Sparse old_id => new_id (only entries where remap is needed)
	 */
	public static function resolve( array $references ) {
		$id_map = array();

		foreach ( $references as $ref ) {
			$old_id     = (int) ( $ref['id'] ?? 0 );
			$post_type  = (string) ( $ref['post_type'] ?? '' );
			$post_title = (string) ( $ref['post_title'] ?? '' );
			$post_name  = (string) ( $ref['post_name'] ?? '' );
			$language   = (string) ( $ref['language_code'] ?? '' );

			if ( $old_id <= 0 || $post_type === '' ) {
				continue;
			}

			// 1. Direct ID match — only counts if the post on the target
			// at the same ID is the SAME content. If the IDs collided but
			// point at unrelated posts (different titles), fall through to
			// the lookup step.
			if ( self::id_matches( $old_id, $post_type, $language, $post_title ) ) {
				continue;
			}

			// 2. By slug.
			if ( $post_name !== '' ) {
				$found = self::find_by_field( 'post_name', $post_name, $post_type, $language );
				if ( $found ) {
					$id_map[ $old_id ] = $found;
					continue;
				}
			}

			// 3. By title.
			if ( $post_title !== '' ) {
				$found = self::find_by_field( 'post_title', $post_title, $post_type, $language );
				if ( $found ) {
					$id_map[ $old_id ] = $found;
				}
			}
		}

		return $id_map;
	}

	private static function id_matches( $id, $post_type, $language, $expected_title = '' ) {
		$post = get_post( $id );
		if ( ! $post || $post->post_type !== $post_type ) {
			return false;
		}
		// The IDs across sites are independent. Compare titles too so we
		// don't conclude "ID 960 already matches" when both sites just
		// happen to have *something* with that ID.
		if ( $expected_title !== '' && trim( $post->post_title ) !== trim( $expected_title ) ) {
			return false;
		}
		if ( $language === '' || ! sei_is_multilingual_active() ) {
			return true;
		}
		$post_lang = (string) ( apply_filters( 'wpml_element_language_code', null, array(
			'element_id'   => (int) $id,
			'element_type' => 'post_' . $post->post_type,
		) ) ?: '' );
		return $post_lang === $language;
	}

	/**
	 * Look up posts by exact post_name or post_title, filter by language.
	 *
	 * @return int|null
	 */
	private static function find_by_field( $field, $value, $post_type, $language ) {
		global $wpdb;

		$column = $field === 'post_name' ? 'post_name' : 'post_title';
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE {$column} = %s
			   AND post_type = %s
			   AND post_status IN ('publish','draft','pending','private','future')
			 LIMIT 50",
			$value,
			$post_type
		) );

		if ( empty( $ids ) ) {
			return null;
		}

		if ( $language === '' || ! sei_is_multilingual_active() ) {
			return (int) $ids[0];
		}

		foreach ( $ids as $id ) {
			$post_lang = (string) ( apply_filters( 'wpml_element_language_code', null, array(
				'element_id'   => (int) $id,
				'element_type' => 'post_' . $post_type,
			) ) ?: '' );
			if ( $post_lang === $language ) {
				return (int) $id;
			}
		}

		return null;
	}
}
