<?php
/**
 * Einwilligung (Cookie-Banner) – Entscheidung #25.
 *
 * Externe Dienste (YouTube, Vimeo, Maps, Analytics …) laden erst nach Zustimmung. Kein Fremd-Plugin,
 * keine externen Ressourcen, ein einziges notwendiges Cookie (`hypetech_einwilligung`) speichert die Wahl.
 *
 * Konfiguration: site.json → "einwilligung": { "aktiv": true, "dienste": ["youtube", { eigener Dienst }] }
 * → Option `hypetech_einwilligung` (Importer).
 *
 * Schnittstellen für Themes/Projekte:
 * - Videos: Bild-Block mit Klasse `ht-video` und Link auf YouTube/Vimeo → Zwei-Klick-Einbettung.
 * - Skripte: hypetech_einwilligung_skript( 'matomo', $src, $inline ) → läuft erst nach Zustimmung.
 * - Link „Cookie-Einstellungen“: Ziel `#einwilligung` (auch als Navigation-Link) oder hypetech_einwilligung_link().
 * - JS: window.hypetechEinwilligung.hat( 'youtube' ), .oeffnen(); Ereignis `hypetech:einwilligung` am document.
 *
 * @package HypetechCore
 */

defined( 'ABSPATH' ) || exit;

// Polylang setzt sonst das Cookie `pll_language` ohne Einwilligung – unnötig, die Sprache steht in der URL.
// Der Kern lädt vor Polylang (alphabetisch bzw. über den mu-Loader), daher greift die Konstante.
if ( ! defined( 'PLL_COOKIE' ) ) {
	define( 'PLL_COOKIE', false );
}

const HYPETECH_EINWILLIGUNG_COOKIE   = 'hypetech_einwilligung';
const HYPETECH_EINWILLIGUNG_LAUFZEIT = 365 * DAY_IN_SECONDS; // danach erneut fragen

/**
 * Vorlagen für häufige Dienste. Eigene Dienste in site.json mit denselben Feldern.
 */
function hypetech_einwilligung_vorlagen(): array {
	$google = 'Google Ireland Limited, Gordon House, Barrow Street, Dublin 4, ' . hypetech_t( 'Irland', 'Ireland' );
	return array(
		'youtube'          => array(
			'name'        => 'YouTube',
			'kategorie'   => 'medien',
			'anbieter'    => $google,
			'zweck_de'    => 'Abspielen eingebetteter Videos. Beim Laden werden Daten wie Ihre IP-Adresse an Google übertragen, auch in die USA.',
			'zweck_en'    => 'Plays embedded videos. Loading a video sends data such as your IP address to Google, including to the USA.',
			'datenschutz' => 'https://policies.google.com/privacy',
		),
		'vimeo'            => array(
			'name'        => 'Vimeo',
			'kategorie'   => 'medien',
			'anbieter'    => 'Vimeo.com, Inc., 330 West 34th Street, New York, NY 10001, USA',
			'zweck_de'    => 'Abspielen eingebetteter Videos. Beim Laden werden Daten wie Ihre IP-Adresse an Vimeo in den USA übertragen.',
			'zweck_en'    => 'Plays embedded videos. Loading a video sends data such as your IP address to Vimeo in the USA.',
			'datenschutz' => 'https://vimeo.com/privacy',
		),
		'google-maps'      => array(
			'name'        => 'Google Maps',
			'kategorie'   => 'medien',
			'anbieter'    => $google,
			'zweck_de'    => 'Anzeige interaktiver Karten. Beim Laden werden Daten wie Ihre IP-Adresse an Google übertragen, auch in die USA.',
			'zweck_en'    => 'Shows interactive maps. Loading a map sends data such as your IP address to Google, including to the USA.',
			'datenschutz' => 'https://policies.google.com/privacy',
		),
		'google-analytics' => array(
			'name'        => 'Google Analytics',
			'kategorie'   => 'statistik',
			'anbieter'    => $google,
			'zweck_de'    => 'Anonymisierte Statistik über die Nutzung der Website. Setzt Cookies und überträgt Daten an Google, auch in die USA.',
			'zweck_en'    => 'Statistics on how the website is used. Sets cookies and sends data to Google, including to the USA.',
			'datenschutz' => 'https://policies.google.com/privacy',
		),
		'matomo'           => array(
			'name'        => 'Matomo',
			'kategorie'   => 'statistik',
			'anbieter'    => '',
			'zweck_de'    => 'Statistik über die Nutzung der Website auf unserem eigenen Server. Es werden keine Daten an Dritte weitergegeben.',
			'zweck_en'    => 'Statistics on how the website is used, on our own server. No data is passed on to third parties.',
			'datenschutz' => '',
		),
	);
}

