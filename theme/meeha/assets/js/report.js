/**
 * Meeha — reader report dialog. The <dialog> is built lazily from #meeha-report-tpl on the
 * first click, after its CSS (data-css) has loaded. Submits multipart to REST meeha/v1/report.
 */
( function () {
	'use strict';

	var dialog = null;
	var cssPromise = null;
	var opener = null;

	function loadCss( href ) {
		if ( cssPromise ) {
			return cssPromise;
		}
		cssPromise = new Promise( function ( resolve ) {
			if ( ! href || document.querySelector( 'link[data-meeha-report-css]' ) ) {
				resolve();
				return;
			}
			var l = document.createElement( 'link' );
			l.rel = 'stylesheet';
			l.href = href;
			l.setAttribute( 'data-meeha-report-css', '' );
			l.onload = resolve;
			l.onerror = resolve;
			document.head.appendChild( l );
			setTimeout( resolve, 1500 );
		} );
		return cssPromise;
	}

	function say( el, text, type ) {
		el.textContent = text || '';
		el.classList.toggle( 'is-error', type === 'error' );
		el.classList.toggle( 'is-ok', type === 'ok' );
	}

	function build( btn ) {
		var tpl = document.getElementById( 'meeha-report-tpl' );
		if ( ! tpl ) {
			return null;
		}
		var frag = tpl.content.cloneNode( true );
		var dlg = frag.querySelector( 'dialog' );
		document.body.appendChild( frag );
		var form = dlg.querySelector( 'form' );
		var msg = dlg.querySelector( '.m-report__msg' );
		var fileInput = dlg.querySelector( 'input[type="file"]' );
		var fileLabel = dlg.querySelector( '[data-file-label]' );
		var fileText = fileLabel ? fileLabel.textContent : '';

		dlg.querySelectorAll( '[data-close]' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () { dlg.close(); } );
		} );
		dlg.addEventListener( 'click', function ( e ) {
			if ( e.target === dlg ) {
				dlg.close(); // backdrop click
			}
		} );
		dlg.addEventListener( 'close', function () {
			document.documentElement.classList.remove( 'm-noscroll' );
			if ( opener ) {
				opener.focus();
			}
		} );

		if ( fileInput ) {
			fileInput.addEventListener( 'change', function () {
				var f = fileInput.files && fileInput.files[ 0 ];
				if ( ! f ) {
					fileLabel.textContent = fileText;
					return;
				}
				if ( [ 'image/jpeg', 'image/png', 'image/webp' ].indexOf( f.type ) < 0 || f.size > 3 * 1024 * 1024 ) {
					say( msg, 'প্রমাণ হিসেবে শুধু JPG, PNG বা WebP ছবি (সর্বোচ্চ ৩ MB) দেওয়া যাবে।', 'error' );
					fileInput.value = '';
					fileLabel.textContent = fileText;
					return;
				}
				say( msg, '' );
				fileLabel.textContent = f.name;
			} );
		}

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			if ( ! form.checkValidity() ) {
				form.classList.add( 'was-validated' );
				var bad = form.querySelector( ':invalid' );
				if ( bad ) {
					bad.focus();
				}
				say( msg, 'রিপোর্টের ধরন, নাম, ফোন ও বক্তব্য দিন।', 'error' );
				return;
			}
			var submit = form.querySelector( '[type="submit"]' );
			submit.disabled = true;
			submit.textContent = 'পাঠানো হচ্ছে…';
			say( msg, '' );
			fetch( dlg.getAttribute( 'data-endpoint' ), { method: 'POST', credentials: 'same-origin', body: new FormData( form ) } )
				.then( function ( r ) {
					return r.json().catch( function () { return { success: false, data: {} }; } );
				} )
				.then( function ( res ) {
					var d = res.data || {};
					if ( res.success ) {
						form.classList.add( 'is-done' );
						say( msg, d.message || 'ধন্যবাদ।', 'ok' );
						form.querySelectorAll( 'input, select, textarea' ).forEach( function ( el ) { el.disabled = true; } );
						submit.textContent = 'জমা হয়েছে';
						return;
					}
					say( msg, d.message || 'রিপোর্ট জমা করা যায়নি।', 'error' );
					submit.disabled = false;
					submit.textContent = 'রিপোর্ট জমা দিন';
				} )
				.catch( function () {
					say( msg, 'সংযোগে সমস্যা হয়েছে, আবার চেষ্টা করুন।', 'error' );
					submit.disabled = false;
					submit.textContent = 'রিপোর্ট জমা দিন';
				} );
		} );
		return dlg;
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest && e.target.closest( '[data-meeha-report]' );
		if ( ! btn ) {
			return;
		}
		e.preventDefault();
		opener = btn;
		loadCss( btn.getAttribute( 'data-css' ) ).then( function () {
			if ( ! dialog ) {
				dialog = build( btn );
			}
			if ( ! dialog ) {
				return;
			}
			var form = dialog.querySelector( 'form' );
			if ( form.classList.contains( 'is-done' ) && form.elements.post_id.value !== btn.getAttribute( 'data-meeha-report' ) ) {
				dialog.remove();
				dialog = build( btn );
				form = dialog.querySelector( 'form' );
			}
			form.elements.post_id.value = btn.getAttribute( 'data-meeha-report' );
			dialog.setAttribute( 'data-endpoint', btn.getAttribute( 'data-endpoint' ) );
			if ( typeof dialog.showModal === 'function' ) {
				dialog.showModal();
			} else {
				dialog.setAttribute( 'open', '' );
			}
			document.documentElement.classList.add( 'm-noscroll' );
			var first = dialog.querySelector( 'input[name="report_type"]' );
			if ( first ) {
				first.focus();
			}
		} );
	} );
}() );
