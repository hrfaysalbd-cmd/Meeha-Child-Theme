/**
 * Quote archive page filter bar - search + quote-type, infinite load more.
 */
( function () {
	'use strict';

	var listEl    = document.getElementById( 'meeha-qa-list' );
	var emptyEl   = document.getElementById( 'meeha-qa-empty' );
	var loadingEl = document.getElementById( 'meeha-qa-loading' );
	var moreBtn   = document.getElementById( 'meeha-qa-more' );
	var typeSel   = document.getElementById( 'meeha-qa-type' );
	var searchEl  = document.getElementById( 'meeha-qa-search' );

	if ( ! listEl || ! window.meehaGlanceAjax ) {
		return;
	}

	var state = { page: 1, type: 0, search: '' };
	var debounce;

	function setLoading( isLoading ) {
		loadingEl.hidden = ! isLoading;
		moreBtn.disabled = isLoading;
	}

	function fetchPage( reset ) {
		setLoading( true );
		emptyEl.hidden = true;

		var data = new FormData();
		data.append( 'action', 'meeha_quote_archive_fetch' );
		data.append( 'nonce', window.meehaGlanceAjax.nonce );
		data.append( 'page', state.page );
		data.append( 'type', state.type );
		data.append( 'search', state.search );

		fetch( window.meehaGlanceAjax.url, { method: 'POST', body: data } )
			.then( function ( res ) { return res.json(); } )
			.then( function ( res ) {
				if ( ! res.success ) {
					return;
				}
				if ( reset ) {
					listEl.innerHTML = res.data.html;
				} else {
					listEl.insertAdjacentHTML( 'beforeend', res.data.html );
				}
				moreBtn.hidden = ! res.data.has_more;
				if ( reset && 0 === res.data.found ) {
					emptyEl.hidden = false;
				}
			} )
			.catch( function ( err ) { console.error( 'Meeha quote archive error:', err ); } )
			.finally( function () { setLoading( false ); } );
	}

	function resetAndFetch() {
		state.page = 1;
		fetchPage( true );
	}

	typeSel.addEventListener( 'change', function () {
		state.type = typeSel.value;
		resetAndFetch();
	} );

	searchEl.addEventListener( 'input', function () {
		clearTimeout( debounce );
		debounce = setTimeout( function () {
			state.search = searchEl.value.trim();
			resetAndFetch();
		}, 350 );
	} );

	moreBtn.addEventListener( 'click', function () {
		state.page += 1;
		fetchPage( false );
	} );

	fetchPage( true );
} )();
