<?php
/**
 * Admin-Aufräumen für die Kundenrolle (SPEC 4.2).
 *
 * @package HypetechCore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Menü auf Dashboard, Seiten, Medien, Profil reduzieren.
 */
add_action(
	'admin_menu',
	static function (): void {
		if ( ! hypetech_is_kunde() ) {
			return;
		}
		global $menu;
		$allowed = array( 'index.php', 'edit.php?post_type=page', 'upload.php', 'profile.php' );
		if ( hypetech_kunde_darf_beitraege() ) {
			$allowed[] = 'edit.php';
		}
		foreach ( (array) $menu as $item ) {
			if ( isset( $item[2] ) && ! in_array( $item[2], $allowed, true ) ) {
				remove_menu_page( $item[2] );
			}
		}
		remove_submenu_page( 'edit.php?post_type=page', 'post-new.php?post_type=page' );
		remove_submenu_page( 'index.php', 'update-core.php' );
	},
	999
);

/**
 * Seiten im Admin, die der Kunde nie sehen soll – auch nicht per direkter URL.
 * (Die meisten sind schon über fehlende Rechte gesperrt; das hier ist die zweite Linie.)
 */
add_action(
	'current_screen',
	static function ( WP_Screen $screen ): void {
		if ( ! hypetech_is_kunde() ) {
			return;
		}
		$forbidden = array( 'site-editor', 'customize', 'widgets', 'tools', 'export', 'import', 'edit-wp_block', 'themes', 'plugins', 'options-general', 'users' );
		if ( in_array( $screen->id, $forbidden, true ) || in_array( $screen->base, $forbidden, true ) ) {
			wp_die( esc_html__( 'Dieser Bereich ist für Ihren Zugang nicht freigeschaltet.', 'hypetech-core' ), 403 );
		}
	}
);

/**
 * Dashboard: nur das HYPE:TECH-Widget.
 */
add_action(
	'wp_dashboard_setup',
	static function (): void {
		if ( ! hypetech_is_kunde() ) {
			return;
		}
		global $wp_meta_boxes;
		$wp_meta_boxes['dashboard'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		remove_action( 'welcome_panel', 'wp_welcome_panel' );
		wp_add_dashboard_widget( 'hypetech_hilfe', 'Willkommen – so pflegen Sie Ihre Website', 'hypetech_dashboard_widget' );
	},
	999
);

/**
 * Inhalt des Dashboard-Widgets.
 */
function hypetech_dashboard_widget(): void {
	$kontakt = get_option( 'hypetech_support_kontakt', 'support@hype-tech.de' );
	?>
	<ol style="padding-left:1.2em;line-height:1.7">
		<li>Links auf <strong>Seiten</strong> klicken und die gewünschte Seite öffnen.</li>
		<li>In einen Text klicken und lostippen. Bilder: Bild anklicken → <em>Ersetzen</em>.</li>
		<li>Beim Bildwechsel immer den <strong>Alternativtext</strong> ausfüllen (kurze Bildbeschreibung).</li>
		<li>Oben rechts auf <strong>Aktualisieren</strong> klicken – fertig.</li>
	</ol>
	<?php if ( hypetech_kunde_darf_beitraege() ) : ?>
	<p><strong>Neuer Beitrag:</strong> Links auf <em>Beiträge → Neu hinzufügen</em>. Titel, kurze Zusammenfassung (Textauszug), Beitragsbild und Kategorie ausfüllen, Text schreiben, <em>Veröffentlichen</em>.</p>
	<?php endif; ?>
	<p>Layout und Design sind geschützt, damit nichts versehentlich verrutscht. Sie möchten einen neuen Abschnitt oder eine neue Seite?</p>
	<p><strong>Ihr Ansprechpartner:</strong> <?php echo esc_html( $kontakt ); ?></p>
	<?php
}

/**
 * Nach dem Login direkt zur Seitenliste (statt ins Profil).
 */
add_filter(
	'login_redirect',
	static function ( $redirect, $requested, $user ) {
		if ( $user instanceof WP_User && hypetech_is_kunde( $user ) && ( ! $requested || str_contains( (string) $requested, 'wp-admin/profile.php' ) || admin_url() === $requested ) ) {
			return admin_url( 'edit.php?post_type=page' );
		}
		return $redirect;
	},
	10,
	3
);

/**
 * Kommentare global deaktivieren (Version 1 hat keinen Blog).
 */
add_action(
	'init',
	static function (): void {
		foreach ( array( 'post', 'page', 'attachment' ) as $type ) {
			remove_post_type_support( $type, 'comments' );
			remove_post_type_support( $type, 'trackbacks' );
		}
	},
	100
);
add_filter( 'comments_open', '__return_false', 20 );
add_filter( 'pings_open', '__return_false', 20 );
add_filter( 'comments_array', '__return_empty_array', 10 );
add_action(
	'admin_menu',
	static function (): void {
		remove_menu_page( 'edit-comments.php' );
	}
);
add_action(
	'admin_bar_menu',
	static function ( WP_Admin_Bar $bar ): void {
		$bar->remove_node( 'comments' );
		$bar->remove_node( 'wp-logo' );
		if ( hypetech_is_kunde() ) {
			// „Neu“ nur für Beiträge, wenn freigeschaltet – nie Seiten oder Nutzer.
			$weg = hypetech_kunde_darf_beitraege() ? array( 'new-page', 'new-user' ) : array( 'new-content' );
			foreach ( array_merge( $weg, array( 'customize', 'updates', 'search', 'site-editor' ) ) as $node ) {
				$bar->remove_node( $node );
			}
		}
	},
	999
);

/**
 * Admin-Fußzeile.
 */
add_filter(
	'admin_footer_text',
	static fn() => 'Website betreut von <strong>HYPE:TECH</strong>'
);
