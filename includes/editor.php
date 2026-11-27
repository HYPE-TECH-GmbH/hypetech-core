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
	static function ( array $settings, $context = null ): array {
		// Für alle Nutzer: keine Fremdquellen im Editor (DSGVO).
		$settings['enableOpenverseMediaCategory'] = false;

		if ( ! hypetech_is_kunde() ) {
			return $settings;
		}
		$settings['canLockBlocks']      = false;
		$settings['codeEditingEnabled'] = false;
		$settings['__experimentalCanUserUseUnfilteredHTML'] = false;
		// Ohne Abschnittsverwaltung: oberste Ebene komplett gesperrt (kein Einfügen/Verschieben/Löschen).
		// Beiträge (Projektberichte) schreibt der Kunde frei aus den erlaubten Blöcken; Seiten bleiben gesperrt.
		$ist_beitrag = isset( $context->post ) && $context->post instanceof WP_Post && 'post' === $context->post->post_type;
		if ( ! hypetech_kunde_darf_abschnitte() && ! $ist_beitrag ) {
			$settings['templateLock'] = 'all';
		}
		return $settings;
	},
	20,
	2
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
 * Editor-Skript für Kunden (Komfort-Schicht – die harte Garantie ist die Strukturprüfung beim Speichern):
 * - HTML-Bearbeitung einzelner Blöcke aus
 * - „Vorlage bearbeiten“ (hebt contentOnly auf) ausblenden; wird eine Sperre trotzdem aufgehoben, sofort zurücksetzen
 */
add_action(
	'enqueue_block_editor_assets',
	static function (): void {
		if ( ! hypetech_is_kunde() ) {
			return;
		}
		wp_register_script( 'hypetech-kunde-editor', '', array( 'wp-hooks', 'wp-blocks', 'wp-data', 'wp-block-editor', 'wp-dom-ready' ), HYPETECH_CORE_VERSION, false );
		wp_enqueue_script( 'hypetech-kunde-editor' );
		wp_add_inline_script(
			'hypetech-kunde-editor',
			<<<'JS'
wp.hooks.addFilter('blocks.registerBlockType','hypetech/kunde-ohne-html',function(s){return Object.assign({},s,{supports:Object.assign({},s.supports,{html:false,lock:false})});});
wp.domReady(function(){
	var labels=/^(Vorlage bearbeiten|Inhalte bearbeiten|Bearbeiten|Edit pattern|Edit contents|Modify)$/;
	new MutationObserver(function(){
		document.querySelectorAll('.block-editor-block-toolbar button, .block-editor-block-inspector-edit-contents button').forEach(function(b){ if(labels.test(b.textContent.trim())) b.style.display='none'; });
	}).observe(document.body,{childList:true,subtree:true});
	var be=wp.data.select('core/block-editor'), d=wp.data.dispatch('core/block-editor'), gesperrt={};
	wp.data.subscribe(function(){
		be.getBlocks().forEach(function(b){
			if(b.attributes.templateLock==='contentOnly'){ gesperrt[b.clientId]=true; }
			else if(gesperrt[b.clientId]){ d.updateBlockAttributes(b.clientId,{templateLock:'contentOnly'}); }
		});
	});
});
JS
		);
		wp_add_inline_style( 'wp-edit-blocks', '.block-editor-block-inspector-edit-contents{display:none!important}' );
	},
	1
);

/**
 * Blockstruktur einer Seite als Vergleichswert: Blocknamen, Verschachtelung und Sperr-Attribute – ohne Inhalte.
 * Texte, Links und Bilder ändern die Struktur nicht; Abschnitte löschen/verschieben/umbauen schon.
 */
function hypetech_block_struktur( string $content ): array {
	$map = static function ( array $blocks ) use ( &$map ): array {
		$out = array();
		foreach ( $blocks as $b ) {
			if ( empty( $b['blockName'] ) ) {
				continue;
			}
			$out[] = array(
				'n' => $b['blockName'],
				'l' => $b['attrs']['templateLock'] ?? null,
				'c' => $b['attrs']['className'] ?? null,
				'i' => $map( $b['innerBlocks'] ?? array() ),
			);
		}
		return $out;
	};
	return $map( parse_blocks( $content ) );
}

/**
 * Harte Garantie (serverseitig, nicht per Browser umgehbar): Kunden dürfen die Struktur einer Seite nicht ändern.
 * Mit „Abschnitte verwalten“ (Entscheidung #09) dürfen Abschnitte entfallen, umsortiert oder aus der Bibliothek
 * ergänzt werden – das Innere jedes Abschnitts muss aber unverändert bzw. ein Bibliotheks-Pattern sein.
 */
add_filter(
	'rest_pre_insert_page',
	static function ( $prepared, WP_REST_Request $request ) {
		if ( ! hypetech_is_kunde() || ! isset( $request['content'] ) || empty( $prepared->ID ) ) {
			return $prepared;
		}
		$alt = get_post( $prepared->ID );
		if ( ! $alt ) {
			return $prepared;
		}
		$vorher  = hypetech_block_struktur( $alt->post_content );
		$nachher = hypetech_block_struktur( (string) $prepared->post_content );
		if ( $vorher === $nachher ) {
			return $prepared;
		}
		if ( hypetech_kunde_darf_abschnitte() ) {
			$erlaubt = array_map( 'wp_json_encode', $vorher );
			foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
				foreach ( hypetech_block_struktur( $pattern['content'] ) as $abschnitt ) {
					$erlaubt[] = wp_json_encode( $abschnitt );
				}
			}
			$ok = true;
			foreach ( $nachher as $abschnitt ) {
				$ok = $ok && 'contentOnly' === $abschnitt['l'] && in_array( wp_json_encode( $abschnitt ), $erlaubt, true );
			}
			if ( $ok ) {
				return $prepared;
			}
		}
		return new WP_Error(
			'hypetech_struktur_gesperrt',
			'Das Layout dieser Seite ist geschützt. Sie können Texte und Bilder ändern, aber keine Abschnitte oder Blöcke hinzufügen, entfernen oder umbauen. Bitte laden Sie die Seite neu – für Layout-Änderungen melden Sie sich gern bei uns.',
			array( 'status' => 403 )
		);
	},
	10,
	2
);

/**
 * Kunden können veröffentlichte Seiten aktualisieren, aber nie offline nehmen (Entwurf/Privat/Ausstehend/Papierkorb).
 */
add_filter(
	'wp_insert_post_data',
	static function ( array $data, array $postarr ) {
		if ( 'page' !== ( $data['post_type'] ?? '' ) || empty( $postarr['ID'] ) || ! hypetech_is_kunde() ) {
			return $data;
		}
		$alt = get_post( (int) $postarr['ID'] );
		if ( $alt && 'publish' === $alt->post_status ) {
			$data['post_status']   = 'publish';
			$data['post_password'] = '';
		}
		return $data;
	},
	20,
	2
);
