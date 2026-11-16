/**
 * Meeha — [meeha_at_a_glance] enhancement. The page is fully server-rendered (filters are a
 * GET form, "আরও দেখুন" is a link); this script swaps results in place via
 * GET meeha/v1/glance (public, cached) and keeps the URL shareable with history.replaceState.
 */
( function () {
	'use strict';

	var root = document.querySelector( '[data-glance]' );
	if ( ! root || ! window.fetch || ! window.URLSearchParams ) {
		return;
	}
	var form = root.querySelector( '[data-glance-form]' );
	var list = root.querySelector( '[data-glance-list]' );
	var status = root.querySelector( '[data-glance-status]' );
	var moreWrap = root.querySelector( '[data-glance-morewrap]' );
	var more = root.querySelector( '[data-glance-more]' );
	var endpoint = root.getAttribute( 'data-endpoint' );
	var base = root.getAttribute( 'data-base' );
	var perPage = root.getAttribute( 'data-per-page' ) || '12';
	var page = parseInt( root.getAttribute( 'data-page' ), 10 ) || 1;
	var ctrl = null;

	root.querySelectorAll( '[data-js-hide]' ).forEach( function ( el ) {
		el.style.display = 'none';
	} );

	function filters() {
		var fd = new FormData( form );
		return {
			range: fd.get( 'gr' ) || 'all',
			date: fd.get( 'gd' ) || '',
			cat: fd.get( 'gc' ) || '',
			q: ( fd.get( 'gq' ) || '' ).trim()
		};
	}

	function pageUrl( f, p ) {
		var u = new URL( base, window.location.href );
		if ( f.range && f.range !== 'all' ) {
			u.searchParams.set( 'gr', f.range );
		}
		if ( f.date ) {
			u.searchParams.set( 'gd', f.date );
		}
		if ( f.cat ) {
			u.searchParams.set( 'gc', f.cat );
		}
		if ( f.q ) {
			u.searchParams.set( 'gq', f.q );
		}
		if ( p > 1 ) {
			u.searchParams.set( 'gp', p );
		}
		return u.toString();
	}

	function request( f, p ) {
		if ( ctrl ) {
			ctrl.abort();
		}
		ctrl = window.AbortController ? new AbortController() : null;
		var q = new URLSearchParams( { range: f.range, date: f.date, cat: f.cat, q: f.q, page: p, per_page: perPage } );
		return fetch( endpoint + ( endpoint.indexOf( '?' ) > -1 ? '&' : '?' ) + q.toString(), {
			credentials: 'omit',
			signal: ctrl ? ctrl.signal : undefined
		} ).then( function ( r ) {
			if ( ! r.ok ) {
				throw new Error( 'HTTP ' + r.status );
			}
			return r.json();
		} );
	}

	function setMore( hasMore, f, p ) {
		if ( ! moreWrap ) {
			return;
		}
		moreWrap.hidden = ! hasMore;
		moreWrap.style.display = hasMore ? '' : 'none';
		if ( more ) {
			more.href = pageUrl( f, p + 1 );
		}
	}

	function replace() {
		var f = filters();
		root.classList.add( 'is-loading' );
		request( f, 1 ).then( function ( d ) {
			page = 1;
			list.innerHTML = d.html || '';
			status.textContent = d.status || '';
			var empty = root.querySelector( '[data-glance-empty]' );
			if ( empty ) {
				empty.remove();
			}
			setMore( !! d.has_more, f, 1 );
			if ( window.history && history.replaceState ) {
				history.replaceState( null, '', pageUrl( f, 1 ) );
			}
		} ).catch( function ( e ) {
			if ( e && e.name === 'AbortError' ) {
				return;
			}
			status.textContent = 'লোড করা যায়নি, আবার চেষ্টা করুন।';
		} ).then( function () {
			root.classList.remove( 'is-loading' );
		} );
	}

	/** Append day groups, merging into the last group when the day repeats. */
	function append( html ) {
		var tmp = document.createElement( 'div' );
		tmp.innerHTML = html;
		var groups = Array.prototype.slice.call( tmp.querySelectorAll( '.m-gl__day' ) );
		groups.forEach( function ( g, i ) {
			var last = list.querySelector( '.m-gl__day:last-of-type' );
			if ( i === 0 && last && last.getAttribute( 'data-day' ) === g.getAttribute( 'data-day' ) ) {
				var ol = last.querySelector( '.m-gl__items' );
				Array.prototype.slice.call( g.querySelectorAll( '.m-gl__item' ) ).forEach( function ( li ) {
					ol.appendChild( li );
				} );
			} else {
				list.appendChild( g );
			}
		} );
	}

	if ( more ) {
		more.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var f = filters();
			more.setAttribute( 'aria-busy', 'true' );
			more.textContent = 'লোড হচ্ছে…';
			request( f, page + 1 ).then( function ( d ) {
				page += 1;
				append( d.html || '' );
				setMore( !! d.has_more, f, page );
			} ).catch( function () {} ).then( function () {
				more.removeAttribute( 'aria-busy' );
				more.textContent = 'আরও দেখুন';
			} );
		} );
	}

	var t = null;
	form.addEventListener( 'change', function ( e ) {
		if ( e.target.name === 'gd' && e.target.value ) {
			var all = form.querySelector( 'input[name="gr"][value="all"]' );
			if ( all ) {
				all.checked = true; // a specific date overrides the quick range
			}
		}
		if ( e.target.name === 'gr' ) {
			var d = form.querySelector( 'input[name="gd"]' );
			if ( d && e.target.value !== 'all' ) {
				d.value = '';
			}
		}
		if ( e.target.name !== 'gq' ) {
			replace();
		}
	} );
	form.addEventListener( 'input', function ( e ) {
		if ( e.target.name !== 'gq' ) {
			return;
		}
		clearTimeout( t );
		t = setTimeout( replace, 450 );
	} );
	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		clearTimeout( t );
		replace();
	} );
}() );
