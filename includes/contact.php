<?php
/**
 * Kontaktformular (SPEC 4.5): Block hypetech/contact-form, REST-Endpunkt und Fallback ohne JavaScript.
 * Speichert NICHTS in der Datenbank. Keine Logs mit personenbezogenen Daten.
 *
 * @package HypetechCore
 */

defined( 'ABSPATH' ) || exit;

const HYPETECH_CONTACT_MIN_SECONDS = 3;
const HYPETECH_CONTACT_MAX_LENGTH  = 5000;

add_action(
	'init',
	static function (): void {
		register_block_type( HYPETECH_CORE_DIR . '/blocks/contact-form' );
	}
);

/**
 * Signiertes Zeit-Token für die Mindestzeit zwischen Laden und Absenden.
 */
function hypetech_contact_token(): string {
	$t = (string) time();
	return $t . '.' . substr( hash_hmac( 'sha256', $t, wp_salt( 'nonce' ) ), 0, 20 );
}

function hypetech_contact_token_ok( string $token ): bool {
	$parts = explode( '.', $token );
	if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
		return false;
	}
	$expected = substr( hash_hmac( 'sha256', $parts[0], wp_salt( 'nonce' ) ), 0, 20 );
	return hash_equals( $expected, $parts[1] ) && ( time() - (int) $parts[0] ) >= HYPETECH_CONTACT_MIN_SECONDS;
}

/**
 * Verarbeitet eine Anfrage. Gibt ['ok' => bool, 'message' => string, 'errors' => [feld => text]] zurück.
 */
function hypetech_contact_process( array $input ): array {
	$fail = static fn( string $msg, array $errors = array() ) => array( 'ok' => false, 'message' => $msg, 'errors' => $errors );

	// Honeypot: Bots füllen das versteckte Feld. Wir tun so, als wäre alles gut.
	if ( ! empty( $input['website'] ) ) {
		return array( 'ok' => true, 'message' => '', 'errors' => array() );
	}
	if ( ! hypetech_contact_token_ok( (string) ( $input['_ht'] ?? '' ) ) ) {
		return $fail( 'Das Formular wurde zu schnell abgeschickt. Bitte versuchen Sie es in ein paar Sekunden erneut.' );
	}
	if ( hypetech_rate_limited( 'contact', 5, 10 * MINUTE_IN_SECONDS ) ) {
		return $fail( 'Zu viele Anfragen in kurzer Zeit. Bitte versuchen Sie es später erneut oder rufen Sie uns an.' );
	}

	$name      = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
	$email     = sanitize_email( (string) ( $input['email'] ?? '' ) );
	$telefon   = sanitize_text_field( (string) ( $input['telefon'] ?? '' ) );
	$nachricht = sanitize_textarea_field( (string) ( $input['nachricht'] ?? '' ) );

	$errors = array();
	if ( '' === $name || mb_strlen( $name ) > 200 ) {
		$errors['name'] = 'Bitte geben Sie Ihren Namen an.';
	}
	if ( ! is_email( $email ) ) {
		$errors['email'] = 'Bitte geben Sie eine gültige E-Mail-Adresse an.';
	}
	if ( mb_strlen( $telefon ) > 50 ) {
		$errors['telefon'] = 'Die Telefonnummer ist zu lang.';
	}
	if ( '' === trim( $nachricht ) ) {
		$errors['nachricht'] = 'Bitte schreiben Sie uns eine Nachricht.';
	} elseif ( mb_strlen( $nachricht ) > HYPETECH_CONTACT_MAX_LENGTH ) {
		$errors['nachricht'] = 'Die Nachricht ist zu lang (maximal 5.000 Zeichen).';
	}
	if ( $errors ) {
		return $fail( 'Bitte prüfen Sie die markierten Felder.', $errors );
	}

	$recipient = sanitize_email( (string) get_option( 'hypetech_contact_recipient', '' ) );
	if ( ! is_email( $recipient ) ) {
		$recipient = get_option( 'admin_email' );
	}

	$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$subject = sprintf( 'Neue Anfrage über %s von %s', $site, $name );
	$body    = "Neue Anfrage über das Kontaktformular\n\n"
		. "Name: {$name}\n"
		. "E-Mail: {$email}\n"
		. ( $telefon ? "Telefon: {$telefon}\n" : '' )
		. "\nNachricht:\n{$nachricht}\n\n"
		. "—\nGesendet von " . home_url( '/' ) . "\nAntworten Sie direkt auf diese Mail, um dem Absender zu antworten.";
	$headers = array(
		'Content-Type: text/plain; charset=UTF-8',
		sprintf( 'Reply-To: %s <%s>', str_replace( array( "\r", "\n", '<', '>' ), '', $name ), $email ),
	);

	if ( ! wp_mail( $recipient, $subject, $body, $headers ) ) {
		return $fail( 'Die Nachricht konnte gerade nicht versendet werden. Bitte rufen Sie uns an oder schreiben Sie uns direkt eine E-Mail.' );
	}
	return array( 'ok' => true, 'message' => '', 'errors' => array() );
}

/**
 * REST: POST /wp-json/hypetech/v1/contact (Absenden ohne Neuladen).
 */
add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'hypetech/v1',
			'/contact',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ) {
					$result = hypetech_contact_process( (array) $request->get_body_params() );
					return new WP_REST_Response( $result, $result['ok'] ? 200 : 422 );
				},
			)
		);
	}
);

/**
 * Fallback ohne JavaScript: normales POST an admin-post.php, danach Weiterleitung zurück.
 */
function hypetech_contact_admin_post(): void {
	$result = hypetech_contact_process( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- öffentliches Formular, eigener Spamschutz.
	$back   = wp_get_referer() ? wp_get_referer() : home_url( '/' );
	$back   = remove_query_arg( array( 'kontakt', 'kontakt_msg' ), $back );
	$args   = $result['ok'] ? array( 'kontakt' => 'ok' ) : array( 'kontakt' => 'fehler', 'kontakt_msg' => rawurlencode( $result['message'] . ' ' . implode( ' ', $result['errors'] ) ) );
	wp_safe_redirect( add_query_arg( $args, $back ) . '#kontaktformular', 303 );
	exit;
}
add_action( 'admin_post_nopriv_hypetech_contact', 'hypetech_contact_admin_post' );
add_action( 'admin_post_hypetech_contact', 'hypetech_contact_admin_post' );

/**
 * Lokale Entwicklung: Mails nicht versenden, sondern in wp-content/hypetech-mails.log schreiben.
 * Nur bei WP_ENVIRONMENT_TYPE=local (wp-env setzt das). Auf Servern nie aktiv.
 */
add_filter(
	'pre_wp_mail',
	static function ( $short, array $atts ) {
		if ( 'local' !== wp_get_environment_type() || hypetech_smtp_configured() ) {
			return $short;
		}
		$line = sprintf( "==== %s\nAn: %s\nBetreff: %s\nHeader: %s\n\n%s\n\n", gmdate( 'c' ), implode( ',', (array) $atts['to'] ), $atts['subject'], implode( ' | ', (array) $atts['headers'] ), $atts['message'] );
		file_put_contents( WP_CONTENT_DIR . '/hypetech-mails.log', $line, FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return true;
	},
	10,
	2
);
