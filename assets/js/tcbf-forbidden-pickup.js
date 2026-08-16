/**
 * TCBF — early frontend warning for forbidden pickup (START) dates.
 *
 * Advisory layer only: the PHP woocommerce_add_to_cart_validation filter in
 * Woo_ForbiddenPickup remains the enforcement boundary. If this script fails,
 * is absent, or is bypassed, the booking form must keep working and the
 * server still rejects forbidden starts — so every step here bails silently
 * rather than throwing.
 *
 * Start date source: the canonical Bookings hidden fields
 * wc_bookings_field_start_date_{year,month,day} — the SAME fields the PHP
 * validator reads. The calendar is only used to know WHEN to re-evaluate.
 * Trigger signals mirror the set already proven in production by GF_JS's
 * ledger watcher: change events on the date fields/datepicker inputs, the
 * jQuery events `date-selected` (booking form) and `wc_bookings_calculated_cost`
 * (body), and a debounced MutationObserver on the Bookings cost node.
 *
 * Forbidden dates are NEVER fed into Woo availability and calendar days are
 * never disabled: they remain valid interior and return days.
 */
(function () {
	'use strict';

	var cfg = window.tcbfForbiddenPickup;
	if ( ! cfg || ! cfg.ranges || ! cfg.ranges.length ) {
		return;
	}

	var blocked = false;

	function pad2( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}

	// Y-m-d from the canonical Bookings fields; '' when no complete start yet.
	function getStartYmd() {
		var y = document.querySelector( '[name="wc_bookings_field_start_date_year"]' );
		var m = document.querySelector( '[name="wc_bookings_field_start_date_month"]' );
		var d = document.querySelector( '[name="wc_bookings_field_start_date_day"]' );
		if ( ! y || ! m || ! d ) {
			return '';
		}
		var yy = parseInt( y.value, 10 );
		var mm = parseInt( m.value, 10 );
		var dd = parseInt( d.value, 10 );
		if ( ! yy || ! mm || ! dd ) {
			return '';
		}
		return yy + '-' + pad2( mm ) + '-' + pad2( dd );
	}

	// First configured range containing the date — same first-hit semantics
	// as PHP find_forbidden_range(); plain Y-m-d string comparison, no Date
	// objects, no timezone math.
	function findHit( ymd ) {
		for ( var i = 0; i < cfg.ranges.length; i++ ) {
			var r = cfg.ranges[ i ];
			if ( r && r.start && r.end && ymd >= r.start && ymd <= r.end ) {
				return r;
			}
		}
		return null;
	}

	// One TCBF-owned status node, reused forever (never duplicated), inserted
	// as a SIBLING after Woo's cost node — Woo keeps full ownership of
	// .wc-bookings-booking-cost.
	function ensureNode() {
		var node = document.querySelector( '.tcbf-pickup-restriction' );
		if ( node ) {
			return node;
		}
		node = document.createElement( 'div' );
		node.className = 'tcbf-pickup-restriction';
		node.setAttribute( 'role', 'status' );
		node.setAttribute( 'aria-live', 'polite' );

		var cost = document.querySelector( '.wc-bookings-booking-cost' );
		if ( cost && cost.parentNode ) {
			cost.parentNode.insertBefore( node, cost.nextSibling );
			return node;
		}
		var form = document.querySelector( '.wc-bookings-booking-form' );
		if ( form && form.parentNode ) {
			form.parentNode.insertBefore( node, form.nextSibling );
			return node;
		}
		return null;
	}

	function findDetailsWrapper() {
		var wrap = null;
		if ( cfg.formId ) {
			wrap = document.getElementById( 'gform_wrapper_' + cfg.formId );
		}
		if ( ! wrap ) {
			var cart = document.querySelector( 'form.cart' );
			if ( cart ) {
				wrap = cart.querySelector( '.gform_wrapper' );
			}
		}
		return wrap;
	}

	// Visually gate the details form with a CSS class only: no disabled
	// attributes, no value changes — Gravity Forms state and everything the
	// customer already typed survive forbidden->allowed->forbidden intact.
	function gateDetails( on ) {
		var wrap = findDetailsWrapper();
		if ( ! wrap ) {
			return;
		}
		wrap.classList.toggle( 'tcbf-pickup-gated', on );

		var prev = wrap.previousElementSibling;
		var note = ( prev && prev.classList && prev.classList.contains( 'tcbf-pickup-gate-note' ) ) ? prev : null;
		if ( on && ! note && cfg.gateText ) {
			note = document.createElement( 'p' );
			note.className = 'tcbf-pickup-gate-note';
			note.textContent = cfg.gateText;
			wrap.parentNode.insertBefore( note, wrap );
		} else if ( ! on && note ) {
			note.parentNode.removeChild( note );
		}
	}

	function setBlocked( hit ) {
		blocked = !! hit;
		var node = ensureNode();
		if ( node ) {
			if ( hit ) {
				node.textContent = hit.message || '';
				node.classList.add( 'is-active' );
			} else {
				node.textContent = '';
				node.classList.remove( 'is-active' );
			}
		}
		gateDetails( blocked );
	}

	function evaluate() {
		var ymd = getStartYmd();
		if ( ! ymd ) {
			// No resolvable start yet: never show an error, clear our state.
			setBlocked( null );
			return;
		}
		setBlocked( findHit( ymd ) );
	}

	var debounceTimer = null;
	function evaluateSoon() {
		if ( debounceTimer ) {
			clearTimeout( debounceTimer );
		}
		debounceTimer = setTimeout( evaluate, 150 );
	}

	function bind() {
		// Canonical field / datepicker change events.
		var fields = document.querySelectorAll( '[name^="wc_bookings_field_start_date"], .wc-bookings-date-picker input' );
		for ( var i = 0; i < fields.length; i++ ) {
			fields[ i ].addEventListener( 'change', evaluateSoon );
			fields[ i ].addEventListener( 'input', evaluateSoon );
		}

		// Bookings triggers these via jQuery, so they must be bound via jQuery.
		if ( window.jQuery ) {
			window.jQuery( 'body' ).off( 'wc_bookings_calculated_cost.tcbfPickup' )
				.on( 'wc_bookings_calculated_cost.tcbfPickup', evaluateSoon );
			window.jQuery( '.wc-bookings-booking-form' ).off( 'date-selected.tcbfPickup' )
				.on( 'date-selected.tcbfPickup', evaluateSoon );
		}

		// Debounced fallback: Bookings re-renders the cost node on every
		// AJAX recalculation, which implies a possible date change. Scoped to
		// that node — not a global body observer, no polling.
		var cost = document.querySelector( '.wc-bookings-booking-cost' );
		if ( cost && window.MutationObserver ) {
			new MutationObserver( evaluateSoon )
				.observe( cost, { childList: true, subtree: true, characterData: true } );
		}

		// Extra guard only: intercept submission while TCBF's own blocked
		// flag is true. Woo's button state is never touched.
		var cart = document.querySelector( 'form.cart' );
		if ( cart ) {
			cart.addEventListener( 'submit', function ( e ) {
				if ( blocked ) {
					e.preventDefault();
					var node = document.querySelector( '.tcbf-pickup-restriction' );
					if ( node && node.scrollIntoView ) {
						node.scrollIntoView( { behavior: 'smooth', block: 'center' } );
					}
				}
			} );
		}

		// Initial evaluation covers browser-restored field values.
		evaluate();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', bind );
	} else {
		bind();
	}
})();
