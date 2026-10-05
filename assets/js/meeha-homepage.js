/**
 * Meeha News — হোমপেজের ছোট ইন্টারঅ্যাকশন
 * ইচ্ছাকৃতভাবে খুব ছোট রাখা হয়েছে — ভারী JS ফ্রেমওয়ার্কের দরকার নেই।
 */
( function () {
	'use strict';

	// মোবাইলে :hover নেই, তাই টাচ করলেও টিকার থামুক
	var ticker = document.querySelector( '.meeha-ticker' );
	if ( ticker ) {
		var track = ticker.querySelector( '.meeha-ticker__list' );
		ticker.addEventListener( 'touchstart', function () {
			if ( track ) {
				track.style.animationPlayState = 'paused';
			}
		}, { passive: true } );
	}

	// নিউজলেটার ফর্ম — সরাসরি AJAX দিয়ে সাবমিট হয়, পেজ রিলোড হয় না
	var form = document.getElementById( 'meeha-newsletter-form' );
	var msg  = document.getElementById( 'meeha-newsletter-msg' );

	if ( form && msg && window.meehaAjax ) {
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();

			var emailInput = form.querySelector( 'input[type="email"]' );
			var button = form.querySelector( 'button[type="submit"]' );
			var originalLabel = button.textContent;

			button.disabled = true;
			button.textContent = 'পাঠানো হচ্ছে...';

			var data = new FormData();
			data.append( 'action', 'meeha_newsletter_signup' );
			data.append( 'nonce', window.meehaAjax.nonce );
			data.append( 'email', emailInput.value );

			fetch( window.meehaAjax.url, {
				method: 'POST',
				body: data,
			} )
				.then( function ( res ) {
					// সার্ভার কোনো কারণে JSON না দিয়ে অন্য কিছু (PHP এরর/ওয়ার্নিং টেক্সট, HTML এরর পেজ)
					// ফেরত দিলে .json() ছাড়া আগে টেক্সট হিসেবে পড়ে ডিবাগ করা সহজ হয়
					return res.text().then( function ( text ) {
						try {
							return JSON.parse( text );
						} catch ( parseErr ) {
							console.error( 'Meeha newsletter: অপ্রত্যাশিত সার্ভার রেসপন্স —', text );
							throw new Error( 'invalid-json' );
						}
					} );
				} )
				.then( function ( res ) {
					msg.textContent = res.data && res.data.message ? res.data.message : '';
					msg.classList.toggle( 'meeha-newsletter__msg--error', ! res.success );
					if ( res.success ) {
						form.reset();
					}
				} )
				.catch( function ( err ) {
					console.error( 'Meeha newsletter error:', err );
					msg.textContent = 'সমস্যা হয়েছে, আবার চেষ্টা করুন।';
					msg.classList.add( 'meeha-newsletter__msg--error' );
				} )
				.finally( function () {
					button.disabled = false;
					button.textContent = originalLabel;
				} );
		} );
	}
} )();