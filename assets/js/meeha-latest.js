/**
 * "সর্বশেষ" page - homepage-style hero+grid at top (auto-refreshed),
 * offset-based load more below, and a category tab explorer.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'meeha-latest-page' );
	if ( ! root || ! window.meehaGlanceAjax ) {
		return;
	}

	var topWrap    = document.getElementById( 'meeha-latest-top' );
	var moreWrap   = document.getElementById( 'meeha-latest-more-wrap' );
	var moreBtn    = document.getElementById( 'meeha-latest-more' );
	var loadingEl  = document.getElementById( 'meeha-latest-loading' );
	var tabsWrap   = document.getElementById( 'meeha-latest-cats-tabs' );
	var tabsGrid   = document.getElementById( 'meeha-latest-cats-grid' );

	var INITIAL_TOP_COUNT = 13; // hero (5) + first grid batch (8), keep in sync with PHP defaults
	var loadedOffset = INITIAL_TOP_COUNT;
	var refreshSeconds = parseInt( root.getAttribute( 'data-refresh-seconds' ), 10 ) || 30;

	// periodic silent refresh of the top hero+grid block only - never touches
	// the load-more list below, so scroll position and loaded items are safe
	function refreshTop() {
		var data = new FormData();
		data.append( 'action', 'meeha_latest_top_refresh' );
		data.append( 'nonce', window.meehaGlanceAjax.nonce );

		fetch( window.meehaGlanceAjax.url, { method: 'POST', body: data } )
			.then( function ( res ) { return res.json(); } )
			.then( function ( res ) {
				if ( res.success && topWrap ) {
					topWrap.innerHTML = res.data.html;
				}
			} )
			.catch( function () {} );
	}
	setInterval( refreshTop, refreshSeconds * 1000 );

	// "আরও দেখুন" - offset-based, appends below the auto-refreshed top block
	if ( moreBtn ) {
		moreBtn.addEventListener( 'click', function () {
			loadingEl.hidden = false;
			moreBtn.disabled = true;

			var data = new FormData();
			data.append( 'action', 'meeha_latest_load_more' );
			data.append( 'nonce', window.meehaGlanceAjax.nonce );
			data.append( 'offset', loadedOffset );

			fetch( window.meehaGlanceAjax.url, { method: 'POST', body: data } )
				.then( function ( res ) { return res.json(); } )
				.then( function ( res ) {
					if ( ! res.success ) {
						return;
					}
					moreWrap.insertAdjacentHTML( 'beforeend', res.data.html );
					loadedOffset += 8;
					moreBtn.hidden = ! res.data.has_more;
				} )
				.catch( function ( err ) { console.error( 'Meeha latest load-more error:', err ); } )
				.finally( function () {
					loadingEl.hidden = true;
					moreBtn.disabled = false;
				} );
		} );
	}

	// category tab explorer
	if ( tabsWrap && tabsGrid ) {
		tabsWrap.addEventListener( 'click', function ( e ) {
			var tab = e.target.closest( '.meeha-latest-cats__tab' );
			if ( ! tab ) {
				return;
			}
			tabsWrap.querySelectorAll( '.meeha-latest-cats__tab' ).forEach( function ( t ) {
				t.classList.remove( 'is-active' );
			} );
			tab.classList.add( 'is-active' );

			var data = new FormData();
			data.append( 'action', 'meeha_latest_category_tab' );
			data.append( 'nonce', window.meehaGlanceAjax.nonce );
			data.append( 'category', tab.getAttribute( 'data-category' ) );

			fetch( window.meehaGlanceAjax.url, { method: 'POST', body: data } )
				.then( function ( res ) { return res.json(); } )
				.then( function ( res ) {
					if ( res.success ) {
						tabsGrid.innerHTML = res.data.html;
					}
				} )
				.catch( function ( err ) { console.error( 'Meeha latest category tab error:', err ); } );
		} );
	}
} )();
