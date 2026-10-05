/**
 * Meeha News — pre-checks the "Trending" Quick Edit checkbox from the
 * hidden value WordPress doesn't populate custom fields into Quick Edit
 * automatically, so this wraps inlineEditPost.edit (WP core, wp-admin/js/
 * inline-edit-post.js) the standard documented way.
 */
( function ( $ ) {
	'use strict';

	if ( typeof inlineEditPost === 'undefined' ) {
		return;
	}

	var wpInlineEdit = inlineEditPost.edit;

	inlineEditPost.edit = function ( id ) {
		wpInlineEdit.apply( this, arguments );

		var postId = 0;
		if ( typeof id === 'object' ) {
			postId = parseInt( this.getId( id ), 10 );
		} else {
			postId = parseInt( id, 10 );
		}
		if ( ! postId ) {
			return;
		}

		var row   = $( '#post-' + postId );
		var value = $( '.meeha-trending-inline-value', row ).text();
		$( 'input[name="meeha_trending"]', '.inline-edit-row' ).prop( 'checked', '1' === value );

		var topicValue = $( '.meeha-topic-inline-value', row ).text();
		$( 'input[name="meeha_topic"]', '.inline-edit-row' ).val( topicValue );
	};
} )( jQuery );
