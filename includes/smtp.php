<?php
/**
 * SMTP-Versand (SPEC 4.4). Zugangsdaten ausschließlich als Konstanten in wp-config.php.
 *
 * @package HypetechCore
 */

defined( 'ABSPATH' ) || exit;

function hypetech_smtp_configured(): bool {
	return defined( 'HYPETECH_SMTP_HOST' ) && defined( 'HYPETECH_SMTP_USER' ) && defined( 'HYPETECH_SMTP_PASS' );
}

add_action(
	'phpmailer_init',
	static function ( $mailer ): void {
		if ( ! hypetech_smtp_configured() ) {
			return;
		}
		$port = defined( 'HYPETECH_SMTP_PORT' ) ? (int) HYPETECH_SMTP_PORT : 465;

		$mailer->isSMTP();
		$mailer->Host       = HYPETECH_SMTP_HOST;
		$mailer->Port       = $port;
		$mailer->SMTPAuth   = true;
		$mailer->Username   = HYPETECH_SMTP_USER;
		$mailer->Password   = HYPETECH_SMTP_PASS;
		$mailer->SMTPSecure = 465 === $port ? 'ssl' : 'tls';
		$mailer->Timeout    = 15;
	}
);

add_filter(
	'wp_mail_from',
	static fn( $from ) => defined( 'HYPETECH_SMTP_FROM' ) ? HYPETECH_SMTP_FROM : $from
);
add_filter(
	'wp_mail_from_name',
	static fn( $name ) => defined( 'HYPETECH_SMTP_FROM_NAME' ) ? HYPETECH_SMTP_FROM_NAME : get_bloginfo( 'name' )
);

/**
 * Hinweis im Admin, falls SMTP fehlt (Hostinger-Servermail: max. 10/min, 100/Tag).
 */
add_action(
	'admin_notices',
	static function (): void {
		if ( hypetech_smtp_configured() || ! current_user_can( 'manage_options' ) || wp_get_environment_type() === 'local' ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>HYPE:TECH:</strong> SMTP ist nicht eingerichtet. Kontaktformular-Mails laufen über den Server-Mailversand. Konstanten <code>HYPETECH_SMTP_*</code> in <code>wp-config.php</code> setzen.</p></div>';
	}
);
