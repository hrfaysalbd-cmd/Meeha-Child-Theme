( function () {
	document.addEventListener( 'DOMContentLoaded', function () {
		var wrap = document.getElementById( 'meeha-mostread-filters' );
		var grid = document.getElementById( 'meeha-most-read-grid' );
		if ( ! wrap || ! grid || ! window.meehaMostReadAjax ) {
			return;
		}

		wrap.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.meeha-mostread-filter' );
			if ( ! btn || btn.classList.contains( 'is-active' ) ) {
				return;
			}

			wrap.querySelectorAll( '.meeha-mostread-filter' ).forEach( function ( b ) {
				b.classList.remove( 'is-active' );
			} );
			btn.classList.add( 'is-active' );

			grid.style.opacity = '0.5';

			var body = new URLSearchParams();
			body.set( 'action', 'meeha_mostread_filter' );
			body.set( 'nonce', window.meehaMostReadAjax.nonce );
			body.set( 'range', btn.getAttribute( 'data-range' ) );

			fetch( window.meehaMostReadAjax.url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} )
				.then( function ( res ) { return res.json(); } )
				.then( function ( data ) {
					if ( data && data.success && data.data && typeof data.data.html === 'string' ) {
						grid.innerHTML = data.data.html;
					}
				} )
				.catch( function () {
					/* silently keep the previous grid on network error */
				} )
				.finally( function () {
					grid.style.opacity = '1';
				} );
		} );
	} );
} )();
