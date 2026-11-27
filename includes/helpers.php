<?php
/**
 * Gemeinsame Hilfsfunktionen.
 *
 * @package HypetechCore
 */

defined( 'ABSPATH' ) || exit;

const HYPETECH_ROLE = 'hypetech_kunde';

/**
 * Ist der aktuelle (oder übergebene) Nutzer ein Kunde mit eingeschränkten Rechten?
 * Administratoren haben nie die Kundenrolle, auch wenn sie ihnen zusätzlich gegeben wurde.
 */
function hypetech_is_kunde( ?WP_User $user = null ): bool {
	$user = $user ?? wp_get_current_user();
	if ( ! $user || ! $user->exists() ) {
		return false;
	}
	return in_array( HYPETECH_ROLE, (array) $user->roles, true ) && ! user_can( $user, 'manage_options' );
}

/**
 * Darf der Kunde ganze Abschnitte einfügen, verschieben und löschen? (Entscheidung #09, pro Kunde in site.json)
 */
function hypetech_kunde_darf_abschnitte(): bool {
	return (bool) get_option( 'hypetech_kunde_darf_abschnitte', false );
}

/**
 * Text in der Sprache der Website: Deutsch für de_*, sonst Englisch (Entscheidung #23).
 * Für feste Kern-Texte im Frontend (Formular, Meldungen) – ohne Übersetzungsdateien.
 */
function hypetech_t( string $de, string $en ): string {
	return str_starts_with( get_locale(), 'de' ) ? $de : $en;
}

/**
 * Darf der Kunde Beiträge (Blog, z. B. Projektberichte) selbst anlegen, bearbeiten und löschen?
 * Pro Kunde in site.json (`kunde_darf_beitraege`). Seiten bleiben davon unberührt gesperrt.
 */
function hypetech_kunde_darf_beitraege(): bool {
	return (bool) get_option( 'hypetech_kunde_darf_beitraege', false );
}

/**
 * Anonymisierter Schlüssel für die Client-IP (für Rate-Limits). Die IP selbst wird nie gespeichert.
 */
function hypetech_ip_key( string $prefix ): string {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
	return 'ht_' . $prefix . '_' . substr( wp_hash( $ip . gmdate( 'Y-m-d' ) ), 0, 24 );
}

/**
 * Einfaches Rate-Limit über Transients. Gibt true zurück, wenn das Limit überschritten ist.
 */
function hypetech_rate_limited( string $bucket, int $max, int $window_seconds, bool $count = true ): bool {
	$key  = hypetech_ip_key( $bucket );
	$hits = (int) get_transient( $key );
	if ( $hits >= $max ) {
		return true;
	}
	if ( $count ) {
		set_transient( $key, $hits + 1, $window_seconds );
	}
	return false;
}
