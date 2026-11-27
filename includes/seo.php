<?php
/**
 * SEO-Grundausstattung für alle Kundenseiten (Entscheidung #22): Meta-Beschreibung, Canonical, Robots,
 * Open Graph/Twitter, strukturierte Daten (JSON-LD @graph) und eine bereinigte Sitemap.
 *
 * Datenquellen:
 *   - Option hypetech_seo (aus content/site.json → seo): Organisation, Standard-Bild, Titel-Trenner
 *   - je Seite/Beitrag (Import aus site.json): _hypetech_seo_beschreibung, _hypetech_seo_titel, _hypetech_seo_bild,
 *     _hypetech_seo_noindex, _hypetech_seo_typ (z. B. "service")
 *   - Beiträge: Textauszug als Beschreibung, Beitragsbild als Vorschaubild
 * Theme kann den Graphen über den Filter 'hypetech_seo_graph' erweitern.
 * Ist „The SEO Framework“ aktiv, gibt dieses Modul nichts aus (keine doppelten Tags).
 *
 * @package HypetechCore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ist das eigene SEO-Modul zuständig?
 */
function hypetech_seo_aktiv(): bool {
	return ! defined( 'THE_SEO_FRAMEWORK_VERSION' ) && apply_filters( 'hypetech_seo_aktiv', true );
}

/**
 * Einstellungen aus site.json → seo.
 */
function hypetech_seo_option( string $key = '' ) {
	$opt = (array) get_option( 'hypetech_seo', array() );
	return '' === $key ? $opt : ( $opt[ $key ] ?? null );
}

/**
 * Pfad aus dem Theme oder absolute URL in eine URL umwandeln.
 */
function hypetech_seo_url( string $wert ): string {
	if ( '' === $wert ) {
		return '';
	}
	return preg_match( '#^https?://#', $wert ) ? $wert : get_theme_file_uri( ltrim( $wert, '/' ) );
}

/**
 * Startseiten-URL der aktuellen Sprache (Polylang: /de/ usw., sonst /).
 */
function hypetech_seo_home(): string {
	return function_exists( 'pll_home_url' ) ? (string) pll_home_url() : home_url( '/' );
}

/**
 * Das gerade angezeigte Objekt (Seite/Beitrag) – auch für die Beitragsseite (Blog-Übersicht).
 */
function hypetech_seo_post(): ?WP_Post {
	if ( is_home() && ! is_front_page() ) {
		$id = (int) get_option( 'page_for_posts' );
		return $id ? get_post( $id ) : null;
	}
	return is_singular() ? get_queried_object() : null;
}

/**
 * Kanonische URL der aktuellen Ansicht (inkl. Blätterseite).
 */
function hypetech_seo_canonical(): string {
	$url = '';
	if ( is_front_page() ) {
		$url = hypetech_seo_home();
	} elseif ( is_home() ) {
		$url = (string) get_permalink( (int) get_option( 'page_for_posts' ) );
	} elseif ( is_singular() ) {
		$url = (string) wp_get_canonical_url();
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$link = get_term_link( get_queried_object() );
		$url  = is_wp_error( $link ) ? '' : $link;
	}
	$seite = (int) get_query_var( 'paged' );
	if ( $url && $seite > 1 && ! is_singular() ) {
		$url = trailingslashit( $url ) . user_trailingslashit( 'page/' . $seite, 'paged' );
	}
	return $url;
}

/**
 * Meta-Beschreibung der aktuellen Ansicht (max. ~160 Zeichen).
 */
function hypetech_seo_beschreibung(): string {
	$text = '';
	$post = hypetech_seo_post();
	if ( $post ) {
		$text = (string) get_post_meta( $post->ID, '_hypetech_seo_beschreibung', true );
		if ( '' === $text && has_excerpt( $post ) ) {
			$text = $post->post_excerpt;
		}
		if ( '' === $text && 'post' === $post->post_type ) {
			$text = wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 30, '…' );
		}
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$text = term_description();
	}
	if ( '' === trim( wp_strip_all_tags( $text ) ) && is_front_page() ) {
		$text = get_bloginfo( 'description' );
	}
	$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );
	return mb_strlen( $text ) > 165 ? rtrim( mb_substr( $text, 0, 160 ), " ,.;:–-" ) . '…' : $text;
}

