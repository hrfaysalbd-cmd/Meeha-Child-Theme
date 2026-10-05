/**
 * Meeha News — homepage tabbed sidebar (জনপ্রিয়/ট্রেন্ড/নতুন) tab switching (AJAX)
 */
( function () {
	'use strict';

	var tabsBar = document.getElementById( 'meeha-tabbed-tabs' );
	var list    = document.getElementById( 'meeha-tabbed-list' );
	if ( ! tabsBar || ! list || ! window.meehaTabbedAjax ) {
		return;
	}

	var tabs = tabsBar.querySelectorAll( '.meeha-tabbed-tab' );

	tabs.forEach( function ( tab ) {
		tab.addEventListener( 'click', function () {
			if ( tab.classList.contains( 'is-active' ) ) {
				return;
			}
			tabs.forEach( function ( t ) { t.classList.remove( 'is-active' ); } );
			tab.classList.add( 'is-active' );

			list.style.opacity = '0.5';
			var data = new FormData();
			data.append( 'action', 'meeha_tabbed_sidebar' );
			data.append( 'nonce', window.meehaTabbedAjax.nonce );
			data.append( 'tab', tab.getAttribute( 'data-tab' ) );

			fetch( window.meehaTabbedAjax.url, { method: 'POST', body: data } )
				.then( function ( res ) { return res.json(); } )
				.then( function ( res ) {
					if ( res.success ) {
						list.innerHTML = res.data.html;
					}
				} )
				.finally( function () {
					list.style.opacity = '1';
				} );
		} );
	} );
} )();
