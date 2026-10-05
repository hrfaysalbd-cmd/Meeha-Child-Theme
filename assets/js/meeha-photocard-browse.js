/**
 * Meeha News — photocard browse: tabs, search, load more (AJAX).
 */
( function () {
	'use strict';

	var root = document.getElementById( 'meeha-pc-browse' );
	if ( ! root || ! window.meehaPcBrowseAjax ) {
		return;
	}

	var grid       = document.getElementById( 'meeha-pc-grid' );
	var emptyMsg   = document.getElementById( 'meeha-pc-empty' );
	var moreBtn    = document.getElementById( 'meeha-pc-more' );
	var searchInput = document.getElementById( 'meeha-pc-search-input' );
	var tabs       = root.querySelectorAll( '.meeha-pc-tab' );
	var count      = parseInt( root.getAttribute( 'data-count' ), 10 ) || 12;

	var state = { tab: 'all', search: '', offset: 0 };

	function setLoading( isLoading ) {
		grid.style.opacity = isLoading ? '0.5' : '1';
	}

	function fetchBatch( append ) {
		setLoading( true );
		var data = new FormData();
		data.append( 'action', 'meeha_pc_browse' );
		data.append( 'nonce', window.meehaPcBrowseAjax.nonce );
		data.append( 'tab', state.tab );
		data.append( 'search', state.search );
		data.append( 'offset', state.offset );
		data.append( 'count', count );

		fetch( window.meehaPcBrowseAjax.url, { method: 'POST', body: data } )
			.then( function ( res ) { return res.json(); } )
			.then( function ( res ) {
				if ( ! res.success ) {
					return;
				}
				if ( append ) {
					grid.insertAdjacentHTML( 'beforeend', res.data.html );
				} else {
					grid.innerHTML = res.data.html;
				}
				state.offset += res.data.count;
				moreBtn.hidden = ! res.data.has_more;
				emptyMsg.hidden = ! ( ! append && res.data.count === 0 );
			} )
			.finally( function () {
				setLoading( false );
			} );
	}

	tabs.forEach( function ( tab ) {
		tab.addEventListener( 'click', function () {
			tabs.forEach( function ( t ) { t.classList.remove( 'is-active' ); } );
			tab.classList.add( 'is-active' );
			state.tab    = tab.getAttribute( 'data-tab' );
			state.offset = 0;
			fetchBatch( false );
		} );
	} );

	if ( moreBtn ) {
		moreBtn.addEventListener( 'click', function () {
			fetchBatch( true );
		} );
	}

	if ( searchInput ) {
		var debounceTimer;
		searchInput.addEventListener( 'input', function () {
			window.clearTimeout( debounceTimer );
			debounceTimer = window.setTimeout( function () {
				state.search = searchInput.value.trim();
				state.offset = 0;
				fetchBatch( false );
			}, 350 );
		} );
	}
} )();
