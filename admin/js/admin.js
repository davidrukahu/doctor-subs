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

	// Combined-filter state. Bucket counters, rule chips, and search box
	// all read/write this and call applyTableFilter().
	var FILTER_STATE = {
		bucket: 'all',
		rule:   'all',
		search: '',
	};

	function applyFilter( filter ) {
		// Toggle off if clicking the already-active one.
		var next = ( FILTER_STATE.bucket === filter ) ? 'all' : filter;
		FILTER_STATE.bucket = next;

		// Bucket and rule are mutually exclusive - picking a bucket
		// clears the rule chip selection (and vice versa). Two
		// independent narrowings on a 50-row table got confusing.
		if ( next !== 'all' ) {
			FILTER_STATE.rule = 'all';
			syncRuleChipUI();
		}

		$$( '.counter' ).forEach( function ( c ) {
			var isActive = c.getAttribute( 'data-dr-subs-filter' ) === next;
			c.classList.toggle( 'active', isActive );
			if ( c.hasAttribute( 'data-dr-subs-filter' ) ) {
				c.setAttribute( 'aria-pressed', isActive ? 'true' : 'false' );
			}
		} );

		applyTableFilter();
	}

	function syncRuleChipUI() {
		$$( '[data-dr-subs-rule-chip]' ).forEach( function ( c ) {
			var isActive = c.getAttribute( 'data-dr-subs-rule-chip' ) === FILTER_STATE.rule;
			c.classList.toggle( 'active', isActive );
			c.setAttribute( 'aria-pressed', isActive ? 'true' : 'false' );
		} );
	}

	function syncBucketUI() {
		$$( '.counter' ).forEach( function ( c ) {
			var isActive = c.getAttribute( 'data-dr-subs-filter' ) === FILTER_STATE.bucket;
			c.classList.toggle( 'active', isActive );
			if ( c.hasAttribute( 'data-dr-subs-filter' ) ) {
				c.setAttribute( 'aria-pressed', isActive ? 'true' : 'false' );
			}
		} );
	}

	function wireRuleChips() {
		$$( '[data-dr-subs-rule-chip]' ).forEach( function ( chip ) {
			chip.addEventListener( 'click', function () {
				var rid = chip.getAttribute( 'data-dr-subs-rule-chip' ) || 'all';
				FILTER_STATE.rule = rid;
				// Bucket and rule are mutually exclusive - see applyFilter.
				if ( rid !== 'all' ) {
					FILTER_STATE.bucket = 'all';
					syncBucketUI();
				}
				syncRuleChipUI();
				applyTableFilter();
			} );
		} );
	}

	function wireSearch() {
		var input = $( '[data-dr-subs-search]' );
		if ( ! input ) return;
		var clear = $( '[data-dr-subs-search-clear]' );

		var run = debounce( function () {
			FILTER_STATE.search = ( input.value || '' ).trim().toLowerCase();
			if ( clear ) clear.hidden = FILTER_STATE.search === '';
			applyTableFilter();
		}, 180 );

		input.addEventListener( 'input', run );
		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && input.value ) {
				input.value = '';
				FILTER_STATE.search = '';
				if ( clear ) clear.hidden = true;
				applyTableFilter();
			}
		} );
		if ( clear ) {
			clear.addEventListener( 'click', function () {
				input.value = '';
				FILTER_STATE.search = '';
				clear.hidden = true;
				applyTableFilter();
				input.focus();
			} );
		}
	}

	function applyTableFilter() {
		var rows    = $$( '[data-dr-subs-row]' );
		var bucket  = FILTER_STATE.bucket;
		var rule    = FILTER_STATE.rule;
		var search  = FILTER_STATE.search;

		var visible = 0;
		rows.forEach( function ( row ) {
			var rb = row.getAttribute( 'data-bucket' ) || '';
			var rr = row.getAttribute( 'data-rule' )   || '';
			var rc = row.getAttribute( 'data-customer' ) || '';
			var re = row.getAttribute( 'data-email' )    || '';
			var rs = row.getAttribute( 'data-sub-id' )   || '';

			var bucketOk = bucket === 'all' || rb === bucket;
			var ruleOk   = rule   === 'all' || rr === rule;
			var searchOk = search === ''
				|| rc.indexOf( search ) !== -1
				|| re.indexOf( search ) !== -1
				|| rs.indexOf( search.replace( /^#/, '' ) ) !== -1;

			var show = bucketOk && ruleOk && searchOk;
			row.style.display = show ? '' : 'none';
			if ( show ) visible++;
		} );

		// Toggle JS-only "no matches" row when everything got filtered out.
		var emptyRow = $( '[data-dr-subs-empty]' );
		if ( emptyRow ) {
			emptyRow.hidden = ( visible > 0 );
		}

		// Update "showing/filtering" meta copy.
		var metaEl = $( '.table-head .meta' );
		if ( metaEl ) {
			if ( bucket === 'all' && rule === 'all' && search === '' ) {
				metaEl.textContent = ( ajax.strings && ajax.strings.showingAll ) || 'showing all broken and at-risk';
			} else {
				var filtering = ajax.strings && ajax.strings.filtering;
				var tpl;
				if ( filtering && typeof filtering === 'object' && bucket !== 'all' && filtering[ bucket ] ) {
					tpl = filtering[ bucket ];
				} else if ( typeof filtering === 'string' ) {
					tpl = filtering;
				} else {
					tpl = 'showing %d of %d';
				}
				metaEl.textContent = tpl
					.replace( /%1\$d/g, visible )
					.replace( /%2\$s/g, bucket === 'all' ? 'matching' : bucket )
					.replace( '%d', visible )
					.replace( '%s', bucket === 'all' ? 'matching' : bucket );
			}
		}

		// Show/hide the bucket-counter clear button.
		var clear = $( '.table-head .clear' );
		if ( clear ) {
			clear.style.display = bucket === 'all' ? 'none' : '';
		}

		// Bulk-fix button: visible when there are bulk-fixable visible rows.
		// Counts rows whose rule is NOT in BULK_DISABLED_RULES (total_drift
		// is flag-only). Works for both single-rule chip and All rules.
		var bulkBtn = $( '[data-dr-subs-bulk-fix]' );
		if ( bulkBtn ) {
			var fixable = rows.filter( function ( r ) {
				return r.style.display !== 'none' &&
					BULK_DISABLED_RULES.indexOf( r.getAttribute( 'data-rule' ) ) === -1;
			} );
			var fixableCount = fixable.length;
			if ( fixableCount > 0 ) {
				var activeChipEl = $( '[data-dr-subs-rule-chip].active' );
				var labelTxt;
				if ( rule === 'all' ) {
					labelTxt = 'Fix all ' + fixableCount + ' matches';
				} else {
					var chipLabel = activeChipEl ? ( activeChipEl.textContent || '' ).trim() : '';
					labelTxt = ( 'Fix all ' + fixableCount + ' ' + chipLabel ).replace( /\s+/g, ' ' );
				}
				bulkBtn.textContent = labelTxt;
				bulkBtn.setAttribute( 'data-rule-id', rule );
				bulkBtn.hidden = false;
			} else {
				bulkBtn.hidden = true;
			}
		}
	}

	// Rules that should never be bulk-fixed (apply_fix throws). Mirrors
	// the data-bulk-disabled='1' attribute on the chip.
	var BULK_DISABLED_RULES = [ 'total_drift' ];

	function wireBulkFix() {
		var bulkBtn = $( '[data-dr-subs-bulk-fix]' );
		if ( ! bulkBtn ) return;

		bulkBtn.addEventListener( 'click', function () {
			// Group visible, fixable rows by rule_id. The bulk_fix endpoint
			// is per-rule, so we sequence one POST per rule when "All
			// rules" is selected.
			var byRule = {};
			$$( '[data-dr-subs-row]' ).forEach( function ( r ) {
				if ( r.style.display === 'none' ) return;
				var rid = r.getAttribute( 'data-rule' );
				var sid = r.getAttribute( 'data-sub-id' );
				if ( ! rid || ! sid ) return;
				if ( BULK_DISABLED_RULES.indexOf( rid ) !== -1 ) return;
				( byRule[ rid ] = byRule[ rid ] || [] ).push( sid );
			} );

			var totalCount = Object.keys( byRule ).reduce( function ( n, k ) {
				return n + byRule[ k ].length;
			}, 0 );
			if ( totalCount === 0 ) return;

			var ruleSummary = Object.keys( byRule ).map( function ( rid ) {
				return byRule[ rid ].length + ' ' + rid.replace( /_/g, ' ' );
			} ).join( ', ' );

			openConfirmModal( {
				title: 'Apply ' + totalCount + ' fix' + ( totalCount === 1 ? '' : 'es' ) + '?',
				body: 'Doctor Subs will run the per-rule fix on each subscription: ' + ruleSummary + '. Each can be reverted individually from Fix history.',
				warning: 'Some fixes schedule a renewal payment. The customer may be charged within minutes.',
				primaryLabel: 'Apply ' + totalCount + ' fix' + ( totalCount === 1 ? '' : 'es' ),
				dangerous: false,
			} ).then( function ( confirmed ) {
				if ( ! confirmed ) return;
				doBulkFix( bulkBtn, byRule );
			} );
		} );
	}

	function doBulkFix( bulkBtn, byRule ) {
			bulkBtn.disabled = true;
			bulkBtn.classList.add( 'is-busy' );

			var ruleIds = Object.keys( byRule );
			var allOk   = true;
			var errors  = [];

			function postOne( ruleId ) {
				var body = new URLSearchParams();
				body.set( 'action', 'dr_subs_bulk_fix' );
				body.set( '_ajax_nonce', ajax.nonce || '' );
				body.set( 'rule_id', ruleId );
				byRule[ ruleId ].forEach( function ( id ) { body.append( 'sub_ids[]', id ); } );
				return fetch( ajax.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body,
				} ).then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						if ( ! res || ! res.success ) {
							allOk = false;
							errors.push( ruleId + ': ' + ( ( res && res.data && res.data.message ) || 'failed' ) );
						}
					} )
					.catch( function ( err ) {
						allOk = false;
						errors.push( ruleId + ': ' + err.message );
					} );
			}

			// Sequence the per-rule POSTs (parallel would be fine too, but
			// scanner concurrency lock prefers serial).
			var chain = Promise.resolve();
			ruleIds.forEach( function ( rid ) {
				chain = chain.then( function () { return postOne( rid ); } );
			} );
			chain.then( function () {
				bulkBtn.disabled = false;
				bulkBtn.classList.remove( 'is-busy' );
				if ( allOk ) {
					window.location.reload();
				} else {
					alert( 'Some bulk fixes failed:\n' + errors.join( '\n' ) );
					window.location.reload();
				}
			} );
	}

	/* ============================================================
	 * Fix preview modal
	 * ============================================================ */

	function wireRowsAndFixButtons() {
		// Row click opens modal.
		$$( '[data-dr-subs-row]' ).forEach( function ( row ) {
			row.addEventListener( 'click', function ( e ) {
				// Don't open if the click was on the Fix button or the
				// sub-id link - let those handle themselves.
				if ( e.target.closest( '[data-dr-subs-fix]' ) ) {
					return;
				}
				if ( e.target.closest( '[data-dr-subs-row-link]' ) ) {
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

	/**
	 * Generic styled-modal confirm. Replaces window.confirm() so destructive
	 * actions (bulk-fix, revert with executed payment) stay inside the
	 * plugin's design language. Returns a Promise<bool>.
	 */
	function openConfirmModal( opts ) {
		opts = opts || {};
		LAST_FOCUS = document.activeElement;
		return new Promise( function ( resolve ) {
			var mount = $( '[data-dr-subs-modal-mount]' ) || ensureModalMount();
			var title = opts.title || 'Confirm';
			var body = opts.body || '';
			var warning = opts.warning || '';
			var primaryLabel = opts.primaryLabel || 'Confirm';
			var primaryClass = opts.dangerous ? 'btn btn-primary btn-danger' : 'btn btn-primary';

			var html = '<div class="ds-root" data-dr-subs-modal-layer>'
				+ '<div class="modal-backdrop" data-dr-subs-confirm-backdrop tabindex="-1"></div>'
				+ '<div class="modal" role="alertdialog" aria-modal="true" aria-labelledby="dr-subs-confirm-title" data-dr-subs-confirm-modal>'
				+   '<div class="modal-head"><span class="customer" id="dr-subs-confirm-title">' + escapeHtml( title ) + '</span></div>'
				+   '<div class="modal-body">'
				+     '<p class="narrative">' + escapeHtml( body ) + '</p>'
				+     ( warning
					? '<div class="executed-warning" role="note">'
						+ '<span class="icon" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><circle cx="12" cy="16" r="0.8" fill="currentColor"/></svg></span>'
						+ '<span class="body">' + escapeHtml( warning ) + '</span>'
						+ '</div>'
					: '' )
				+   '</div>'
				+   '<div class="modal-foot">'
				+     '<button type="button" class="btn btn-ghost" data-dr-subs-confirm-cancel>Cancel</button>'
				+     '<button type="button" class="' + primaryClass + '" data-dr-subs-confirm-ok>' + escapeHtml( primaryLabel ) + '</button>'
				+   '</div>'
				+ '</div>'
				+ '</div>';

			mount.innerHTML = html;
			ACTIVE_MODAL = $( '[data-dr-subs-confirm-modal]', mount );
			document.body.style.overflow = 'hidden';

			function done( v ) {
				cleanup();
				resolve( v );
			}
			function cleanup() {
				mount.innerHTML = '';
				document.body.style.overflow = '';
				ACTIVE_MODAL = null;
				if ( LAST_FOCUS && LAST_FOCUS.focus ) LAST_FOCUS.focus();
				document.removeEventListener( 'keydown', onKey );
			}
			function onKey( e ) {
				if ( e.key === 'Escape' ) done( false );
				if ( e.key === 'Enter' ) done( true );
			}

			$( '[data-dr-subs-confirm-backdrop]' ).addEventListener( 'click', function () { done( false ); } );
			$( '[data-dr-subs-confirm-cancel]' ).addEventListener( 'click', function () { done( false ); } );
			$( '[data-dr-subs-confirm-ok]' ).addEventListener( 'click', function () { done( true ); } );
			document.addEventListener( 'keydown', onKey );
			trapFocus( ACTIVE_MODAL );
			var ok = $( '[data-dr-subs-confirm-ok]', ACTIVE_MODAL );
			if ( ok ) ok.focus();
		} );
	}

	function escapeHtml( s ) {
		return String( s == null ? '' : s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
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
		// Focus the dialog itself (tabindex=-1) so no inner link or button
		// gets a visible focus ring on open. The first focusable in this
		// design is the sub-id link, which made it look "selected" on the
		// modal head. Users tab into controls naturally from here.
		if ( ! modal.hasAttribute( 'tabindex' ) ) {
			modal.setAttribute( 'tabindex', '-1' );
		}
		modal.focus( { preventScroll: true } );
		modal.addEventListener( 'keydown', function ( e ) {
			if ( e.key !== 'Tab' ) return;
			var items = $$( FOCUSABLE, modal );
			if ( ! items.length ) return;
			var first = items[ 0 ];
			var last  = items[ items.length - 1 ];
			if ( e.shiftKey && document.activeElement === first ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && ( document.activeElement === last || document.activeElement === modal ) ) {
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

	function runScan( btn, labelWhileScanning ) {
		var counters = $( '.counters' );
		if ( counters ) counters.classList.add( 'refreshing' );

		var originalHTML = btn ? btn.innerHTML : null;
		if ( btn ) {
			btn.disabled = true;
			btn.classList.add( 'is-busy' );
			btn.setAttribute( 'aria-busy', 'true' );
			btn.textContent = labelWhileScanning;
		}

		return postForm( 'dr_subs_run_scan', {} )
			.then( function () {
				window.location.reload();
			} )
			.catch( function ( err ) {
				if ( counters ) counters.classList.remove( 'refreshing' );
				if ( btn ) {
					btn.disabled = false;
					btn.classList.remove( 'is-busy' );
					btn.removeAttribute( 'aria-busy' );
					btn.innerHTML = originalHTML;
				}
				console.error( 'Doctor Subs: scan failed', err );
				alert( ( ajax.strings && ajax.strings.scanError ) || 'Scan failed. Check your connection and try again.' );
			} );
	}

	function wireRefresh() {
		$$( '[data-dr-subs-refresh]' ).forEach( function ( link ) {
			link.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( link.disabled ) return;
				runScan( link, ( ajax.strings && ajax.strings.refreshing ) || 'Refreshing…' );
			} );
		} );
	}

	function wireScan() {
		$$( '[data-dr-subs-scan]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( btn.disabled ) return;
				runScan( btn, ( ajax.strings && ajax.strings.scanning ) || 'Scanning…' );
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
				var executed = btn.getAttribute( 'data-executed' ) === '1';

				openConfirmModal( {
					title: 'Revert this fix?',
					body: executed
						? 'The renewal payment for this fix has already gone through. Reverting will undo the status change.'
						: 'The subscription will return to its previous state.',
					warning: executed
						? 'This will NOT refund the customer. If a refund is needed, handle it in the WooCommerce order directly.'
						: '',
					primaryLabel: 'Revert',
					dangerous: executed,
				} ).then( function ( confirmed ) {
					if ( ! confirmed ) return;
					doRevert( btn, entryId );
				} );
			} );
		} );
	}

	function doRevert( btn, entryId ) {
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
		wireRuleChips();
		wireSearch();
		wireBulkFix();
		wireRowsAndFixButtons();
		wireRefresh();
		wireScan();
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
