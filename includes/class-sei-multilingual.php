<?php
/**
 * WPML / WP-LOC compatibility layer for translation export & connect.
 *
 * @package Simple_Export_Import
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEI_Multilingual {

	/**
	 * Fetch all translations for a post via the WPML hook surface.
	 *
	 * @return object[] Map of language_code => stdClass{element_id, language_code, source_language_code}.
	 */
	public static function get_post_translations( $post_id, $post_type ) {
		if ( ! sei_is_multilingual_active() ) {
			return array();
		}

		$element_type = 'post_' . $post_type;

		$lang_details = sei_normalize_lang_details( apply_filters( 'wpml_element_language_details', null, array(
			'element_id'   => $post_id,
			'element_type' => $element_type,
		) ) );

		if ( ! $lang_details || empty( $lang_details->trid ) ) {
			return array();
		}

		$translations = apply_filters( 'wpml_get_element_translations', null, $lang_details->trid, $element_type );

		if ( empty( $translations ) || ! is_array( $translations ) ) {
			return array();
		}

		return $translations;
	}

	/**
	 * Connect imported translations in the multilingual backend.
	 * Uses the WPML hook surface only — works with real WPML, WP-LOC, or
	 * any plugin that registers the same filters/actions. Avoids touching
	 * $sitepress directly so we don't rely on a global that may be a mock
	 * (WP-LOC) or absent.
	 *
	 * @param int               $original_post_id Newly inserted "original-language" post ID.
	 * @param string            $original_lang    Original language code in WPML format (e.g. "en").
	 * @param array<string,int> $translations_map language_code => post_id of inserted translations.
	 */
	public static function connect_translations( $original_post_id, $original_lang, $translations_map ) {
		if ( ! sei_is_multilingual_active() || empty( $translations_map ) ) {
			return;
		}

		$post_type    = get_post_type( $original_post_id );
		$element_type = 'post_' . $post_type;

		// Reuse the original's existing trid if any; otherwise let the
		// backend allocate a fresh translation group.
		$trid = apply_filters( 'wpml_element_trid', null, $original_post_id, $element_type );

		do_action( 'wpml_set_element_language_details', array(
			'element_id'           => $original_post_id,
			'element_type'         => $element_type,
			'trid'                 => $trid ?: null,
			'language_code'        => $original_lang,
			'source_language_code' => null,
		) );

		// Re-fetch trid in case the backend just allocated a new one.
		$trid = apply_filters( 'wpml_element_trid', null, $original_post_id, $element_type );

		foreach ( $translations_map as $lang_code => $translation_post_id ) {
			do_action( 'wpml_set_element_language_details', array(
				'element_id'           => $translation_post_id,
				'element_type'         => $element_type,
				'trid'                 => $trid ?: null,
				'language_code'        => $lang_code,
				'source_language_code' => $original_lang,
			) );
		}
	}
}
