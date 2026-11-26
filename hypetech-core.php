<?php
/**
 * Plugin Name:       HYPE:TECH Core
 * Description:       Kundenrolle, Editor-Sperren, Sicherheit, SMTP, Kontaktformular und Updates für HYPE:TECH-Websites. Wird über das mu-plugin hypetech-loader.php immer aktiv gehalten.
 * Version:           1.2.1
 * Requires at least: 6.6
 * Requires PHP:      8.2
 * Author:            HYPE:TECH
 * License:           GPL-2.0-or-later
 * Text Domain:       hypetech-core
 * Update URI:        hypetech-core
 *
 * @package HypetechCore
 */

defined( 'ABSPATH' ) || exit;

const HYPETECH_CORE_VERSION = '1.2.1';
define( 'HYPETECH_CORE_FILE', __FILE__ );
define( 'HYPETECH_CORE_DIR', __DIR__ );

/*
 * Update-Quelle: JSON-Manifest des neuesten Releases (siehe bin/release.mjs).
 * Kann in wp-config.php überschrieben werden. Leer = Updater aus.
 * Platzhalter <ORG> nach Anlegen des GitHub-Repos ersetzen (docs/ENTSCHEIDUNGEN.md #08).
 */
if ( ! defined( 'HYPETECH_CORE_UPDATE_JSON' ) ) {
	define( 'HYPETECH_CORE_UPDATE_JSON', 'https://github.com/<ORG>/hypetech-core/releases/latest/download/update.json' );
}

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/role.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/editor.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/frontend.php';
require_once __DIR__ . '/includes/images.php';
require_once __DIR__ . '/includes/smtp.php';
require_once __DIR__ . '/includes/contact.php';
require_once __DIR__ . '/includes/updater.php';
require_once __DIR__ . '/includes/status.php';
require_once __DIR__ . '/includes/importer.php';
