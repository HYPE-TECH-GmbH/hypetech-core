<?php
/**
 * Ausgabe des Kontaktformulars. Feste Texte in der Sprache der Website (hypetech_t, Entscheidung #23).
 *
 * @var array $attributes Block-Attribute.
 * @package HypetechCore
 */

defined( 'ABSPATH' ) || exit;

$hypetech_status = isset( $_GET['kontakt'] ) ? sanitize_key( wp_unslash( $_GET['kontakt'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$hypetech_msg    = isset( $_GET['kontakt_msg'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['kontakt_msg'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$hypetech_ok     = 'ok' === $hypetech_status;
$hypetech_id     = 'kf-' . substr( md5( (string) get_the_ID() ), 0, 6 );
$hypetech_de     = str_starts_with( get_locale(), 'de' );
$hypetech_button = wp_strip_all_tags( (string) ( $attributes['buttonText'] ?? '' ) );
$hypetech_thanks = wp_strip_all_tags( (string) ( $attributes['successText'] ?? '' ) );
// Standardtexte aus block.json sind deutsch – auf englischen Seiten durch englische ersetzen.
if ( ! $hypetech_de && ( '' === $hypetech_button || 'Nachricht senden' === $hypetech_button ) ) {
	$hypetech_button = 'Send message';
}
if ( ! $hypetech_de && ( '' === $hypetech_thanks || str_starts_with( $hypetech_thanks, 'Vielen Dank! Ihre Nachricht' ) ) ) {
	$hypetech_thanks = 'Thank you! Your message has arrived – we will get back to you shortly.';
}
$hypetech_priv_pfad = (string) ( $attributes['privacyUrl'] ?? '' );
if ( '' === $hypetech_priv_pfad || ( ! $hypetech_de && '/datenschutz/' === $hypetech_priv_pfad ) ) {
	$hypetech_priv_pfad = get_privacy_policy_url() ? wp_make_link_relative( get_privacy_policy_url() ) : '/datenschutz/';
}
$hypetech_priv      = esc_url( home_url( $hypetech_priv_pfad ) );
$hypetech_nachricht = wp_strip_all_tags( (string) ( $attributes['messageLabel'] ?? '' ) ) ?: hypetech_t( 'Ihre Nachricht', 'Your message' );
$hypetech_ph        = wp_strip_all_tags( (string) ( $attributes['messagePlaceholder'] ?? '' ) );
$hypetech_optional  = hypetech_t( '(optional)', '(optional)' );

$hypetech_wrapper = get_block_wrapper_attributes( array( 'class' => 'ht-form' . ( $hypetech_ok ? ' is-sent' : '' ) ) );

/**
 * Ein Eingabefeld (Label, Feld, Fehlerplatz).
 */
$hypetech_feld = static function ( string $name, string $label, string $typ, bool $pflicht, string $auto, int $max ) use ( $hypetech_id, $hypetech_optional ): void {
	$id = $hypetech_id . '-' . $name;
	?>
	<p class="ht-form__field">
		<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?>
			<?php if ( $pflicht ) : ?>
				<span aria-hidden="true">*</span>
			<?php else : ?>
				<span class="ht-form__optional"><?php echo esc_html( $hypetech_optional ); ?></span>
			<?php endif; ?>
		</label>
		<input type="<?php echo esc_attr( $typ ); ?>" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" <?php echo $pflicht ? 'required' : ''; ?> autocomplete="<?php echo esc_attr( $auto ); ?>"<?php echo $max ? ' maxlength="' . (int) $max . '"' : ''; ?> aria-describedby="<?php echo esc_attr( $id ); ?>-err">
		<span class="ht-form__error" id="<?php echo esc_attr( $id ); ?>-err" data-for="<?php echo esc_attr( $name ); ?>"></span>
	</p>
	<?php
};
?>
<div <?php echo $hypetech_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput ?> id="kontaktformular">
	<form class="ht-form__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
		data-endpoint="<?php echo esc_url( rest_url( 'hypetech/v1/contact' ) ); ?>"
		data-text-senden="<?php echo esc_attr( hypetech_t( 'Wird gesendet …', 'Sending …' ) ); ?>"
		data-text-fehler="<?php echo esc_attr( hypetech_t( 'Es ist ein Fehler aufgetreten.', 'Something went wrong.' ) ); ?>"
		data-text-verbindung="<?php echo esc_attr( hypetech_t( 'Verbindung fehlgeschlagen. Bitte versuchen Sie es erneut.', 'Connection failed. Please try again.' ) ); ?>"
		novalidate <?php echo $hypetech_ok ? 'hidden' : ''; ?>>
		<input type="hidden" name="action" value="hypetech_contact">
		<input type="hidden" name="_ht" value="<?php echo esc_attr( hypetech_contact_token() ); ?>">
		<div class="ht-form__hp" aria-hidden="true">
			<label for="<?php echo esc_attr( $hypetech_id ); ?>-website"><?php echo esc_html( hypetech_t( 'Website (bitte leer lassen)', 'Website (please leave empty)' ) ); ?></label>
			<input type="text" id="<?php echo esc_attr( $hypetech_id ); ?>-website" name="website" tabindex="-1" autocomplete="off">
		</div>

		<div class="ht-form__row">
			<?php
			$hypetech_feld( 'name', 'Name', 'text', true, 'name', 200 );
			if ( ! empty( $attributes['showCompany'] ) ) {
				$hypetech_feld( 'firma', hypetech_t( 'Firma', 'Company' ), 'text', false, 'organization', 200 );
			} else {
				$hypetech_feld( 'email', hypetech_t( 'E-Mail', 'Email' ), 'email', true, 'email', 0 );
			}
			?>
		</div>
		<?php if ( ! empty( $attributes['showCompany'] ) ) : ?>
			<div class="ht-form__row">
				<?php
				$hypetech_feld( 'email', hypetech_t( 'E-Mail', 'Email' ), 'email', true, 'email', 0 );
				if ( ! empty( $attributes['showPhone'] ) ) {
					$hypetech_feld( 'telefon', hypetech_t( 'Telefon', 'Phone' ), 'tel', false, 'tel', 50 );
				}
				?>
			</div>
		<?php elseif ( ! empty( $attributes['showPhone'] ) ) : ?>
			<?php $hypetech_feld( 'telefon', hypetech_t( 'Telefon', 'Phone' ), 'tel', false, 'tel', 50 ); ?>
		<?php endif; ?>
		<p class="ht-form__field">
			<label for="<?php echo esc_attr( $hypetech_id ); ?>-nachricht"><?php echo esc_html( $hypetech_nachricht ); ?> <span aria-hidden="true">*</span></label>
			<textarea id="<?php echo esc_attr( $hypetech_id ); ?>-nachricht" name="nachricht" rows="6" required maxlength="5000"<?php echo $hypetech_ph ? ' placeholder="' . esc_attr( $hypetech_ph ) . '"' : ''; ?> aria-describedby="<?php echo esc_attr( $hypetech_id ); ?>-nachricht-err"></textarea>
			<span class="ht-form__error" id="<?php echo esc_attr( $hypetech_id ); ?>-nachricht-err" data-for="nachricht"></span>
		</p>

		<p class="ht-form__privacy">
			<?php if ( $hypetech_de ) : ?>
				Mit dem Absenden werden Ihre Angaben zur Bearbeitung Ihrer Anfrage per E-Mail an uns übermittelt und nicht auf dieser Website gespeichert. Mehr in der <a href="<?php echo $hypetech_priv; // phpcs:ignore WordPress.Security.EscapeOutput -- oben escaped ?>">Datenschutzerklärung</a>.
			<?php else : ?>
				We use your details only to answer your request. They are sent to us by email and not stored on this website. See the <a href="<?php echo $hypetech_priv; // phpcs:ignore WordPress.Security.EscapeOutput -- oben escaped ?>">privacy policy</a>.
			<?php endif; ?>
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
