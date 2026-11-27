<?php
/**
 * Inhalts-Import (Entscheidung T3/T4): content/site.json + content/pages/*.html + briefing/assets/bilder → WordPress.
 * Ein Importer für alle Wege:
 *   - lokal/SSH:  wp eval-file <projekt>/bin/import-content.php [neu|alle|erzwingen]
 *   - ohne SSH:   Werkzeuge → HYPE:TECH Import (ZIP aus `npm run zip` hochladen)
 *
 * Modi:
 *   neu        – nur fehlende Seiten anlegen (Standard auf Live-Seiten: Kundenänderungen bleiben unangetastet)
 *   alle       – alle Seiten aus dem Repo schreiben, AUSSER der Kunde hat sie seit dem letzten Import geändert
 *   erzwingen  – alles überschreiben (nur lokal / vor Livegang)
 *
 * @package HypetechCore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Führt den Import aus. Gibt Protokollzeilen zurück.
 */
function hypetech_import( string $root, string $modus = 'neu' ): array {
	$log  = array();
	$root = untrailingslashit( $root );
	$file = $root . '/content/site.json';
	if ( ! is_readable( $file ) ) {
		return array( "FEHLER: {$file} nicht gefunden." );
	}
	$site = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! is_array( $site ) ) {
		return array( 'FEHLER: content/site.json ist kein gültiges JSON.' );
	}
	if ( ! in_array( $modus, array( 'neu', 'alle', 'erzwingen' ), true ) ) {
		return array( "FEHLER: unbekannter Modus „{$modus}“ (neu|alle|erzwingen)." );
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	// Block-Markup unverändert speichern (kses würde Attribute entfernen).
	kses_remove_filters();

	// ---------------------------------------------------------- Optionen
	$opt = $site['optionen'] ?? array();
	$map = array(
		'titel'                 => 'blogname',
		'untertitel'            => 'blogdescription',
		'kontakt_empfaenger'    => 'hypetech_contact_recipient',
		'kunde_darf_abschnitte' => 'hypetech_kunde_darf_abschnitte',
		'kunde_darf_beitraege'  => 'hypetech_kunde_darf_beitraege',
		'support_kontakt'       => 'hypetech_support_kontakt',
	);
	foreach ( $map as $key => $option ) {
		if ( array_key_exists( $key, $opt ) ) {
			update_option( $option, is_bool( $opt[ $key ] ) ? (int) $opt[ $key ] : sanitize_text_field( (string) $opt[ $key ] ) );
		}
	}
	// Sprache der Website (Entscheidung #23): Standard Deutsch, international z. B. "en_US".
	$sprache = preg_replace( '/[^A-Za-z_]/', '', (string) ( $opt['sprache'] ?? 'de_DE' ) );
	update_option( 'WPLANG', 'en_US' === $sprache ? '' : $sprache );
	$deutsch = str_starts_with( $sprache, 'de' );
	update_option( 'timezone_string', sanitize_text_field( (string) ( $opt['zeitzone'] ?? 'Europe/Berlin' ) ) );
	update_option( 'date_format', $deutsch ? 'j. F Y' : 'F j, Y' );
	update_option( 'time_format', $deutsch ? 'H:i' : 'g:i a' );
	update_option( 'start_of_week', 1 );
	update_option( 'uploads_use_yearmonth_folders', 0 );
	update_option( 'default_comment_status', 'closed' );
	update_option( 'default_ping_status', 'closed' );
	// Permalinks: Standard /%postname%/; mit Blog z. B. "/projects/%postname%/" + Kategorie-Basis.
	$permalinks = (string) ( $opt['permalinks'] ?? '/%postname%/' );
	$permalinks = preg_match( '#^/[a-z0-9/_%-]*%postname%/$#', $permalinks ) ? $permalinks : '/%postname%/';
	$kat_basis  = sanitize_text_field( (string) ( $opt['kategorie_basis'] ?? '' ) );
	global $wp_rewrite;
	$wp_rewrite->set_permalink_structure( $permalinks );
	$wp_rewrite->set_category_base( $kat_basis );
	// Regeln werden am Ende des Imports neu geschrieben (nach Sprachen und Taxonomien).
	// SEO-Grunddaten (Organisation, Standard-Bild …) für includes/seo.php.
	if ( isset( $site['seo'] ) && is_array( $site['seo'] ) ) {
		update_option( 'hypetech_seo', hypetech_import_text_tief( $site['seo'] ), false );
	}
	// Einwilligung/Cookie-Banner (Entscheidung #25) für includes/consent.php. Fehlt der Eintrag → aus.
	$einwilligung = isset( $site['einwilligung'] ) && is_array( $site['einwilligung'] ) ? hypetech_import_text_tief( $site['einwilligung'] ) : array( 'aktiv' => false );
	update_option( 'hypetech_einwilligung', $einwilligung );
	if ( ! empty( $einwilligung['aktiv'] ) ) {
		$log[] = 'Einwilligung aktiv: ' . implode( ', ', array_map( static fn( $d ) => is_array( $d ) ? ( $d['id'] ?? '?' ) : $d, (array) ( $einwilligung['dienste'] ?? array() ) ) ) . '.';
	}
	$log[] = "Optionen gesetzt (Titel, Sprache {$sprache}, Formular-Empfänger, Permalinks {$permalinks}, Zeitzone).";

	// ---------------------------------------------------------- Sprachen (Polylang, Entscheidung #24)
	$mehrsprachig = function_exists( 'PLL' ) && function_exists( 'pll_set_post_language' ) && ! empty( $site['sprachen'] );
	if ( $mehrsprachig ) {
		$log = array_merge( $log, hypetech_import_sprachen( (array) $site['sprachen'] ) );
	} elseif ( ! empty( $site['sprachen'] ) ) {
		$log[] = 'WARNUNG: site.json → sprachen gesetzt, aber Polylang ist nicht aktiv (site.json → plugins).';
	}
	$gruppen = array(
		'post' => array(),
		'term' => array(),
	);
	$merke   = static function ( string $art, array $e, int $id ) use ( &$gruppen, $mehrsprachig ): void {
		if ( $mehrsprachig && $id && ! empty( $e['gruppe'] ) && ! empty( $e['sprache'] ) ) {
			$gruppen[ $art ][ sanitize_key( (string) $e['gruppe'] ) ][ sanitize_key( (string) $e['sprache'] ) ] = $id;
		}
	};

	// ---------------------------------------------------------- Bilder
	$ids  = array();
	$urls = array();
	foreach ( (array) ( $site['bilder'] ?? array() ) as $name => $def ) {
		$name   = sanitize_key( $name );
		$result = hypetech_import_bild( $root, $name, (array) $def );
		if ( is_wp_error( $result ) ) {
			$log[] = "FEHLER Bild {$name}: " . $result->get_error_message();
			continue;
		}
		$ids[ $name ]  = $result['id'];
		$urls[ $name ] = wp_get_attachment_url( $result['id'] );
		$log[]         = sprintf( 'Bild %-18s %s (ID %d)', $name, $result['status'], $result['id'] );
	}

	// ---------------------------------------------------------- Medien (Video, nur echte Dateien aus briefing/assets/medien)
	foreach ( (array) ( $site['medien'] ?? array() ) as $name => $def ) {
		$name   = sanitize_key( $name );
		$result = hypetech_import_bild( $root, $name, (array) $def, 'medien' );
		if ( is_wp_error( $result ) ) {
			$log[] = "FEHLER Medium {$name}: " . $result->get_error_message();
			continue;
		}
		$ids[ 'medien:' . $name ]  = $result['id'];
		$urls[ 'medien:' . $name ] = wp_get_attachment_url( $result['id'] );
		$log[]                     = sprintf( 'Medium %-16s %s (ID %d)', $name, $result['status'], $result['id'] );
	}

	// ---------------------------------------------------------- Seiten
	$platzhalter = static function ( string $content ) use ( $ids, $urls, &$log ): string {
		return (string) preg_replace_callback(
			'/\{\{(bild|bild-id|medien):([a-z0-9_-]+)[^}]*\}\}/',
			static function ( $m ) use ( $ids, $urls, &$log ) {
				$key = 'medien' === $m[1] ? 'medien:' . $m[2] : $m[2];
				if ( ! isset( $ids[ $key ] ) ) {
					$log[] = "WARNUNG: „{$key}“ nicht importiert.";
					return 'bild-id' === $m[1] ? '0' : '';
				}
				return 'bild-id' === $m[1] ? (string) $ids[ $key ] : esc_url( $urls[ $key ] );
			},
			$content
		);
	};

	$page_ids = array();
	foreach ( (array) ( $site['seiten'] ?? array() ) as $seite ) {
		$slug = sanitize_title( $seite['slug'] ?? '' );
		$path = $root . '/content/pages/' . basename( (string) ( $seite['datei'] ?? '' ) );
		if ( ! $slug || ! is_readable( $path ) ) {
			$log[] = "FEHLER Seite {$slug}: Datei fehlt ({$path}).";
			continue;
		}
		$id = hypetech_import_eintrag( 'page', (array) $seite, $platzhalter( (string) file_get_contents( $path ) ), $modus, $ids, $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( $id ) {
			$page_ids[ $slug ] = $id;
			$merke( 'post', (array) $seite, $id );
		}
	}

	// ---------------------------------------------------------- Kategorien & Beiträge (Blog, Entscheidung #21)
	foreach ( (array) ( $site['kategorien'] ?? array() ) as $kat ) {
		$slug = sanitize_title( $kat['slug'] ?? '' );
		if ( ! $slug ) {
			continue;
		}
		$args = array(
			'slug'        => $slug,
			'description' => sanitize_textarea_field( (string) ( $kat['beschreibung'] ?? '' ) ),
		);
		$term = get_term_by( 'slug', $slug, 'category' );
		$res  = $term
			? wp_update_term( $term->term_id, 'category', $args + array( 'name' => sanitize_text_field( (string) ( $kat['name'] ?? $slug ) ) ) )
			: wp_insert_term( sanitize_text_field( (string) ( $kat['name'] ?? $slug ) ), 'category', $args );
		if ( ! is_wp_error( $res ) && $mehrsprachig && ! empty( $kat['sprache'] ) ) {
			pll_set_term_language( (int) $res['term_id'], sanitize_key( (string) $kat['sprache'] ) );
			$merke( 'term', (array) $kat, (int) $res['term_id'] );
		}
		$log[] = is_wp_error( $res ) ? "FEHLER Kategorie {$slug}: " . $res->get_error_message() : "Kategorie {$slug}: " . ( $term ? 'aktualisiert' : 'angelegt' ) . '.';
	}
	// Leere Standardkategorie nicht öffentlich zeigen, wenn eigene Kategorien definiert sind.
	if ( ! empty( $site['kategorien'] ) ) {
		$erste = get_term_by( 'slug', sanitize_title( $site['kategorien'][0]['slug'] ?? '' ), 'category' );
		if ( $erste ) {
			update_option( 'default_category', $erste->term_id );
		}
		// WordPress-/Polylang-Standardkategorien („Uncategorized“, „uncategorized-de“, „Allgemein“) entfernen, wenn leer.
		foreach ( get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'lang' => '' ) ) as $unkat ) {
			if ( preg_match( '/^(uncategorized|allgemein|unkategorisiert)(-[a-z]{2})?$/', $unkat->slug ) && 0 === (int) $unkat->count && ( ! $erste || $erste->term_id !== $unkat->term_id ) ) {
				wp_delete_term( $unkat->term_id, 'category' );
			}
		}
	}
	foreach ( (array) ( $site['beitraege'] ?? array() ) as $beitrag ) {
		$slug = sanitize_title( $beitrag['slug'] ?? '' );
		$path = $root . '/content/beitraege/' . basename( (string) ( $beitrag['datei'] ?? '' ) );
		if ( ! $slug || ! is_readable( $path ) ) {
			$log[] = "FEHLER Beitrag {$slug}: Datei fehlt ({$path}).";
			continue;
		}
		$merke( 'post', (array) $beitrag, hypetech_import_eintrag( 'post', (array) $beitrag, $platzhalter( (string) file_get_contents( $path ) ), $modus, $ids, $log ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	// Übersetzungen verknüpfen (Polylang): gleiche „gruppe“ = dieselbe Seite in mehreren Sprachen.
	foreach ( $gruppen['post'] as $g => $paar ) {
		if ( count( $paar ) > 1 ) {
			pll_save_post_translations( $paar );
			$log[] = "Übersetzungen verknüpft: {$g} (" . implode( ', ', array_keys( $paar ) ) . ').';
		}
	}
	foreach ( $gruppen['term'] as $paar ) {
		if ( count( $paar ) > 1 ) {
			pll_save_term_translations( $paar );
		}
	}
	$blog = sanitize_title( (string) ( $site['beitragsseite'] ?? '' ) );
	if ( $blog && isset( $page_ids[ $blog ] ) ) {
		update_option( 'page_for_posts', $page_ids[ $blog ] );
		update_option( 'posts_per_page', max( 1, (int) ( $opt['beitraege_pro_seite'] ?? 12 ) ) );
		$log[] = "Beitragsseite: {$blog}.";
	}

	// ---------------------------------------------------------- Startseite & Aufräumen
	$start = $site['startseite'] ?? '';
	if ( $start && isset( $page_ids[ $start ] ) ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_ids[ $start ] );
		$log[] = "Startseite: {$start}.";
	}
	foreach ( array( array( 'sample-page', 'page' ), array( 'hello-world', 'post' ), array( 'beispiel-seite', 'page' ), array( 'hallo-welt', 'post' ) ) as [ $slug, $type ] ) {
		$p = get_page_by_path( $slug, OBJECT, $type );
		if ( $p && ! isset( $page_ids[ $slug ] ) ) {
			wp_delete_post( $p->ID, true );
			$log[] = "WordPress-Beispielinhalt „{$slug}“ gelöscht.";
		}
	}
	$privacy = get_page_by_path( 'datenschutzerklaerung', OBJECT, 'page' );
	if ( $privacy && 'draft' === $privacy->post_status ) {
		wp_delete_post( $privacy->ID, true );
	}
	if ( isset( $page_ids['datenschutz'] ) ) {
		update_option( 'wp_page_for_privacy_policy', $page_ids['datenschutz'] );
	}
	if ( isset( $page_ids['impressum'] ) ) {
		update_option( 'hypetech_impressum_seite', $page_ids['impressum'] ); // Link im Cookie-Banner
	}

	// Kategorie-Basis wirkt erst nach erneutem Registrieren der Standard-Taxonomien; danach Regeln neu schreiben.
	create_initial_taxonomies();
	if ( function_exists( 'PLL' ) && isset( PLL()->model ) ) {
		PLL()->model->clean_languages_cache(); // Startseite/Beitragsseite je Sprache neu ermitteln
	}
	flush_rewrite_rules( false );

	kses_init_filters();
	return $log;
}

/**
 * Eine Seite oder einen Beitrag anlegen/aktualisieren (gleiche Modus-Regeln für beide).
 * Felder: slug, titel, reihenfolge | datum, auszug, kategorien[], terme{taxonomie:[slugs]}, bild (Beitragsbild),
 * SEO: beschreibung, seo_titel, seo_bild (Bildname), noindex.
 *
 * @return int Post-ID oder 0.
 */
function hypetech_import_eintrag( string $typ, array $e, string $content, string $modus, array $ids, array &$log ): int {
	$slug     = sanitize_title( $e['slug'] ?? '' );
	$name     = 'page' === $typ ? 'Seite' : 'Beitrag';
	$existing = get_page_by_path( $slug, OBJECT, $typ );
	$hash     = md5( $content . wp_json_encode( $e ) . ( $ids[ sanitize_key( (string) ( $e['bild'] ?? '' ) ) ] ?? '' ) . ( $ids[ sanitize_key( (string) ( $e['seo_bild'] ?? '' ) ) ] ?? '' ) ); // inkl. Bild-IDs: neues Bild = neu schreiben
	$data     = array(
		'post_type'    => $typ,
		'post_status'  => in_array( $e['status'] ?? '', array( 'draft', 'private' ), true ) ? $e['status'] : 'publish',
		'post_title'   => wp_strip_all_tags( (string) ( $e['titel'] ?? $slug ) ),
		'post_name'    => $slug,
		'post_content' => $content,
		'menu_order'   => (int) ( $e['reihenfolge'] ?? 0 ),
		'post_excerpt' => sanitize_textarea_field( (string) ( $e['auszug'] ?? '' ) ),
	);
	if ( ! empty( $e['datum'] ) && strtotime( (string) $e['datum'] ) ) {
		$data['post_date'] = gmdate( 'Y-m-d H:i:s', (int) strtotime( (string) $e['datum'] ) );
	}

	if ( $existing ) {
		$import_time     = (int) get_post_meta( $existing->ID, '_hypetech_import_zeit', true );
		$kunde_geaendert = $import_time && strtotime( $existing->post_modified_gmt . ' UTC' ) > $import_time + 5;
		if ( 'neu' === $modus ) {
			$log[] = "{$name} {$slug}: existiert – übersprungen (Modus neu).";
			return $existing->ID;
		}
		if ( 'alle' === $modus && $kunde_geaendert ) {
			$log[] = "{$name} {$slug}: im CMS geändert – NICHT überschrieben (Modus erzwingen nötig).";
			return $existing->ID;
		}
		if ( get_post_meta( $existing->ID, '_hypetech_import_hash', true ) === $hash ) {
			$log[] = "{$name} {$slug}: unverändert.";
			return $existing->ID;
		}
		$data['ID'] = $existing->ID;
		$id         = wp_update_post( wp_slash( $data ), true );
		$status     = 'aktualisiert';
	} else {
		$id     = wp_insert_post( wp_slash( $data ), true );
		$status = 'angelegt';
	}
	if ( is_wp_error( $id ) ) {
		$log[] = "FEHLER {$name} {$slug}: " . $id->get_error_message();
		return 0;
	}

	if ( 'post' === $typ ) {
		$kats = array();
		foreach ( (array) ( $e['kategorien'] ?? array() ) as $k ) {
			$term = get_term_by( 'slug', sanitize_title( (string) $k ), 'category' );
			if ( $term ) {
				$kats[] = (int) $term->term_id;
			}
		}
		if ( $kats ) {
			wp_set_post_categories( $id, $kats );
		}
	}
	foreach ( (array) ( $e['terme'] ?? array() ) as $tax => $slugs ) {
		if ( taxonomy_exists( (string) $tax ) ) {
			wp_set_object_terms( $id, array_map( 'sanitize_text_field', (array) $slugs ), (string) $tax ); // Slug oder Name; fehlende Begriffe werden mit diesem Namen angelegt.
		}
	}
	$bild = sanitize_key( (string) ( $e['bild'] ?? '' ) );
	if ( $bild && isset( $ids[ $bild ] ) ) {
		set_post_thumbnail( $id, $ids[ $bild ] );
	}
	$seo_bild = sanitize_key( (string) ( $e['seo_bild'] ?? '' ) );
	$meta     = array(
		'_hypetech_seo_beschreibung' => sanitize_textarea_field( (string) ( $e['beschreibung'] ?? '' ) ),
		'_hypetech_seo_titel'        => sanitize_text_field( (string) ( $e['seo_titel'] ?? '' ) ),
		'_hypetech_seo_bild'         => $seo_bild && isset( $ids[ $seo_bild ] ) ? (int) $ids[ $seo_bild ] : 0,
		'_hypetech_seo_noindex'      => empty( $e['noindex'] ) ? 0 : 1,
		'_hypetech_seo_typ'          => sanitize_key( (string) ( $e['seo_typ'] ?? '' ) ),
	);
	foreach ( $meta as $k => $v ) {
		if ( $v ) {
			update_post_meta( $id, $k, $v );
		} else {
			delete_post_meta( $id, $k );
		}
	}
	if ( ! empty( $e['sprache'] ) && function_exists( 'pll_set_post_language' ) ) {
		pll_set_post_language( $id, sanitize_key( (string) $e['sprache'] ) );
	}
	update_post_meta( $id, '_hypetech_import_hash', $hash );
	update_post_meta( $id, '_hypetech_import_zeit', time() );
	$log[] = "{$name} {$slug}: {$status} (ID {$id}).";
	return (int) $id;
}

/**
 * Sprachen in Polylang anlegen (site.json → sprachen: [{locale, slug, name?, flag?, standard?}]) und Sprachpakete laden.
 * Polylang-Grundeinstellungen: Sprache im Pfad (/de/), Standardsprache ohne Präfix, keine Browser-Weiterleitung (SEO).
 */
function hypetech_import_sprachen( array $sprachen ): array {
	$log = array();
	require_once ABSPATH . 'wp-admin/includes/translation-install.php';
	$standard = '';
	foreach ( array_values( $sprachen ) as $i => $s ) {
		$locale = preg_replace( '/[^A-Za-z_]/', '', (string) ( $s['locale'] ?? '' ) );
		$slug   = sanitize_key( (string) ( $s['slug'] ?? '' ) );
		if ( ! $locale || ! $slug ) {
			continue;
		}
		if ( ! empty( $s['standard'] ) || ! $standard ) {
			$standard = ! empty( $s['standard'] ) ? $slug : ( $standard ?: $slug );
		}
		if ( 'en_US' !== $locale && ! in_array( $locale, get_available_languages(), true ) ) {
			$paket = wp_download_language_pack( $locale );
			$log[] = $paket ? "Sprachpaket {$locale} installiert." : "WARNUNG: Sprachpaket {$locale} nicht ladbar.";
		}
		if ( PLL()->model->get_language( $slug ) ) {
			continue;
		}
		$args = array_filter(
			array(
				'locale'         => $locale,
				'slug'           => $slug,
				'name'           => isset( $s['name'] ) ? sanitize_text_field( (string) $s['name'] ) : null,
				'flag'           => isset( $s['flag'] ) ? sanitize_key( (string) $s['flag'] ) : null,
				'term_group'     => $i,
				'no_default_cat' => true,
			),
			static fn( $v ) => null !== $v
		);
		$res   = isset( PLL()->model->languages ) ? PLL()->model->languages->add( $args ) : PLL()->model->add_language( $args );
		$log[] = is_wp_error( $res ) ? "FEHLER Sprache {$slug}: " . $res->get_error_message() : "Sprache {$slug} ({$locale}) angelegt.";
	}
	if ( $standard ) {
		$o = PLL()->options;
		foreach ( array( 'default_lang' => $standard, 'force_lang' => 1, 'hide_default' => true, 'rewrite' => true, 'browser' => false, 'redirect_lang' => true, 'media_support' => false ) as $k => $v ) {
			$o->set( $k, $v );
		}
		$o->save();
		PLL()->model->clean_languages_cache();
		$log[] = "Polylang: Standardsprache {$standard}, weitere Sprachen unter /<code>/ (Startseite = /<code>/).";
	}
	return $log;
}

/**
 * Verschachtelte Angaben (site.json → seo) als reinen Text übernehmen.
 */
function hypetech_import_text_tief( array $daten ): array {
	$out = array();
	foreach ( $daten as $k => $v ) {
		$k         = is_int( $k ) ? $k : sanitize_key( $k );
		$out[ $k ] = is_array( $v ) ? hypetech_import_text_tief( $v ) : ( is_bool( $v ) ? $v : sanitize_text_field( (string) $v ) );
	}
	return $out;
}

/**
 * Ein Bild importieren: Datei aus briefing/assets/bilder oder generierter Platzhalter.
 * Gleiche Quelle (Hash) → vorhandenes Attachment wiederverwenden.
 *
 * @return array{id:int,status:string}|WP_Error
 */
function hypetech_import_bild( string $root, string $name, array $def, string $ordner = 'bilder' ) {
	$source = null;
	$endung = 'medien' === $ordner ? array( 'mp4', 'webm' ) : array( 'jpg', 'jpeg', 'png', 'webp' );
	foreach ( $endung as $ext ) {
		$candidate = "{$root}/briefing/assets/{$ordner}/{$name}.{$ext}";
		if ( is_readable( $candidate ) ) {
			$source = $candidate;
			break;
		}
	}
	[ $w, $h ] = array_map( 'intval', explode( 'x', (string) ( $def['groesse'] ?? '1600x1000' ) ) + array( 1600, 1000 ) );
	$motiv     = (string) ( $def['motiv'] ?? $name );
	$hash      = $source ? md5_file( $source ) : md5( "platzhalter:v2:{$w}x{$h}:{$motiv}:" . ( $def['ton'] ?? '' ) );

	$found = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array( 'key' => '_hypetech_bild', 'value' => $name ),
				array( 'key' => '_hypetech_bild_hash', 'value' => $hash ),
			),
		)
	);
	if ( $found ) {
		update_post_meta( $found[0], '_wp_attachment_image_alt', sanitize_text_field( (string) ( $def['alt'] ?? '' ) ) );
		return array( 'id' => (int) $found[0], 'status' => 'vorhanden' );
	}

	if ( ! $source && 'medien' === $ordner ) {
		return new WP_Error( 'medium', "Datei briefing/assets/medien/{$name}.(mp4|webm) fehlt." );
	}

	$tmp = wp_tempnam( $name );
	if ( $source ) {
		copy( $source, $tmp );
		$filename = $name . '.' . pathinfo( $source, PATHINFO_EXTENSION );
	} else {
		$ok = hypetech_platzhalter_bild( $tmp, $w, $h, $motiv, (string) ( $def['ton'] ?? '' ) );
		if ( ! $ok ) {
			return new WP_Error( 'gd', 'Platzhalter konnte nicht erzeugt werden (GD fehlt?).' );
		}
		$filename = $name . '.jpg';
	}

	$id = media_handle_sideload( array( 'name' => $filename, 'tmp_name' => $tmp ), 0, $def['titel'] ?? ucfirst( $name ) );
	if ( is_wp_error( $id ) ) {
		wp_delete_file( $tmp );
		return $id;
	}
	update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) ( $def['alt'] ?? '' ) ) );
	update_post_meta( $id, '_hypetech_bild', $name );
	update_post_meta( $id, '_hypetech_bild_hash', $hash );
	return array( 'id' => (int) $id, 'status' => $source ? 'importiert' : 'Platzhalter erzeugt' );
}

