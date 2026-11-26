<?php
/**
 * Frontend aufräumen (SPEC 3.6). Zentral im Kern, damit jede Kundenseite gleich schlank ist.
 *
 * @package HypetechCore
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	static function (): void {
		// Emojis.
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		add_filter( 'emoji_svg_url', '__return_false' );

		// Unnötige <head>-Einträge.
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		remove_action( 'wp_head', 'feed_links', 2 );
		remove_action( 'wp_head', 'feed_links_extra', 3 );
		remove_action( 'wp_head', 'rest_output_link_wp_head' );
		remove_action( 'template_redirect', 'rest_output_link_header', 11 );
		remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
	}
);

// Block-CSS nur für tatsächlich genutzte Blöcke laden.
add_filter( 'should_load_separate_core_block_assets', '__return_true' );

// Kein jQuery und kein jQuery-Migrate im Frontend.
add_action(
	'wp_enqueue_scripts',
	static function (): void {
		if ( ! is_admin() && ! is_user_logged_in() ) {
			wp_dequeue_script( 'jquery' );
			wp_deregister_script( 'jquery' );
		}
	},
	100
);

// Keine Feeds (kein Blog in Version 1).
foreach ( array( 'do_feed', 'do_feed_rdf', 'do_feed_rss', 'do_feed_rss2', 'do_feed_atom' ) as $feed_hook ) {
	add_action(
		$feed_hook,
		static function (): void {
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		},
		1
	);
}

// Anhang-Seiten (attachment pages) auf die Datei umleiten.
add_action(
	'template_redirect',
	static function (): void {
		if ( is_attachment() ) {
			$url = wp_get_attachment_url( (int) get_queried_object_id() );
			wp_safe_redirect( $url ? $url : home_url( '/' ), 301 );
			exit;
		}
	}
);

/**
 * Seiten-Cache an CDNs (z. B. Hostinger „hcdn“) begrenzen: Ohne Angabe hält das CDN HTML bis zu 7 Tage –
 * Inhalts- und Code-Änderungen wären so lange unsichtbar. Für Besucher: kurz cachen, im Hintergrund erneuern.
 * Angemeldete Nutzer, Vorschauen und Formular-Antworten werden nicht gecacht.
 * Filter `hypetech_cache_control` (leerer String = Header nicht setzen).
 */
add_action(
	'send_headers',
	static function (): void {
		if ( is_admin() || headers_sent() || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return;
		}
		$wert = ( is_user_logged_in() || is_preview() || isset( $_GET['kontakt'] ) ) // phpcs:ignore WordPress.Security.NonceVerification
			? 'private, no-store'
			: 'public, max-age=0, s-maxage=300, stale-while-revalidate=60';
		$wert = (string) apply_filters( 'hypetech_cache_control', $wert );
		if ( '' !== $wert ) {
			header( 'Cache-Control: ' . $wert );
		}
	}
);
