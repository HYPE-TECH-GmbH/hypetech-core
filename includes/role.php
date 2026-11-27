<?php
/**
 * Kundenrolle hypetech_kunde (SPEC 4.1) und Sperre „Seiten anlegen“.
 *
 * @package HypetechCore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rolle bei Versionswechsel (neu) anlegen. Läuft billig auf jedem Request, schreibt nur bei Änderung.
 */
add_action(
	'init',
	static function (): void {
		if ( get_option( 'hypetech_core_role_version' ) === HYPETECH_CORE_VERSION ) {
			return;
		}
		remove_role( HYPETECH_ROLE );
		add_role(
			HYPETECH_ROLE,
			'Kunde (HYPE:TECH)',
			array(
				'read'                 => true,
				'edit_pages'           => true,
				'edit_published_pages' => true,
				'edit_others_pages'    => true,
				// Nötig, damit der Editor „Aktualisieren“ statt „Zur Überprüfung einreichen“ zeigt.
				// Seiten anlegen ist separat gesperrt, offline nehmen verhindert editor.php.
				'publish_pages'        => true,
				'upload_files'         => true,
				// Eigene Beiträge: nur wirksam mit kunde_darf_beitraege (siehe user_has_cap unten).
				'edit_posts'             => true,
				'edit_published_posts'   => true,
				'publish_posts'          => true,
				'delete_posts'           => true,
				'delete_published_posts' => true,
			)
		);
		// Eigenes Recht „Seiten anlegen“ für Admins und Redakteure.
		foreach ( array( 'administrator', 'editor' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->add_cap( 'hypetech_create_pages' );
			}
		}
		update_option( 'hypetech_core_role_version', HYPETECH_CORE_VERSION, true );
	},
	5
);

/**
 * Seiten anlegen ist standardmäßig an edit_pages gekoppelt. Wir trennen das,
 * damit der Kunde bestehende Seiten bearbeiten, aber keine neuen anlegen kann.
 */
add_filter(
	'register_post_type_args',
	static function ( array $args, string $post_type ): array {
		if ( 'page' === $post_type ) {
			$args['capabilities']                 = $args['capabilities'] ?? array();
			$args['capabilities']['create_posts'] = 'hypetech_create_pages';
		}
		return $args;
	},
	10,
	2
);

/**
 * Wiederverwendbare Blöcke, Navigationen, Templates: für Kunden komplett gesperrt.
 */
add_filter(
	'map_meta_cap',
	static function ( array $caps, string $cap, int $user_id, array $args = array() ): array {
		static $blocked_types = array( 'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part', 'wp_global_styles' );
		if ( ! in_array( $cap, array( 'edit_post', 'delete_post', 'publish_post', 'read_post' ), true ) && ! str_starts_with( $cap, 'edit_' ) ) {
			return $caps;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || ! hypetech_is_kunde( $user ) ) {
			return $caps;
		}
		foreach ( $blocked_types as $type ) {
			if ( str_contains( $cap, $type ) ) {
				return array( 'do_not_allow' );
			}
		}
		// Kunde darf keine Seiten löschen (auch nicht in den Papierkorb) – eigene Beiträge schon, wenn freigeschaltet.
		if ( 'delete_post' === $cap && hypetech_kunde_darf_beitraege() && isset( $args[0] ) && 'post' === get_post_type( (int) $args[0] ) ) {
			return $caps;
		}
		if ( in_array( $cap, array( 'delete_post', 'delete_page' ), true ) ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	},
	10,
	4
);

/**
 * Beitragsrechte nur, wenn für diese Website freigeschaltet (Entscheidung #21).
 * Die Rolle ist überall gleich, die Freigabe ist eine Option der jeweiligen Website.
 */
add_filter(
	'user_has_cap',
	static function ( array $allcaps, array $caps, array $args, WP_User $user ): array {
		if ( ! in_array( HYPETECH_ROLE, (array) $user->roles, true ) || ! empty( $allcaps['manage_options'] ) || hypetech_kunde_darf_beitraege() ) {
			return $allcaps;
		}
		foreach ( array( 'edit_posts', 'edit_published_posts', 'publish_posts', 'delete_posts', 'delete_published_posts' ) as $c ) {
			unset( $allcaps[ $c ] );
		}
		return $allcaps;
	},
	10,
	4
);
