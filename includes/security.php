<?php
/**
 * Sicherheits-Basis (SPEC 4.3).
 *
 * @package HypetechCore
 */

defined( 'ABSPATH' ) || exit;

// Datei-Editor für Themes/Plugins aus – auch falls wp-config.php es vergessen hat.
if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
	define( 'DISALLOW_FILE_EDIT', true );
}

// XML-RPC und Pingback komplett aus – xmlrpc.php antwortet mit 403.
if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
	http_response_code( 403 );
	exit( 'XML-RPC ist deaktiviert.' );
}
add_filter( 'xmlrpc_enabled', '__return_false' );
add_filter( 'xmlrpc_methods', '__return_empty_array' );
add_filter(
	'wp_headers',
	static function ( array $headers ): array {
		unset( $headers['X-Pingback'] );
		return $headers;
	}
);

// Anwendungspasswörter aus (werden nicht gebraucht, verringern Angriffsfläche).
add_filter( 'wp_is_application_passwords_available', '__return_false' );

/**
 * Benutzer-Aufzählung verhindern: REST /wp/v2/users nur für eingeloggte Nutzer.
 */
add_filter(
	'rest_endpoints',
	static function ( array $endpoints ): array {
		if ( is_user_logged_in() ) {
			return $endpoints;
		}
		foreach ( array_keys( $endpoints ) as $route ) {
			if ( str_starts_with( $route, '/wp/v2/users' ) ) {
				unset( $endpoints[ $route ] );
			}
		}
		return $endpoints;
	}
);

/**
 * Autoren-Archive (?author=1, /author/name/) auf die Startseite umleiten.
 */
add_action(
	'template_redirect',
	static function (): void {
		if ( is_author() || isset( $_GET['author'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	},
	1
);
// Autoren nicht in der Sitemap.
add_filter(
	'wp_sitemaps_add_provider',
	static fn( $provider, $name ) => 'users' === $name ? false : $provider,
	10,
	2
);

/**
 * Login: einheitliche Fehlermeldung und Drosselung (5 Fehlversuche / 15 min pro IP-Hash).
 */
add_filter(
	'login_errors',
	static fn( $error ) => str_contains( (string) $error, 'Zu viele Fehlversuche' ) ? $error : 'Anmeldung fehlgeschlagen. Bitte Zugangsdaten prüfen.'
);

add_action(
	'wp_login_failed',
	static function (): void {
		hypetech_rate_limited( 'login', PHP_INT_MAX, 15 * MINUTE_IN_SECONDS );
	}
);

add_filter(
	'authenticate',
	static function ( $user ) {
		if ( hypetech_rate_limited( 'login', 5, 15 * MINUTE_IN_SECONDS, false ) ) {
			return new WP_Error( 'hypetech_gesperrt', 'Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen.' );
		}
		return $user;
	},
	30
);

/**
 * Sicherheits-Header.
 */
add_action(
	'send_headers',
	static function (): void {
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()' );
	}
);

/**
 * Automatische Updates: Core (auch Hauptversionen), Plugins, Übersetzungen. Themes nicht (eigenes Theme kommt per Deployment).
 */
add_filter( 'allow_major_auto_core_updates', '__return_true' );
add_filter( 'allow_minor_auto_core_updates', '__return_true' );
add_filter( 'auto_update_plugin', '__return_true' );
add_filter( 'auto_update_translation', '__return_true' );
add_filter(
	'auto_update_theme',
	static fn( $update, $item ) => isset( $item->theme ) && str_starts_with( (string) $item->theme, 'hypetech-' ) ? false : $update,
	10,
	2
);

/**
 * WordPress-Version nicht verraten.
 */
remove_action( 'wp_head', 'wp_generator' );
add_filter( 'the_generator', '__return_empty_string' );
