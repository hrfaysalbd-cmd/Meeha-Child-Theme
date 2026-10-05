/**
 * Meeha News — সিঙ্গেল আর্টিকেল পেজের ইন্টারঅ্যাকশন
 */
( function () {
	'use strict';

	// রিডিং প্রোগ্রেস বার — স্ক্রল অনুযায়ী পূরণ হয়
	var bar = document.getElementById( 'meeha-progress__bar' );
	var article = document.querySelector( '.meeha-article' );

	if ( bar && article ) {
		var updateProgress = function () {
			var articleTop = article.offsetTop;
			var articleHeight = article.offsetHeight;
			var scrolled = window.scrollY - articleTop;
			var percent = ( scrolled / articleHeight ) * 100;
			percent = Math.max( 0, Math.min( 100, percent ) );
			bar.style.width = percent + '%';
		};

		window.addEventListener( 'scroll', updateProgress, { passive: true } );
		window.addEventListener( 'resize', updateProgress );
		updateProgress();
	}

	// "লিংক কপি করুন" বাটন
	var copyButtons = document.querySelectorAll( '.meeha-share__copy' );
	copyButtons.forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var url = btn.getAttribute( 'data-url' );
			if ( navigator.clipboard ) {
				navigator.clipboard.writeText( url ).then( function () {
					meehaShowCopied( btn );
				} );
			} else {
				// পুরনো ব্রাউজারের জন্য fallback
				var temp = document.createElement( 'textarea' );
				temp.value = url;
				document.body.appendChild( temp );
				temp.select();
				document.execCommand( 'copy' );
				document.body.removeChild( temp );
				meehaShowCopied( btn );
			}
		} );
	} );

	function meehaShowCopied( btn ) {
		// শুধু লেবেল স্প্যান বদলানো হচ্ছে, পাশের কাউন্ট স্প্যান অক্ষত থাকে
		var label = btn.querySelector( '.meeha-share__label' );
		if ( ! label ) {
			return;
		}
		var originalText = label.textContent;
		label.textContent = 'কপি হয়েছে ✓';
		btn.classList.add( 'meeha-share__copied' );
		setTimeout( function () {
			label.textContent = originalText;
			btn.classList.remove( 'meeha-share__copied' );
		}, 2000 );
	}

	// প্রতিটা শেয়ার বাটন ক্লিকে কাউন্ট ও মোট বাড়ানো — লিংক ওপেন হওয়া/কপি হওয়ার পাশাপাশি
	document.querySelectorAll( '.meeha-share' ).forEach( function ( shareWrap ) {
		var postId = shareWrap.getAttribute( 'data-post-id' );
		var totalEl = shareWrap.parentElement.querySelector( '[data-total-count]' );

		shareWrap.querySelectorAll( '[data-share-type]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				if ( ! postId || ! window.meehaPollAjax ) {
					return;
				}
				var type = btn.getAttribute( 'data-share-type' );
				var data = new FormData();
				data.append( 'action', 'meeha_track_share' );
				data.append( 'nonce', window.meehaPollAjax.nonce );
				data.append( 'post_id', postId );
				data.append( 'share_type', type );

				fetch( window.meehaPollAjax.url, { method: 'POST', body: data } )
					.then( function ( res ) { return res.json(); } )
					.then( function ( res ) {
						if ( ! res.success ) {
							return;
						}
						var countEl = shareWrap.querySelector( '[data-count="' + type + '"]' );
						if ( countEl ) {
							countEl.textContent = res.data.counts[ type ];
						}
						if ( totalEl ) {
							totalEl.textContent = res.data.total;
						}
					} )
					.catch( function () {} );
			} );
		} );
	} );

	// "আরও পড়ুন" — লম্বা আর্টিকেলে কিছুটা পড়ার পর বাকিটা ফেইড করে বাটনের
	// পেছনে লুকিয়ে রাখা হয়; ছোট আর্টিকেলে কিছুই বদলায় না
	var wrap = document.getElementById( 'meeha-body-wrap' );
	var body = document.getElementById( 'meeha-article-body' );
	var readmoreBtn = document.getElementById( 'meeha-readmore-btn' );

	if ( wrap && body && readmoreBtn ) {
		var TRUNCATE_TRIGGER_HEIGHT = window.meehaReadmoreHeight || 900; // এর বেশি লম্বা হলেই "আরও পড়ুন" দেখাবে

		if ( body.scrollHeight > TRUNCATE_TRIGGER_HEIGHT ) {
			wrap.classList.add( 'meeha-truncated' );
			readmoreBtn.hidden = false;

			readmoreBtn.addEventListener( 'click', function () {
				wrap.classList.remove( 'meeha-truncated' );
				readmoreBtn.hidden = true;
			} );
		}
	}

	// "সারাংশ পড়ুন" — বাটনে ক্লিক করলে পপ-আপে সারাংশ (মেটাবক্সে যা পেস্ট করা আছে তা) দেখায়,
	// শেষে "ক্যাপশন কপি করুন", "সম্পূর্ণ নিউজ পড়ুন" ও "হোমে ফিরুন" — তিনটা একশন বাটন থাকে
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.meeha-summary-btn' );
		if ( ! btn ) {
			return;
		}
		var targetId = btn.getAttribute( 'data-summary-target' );
		var content  = document.getElementById( targetId );
		if ( ! content ) {
			return;
		}
		var homeUrl = window.meehaHomeUrl || '/';

		var bodyHtml =
			content.innerHTML +
			'<div class="meeha-summary-actions">' +
				'<button type="button" class="meeha-copy-caption-btn meeha-summary-actions__btn meeha-summary-actions__btn--secondary" data-copy-target="' + targetId + '">ক্যাপশন কপি করুন</button>' +
				'<button type="button" class="meeha-modal-dismiss meeha-summary-actions__btn meeha-summary-actions__btn--primary">সম্পূর্ণ নিউজ পড়ুন</button>' +
				'<a href="' + homeUrl + '" class="meeha-summary-actions__btn meeha-summary-actions__btn--secondary">হোমে ফিরুন</a>' +
			'</div>';

		meehaOpenModal( 'সারাংশ', bodyHtml );
	} );

	// generic clipboard-copy handler - copies the plain text of whatever element data-copy-target points to
	document.addEventListener( 'click', function ( e ) {
		var copyBtn = e.target.closest( '.meeha-copy-caption-btn' );
		if ( ! copyBtn ) {
			return;
		}
		var targetId = copyBtn.getAttribute( 'data-copy-target' );
		var source    = document.getElementById( targetId );
		var text      = copyBtn.getAttribute( 'data-copy-text' ) || ( source ? source.textContent.trim() : '' );
		if ( ! text ) {
			return;
		}
		var original = copyBtn.textContent;
		var doneText = 'কপি হয়েছে ✓';
		if ( navigator.clipboard ) {
			navigator.clipboard.writeText( text ).then( function () {
				copyBtn.textContent = doneText;
				setTimeout( function () { copyBtn.textContent = original; }, 2000 );
			} );
		} else {
			var temp = document.createElement( 'textarea' );
			temp.value = text;
			document.body.appendChild( temp );
			temp.select();
			document.execCommand( 'copy' );
			document.body.removeChild( temp );
			copyBtn.textContent = doneText;
			setTimeout( function () { copyBtn.textContent = original; }, 2000 );
		}
	} );

	/**
	 * একটা সাধারণ (generic) মডাল/পপ-আপ — সারাংশ আর ফটোকার্ড প্রিভিউ দুটোতেই ব্যবহৃত হয়
	 */
	function meehaOpenModal( title, bodyHtml ) {
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
				'<h3 class="meeha-modal__title">' + title + '</h3>' +
				'<div class="meeha-modal__body">' + bodyHtml + '</div>' +
			'</div>';

		document.body.appendChild( overlay );
		document.body.classList.add( 'meeha-modal-open' );

		function close() {
			overlay.remove();
			document.body.classList.remove( 'meeha-modal-open' );
		}

		overlay.addEventListener( 'click', function ( e ) {
			if ( e.target === overlay || e.target.closest( '.meeha-modal__close' ) || e.target.closest( '.meeha-modal-dismiss' ) ) {
				close();
			}
		} );
		document.addEventListener( 'keydown', function escHandler( e ) {
			if ( 'Escape' === e.key ) {
				close();
				document.removeEventListener( 'keydown', escHandler );
			}
		} );
	}
	window.meehaOpenModal = meehaOpenModal;

	// পোল ভোট সাবমিট — AJAX দিয়ে, পেজ রিলোড ছাড়াই
	var pollForm = document.getElementById( 'meeha-poll-form' );
	if ( pollForm && window.meehaPollAjax ) {
		var pollSection = document.getElementById( 'meeha-poll' );
		var pollPostId  = pollSection ? pollSection.getAttribute( 'data-post-id' ) : '';

		pollForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();

			var msg      = document.getElementById( 'meeha-poll-msg' );
			var button   = pollForm.querySelector( '.meeha-poll__submit' );
			var original = button.textContent;
			var voteSucceeded = false;

			button.disabled = true;
			button.textContent = 'পাঠানো হচ্ছে...';
			msg.textContent = '';
			msg.classList.remove( 'meeha-poll__msg--error' );

			var data = new FormData( pollForm );
			data.append( 'action', 'meeha_poll_vote' );
			data.append( 'nonce', window.meehaPollAjax.nonce );
			data.append( 'post_id', pollPostId );

			fetch( window.meehaPollAjax.url, { method: 'POST', body: data } )
				.then( function ( res ) { return res.text(); } )
				.then( function ( text ) {
					var parsed;
					try {
						parsed = JSON.parse( text );
					} catch ( err ) {
						console.error( 'Meeha poll: অপ্রত্যাশিত সার্ভার রেসপন্স —', text );
						throw new Error( 'invalid-json' );
					}
					msg.textContent = parsed.data && parsed.data.message ? parsed.data.message : '';
					if ( parsed.success ) {
						voteSucceeded = true;
						pollForm.reset();
						pollForm.querySelectorAll( 'input' ).forEach( function ( el ) { el.disabled = true; } );
						refreshLiveTally(); // ভোট দেওয়ার সাথে সাথে লাইভ কাউন্ট ও মার্কি আপডেট হবে
					} else {
						msg.classList.add( 'meeha-poll__msg--error' );
					}
				} )
				.catch( function ( err ) {
					console.error( 'Meeha poll error:', err );
					msg.textContent = 'সমস্যা হয়েছে, আবার চেষ্টা করুন।';
					msg.classList.add( 'meeha-poll__msg--error' );
				} )
				.finally( function () {
					if ( voteSucceeded ) {
						button.textContent = 'ভোট দেওয়া হয়েছে ✓';
						button.classList.add( 'meeha-poll__submit--done' );
						// বাটন চিরতরে disabled-ই থাকবে, যাতে একই সেশনে আবার ভোট দেওয়া না যায়
					} else {
						button.disabled = false;
						button.textContent = original;
					}
				} );
		} );
	}

	// কাউন্টডাউন টাইমার — "*** সময় বাকি আছে"
	var countdownEl = document.getElementById( 'meeha-poll-countdown' );
	if ( countdownEl ) {
		var countdownText = document.getElementById( 'meeha-poll-countdown-text' );
		var endTime = parseInt( countdownEl.getAttribute( 'data-end' ), 10 ) * 1000;

		var updateCountdown = function () {
			var diff = endTime - Date.now();
			if ( diff <= 0 ) {
				countdownText.textContent = 'মেয়াদ শেষ হয়েছে';
				clearInterval( countdownTimer );
				return;
			}
			var days    = Math.floor( diff / ( 24 * 60 * 60 * 1000 ) );
			var hours   = Math.floor( ( diff % ( 24 * 60 * 60 * 1000 ) ) / ( 60 * 60 * 1000 ) );
			var minutes = Math.floor( ( diff % ( 60 * 60 * 1000 ) ) / ( 60 * 1000 ) );
			var seconds = Math.floor( ( diff % ( 60 * 1000 ) ) / 1000 );

			var parts = [];
			if ( days > 0 ) {
				parts.push( days + ' দিন' );
			}
			if ( days > 0 || hours > 0 ) {
				parts.push( hours + ' ঘণ্টা' );
			}
			parts.push( minutes + ' মিনিট' );
			if ( days === 0 && hours === 0 ) {
				parts.push( seconds + ' সেকেন্ড' );
			}
			countdownText.textContent = parts.join( ' ' );
		};

		updateCountdown();
		var countdownTimer = setInterval( updateCountdown, 1000 );
	}

	// লাইভ ভোট-গণনা ও মার্কি — প্রতি ১৫ সেকেন্ডে রিফ্রেশ হয়, যাতে অন্য পাঠকের
	// ভোটও লাইভ দেখা যায় (শুধু নিজে ভোট দিলেই না)
	function refreshLiveTally() {
		if ( ! pollPostId || ! window.meehaPollAjax ) {
			return;
		}

		var data = new FormData();
		data.append( 'action', 'meeha_poll_live_tally' );
		data.append( 'nonce', window.meehaPollAjax.nonce );
		data.append( 'post_id', pollPostId );

		fetch( window.meehaPollAjax.url, { method: 'POST', body: data } )
			.then( function ( res ) { return res.json(); } )
			.then( function ( res ) {
				if ( ! res.success ) {
					return;
				}
				var liveCountEl    = document.getElementById( 'meeha-poll-live-count' );
				var marqueeCountEl = document.getElementById( 'meeha-poll-marquee-count' );
				var marqueeTrack   = document.getElementById( 'meeha-poll-marquee-track' );

				if ( liveCountEl ) {
					liveCountEl.textContent = res.data.total;
				}
				if ( marqueeCountEl ) {
					marqueeCountEl.textContent = res.data.total;
				}
				if ( marqueeTrack && res.data.voters && res.data.voters.length ) {
					var html = '';
					for ( var r = 0; r < 2; r++ ) {
						res.data.voters.forEach( function ( voter ) {
							html += '<span class="meeha-poll__marquee-item">' + voter.name + ' — ' + voter.phone + '</span>';
						} );
					}
					marqueeTrack.innerHTML = html;
				}
			} )
			.catch( function () {} );
	}

	if ( pollForm ) {
		setInterval( refreshLiveTally, 15000 );
	}

	// report button - opens modal with the form template, submits via FormData (supports file upload)
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.meeha-report-btn' );
		if ( ! btn ) {
			return;
		}
		var template = document.getElementById( 'meeha-report-form-template' );
		if ( ! template ) {
			return;
		}
		meehaOpenModal( 'রিপোর্ট করুন', template.innerHTML );

		var form = document.getElementById( 'meeha-report-form' );
		if ( ! form || ! window.meehaPollAjax ) {
			return;
		}
		var postId = btn.getAttribute( 'data-post-id' );

		form.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			var msg      = document.getElementById( 'meeha-report-msg' );
			var button   = form.querySelector( '.meeha-poll__submit' );
			var original = button.textContent;

			button.disabled = true;
			button.textContent = 'পাঠানো হচ্ছে...';
			msg.textContent = '';
			msg.classList.remove( 'meeha-poll__msg--error' );

			var data = new FormData( form );
			data.append( 'action', 'meeha_report_submit' );
			data.append( 'nonce', window.meehaPollAjax.nonce );
			data.append( 'post_id', postId );

			fetch( window.meehaPollAjax.url, { method: 'POST', body: data } )
				.then( function ( res ) { return res.json(); } )
				.then( function ( res ) {
					msg.textContent = res.data && res.data.message ? res.data.message : '';
					if ( res.success ) {
						form.reset();
						form.querySelectorAll( 'input, select, textarea, button' ).forEach( function ( el ) { el.disabled = true; } );
					} else {
						msg.classList.add( 'meeha-poll__msg--error' );
					}
				} )
				.catch( function ( err ) {
					console.error( 'Meeha report error:', err );
					msg.textContent = 'সমস্যা হয়েছে, আবার চেষ্টা করুন।';
					msg.classList.add( 'meeha-poll__msg--error' );
				} )
				.finally( function () {
					if ( ! form.querySelector( '.meeha-poll__submit' ).disabled ) {
						button.disabled = false;
						button.textContent = original;
					}
				} );
		} );
	} );

	// "শুনুন" — ব্রাউজারের নিজস্ব Web Speech API দিয়ে আর্টিকেল পড়ে শোনায় (ফ্রি,
	// সার্ভার-সাইড কিছু লাগে না)। এখানে বাস্তব সমস্যাগুলো ধরে ধরে ঠিক করা হয়েছে:
	//  ১) বাক্যের/প্যারার শুরুর অংশ বাদ পড়া — একসাথে সব chunk queue করলে ব্রাউজার
	//     ইঞ্জিন পরের chunk-এর শুরুটা মাঝেমধ্যে "খেয়ে" ফেলে। এখন প্রতিটা chunk
	//     আগেরটা শেষ হওয়ার (onend) পর, সামান্য বিরতি দিয়ে (২৫০ms) তবেই শুরু হয়
	//  ২) আগে pause()+resume() দিয়ে যে "কিপ-অ্যালাইভ" রাখা হতো, সেটাই আসলে
	//     মাঝেমধ্যে অংশ বাদ পড়ার কারণ হচ্ছিল — সেটা বাদ দিয়ে শুধু resume()
	//     (কখনো pause() না) দিয়ে হালকা "নাজ" রাখা হচ্ছে, যেটা মোবাইলে ইঞ্জিন
	//     ঘুমিয়ে/থেমে গেলে জাগাতে সাহায্য করে কিন্তু চলমান অডিও ব্যাহত করে না
	//  ৩) ভয়েস মান — লোকাল/অফলাইন ভয়েসের বদলে ক্লাউড/নেটওয়ার্ক ভয়েস (যেমন
	//     ব্রাউজারের "Google বাংলা") অগ্রাধিকার পাচ্ছে, যেটা অনেক বেশি স্বাভাবিক শোনায়
	//  ৪) ভলিউম স্পষ্টভাবে সর্বোচ্চ (১.০) সেট করা হচ্ছে
	//  ৫) utterance অবজেক্ট আউটার স্কোপে রাখা — মাঝপথে গার্বেজ-কালেক্ট হয়ে
	//     চুপচাপ থেমে যাওয়া এড়াতে
	var listenBtn = document.getElementById( 'meeha-listen-btn' );
	var floatListenBtn = document.getElementById( 'meeha-float-listen-btn' );

	if ( listenBtn ) {
		if ( ! window.speechSynthesis ) {
			listenBtn.hidden = true;
			if ( floatListenBtn ) {
				floatListenBtn.hidden = true;
			}
		} else {
			var listenLabel   = listenBtn.querySelector( '.meeha-toolbar__label' );
			var listenIcon    = listenBtn.querySelector( 'span[aria-hidden]' );
			var floatIcon     = floatListenBtn ? floatListenBtn.querySelector( 'span[aria-hidden]' ) : null;
			var chunkQueue    = [];
			var chunkIndex    = 0;
			var currentUtterance = null;
			var state          = 'idle'; // idle | speaking | paused
			var keepAliveTimer = null;
			var cachedVoices   = window.speechSynthesis.getVoices() || [];

			if ( ! cachedVoices.length && 'onvoiceschanged' in window.speechSynthesis ) {
				window.speechSynthesis.onvoiceschanged = function () {
					cachedVoices = window.speechSynthesis.getVoices() || [];
				};
			}

			// ক্লাউড/নেটওয়ার্ক ভয়েস (localService === false, বা নামে "Google")
			// অফলাইন/লোকাল ভয়েসের চেয়ে সাধারণত অনেক বেশি স্বাভাবিক শোনায়
			function findBestBnVoice() {
				var bnVoices = cachedVoices.filter( function ( v ) {
					return v.lang && v.lang.toLowerCase().indexOf( 'bn' ) === 0;
				} );
				if ( ! bnVoices.length ) {
					return null;
				}
				var cloudNamed = bnVoices.find( function ( v ) {
					return ! v.localService && /google/i.test( v.name );
				} );
				if ( cloudNamed ) {
					return cloudNamed;
				}
				var anyCloud = bnVoices.find( function ( v ) { return ! v.localService; } );
				return anyCloud || bnVoices[ 0 ];
			}

			// বাক্য-ভিত্তিক ছোট অংশে ভাঙা (লুকবিহাইন্ড ছাড়া, পুরনো ব্রাউজারেও কাজ করবে)
			function splitIntoChunks( text, maxLen ) {
				var parts = text.split( /([।!?.])/ );
				var sentences = [];
				for ( var i = 0; i < parts.length; i += 2 ) {
					var s = ( parts[ i ] || '' ).trim();
					var delim = parts[ i + 1 ] || '';
					if ( s ) {
						sentences.push( s + delim );
					}
				}
				if ( ! sentences.length ) {
					return [ text ];
				}
				var chunks = [];
				var current = '';
				sentences.forEach( function ( s ) {
					if ( ( current + s ).length > maxLen && current ) {
						chunks.push( current.trim() );
						current = s;
					} else {
						current += s;
					}
				} );
				if ( current.trim() ) {
					chunks.push( current.trim() );
				}
				return chunks;
			}

			function setUI( newState ) {
				state = newState;
				var symbol = 'speaking' === state ? '⏸' : '▶';
				var text   = 'speaking' === state ? 'থামুন' : ( 'paused' === state ? 'চালিয়ে যান' : 'শুনুন' );
				listenIcon.textContent = 'idle' === state ? '🔊' : symbol;
				listenLabel.textContent = text;
				if ( floatIcon ) {
					floatIcon.textContent = symbol;
				}
				if ( floatListenBtn ) {
					floatListenBtn.setAttribute( 'aria-label', text );
				}
			}

			// শুধু resume() — কখনো pause() না — যাতে চলমান অডিও নিজেই ব্যাহত না হয়,
			// কিন্তু মোবাইলে ইঞ্জিন নিষ্ক্রিয় হয়ে গেলে এটা তাকে "জাগিয়ে" রাখতে সাহায্য করে
			function startKeepAlive() {
				stopKeepAlive();
				keepAliveTimer = setInterval( function () {
					if ( window.speechSynthesis.paused ) {
						return; // ইউজার নিজে থামিয়ে থাকলে ছুঁয়ে দেওয়া হবে না
					}
					window.speechSynthesis.resume();
				}, 12000 );
			}

			function stopKeepAlive() {
				if ( keepAliveTimer ) {
					clearInterval( keepAliveTimer );
					keepAliveTimer = null;
				}
			}

			function speakChunk( index ) {
				if ( index >= chunkQueue.length ) {
					stopKeepAlive();
					setUI( 'idle' );
					chunkQueue = [];
					chunkIndex = 0;
					return;
				}
				chunkIndex = index;
				var u = new SpeechSynthesisUtterance( chunkQueue[ index ] );
				u.lang   = 'bn-BD';
				u.volume = 1;
				u.pitch  = 1;
				u.rate   = 1;
				var bnVoice = findBestBnVoice();
				if ( bnVoice ) {
					u.voice = bnVoice;
				}
				u.onend = function () {
					// পরের chunk শুরুর আগে সামান্য বিরতি — ইঞ্জিনকে "রিসেট" হতে সময় দেয়,
					// এটাই বাক্যের শুরুর শব্দ বাদ পড়ার সবচেয়ে সাধারণ কারণ
					setTimeout( function () {
						if ( 'speaking' === state ) {
							speakChunk( index + 1 );
						}
					}, 250 );
				};
				u.onerror = function () {
					stopKeepAlive();
					setUI( 'idle' );
					chunkQueue = [];
				};
				currentUtterance = u;
				window.speechSynthesis.speak( u );
			}

			function stopSpeaking() {
				window.speechSynthesis.cancel();
				stopKeepAlive();
				chunkQueue = [];
				chunkIndex = 0;
				currentUtterance = null;
				setUI( 'idle' );
			}

			function startSpeaking() {
				var targetId = listenBtn.getAttribute( 'data-target' );
				var target   = document.getElementById( targetId );
				if ( ! target ) {
					return;
				}
				var text = target.textContent.trim();
				if ( ! text ) {
					return;
				}
				window.speechSynthesis.cancel();
				chunkQueue = splitIntoChunks( text, 200 );
				setUI( 'speaking' );
				startKeepAlive();
				speakChunk( 0 );
			}

			function togglePlayPause() {
				if ( 'idle' === state ) {
					startSpeaking();
				} else if ( 'speaking' === state ) {
					window.speechSynthesis.pause();
					stopKeepAlive();
					setUI( 'paused' );
				} else if ( 'paused' === state ) {
					setUI( 'speaking' );
					window.speechSynthesis.resume();
					startKeepAlive();
				}
			}

			listenBtn.addEventListener( 'click', togglePlayPause );
			if ( floatListenBtn ) {
				floatListenBtn.addEventListener( 'click', togglePlayPause );
			}

			// পেজ থেকে সরে গেলে/আনলোড হলে পড়া বন্ধ হয়ে যাওয়া উচিত
			window.addEventListener( 'beforeunload', stopSpeaking );
		}
	}

	// ফন্ট সাইজ কন্ট্রোল — লেখা বড়/ছোট করা, localStorage-এ পছন্দ মনে রাখে
	var fontControl = document.getElementById( 'meeha-font-control' );
	var articleBody = document.getElementById( 'meeha-article-body' );
	if ( fontControl && articleBody ) {
		var MIN_SCALE = 0.85;
		var MAX_SCALE = 1.4;
		// অ্যাডমিন থেকে সেট করা % (ডিফল্ট ১০%) - Meeha হোমপেজ > সিঙ্গেল পোস্ট সেটিংস
		var STEP = ( window.meehaFontStepPercent ? window.meehaFontStepPercent : 10 ) / 100;
		var scale = parseFloat( window.localStorage ? window.localStorage.getItem( 'meeha_font_scale' ) : '' ) || 1;

		function applyScale() {
			articleBody.style.fontSize = ( scale * 100 ) + '%';
			if ( window.localStorage ) {
				window.localStorage.setItem( 'meeha_font_scale', scale );
			}
		}
		applyScale();

		fontControl.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.meeha-font-control__btn' );
			if ( ! btn ) {
				return;
			}
			var action = btn.getAttribute( 'data-font-action' );
			if ( 'increase' === action ) {
				scale = Math.min( MAX_SCALE, scale + STEP );
			} else {
				scale = Math.max( MIN_SCALE, scale - STEP );
			}
			applyScale();
		} );
	}


	// রিঅ্যাকশন — একজন ভিজিটর একবার বাছাই করতে পারবে, চাইলে বদলাতেও পারবে
	document.querySelectorAll( '.meeha-reactions' ).forEach( function ( wrap ) {
		var postId  = wrap.getAttribute( 'data-post-id' );
		var storageKey = 'meeha_reaction_' + postId;
		var current = window.localStorage ? window.localStorage.getItem( storageKey ) : null;

		if ( current ) {
			var activeBtn = wrap.querySelector( '[data-reaction="' + current + '"]' );
			if ( activeBtn ) {
				activeBtn.classList.add( 'is-active' );
			}
		}

		wrap.querySelectorAll( '.meeha-reactions__btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var type = btn.getAttribute( 'data-reaction' );
				if ( type === current || ! window.meehaPollAjax ) {
					return; // already selected, nothing to do
				}

				var data = new FormData();
				data.append( 'action', 'meeha_reaction' );
				data.append( 'nonce', window.meehaPollAjax.nonce );
				data.append( 'post_id', postId );
				data.append( 'reaction', type );
				data.append( 'previous', current || '' );

				fetch( window.meehaPollAjax.url, { method: 'POST', body: data } )
					.then( function ( res ) { return res.json(); } )
					.then( function ( res ) {
						if ( ! res.success ) {
							return;
						}
						Object.keys( res.data.counts ).forEach( function ( key ) {
							var countEl = wrap.querySelector( '[data-reaction-count="' + key + '"]' );
							if ( countEl ) {
								countEl.textContent = res.data.counts[ key ];
							}
						} );
						wrap.querySelectorAll( '.meeha-reactions__btn' ).forEach( function ( b ) {
							b.classList.remove( 'is-active' );
						} );
						btn.classList.add( 'is-active' );
						current = type;
						if ( window.localStorage ) {
							window.localStorage.setItem( storageKey, type );
						}
					} )
					.catch( function () {} );
			} );
		} );
	} );

	// এরিয়া ভিত্তিক খবর — সাইডবার উইজেট, ফলাফল ইনলাইনে দেখায়
	var areaSearchBtn = document.getElementById( 'meeha-area-search-btn' );
	if ( areaSearchBtn && window.meehaPollAjax ) {
		areaSearchBtn.addEventListener( 'click', function () {
			var widget   = areaSearchBtn.closest( '.meeha-area-widget' );
			var division = widget.querySelector( '[name="meeha_filter_division"]' ).value;
			var district = widget.querySelector( '[name="meeha_filter_district"]' ).value;
			var upazila  = widget.querySelector( '[name="meeha_filter_upazila"]' ).value;
			var resultsEl = document.getElementById( 'meeha-area-results' );

			var data = new FormData();
			data.append( 'action', 'meeha_area_filter' );
			data.append( 'nonce', window.meehaPollAjax.nonce );
			data.append( 'division', division );
			data.append( 'district', district );
			data.append( 'upazila', upazila );

			resultsEl.innerHTML = '<p class="meeha-glance__loading">খোঁজা হচ্ছে...</p>';

			fetch( window.meehaPollAjax.url, { method: 'POST', body: data } )
				.then( function ( res ) { return res.json(); } )
				.then( function ( res ) {
					resultsEl.innerHTML = res.success ? res.data.html : '';
				} )
				.catch( function () {
					resultsEl.innerHTML = '<p class="meeha-glance__empty">সমস্যা হয়েছে, আবার চেষ্টা করুন।</p>';
				} );
		} );
	}
} )();