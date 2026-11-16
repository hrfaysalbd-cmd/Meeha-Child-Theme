/**
 * Meeha — [meeha_latest_feed] enhancement.
 *  - Polls GET meeha/v1/latest?part=top at most every 60 s and ONLY while the tab is visible
 *    (Page Visibility API); new stories show a "নতুন খবর" button — the list is never replaced
 *    under the reader's eyes.
 *  - "আরও দেখুন" and the category chips load in place (links work without JS).
 */
( function () {
	'use strict';

	var root = document.querySelector( '[data-latest]' );
	if ( ! root || ! window.fetch ) {
		return;
	}
	var endpoint = root.getAttribute( 'data-endpoint' );
	var interval = Math.max( 60, parseInt( root.getAttribute( 'data-interval' ), 10 ) || 60 ) * 1000;
	var top = root.querySelector( '[data-latest-top]' );
	var fresh = root.querySelector( '[data-latest-fresh]' );
	var freshText = root.querySelector( '[data-latest-fresh-text]' );
	var moreList = root.querySelector( '[data-latest-more]' );
	var moreBtn = root.querySelector( '[data-latest-loadmore]' );
	var catList = root.querySelector( '[data-latest-cat]' );
	var catLink = root.querySelector( '[data-latest-catlink]' );
	var offset = parseInt( root.getAttribute( 'data-offset' ), 10 ) || 13;
	var known = ( top.getAttribute( 'data-ids' ) || '' ).split( ',' ).filter( Boolean );
	var pending = null;
	var timer = null;
	var lastPoll = Date.now();
	var BN = [ '০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯' ];

	function bn( n ) {
		return String( n ).replace( /[0-9]/g, function ( d ) { return BN[ +d ]; } );
	}
	function get( params ) {
		var q = new URLSearchParams( params ).toString();
		return fetch( endpoint + ( endpoint.indexOf( '?' ) > -1 ? '&' : '?' ) + q, { credentials: 'omit' } ).then( function ( r ) {
			if ( ! r.ok ) {
				throw new Error( 'HTTP ' + r.status );
			}
			return r.json();
		} );
	}

	/* ---------- polling (visible tab only, ≥ 60 s) ---------- */
	function poll() {
		lastPoll = Date.now();
		get( { part: 'top', _: Math.floor( Date.now() / 60000 ) } ).then( function ( d ) {
			var ids = ( d.ids || [] ).map( String );
			var newer = ids.filter( function ( id ) { return known.indexOf( id ) < 0; } );
			if ( newer.length && ids[ 0 ] !== known[ 0 ] ) {
				pending = d;
				freshText.textContent = 'নতুন ' + bn( newer.length ) + 'টি খবর — দেখুন';
				fresh.hidden = false;
			}
		} ).catch( function () {} );
	}
	function schedule() {
		clearTimeout( timer );
		if ( document.visibilityState !== 'visible' ) {
			return;
		}
		var wait = Math.max( 0, interval - ( Date.now() - lastPoll ) );
		timer = setTimeout( function () {
			poll();
			schedule();
		}, wait );
	}
	document.addEventListener( 'visibilitychange', schedule );
	schedule();

	fresh.addEventListener( 'click', function () {
		if ( ! pending ) {
			return;
		}
		top.innerHTML = pending.html;
		known = ( pending.ids || [] ).map( String );
		top.setAttribute( 'data-ids', known.join( ',' ) );
		pending = null;
		fresh.hidden = true;
		var first = top.querySelector( 'a' );
		top.scrollIntoView( { block: 'start', behavior: 'smooth' } );
		if ( first ) {
			first.focus( { preventScroll: true } );
		}
	} );

	/* ---------- load more ---------- */
	if ( moreBtn ) {
		moreBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			moreBtn.setAttribute( 'aria-busy', 'true' );
			moreBtn.textContent = 'লোড হচ্ছে…';
			get( { part: 'more', offset: offset } ).then( function ( d ) {
				moreList.insertAdjacentHTML( 'beforeend', d.html || '' );
				offset = d.next_offset || offset;
				if ( ! d.has_more ) {
					moreBtn.parentNode.style.display = 'none';
				}
			} ).catch( function () {} ).then( function () {
				moreBtn.removeAttribute( 'aria-busy' );
				moreBtn.textContent = 'আরও দেখুন';
			} );
		} );
	}

	/* ---------- category chips ---------- */
	root.querySelectorAll( '[data-cat]' ).forEach( function ( chip ) {
		chip.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			root.querySelectorAll( '[data-cat]' ).forEach( function ( c ) {
				c.removeAttribute( 'aria-current' );
			} );
			chip.setAttribute( 'aria-current', 'true' );
			catList.style.opacity = '.55';
			get( { part: 'cat', cat: chip.getAttribute( 'data-cat' ) } ).then( function ( d ) {
				catList.innerHTML = d.html || '';
				if ( catLink && d.link ) {
					catLink.href = d.link;
				}
			} ).catch( function () {} ).then( function () {
				catList.style.opacity = '';
			} );
		} );
	} );
}() );
