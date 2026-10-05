/**
 * Meeha News - account pages (login/register/my-account/lost-password)
 */
( function () {
	'use strict';

	if ( ! window.meehaAccountAjax ) {
		return;
	}

	function submitForm( form, action, msgEl, onSuccess ) {
		var button   = form.querySelector( '.meeha-account-form__submit' );
		var original = button ? button.textContent : '';
		if ( button ) {
			button.disabled = true;
			button.textContent = 'অপেক্ষা করুন...';
		}
		msgEl.textContent = '';
		msgEl.classList.remove( 'meeha-account-msg--error' );

		var data = new FormData( form );
		data.append( 'action', action );
		data.append( 'nonce', window.meehaAccountAjax.nonce );

		fetch( window.meehaAccountAjax.url, { method: 'POST', body: data } )
			.then( function ( res ) { return res.json(); } )
			.then( function ( res ) {
				msgEl.textContent = res.data && res.data.message ? res.data.message : '';
				if ( res.success ) {
					if ( onSuccess ) {
						onSuccess( res.data );
					}
					if ( res.data && res.data.redirect ) {
						setTimeout( function () { window.location.href = res.data.redirect; }, 800 );
					}
				} else {
					msgEl.classList.add( 'meeha-account-msg--error' );
				}
			} )
			.catch( function () {
				msgEl.textContent = 'সমস্যা হয়েছে, আবার চেষ্টা করুন।';
				msgEl.classList.add( 'meeha-account-msg--error' );
			} )
			.finally( function () {
				if ( button ) {
					button.disabled = false;
					button.textContent = original;
				}
			} );
	}

	var loginForm = document.getElementById( 'meeha-login-form' );
	var otpForm   = document.getElementById( 'meeha-otp-form' );

	function showOtpStep( data ) {
		if ( ! otpForm ) {
			return;
		}
		loginForm.hidden = true;
		otpForm.hidden = false;
		otpForm.querySelector( 'input[name="token"]' ).value = data.token || '';
		document.getElementById( 'meeha-otp-hint' ).textContent = data.message || '';
		document.getElementById( 'meeha-otp-msg' ).textContent = '';
		var codeInput = otpForm.querySelector( 'input[name="code"]' );
		codeInput.value = '';
		codeInput.focus();
	}

	function backToLogin( message ) {
		otpForm.hidden = true;
		loginForm.hidden = false;
		var msg = document.getElementById( 'meeha-login-msg' );
		msg.textContent = message || '';
		msg.classList.add( 'meeha-account-msg--error' );
	}

	function otpRequest( action, extra ) {
		var data = new FormData( otpForm );
		data.append( 'action', action );
		data.append( 'nonce', window.meehaAccountAjax.nonce );
		return fetch( window.meehaAccountAjax.url, { method: 'POST', body: data } )
			.then( function ( res ) { return res.json(); } );
	}

	if ( loginForm ) {
		loginForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			submitForm( loginForm, 'meeha_ajax_login', document.getElementById( 'meeha-login-msg' ), function ( data ) {
				if ( data && data.otp_required ) {
					showOtpStep( data );
				}
			} );
		} );
	}

	if ( otpForm ) {
		var otpMsg = document.getElementById( 'meeha-otp-msg' );
		otpForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var btn = otpForm.querySelector( '.meeha-account-form__submit' );
			btn.disabled = true;
			otpMsg.classList.remove( 'meeha-account-msg--error' );
			otpRequest( 'meeha_ajax_login_otp' )
				.then( function ( res ) {
					var d = res.data || {};
					if ( res.success ) {
						otpMsg.textContent = d.message || '';
						window.location.href = d.redirect || window.meehaAccountAjax.homeUrl;
						return;
					}
					if ( d.restart ) {
						backToLogin( d.message );
						return;
					}
					otpMsg.textContent = d.message || 'ওটিপি সঠিক নয়।';
					otpMsg.classList.add( 'meeha-account-msg--error' );
				} )
				.catch( function () {
					otpMsg.textContent = 'সমস্যা হয়েছে, আবার চেষ্টা করুন।';
					otpMsg.classList.add( 'meeha-account-msg--error' );
				} )
				.finally( function () { btn.disabled = false; } );
		} );

		document.getElementById( 'meeha-otp-resend' ).addEventListener( 'click', function ( e ) {
			e.preventDefault();
			otpMsg.classList.remove( 'meeha-account-msg--error' );
			otpRequest( 'meeha_ajax_login_otp_resend' ).then( function ( res ) {
				var d = res.data || {};
				if ( res.success ) {
					otpForm.querySelector( 'input[name="token"]' ).value = d.token || otpForm.querySelector( 'input[name="token"]' ).value;
					otpMsg.textContent = d.message || '';
				} else if ( d.restart ) {
					backToLogin( d.message );
				} else {
					otpMsg.textContent = d.message || '';
					otpMsg.classList.add( 'meeha-account-msg--error' );
				}
			} );
		} );
	}

	var registerForm = document.getElementById( 'meeha-register-form' );
	if ( registerForm ) {
		registerForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			submitForm( registerForm, 'meeha_ajax_register', document.getElementById( 'meeha-register-msg' ) );
		} );
	}

	var lostForm = document.getElementById( 'meeha-lost-password-form' );
	if ( lostForm ) {
		lostForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			submitForm( lostForm, 'meeha_ajax_lost_password', document.getElementById( 'meeha-lost-password-msg' ) );
		} );
	}

	var profileForm = document.getElementById( 'meeha-profile-form' );
	if ( profileForm ) {
		profileForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			submitForm( profileForm, 'meeha_ajax_profile_update', document.getElementById( 'meeha-profile-msg' ) );
		} );
	}

	var passwordForm = document.getElementById( 'meeha-password-form' );
	if ( passwordForm ) {
		passwordForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			submitForm( passwordForm, 'meeha_ajax_password_change', document.getElementById( 'meeha-password-msg' ) );
		} );
	}

	// avatar upload - clicking the preview image opens the hidden file input
	var avatarInput   = document.getElementById( 'meeha-avatar-input' );
	var avatarPreview = document.getElementById( 'meeha-account-avatar-preview' );
	if ( avatarInput ) {
		avatarInput.addEventListener( 'change', function () {
			if ( ! avatarInput.files || ! avatarInput.files[ 0 ] ) {
				return;
			}
			var form = document.getElementById( 'meeha-avatar-form' );
			var msgEl = document.getElementById( 'meeha-avatar-msg' );
			submitForm( form, 'meeha_ajax_avatar_upload', msgEl, function ( data ) {
				if ( avatarPreview && data.url ) {
					avatarPreview.src = data.url;
				}
			} );
		} );
	}
	if ( avatarPreview ) {
		avatarPreview.style.cursor = 'pointer';
		avatarPreview.addEventListener( 'click', function () {
			if ( avatarInput ) {
				avatarInput.click();
			}
		} );
	}

	// My Account tabs (profile / correspondent dashboard / security)
	var accountTabs = document.querySelectorAll( '[data-account-tab]' );
	accountTabs.forEach( function ( tab ) {
		tab.addEventListener( 'click', function () {
			var target = tab.getAttribute( 'data-account-tab' );
			accountTabs.forEach( function ( t ) { t.classList.remove( 'is-active' ); } );
			tab.classList.add( 'is-active' );
			document.querySelectorAll( '[data-account-panel]' ).forEach( function ( panel ) {
				panel.hidden = panel.getAttribute( 'data-account-panel' ) !== target;
			} );
		} );
	} );
} )();
