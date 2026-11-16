/**
 * Meeha — admin: Quick Edit pre-fill for the "মিহা সম্পাদকীয়" fields and the
 * একনজরে-sentence character counter (post editor + Quick Edit).
 * Vanilla DOM; only wraps core's inlineEditPost.edit (wp-admin/js/inline-edit-post.js).
 */
( function () {
	'use strict';

	var BN = [ '০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯' ];
	function bn( n ) {
		return String( n ).replace( /[0-9]/g, function ( d ) { return BN[ +d ]; } );
	}
	function len( s ) {
		return Array.from ? Array.from( s ).length : s.length;
	}

	function updateCounter( ta ) {
		var box = ta.closest( '.meeha-ed' );
		var out = box && box.querySelector( '[data-meeha-count]' );
		if ( ! out ) {
			return;
		}
		var max = parseInt( ta.getAttribute( 'maxlength' ), 10 ) || 90;
		var n = len( ta.value );
		out.textContent = bn( n ) + ' / ' + bn( max );
		out.classList.toggle( 'is-over', n > max );
	}

	function syncGlance( box ) {
		var toggle = box.querySelector( '[data-meeha-glance-toggle]' );
		var row = box.querySelector( '.meeha-ed__glance' );
		if ( toggle && row ) {
			row.style.opacity = toggle.checked ? '1' : '.6';
		}
	}

	document.addEventListener( 'input', function ( e ) {
		if ( e.target.matches && e.target.matches( '[data-meeha-counter]' ) ) {
			updateCounter( e.target );
		}
	} );
	document.addEventListener( 'change', function ( e ) {
		if ( e.target.matches && e.target.matches( '[data-meeha-glance-toggle]' ) ) {
			syncGlance( e.target.closest( '.meeha-ed' ) );
		}
	} );
	document.querySelectorAll( '.meeha-ed' ).forEach( function ( box ) {
		var ta = box.querySelector( '[data-meeha-counter]' );
		if ( ta ) {
			updateCounter( ta );
		}
		syncGlance( box );
	} );

	/* ---------- Quick Edit ---------- */
	if ( typeof window.inlineEditPost === 'undefined' ) {
		return;
	}
	var coreEdit = window.inlineEditPost.edit;
	window.inlineEditPost.edit = function ( id ) {
		var ret = coreEdit.apply( this, arguments );
		var postId = typeof id === 'object' ? parseInt( this.getId( id ), 10 ) : parseInt( id, 10 );
		if ( ! postId ) {
			return ret;
		}
		var row = document.getElementById( 'post-' + postId );
		var editRow = document.getElementById( 'edit-' + postId );
		var holder = row && row.querySelector( '.meeha-qe-data' );
		if ( ! editRow || ! holder ) {
			return ret;
		}
		var v = {};
		try {
			v = JSON.parse( holder.getAttribute( 'data-meeha-qe' ) ) || {};
		} catch ( err ) {
			v = {};
		}
		var set = function ( name, checked ) {
			var el = editRow.querySelector( 'input[name="' + name + '"]' );
			if ( el ) {
				el.checked = !! checked;
			}
		};
		var topic = editRow.querySelector( 'input[name="meeha_topic"]' );
		if ( topic ) {
			topic.value = v.topic || '';
		}
		set( 'meeha_breaking', v.breaking === '1' );
		set( 'meeha_trending', v.trending === '1' );
		set( 'meeha_investigation', v.investigation === '1' );
		set( 'meeha_glance', v.glance === '1' );
		var gt = editRow.querySelector( 'textarea[name="meeha_glance_text"]' );
		if ( gt ) {
			gt.value = v.glance_text || '';
			updateCounter( gt );
		}
		var box = editRow.querySelector( '.meeha-ed' );
		if ( box ) {
			syncGlance( box );
		}
		return ret;
	};
}() );
