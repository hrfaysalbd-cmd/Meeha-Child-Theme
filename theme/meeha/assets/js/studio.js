/*!
 * studio.js — wp-admin › ফটোকার্ড › স্টুডিও. Vanilla JS; uses window.MeehaCards (cards.js) and wp.media.
 * Config: window.MeehaStudio (inc/studio/admin.php → meeha_studio_js_config()).
 */
( function () {
	'use strict';
	var S = window.MeehaStudio, MC = window.MeehaCards;
	var SAMPLE = {
		news: { title: 'উপকূলে লবণাক্ততায় ধানের ফলন কমেছে অর্ধেক', category: 'অনুসন্ধান', date: '৬ অক্টোবর ২০২৬' },
		morning: { title: 'শুভ সকাল', caption: 'ভোরের আলোয় নদীর ঘাট', credit: 'মিহা নিউজ', dates: { weekday: 'মঙ্গলবার', gregorian: '৬ অক্টোবর ২০২৬', bangla: '২১ আশ্বিন ১৪৩৩', hijri: '২৪ রবিউস সানি ১৪৪৮' } },
		opinion: { title: 'মতামতের শিরোনাম এখানে বসবে', author_name: 'লেখকের নাম', author_title: 'উপাধি' },
		feature: { kicker: 'মিহা স্পেশাল', title: 'ফিচারের শিরোনাম এখানে বসবে' },
		breaking: { time: '১০:৪২', title: 'ব্রেকিং খবরের শিরোনাম এখানে বসবে' },
		category: { category: 'বিভাগ', title: 'খবরের শিরোনাম এখানে বসবে' },
		birthday: { heading: 'শুভ জন্মদিন', name: 'নাম', role: 'পরিচয়', message: 'শুভকামনা' },
		day: { title: 'দিবসের নাম', subtitle: 'তারিখ', message: 'শুভেচ্ছা বার্তা' },
		condolence: { heading: 'গভীর শোক', name: 'নাম', role: 'পরিচয়', message: 'শ্রদ্ধা' },
		literature: { label: 'গল্প', title: 'শিরোনাম এখানে', author: 'লেখকের নাম', excerpt: 'লেখার সবচেয়ে সুন্দর দুই-তিন লাইন এখানে বসবে।' },
		poem: { label: 'কবিতা', title: 'কবিতার নাম', author: 'কবির নাম', poem: 'প্রথম পঙ্‌ক্তি\nদ্বিতীয় পঙ্‌ক্তি\nতৃতীয় পঙ্‌ক্তি\nচতুর্থ পঙ্‌ক্তি' }
	};
	/* draw a small live thumbnail of a built-in preset into a canvas */
	var thumbQ = Promise.resolve();
	function thumb( cv, type, preset, data, size, extra ) {
		thumbQ = thumbQ.then( function () {
			if ( ! cv.isConnected ) { return null; }
			return MC.render( type, data, Object.assign( { preset: preset, size: size || '1080x1080' }, extra || {} ) ).then( function ( c ) {
				var x = cv.getContext( '2d' ), k = Math.min( cv.width / c.width, cv.height / c.height );
				x.clearRect( 0, 0, cv.width, cv.height );
				x.drawImage( c, ( cv.width - c.width * k ) / 2, ( cv.height - c.height * k ) / 2, c.width * k, c.height * k );
			} ).catch( function () {} );
		} );
		return thumbQ;
	}
	if ( MC && ! S ) { /* ফ্রেম লাইব্রেরি: built-in thumbnails */
		Array.prototype.forEach.call( document.querySelectorAll( 'canvas.ms-thumbc' ), function ( cv ) {
			var t = cv.getAttribute( 'data-type' ), p = cv.getAttribute( 'data-preset' ), d = Object.assign( {}, SAMPLE[ t ] || {} );
			if ( 'day' === t ) { d.theme = p; }
			thumb( cv, t, p, d );
		} );
		return;
	}
	if ( ! S || ! MC ) { return; }
	var $ = function ( id ) { return document.getElementById( id ); };
	var qsa = function ( sel, root ) { return Array.prototype.slice.call( ( root || document ).querySelectorAll( sel ) ); };
	var bn = MC.bnDigits;
	var form = $( 'ms-form' ), view = $( 'ms-canvas' );

	var state = {
		type: S.types[ S.init.type ] ? S.init.type : 'morning',
		size: ( document.querySelector( 'input[name="ms-size"]:checked' ) || { value: '1080x1080' } ).value,
		ad: !! S.adDefault,
		adId: S.ads.length ? S.ads[ 0 ].id : '',
		date: S.today, hijri: S.hijriOffset, dates: S.dates || {},
		image: null, focus: { x: 50, y: 50 }, zoom: 1,
		authorPhoto: '', speakerPhoto: '', quoteFrame: 'opinion',
		sel: {}, last: null, seq: 0
	};
	/* the photo (and its focus/zoom) belongs to the card type, so a news photo never leaks into a দিবস card */
	var perType = {};
	[ 'image', 'focus', 'zoom' ].forEach( function ( k ) {
		var dflt = { image: null, focus: { x: 50, y: 50 }, zoom: 1 }[ k ];
		Object.defineProperty( state, k, {
			get: function () { var v = ( perType[ state.type ] || {} )[ k ]; return undefined === v ? dflt : v; },
			set: function ( v ) { ( perType[ state.type ] = perType[ state.type ] || {} )[ k ] = v; }
		} );
	} );
	window.MeehaStudioState = state; // for tests / debugging

	/* ------------------------------------------------------------ helpers */
	function msg( el, text, kind ) { el = $( el ); if ( ! el ) { return; } el.textContent = text || ''; el.className = 'ms-msg' + ( kind ? ' is-' + kind : '' ); }
	function api( path, opt ) {
		opt = opt || {};
		opt.headers = Object.assign( { 'X-WP-Nonce': S.nonce }, opt.headers || {} );
		opt.credentials = 'same-origin';
		return fetch( ( /^https?:/.test( path ) ? path : S.rest + path ), opt ).then( function ( r ) {
			return r.json().catch( function () { return {}; } ).then( function ( j ) {
				if ( ! r.ok ) { var e = new Error( ( j && j.message ) || ( 'ত্রুটি ' + r.status ) ); e.status = r.status; throw e; }
				return j;
			} );
		} );
	}
	function val( id ) { var el = $( id ); return el ? el.value : ''; }
	function setVal( id, v ) { var el = $( id ); if ( el && undefined !== v && null !== v ) { el.value = v; } }
	function inType( el, type ) { var w = el.closest( '[data-for]' ); return ! w || ( ' ' + w.getAttribute( 'data-for' ) + ' ' ).indexOf( ' ' + type + ' ' ) > -1; }
	function fields( type ) {
		var out = {};
		qsa( '[data-k]', form ).forEach( function ( el ) {
			if ( ! inType( el, type ) ) { return; }
			out[ el.getAttribute( 'data-k' ) ] = 'checkbox' === el.type ? el.checked : el.value;
		} );
		return out;
	}
	function sizeWH() { var p = state.size.split( 'x' ); return [ +p[ 0 ], +p[ 1 ] ]; }
	function typeConf() { return S.types[ state.type ]; }
	function hasPhoto() { return typeConf().photo; }

	/* ------------------------------------------------------------ defaults */
	setVal( 'ms-m-title', S.titles[ 0 ] || 'শুভ সকাল' );
	setVal( 'ms-m-credit', S.defaults.credit );
	setVal( 'ms-o-label', S.defaults.opinion_label );
	setVal( 'ms-f-kicker', S.defaults.feature_kicker );
	setVal( 'ms-b-time', bn( S.now ) );
	setVal( 'ms-s-head', S.defaults.condolence_heading );
	setVal( 'ms-s-msg', S.defaults.condolence_message );
	setVal( 'ms-s-died', S.dates && S.dates.gregorian ? S.dates.gregorian : '' );
	setVal( 'ms-d-head', S.defaults.birthday_heading );
	setVal( 'ms-d-msg', S.defaults.birthday_message );
	setVal( 'ms-n-date', S.dates && S.dates.gregorian ? S.dates.gregorian : '' );
	setVal( 'ms-l-label', S.litLabel || 'সাহিত্য' );
	state.sel.news = 'b:' + ( S.newsDefault || 'classic' );
	state.dayTheme = 'generic'; state.dayIdx = 0;
	function applyDay( i ) {
		var dd = S.days[ i ]; if ( ! dd ) { return; }
		setVal( 'ms-y-title', dd.title ); setVal( 'ms-y-sub', dd.subtitle ); setVal( 'ms-y-msg', dd.message );
		state.dayTheme = dd.theme; state.sel.day = 'b:' + dd.theme; state.dayIdx = i;
	}
	/* "দিবস বাছুন": the দিবস list (text + built-in design) and, in their own group, the uploaded দিবস frames — an
	   uploaded frame is a design of its own (cards.js draws it standalone, without the built-in artwork) */
	function paintDayPick() {
		var sel = $( 'ms-y-pick' ); if ( ! sel ) { return; }
		sel.innerHTML = '';
		var g1 = document.createElement( 'optgroup' ); g1.label = 'দিবসের তালিকা (বিল্ট-ইন নকশা)';
		S.days.forEach( function ( dd, i ) { var o = document.createElement( 'option' ); o.value = String( i ); o.textContent = dd.title + ( dd.subtitle ? ' — ' + dd.subtitle : '' ); g1.appendChild( o ); } );
		sel.appendChild( g1 );
		var own = S.frames.filter( function ( f ) { return 'day' === f.type; } );
		if ( own.length ) {
			var g2 = document.createElement( 'optgroup' ); g2.label = 'আপলোড করা ফ্রেম';
			own.forEach( function ( f ) {
				var o = document.createElement( 'option' ); o.value = 'f:' + f.id;
				o.textContent = f.label + ' (নিজের ফ্রেম' + ( f.size !== state.size ? ', ' + sizeLabel( f.size ) : '' ) + ')';
				g2.appendChild( o );
			} );
			sel.appendChild( g2 );
		}
		syncDayPick();
	}
	function syncDayPick() {
		var sel = $( 'ms-y-pick' ), k = state.sel.day || '';
		if ( ! sel ) { return; }
		sel.value = 0 === k.indexOf( 'c:' ) && sel.querySelector( 'option[value="f:' + k.slice( 2 ) + '"]' ) ? 'f:' + k.slice( 2 ) : String( state.dayIdx );
	}
	function sizeLabel( sz ) { return bn( sz.replace( 'x', ' × ' ) ); }
	/* choose an uploaded frame (switches the card size to the frame's own size) */
	function chooseFrame( f ) {
		state.sel[ f.type ] = 'c:' + f.id;
		if ( f.size !== state.size ) {
			var r = document.querySelector( 'input[name="ms-size"][value="' + f.size + '"]' );
			if ( r ) { r.checked = true; state.size = f.size; }
		}
	}
	( function () {
		var sel = $( 'ms-y-pick' );
		sel.addEventListener( 'change', function () {
			var v = sel.value;
			if ( 0 === v.indexOf( 'f:' ) ) {
				var f = S.frames.filter( function ( x ) { return x.id === v.slice( 2 ); } )[ 0 ];
				if ( f ) { chooseFrame( f ); }
			} else { applyDay( +v ); }
			renderFrames(); syncAd(); schedule();
		} );
		applyDay( 0 );
	}() );
	( function () {
		var chips = $( 'ms-m-presets' );
		S.titles.forEach( function ( t ) {
			var b = document.createElement( 'button' ); b.type = 'button'; b.className = 'button button-small'; b.textContent = t;
			b.addEventListener( 'click', function () { setVal( 'ms-m-title', t ); schedule(); } );
			chips.appendChild( b );
		} );
		var pre = $( 'ms-s-pre' );
		[ '' ].concat( S.condPre ).forEach( function ( t, i ) {
			var o = document.createElement( 'option' ); o.value = t; o.textContent = t || '— নেই —'; if ( 1 === i ) { o.selected = true; } pre.appendChild( o );
		} );
		var cat = $( 'ms-c-cat' );
		S.categories.forEach( function ( c ) { var o = document.createElement( 'option' ); o.value = c.id; o.textContent = c.name; cat.appendChild( o ); } );
		var ad = $( 'ms-adsel' );
		S.ads.forEach( function ( a ) { var o = document.createElement( 'option' ); o.value = a.id; o.textContent = a.label; ad.appendChild( o ); } );
		$( 'ms-ad' ).checked = state.ad && S.ads.length > 0;
	}() );

	/* ------------------------------------------------------------ type switching */
	function applyType() {
		var t = state.type;
		qsa( '.ms-tabs .nav-tab' ).forEach( function ( a ) {
			var on = a.getAttribute( 'data-type' ) === t;
			a.classList.toggle( 'nav-tab-active', on );
			if ( on ) { a.setAttribute( 'aria-current', 'page' ); } else { a.removeAttribute( 'aria-current' ); }
		} );
		qsa( '[data-for]', form ).forEach( function ( el ) { el.hidden = ! inType( el, t ) || ( el.parentElement.closest( '[data-for]' ) && ! inType( el.parentElement, t ) ); } );
		var n = 0;
		qsa( '.postbox', form ).forEach( function ( box ) { var s = box.querySelector( '.ms-n' ); if ( s && ! box.hidden ) { n++; s.textContent = bn( n ) + '. '; } } );
		var ll = val( 'ms-l-label' );
		if ( 'poem' === t && ( ! ll || ll === ( S.litLabel || 'সাহিত্য' ) ) ) { setVal( 'ms-l-label', 'কবিতা' ); }
		if ( 'literature' === t && ( ! ll || 'কবিতা' === ll ) ) { setVal( 'ms-l-label', S.litLabel || 'সাহিত্য' ); }
		paintImage(); renderFrames(); syncAd(); schedule( true );
		try { history.replaceState( null, '', location.pathname + '?page=meeha-photocard-studio&type=' + t ); } catch ( e ) { /* ignore */ }
	}
	qsa( '.ms-tabs .nav-tab' ).forEach( function ( a ) {
		a.addEventListener( 'click', function ( e ) { e.preventDefault(); state.type = a.getAttribute( 'data-type' ); applyType(); } );
	} );

	/* ------------------------------------------------------------ frames */
	function selKey() { return state.sel[ state.type ] || ''; }
	function frameRows() {
		var t = state.type, rows = [];
		Object.keys( typeConf().presets ).forEach( function ( p ) { rows.push( { key: 'b:' + p, label: typeConf().presets[ p ], builtin: true, preset: p } ); } );
		S.frames.forEach( function ( f ) { if ( f.type === t && f.size === state.size ) { rows.push( { key: 'c:' + f.id, label: f.label, builtin: false, frame: f } ); } } );
		return rows;
	}
	function renderFrames() {
		var ul = $( 'ms-frames' ), rows = frameRows(), cur = selKey();
		if ( ! rows.some( function ( r ) { return r.key === cur; } ) ) {
			cur = 'quote' === state.type ? 'b:' + state.quoteFrame : ( 'day' === state.type ? 'b:' + state.dayTheme : rows[ 0 ].key );
			if ( ! rows.some( function ( r ) { return r.key === cur; } ) ) { cur = rows[ 0 ].key; }
			state.sel[ state.type ] = cur;
		}
		ul.innerHTML = '';
		rows.forEach( function ( r, i ) {
			var li = document.createElement( 'li' ), lab = document.createElement( 'label' ), inp = document.createElement( 'input' ), sw = document.createElement( 'span' );
			inp.type = 'radio'; inp.name = 'ms-frame'; inp.value = r.key; inp.checked = r.key === cur;
			sw.className = 'sw';
			if ( r.builtin && 'quote' !== state.type ) {
				sw.className += ' sw-' + state.type + '-' + r.preset;
				var tc = document.createElement( 'canvas' ); tc.width = 192; tc.height = 192; tc.className = 'ms-thumbc'; tc.setAttribute( 'aria-hidden', 'true' ); sw.appendChild( tc );
				var td = cardData(); if ( 'day' === state.type ) { td.theme = r.preset; }
				thumb( tc, state.type, r.preset, td, state.size );
			} else if ( ! r.builtin ) {
				/* an own / ready-made frame: show it on a live card, as it will come out (not the bare transparent PNG) */
				var fc = document.createElement( 'canvas' ); fc.width = 192; fc.height = 192; fc.className = 'ms-thumbc'; fc.setAttribute( 'aria-hidden', 'true' ); sw.appendChild( fc );
				var fd = cardData(), fx = { frame: r.frame.url };
				if ( 'quote' === state.type ) { fx.builtinFrame = false; }
				thumb( fc, state.type, framePreset(), fd, state.size, fx );
			} else {
				var img = document.createElement( 'img' ); img.alt = ''; img.loading = 'lazy';
				img.src = S.frameBase + 'quote-' + r.preset + '.svg';
				sw.className += ' sw-img'; sw.appendChild( img );
			}
			lab.appendChild( inp ); lab.appendChild( sw ); lab.appendChild( document.createTextNode( r.label ) );
			if ( r.builtin && 0 === i ) { var tg = document.createElement( 'span' ); tg.className = 'tag'; tg.textContent = 'বিল্ট-ইন'; lab.appendChild( tg ); }
			if ( ! r.builtin ) { var tu = document.createElement( 'span' ); tu.className = 'tag tag--own'; tu.textContent = r.frame.readymade ? 'রেডিমেড' : ( solo() ? 'নিজের নকশা' : 'আপলোড' ); lab.appendChild( tu ); li.className = 'is-own'; }
			inp.addEventListener( 'change', function () {
				state.sel[ state.type ] = r.key;
				if ( 'quote' === state.type && r.builtin ) { state.quoteFrame = r.preset; }
				if ( 'day' === state.type && r.builtin ) { state.dayTheme = r.preset; }
				if ( 'day' === state.type ) { syncDayPick(); }
				paintFrameText(); syncAd(); schedule();
			} );
			li.appendChild( lab );
			if ( ! r.builtin && r.frame.can_delete ) {
				var del = document.createElement( 'button' ); del.type = 'button'; del.className = 'ms-fdel'; del.title = 'ফ্রেম মুছুন'; del.setAttribute( 'aria-label', 'ফ্রেম মুছুন: ' + r.label ); del.textContent = '×';
				del.addEventListener( 'click', function () { deleteFrame( r.frame ); } );
				li.appendChild( del );
			}
			ul.appendChild( li );
		} );
		if ( S.canUpload ) {
			var add = document.createElement( 'li' ); add.className = 'add';
			add.innerHTML = '<label><span class="sw"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span></span>নতুন ফ্রেম</label>';
			var btn = add.querySelector( 'label' ); btn.tabIndex = 0; btn.setAttribute( 'role', 'button' );
			var open = function () { $( 'ms-ffile' ).click(); };
			btn.addEventListener( 'click', open );
			btn.addEventListener( 'keydown', function ( e ) { if ( 'Enter' === e.key || ' ' === e.key ) { e.preventDefault(); open(); } } );
			ul.appendChild( add );
		}
		var wh = sizeWH();
		var other = S.frames.filter( function ( f ) { return f.type === state.type && f.size !== state.size; } ).length;
		$( 'ms-framehelp' ).textContent = 'নিজের ফ্রেম: স্বচ্ছ PNG, ঠিক ' + bn( wh[ 0 ] ) + ' × ' + bn( wh[ 1 ] ) + ' পিক্সেল (নির্বাচিত মাপ), সর্বোচ্চ ১ এমবি। '
			+ ( MC.isStandalone && MC.isStandalone( state.type ) ? 'এই ধরনে আপলোড করা ফ্রেম নিজেই পুরো নকশা — বিল্ট-ইন নকশা আঁকা হয় না; ছবি (দিলে) ফ্রেমের নিচে, লেখা ওপরে।' : 'ফ্রেম ছবির ওপরে বসে, লেখার নিচে।' )
			+ ( other ? ' অন্য মাপের আরও ' + bn( other ) + 'টি ফ্রেম আছে — মাপ বদলালে দেখা যাবে।' : '' );
		if ( 'day' === state.type ) { paintDayPick(); }
		paintFrameText();
	}
	/* uploaded frame on a full-artwork type (দিবস, জন্মদিন, শোক, উক্তি) → standalone design + its text options */
	function curFrame() {
		var key = selKey();
		if ( 0 !== key.indexOf( 'c:' ) ) { return null; }
		return S.frames.filter( function ( x ) { return x.id === key.slice( 2 ); } )[ 0 ] || null;
	}
	function solo() { return !! ( MC.isStandalone && MC.isStandalone( state.type ) ); }
	function soloOn() { return solo() && !! curFrame(); }
	var ftext = {};
	function ftState() { return ( ftext[ state.type ] = ftext[ state.type ] || { ink: 'auto', pos: 'bottom', hide: {} } ); }
	function paintFrameText() {
		var box = $( 'ms-ftext' ); if ( ! box ) { return; }
		box.hidden = ! soloOn();
		if ( box.hidden ) { return; }
		var st = ftState(), list = $( 'ms-ft-show' );
		qsa( 'input[name="ms-ft-ink"]', box ).forEach( function ( r ) { r.checked = r.value === st.ink; } );
		qsa( 'input[name="ms-ft-pos"]', box ).forEach( function ( r ) { r.checked = r.value === st.pos; } );
		list.innerHTML = '';
		( MC.standaloneText[ state.type ] || [] ).concat( [ [ 'logo', 'logo', 'লোগো' ] ] ).forEach( function ( e ) {
			var lab = document.createElement( 'label' ), cb = document.createElement( 'input' );
			lab.className = 'check'; cb.type = 'checkbox'; cb.value = e[ 0 ]; cb.checked = ! st.hide[ e[ 0 ] ]; cb.setAttribute( 'data-ft-el', e[ 0 ] );
			cb.addEventListener( 'change', function () { st.hide[ e[ 0 ] ] = ! cb.checked; schedule(); } );
			lab.appendChild( cb ); lab.appendChild( document.createTextNode( e[ 2 ] ) ); list.appendChild( lab );
		} );
	}
	( function () {
		var box = $( 'ms-ftext' ); if ( ! box ) { return; }
		qsa( 'input[name="ms-ft-ink"]', box ).forEach( function ( r ) { r.addEventListener( 'change', function () { ftState().ink = r.value; schedule(); } ); } );
		qsa( 'input[name="ms-ft-pos"]', box ).forEach( function ( r ) { r.addEventListener( 'change', function () { ftState().pos = r.value; schedule(); } ); } );
	}() );
	function deleteFrame( f ) {
		if ( ! window.confirm( '“' + f.label + '” ফ্রেমটি মুছে ফেলবেন?' ) ) { return; }
		api( 'card-frames/' + encodeURIComponent( f.id ), { method: 'DELETE' } ).then( function () {
			S.frames = S.frames.filter( function ( x ) { return x.id !== f.id; } );
			msg( 'ms-framemsg', 'ফ্রেম মুছে ফেলা হয়েছে।', 'ok' ); renderFrames(); schedule();
		} ).catch( function ( e ) { msg( 'ms-framemsg', e.message, 'err' ); } );
	}
	/* client-side validation mirrors the server: real PNG signature, ≤ 1 MB, exact canvas size; alpha preferred */
	function checkFrameFile( file ) {
		var wh = sizeWH();
		if ( file.size > S.maxBytes ) { return Promise.reject( new Error( 'ফাইলটি ১ এমবি-র বেশি। ছোট করে আবার দিন।' ) ); }
		return file.slice( 0, 8 ).arrayBuffer().then( function ( buf ) {
			var b = new Uint8Array( buf ), sig = [ 137, 80, 78, 71, 13, 10, 26, 10 ];
			if ( ! sig.every( function ( v, i ) { return b[ i ] === v; } ) ) { throw new Error( 'শুধু আসল PNG ফাইল চলবে (নাম বদলানো JPG/অন্য ফাইল নয়)।' ); }
			return new Promise( function ( resolve, reject ) {
				var u = URL.createObjectURL( file ), im = new Image();
				im.onload = function () {
					if ( im.naturalWidth !== wh[ 0 ] || im.naturalHeight !== wh[ 1 ] ) {
						URL.revokeObjectURL( u );
						reject( new Error( 'ফ্রেমের মাপ ঠিক ' + bn( wh[ 0 ] ) + ' × ' + bn( wh[ 1 ] ) + ' পিক্সেল হতে হবে (এই ফাইলটি ' + bn( im.naturalWidth ) + ' × ' + bn( im.naturalHeight ) + ')।' ) );
						return;
					}
					var c = document.createElement( 'canvas' ); c.width = 270; c.height = Math.round( 270 * wh[ 1 ] / wh[ 0 ] );
					var x = c.getContext( '2d' ); x.drawImage( im, 0, 0, c.width, c.height );
					var d = x.getImageData( 0, 0, c.width, c.height ).data, alpha = false;
					for ( var i = 3; i < d.length; i += 4 ) { if ( d[ i ] < 250 ) { alpha = true; break; } }
					URL.revokeObjectURL( u ); resolve( alpha );
				};
				im.onerror = function () { URL.revokeObjectURL( u ); reject( new Error( 'ছবিটি পড়া যায়নি।' ) ); };
				im.src = u;
			} );
		} );
	}
	$( 'ms-ffile' ).addEventListener( 'change', function () {
		var inp = this, file = inp.files && inp.files[ 0 ];
		if ( ! file ) { return; }
		msg( 'ms-framemsg', 'যাচাই হচ্ছে…' );
		checkFrameFile( file ).then( function ( alpha ) {
			if ( ! alpha && ! window.confirm( 'এই PNG-তে কোনো স্বচ্ছ অংশ নেই — ফ্রেমটি ছবিকে পুরো ঢেকে দেবে। তবুও আপলোড করবেন?' ) ) { throw new Error( 'আপলোড বাতিল।' ); }
			var fd = new FormData();
			fd.append( 'file', file, file.name );
			fd.append( 'type', state.type ); fd.append( 'size', state.size );
			fd.append( 'label', val( 'ms-flabel' ) || file.name.replace( /\.png$/i, '' ) );
			msg( 'ms-framemsg', 'আপলোড হচ্ছে…' );
			return api( 'card-frames', { method: 'POST', body: fd } );
		} ).then( function ( f ) {
			S.frames.push( f ); chooseFrame( f );
			msg( 'ms-framemsg', 'ফ্রেম যোগ হয়েছে' + ( f.warning ? ' — ' + f.warning : '।' ), f.warning ? 'warn' : 'ok' );
			setVal( 'ms-flabel', '' ); renderFrames(); schedule();
		} ).catch( function ( e ) { msg( 'ms-framemsg', e.message, 'err' ); } ).then( function () { inp.value = ''; } );
	} );

	/* ------------------------------------------------------------ media */
	function mediaFrame( title, cb ) {
		if ( ! window.wp || ! wp.media ) { return; }
		var fr = wp.media( { title: title, library: { type: 'image' }, multiple: false, button: { text: 'এই ছবিটি নিন' } } );
		fr.on( 'select', function () { cb( fr.state().get( 'selection' ).first().toJSON() ); } );
		fr.on( 'open', function () { try { fr.content.mode( 'browse' ); } catch ( e ) { /* ignore */ } } );
		fr.open();
	}
	function setImage( img ) {
		state.image = img; state.focus = { x: 50, y: 50 }; state.zoom = 1;
		paintImage(); renderFrames(); schedule();
	}
	function paintImage() {
		var img = state.image;
		$( 'ms-zoom' ).value = Math.round( state.zoom * 100 ); $( 'ms-zoomv' ).textContent = bn( Math.round( state.zoom * 100 ) ) + '%';
		var th = $( 'ms-thumb' ); th.innerHTML = '';
		if ( img ) { var i = document.createElement( 'img' ); i.src = img.thumb || img.url; i.alt = ''; th.appendChild( i ); }
		$( 'ms-unpick' ).hidden = ! img;
		var info = 'ছবি বাছাই করা হয়নি।';
		if ( img ) {
			info = ( img.name || '' ) + ( img.w ? ' · ' + bn( img.w ) + ' × ' + bn( img.h ) : '' ) + '। ছবিতে টেনে ফোকাস ঠিক করুন।';
			if ( img.w && img.w < 1080 ) { info += ' ছবিটি ছোট — কার্ডে ঝাপসা দেখাতে পারে।'; }
		}
		$( 'ms-imginfo' ).textContent = info;
	}
	window.MeehaStudioSetImage = setImage; // tests
	$( 'ms-pick' ).addEventListener( 'click', function () {
		mediaFrame( 'কার্ডের ছবি বাছুন', function ( a ) {
			setImage( { id: a.id, url: a.url, w: a.width, h: a.height, name: a.filename, thumb: a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url } );
		} );
	} );
	$( 'ms-unpick' ).addEventListener( 'click', function () { setImage( null ); } );
	$( 'ms-o-pick' ).addEventListener( 'click', function () {
		mediaFrame( 'লেখকের ছবি বাছুন', function ( a ) { state.authorPhoto = a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url; $( 'ms-o-photo' ).checked = true; schedule(); } );
	} );
	$( 'ms-zoom' ).addEventListener( 'input', function () { state.zoom = this.value / 100; $( 'ms-zoomv' ).textContent = bn( this.value ) + '%'; schedule(); } );
	$( 'ms-refocus' ).addEventListener( 'click', function () { state.focus = { x: 50, y: 50 }; schedule(); } );

	/* focal point: drag the photo in the preview */
	( function () {
		var drag = null;
		view.addEventListener( 'pointerdown', function ( e ) {
			if ( ! hasPhoto() || ! state.image ) { return; }
			drag = { x: e.clientX, y: e.clientY, f: { x: state.focus.x, y: state.focus.y } };
			view.setPointerCapture( e.pointerId ); view.classList.add( 'is-drag' );
		} );
		view.addEventListener( 'pointermove', function ( e ) {
			if ( ! drag ) { return; }
			var r = view.getBoundingClientRect(), k = 100 / state.zoom;
			state.focus = {
				x: Math.max( 0, Math.min( 100, drag.f.x - ( e.clientX - drag.x ) / r.width * k ) ),
				y: Math.max( 0, Math.min( 100, drag.f.y - ( e.clientY - drag.y ) / r.height * k ) )
			};
			schedule( true );
		} );
		var end = function () { drag = null; view.classList.remove( 'is-drag' ); };
		view.addEventListener( 'pointerup', end ); view.addEventListener( 'pointercancel', end );
		view.addEventListener( 'keydown', function ( e ) {
			var d = { ArrowLeft: [ -2, 0 ], ArrowRight: [ 2, 0 ], ArrowUp: [ 0, -2 ], ArrowDown: [ 0, 2 ] }[ e.key ];
			if ( ! d || ! state.image ) { return; }
			e.preventDefault(); state.focus = { x: Math.max( 0, Math.min( 100, state.focus.x + d[ 0 ] ) ), y: Math.max( 0, Math.min( 100, state.focus.y + d[ 1 ] ) ) }; schedule();
		} );
		view.tabIndex = 0;
	}() );

	/* ------------------------------------------------------------ post-ID autofill */
	function fetchSource( v, opt ) {
		v = ( v || '' ).trim();
		if ( ! v ) { return Promise.resolve(); }
		v = v.replace( /[০-৯]/g, function ( d ) { return '০১২৩৪৫৬৭৮৯'.indexOf( d ); } );
		var box = $( 'ms-fetched' );
		msg( 'ms-status', 'তথ্য আনা হচ্ছে…' );
		return api( 'card-source?post=' + encodeURIComponent( v ) ).then( function ( r ) {
			var t = state.type;
			if ( r.quote && 'quote' !== t ) { state.type = t = 'quote'; applyType(); }
			/* a সাহিত্য post opens the সাহিত্য / কবিতা card; a pick from the recent list may also switch to মতামত */
			var want = r.lit_genre ? ( 'poem' === r.lit_genre ? 'poem' : 'literature' ) : '';
			if ( opt && opt.suggest && S.types[ opt.suggest ] && opt.suggest !== t && ( want || /^(literature|poem)$/.test( t ) || 'opinion' === opt.suggest ) ) { state.type = t = opt.suggest; applyType(); } else if ( want && 'news' === t ) { state.type = t = want; applyType(); }
			if ( 'opinion' === t ) {
				setVal( 'ms-o-title', r.title ); setVal( 'ms-o-name', r.author_name ); setVal( 'ms-o-role', r.author_title );
				state.authorPhoto = r.author_photo || '';
			} else if ( 'news' === t ) {
				setVal( 'ms-n-title', r.title ); setVal( 'ms-n-cat', r.category || r.kicker ); setVal( 'ms-n-date', r.date_short || r.date );
				if ( r.image ) { setImage( { id: r.image_id, url: r.image, name: 'ফিচার ছবি' } ); }
			} else if ( 'quote' === t && r.quote ) {
				pickQuote( r.quote );
			} else if ( 'literature' === t || 'poem' === t ) {
				setVal( 'ms-l-title', r.title ); setVal( 'ms-l-author', r.lit_writer || r.author_name );
				setVal( 'ms-l-label', r.lit_label || ( 'poem' === t ? 'কবিতা' : S.litLabel || 'সাহিত্য' ) );
				setVal( 'ms-l-excerpt', r.lit_excerpt || r.summary || r.excerpt || '' );
				if ( r.poem_lines && r.poem_lines.length ) { setVal( 'ms-l-poem', r.poem_lines.join( '\n' ) ); }
				if ( r.image ) { setImage( { id: r.image_id, url: r.image, name: 'ফিচার ছবি' } ); }
			} else {
				setVal( 'feature' === t || 'category' === t ? 'ms-f-title' : 'ms-b-title', r.title );
				if ( 'feature' === t ) { setVal( 'ms-f-kicker', r.kicker || r.category || S.defaults.feature_kicker ); }
				if ( 'category' === t && r.category_id ) { setVal( 'ms-c-cat', String( r.category_id ) ); }
				if ( 'breaking' === t && r.is_breaking ) { var rb = form.querySelector( 'input[name="ms-b-variant"][value="breaking"]' ); if ( rb ) { rb.checked = true; } }
				if ( r.image ) { setImage( { id: r.image_id, url: r.image, name: 'ফিচার ছবি' } ); }
			}
			box.innerHTML = '';
			var im = document.createElement( 'img' ); im.alt = ''; im.src = r.author_photo || r.image || ''; if ( ! im.src ) { im.hidden = true; }
			var b = document.createElement( 'b' ); b.textContent = r.title;
			var s = document.createElement( 'span' ); s.textContent = [ r.author_name, r.author_title ].filter( Boolean ).join( ' · ' );
			box.appendChild( im ); box.appendChild( b ); box.appendChild( s ); box.hidden = false;
			msg( 'ms-status', 'তথ্য বসানো হয়েছে — দরকার হলে বদলে নিন।', 'ok' );
			markRecent( r.id );
			schedule();
		} ).catch( function ( e ) { box.hidden = true; msg( 'ms-status', e.message, 'err' ); } );
	}
	$( 'ms-fetch' ).addEventListener( 'click', function () { fetchSource( val( 'ms-post' ) ); } );

	/* ------------------------------------------------------------ সাম্প্রতিক পোস্ট (latest published, ‹ › paging, refresh, search) */
	var recent = { offset: 0, per: 5, more: false, search: '', items: [], picked: 0, seq: 0 };
	function markRecent( id ) {
		recent.picked = +id || 0;
		qsa( '.ms-recent__item', $( 'ms-rlist' ) ).forEach( function ( b ) { var on = +b.getAttribute( 'data-id' ) === recent.picked; b.classList.toggle( 'is-on', on ); b.setAttribute( 'aria-pressed', on ? 'true' : 'false' ); } );
	}
	function paintRecent( note, err ) {
		var ul = $( 'ms-rlist' ); ul.innerHTML = '';
		if ( note ) { var li0 = document.createElement( 'li' ); li0.className = 'ms-recent__msg' + ( err ? ' is-err' : '' ); li0.textContent = note; ul.appendChild( li0 ); }
		recent.items.forEach( function ( p ) {
			var li = document.createElement( 'li' ), b = document.createElement( 'button' );
			b.type = 'button'; b.className = 'ms-recent__item'; b.setAttribute( 'data-id', p.id );
			if ( p.thumb ) { var im = document.createElement( 'img' ); im.src = p.thumb; im.alt = ''; im.loading = 'lazy'; b.appendChild( im ); } else { var ph = document.createElement( 'span' ); ph.className = 'ms-recent__ph'; b.appendChild( ph ); }
			var tx = document.createElement( 'span' ); tx.className = 'ms-recent__text';
			var t = document.createElement( 'b' ); t.textContent = p.title;
			var m = document.createElement( 'small' ); m.textContent = [ 'আইডি ' + bn( p.id ), p.category, p.date ].filter( Boolean ).join( ' · ' );
			tx.appendChild( t ); tx.appendChild( m ); b.appendChild( tx );
			b.addEventListener( 'click', function () { setVal( 'ms-post', String( p.id ) ); fetchSource( String( p.id ), { suggest: p.type } ); } );
			li.appendChild( b ); ul.appendChild( li );
		} );
		$( 'ms-rprev' ).disabled = recent.offset <= 0;
		$( 'ms-rnext' ).disabled = ! recent.more;
		markRecent( recent.picked );
	}
	function loadRecent( offset ) {
		var seq = ++recent.seq;
		recent.offset = Math.max( 0, offset || 0 );
		if ( ! recent.items.length ) { paintRecent( 'আনা হচ্ছে…' ); }
		$( 'ms-rrefresh' ).disabled = true;
		return api( 'card-recent?offset=' + recent.offset + '&search=' + encodeURIComponent( recent.search ) ).then( function ( r ) {
			if ( seq !== recent.seq ) { return; }
			recent.items = r.items || []; recent.per = r.per || 5; recent.more = !! r.more;
			paintRecent( recent.items.length ? '' : ( recent.search ? 'এই শব্দে কোনো প্রকাশিত পোস্ট পাওয়া যায়নি।' : 'এখনো কোনো প্রকাশিত পোস্ট নেই।' ) );
		} ).catch( function ( e ) {
			if ( seq !== recent.seq ) { return; }
			recent.items = []; paintRecent( 'পোস্টের তালিকা আনা যায়নি: ' + e.message, true );
		} ).then( function () { $( 'ms-rrefresh' ).disabled = false; } );
	}
	$( 'ms-rprev' ).addEventListener( 'click', function () { loadRecent( recent.offset - recent.per ); } );
	$( 'ms-rnext' ).addEventListener( 'click', function () { loadRecent( recent.offset + recent.per ); } );
	$( 'ms-rrefresh' ).addEventListener( 'click', function () { recent.items = []; loadRecent( 0 ); } );
	( function () {
		var t = 0, inp = $( 'ms-rsearch' );
		inp.addEventListener( 'input', function () { clearTimeout( t ); t = setTimeout( function () { recent.search = inp.value.trim(); recent.items = []; loadRecent( 0 ); }, 350 ); } );
		inp.addEventListener( 'keydown', function ( e ) { if ( 'Enter' === e.key ) { e.preventDefault(); } } );
	}() );

	/* "নির্বাচিত লেখা হাইলাইট করুন": wrap the selection in ** … ** (or unwrap it) */
	qsa( '.ms-mark' ).forEach( function ( btn ) {
		btn.addEventListener( 'mousedown', function ( e ) { e.preventDefault(); } ); /* keep the textarea selection */
		btn.addEventListener( 'click', function () {
			var ta = $( btn.getAttribute( 'data-target' ) ); if ( ! ta ) { return; }
			var a = ta.selectionStart, b = ta.selectionEnd, v = ta.value;
			if ( a === b ) { msg( 'ms-status', 'আগে লেখার যে অংশ রঙিন করতে চান তা মাউস দিয়ে বাছুন।', 'warn' ); ta.focus(); return; }
			var sel = v.slice( a, b ), out = /^\*\*[\s\S]*\*\*$/.test( sel ) ? sel.slice( 2, -2 ) : '**' + sel.replace( /\*\*/g, '' ).trim() + '**';
			ta.value = v.slice( 0, a ) + out + v.slice( b );
			ta.focus(); ta.setSelectionRange( a, a + out.length );
			schedule();
		} );
	} );
	$( 'ms-post' ).addEventListener( 'keydown', function ( e ) { if ( 'Enter' === e.key ) { e.preventDefault(); fetchSource( this.value ); } } );

	/* ------------------------------------------------------------ quotes */
	function pickQuote( q ) {
		setVal( 'ms-q-text', q.text ); setVal( 'ms-q-speaker', q.speaker ); setVal( 'ms-q-role', q.role );
		setVal( 'ms-q-type', q.type_label ); setVal( 'ms-q-year', q.year || '' );
		state.speakerPhoto = q.photo || '';
		state.quoteFrame = S.quoteFrames[ q.frame ] ? q.frame : 'opinion';
		state.sel.quote = 'b:' + state.quoteFrame;
		renderFrames(); schedule();
	}
	function loadQuotes( search ) {
		var ul = $( 'ms-qlist' );
		ul.innerHTML = '<li class="ms-qload">খোঁজা হচ্ছে…</li>';
		api( 'card-quotes?search=' + encodeURIComponent( search || '' ) ).then( function ( r ) {
			ul.innerHTML = '';
			if ( ! r.items.length ) { ul.innerHTML = '<li class="ms-qload">কোনো উক্তি পাওয়া যায়নি।</li>'; return; }
			r.items.forEach( function ( q ) {
				var li = document.createElement( 'li' ), b = document.createElement( 'button' );
				b.type = 'button'; b.setAttribute( 'role', 'option' ); b.className = 'ms-q ms-q--' + q.frame;
				var t = document.createElement( 'span' ); t.textContent = q.text;
				var s = document.createElement( 'small' ); s.textContent = [ q.speaker, q.type_label ].filter( Boolean ).join( ' · ' );
				b.appendChild( t ); b.appendChild( s );
				b.addEventListener( 'click', function () { qsa( '.ms-q', ul ).forEach( function ( x ) { x.setAttribute( 'aria-selected', 'false' ); } ); b.setAttribute( 'aria-selected', 'true' ); pickQuote( q ); } );
				li.appendChild( b ); ul.appendChild( li );
			} );
		} ).catch( function ( e ) { ul.innerHTML = ''; msg( 'ms-status', e.message, 'err' ); } );
	}
	$( 'ms-qgo' ).addEventListener( 'click', function () { loadQuotes( val( 'ms-qsearch' ) ); } );
	$( 'ms-qsearch' ).addEventListener( 'keydown', function ( e ) { if ( 'Enter' === e.key ) { e.preventDefault(); loadQuotes( this.value ); } } );

	/* ------------------------------------------------------------ dates */
	function paintDates() {
		var d = state.dates || {}, box = $( 'ms-dates' );
		box.querySelector( '[data-d="wg"]' ).textContent = [ d.weekday, d.gregorian ].filter( Boolean ).join( ', ' );
		box.querySelector( '[data-d="bangla"]' ).textContent = d.bangla || '';
		box.querySelector( '[data-d="hijri"]' ).textContent = d.hijri || '';
	}
	function loadDates() {
		state.date = val( 'ms-date' ) || S.today; state.hijri = parseInt( val( 'ms-hijri' ), 10 ) || 0;
		return api( 'dates?d=' + encodeURIComponent( state.date ) + '&offset=' + state.hijri ).then( function ( d ) { state.dates = d; paintDates(); schedule(); } )
			.catch( function ( e ) { msg( 'ms-status', 'তারিখ আনা যায়নি: ' + e.message, 'err' ); } );
	}
	$( 'ms-date' ).addEventListener( 'change', loadDates );
	$( 'ms-hijri' ).addEventListener( 'change', loadDates );
	paintDates();
	if ( String( S.hijriOffset ) !== '0' ) { /* server already applied the default offset */ }

	/* ------------------------------------------------------------ size & ad */
	function syncAd() {
		var cb = $( 'ms-ad' ), sel = $( 'ms-adsel' ), help = $( 'ms-adhelp' ), why = '';
		if ( ! typeConf().ad ) { why = 'শোক কার্ডে বিজ্ঞাপন স্ট্রিপ বসে না।'; } else if ( 'day' === state.type && ! soloOn() && 'matribhasha' === state.dayTheme ) { why = 'শহীদ দিবসের কার্ডে বিজ্ঞাপন স্ট্রিপ বসে না।'; } else if ( 'day' === state.type && soloOn() && MC.adAllowed && '1200x630' !== state.size && ! MC.adAllowed( 'day', fields( 'day' ), { size: state.size, frame: 'own' } ) ) { why = 'শহীদ দিবস / শোক দিবসের কার্ডে বিজ্ঞাপন স্ট্রিপ বসে না।'; } else if ( '1200x630' === state.size ) { why = 'লিংক প্রিভিউ (১২০০ × ৬৩০) মাপে বিজ্ঞাপন স্ট্রিপ নেই।'; } else if ( ! S.ads.length ) { why = 'কোনো বিজ্ঞাপন স্ট্রিপ সেট করা নেই — মিহা থিম › বিজ্ঞাপন (card_strip) থেকে যোগ করুন।'; }
		cb.disabled = !! why; if ( why ) { cb.checked = false; } else { cb.checked = state.ad; }
		sel.disabled = !! why || ! cb.checked;
		help.textContent = why || ( cb.checked ? 'স্ট্রিপ: ১০৮০ × ১২০, কার্ডের একদম নিচে।' : '' );
	}
	$( 'ms-ad' ).addEventListener( 'change', function () { state.ad = this.checked; syncAd(); schedule(); } );
	$( 'ms-adsel' ).addEventListener( 'change', function () { state.adId = this.value; schedule(); } );
	qsa( 'input[name="ms-size"]' ).forEach( function ( r ) {
		r.addEventListener( 'change', function () { state.size = r.value; syncStory(); renderFrames(); syncAd(); schedule( true ); } );
	} );

	function syncStory() { var f = $( 'ms-story-f' ); if ( f ) { f.hidden = '1080x1920' !== state.size; } }
	syncStory();

	/* ------------------------------------------------------------ build + render */
	/* CTA pill ("বিস্তারিত কমেন্টে") for news-type cards: text field + "দেখান" checkbox ('' = hidden) */
	function cardData() {
		var d = cardData0(), t = state.type;
		if ( /^(news|opinion|feature|breaking|category)$/.test( t ) ) {
			var on = $( 'ms-cta-on' ), tx = $( 'ms-cta' );
			d.cta = on && on.checked && tx ? tx.value : '';
		}
		if ( '1080x1920' === state.size ) { d.story_cta = val( 'ms-story-cta' ); }
		return d;
	}
	function cardData0() {
		var t = state.type, f = fields( t ), img = state.image ? state.image.url : '';
		var d = { focus: state.focus, zoom: state.zoom };
		if ( 'news' === t ) { return Object.assign( d, { title: f.title, category: f.category, date: f.date, image: img } ); }
		if ( 'birthday' === t ) { return Object.assign( d, { heading: f.heading, name: f.name, role: f.role, message: f.message, image: img } ); }
		if ( 'day' === t ) { return Object.assign( d, { title: f.title, subtitle: f.subtitle, message: f.message, theme: state.dayTheme, image: img } ); }
		if ( 'morning' === t ) { return Object.assign( d, { title: f.title, caption: f.caption, credit: f.credit, image: img, dates: state.dates } ); }
		if ( 'opinion' === t ) { return { title: f.title, author_name: f.author_name, author_title: f.author_title, author_photo: state.authorPhoto, show_photo: !! f.show_photo, label: f.label }; }
		if ( 'feature' === t ) { return Object.assign( d, { kicker: f.kicker, title: f.title, image: img } ); }
		if ( 'breaking' === t ) {
			var v = ( form.querySelector( 'input[name="ms-b-variant"]:checked' ) || {} ).value || 'breaking';
			return Object.assign( d, { variant: v, time: f.time, title: f.title, image: img } );
		}
		if ( 'quote' === t ) {
			return { text: f.text, speaker: f.speaker, role: f.role, type_label: f.type_label, year: f.year, frame: state.quoteFrame, speaker_photo: $( 'ms-q-photo' ).checked ? state.speakerPhoto : '' };
		}
		if ( 'literature' === t ) { return Object.assign( d, { label: f.label, title: f.title, author: f.author, excerpt: f.excerpt, image: img } ); }
		if ( 'poem' === t ) { return Object.assign( d, { label: f.label, title: f.title, author: f.author, poem: f.poem, image: img } ); }
		if ( 'condolence' === t ) {
			var dl = [ f.born ? 'জন্ম: ' + f.born : '', f.died ? 'মৃত্যু: ' + f.died : '' ].filter( Boolean ).join( ' | ' );
			return Object.assign( d, { pre: f.pre, heading: f.heading, name: f.name, role: f.role, dates_line: dl, message: f.message, image: img } );
		}
		var cat = S.categories.filter( function ( c ) { return String( c.id ) === String( f.category_id ); } )[ 0 ] || {};
		return Object.assign( d, { category: cat.name || '', color: cat.color || '', title: f.title, image: img } );
	}
	/* the layout an own frame sits on: the type's first stock preset (news: the default from settings) */
	function framePreset() {
		var presets = Object.keys( typeConf().presets ), nd = S.newsDefault;
		if ( 'quote' === state.type ) { return state.quoteFrame; }
		if ( 'day' === state.type ) { return state.dayTheme; }
		if ( 'news' === state.type && nd && presets.indexOf( nd ) > -1 && ! MC.isDeco( 'news', nd ) ) { return nd; }
		return presets[ 0 ];
	}
	function cardOpts() {
		var key = selKey(), o = { size: state.size };
		if ( 0 === key.indexOf( 'b:' ) ) { o.preset = key.slice( 2 ); } else {
			var id = key.slice( 2 ), f = S.frames.filter( function ( x ) { return x.id === id; } )[ 0 ];
			if ( f ) { o.frame = f.url; }
			o.preset = framePreset();
			if ( 'quote' === state.type ) { o.builtinFrame = false; }
			if ( f && solo() ) { var ft = ftState(); o.frameText = { ink: ft.ink, pos: ft.pos, hide: Object.assign( {}, ft.hide ) }; }
		}
		if ( 'breaking' === state.type ) { o.preset = cardData().variant; }
		if ( state.ad && ! $( 'ms-ad' ).disabled ) {
			var ad = S.ads.filter( function ( a ) { return a.id === ( val( 'ms-adsel' ) || state.adId ); } )[ 0 ] || S.ads[ 0 ];
			o.ad = ad ? { url: ad.url } : true;
		}
		return o;
	}
	var timer = 0;
	function schedule( fast ) { clearTimeout( timer ); timer = setTimeout( render, fast ? 16 : 140 ); }
	function render() {
		var seq = ++state.seq, wh = sizeWH(), stage = $( 'ms-stage' );
		$( 'ms-busy' ).hidden = false;
		var data = cardData(), opts = cardOpts();
		return MC.render( state.type, data, opts ).then( function ( c ) {
			if ( seq !== state.seq ) { return; }
			view.width = c.width; view.height = c.height;
			view.getContext( '2d' ).drawImage( c, 0, 0 );
			var scale = c.height > 1400 ? 0.36 : 0.5; /* স্টোরি stays on screen */
			stage.style.width = Math.round( c.width * scale ) + 'px'; stage.style.height = Math.round( c.height * scale ) + 'px';
			stage.classList.toggle( 'is-photo', hasPhoto() && !! state.image );
			state.last = { canvas: c, type: state.type, data: data, opts: opts };
			$( 'ms-meta' ).textContent = bn( wh[ 0 ] ) + ' × ' + bn( wh[ 1 ] ) + ( 1920 === wh[ 1 ] ? ' · স্টোরি: ওপরে-নিচে অ্যাপের বোতামের জায়গা ফাঁকা রাখা হয়েছে' : ' · নীল ডটেড রেখা = নিরাপদ এলাকা (৬০ পিক্সেল)' ) + ( opts.ad ? ' · বিজ্ঞাপনসহ' : '' );
			stage.classList.toggle( 'is-story', 1920 === wh[ 1 ] );
			$( 'ms-busy' ).hidden = true;
			document.dispatchEvent( new CustomEvent( 'meeha:studio-rendered', { detail: { type: state.type, size: state.size, ad: !! opts.ad } } ) );
		} ).catch( function ( e ) { $( 'ms-busy' ).hidden = true; msg( 'ms-status', e.message, 'err' ); } );
	}
	window.MeehaStudioRender = render; // tests
	form.addEventListener( 'input', function ( e ) { if ( 'day' === state.type && soloOn() && e.target && e.target.hasAttribute( 'data-k' ) ) { syncAd(); } schedule(); } );
	form.addEventListener( 'change', function ( e ) { if ( e.target && 'ms-ffile' !== e.target.id ) { schedule(); } } );

	/* ------------------------------------------------------------ export */
	function nameFor( ext ) {
		var l = state.last, d = l ? l.data : {};
		var title = d.title || d.text || d.name || d.caption || '';
		var date = 'morning' === state.type ? state.date : '';
		return MC.filename( state.type, title, date, ext );
	}
	function ready() { return state.last ? Promise.resolve() : render(); }
	$( 'ms-png' ).addEventListener( 'click', function () { ready().then( function () { return MC.download( state.last.canvas, nameFor( 'png' ), 'image/png' ); } ).then( function () { msg( 'ms-status', 'PNG ডাউনলোড হয়েছে।', 'ok' ); } ); } );
	$( 'ms-jpg' ).addEventListener( 'click', function () { ready().then( function () { return MC.download( state.last.canvas, nameFor( 'jpg' ), 'image/jpeg' ); } ).then( function () { msg( 'ms-status', 'JPG ডাউনলোড হয়েছে।', 'ok' ); } ); } );
	if ( $( 'ms-save' ) ) {
		$( 'ms-save' ).addEventListener( 'click', function () {
			var btn = this; btn.disabled = true;
			msg( 'ms-status', 'মিডিয়া লাইব্রেরিতে রাখা হচ্ছে…' );
			ready().then( function () { return MC.toBlob( state.last.canvas, 'image/png' ); } ).then( function ( blob ) {
				var fd = new FormData(), name = nameFor( 'png' ), l = state.last.data;
				fd.append( 'file', blob, name );
				fd.append( 'title', ( S.types[ state.type ].label + ' — ' + ( l.title || l.text || l.name || l.caption || '' ) ).slice( 0, 180 ) );
				fd.append( 'alt_text', ( l.title || l.text || l.name || '' ).slice( 0, 180 ) );
				fd.append( 'meta[_meeha_card_type]', state.type );
				return api( S.wpRest + 'media', { method: 'POST', body: fd } );
			} ).then( function ( m ) {
				var th = m.media_details && m.media_details.sizes && m.media_details.sizes.thumbnail ? m.media_details.sizes.thumbnail.source_url : m.source_url;
				S.history.unshift( { id: m.id, url: m.source_url, thumb: th, type: state.type, title: m.title && m.title.rendered } );
				S.history = S.history.slice( 0, Math.max( 1, S.historyMax ) );
				paintHistory();
				msg( 'ms-status', 'মিডিয়া লাইব্রেরিতে রাখা হয়েছে (আইডি ' + bn( m.id ) + ')।', 'ok' );
			} ).catch( function ( e ) { msg( 'ms-status', e.message, 'err' ); } ).then( function () { btn.disabled = false; } );
		} );
	}
	function paintHistory() {
		var box = $( 'ms-history' ); box.innerHTML = '';
		if ( ! S.history.length ) { box.innerHTML = '<p class="desc">এখনো কোনো কার্ড রাখা হয়নি।</p>'; return; }
		S.history.forEach( function ( h ) {
			var a = document.createElement( 'a' ); a.href = h.url; a.target = '_blank'; a.rel = 'noopener';
			a.title = ( S.types[ h.type ] ? S.types[ h.type ].label : h.type ) + ( h.title ? ' — ' + h.title.replace( /<[^>]*>/g, '' ) : '' );
			var i = document.createElement( 'img' ); i.src = h.thumb || h.url; i.alt = a.title; i.loading = 'lazy';
			a.appendChild( i ); box.appendChild( a );
		} );
	}

	/* ------------------------------------------------------------ boot */
	paintHistory();
	applyType();
	if ( S.init.post ) { setVal( 'ms-post', String( S.init.post ) ); fetchSource( String( S.init.post ) ); }
	loadRecent( 0 );
	if ( 'quote' === state.type ) { loadQuotes( '' ); }
	qsa( '.ms-tabs .nav-tab[data-type="quote"]' ).forEach( function ( a ) { a.addEventListener( 'click', function () { if ( ! $( 'ms-qlist' ).children.length ) { loadQuotes( '' ); } } ); } );
}() );
