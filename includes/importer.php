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
		'support_kontakt'       => 'hypetech_support_kontakt',
	);
	foreach ( $map as $key => $option ) {
		if ( array_key_exists( $key, $opt ) ) {
			update_option( $option, is_bool( $opt[ $key ] ) ? (int) $opt[ $key ] : sanitize_text_field( (string) $opt[ $key ] ) );
		}
	}
	update_option( 'timezone_string', 'Europe/Berlin' );
	update_option( 'date_format', 'j. F Y' );
	update_option( 'time_format', 'H:i' );
	update_option( 'start_of_week', 1 );
	update_option( 'uploads_use_yearmonth_folders', 0 );
	update_option( 'default_comment_status', 'closed' );
	update_option( 'default_ping_status', 'closed' );
	if ( '/%postname%/' !== get_option( 'permalink_structure' ) ) {
		update_option( 'permalink_structure', '/%postname%/' );
		flush_rewrite_rules( false );
	}
	$log[] = 'Optionen gesetzt (Titel, Formular-Empfänger, Permalinks, Zeitzone).';

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

	// ---------------------------------------------------------- Seiten
	$page_ids = array();
	foreach ( (array) ( $site['seiten'] ?? array() ) as $seite ) {
		$slug = sanitize_title( $seite['slug'] ?? '' );
		$path = $root . '/content/pages/' . basename( (string) ( $seite['datei'] ?? '' ) );
		if ( ! $slug || ! is_readable( $path ) ) {
			$log[] = "FEHLER Seite {$slug}: Datei fehlt ({$path}).";
			continue;
		}
		$content = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$content = preg_replace_callback(
			'/\{\{bild(-id)?:([a-z0-9_-]+)[^}]*\}\}/',
			static function ( $m ) use ( $ids, $urls, &$log ) {
				$key = $m[2];
				if ( ! isset( $ids[ $key ] ) ) {
					$log[] = "WARNUNG: Bild „{$key}“ nicht importiert.";
					return $m[1] ? '0' : '';
				}
				return $m[1] ? (string) $ids[ $key ] : esc_url( $urls[ $key ] );
			},
			$content
		);

		$existing = get_page_by_path( $slug, OBJECT, 'page' );
		$hash     = md5( $content );
		$data     = array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => wp_strip_all_tags( (string) ( $seite['titel'] ?? $slug ) ),
			'post_name'    => $slug,
			'post_content' => $content,
			'menu_order'   => (int) ( $seite['reihenfolge'] ?? 0 ),
		);

		if ( $existing ) {
			$page_ids[ $slug ] = $existing->ID;
			$import_time       = (int) get_post_meta( $existing->ID, '_hypetech_import_zeit', true );
			$kunde_geaendert   = $import_time && strtotime( $existing->post_modified_gmt . ' UTC' ) > $import_time + 5;
			if ( 'neu' === $modus ) {
				$log[] = "Seite {$slug}: existiert – übersprungen (Modus neu).";
				continue;
			}
			if ( 'alle' === $modus && $kunde_geaendert ) {
				$log[] = "Seite {$slug}: im CMS geändert – NICHT überschrieben (Modus erzwingen nötig).";
				continue;
			}
			if ( get_post_meta( $existing->ID, '_hypetech_import_hash', true ) === $hash ) {
				$log[] = "Seite {$slug}: unverändert.";
				continue;
			}
			$data['ID'] = $existing->ID;
			$id         = wp_update_post( wp_slash( $data ), true );
			$status     = 'aktualisiert';
		} else {
			$id     = wp_insert_post( wp_slash( $data ), true );
			$status = 'angelegt';
		}
		if ( is_wp_error( $id ) ) {
			$log[] = "FEHLER Seite {$slug}: " . $id->get_error_message();
			continue;
		}
		$page_ids[ $slug ] = $id;
		update_post_meta( $id, '_hypetech_import_hash', $hash );
		update_post_meta( $id, '_hypetech_import_zeit', time() );
		$log[] = "Seite {$slug}: {$status} (ID {$id}).";
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

	kses_init_filters();
	return $log;
}

/**
 * Ein Bild importieren: Datei aus briefing/assets/bilder oder generierter Platzhalter.
 * Gleiche Quelle (Hash) → vorhandenes Attachment wiederverwenden.
 *
 * @return array{id:int,status:string}|WP_Error
 */
function hypetech_import_bild( string $root, string $name, array $def ) {
	$source = null;
	foreach ( array( 'jpg', 'jpeg', 'png', 'webp' ) as $ext ) {
		$candidate = "{$root}/briefing/assets/bilder/{$name}.{$ext}";
		if ( is_readable( $candidate ) ) {
			$source = $candidate;
			break;
		}
	}
	[ $w, $h ] = array_map( 'intval', explode( 'x', (string) ( $def['groesse'] ?? '1600x1000' ) ) + array( 1600, 1000 ) );
	$motiv     = (string) ( $def['motiv'] ?? $name );
	$hash      = $source ? md5_file( $source ) : md5( "platzhalter:{$w}x{$h}:{$motiv}:" . ( $def['ton'] ?? '' ) );

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
	$scale = max( 1, (int) floor( $w / 900 ) + 1 );
	$sw    = imagesx( $small ) * $scale;
	$sh    = imagesy( $small ) * $scale;
	imagecopyresized( $img, $small, (int) ( ( $w - $sw ) / 2 ), (int) ( ( $h - $sh ) / 2 ), 0, 0, $sw, $sh, imagesx( $small ), imagesy( $small ) );
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
