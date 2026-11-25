<?php
/**
 * Status-Endpunkt für das Monitoring (tools/monitor.mjs).
 * Nur aktiv, wenn HYPETECH_STATUS_TOKEN in wp-config.php gesetzt ist. Abruf mit Header „X-Hypetech-Token“.
 * Liefert ausschließlich technische Daten, keine Inhalte und keine personenbezogenen Daten.
 *
 * @package HypetechCore
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'rest_api_init',
	static function (): void {
		if ( ! defined( 'HYPETECH_STATUS_TOKEN' ) || strlen( (string) HYPETECH_STATUS_TOKEN ) < 32 ) {
			return;
		}
		register_rest_route(
			'hypetech/v1',
			'/status',
			array(
				'methods'             => 'GET',
				'permission_callback' => static function ( WP_REST_Request $request ): bool {
					if ( hypetech_rate_limited( 'status', 30, HOUR_IN_SECONDS ) ) {
						return false;
					}
					return hash_equals( (string) HYPETECH_STATUS_TOKEN, (string) $request->get_header( 'x_hypetech_token' ) );
				},
				'callback'            => static function () {
					require_once ABSPATH . 'wp-admin/includes/update.php';
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
					wp_update_plugins();
					$plugin_updates = get_plugin_updates();
					$core_updates   = get_core_updates();
					$theme          = wp_get_theme();
					$admins_ohne_2fa = array();
					if ( class_exists( 'Two_Factor_Core' ) ) {
						foreach ( get_users( array( 'role' => 'administrator', 'fields' => array( 'ID' ) ) ) as $u ) {
							if ( ! Two_Factor_Core::is_user_using_two_factor( $u->ID ) ) {
								$admins_ohne_2fa[] = (int) $u->ID;
							}
						}
					}
					return array(
						'wp'               => get_bloginfo( 'version' ),
						'wp_update'        => ( isset( $core_updates[0]->response ) && 'upgrade' === $core_updates[0]->response ) ? $core_updates[0]->current : null,
						'php'              => PHP_VERSION,
						'core'             => HYPETECH_CORE_VERSION,
						'theme'            => $theme->get_stylesheet() . '@' . $theme->get( 'Version' ),
						'plugin_updates'   => array_values( array_map( static fn( $p ) => $p->Name, $plugin_updates ) ),
						'file_edit_off'    => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
						'smtp'             => hypetech_smtp_configured(),
						'two_factor'       => class_exists( 'Two_Factor_Core' ),
						'admins_ohne_2fa'  => count( $admins_ohne_2fa ),
						'indexierbar'      => (bool) get_option( 'blog_public' ),
						'umgebung'         => wp_get_environment_type(),
					);
				},
			)
		);
	}
);
