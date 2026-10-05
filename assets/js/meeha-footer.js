( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {

		/* স্ক্রল-টু-টপ বাটন */
		var topBtn = document.getElementById( 'meeha-scroll-top' );
		if ( topBtn ) {
			window.addEventListener( 'scroll', function () {
				if ( window.scrollY > 400 ) {
					topBtn.classList.add( 'is-visible' );
				} else {
					topBtn.classList.remove( 'is-visible' );
				}
			}, { passive: true } );

			topBtn.addEventListener( 'click', function () {
				window.scrollTo( { top: 0, behavior: 'smooth' } );
			} );
		}

		/* মোবাইলে কুইক-লিংক কলাম অ্যাকর্ডিয়ন */
		document.querySelectorAll( '[data-meeha-footer-toggle]' ).forEach( function ( title ) {
			title.addEventListener( 'click', function () {
				if ( window.innerWidth >= 640 ) {
					return; // ডেক্সটপ/ট্যাবলেটে সবসময় খোলা থাকে, টগলের দরকার নেই
				}
				title.closest( '.meeha-footer__col' ).classList.toggle( 'is-open' );
			} );
		} );

		/* নিউজলেটার ফর্ম (MailPoet কনফিগার করা না থাকলে সাধারণ AJAX ফলব্যাক -
		   হোমপেজের নিউজলেটার ফর্মের সাথে হুবহু একই AJAX action পুনর্ব্যবহার
		   করা হচ্ছে, যাতে দুই জায়গায় দুই রকম সাবস্ক্রাইব-লজিক না থাকে) */
		var form = document.getElementById( 'meeha-footer-newsletter-form' );
		var msg  = document.getElementById( 'meeha-footer-newsletter-msg' );
		if ( form && msg && window.meehaAjax ) {
			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var emailInput = form.querySelector( 'input[type="email"]' );
				var btn = form.querySelector( 'button' );
				btn.disabled = true;
				var data = new FormData();
				data.append( 'action', 'meeha_newsletter_signup' );
				data.append( 'nonce', window.meehaAjax.nonce );
				data.append( 'email', emailInput.value );
				fetch( window.meehaAjax.url, { method: 'POST', body: data } )
					.then( function ( res ) { return res.json(); } )
					.then( function ( res ) {
						msg.textContent = ( res.data && res.data.message ) ? res.data.message : '';
						if ( res.success ) {
							form.reset();
						}
					} )
					.catch( function () {
						msg.textContent = 'সমস্যা হয়েছে, আবার চেষ্টা করুন।';
					} )
					.finally( function () { btn.disabled = false; } );
			} );
		}
	} );
} )();
