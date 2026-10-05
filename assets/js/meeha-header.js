/**
 * Meeha News — header interactions (mobile drawer, search panel, sticky shadow)
 * Vanilla JS, no dependencies - kept small like homepage.js.
 */
( function () {
	'use strict';

	var header  = document.querySelector( '[data-meeha-header]' );
	var overlay = document.querySelector( '[data-meeha-drawer-overlay]' );
	var drawer  = document.querySelector( '[data-meeha-drawer]' );
	var menuBtn = document.querySelector( '[data-meeha-menu-toggle]' );
	var drawerCloseBtn = document.querySelector( '[data-meeha-drawer-close]' );

	function openDrawer() {
		if ( ! drawer || ! overlay ) { return; }
		drawer.classList.add( 'is-open' );
		drawer.setAttribute( 'aria-hidden', 'false' );
		overlay.hidden = false;
		// add the class after reflow so the transition actually runs
		window.requestAnimationFrame( function () {
			overlay.classList.add( 'is-visible' );
		} );
		if ( menuBtn ) { menuBtn.setAttribute( 'aria-expanded', 'true' ); }
		document.body.style.overflow = 'hidden';
	}

	function closeDrawer() {
		if ( ! drawer || ! overlay ) { return; }
		drawer.classList.remove( 'is-open' );
		drawer.setAttribute( 'aria-hidden', 'true' );
		overlay.classList.remove( 'is-visible' );
		if ( menuBtn ) { menuBtn.setAttribute( 'aria-expanded', 'false' ); }
		document.body.style.overflow = '';
		window.setTimeout( function () {
			if ( ! drawer.classList.contains( 'is-open' ) ) {
				overlay.hidden = true;
			}
		}, 250 );
	}

	if ( menuBtn ) {
		menuBtn.addEventListener( 'click', openDrawer );
	}
	if ( drawerCloseBtn ) {
		drawerCloseBtn.addEventListener( 'click', closeDrawer );
	}
	if ( overlay ) {
		overlay.addEventListener( 'click', closeDrawer );
	}
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' ) {
			closeDrawer();
			closeSearch();
		}
	} );

	// search panel toggle
	var searchToggle = document.querySelector( '[data-meeha-search-toggle]' );
	var searchPanel  = document.querySelector( '[data-meeha-search-panel]' );
	var searchClose  = document.querySelector( '[data-meeha-search-close]' );
	var searchInput  = document.querySelector( '[data-meeha-search-input]' );

	function openSearch() {
		if ( ! searchPanel ) { return; }
		searchPanel.hidden = false;
		if ( searchToggle ) { searchToggle.setAttribute( 'aria-expanded', 'true' ); }
		if ( searchInput ) {
			window.setTimeout( function () { searchInput.focus(); }, 50 );
		}
	}

	function closeSearch() {
		if ( ! searchPanel ) { return; }
		searchPanel.hidden = true;
		if ( searchToggle ) { searchToggle.setAttribute( 'aria-expanded', 'false' ); }
	}

	if ( searchToggle && searchPanel ) {
		searchToggle.addEventListener( 'click', function () {
			if ( searchPanel.hidden ) {
				openSearch();
			} else {
				closeSearch();
			}
		} );
	}
	if ( searchClose ) {
		searchClose.addEventListener( 'click', closeSearch );
	}

	// Headline ticker pause/play toggle (explicit control for keyboard/touch
	// users, since hover-to-pause alone doesn't help them)
	var ticker = document.querySelector( '[data-meeha-ticker]' );
	if ( ticker ) {
		var tickerToggle = ticker.querySelector( '[data-meeha-ticker-toggle]' );
		if ( tickerToggle ) {
			tickerToggle.addEventListener( 'click', function () {
				var isPaused = ticker.classList.toggle( 'is-paused' );
				tickerToggle.setAttribute( 'aria-pressed', isPaused ? 'true' : 'false' );
				tickerToggle.setAttribute( 'aria-label', isPaused ? 'টিকার চালু করুন' : 'টিকার থামান' );
				tickerToggle.innerHTML = isPaused
					? '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M7 5v14l12-7z"></path></svg>'
					: '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="5" width="4" height="14" rx="1"></rect><rect x="14" y="5" width="4" height="14" rx="1"></rect></svg>';
			} );
		}
	}

	// subtle shadow on scroll while sticky, to read as "floating"
	if ( header && header.classList.contains( 'meeha-header--sticky' ) ) {
		var lastState = false;
		function onScroll() {
			var scrolled = window.scrollY > 8;
			if ( scrolled !== lastState ) {
				header.classList.toggle( 'is-scrolled', scrolled );
				lastState = scrolled;
			}
		}
		window.addEventListener( 'scroll', onScroll, { passive: true } );
		onScroll();
	}

	// ==========================================================
	// Desktop "More" - items beyond the configured count move into a
	// row/column grid panel, so the logo/search/account icons never get squeezed.
	// ==========================================================
	function setupDesktopMoreMenu() {
		var nav = document.querySelector( '.meeha-nav-menu--desktop, .meeha-nav-fallback--desktop' );
		if ( ! nav ) { return; }

		var limit = parseInt( nav.getAttribute( 'data-menu-limit' ), 10 );
		if ( ! limit || limit < 1 ) { return; }

		// store the original <li> list once (dropping any existing "More" item),
		// so repeated resizes don't lose items or duplicate them
		if ( ! nav.__meehaOriginalItems ) {
			nav.__meehaOriginalItems = Array.prototype.slice.call( nav.children ).filter( function ( li ) {
				return ! li.classList.contains( 'meeha-nav-more' );
			} );
		}

		var items = nav.__meehaOriginalItems;

		// remove any previously-built "More" item before rebuilding
		var existingMore = nav.querySelector( ':scope > .meeha-nav-more' );
		if ( existingMore ) {
			existingMore.remove();
		}
		// put all items back in their original place first
		items.forEach( function ( li ) {
			nav.appendChild( li );
		} );

		if ( items.length <= limit ) {
			return; // everything fits, no "More" needed
		}

		var overflowItems = items.slice( limit );

		var moreLi = document.createElement( 'li' );
		moreLi.className = 'menu-item meeha-nav-more';

		var toggle = document.createElement( 'button' );
		toggle.type = 'button';
		toggle.className = 'meeha-nav-more-toggle';
		toggle.setAttribute( 'aria-haspopup', 'true' );
		toggle.setAttribute( 'aria-expanded', 'false' );
		toggle.innerHTML = 'আরো দেখুন <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>';

		var panel = document.createElement( 'ul' );
		panel.className = 'meeha-nav-more-panel';
		overflowItems.forEach( function ( li ) {
			panel.appendChild( li );
		} );

		moreLi.appendChild( toggle );
		moreLi.appendChild( panel );
		nav.appendChild( moreLi );

		toggle.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			var isOpen = moreLi.classList.toggle( 'is-open' );
			toggle.setAttribute( 'aria-expanded', isOpen ? 'true' : 'false' );
		} );

		// click outside closes the panel
		document.addEventListener( 'click', function ( e ) {
			if ( moreLi.classList.contains( 'is-open' ) && ! moreLi.contains( e.target ) ) {
				moreLi.classList.remove( 'is-open' );
				toggle.setAttribute( 'aria-expanded', 'false' );
			}
		} );
	}

	// runs on load and only at desktop widths (mobile uses the drawer instead)
	function maybeSetupMoreMenu() {
		if ( window.innerWidth >= 992 ) {
			setupDesktopMoreMenu();
		}
	}
	maybeSetupMoreMenu();

	var resizeTimer;
	window.addEventListener( 'resize', function () {
		window.clearTimeout( resizeTimer );
		resizeTimer = window.setTimeout( maybeSetupMoreMenu, 200 );
	} );

	// submenu toggle button - drawer only (no hover on touch devices)
	var drawerNav = document.querySelector( '.meeha-drawer__nav' );
	if ( drawerNav ) {
		var parents = drawerNav.querySelectorAll( '.menu-item-has-children' );
		parents.forEach( function ( li ) {
			var link = li.querySelector( ':scope > a' );
			if ( ! link ) { return; }

			var toggle = document.createElement( 'button' );
			toggle.type = 'button';
			toggle.className = 'meeha-submenu-toggle';
			toggle.setAttribute( 'aria-expanded', 'false' );
			toggle.setAttribute( 'aria-label', 'সাব-মেনু খুলুন' );
			toggle.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>';

			link.insertAdjacentElement( 'afterend', toggle );

			toggle.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var isOpen = li.classList.toggle( 'is-open' );
				toggle.setAttribute( 'aria-expanded', isOpen ? 'true' : 'false' );
			} );
		} );
	}
} )();
