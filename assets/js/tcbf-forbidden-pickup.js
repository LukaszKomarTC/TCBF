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

	// While blocked, Woo's calculated price must not read as an actionable
	// offer: hide the cost node via a TCBF class only. Woo keeps ownership of
	// the node and recalculates freely while hidden; removing the class
	// exposes whatever Woo currently renders — no price reconstruction.
	// Re-applied on every evaluation, so a Bookings re-render while blocked
	// (caught by the cost-node observer) cannot resurface the price.
	function applyCostState( on ) {
		var cost = document.querySelector( '.wc-bookings-booking-cost' );
		if ( cost ) {
			cost.classList.toggle( 'tcbf-pickup-cost-hidden', on );
		}
	}

	// While blocked, the submit CTA is genuinely disabled (real disabled
	// property — not keyboard-activatable — plus aria-disabled and a TCBF
	// class for styling). Unblocking restores EXACTLY the pre-block disabled
	// property recorded at block time and touches nothing else: on this
	// site's theme the button carries .single_add_to_cart_button but not
	// .wc-bookings-booking-form-button, so Bookings manages its own state
	// via its 'disabled' CLASS on the button (which TCBF never touches) —
	// the property is TCBF's to set and restore. The data-tcbf-blocked
	// marker guarantees TCBF only ever removes state TCBF itself added; a
	// Woo re-render that replaces the button drops the marker, and the next
	// evaluation (observer/change-driven) re-applies the blocked state to
	// the fresh node.
	function applyButtonState( on ) {
		var cart = document.querySelector( 'form.cart' );
		if ( ! cart ) {
			return;
		}
		var btn = cart.querySelector( 'button[type="submit"], input[type="submit"], .single_add_to_cart_button' );
		if ( ! btn ) {
			return;
		}
		if ( on ) {
			if ( ! btn.hasAttribute( 'data-tcbf-blocked' ) ) {
				btn.setAttribute( 'data-tcbf-prev-disabled', btn.disabled ? '1' : '0' );
				btn.setAttribute( 'data-tcbf-blocked', '1' );
			}
			btn.classList.add( 'tcbf-pickup-disabled' );
			btn.setAttribute( 'aria-disabled', 'true' );
			btn.disabled = true;
		} else if ( btn.hasAttribute( 'data-tcbf-blocked' ) ) {
			var prev = btn.getAttribute( 'data-tcbf-prev-disabled' ) === '1';
			btn.removeAttribute( 'data-tcbf-blocked' );
			btn.removeAttribute( 'data-tcbf-prev-disabled' );
			btn.classList.remove( 'tcbf-pickup-disabled' );
			btn.removeAttribute( 'aria-disabled' );
			btn.disabled = prev;
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
		applyCostState( blocked );
		applyButtonState( blocked );
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
		// flag is true. Woo's button state is never touched. Two layers are
		// needed because Woo Bookings submits the cart form programmatically
		// (form.submit() does NOT fire 'submit' listeners — verified against
		// the installed Bookings on staging): a capture-phase click guard on
		// the submit control catches that path before Bookings' handler runs,
		// and the plain submit listener covers everything else.
		var cart = document.querySelector( 'form.cart' );
		if ( cart ) {
			var showWarning = function () {
				var node = document.querySelector( '.tcbf-pickup-restriction' );
				if ( node && node.scrollIntoView ) {
					node.scrollIntoView( { behavior: 'smooth', block: 'center' } );
				}
			};
			cart.addEventListener( 'click', function ( e ) {
				if ( ! blocked || ! e.target || ! e.target.closest ) {
					return;
				}
				var btn = e.target.closest( 'button[type="submit"], input[type="submit"], .single_add_to_cart_button' );
				if ( btn ) {
					e.preventDefault();
					e.stopPropagation();
					showWarning();
				}
			}, true );
			cart.addEventListener( 'submit', function ( e ) {
				if ( blocked ) {
					e.preventDefault();
					showWarning();
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
