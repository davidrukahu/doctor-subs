/**
 * Doctor Subs - admin.js (v2)
 *
 * Vanilla JS. No framework dependency. Scoped to .ds-root surfaces.
 *
 * Handles:
 *   - Counter filter clicks → filter "Needs attention" table
 *   - Row click / Fix button → open preview modal via AJAX
 *   - Modal apply / cancel / ESC / backdrop close
 *   - Refresh link → kick off a new scan
 *   - Fix-history Revert buttons
 *   - Settings form save + unsaved-changes guard
 *   - Toggle visible label sync
 *
 * Assumes `window.drSubsAjax` is localised with: ajaxUrl, nonce, strings.
 *
 * @package Dr_Subs
 * @since   2.0.0
 */
( function () {
	'use strict';

	var ajax = window.drSubsAjax || {};
	var ACTIVE_MODAL = null;
	var LAST_FOCUS = null;
	var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

	/* ============================================================
	 * Small helpers
	 * ============================================================ */

	function $( sel, root ) {
		return ( root || document ).querySelector( sel );
	}

	function $$( sel, root ) {
		return Array.from( ( root || document ).querySelectorAll( sel ) );
	}

	function postForm( action, data ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( '_ajax_nonce', ajax.nonce || '' );
		Object.keys( data || {} ).forEach( function ( k ) {
			body.set( k, data[ k ] );
		} );
		return fetch( ajax.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body,
		} ).then( function ( r ) {
			if ( ! r.ok ) throw new Error( 'HTTP ' + r.status );
			return r.text();
		} );
	}

	function debounce( fn, ms ) {
		var t;
		return function () {
			var args = arguments;
			clearTimeout( t );
			t = setTimeout( function () { fn.apply( null, args ); }, ms );
		};
	}

	/* ============================================================
	 * Counter filter (dashboard)
	 * ============================================================ */

	function wireCounters() {
		$$( '[data-dr-subs-filter]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var target = btn.getAttribute( 'data-dr-subs-filter' );
				applyFilter( target );
			} );
		} );
	}

	function applyFilter( filter ) {
		var active = $( '.counter.active' );
		var current = active ? active.getAttribute( 'data-dr-subs-filter' ) : 'all';
		// Toggle off if clicking the already-active one.
		var next = ( current === filter ) ? 'all' : filter;

		$$( '.counter' ).forEach( function ( c ) {
			var isActive = c.getAttribute( 'data-dr-subs-filter' ) === next;
			c.classList.toggle( 'active', isActive );
			c.setAttribute( 'aria-pressed', isActive ? 'true' : 'false' );
		} );

		// Filter rows by data-bucket or data-rule. We use data-sub-id on rows
		// but bucket info comes from the row classes or data attrs.
		var rows = $$( '[data-dr-subs-row]' );
		rows.forEach( function ( row ) {
			var rule = row.getAttribute( 'data-rule' );
			// Map rule → bucket for visibility:
			//   ghost → broken, onhold → varies, repfail → risk
			// In the real PHP we'll emit data-bucket directly. Fallback:
			var bucket = row.getAttribute( 'data-bucket' ) || rule;
			var show =
				next === 'all' ||
				bucket === next ||
				( next === 'broken' && rule === 'ghost' ) ||
				( next === 'risk' && ( rule === 'repfail' || rule === 'onhold' ) );
			row.style.display = show ? '' : 'none';
		} );

		// Update "showing" meta copy.
		var metaEl = $( '.table-head .meta' );
		if ( metaEl ) {
			if ( next === 'all' ) {
				metaEl.textContent = ( ajax.strings && ajax.strings.showingAll ) || 'showing all broken and at-risk';
			} else {
				var visible = rows.filter( function ( r ) { return r.style.display !== 'none'; } ).length;
				var tpl = ( ajax.strings && ajax.strings.filtering ) || 'filtering to %d %s';
				metaEl.textContent = tpl.replace( '%d', visible ).replace( '%s', next );
			}
		}

		// Show/hide the clear filter button.
		var clear = $( '.table-head .clear' );
		if ( clear ) {
			clear.style.display = next === 'all' ? 'none' : '';
		}
	}

	/* ============================================================
	 * Fix preview modal
	 * ============================================================ */

	function wireRowsAndFixButtons() {
		// Row click opens modal.
		$$( '[data-dr-subs-row]' ).forEach( function ( row ) {
			row.addEventListener( 'click', function ( e ) {
				// Don't open if the click was on the Fix button itself - let it bubble up to the button handler.
				if ( e.target.closest( '[data-dr-subs-fix]' ) ) {
					return;
				}
				openFixModal( row.getAttribute( 'data-sub-id' ) );
			} );
			row.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Enter' || e.key === ' ' ) {
					e.preventDefault();
					openFixModal( row.getAttribute( 'data-sub-id' ) );
				}
			} );
		} );

		// Explicit Fix button.
		$$( '[data-dr-subs-fix]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				openFixModal( btn.getAttribute( 'data-sub-id' ) );
			} );
		} );
	}

	function openFixModal( subId ) {
		if ( ! subId ) return;
		LAST_FOCUS = document.activeElement;

		postForm( 'dr_subs_get_fix_preview', { sub_id: subId } )
			.then( function ( html ) {
				var mount = $( '[data-dr-subs-modal-mount]' ) || ensureModalMount();
				mount.innerHTML = html;
				ACTIVE_MODAL = $( '[data-dr-subs-modal]', mount );
				trapFocus( ACTIVE_MODAL );
				wireModalButtons();
				// Prevent body scroll while modal is open.
				document.body.style.overflow = 'hidden';
			} )
			.catch( function ( err ) {
				console.error( 'Doctor Subs: failed to open fix preview', err );
				alert( ( ajax.strings && ajax.strings.modalLoadError ) || 'Could not load the fix preview. Try again in a moment.' );
			} );
	}

	function ensureModalMount() {
		var mount = document.createElement( 'div' );
		mount.setAttribute( 'data-dr-subs-modal-mount', '' );
		document.body.appendChild( mount );
		return mount;
	}

	function wireModalButtons() {
		if ( ! ACTIVE_MODAL ) return;

		var backdrop = document.querySelector( '[data-dr-subs-modal-backdrop]' );
		if ( backdrop ) {
			backdrop.addEventListener( 'click', closeFixModal );
		}

		var cancel = $( '[data-dr-subs-modal-cancel]', ACTIVE_MODAL );
		if ( cancel ) {
			cancel.addEventListener( 'click', closeFixModal );
		}

		var apply = $( '[data-dr-subs-modal-apply]', ACTIVE_MODAL );
		if ( apply ) {
			apply.addEventListener( 'click', function () {
				applyFix( apply.getAttribute( 'data-sub-id' ), apply );
			} );
		}
	}

	function closeFixModal() {
		var mount = $( '[data-dr-subs-modal-mount]' );
		if ( mount ) {
			mount.innerHTML = '';
		}
		document.body.style.overflow = '';
		ACTIVE_MODAL = null;
		if ( LAST_FOCUS && LAST_FOCUS.focus ) {
			LAST_FOCUS.focus();
		}
	}

	function applyFix( subId, applyBtn ) {
		if ( ! subId || ! applyBtn ) return;

		var originalLabel = applyBtn.textContent;
		applyBtn.disabled = true;
		applyBtn.textContent = ( ajax.strings && ajax.strings.applying ) || 'Applying…';

		postForm( 'dr_subs_apply_fix', { sub_id: subId } )
			.then( function ( resp ) {
				var data = {};
				try { data = JSON.parse( resp ); } catch ( e ) { data = { success: false, message: resp }; }

				if ( data.success ) {
					// Row disappears with a small animation.
					var row = $( '[data-dr-subs-row][data-sub-id="' + subId + '"]' );
					if ( row ) {
						row.classList.add( 'row-exit' );
						setTimeout( function () { row.remove(); updateCountsAfterFix(); }, 240 );
					}
					closeFixModal();
				} else {
					applyBtn.disabled = false;
					applyBtn.textContent = originalLabel;
					alert( data.message || ( ajax.strings && ajax.strings.applyError ) || 'Something went wrong - nothing was changed.' );
				}
			} )
			.catch( function ( err ) {
				applyBtn.disabled = false;
				applyBtn.textContent = originalLabel;
				console.error( 'Doctor Subs: apply fix failed', err );
				alert( ( ajax.strings && ajax.strings.applyError ) || 'Something went wrong - nothing was changed.' );
			} );
	}

	function updateCountsAfterFix() {
		// Optimistic client update. Server truth lands on next refresh/scan.
		var broken = $( '.counter[data-state="broken"] .num' );
		if ( broken ) {
			var n = parseInt( broken.textContent.replace( /[^0-9]/g, '' ), 10 );
			if ( ! isNaN( n ) && n > 0 ) {
				broken.textContent = ( n - 1 ).toLocaleString();
			}
		}
	}

	/* ============================================================
	 * ESC to close modal, focus trap
	 * ============================================================ */

	function trapFocus( modal ) {
		var focusables = $$( FOCUSABLE, modal );
		if ( focusables.length ) {
			focusables[ 0 ].focus();
		}
		modal.addEventListener( 'keydown', function ( e ) {
			if ( e.key !== 'Tab' ) return;
			var items = $$( FOCUSABLE, modal );
			if ( ! items.length ) return;
			var first = items[ 0 ];
			var last  = items[ items.length - 1 ];
			if ( e.shiftKey && document.activeElement === first ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && document.activeElement === last ) {
				e.preventDefault();
				first.focus();
			}
		} );
	}

	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' && ACTIVE_MODAL ) {
			closeFixModal();
		}
	} );

	/* ============================================================
	 * Refresh (manual scan trigger)
	 * ============================================================ */

	function wireRefresh() {
		$$( '[data-dr-subs-refresh]' ).forEach( function ( link ) {
			link.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var counters = $( '.counters' );
				if ( counters ) counters.classList.add( 'refreshing' );

				postForm( 'dr_subs_run_scan', {} )
					.then( function () {
						window.location.reload();
					} )
					.catch( function ( err ) {
						if ( counters ) counters.classList.remove( 'refreshing' );
						console.error( 'Doctor Subs: scan failed', err );
					} );
			} );
		} );
	}

	/* ============================================================
	 * Fix history: filter tabs + Revert
	 * ============================================================ */

	function wireHistory() {
		// Client-side filter: show/hide rows.
		$$( '[data-dr-subs-history-filter]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var filter = btn.getAttribute( 'data-dr-subs-history-filter' );
				filterHistory( filter );
			} );
		} );

		// Revert buttons.
		$$( '[data-dr-subs-revert]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var entryId = btn.getAttribute( 'data-entry-id' );
				if ( ! entryId ) return;
				var confirmMsg = ( ajax.strings && ajax.strings.confirmRevert ) || 'Revert this fix? The subscription will return to its previous state.';
				if ( ! window.confirm( confirmMsg ) ) return;

				var originalLabel = btn.textContent;
				btn.disabled = true;
				btn.textContent = ( ajax.strings && ajax.strings.reverting ) || 'Reverting…';

				postForm( 'dr_subs_revert_fix', { entry_id: entryId } )
					.then( function ( resp ) {
						var data = {};
						try { data = JSON.parse( resp ); } catch ( e ) { data = { success: false, message: resp }; }
						if ( data.success ) {
							window.location.reload();
						} else {
							btn.disabled = false;
							btn.textContent = originalLabel;
							alert( data.message || 'Could not revert.' );
						}
					} )
					.catch( function ( err ) {
						btn.disabled = false;
						btn.textContent = originalLabel;
						console.error( 'Doctor Subs: revert failed', err );
					} );
			} );
		} );
	}

	function filterHistory( filter ) {
		$$( '[data-dr-subs-history-filter]' ).forEach( function ( b ) {
			var isActive = b.getAttribute( 'data-dr-subs-history-filter' ) === filter;
			b.classList.toggle( 'active', isActive );
			b.setAttribute( 'aria-pressed', isActive ? 'true' : 'false' );
		} );
		$$( '[data-dr-subs-entry]' ).forEach( function ( row ) {
			// Rule class on batch entries is trickier; batch entries show on 'all' always.
			var isBatch = row.classList.contains( 'batch' );
			if ( filter === 'all' ) {
				row.style.display = '';
			} else if ( isBatch ) {
				row.style.display = 'none';
			} else {
				var pill = row.querySelector( '.pill' );
				var match = false;
				if ( pill ) {
					match = pill.classList.contains( 'pill-broken' ) && ( filter === 'ghost' || filter === 'onhold' )
					     || pill.classList.contains( 'pill-risk' )   &&   filter === 'repfail';
				}
				row.style.display = match ? '' : 'none';
			}
		} );
	}

	/* ============================================================
	 * Settings - toggle labels + unsaved-changes guard
	 * ============================================================ */

	function wireSettings() {
		var form = $( '[data-dr-subs-settings]' );
		if ( ! form ) return;

		// Keep toggle label in sync with checkbox state.
		$$( '.toggle input[type="checkbox"]', form ).forEach( function ( input ) {
			var label = input.parentElement.querySelector( '.toggle-state' );
			if ( ! label ) return;
			var onLabel  = label.getAttribute( 'data-on-label' )  || 'On';
			var offLabel = label.getAttribute( 'data-off-label' ) || 'Off';
			var sync = function () {
				label.textContent = input.checked ? onLabel : offLabel;
			};
			input.addEventListener( 'change', sync );
			sync();
		} );

		// Unsaved-changes guard.
		var initial = new FormData( form );
		var dirty = false;
		var guard = function () {
			var now = new FormData( form );
			dirty = false;
			initial.forEach( function ( val, key ) {
				if ( String( now.get( key ) ) !== String( val ) ) dirty = true;
			} );
		};
		form.addEventListener( 'change', guard );
		form.addEventListener( 'input', debounce( guard, 120 ) );

		window.addEventListener( 'beforeunload', function ( e ) {
			if ( dirty ) {
				e.preventDefault();
				e.returnValue = '';
				return '';
			}
		} );

		// Reset button → also resync.
		form.addEventListener( 'reset', function () {
			setTimeout( function () {
				initial = new FormData( form );
				$$( '.toggle input[type="checkbox"]', form ).forEach( function ( i ) {
					i.dispatchEvent( new Event( 'change' ) );
				} );
				dirty = false;
			}, 0 );
		} );

		// AJAX save with Saving... -> Saved flash.
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var submitBtn = form.querySelector( 'button[type="submit"]' );
			if ( ! submitBtn ) return;

			var originalLabel = submitBtn.textContent.trim();
			submitBtn.disabled = true;
			submitBtn.textContent = ( ajax.strings && ajax.strings.saving ) || 'Saving…';

			var body = new FormData( form );
			body.set( 'action', 'dr_subs_save_settings' );
			body.set( '_ajax_nonce', ajax.nonce || '' );

			fetch( ajax.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			} )
				.then( function ( r ) { return r.text(); } )
				.then( function ( resp ) {
					var data = {};
					try { data = JSON.parse( resp ); } catch ( e ) { data = { success: true }; }

					submitBtn.disabled = false;
					submitBtn.textContent = originalLabel;

					if ( data.success !== false ) {
						showSavedFlash( form );
						initial = new FormData( form );
						dirty = false;
					} else {
						alert( ( data.data && data.data.message ) || 'Could not save. Try again?' );
					}
				} )
				.catch( function ( err ) {
					submitBtn.disabled = false;
					submitBtn.textContent = originalLabel;
					console.error( 'Doctor Subs: settings save failed', err );
					alert( ( ajax.strings && ajax.strings.saveError ) || 'Could not save. Check your connection and try again.' );
				} );
		} );
	}

	function showSavedFlash( form ) {
		var foot = form.querySelector( '.settings-foot' );
		if ( ! foot ) return;
		// Clear existing status / flash nodes first.
		$$( '.status, .saved-flash', foot ).forEach( function ( n ) { n.remove(); } );
		var flash = document.createElement( 'span' );
		flash.className = 'saved-flash';
		flash.setAttribute( 'role', 'status' );
		flash.textContent = ( ajax.strings && ajax.strings.saved ) || 'Saved.';
		foot.appendChild( flash );
		setTimeout( function () {
			flash.style.transition = 'opacity 300ms ease';
			flash.style.opacity = '0';
			setTimeout( function () { if ( flash.parentNode ) flash.parentNode.removeChild( flash ); }, 300 );
		}, 2400 );
	}

	/* ============================================================
	 * Cancel scan (first-run scanning state)
	 * ============================================================ */

	function wireCancelScan() {
		$$( '[data-dr-subs-cancel-scan]' ).forEach( function ( a ) {
			a.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				postForm( 'dr_subs_cancel_scan', {} ).finally( function () {
					window.location.reload();
				} );
			} );
		} );
	}

	/* ============================================================
	 * Boot
	 * ============================================================ */

	/* ============================================================
	 * Keyboard shortcuts (power-user delight)
	 * ============================================================ */

	function wireShortcuts() {
		document.addEventListener( 'keydown', function ( e ) {
			// Skip modifier combos (let the browser handle them).
			if ( e.ctrlKey || e.metaKey || e.altKey ) return;

			// Skip if a modal owns the keys.
			if ( ACTIVE_MODAL ) return;

			// Skip if the user is typing in an input/textarea/contenteditable.
			var t = e.target;
			if ( t && t.matches && t.matches( 'input, textarea, select, [contenteditable="true"]' ) ) return;

			// R = refresh scan (only on a surface that has a refresh control).
			if ( e.key === 'r' || e.key === 'R' ) {
				var refresh = $( '[data-dr-subs-refresh]' );
				if ( refresh ) {
					e.preventDefault();
					refresh.click();
				}
				return;
			}

			// ? = show shortcut help (reserved; render inline hints for now).
		} );
	}

	function init() {
		wireCounters();
		wireRowsAndFixButtons();
		wireRefresh();
		wireHistory();
		wireSettings();
		wireCancelScan();
		wireShortcuts();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
