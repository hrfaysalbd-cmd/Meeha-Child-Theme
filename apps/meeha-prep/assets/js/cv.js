/**
 * CV studio: gallery thumbnails and the editor's live preview.
 * The template language is the same small Mustache subset MHP_CV::render() implements in PHP:
 * {{field}} (escaped), {{#list}}…{{/list}} (repeat, or show when filled), {{^field}}…{{/field}} (show when empty).
 * Preview frames are sandboxed without scripts; the design's HTML/CSS never runs in this page.
 */
( function () {
	'use strict';

	var MM = 96 / 25.4;
	var PAGE = 297 * MM;
	var A4W = 210 * MM;

	/* ---------- gallery thumbnails ---------- */
	function fitThumbs() {
		document.querySelectorAll( '[data-cvthumb]' ).forEach( function ( box ) {
			var f = box.querySelector( 'iframe' );
			if ( f ) {
				f.style.transform = 'scale(' + ( box.clientWidth / A4W ) + ')';
			}
		} );
	}
	fitThumbs();
	window.addEventListener( 'resize', fitThumbs );

	/* ---------- confirm + copy (any CV page) ---------- */
	document.querySelectorAll( 'form[data-confirm]' ).forEach( function ( f ) {
		f.addEventListener( 'submit', function ( e ) {
			if ( ! window.confirm( f.getAttribute( 'data-confirm' ) ) ) {
				e.preventDefault();
			}
		} );
	} );
	document.querySelectorAll( '[data-copy]' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			var inp = b.parentNode.querySelector( '[data-copy-src]' );
			if ( ! inp ) {
				return;
			}
			var done = function () {
				b.textContent = 'কপি হয়েছে';
				setTimeout( function () {
					b.textContent = 'কপি';
				}, 1800 );
			};
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( inp.value ).then( done );
			} else {
				inp.select();
				document.execCommand( 'copy' );
				done();
			}
		} );
	} );

	var root = document.querySelector( '[data-cved]' );
	var confEl = document.getElementById( 'mc-cv-conf' );
	if ( ! root || ! confEl ) {
		return;
	}
	var conf;
	try {
		conf = JSON.parse( confEl.textContent );
	} catch ( e ) {
		return;
	}
	var form = document.getElementById( 'mc-cvform' );
	var frame = document.getElementById( 'mc-cvframe' );
	var paper = root.querySelector( '[data-paper]' );
	var tplSel = document.getElementById( 'mc-cv-tpl' );
	var langSel = document.getElementById( 'mc-cv-lang' );
	var photo = conf.photo || '';
	var photoRemoved = false;

	/* ---------- template language ---------- */
	function esc( s ) {
		return String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' ).replace( /'/g, '&#039;' );
	}
	var TAG = /(\{\{\s*[#^\/]?\s*[A-Za-z0-9_.]+\s*\}\})/;
	var ONE = /^\{\{\s*([#^\/]?)\s*([A-Za-z0-9_.]+)\s*\}\}$/;
	function build( parts, st, close, depth ) {
		var out = [];
		while ( st.i < parts.length ) {
			var p = parts[ st.i++ ];
			if ( '' === p ) {
				continue;
			}
			var m = ONE.exec( p );
			if ( m ) {
				if ( '/' === m[ 1 ] ) {
					if ( m[ 2 ] === close ) {
						return out;
					}
					continue;
				}
				if ( '' === m[ 1 ] ) {
					out.push( [ 'v', m[ 2 ] ] );
					continue;
				}
				out.push( [ m[ 1 ], m[ 2 ], depth < 12 ? build( parts, st, m[ 2 ], depth + 1 ) : [] ] );
				continue;
			}
			out.push( [ 't', p ] );
		}
		return out;
	}
	function lookup( name, stack ) {
		if ( '.' === name ) {
			return stack[ stack.length - 1 ];
		}
		var path = name.split( '.' );
		var val = null;
		for ( var k = stack.length - 1; k >= 0; k-- ) {
			var c = stack[ k ];
			if ( c && 'object' === typeof c && Object.prototype.hasOwnProperty.call( c, path[ 0 ] ) ) {
				val = c[ path[ 0 ] ];
				break;
			}
		}
		for ( var j = 1; j < path.length; j++ ) {
			val = val && 'object' === typeof val && Object.prototype.hasOwnProperty.call( val, path[ j ] ) ? val[ path[ j ] ] : null;
		}
		return val;
	}
	function truthy( v ) {
		if ( Array.isArray( v ) ) {
			return v.length > 0;
		}
		if ( v && 'object' === typeof v ) {
			return Object.keys( v ).length > 0;
		}
		if ( 'string' === typeof v ) {
			return '' !== v.trim();
		}
		return !! v;
	}
	function run( nodes, stack ) {
		var html = '';
		nodes.forEach( function ( nd ) {
			if ( 't' === nd[ 0 ] ) {
				html += nd[ 1 ];
				return;
			}
			var v = lookup( nd[ 1 ], stack );
			if ( 'v' === nd[ 0 ] ) {
				html += ( 'string' === typeof v || 'number' === typeof v ) ? esc( v ) : '';
			} else if ( '#' === nd[ 0 ] ) {
				if ( Array.isArray( v ) ) {
					v.forEach( function ( row ) {
						html += run( nd[ 2 ], stack.concat( [ row ] ) );
					} );
				} else if ( truthy( v ) ) {
					html += run( nd[ 2 ], v && 'object' === typeof v ? stack.concat( [ v ] ) : stack );
				}
			} else if ( '^' === nd[ 0 ] && ! truthy( v ) ) {
				html += run( nd[ 2 ], stack );
			}
		} );
		return html;
	}
	var cache = {};
	function render( tpl, ctx ) {
		if ( ! cache[ tpl ] ) {
			cache[ tpl ] = build( tpl.split( TAG ), { i: 0 }, '', 0 );
		}
		return run( cache[ tpl ], [ ctx ] );
	}

	/* ---------- form → data (same limits as MHP_CV::clean) ---------- */
	function collect() {
		var raw = {};
		Array.prototype.forEach.call( form.elements, function ( el ) {
			if ( ! el.name || 0 !== el.name.indexOf( 'cv[' ) || el.disabled ) {
				return;
			}
			var path = el.name.slice( 2 ).replace( /^\[|\]$/g, '' ).split( '][' );
			var o = raw;
			for ( var i = 0; i < path.length - 1; i++ ) {
				o[ path[ i ] ] = o[ path[ i ] ] || {};
				o = o[ path[ i ] ];
			}
			o[ path[ path.length - 1 ] ] = el.value;
		} );
		var d = {};
		[ 'name', 'headline', 'phone', 'email', 'address', 'website', 'summary', 'declaration' ].forEach( function ( k ) {
			d[ k ] = ( raw[ k ] || '' ).trim();
		} );
		if ( d.website && ! /^https?:\/\//i.test( d.website ) ) {
			d.website = 'https://' + d.website;
		}
		d.personal = {};
		var p = raw.personal || {};
		[ 'father', 'mother', 'dob', 'nationality', 'religion', 'marital', 'permanent' ].forEach( function ( k ) {
			d.personal[ k ] = ( p[ k ] || '' ).trim();
		} );
		Object.keys( conf.lists ).forEach( function ( list ) {
			var def = conf.lists[ list ];
			var rows = raw[ list ];
			if ( def.lines ) {
				rows = ( 'string' === typeof rows ? rows : '' ).split( /\r\n|\r|\n/ ).map( function ( l ) {
					var r = {};
					r[ def.lines ] = l;
					return r;
				} );
			} else {
				rows = rows && 'object' === typeof rows ? Object.keys( rows ).map( function ( k ) {
					return rows[ k ];
				} ) : [];
			}
			d[ list ] = [];
			rows.forEach( function ( row ) {
				if ( d[ list ].length >= def.max ) {
					return;
				}
				var c = {};
				var any = false;
				Object.keys( def.fields ).forEach( function ( f ) {
					c[ f ] = String( row[ f ] || '' ).trim().slice( 0, def.fields[ f ] );
					any = any || '' !== c[ f ];
				} );
				if ( any ) {
					d[ list ].push( c );
				}
			} );
		} );
		d.lang = 'en' === raw.lang ? 'en' : 'bn';
		return d;
	}
	function context( d ) {
		var c = Object.assign( {}, d );
		Object.keys( conf.lists ).forEach( function ( l ) {
			c[ 'has_' + l ] = d[ l ].length > 0;
		} );
		c.has_personal = Object.keys( d.personal ).some( function ( k ) {
			return '' !== d.personal[ k ];
		} );
		c.photo = photoRemoved ? '' : photo;
		c.L = conf.labels[ d.lang ];
		return c;
	}

	/* ---------- preview ---------- */
	function current() {
		return conf.tpls[ tplSel.value ] || null;
	}
	var GUIDE = '.pv-guides{position:absolute;inset:0;z-index:40;pointer-events:none;background:repeating-linear-gradient(to bottom,transparent 0,transparent calc(297mm - 1px),rgba(2,90,178,.55) calc(297mm - 1px),rgba(2,90,178,.55) 297mm)}';
	function doc( t, ctx ) {
		return '<!doctype html><html lang="' + ctx.lang + '"><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="' + esc( conf.csp ) + '"><style>' + conf.frame + t.frame + GUIDE + '</style><style>' + t.css + '</style></head><body><div class="cv-clip">' +
			render( t.html, ctx ) + ( t.owned ? '' : '<div class="cv-wm"></div>' ) + '<div class="pv-guides"></div></div></body></html>';
	}
	var timer = 0;
	function refresh() {
		clearTimeout( timer );
		timer = setTimeout( function () {
			var t = current();
			if ( ! t ) {
				return;
			}
			frame.srcdoc = doc( t, context( collect() ) );
			status( t );
		}, 180 );
	}
	function status( t ) {
		var lock = root.querySelector( '[data-lock]' );
		lock.hidden = !! t.owned;
		var buy = lock.querySelector( '[data-buy]' );
		if ( buy ) {
			buy.href = t.buy;
			buy.textContent = t.pricet + '-এ কিনুন';
		}
	}
	function measure() {
		var t = current();
		var d = frame.contentDocument;
		if ( ! t || ! d || ! d.body ) {
			return;
		}
		var cv = d.querySelector( '.cv-clip > *' );
		if ( ! cv ) {
			return;
		}
		cv.style.minHeight = '';
		var n = Math.max( 1, Math.ceil( ( cv.scrollHeight - 2 ) / PAGE ) );
		cv.style.minHeight = ( Math.min( n, t.pages ) * 297 ) + 'mm';
		var h = Math.min( n, t.pages ) * PAGE;
		frame.style.height = Math.ceil( h ) + 'px';
		fit( h );
		root.querySelector( '[data-pages]' ).textContent = ( n > t.pages ? 'লেখা এখন ' + bn( n ) + ' পাতা (এই টেমপ্লেটের সীমা ' + bn( t.pages ) + ' পাতা)' : 'এই টেমপ্লেটে সর্বোচ্চ ' + bn( t.pages ) + ' পাতা · এখন ' + bn( n ) + ' পাতা' );
		var over = root.querySelector( '[data-over]' );
		over.hidden = n <= t.pages;
		over.textContent = n > t.pages ? 'বাড়তি অংশ পিডিএফে কাটা পড়বে। কিছু লেখা ছোট করুন' + ( t.pages < 3 ? ' বা বেশি পাতার টেমপ্লেট নিন।' : '।' ) : '';
	}
	function fit( h ) {
		var s = Math.min( 1, paper.clientWidth / A4W );
		frame.style.transform = 'scale(' + s + ')';
		paper.style.height = Math.ceil( ( h || frame.offsetHeight ) * s ) + 'px';
	}
	function bn( n ) {
		return String( n ).replace( /\d/g, function ( c ) {
			return '০১২৩৪৫৬৭৮৯'[ c ];
		} );
	}
	frame.addEventListener( 'load', function () {
		measure();
		var d = frame.contentDocument;
		if ( d && d.fonts && d.fonts.ready ) {
			d.fonts.ready.then( measure );
		}
	} );
	window.addEventListener( 'resize', function () {
		fit();
	} );

	/* ---------- repeaters ---------- */
	root.addEventListener( 'click', function ( e ) {
		var add = e.target.closest( '[data-add]' );
		if ( add ) {
			var list = add.getAttribute( 'data-add' );
			var box = root.querySelector( '[data-rep="' + list + '"]' );
			var tpl = root.querySelector( 'template[data-tpl="' + list + '"]' );
			if ( box.querySelectorAll( '[data-row]' ).length >= +box.getAttribute( 'data-max' ) ) {
				add.disabled = true;
				return;
			}
			var i = +box.getAttribute( 'data-next' );
			box.setAttribute( 'data-next', i + 1 );
			box.insertAdjacentHTML( 'beforeend', tpl.innerHTML.replace( /__i__/g, String( i ) ) );
			add.disabled = box.querySelectorAll( '[data-row]' ).length >= +box.getAttribute( 'data-max' );
			var first = box.lastElementChild.querySelector( 'input,textarea' );
			if ( first ) {
				first.focus();
			}
			return;
		}
		var rm = e.target.closest( '[data-rm]' );
		if ( rm ) {
			var row = rm.closest( '[data-row]' );
			var rep = row.parentNode;
			row.parentNode.removeChild( row );
			var again = root.querySelector( '[data-add="' + rep.getAttribute( 'data-rep' ) + '"]' );
			if ( again ) {
				again.disabled = false;
			}
			dirty();
			refresh();
			return;
		}
		var sw = e.target.closest( '[data-show]' );
		if ( sw ) {
			e.preventDefault();
			var show = sw.getAttribute( 'data-show' );
			root.setAttribute( 'data-view', show );
			root.querySelectorAll( '[data-show]' ).forEach( function ( a ) {
				var on = a === sw;
				a.classList.toggle( 'is-on', on );
				a.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			} );
			if ( 'preview' === show ) {
				fit();
			}
		}
	} );

	/* ---------- inputs ---------- */
	var isDirty = false;
	var dirtyEl = root.querySelector( '[data-dirty]' );
	function dirty() {
		isDirty = true;
		if ( dirtyEl ) {
			dirtyEl.hidden = false;
		}
	}
	form.addEventListener( 'input', function () {
		dirty();
		refresh();
	} );
	form.addEventListener( 'change', function ( e ) {
		var el = e.target;
		dirty();
		if ( 'cv_photo' === el.name && el.files && el.files[ 0 ] ) {
			var r = new FileReader();
			r.onload = function () {
				photo = String( r.result );
				photoRemoved = false;
				refresh();
			};
			r.readAsDataURL( el.files[ 0 ] );
			return;
		}
		if ( el.hasAttribute( 'data-photo-rm' ) ) {
			photoRemoved = el.checked;
		}
		if ( el === langSel ) {
			var decl = document.getElementById( 'mc-cv-cv-declaration-' );
			var other = 'en' === langSel.value ? 'bn' : 'en';
			if ( decl && decl.value.trim() === conf.decl[ other ] ) {
				decl.value = conf.decl[ langSel.value ];
			}
		}
		refresh();
	} );
	form.addEventListener( 'submit', function () {
		isDirty = false;
	} );
	window.addEventListener( 'beforeunload', function ( e ) {
		if ( isDirty ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	root.setAttribute( 'data-view', 'form' );
	refresh();
}() );