/**
 * Aufgelöste Konfiguration (Vorlagen + eigene Dienste), einmal pro Request.
 *
 * @return array{aktiv:bool,dienste:array<string,array>,version:string}
 */
function hypetech_einwilligung_konfig(): array {
	static $konfig = null;
	if ( null !== $konfig ) {
		return $konfig;
	}
	$roh      = get_option( 'hypetech_einwilligung', array() );
	$roh      = is_array( $roh ) ? $roh : array();
	$vorlagen = hypetech_einwilligung_vorlagen();
	$dienste  = array();
	foreach ( (array) ( $roh['dienste'] ?? array() ) as $d ) {
		if ( is_string( $d ) ) {
			$id = sanitize_key( $d );
			if ( isset( $vorlagen[ $id ] ) ) {
				$dienste[ $id ] = $vorlagen[ $id ];
			}
			continue;
		}
		if ( is_array( $d ) && ! empty( $d['id'] ) ) {
			$id             = sanitize_key( (string) $d['id'] );
			$dienste[ $id ] = array_merge(
				$vorlagen[ $id ] ?? array( 'name' => $id, 'kategorie' => 'medien', 'anbieter' => '', 'zweck_de' => '', 'zweck_en' => '', 'datenschutz' => '' ),
				array_filter( array_intersect_key( $d, array_flip( array( 'name', 'kategorie', 'anbieter', 'zweck_de', 'zweck_en', 'datenschutz' ) ) ), 'is_string' )
			);
		}
	}
	/**
	 * Dienste erweitern/ändern (Theme oder Projekt).
	 *
	 * @param array $dienste id => [name, kategorie, anbieter, zweck_de, zweck_en, datenschutz]
	 */
	$dienste = (array) apply_filters( 'hypetech_einwilligung_dienste', $dienste );
	$konfig  = array(
		'aktiv'   => ! empty( $roh['aktiv'] ) && ! empty( $dienste ),
		'dienste' => $dienste,
		// Neue Dienste → neue Version → Besucher werden erneut gefragt.
		'version' => substr( md5( implode( ',', array_keys( $dienste ) ) ), 0, 8 ),
	);
	return $konfig;
}

function hypetech_einwilligung_aktiv(): bool {
	return hypetech_einwilligung_konfig()['aktiv'];
}

/**
 * Hat der aktuelle Besucher dem Dienst zugestimmt? (Serverseitig, z. B. für bedingte Ausgabe.)
 * Achtung: Seiten werden am CDN gecacht – für Ausgaben im HTML lieber hypetech_einwilligung_skript() nutzen.
 */
function hypetech_einwilligung_hat( string $dienst ): bool {
	$wert = isset( $_COOKIE[ HYPETECH_EINWILLIGUNG_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ HYPETECH_EINWILLIGUNG_COOKIE ] ) ) : '';
	$teile = explode( '|', $wert );
	return 3 === count( $teile ) && hypetech_einwilligung_konfig()['version'] === $teile[0] && in_array( $dienst, explode( ',', $teile[2] ), true );
}

/**
 * Seite in der aktuellen Sprache (Polylang), sonst wie gespeichert.
 */
function hypetech_einwilligung_seiten_url( int $id ): string {
	if ( ! $id ) {
		return '';
	}
	if ( function_exists( 'pll_get_post' ) ) {
		$id = (int) ( pll_get_post( $id ) ?: $id );
	}
	return 'publish' === get_post_status( $id ) ? (string) get_permalink( $id ) : '';
}

/**
 * Link „Cookie-Einstellungen“ für Fußbereiche (leer, wenn das Modul aus ist).
 */
function hypetech_einwilligung_link( string $klasse = '' ): string {
	if ( ! hypetech_einwilligung_aktiv() ) {
		return '';
	}
	return sprintf( '<a href="#einwilligung" class="%s" data-hypetech-einwilligung-oeffnen>%s</a>', esc_attr( $klasse ), esc_html( hypetech_t( 'Cookie-Einstellungen', 'Cookie settings' ) ) );
}

/**
 * Skript, das erst nach Zustimmung zum Dienst läuft (type="text/plain" bis dahin).
 */
