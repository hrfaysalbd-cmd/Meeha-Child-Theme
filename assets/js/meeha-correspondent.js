/**
 * Meeha News — correspondent application form + edit-request form (AJAX)
 * Follows the same submitForm() pattern as meeha-account.js for consistency.
 */
( function () {
	'use strict';

	if ( ! window.meehaAccountAjax ) {
		return;
	}

	function submitForm( form, action, msgEl, onSuccess ) {
		var button   = form.querySelector( 'button[type="submit"]' );
		var original = button ? button.textContent : '';
		if ( button ) {
			button.disabled = true;
			button.textContent = 'অপেক্ষা করুন...';
		}
		if ( msgEl ) {
			msgEl.textContent = '';
			msgEl.classList.remove( 'meeha-account-msg--error' );
		}

		var data = new FormData( form );
		data.append( 'action', action );
		data.append( 'nonce', window.meehaAccountAjax.nonce );

		fetch( window.meehaAccountAjax.url, { method: 'POST', body: data } )
			.then( function ( res ) { return res.json(); } )
			.then( function ( res ) {
				if ( msgEl ) {
					msgEl.textContent = res.data && res.data.message ? res.data.message : '';
				}
				if ( res.success ) {
					if ( onSuccess ) {
						onSuccess( res.data );
					}
					if ( res.data && res.data.redirect ) {
						setTimeout( function () { window.location.href = res.data.redirect; }, 900 );
					}
				} else if ( msgEl ) {
					msgEl.classList.add( 'meeha-account-msg--error' );
				}
			} )
			.catch( function () {
				if ( msgEl ) {
					msgEl.textContent = 'সমস্যা হয়েছে, আবার চেষ্টা করুন।';
					msgEl.classList.add( 'meeha-account-msg--error' );
				}
			} )
			.finally( function () {
				if ( button ) {
					button.disabled = false;
					button.textContent = original;
				}
			} );
	}

	var applyForm = document.getElementById( 'meeha-correspondent-apply-form' );
	if ( applyForm ) {
		applyForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			submitForm( applyForm, 'meeha_ajax_correspondent_apply', document.getElementById( 'meeha-correspondent-apply-msg' ) );
		} );
	}

	var editRequestForm = document.getElementById( 'meeha-edit-request-form' );
	if ( editRequestForm ) {
		editRequestForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			submitForm( editRequestForm, 'meeha_ajax_edit_request', document.getElementById( 'meeha-edit-request-msg' ), function () {
				editRequestForm.reset();
			} );
		} );
	}
} )();
