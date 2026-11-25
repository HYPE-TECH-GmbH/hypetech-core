<?php
/**
 * Ausgabe des Kontaktformulars.
 *
 * @var array $attributes Block-Attribute.
 * @package HypetechCore
 */

defined( 'ABSPATH' ) || exit;

$hypetech_status = isset( $_GET['kontakt'] ) ? sanitize_key( wp_unslash( $_GET['kontakt'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$hypetech_msg    = isset( $_GET['kontakt_msg'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['kontakt_msg'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$hypetech_ok     = 'ok' === $hypetech_status;
$hypetech_id     = 'kf-' . substr( md5( (string) get_the_ID() ), 0, 6 );
$hypetech_button = wp_strip_all_tags( $attributes['buttonText'] ?? 'Nachricht senden' );
$hypetech_thanks = wp_strip_all_tags( $attributes['successText'] ?? '' );
$hypetech_priv   = esc_url( home_url( $attributes['privacyUrl'] ?? '/datenschutz/' ) );

$hypetech_wrapper = get_block_wrapper_attributes( array( 'class' => 'ht-form' . ( $hypetech_ok ? ' is-sent' : '' ) ) );
?>
<div <?php echo $hypetech_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput ?> id="kontaktformular">
	<form class="ht-form__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
		data-endpoint="<?php echo esc_url( rest_url( 'hypetech/v1/contact' ) ); ?>" novalidate <?php echo $hypetech_ok ? 'hidden' : ''; ?>>
		<input type="hidden" name="action" value="hypetech_contact">
		<input type="hidden" name="_ht" value="<?php echo esc_attr( hypetech_contact_token() ); ?>">
		<div class="ht-form__hp" aria-hidden="true">
			<label for="<?php echo esc_attr( $hypetech_id ); ?>-website">Website (bitte leer lassen)</label>
			<input type="text" id="<?php echo esc_attr( $hypetech_id ); ?>-website" name="website" tabindex="-1" autocomplete="off">
		</div>

		<div class="ht-form__row">
			<p class="ht-form__field">
				<label for="<?php echo esc_attr( $hypetech_id ); ?>-name">Name <span aria-hidden="true">*</span></label>
				<input type="text" id="<?php echo esc_attr( $hypetech_id ); ?>-name" name="name" required autocomplete="name" maxlength="200" aria-describedby="<?php echo esc_attr( $hypetech_id ); ?>-name-err">
				<span class="ht-form__error" id="<?php echo esc_attr( $hypetech_id ); ?>-name-err" data-for="name"></span>
			</p>
			<p class="ht-form__field">
				<label for="<?php echo esc_attr( $hypetech_id ); ?>-email">E-Mail <span aria-hidden="true">*</span></label>
				<input type="email" id="<?php echo esc_attr( $hypetech_id ); ?>-email" name="email" required autocomplete="email" aria-describedby="<?php echo esc_attr( $hypetech_id ); ?>-email-err">
				<span class="ht-form__error" id="<?php echo esc_attr( $hypetech_id ); ?>-email-err" data-for="email"></span>
			</p>
		</div>
		<?php if ( ! empty( $attributes['showPhone'] ) ) : ?>
			<p class="ht-form__field">
				<label for="<?php echo esc_attr( $hypetech_id ); ?>-telefon">Telefon <span class="ht-form__optional">(optional)</span></label>
				<input type="tel" id="<?php echo esc_attr( $hypetech_id ); ?>-telefon" name="telefon" autocomplete="tel" maxlength="50" aria-describedby="<?php echo esc_attr( $hypetech_id ); ?>-telefon-err">
				<span class="ht-form__error" id="<?php echo esc_attr( $hypetech_id ); ?>-telefon-err" data-for="telefon"></span>
			</p>
		<?php endif; ?>
		<p class="ht-form__field">
			<label for="<?php echo esc_attr( $hypetech_id ); ?>-nachricht">Ihre Nachricht <span aria-hidden="true">*</span></label>
			<textarea id="<?php echo esc_attr( $hypetech_id ); ?>-nachricht" name="nachricht" rows="6" required maxlength="5000" aria-describedby="<?php echo esc_attr( $hypetech_id ); ?>-nachricht-err"></textarea>
			<span class="ht-form__error" id="<?php echo esc_attr( $hypetech_id ); ?>-nachricht-err" data-for="nachricht"></span>
		</p>

		<p class="ht-form__privacy">
			Mit dem Absenden werden Ihre Angaben zur Bearbeitung Ihrer Anfrage per E-Mail an uns übermittelt und nicht auf dieser Website gespeichert. Mehr in der <a href="<?php echo $hypetech_priv; // phpcs:ignore WordPress.Security.EscapeOutput -- oben escaped ?>">Datenschutzerklärung</a>.
		</p>

		<p class="ht-form__status" role="status" aria-live="polite"><?php echo 'fehler' === $hypetech_status ? esc_html( $hypetech_msg ) : ''; ?></p>

		<div class="wp-block-button">
			<button type="submit" class="wp-block-button__link wp-element-button ht-form__submit"><?php echo esc_html( $hypetech_button ); ?></button>
		</div>
	</form>
	<div class="ht-form__thanks" role="status" aria-live="polite" tabindex="-1" <?php echo $hypetech_ok ? '' : 'hidden'; ?>>
		<p><?php echo esc_html( $hypetech_thanks ); ?></p>
	</div>
</div>