function hypetech_einwilligung_skript( string $dienst, string $src = '', string $inline = '' ): void {
	$attr = array(
		'type'                 => 'text/plain',
		'data-hypetech-dienst' => sanitize_key( $dienst ),
	);
	if ( $src ) {
		$attr['data-src'] = esc_url_raw( $src );
	}
	wp_print_inline_script_tag( $inline, $attr );
}

// --------------------------------------------------------------------- Ausgabe

add_action(
	'wp_enqueue_scripts',
	static function (): void {
		if ( ! hypetech_einwilligung_aktiv() ) {
			return;
		}
		$basis = plugins_url( 'assets/', HYPETECH_CORE_FILE );
		wp_enqueue_style( 'hypetech-einwilligung', $basis . 'einwilligung.css', array(), HYPETECH_CORE_VERSION );
		wp_enqueue_script(
			'hypetech-einwilligung',
			$basis . 'einwilligung.js',
			array(),
			HYPETECH_CORE_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
	}
);

/**
 * Banner-Markup. Steht am Anfang von <body> (erste Station für Tastatur/Screenreader), bleibt `hidden`,
 * bis das Skript feststellt, dass noch keine gültige Wahl vorliegt. Für alle Besucher gleich → CDN-fest.
 */
function hypetech_einwilligung_banner(): void {
	static $fertig = false;
	if ( $fertig || is_admin() || ! hypetech_einwilligung_aktiv() ) {
		return;
	}
	$fertig  = true;
	$konfig  = hypetech_einwilligung_konfig();
	$de      = str_starts_with( get_locale(), 'de' );
	$t       = static fn( string $d, string $e ): string => $de ? $d : $e;
	$kats    = array(
		'medien'    => $t( 'Externe Medien', 'External media' ),
		'statistik' => $t( 'Statistik', 'Statistics' ),
		'marketing' => $t( 'Marketing', 'Marketing' ),
	);
	$gruppen = array();
	foreach ( $konfig['dienste'] as $id => $d ) {
		$gruppen[ isset( $kats[ $d['kategorie'] ] ) ? $d['kategorie'] : 'medien' ][ $id ] = $d;
	}
	$namen       = wp_list_pluck( $konfig['dienste'], 'name' );
	$datenschutz = hypetech_einwilligung_seiten_url( (int) get_option( 'wp_page_for_privacy_policy' ) );
	$impressum   = hypetech_einwilligung_seiten_url( (int) get_option( 'hypetech_impressum_seite' ) );
	$daten       = array(
		'version'  => $konfig['version'],
		'laufzeit' => HYPETECH_EINWILLIGUNG_LAUFZEIT,
		'dienste'  => array_map(
			static fn( $id, $d ) => array(
				'id'   => $id,
				'name' => $d['name'],
			),
			array_keys( $konfig['dienste'] ),
			$konfig['dienste']
		),
		'texte'    => array(
			'abspielen' => $t( 'Video abspielen', 'Play video' ),
			/* translators: %s: Dienst, z. B. YouTube */
			'hinweis'   => $t( 'Dieses Video wird von %s bereitgestellt. Beim Abspielen werden Daten an den Anbieter übertragen.', 'This video is provided by %s. Playing it sends data to the provider.' ),
			'einmal'    => $t( 'Video laden', 'Load video' ),
			/* translators: %s: Dienst */
			'immer'     => $t( '%s immer erlauben', 'Always allow %s' ),
			'mehr'      => $t( 'Datenschutzerklärung', 'Privacy policy' ),
		),
		'datenschutz' => $datenschutz,
	);
	?>
<div class="ht-ew" id="einwilligung" role="dialog" aria-modal="false" aria-labelledby="ht-ew-titel" aria-describedby="ht-ew-text" hidden>
	<p class="ht-ew__titel" id="ht-ew-titel" tabindex="-1"><?php echo esc_html( $t( 'Datenschutz-Einstellungen', 'Privacy settings' ) ); ?></p>
	<p class="ht-ew__text" id="ht-ew-text">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: Liste der Dienste */
				$t( 'Wir binden externe Dienste nur mit Ihrer Zustimmung ein: %s. Ihre Wahl können Sie jederzeit über „Cookie-Einstellungen“ unten auf jeder Seite ändern.', 'We only load external services with your consent: %s. You can change your choice at any time via “Cookie settings” at the bottom of every page.' ),
				implode( ', ', $namen )
			)
		);
		?>
	</p>
	<?php if ( $datenschutz || $impressum ) : ?>
	<p class="ht-ew__links">
		<?php if ( $datenschutz ) : ?>
		<a href="<?php echo esc_url( $datenschutz ); ?>"><?php echo esc_html( $t( 'Datenschutzerklärung', 'Privacy policy' ) ); ?></a>
		<?php endif; ?>
		<?php if ( $impressum ) : ?>
		<a href="<?php echo esc_url( $impressum ); ?>"><?php echo esc_html( $t( 'Impressum', 'Imprint' ) ); ?></a>
		<?php endif; ?>
	</p>
	<?php endif; ?>
	<div class="ht-ew__details" id="ht-ew-details" hidden>
		<fieldset class="ht-ew__gruppe">
			<legend><?php echo esc_html( $t( 'Notwendig', 'Essential' ) ); ?></legend>
			<label class="ht-ew__dienst"><input type="checkbox" checked disabled> <span><?php echo esc_html( $t( 'Einwilligung', 'Consent' ) ); ?></span></label>
			<p class="ht-ew__info"><?php echo esc_html( sprintf( $t( 'Speichert Ihre Auswahl im Cookie „%1$s“ (%2$d Tage). Kein Tracking.', 'Stores your choice in the cookie “%1$s” (%2$d days). No tracking.' ), HYPETECH_EINWILLIGUNG_COOKIE, HYPETECH_EINWILLIGUNG_LAUFZEIT / DAY_IN_SECONDS ) ); ?></p>
		</fieldset>
		<?php foreach ( $gruppen as $kat => $dienste ) : ?>
		<fieldset class="ht-ew__gruppe">
			<legend><?php echo esc_html( $kats[ $kat ] ); ?></legend>
			<?php foreach ( $dienste as $id => $d ) : ?>
			<label class="ht-ew__dienst"><input type="checkbox" name="dienst" value="<?php echo esc_attr( $id ); ?>"> <span><?php echo esc_html( $d['name'] ); ?></span></label>
			<p class="ht-ew__info">
				<?php echo esc_html( $de ? $d['zweck_de'] : $d['zweck_en'] ); ?>
				<?php if ( $d['anbieter'] ) : ?>
				<br><?php echo esc_html( $t( 'Anbieter', 'Provider' ) . ': ' . $d['anbieter'] ); ?>
				<?php endif; ?>
				<?php if ( $d['datenschutz'] ) : ?>
				<br><a href="<?php echo esc_url( $d['datenschutz'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $t( 'Datenschutz beim Anbieter', 'Provider privacy policy' ) ); ?></a>
				<?php endif; ?>
			</p>
			<?php endforeach; ?>
		</fieldset>
		<?php endforeach; ?>
	</div>
	<div class="ht-ew__knoepfe">
		<button type="button" class="ht-ew__knopf" data-aktion="alle"><?php echo esc_html( $t( 'Alle akzeptieren', 'Accept all' ) ); ?></button>
		<button type="button" class="ht-ew__knopf" data-aktion="ablehnen"><?php echo esc_html( $t( 'Nur notwendige', 'Essential only' ) ); ?></button>
		<button type="button" class="ht-ew__knopf" data-aktion="speichern" hidden><?php echo esc_html( $t( 'Auswahl speichern', 'Save selection' ) ); ?></button>
		<button type="button" class="ht-ew__mehr" data-aktion="einstellungen" aria-expanded="false" aria-controls="ht-ew-details"><?php echo esc_html( $t( 'Einstellungen', 'Settings' ) ); ?></button>
	</div>
	<script type="application/json" id="ht-ew-daten"><?php echo wp_json_encode( $daten, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?></script>
</div>
	<?php
}
add_action( 'wp_body_open', 'hypetech_einwilligung_banner', 5 );
add_action( 'wp_footer', 'hypetech_einwilligung_banner', 5 ); // Fallback für Themes ohne wp_body_open

/**
 * Navigation-Link auf `#einwilligung` nur ausgeben, wenn das Modul aktiv ist (Vorlage hat ihn im Fuß).
 */
add_filter(
	'render_block_core/navigation-link',
	static function ( string $html, array $block ): string {
		if ( '#einwilligung' === ( $block['attrs']['url'] ?? '' ) && ! hypetech_einwilligung_aktiv() ) {
			return '';
		}
		return $html;
	},
	10,
	2
);