/**
 * Platzhalterbild (JPEG) mit Verlauf in den Theme-Farben und Beschriftung erzeugen.
 */
function hypetech_platzhalter_bild( string $target, int $w, int $h, string $motiv, string $ton = '' ): bool {
	if ( ! function_exists( 'imagecreatetruecolor' ) ) {
		return false;
	}
	$palette = array();
	foreach ( wp_get_global_settings( array( 'color', 'palette', 'theme' ) ) ?: array() as $c ) {
		$palette[ $c['slug'] ] = $c['color'];
	}
	$hex2rgb = static function ( string $hex ): array {
		$hex = ltrim( $hex, '#' );
		return array_map( 'hexdec', str_split( strlen( $hex ) === 3 ? $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2] : substr( $hex, 0, 6 ), 2 ) );
	};
	$von = $hex2rgb( $palette[ $ton ?: 'primary' ] ?? '#33475b' );
	$bis = $hex2rgb( $palette['contrast'] ?? '#111111' );

	$img = imagecreatetruecolor( $w, $h );
	for ( $y = 0; $y < $h; $y++ ) {
		$t   = $y / max( 1, $h - 1 );
		$col = imagecolorallocate( $img, (int) ( $von[0] + ( $bis[0] - $von[0] ) * $t * 0.7 ), (int) ( $von[1] + ( $bis[1] - $von[1] ) * $t * 0.7 ), (int) ( $von[2] + ( $bis[2] - $von[2] ) * $t * 0.7 ) );
		imageline( $img, 0, $y, $w, $y, $col );
	}
	// Dezente Diagonalen als Struktur.
	$linie = imagecolorallocatealpha( $img, 255, 255, 255, 118 );
	for ( $x = -$h; $x < $w; $x += 46 ) {
		imageline( $img, $x, $h, $x + $h, 0, $linie );
	}
	// Beschriftung: „Platzhalter · Motiv“ (eingebaute Schrift, vergrößert).
	$text  = 'PLATZHALTER: ' . strtoupper( remove_accents( $motiv ) );
	$text  = mb_strimwidth( $text, 0, 60, '...' );
	$small = imagecreatetruecolor( imagefontwidth( 5 ) * strlen( $text ) + 24, imagefontheight( 5 ) + 14 );
	imagefill( $small, 0, 0, imagecolorallocate( $small, 20, 20, 20 ) );
	imagestring( $small, 5, 12, 7, $text, imagecolorallocate( $small, 255, 255, 255 ) );
	// Oben rechts platzieren (Texte in Hero/Cover stehen unten links) und nie breiter als 60 % des Bildes.
	$scale  = max( 1, (int) floor( $w / 1000 ) );
	$sw     = imagesx( $small ) * $scale;
	$sh     = imagesy( $small ) * $scale;
	if ( $sw > $w * 0.6 ) {
		$sh = (int) ( $sh * ( $w * 0.6 ) / $sw );
		$sw = (int) ( $w * 0.6 );
	}
	$margin = (int) max( 16, $w * 0.025 );
	imagecopyresampled( $img, $small, $w - $sw - $margin, $margin, 0, 0, $sw, $sh, imagesx( $small ), imagesy( $small ) );
	imagedestroy( $small );

	$ok = imagejpeg( $img, $target, 82 );
	imagedestroy( $img );
	return $ok;
}

