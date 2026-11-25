/* HYPE:TECH Kontaktformular – Editor-Darstellung ohne Build-Schritt. Kunde kann nur Button- und Danke-Text ändern. */
( function ( blocks, element, blockEditor, components ) {
	'use strict';
	var el = element.createElement;
	var RichText = blockEditor.RichText;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;

	function Field( label, tall ) {
		return el(
			'div',
			{ className: 'ht-form__field' },
			el( 'label', null, label ),
			el( tall ? 'textarea' : 'input', { disabled: true, rows: tall ? 4 : undefined } )
		);
	}

	blocks.registerBlockType( 'hypetech/contact-form', {
		edit: function ( props ) {
			var a = props.attributes;
			return el(
				'div',
				useBlockProps( { className: 'ht-form ht-form--editor' } ),
				el(
					InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: 'Formular' },
						el( components.ToggleControl, {
							label: 'Telefonfeld anzeigen',
							checked: !! a.showPhone,
							onChange: function ( v ) {
								props.setAttributes( { showPhone: v } );
							},
						} ),
						el( components.TextControl, {
							label: 'Link zur Datenschutzerklärung',
							value: a.privacyUrl,
							onChange: function ( v ) {
								props.setAttributes( { privacyUrl: v } );
							},
						} )
					)
				),
				el( 'div', { className: 'ht-form__row' }, Field( 'Name *' ), Field( 'E-Mail *' ) ),
				a.showPhone ? Field( 'Telefon (optional)' ) : null,
				Field( 'Ihre Nachricht *', true ),
				el(
					'div',
					{ className: 'wp-block-button' },
					el( RichText, {
						tagName: 'span',
						className: 'wp-block-button__link wp-element-button',
						value: a.buttonText,
						allowedFormats: [],
						withoutInteractiveFormatting: true,
						placeholder: 'Button-Text',
						onChange: function ( v ) {
							props.setAttributes( { buttonText: v } );
						},
					} )
				),
				el(
					'div',
					{ className: 'ht-form__editor-thanks' },
					el( 'strong', null, 'Text nach dem Absenden: ' ),
					el( RichText, {
						tagName: 'span',
						value: a.successText,
						allowedFormats: [],
						placeholder: 'Danke-Text',
						onChange: function ( v ) {
							props.setAttributes( { successText: v } );
						},
					} )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components );
