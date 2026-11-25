<?php
/**
 * Editor-Sperren für die Kundenrolle (SPEC 3.4, Entscheidung #09).
 *
 * @package HypetechCore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Blöcke, die der Kunde im Editor sehen/benutzen darf.
 * Theme kann die Liste über den Filter 'hypetech_kunde_blocks' erweitern (eigene Patterns mit weiteren Blöcken).
 */
function hypetech_kunde_blocks(): array {
	$blocks = array(
		'core/group', 'core/columns', 'core/column', 'core/cover', 'core/media-text',
		'core/heading', 'core/paragraph', 'core/list', 'core/list-item', 'core/quote',
		'core/image', 'core/gallery', 'core/buttons', 'core/button', 'core/details',
		'core/separator', 'core/spacer', 'core/social-links', 'core/social-link',
		'hypetech/contact-form',
	);
	if ( hypetech_kunde_darf_abschnitte() ) {
		$blocks[] = 'core/pattern';
	}
	return apply_filters( 'hypetech_kunde_blocks', $blocks );
}

add_filter(
	'allowed_block_types_all',
	static function ( $allowed ) {
		return hypetech_is_kunde() ? hypetech_kunde_blocks() : $allowed;
	},
	20
);

/**
 * Editor-Einstellungen für Kunden.
 */
add_filter(
	'block_editor_settings_all',
	static function ( array $settings ): array {
		// Für alle Nutzer: keine Fremdquellen im Editor (DSGVO).
		$settings['enableOpenverseMediaCategory'] = false;

		if ( ! hypetech_is_kunde() ) {
			return $settings;
		}
		$settings['canLockBlocks']      = false;
		$settings['codeEditingEnabled'] = false;
		$settings['__experimentalCanUserUseUnfilteredHTML'] = false;
		// Ohne Abschnittsverwaltung: oberste Ebene komplett gesperrt (kein Einfügen/Verschieben/Löschen).
		if ( ! hypetech_kunde_darf_abschnitte() ) {
			$settings['templateLock'] = 'all';
		}
		return $settings;
	},
	20
);

/**
 * Keine Core-/Remote-Patterns und kein Block-Verzeichnis: nur unsere Abschnitte.
 */
add_action(
	'after_setup_theme',
	static function (): void {
		remove_theme_support( 'core-block-patterns' );
	},
	20
);
add_filter( 'should_load_remote_block_patterns', '__return_false' );
remove_action( 'enqueue_block_editor_assets', 'wp_enqueue_editor_block_directory_assets' );

/**
 * Kleines Editor-Skript für Kunden: HTML-Bearbeitung einzelner Blöcke abschalten.
 */
add_action(
	'enqueue_block_editor_assets',
	static function (): void {
		if ( ! hypetech_is_kunde() ) {
			return;
		}
		wp_register_script( 'hypetech-kunde-editor', '', array( 'wp-hooks', 'wp-blocks' ), HYPETECH_CORE_VERSION, false );
		wp_enqueue_script( 'hypetech-kunde-editor' );
		wp_add_inline_script(
			'hypetech-kunde-editor',
			"wp.hooks.addFilter('blocks.registerBlockType','hypetech/kunde-ohne-html',function(s){return Object.assign({},s,{supports:Object.assign({},s.supports,{html:false,lock:false})});});"
		);
	},
	1
);
