/* HYPE:TECH Kontaktformular – Absenden ohne Neuladen. Ohne JS funktioniert das Formular per normalem POST. */
( function () {
	'use strict';

	function init( form ) {
		var root = form.closest( '.ht-form' );
		var status = form.querySelector( '.ht-form__status' );
		var button = form.querySelector( '.ht-form__submit' );
		var thanks = root.querySelector( '.ht-form__thanks' );

		function clearErrors() {
			form.querySelectorAll( '.ht-form__error' ).forEach( function ( el ) {
				el.textContent = '';
			} );
			form.querySelectorAll( '[aria-invalid]' ).forEach( function ( el ) {
				el.removeAttribute( 'aria-invalid' );
			} );
		}

		function showErrors( errors ) {
			var first = null;
			Object.keys( errors || {} ).forEach( function ( name ) {
				var input = form.elements[ name ];
				var slot = form.querySelector( '.ht-form__error[data-for="' + name + '"]' );
				if ( slot ) {
					slot.textContent = errors[ name ];
				}
				if ( input ) {
					input.setAttribute( 'aria-invalid', 'true' );
					first = first || input;
				}
			} );
			if ( first ) {
				first.focus();
			}
		}

		form.addEventListener( 'submit', function ( event ) {
			if ( ! window.fetch || ! window.FormData ) {
				return; // Fallback: normales Absenden.
			}
			event.preventDefault();
			clearErrors();
			status.textContent = form.dataset.textSenden || 'Wird gesendet …';
			button.disabled = true;

			fetch( form.dataset.endpoint, {
				method: 'POST',
				body: new FormData( form ),
				credentials: 'same-origin',
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( data ) {
					if ( data && data.ok ) {
						form.hidden = true;
						thanks.hidden = false;
						root.classList.add( 'is-sent' );
						thanks.focus();
						return;
					}
					status.textContent = ( data && data.message ) || form.dataset.textFehler || 'Es ist ein Fehler aufgetreten.';
					showErrors( data && data.errors );
				} )
				.catch( function () {
					status.textContent = form.dataset.textVerbindung || 'Verbindung fehlgeschlagen. Bitte versuchen Sie es erneut.';
				} )
				.finally( function () {
					button.disabled = false;
				} );
		} );
	}

	document.querySelectorAll( '.ht-form__form' ).forEach( init );
} )();