/**
 * Vorschaubild: eigenes SEO-Bild → Beitragsbild → Standard-Bild aus site.json.
 *
 * @return array{url:string,w:int,h:int,alt:string}|null
 */
function hypetech_seo_bild(): ?array {
	$post = hypetech_seo_post();
	$id   = 0;
	if ( $post ) {
		$id = (int) get_post_meta( $post->ID, '_hypetech_seo_bild', true ) ?: (int) get_post_thumbnail_id( $post );
	}
	if ( $id ) {
		$src = wp_get_attachment_image_src( $id, 'ht-halb' ) ?: wp_get_attachment_image_src( $id, 'large' );
		if ( $src ) {
			return array(
				'url' => $src[0],
				'w'   => (int) $src[1],
				'h'   => (int) $src[2],
				'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			);
		}
	}
	$std = (string) hypetech_seo_option( 'standard_bild' );
	return $std ? array(
		'url' => hypetech_seo_url( $std ),
		'w'   => (int) hypetech_seo_option( 'standard_bild_breite' ),
		'h'   => (int) hypetech_seo_option( 'standard_bild_hoehe' ),
		'alt' => (string) hypetech_seo_option( 'standard_bild_alt' ),
	) : null;
}

/**
 * Darf die Ansicht in den Index?
 */
function hypetech_seo_indexierbar(): bool {
	$post = hypetech_seo_post();
	if ( $post && get_post_meta( $post->ID, '_hypetech_seo_noindex', true ) ) {
		return false;
	}
	return ! ( is_search() || is_404() || is_date() || is_author() || is_attachment() );
}

// ---------------------------------------------------------------- Titel
add_filter(
	'document_title_separator',
	static fn( $sep ) => hypetech_seo_aktiv() && hypetech_seo_option( 'titel_trenner' ) ? (string) hypetech_seo_option( 'titel_trenner' ) : $sep
);
add_filter(
	'pre_get_document_title',
	static function ( $titel ) {
		if ( ! hypetech_seo_aktiv() ) {
			return $titel;
		}
		$post = hypetech_seo_post();
		$eigen = $post ? (string) get_post_meta( $post->ID, '_hypetech_seo_titel', true ) : '';
		return '' !== $eigen ? $eigen : $titel;
	},
	20
);
// Startseite: „Name – Untertitel“ ist Standard; Archivtitel ohne „Kategorie:“-Präfix.
add_filter( 'get_the_archive_title_prefix', '__return_empty_string' );

// ---------------------------------------------------------------- Robots & Canonical
add_filter(
	'wp_robots',
	static function ( array $robots ): array {
		if ( hypetech_seo_aktiv() && ! hypetech_seo_indexierbar() ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}
);
add_action(
	'wp',
	static function (): void {
		if ( hypetech_seo_aktiv() ) {
			remove_action( 'wp_head', 'rel_canonical' );
		}
	}
);

// ---------------------------------------------------------------- Kopfbereich
add_action(
	'wp_head',
	static function (): void {
		if ( ! hypetech_seo_aktiv() || is_404() ) {
			return;
		}
		$beschreibung = hypetech_seo_beschreibung();
		$canonical    = hypetech_seo_canonical();
		$bild         = hypetech_seo_bild();
		$post         = hypetech_seo_post();
		$artikel      = $post && 'post' === $post->post_type;

		echo "\n<!-- HYPE:TECH SEO -->\n";
		if ( $beschreibung ) {
			printf( '<meta name="description" content="%s">' . "\n", esc_attr( $beschreibung ) );
		}
		if ( $canonical && hypetech_seo_indexierbar() ) {
			printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $canonical ) );
		}
		$og = array(
			'og:locale'      => get_locale(),
			'og:type'        => $artikel ? 'article' : 'website',
			'og:site_name'   => get_bloginfo( 'name' ),
			'og:title'       => wp_get_document_title(),
			'og:description' => $beschreibung,
			'og:url'         => $canonical,
		);
		if ( $bild ) {
			$og['og:image'] = $bild['url'];
			if ( $bild['w'] && $bild['h'] ) {
				$og['og:image:width']  = (string) $bild['w'];
				$og['og:image:height'] = (string) $bild['h'];
			}
			$og['og:image:alt'] = $bild['alt'];
		}
		if ( function_exists( 'pll_languages_list' ) ) {
			foreach ( (array) pll_languages_list( array( 'fields' => 'locale' ) ) as $loc ) {
				if ( $loc !== get_locale() ) {
					printf( '<meta property="og:locale:alternate" content="%s">' . "
", esc_attr( $loc ) );
				}
			}
		}
		if ( $artikel ) {
			$og['article:published_time'] = get_post_time( 'c', true, $post );
			$og['article:modified_time']  = get_post_modified_time( 'c', true, $post );
		}
		foreach ( array_filter( $og ) as $prop => $wert ) {
			printf( '<meta property="%s" content="%s">' . "\n", esc_attr( $prop ), esc_attr( $wert ) );
		}
		printf( '<meta name="twitter:card" content="%s">' . "\n", $bild ? 'summary_large_image' : 'summary' );

		$graph = hypetech_seo_graph( $beschreibung, $canonical, $bild );
		if ( $graph ) {
			echo '<script type="application/ld+json">' . wp_json_encode( array( '@context' => 'https://schema.org', '@graph' => $graph ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
		}
		echo "<!-- /HYPE:TECH SEO -->\n";
	},
	2
);

/**
 * Strukturierte Daten als @graph (Organisation, WebSite, WebPage, Breadcrumb, Artikel/Leistung).
 */
function hypetech_seo_graph( string $beschreibung, string $canonical, ?array $bild ): array {
	$home    = home_url( '/' );
	$sprache = str_replace( '_', '-', get_locale() );
	$org_id  = $home . '#organization';
	$site_id = $home . '#website';
	$o       = (array) hypetech_seo_option( 'organisation' );
	$graph   = array();

	$org = array_filter(
		array(
			'@type'       => $o['typ'] ?? 'Organization',
			'@id'         => $org_id,
			'name'        => $o['name'] ?? get_bloginfo( 'name' ),
			'legalName'   => $o['rechtlicher_name'] ?? null,
			'url'         => $home,
			'description' => $o['beschreibung'] ?? null,
			'email'       => $o['email'] ?? null,
			'telephone'   => $o['telefon'] ?? null,
			'foundingDate' => $o['gruendung'] ?? null,
			'areaServed'  => ! empty( $o['gebiet'] ) ? array_values( (array) $o['gebiet'] ) : null,
			'knowsAbout'  => ! empty( $o['themen'] ) ? array_values( (array) $o['themen'] ) : null,
			'sameAs'      => ! empty( $o['sameas'] ) ? array_values( (array) $o['sameas'] ) : null,
		)
	);
	if ( ! empty( $o['logo'] ) ) {
		$org['logo'] = array(
			'@type' => 'ImageObject',
			'@id'   => $home . '#logo',
			'url'   => hypetech_seo_url( (string) $o['logo'] ),
		);
		$org['image'] = array( '@id' => $home . '#logo' );
	}
	$adr = (array) ( $o['adresse'] ?? array() );
	if ( array_filter( $adr ) ) {
		$org['address'] = array_filter(
			array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => $adr['strasse'] ?? null,
				'postalCode'      => $adr['plz'] ?? null,
				'addressLocality' => $adr['ort'] ?? null,
				'addressRegion'   => $adr['region'] ?? null,
				'addressCountry'  => $adr['land'] ?? null,
			)
		);
	}
	$graph[] = $org;
	$graph[] = array(
		'@type'      => 'WebSite',
		'@id'        => $site_id,
		'url'        => $home,
		'name'       => get_bloginfo( 'name' ),
		'inLanguage' => $sprache,
		'publisher'  => array( '@id' => $org_id ),
	);

	if ( ! $canonical || ! hypetech_seo_indexierbar() ) {
		return apply_filters( 'hypetech_seo_graph', $graph );
	}

	// Breadcrumb: Start → (Eltern | Beitragsseite) → aktuelle Ansicht.
	$krumen = array( array( get_bloginfo( 'name' ), hypetech_seo_home() ) );
	$post   = hypetech_seo_post();
	$blog   = (int) get_option( 'page_for_posts' );
	if ( $post && ! is_front_page() ) {
		if ( 'post' === $post->post_type && $blog ) {
			$krumen[] = array( get_the_title( $blog ), get_permalink( $blog ) );
		}
		foreach ( array_reverse( get_post_ancestors( $post ) ) as $eltern ) {
			$krumen[] = array( get_the_title( $eltern ), get_permalink( $eltern ) );
		}
		$krumen[] = array( get_the_title( $post ), $canonical );
	} elseif ( is_category() || is_tag() || is_tax() ) {
		if ( $blog ) {
			$krumen[] = array( get_the_title( $blog ), get_permalink( $blog ) );
		}
		$krumen[] = array( single_term_title( '', false ), $canonical );
	}
	$seite_id = $canonical . '#webpage';
	$typ      = 'WebPage';
	if ( is_home() || is_category() || is_tag() || is_tax() ) {
		$typ = 'CollectionPage';
	}
	$webpage = array_filter(
		array(
			'@type'       => $typ,
			'@id'         => $seite_id,
			'url'         => $canonical,
			'name'        => wp_get_document_title(),
			'description' => $beschreibung ?: null,
			'inLanguage'  => $sprache,
			'isPartOf'    => array( '@id' => $site_id ),
			'about'       => is_front_page() ? array( '@id' => $org_id ) : null,
		)
	);
	if ( $bild ) {
		$webpage['primaryImageOfPage'] = array(
			'@type' => 'ImageObject',
			'url'   => $bild['url'],
		);
	}
	if ( $post ) {
		$webpage['datePublished'] = get_post_time( 'c', true, $post );
		$webpage['dateModified']  = get_post_modified_time( 'c', true, $post );
	}
	if ( count( $krumen ) > 1 ) {
		$items = array();
		foreach ( $krumen as $i => [ $name, $url ] ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'name'     => wp_strip_all_tags( (string) $name ),
				'item'     => $url,
			);
		}
		$graph[]               = array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $canonical . '#breadcrumb',
			'itemListElement' => $items,
		);
		$webpage['breadcrumb'] = array( '@id' => $canonical . '#breadcrumb' );
	}
	$graph[] = $webpage;

	if ( $post && 'post' === $post->post_type ) {
		$graph[] = array_filter(
			array(
				'@type'            => 'BlogPosting',
				'@id'              => $canonical . '#article',
				'headline'         => wp_strip_all_tags( get_the_title( $post ) ),
				'description'      => $beschreibung ?: null,
				'image'            => $bild ? $bild['url'] : null,
				'datePublished'    => get_post_time( 'c', true, $post ),
				'dateModified'     => get_post_modified_time( 'c', true, $post ),
				'inLanguage'       => $sprache,
				'author'           => array( '@id' => $org_id ),
				'publisher'        => array( '@id' => $org_id ),
				'mainEntityOfPage' => array( '@id' => $seite_id ),
				'isPartOf'         => array( '@id' => $site_id ),
				'articleSection'   => wp_list_pluck( get_the_category( $post->ID ), 'name' ) ?: null,
			)
		);
	} elseif ( $post && 'service' === get_post_meta( $post->ID, '_hypetech_seo_typ', true ) ) {
		$graph[] = array_filter(
			array(
				'@type'            => 'Service',
				'@id'              => $canonical . '#service',
				'name'             => wp_strip_all_tags( get_the_title( $post ) ),
				'serviceType'      => wp_strip_all_tags( get_the_title( $post ) ),
				'description'      => $beschreibung ?: null,
				'url'              => $canonical,
				'image'            => $bild ? $bild['url'] : null,
				'provider'         => array( '@id' => $org_id ),
				'areaServed'       => $org['areaServed'] ?? null,
				'mainEntityOfPage' => array( '@id' => $seite_id ),
			)
		);
	}
	return apply_filters( 'hypetech_seo_graph', $graph, $post );
}

// ---------------------------------------------------------------- Sitemap
// Nur Seiten, Beiträge und Kategorien; noindex-Einträge ausblenden.
add_filter(
	'wp_sitemaps_taxonomies',
	static fn( array $tax ): array => array_intersect_key( $tax, array( 'category' => true ) )
);
add_filter(
	'wp_sitemaps_post_types',
	static fn( array $types ): array => array_intersect_key( $types, array( 'page' => true, 'post' => true ) )
);
add_filter(
	'wp_sitemaps_posts_query_args',
	static function ( array $args ): array {
		$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
			array(
				'key'     => '_hypetech_seo_noindex',
				'compare' => 'NOT EXISTS',
			),
		);
		return $args;
	}
);
