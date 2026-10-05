/**
 * Meeha News — "একনজরে" শর্টকোড পেজের ইন্টারঅ্যাকশন
 * ফিল্টার (কুইক-বাটন/তারিখ/ক্যাটাগরি/সার্চ), ইনফিনিট লোড-মোর, আর
 * ক্লিকে-এক্সপান্ড-হওয়া অ্যাকর্ডিয়ন — সবকিছু AJAX দিয়ে, পেজ রিলোড ছাড়াই।
 */
( function () {
	'use strict';

	var root = document.getElementById( 'meeha-glance' );
	if ( ! root || ! window.meehaGlanceAjax ) {
		return;
	}

	var listEl      = document.getElementById( 'meeha-glance-list' );
	var emptyEl     = document.getElementById( 'meeha-glance-empty' );
	var loadingEl   = document.getElementById( 'meeha-glance-loading' );
	var moreBtn     = document.getElementById( 'meeha-glance-more' );
	var dateInput   = document.getElementById( 'meeha-glance-date' );
	var categorySel = document.getElementById( 'meeha-glance-category' );
	var searchInput = document.getElementById( 'meeha-glance-search' );
	var quickBtns   = document.querySelectorAll( '.meeha-glance__quick-btn' );

	var state = {
		page:     1,
		range:    'all',
		date:     '',
		category: 0,
		search:   '',
	};

	var searchDebounce;

	function setLoading( isLoading ) {
		loadingEl.hidden = ! isLoading;
		moreBtn.disabled = isLoading;
	}

	function fetchPage( reset ) {
		setLoading( true );
		emptyEl.hidden = true;

		var data = new FormData();
		data.append( 'action', 'meeha_glance_fetch' );
		data.append( 'nonce', window.meehaGlanceAjax.nonce );
		data.append( 'page', state.page );
		data.append( 'range', state.range );
		data.append( 'date', state.date );
		data.append( 'category', state.category );
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
			.catch( function ( err ) {
				console.error( 'Meeha glance fetch error:', err );
			} )
			.finally( function () {
				setLoading( false );
			} );
	}

	function resetAndFetch() {
		state.page = 1;
		fetchPage( true );
	}

	// কুইক-ফিল্টার বাটন
	quickBtns.forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			quickBtns.forEach( function ( b ) { b.classList.remove( 'is-active' ); } );
			btn.classList.add( 'is-active' );
			state.range = btn.getAttribute( 'data-range' );
			state.date = '';
			dateInput.value = '';
			resetAndFetch();
		} );
	} );

	// নির্দিষ্ট তারিখ বাছাই করলে কুইক-ফিল্টার নিষ্ক্রিয় হয়ে যায়
	dateInput.addEventListener( 'change', function () {
		state.date = dateInput.value;
		if ( state.date ) {
			quickBtns.forEach( function ( b ) { b.classList.remove( 'is-active' ); } );
		}
		resetAndFetch();
	} );

	categorySel.addEventListener( 'change', function () {
		state.category = categorySel.value;
		resetAndFetch();
	} );

	searchInput.addEventListener( 'input', function () {
		clearTimeout( searchDebounce );
		searchDebounce = setTimeout( function () {
			state.search = searchInput.value.trim();
			resetAndFetch();
		}, 350 );
	} );

	moreBtn.addEventListener( 'click', function () {
		state.page += 1;
		fetchPage( false );
	} );

	// অ্যাকর্ডিয়ন — ক্লিক করলে নিচে এক্সপান্ড হবে (পপ-আপ না), একসাথে একাধিক খোলা থাকতে পারে
	listEl.addEventListener( 'click', function ( e ) {
		var head = e.target.closest( '.meeha-glance__item-head' );
		if ( ! head ) {
			return;
		}
		var item = head.closest( '.meeha-glance__item' );
		var isOpen = item.classList.toggle( 'is-open' );
		head.setAttribute( 'aria-expanded', isOpen ? 'true' : 'false' );
	} );

	// প্রথম লোড
	fetchPage( true );
} )();
