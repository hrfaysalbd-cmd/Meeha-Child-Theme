/**
 * Category page - "আরও পড়ুন" offset-based load more
 */
( function () {
	'use strict';

	var moreBtn   = document.getElementById( 'meeha-category-more' );
	var grid      = document.getElementById( 'meeha-category-grid' );
	var loadingEl = document.getElementById( 'meeha-category-loading' );

	if ( ! moreBtn || ! grid || ! window.meehaPollAjax ) {
		return;
	}

	var offset = parseInt( moreBtn.getAttribute( 'data-offset' ), 10 ) || 10;
	var catId  = moreBtn.getAttribute( 'data-category' );

	moreBtn.addEventListener( 'click', function () {
		loadingEl.hidden = false;
		moreBtn.disabled = true;

		var data = new FormData();
		data.append( 'action', 'meeha_category_load_more' );
		data.append( 'nonce', window.meehaPollAjax.nonce );
		data.append( 'category', catId );
		data.append( 'offset', offset );

		fetch( window.meehaPollAjax.url, { method: 'POST', body: data } )
			.then( function ( res ) { return res.json(); } )
			.then( function ( res ) {
				if ( ! res.success ) {
					return;
				}
				grid.insertAdjacentHTML( 'beforeend', res.data.html );
				offset += 10;
				moreBtn.hidden = ! res.data.has_more;
			} )
			.catch( function ( err ) { console.error( 'Meeha category load-more error:', err ); } )
			.finally( function () {
				loadingEl.hidden = true;
				moreBtn.disabled = false;
			} );
	} );
} )();