/**
 * Admin-Seite für den ZIP-Import (Hostings ohne SSH, Entscheidung #03).
 */
add_action(
	'admin_menu',
	static function (): void {
		add_management_page( 'HYPE:TECH Import', 'HYPE:TECH Import', 'manage_options', 'hypetech-import', 'hypetech_import_page' );
	}
);

function hypetech_import_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$log = array();
	if ( isset( $_POST['hypetech_import'] ) && check_admin_referer( 'hypetech_import' ) && ! empty( $_FILES['paket']['tmp_name'] ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		$dir = trailingslashit( get_temp_dir() ) . 'hypetech-import-' . wp_generate_password( 8, false );
		$res = unzip_file( sanitize_text_field( wp_unslash( $_FILES['paket']['tmp_name'] ) ), $dir );
		if ( is_wp_error( $res ) ) {
			$log[] = 'FEHLER: ' . $res->get_error_message();
		} else {
			$root  = is_dir( "{$dir}/inhalte" ) ? "{$dir}/inhalte" : $dir;
			$modus = isset( $_POST['modus'] ) ? sanitize_key( wp_unslash( $_POST['modus'] ) ) : 'neu';
			$log   = hypetech_import( $root, $modus );
		}
		global $wp_filesystem;
		$wp_filesystem->delete( $dir, true );
	}
	?>
	<div class="wrap">
		<h1>HYPE:TECH Import</h1>
		<p>Inhalts-Paket (<code>dist/inhalte.zip</code> aus <code>npm run zip</code>) hochladen. Nur für Hostings ohne SSH – sonst <code>npm run deploy -- --import</code> verwenden.</p>
		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'hypetech_import' ); ?>
			<p><input type="file" name="paket" accept=".zip" required></p>
			<p>
				<label><input type="radio" name="modus" value="neu" checked> nur fehlende Seiten anlegen</label><br>
				<label><input type="radio" name="modus" value="alle"> alle Seiten aktualisieren (außer vom Kunden geänderte)</label><br>
				<label><input type="radio" name="modus" value="erzwingen"> alles überschreiben (nur vor Livegang!)</label>
			</p>
			<p><button class="button button-primary" name="hypetech_import" value="1">Importieren</button></p>
		</form>
		<?php if ( $log ) : ?>
			<pre style="background:#fff;padding:1em;border:1px solid #ccd0d4;max-height:60vh;overflow:auto"><?php echo esc_html( implode( "\n", $log ) ); ?></pre>
		<?php endif; ?>
	</div>
	<?php
}
