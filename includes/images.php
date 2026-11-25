<?php
/**
 * Bild-Optimierung (SPEC 3.5).
 *
 * @package HypetechCore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hochgeladene JPEG/PNG als WebP ausgeben (AVIF, wenn der Server es kann und HYPETECH_AVIF gesetzt ist).
 */
add_filter(
	'image_editor_output_format',
	static function ( array $formats ): array {
		$target = 'image/webp';
		if ( defined( 'HYPETECH_AVIF' ) && HYPETECH_AVIF && wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) ) ) {
			$target = 'image/avif';
		}
		if ( ! wp_image_editor_supports( array( 'mime_type' => $target ) ) ) {
			return $formats;
		}
		$formats['image/jpeg'] = $target;
		$formats['image/png']  = $target;
		return $formats;
	}
);

// Qualität: guter Kompromiss für Fotos.
add_filter( 'wp_editor_set_quality', static fn( $q, $mime ) => in_array( $mime, array( 'image/webp', 'image/avif' ), true ) ? 78 : $q, 10, 2 );

// Riesige Uploads (Handyfotos) auf 2560 px begrenzen.
add_filter( 'big_image_size_threshold', static fn() => 2560 );

// Ungenutzte Standardgrößen entfernen (Theme registriert die eigenen).
add_filter(
	'intermediate_image_sizes',
	static fn( array $sizes ): array => array_values( array_diff( $sizes, array( 'medium_large', '1536x1536', '2048x2048' ) ) )
);

/**
 * Hinweis, wenn ein Bild ohne Alternativtext eingefügt wird, steht im Editor-Inspektor von WordPress selbst.
 * Zusätzlich: Mediathek-Upload übernimmt den Dateinamen nicht mehr als Titel-Unsinn.
 */
add_filter(
	'wp_insert_attachment_data',
	static function ( array $data ): array {
		if ( ! empty( $data['post_title'] ) ) {
			$data['post_title'] = ucfirst( trim( preg_replace( '/[-_]+/', ' ', $data['post_title'] ) ) );
		}
		return $data;
	}
);
