<?php
/**
 * Selbst-Updater (Entscheidung #02/#07): fragt ein JSON-Manifest ab (erstellt von bin/release.mjs)
 * und meldet neue Versionen an den WordPress-Updater. Auto-Updates sind in security.php aktiviert.
 *
 * Manifest-Format: {"version":"1.0.1","download_url":"…/hypetech-core.zip","requires":"6.6","requires_php":"8.2","tested":"6.9","changelog":"…"}
 *
 * @package HypetechCore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manifest laden (6 h gecacht). Gibt null zurück, wenn nichts konfiguriert oder erreichbar ist.
 */
function hypetech_core_manifest( bool $force = false ): ?array {
	$url = (string) HYPETECH_CORE_UPDATE_JSON;
	if ( '' === $url || str_contains( $url, '<ORG>' ) ) {
		return null;
	}
	$cached = get_site_transient( 'hypetech_core_manifest' );
	if ( ! $force && is_array( $cached ) ) {
		return $cached ?: null;
	}
	$response = wp_remote_get( $url, array( 'timeout' => 10, 'headers' => array( 'Accept' => 'application/json' ) ) );
	$data     = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	$valid    = is_array( $data ) && ! empty( $data['version'] ) && ! empty( $data['download_url'] ) && str_starts_with( (string) $data['download_url'], 'https://' );
	set_site_transient( 'hypetech_core_manifest', $valid ? $data : array(), $valid ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
	return $valid ? $data : null;
}

add_filter(
	'pre_set_site_transient_update_plugins',
	static function ( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$manifest = hypetech_core_manifest();
		if ( ! $manifest ) {
			return $transient;
		}
		$basename = plugin_basename( HYPETECH_CORE_FILE );
		$item     = (object) array(
			'id'           => 'hypetech-core',
			'slug'         => 'hypetech-core',
			'plugin'       => $basename,
			'new_version'  => $manifest['version'],
			'package'      => $manifest['download_url'],
			'url'          => 'https://hype-tech.de',
			'requires'     => $manifest['requires'] ?? '',
			'requires_php' => $manifest['requires_php'] ?? '',
			'tested'       => $manifest['tested'] ?? '',
		);
		if ( version_compare( $manifest['version'], HYPETECH_CORE_VERSION, '>' ) ) {
			$transient->response[ $basename ] = $item;
		} else {
			$transient->no_update[ $basename ] = $item;
		}
		return $transient;
	}
);

/**
 * Detail-Popup „Details ansehen“ im Plugin-Bildschirm.
 */
add_filter(
	'plugins_api',
	static function ( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ( $args->slug ?? '' ) !== 'hypetech-core' ) {
			return $result;
		}
		$manifest = hypetech_core_manifest();
		if ( ! $manifest ) {
			return $result;
		}
		return (object) array(
			'name'          => 'HYPE:TECH Core',
			'slug'          => 'hypetech-core',
			'version'       => $manifest['version'],
			'requires'      => $manifest['requires'] ?? '',
			'requires_php'  => $manifest['requires_php'] ?? '',
			'tested'        => $manifest['tested'] ?? '',
			'download_link' => $manifest['download_url'],
			'sections'      => array( 'changelog' => wp_kses_post( wpautop( $manifest['changelog'] ?? '' ) ) ),
		);
	},
	10,
	3
);

/**
 * Nach dem Update: Ordnername sicherstellen (ZIP von GitHub kann anders heißen).
 */
add_filter(
	'upgrader_source_selection',
	static function ( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( ( $hook_extra['plugin'] ?? '' ) !== plugin_basename( HYPETECH_CORE_FILE ) ) {
			return $source;
		}
		$target = trailingslashit( $remote_source ) . 'hypetech-core/';
		if ( untrailingslashit( $source ) !== untrailingslashit( $target ) && rename( $source, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return $target;
		}
		return $source;
	},
	10,
	4
);
