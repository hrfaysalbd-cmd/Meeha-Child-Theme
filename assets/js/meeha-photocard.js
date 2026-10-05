/**
 * Meeha News — ফটোকার্ড জেনারেটর
 *
 * প্রতিটা .meeha-photocard এলিমেন্টের data-* অ্যাট্রিবিউট থেকে ছবি/টাইটেল/লোগো/
 * বিজ্ঞাপন পড়ে, ব্রাউজারে <canvas>-এ পুরো কার্ডটা আঁকে, তারপর একটা পপ-আপ
 * মডালে প্রিভিউ দেখায় — মডালের ভেতরের বাটনে ক্লিক করলে তবেই PNG ডাউনলোড হয়।
 * data-layout অনুযায়ী তিন ধরনের ডিজাইন আঁকা হয়:
 *   - "banded": আর্টিকেলের অটো-কার্ড — ছবি উপরে, নিচে সলিড ব্যান্ডে সেন্টার-অ্যালাইনড হেডলাইন
 *   - "quote-simple": রাজনৈতিক বিশ্লেষক/অ্যাক্টিভিস্টদের উক্তি — কোট আইকন, গোলাকার ছবি, উক্তি ও নাম
 *   - "poll-result": ভোটের ফলাফল কার্ড — প্রশ্ন, বক্তা, ফলাফল ও বিজয়ী হাইলাইট
 */
( function () {
	'use strict';

	var CARD_WIDTH  = 1080;
	var CARD_HEIGHT = 1350; // 4:5 — ফেসবুক ও ইনস্টাগ্রাম দুটোতেই ভালো দেখায়

	function loadImage( src ) {
		return new Promise( function ( resolve, reject ) {
			if ( ! src ) {
				resolve( null );
				return;
			}
			var img = new Image();
			img.crossOrigin = 'anonymous';
			img.onload  = function () { resolve( img ); };
			img.onerror = function () { reject( new Error( 'ছবি লোড করা যায়নি: ' + src ) ); };
			img.src = src;
		} );
	}

	function loadFonts() {
		if ( ! document.fonts || ! document.fonts.load ) {
			return Promise.resolve();
		}
		return Promise.all( [
			document.fonts.load( '800 60px "Baloo Da 2"' ),
			document.fonts.load( '700 40px "Baloo Da 2"' ),
			document.fonts.load( '600 30px "Hind Siliguri"' ),
			document.fonts.load( '500 26px "Hind Siliguri"' ),
		] ).catch( function () {} );
	}

	function wrapText( ctx, text, maxWidth ) {
		var words = ( text || '' ).split( ' ' );
		var lines = [];
		var current = '';
		for ( var i = 0; i < words.length; i++ ) {
			var test = current ? current + ' ' + words[ i ] : words[ i ];
			if ( ctx.measureText( test ).width > maxWidth && current ) {
				lines.push( current );
				current = words[ i ];
			} else {
				current = test;
			}
		}
		if ( current ) {
			lines.push( current );
		}
		return lines;
	}

	// r must not exceed half of w/h, or arcTo() draws a broken/self-intersecting path.
	// CSS auto-clamps border-radius; canvas arcTo does not — this was the actual bug.
	function roundedRect( ctx, x, y, w, h, r ) {
		r = Math.max( 0, Math.min( r, w / 2, h / 2 ) );
		ctx.beginPath();
		ctx.moveTo( x + r, y );
		ctx.arcTo( x + w, y, x + w, y + h, r );
		ctx.arcTo( x + w, y + h, x, y + h, r );
		ctx.arcTo( x, y + h, x, y, r );
		ctx.arcTo( x, y, x + w, y, r );
		ctx.closePath();
	}

	function drawCover( ctx, img, x, y, w, h ) {
		var imgRatio = img.width / img.height;
		var boxRatio = w / h;
		var sx, sy, sw, sh;
		if ( imgRatio > boxRatio ) {
			sh = img.height;
			sw = sh * boxRatio;
			sx = ( img.width - sw ) / 2;
			sy = 0;
		} else {
			sw = img.width;
			sh = sw / boxRatio;
			sx = 0;
			sy = ( img.height - sh ) / 2;
		}
		ctx.drawImage( img, sx, sy, sw, sh, x, y, w, h );
	}

	// একটা ছবি গোলাকার (সার্কেল) করে ক্লিপ করে বসায়
	function drawCircleImage( ctx, img, cx, cy, radius ) {
		ctx.save();
		ctx.beginPath();
		ctx.arc( cx, cy, radius, 0, Math.PI * 2 );
		ctx.closePath();
		ctx.clip();
		drawCover( ctx, img, cx - radius, cy - radius, radius * 2, radius * 2 );
		ctx.restore();
	}

	/**
	 * সাইট লোগো (বা লোগো না থাকলে সাইটের নাম) — সবসময় অনুভূমিকভাবে সেন্টারে বসে,
	 * যেমনটা চাওয়া হয়েছে ("ফটোকার্ডে মিহা নিউজের লোগো সেন্টারে থাকবে")
	 */
	function drawCenteredBrand( ctx, logoImg, siteName, centerX, y, onDark ) {
		var pillBg = onDark ? 'rgba(255,255,255,0.94)' : '#FFFFFF';
		var pillBorder = onDark ? null : '#E6E6E2';

		if ( logoImg ) {
			var logoH = 72;
			var logoW = ( logoImg.width / logoImg.height ) * logoH;
			var boxW  = logoW + 40;
			var boxH  = logoH + 24;
			var boxX  = centerX - boxW / 2;
			roundedRect( ctx, boxX, y, boxW, boxH, 14 );
			ctx.fillStyle = pillBg;
			ctx.fill();
			if ( pillBorder ) {
				ctx.strokeStyle = pillBorder;
				ctx.lineWidth = 1;
				ctx.stroke();
			}
			ctx.drawImage( logoImg, boxX + 20, y + 12, logoW, logoH );
			return boxH;
		} else if ( siteName ) {
			ctx.font = '800 34px "Baloo Da 2", sans-serif';
			var textW = ctx.measureText( siteName ).width;
			var pillW = textW + 48;
			var pillX = centerX - pillW / 2;
			roundedRect( ctx, pillX, y, pillW, 66, 14 );
			ctx.fillStyle = pillBg;
			ctx.fill();
			ctx.fillStyle = '#146356';
			ctx.textAlign = 'center';
			ctx.textBaseline = 'middle';
			ctx.fillText( siteName, centerX, y + 33 );
			ctx.textAlign = 'left';
			ctx.textBaseline = 'alphabetic';
			return 66;
		}
		return 0;
	}

	/**
	 * "banded" লেআউট — সাধারণ আর্টিকেলের অটো-কার্ড।
	 * ছবি উপরে পুরোপুরি পরিষ্কার, নিচে সলিড ব্যান্ডে সেন্টার-অ্যালাইনড লোগো,
	 * ক্যাটাগরি ব্যাজ ও হেডলাইন — প্রতিটার মাঝে স্পষ্ট গ্যাপ রেখে।
	 */
	function drawBandedCard( ctx, data, images ) {
		var bgImg   = images.bg;
		var logoImg = images.logo;
		var adImg   = images.ad;

		var footerHeight = ( data.adEnabled && adImg ) ? 150 : 0;
		var bandHeight   = 540;
		var photoHeight  = CARD_HEIGHT - bandHeight - footerHeight;
		var centerX      = CARD_WIDTH / 2;
		var padX         = 80;
		var contentW     = CARD_WIDTH - padX * 2;

		// ছবি
		if ( bgImg ) {
			drawCover( ctx, bgImg, 0, 0, CARD_WIDTH, photoHeight );
		} else {
			ctx.fillStyle = '#146356';
			ctx.fillRect( 0, 0, CARD_WIDTH, photoHeight );
		}

		// সলিড ব্যান্ড
		ctx.fillStyle = '#146356';
		ctx.fillRect( 0, photoHeight, CARD_WIDTH, bandHeight );

		ctx.textAlign = 'center';

		// ১) লোগো — ব্যান্ডের একদম উপরে, সেন্টারে
		var logoY = photoHeight + 34;
		var logoBoxH = drawCenteredBrand( ctx, logoImg, data.siteName, centerX, logoY, true );

		var cursorY = logoY + logoBoxH + 46; // লোগোর পরে স্পষ্ট গ্যাপ

		// ২) ক্যাটাগরি ব্যাজ — সেন্টারে, নিজস্ব পিল-শেপ ব্যাকগ্রাউন্ডে (টাইটেলের সাথে না মিশে)
		if ( data.kicker ) {
			ctx.font = '700 28px "Hind Siliguri", sans-serif';
			var kickerW = ctx.measureText( data.kicker ).width;
			var pillW = kickerW + 44;
			roundedRect( ctx, centerX - pillW / 2, cursorY, pillW, 50, 999 );
			ctx.fillStyle = 'rgba(255,255,255,0.16)';
			ctx.fill();
			ctx.fillStyle = '#FFFFFF';
			ctx.textBaseline = 'middle';
			ctx.fillText( data.kicker, centerX, cursorY + 25 );
			ctx.textBaseline = 'alphabetic';
			cursorY += 50 + 40; // ব্যাজের পরে স্পষ্ট গ্যাপ, তারপরই টাইটেল শুরু
		} else {
			cursorY += 12;
		}

		// ৩) হেডলাইন — সেন্টার-অ্যালাইনড, একাধিক লাইনে
		ctx.font = '800 52px "Baloo Da 2", "Hind Siliguri", sans-serif';
		ctx.fillStyle = '#FFFFFF';
		var allTitleLines = wrapText( ctx, data.title, contentW );
		var titleLines = allTitleLines.slice( 0, 2 );
		if ( allTitleLines.length > 2 ) {
			titleLines[ 1 ] = titleLines[ 1 ].replace( /\s*$/, '' ) + '...';
		}
		titleLines.forEach( function ( line ) {
			ctx.fillText( line, centerX, cursorY );
			cursorY += 64;
		} );

		// CTA বাটন — বড় ও স্পষ্টভাবে হাইলাইট করা সাদা পিল, যাতে চোখ এড়িয়ে না যায়
		var ctaText = '🔗 নিউজ লিংক কমেন্টে';
		ctx.font = '800 36px "Hind Siliguri", sans-serif';
		var ctaW = ctx.measureText( ctaText ).width;
		var ctaPillW = ctaW + 76;
		var ctaPillH = 72;
		var ctaY = photoHeight + bandHeight - 108;

		// হালকা শ্যাডো, যাতে বাটনটা ব্যান্ডের ওপর ভেসে থাকার মতো লাগে
		ctx.save();
		ctx.shadowColor = 'rgba(0,0,0,0.25)';
		ctx.shadowBlur = 14;
		ctx.shadowOffsetY = 4;
		roundedRect( ctx, centerX - ctaPillW / 2, ctaY - ctaPillH / 2, ctaPillW, ctaPillH, 999 );
		ctx.fillStyle = '#FFFFFF';
		ctx.fill();
		ctx.restore();

		ctx.fillStyle = '#146356';
		ctx.textBaseline = 'middle';
		ctx.fillText( ctaText, centerX, ctaY + 2 );
		ctx.textBaseline = 'alphabetic';

		// ৫) তারিখ — ব্যান্ডের একদম নিচে
		if ( data.date ) {
			ctx.font = '500 26px "Hind Siliguri", sans-serif';
			ctx.fillStyle = 'rgba(255,255,255,0.72)';
			ctx.fillText( data.date, centerX, photoHeight + bandHeight - 34 );
		}

		ctx.textAlign = 'left';

		// ফুটার বিজ্ঞাপন ব্যান্ড
		if ( footerHeight && adImg ) {
			ctx.fillStyle = '#FFFFFF';
			ctx.fillRect( 0, photoHeight + bandHeight, CARD_WIDTH, footerHeight );
			drawCover( ctx, adImg, 0, photoHeight + bandHeight, CARD_WIDTH, footerHeight );
		}
	}

	/**
	 * "quote-simple" লেআউট — বিশ্লেষক/অ্যাক্টিভিস্টদের উক্তি। সাদা ব্যাকগ্রাউন্ড,
	 * ওপরে বড় কোট-আইকন, মাঝে উক্তি (সেন্টার), নিচে গোলাকার ছবি, নাম ও সেন্টার্ড লোগো।
	 */
	function drawQuoteSimpleCard( ctx, data, images ) {
		var avatarImg = images.bg;
		var logoImg   = images.logo;
		var adImg     = images.ad;

		var footerHeight  = ( data.adEnabled && adImg ) ? 150 : 0;
		var contentHeight = CARD_HEIGHT - footerHeight;

		ctx.fillStyle = '#FBFBF9';
		ctx.fillRect( 0, 0, CARD_WIDTH, contentHeight );

		var padX = 96;
		var contentW = CARD_WIDTH - padX * 2;
		var centerX = CARD_WIDTH / 2;

		ctx.textAlign = 'center';

		// tinted circle backdrop behind the quote icon
		ctx.beginPath();
		ctx.arc( centerX, 150, 74, 0, Math.PI * 2 );
		ctx.fillStyle = 'rgba(20,99,86,0.07)';
		ctx.fill();
		ctx.font = '800 120px Georgia, serif';
		ctx.fillStyle = '#146356';
		ctx.fillText( '\u201C', centerX, 200 );

		ctx.font = '700 46px "Baloo Da 2", "Hind Siliguri", sans-serif';
		ctx.fillStyle = '#14171F';
		var lines = wrapText( ctx, data.title, contentW ).slice( 0, 6 );
		var lineHeight = 64;
		var textBlockHeight = lines.length * lineHeight;
		var textStartY = 320;

		lines.forEach( function ( line, i ) {
			ctx.fillText( line, centerX, textStartY + i * lineHeight );
		} );

		// thin divider instead of heavy border
		var dividerY = textStartY + textBlockHeight + 56;
		ctx.strokeStyle = 'rgba(20,23,31,0.1)';
		ctx.lineWidth = 2;
		ctx.beginPath();
		ctx.moveTo( centerX - 60, dividerY );
		ctx.lineTo( centerX + 60, dividerY );
		ctx.stroke();

		var avatarY = dividerY + 100;
		var avatarR = 88;
		if ( avatarImg ) {
			ctx.save();
			ctx.shadowColor = 'rgba(20,23,31,0.25)';
			ctx.shadowBlur = 24;
			ctx.shadowOffsetY = 8;
			ctx.beginPath();
			ctx.arc( centerX, avatarY, avatarR, 0, Math.PI * 2 );
			ctx.fillStyle = '#fff';
			ctx.fill();
			ctx.restore();

			drawCircleImage( ctx, avatarImg, centerX, avatarY, avatarR - 4 );

			ctx.beginPath();
			ctx.arc( centerX, avatarY, avatarR - 2, 0, Math.PI * 2 );
			ctx.strokeStyle = '#fff';
			ctx.lineWidth = 4;
			ctx.stroke();
		}

		var nameParts = ( data.attribution || '' ).split( ',' );
		var name  = ( nameParts[ 0 ] || '' ).trim();
		var role  = nameParts.length > 1 ? nameParts.slice( 1 ).join( ',' ).trim() : '';

		var infoY = avatarY + avatarR + 60;
		if ( name ) {
			ctx.font = '700 38px "Hind Siliguri", sans-serif';
			ctx.fillStyle = '#14171F';
			ctx.fillText( name, centerX, infoY );
			infoY += 44;
		}
		if ( role ) {
			ctx.font = '500 28px "Hind Siliguri", sans-serif';
			ctx.fillStyle = '#146356';
			ctx.fillText( role, centerX, infoY );
			infoY += 40;
		}

		drawCenteredBrand( ctx, logoImg, data.siteName, centerX, Math.max( infoY + 24, contentHeight - 126 ), false );

		if ( footerHeight && adImg ) {
			ctx.fillStyle = '#FFFFFF';
			ctx.fillRect( 0, contentHeight, CARD_WIDTH, footerHeight );
			drawCover( ctx, adImg, 0, contentHeight, CARD_WIDTH, footerHeight );
		}

		ctx.textAlign = 'left';
	}

	/**
	 * "poll-result" লেআউট — ভোটের ফলাফল সোশ্যাল মিডিয়ায় শেয়ার করার কার্ড।
	 * প্রশ্ন কী ছিল (উক্তি), কার উক্তি (গোলাকার ছবি + নাম), দুই অপশনের ভোট-সংখ্যা,
	 * বিজয়ী অপশনটা রঙ/ব্যাজ দিয়ে হাইলাইট করা।
	 */
	function drawPollResultCard( ctx, data, images ) {
		var avatarImg = images.bg;
		var logoImg   = images.logo;
		var adImg     = images.ad;

		var footerHeight = ( data.adEnabled && adImg ) ? 150 : 0;

		ctx.fillStyle = '#FBFBF9';
		ctx.fillRect( 0, 0, CARD_WIDTH, CARD_HEIGHT );

		var centerX = CARD_WIDTH / 2;
		var padX = 90;
		var contentW = CARD_WIDTH - padX * 2;
		ctx.textAlign = 'center';

		// ব্র্যান্ডিং, সেন্টারে, একদম উপরে
		var logoH = drawCenteredBrand( ctx, logoImg, data.siteName, centerX, 56, false );

		// লেবেল
		ctx.font = '700 28px "Hind Siliguri", sans-serif';
		ctx.fillStyle = '#146356';
		ctx.fillText( 'ভোটের ফলাফল', centerX, 56 + logoH + 60 );

		// প্রশ্ন (মূল উক্তি/টাইটেল)
		ctx.font = '700 42px "Baloo Da 2", "Hind Siliguri", sans-serif';
		ctx.fillStyle = '#14171F';
		var qLines = wrapText( ctx, data.title, contentW ).slice( 0, 3 );
		var qY = 56 + logoH + 130;
		qLines.forEach( function ( line ) {
			ctx.fillText( line, centerX, qY );
			qY += 54;
		} );

		// বক্তা — গোলাকার ছবি + নাম
		var avatarY = qY + 90;
		var avatarR = 70;
		if ( avatarImg ) {
			ctx.save();
			ctx.beginPath();
			ctx.arc( centerX, avatarY, avatarR + 5, 0, Math.PI * 2 );
			ctx.fillStyle = '#146356';
			ctx.fill();
			ctx.restore();
			drawCircleImage( ctx, avatarImg, centerX, avatarY, avatarR );
		}
		var name = ( data.attribution || '' ).split( ',' )[ 0 ].trim();
		if ( name ) {
			ctx.font = '600 30px "Hind Siliguri", sans-serif';
			ctx.fillStyle = '#565A66';
			ctx.fillText( name, centerX, avatarY + avatarR + 46 );
		}

		// ফলাফল বার — দুটো অপশন, বিজয়ীটা হাইলাইট করা
		var total = Math.max( 1, data.countA + data.countB );
		var pctA  = Math.round( ( data.countA / total ) * 100 );
		var pctB  = Math.round( ( data.countB / total ) * 100 );

		var barTop = avatarY + avatarR + 100;
		var barW   = contentW;
		var barH   = 64;
		var barGap = 46;

		function drawResultBar( label, pct, count, y, isWinner, fillColor ) {
			ctx.textAlign = 'left';
			ctx.font = ( isWinner ? '800' : '600' ) + ' 32px "Hind Siliguri", sans-serif';
			ctx.fillStyle = isWinner ? '#146356' : '#14171F';
			ctx.fillText( label + ( isWinner ? '  🏆' : '' ), padX, y - 14 );

			ctx.textAlign = 'right';
			ctx.fillText( pct + '% (' + count + ')', padX + barW, y - 14 );

			roundedRect( ctx, padX, y, barW, barH, barH / 2 );
			ctx.fillStyle = '#E6E6E2';
			ctx.fill();

			var fillW = Math.max( barH, ( barW * pct ) / 100 );
			roundedRect( ctx, padX, y, fillW, barH, barH / 2 );
			ctx.fillStyle = fillColor;
			ctx.fill();

			if ( isWinner ) {
				roundedRect( ctx, padX - 4, y - 4, barW + 8, barH + 8, barH / 2 + 4 );
				ctx.strokeStyle = '#146356';
				ctx.lineWidth = 4;
				ctx.stroke();
			}
			ctx.textAlign = 'center';
		}

		drawResultBar( data.optionA, pctA, data.countA, barTop, 'a' === data.winner, '#146356' );
		drawResultBar( data.optionB, pctB, data.countB, barTop + barH + barGap, 'b' === data.winner, '#C08A2E' );

		ctx.font = '500 26px "Hind Siliguri", sans-serif';
		ctx.fillStyle = '#565A66';
		ctx.fillText( total + ' জন ভোট দিয়েছেন', centerX, barTop + ( barH + barGap ) * 2 + 20 );

		if ( footerHeight && adImg ) {
			var adTop = CARD_HEIGHT - footerHeight;
			ctx.fillStyle = '#FFFFFF';
			ctx.fillRect( 0, adTop, CARD_WIDTH, footerHeight );
			drawCover( ctx, adImg, 0, adTop, CARD_WIDTH, footerHeight );
		}

		ctx.textAlign = 'left';
	}

	/**
	 * পুরোনো "overlay" লেআউট — ব্যাকওয়ার্ড কম্প্যাটিবিলিটির জন্য রাখা হয়েছে,
	 * বর্তমানে কোনো টেমপ্লেট এটা ব্যবহার করছে না।
	 */
	function drawOverlayCard( ctx, data, images ) {
		var bgImg = images.bg, logoImg = images.logo, adImg = images.ad;
		var footerHeight = ( data.adEnabled && adImg ) ? 160 : 0;
		var photoHeight  = CARD_HEIGHT - footerHeight;

		if ( bgImg ) {
			drawCover( ctx, bgImg, 0, 0, CARD_WIDTH, photoHeight );
		} else {
			ctx.fillStyle = '#146356';
			ctx.fillRect( 0, 0, CARD_WIDTH, photoHeight );
		}

		var gradient = ctx.createLinearGradient( 0, photoHeight * 0.35, 0, photoHeight );
		gradient.addColorStop( 0, 'rgba(0,0,0,0)' );
		gradient.addColorStop( 1, 'rgba(0,0,0,0.88)' );
		ctx.fillStyle = gradient;
		ctx.fillRect( 0, 0, CARD_WIDTH, photoHeight );

		drawCenteredBrand( ctx, logoImg, data.siteName, CARD_WIDTH / 2, 56, true );

		var textLeft = 64, textWidth = CARD_WIDTH - 128, cursorY = photoHeight - 60;
		ctx.font = '800 56px "Baloo Da 2", "Hind Siliguri", sans-serif';
		var titleLines = wrapText( ctx, data.title, textWidth );

		if ( data.date ) {
			ctx.font = '500 26px "Hind Siliguri", sans-serif';
			ctx.fillStyle = 'rgba(255,255,255,0.75)';
			ctx.fillText( data.date, textLeft, cursorY );
			cursorY -= 44;
		}
		ctx.font = '800 56px "Baloo Da 2", "Hind Siliguri", sans-serif';
		ctx.fillStyle = '#FFFFFF';
		for ( var t = titleLines.length - 1; t >= 0; t-- ) {
			ctx.fillText( titleLines[ t ], textLeft, cursorY );
			cursorY -= 68;
		}

		if ( footerHeight && adImg ) {
			ctx.fillStyle = '#FFFFFF';
			ctx.fillRect( 0, photoHeight, CARD_WIDTH, footerHeight );
			drawCover( ctx, adImg, 0, photoHeight, CARD_WIDTH, footerHeight );
		}
	}

	function buildCard( data ) {
		return Promise.all( [
			loadImage( data.image ),
			loadImage( data.logo ),
			data.adEnabled ? loadImage( data.adImage ) : Promise.resolve( null ),
			loadFonts(),
		] ).then( function ( results ) {
			var canvas = document.createElement( 'canvas' );
			canvas.width  = CARD_WIDTH;
			canvas.height = CARD_HEIGHT;
			var ctx = canvas.getContext( '2d' );
			var images = { bg: results[ 0 ], logo: results[ 1 ], ad: results[ 2 ] };

			if ( 'quote-simple' === data.layout ) {
				drawQuoteSimpleCard( ctx, data, images );
			} else if ( 'banded' === data.layout ) {
				drawBandedCard( ctx, data, images );
			} else if ( 'poll-result' === data.layout ) {
				drawPollResultCard( ctx, data, images );
			} else {
				drawOverlayCard( ctx, data, images );
			}

			return canvas;
		} );
	}

	function triggerDownload( canvas, filename ) {
		canvas.toBlob( function ( blob ) {
			if ( ! blob ) {
				return;
			}
			var url = URL.createObjectURL( blob );
			var a = document.createElement( 'a' );
			a.href = url;
			a.download = filename;
			document.body.appendChild( a );
			a.click();
			document.body.removeChild( a );
			setTimeout( function () { URL.revokeObjectURL( url ); }, 4000 );
		}, 'image/png', 0.95 );
	}

	// ফাইলের নাম নিউমেরিক রাখা হচ্ছে (পোস্ট আইডি ভিত্তিক)
	function numericFilename( fileId ) {
		var idPart = fileId ? String( fileId ).replace( /[^0-9]/g, '' ) : '';
		var id = idPart || Date.now();
		return 'meeha-' + id + '.png';
	}

	// নিজস্ব হালকা মডাল — meeha-single.js এর window.meehaOpenModal থাকলে সেটাই ব্যবহার করে
	// (একই ভিজ্যুয়াল স্টাইল বজায় থাকে), না থাকলে (যেমন শুধু শর্টকোড-পেজে যেখানে
	// is_single() ফলস, তাই meeha-single.js লোড হয় না) এই ফলব্যাকটা ব্যবহৃত হয়
	function openPreviewModal( bodyHtml ) {
		if ( window.meehaOpenModal ) {
			window.meehaOpenModal( 'প্রিভিউ', bodyHtml );
			return;
		}

		var existing = document.getElementById( 'meeha-modal-overlay' );
		if ( existing ) {
			existing.remove();
		}
		var overlay = document.createElement( 'div' );
		overlay.className = 'meeha-modal-overlay';
		overlay.id = 'meeha-modal-overlay';
		overlay.innerHTML =
			'<div class="meeha-modal" role="dialog" aria-modal="true">' +
				'<button type="button" class="meeha-modal__close" aria-label="বন্ধ করুন">×</button>' +
				'<h3 class="meeha-modal__title">প্রিভিউ</h3>' +
				'<div class="meeha-modal__body">' + bodyHtml + '</div>' +
			'</div>';
		document.body.appendChild( overlay );
		document.body.classList.add( 'meeha-modal-open' );
		overlay.addEventListener( 'click', function ( e ) {
			if ( e.target === overlay || e.target.closest( '.meeha-modal__close' ) ) {
				overlay.remove();
				document.body.classList.remove( 'meeha-modal-open' );
			}
		} );
	}

	// fire-and-forget download counter, only fires when meehaPollAjax context exists
	function trackDownload( postId ) {
		if ( ! window.meehaPollAjax || ! postId ) {
			return;
		}
		var data = new FormData();
		data.append( 'action', 'meeha_track_download' );
		data.append( 'nonce', window.meehaPollAjax.nonce );
		data.append( 'post_id', postId );
		fetch( window.meehaPollAjax.url, { method: 'POST', body: data } ).catch( function () {} );
	}

	function handleDownloadClick( card, button ) {
		var originalLabel = button.innerHTML;
		button.disabled = true;
		button.textContent = 'তৈরি হচ্ছে...';

		var data = {
			layout:      card.dataset.layout || 'overlay',
			image:       card.dataset.image || '',
			logo:        card.dataset.logo || '',
			siteName:    card.dataset.sitename || '',
			title:       card.dataset.quote || '',
			kicker:      card.dataset.kicker || '',
			attribution: card.dataset.attribution || '',
			date:        card.dataset.date || '',
			adEnabled:   card.dataset.adEnabled === '1',
			adImage:     card.dataset.adImage || '',
			optionA:     card.dataset.optionA || 'একমত',
			optionB:     card.dataset.optionB || 'দ্বিমত',
			countA:      parseInt( card.dataset.countA, 10 ) || 0,
			countB:      parseInt( card.dataset.countB, 10 ) || 0,
			winner:      card.dataset.winner || '',
		};
		var filename = numericFilename( card.dataset.fileId );

		buildCard( data )
			.then( function ( canvas ) {
				var dataUrl = canvas.toDataURL( 'image/png' );
				var shareText = card.dataset.shareText || card.dataset.quote || '';
				var modalBody =
					'<img class="meeha-photocard-modal__img" src="' + dataUrl + '" alt="প্রিভিউ" />' +
					'<div class="meeha-photocard-modal__actions">' +
						'<button type="button" class="meeha-photocard-modal__download-btn">ডাউনলোড করুন</button>' +
						'<button type="button" class="meeha-photocard-modal__copy-img-btn"><span aria-hidden="true">🖼️</span> ছবি কপি করুন</button>' +
					'</div>' +
					( shareText
						? '<button type="button" class="meeha-copy-caption-btn meeha-photocard-modal__copy-btn" data-copy-text="' +
							shareText.replace( /"/g, '&quot;' ) + '"><span aria-hidden="true">🔗</span> ক্যাপশন কপি করুন</button>'
						: '' );

				openPreviewModal( modalBody );
				var modalBtn = document.querySelector( '.meeha-photocard-modal__download-btn' );
				if ( modalBtn ) {
					modalBtn.addEventListener( 'click', function () {
						triggerDownload( canvas, filename );
						trackDownload( card.dataset.postId );
					} );
				}
				var copyImgBtn = document.querySelector( '.meeha-photocard-modal__copy-img-btn' );
				if ( copyImgBtn ) {
					copyImgBtn.addEventListener( 'click', function () {
						var original = copyImgBtn.textContent;
						canvas.toBlob( function ( blob ) {
							if ( ! blob || ! navigator.clipboard || ! window.ClipboardItem ) {
								window.alert( 'এই ব্রাউজারে সরাসরি ছবি কপি সাপোর্ট করছে না। "ডাউনলোড করুন" ব্যবহার করুন।' );
								return;
							}
							navigator.clipboard.write( [ new ClipboardItem( { 'image/png': blob } ) ] )
								.then( function () {
									copyImgBtn.textContent = 'কপি হয়েছে ✓';
									setTimeout( function () { copyImgBtn.textContent = original; }, 2000 );
								} )
								.catch( function () {
									window.alert( 'ছবি কপি করা যায়নি। "ডাউনলোড করুন" ব্যবহার করুন।' );
								} );
						}, 'image/png' );
					} );
				}
			} )
			.catch( function ( err ) {
				console.error( 'Meeha photocard error:', err );
				window.alert(
					'ফটোকার্ড তৈরি করা যায়নি। সম্ভবত ছবিটি এমন একটি সার্ভার/CDN থেকে আসছে যা ' +
					'ক্রস-অরিজিন অ্যাক্সেসের অনুমতি দিচ্ছে না। ছবিটি সরাসরি এই ওয়ার্ডপ্রেস ' +
					'মিডিয়া লাইব্রেরিতে আপলোড করা থাকলে সাধারণত এই সমস্যা হয় না।'
				);
			} )
			.finally( function () {
				button.disabled = false;
				button.innerHTML = originalLabel;
			} );
	}

	document.addEventListener( 'click', function ( e ) {
		var copyBtn = e.target.closest( '.meeha-copy-caption-btn' );
		if ( copyBtn ) {
			var text = copyBtn.getAttribute( 'data-copy-text' ) || '';
			if ( ! text ) {
				return;
			}
			var original = copyBtn.textContent;
			var finish = function () {
				copyBtn.textContent = 'কপি হয়েছে ✓';
				setTimeout( function () { copyBtn.textContent = original; }, 2000 );
			};
			if ( navigator.clipboard ) {
				navigator.clipboard.writeText( text ).then( finish );
			} else {
				var temp = document.createElement( 'textarea' );
				temp.value = text;
				document.body.appendChild( temp );
				temp.select();
				document.execCommand( 'copy' );
				document.body.removeChild( temp );
				finish();
			}
			return;
		}

		var button = e.target.closest( '.meeha-photocard__download' );
		if ( ! button ) {
			return;
		}
		var card = button.closest( '.meeha-photocard' );
		if ( ! card ) {
			return;
		}
		handleDownloadClick( card, button );
	} );
} )();