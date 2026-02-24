/**
 * TCBF Transport — Cart toggle + Google Maps address picker
 *
 * Architecture:
 * - Per-bike transport toggle on rental items in cart
 * - One delivery address per order (shared across all transported bikes)
 * - First toggle ON → opens address picker modal
 * - Subsequent toggles → inherit existing address, no prompt
 * - Changing address updates all transport items
 *
 * Identification:
 * - Each toggle uses the rental's cart_item_key as identifier (data-cart-key)
 * - Transport child items reference their parent rental via _tcbf_transport_parent_key
 *
 * Depends on: jQuery, tcbfTransport (wp_localize_script)
 * Optional: Google Maps Places API (for autocomplete)
 */
(function ($) {
	'use strict';

	if (typeof tcbfTransport === 'undefined') {
		return;
	}

	var config = tcbfTransport;
	var i18n   = config.i18n || {};
	var $modal = null;
	var autocomplete = null;
	var pendingCartKey = null;
	var selectedPlace = null;

	/* ================================================================
	 * Initialization
	 * ================================================================ */

	$(document).ready(function () {
		bindToggleEvents();
		bindChangeAddressLink();
	});

	// Re-bind after WC cart updates (AJAX cart refresh)
	$(document.body).on('updated_wc_div updated_cart_totals', function () {
		bindToggleEvents();
		bindChangeAddressLink();
	});

	/* ================================================================
	 * Toggle events
	 * ================================================================ */

	function bindToggleEvents() {
		$('.tcbf-transport-toggle__input')
			.off('change.tcbfTransport')
			.on('change.tcbfTransport', function () {
				var $input   = $(this);
				var cartKey  = $input.data('cart-key');
				var enabled  = $input.is(':checked') ? '1' : '0';
				var $toggle  = $input.closest('.tcbf-transport-toggle');

				$toggle.addClass('tcbf-transport-toggle--loading');

				$.post(config.ajaxUrl, {
					action:   'tcbf_transport_toggle',
					cart_key: cartKey,
					enabled:  enabled,
					nonce:    config.nonce
				}, function (response) {
					$toggle.removeClass('tcbf-transport-toggle--loading');

					if (!response.success) {
						showError(response.data ? response.data.message : i18n.errorGeneric);
						$input.prop('checked', !$input.is(':checked'));
						return;
					}

					var data = response.data;

					if (data.action === 'needs_address') {
						// First toggle — need address
						pendingCartKey = cartKey;
						openAddressModal();
						return;
					}

					if (data.action === 'added') {
						updateToggleDisplay($toggle, true, data.price, data.zone);
					}

					if (data.action === 'removed') {
						updateToggleDisplay($toggle, false, null, null);
					}

					// Refresh cart fragments
					if (data.fragments) {
						applyFragments(data.fragments);
					}

					// Trigger WC cart update
					$(document.body).trigger('wc_update_cart');

				}).fail(function () {
					$toggle.removeClass('tcbf-transport-toggle--loading');
					showError(i18n.errorGeneric);
					$input.prop('checked', !$input.is(':checked'));
				});
			});
	}

	function bindChangeAddressLink() {
		$(document).off('click.tcbfChangeAddr', '.tcbf-transport-change-address');
		$(document).on('click.tcbfChangeAddr', '.tcbf-transport-change-address', function (e) {
			e.preventDefault();
			pendingCartKey = null; // changing address, not adding new
			openAddressModal();
		});
	}

	/* ================================================================
	 * Address picker modal
	 * ================================================================ */

	function openAddressModal() {
		if ($modal) {
			$modal.remove();
		}

		var modalHtml =
			'<div class="tcbf-transport-modal-overlay">' +
			'<div class="tcbf-transport-modal">' +
			'<div class="tcbf-transport-modal__header">' +
			'<h3>' + escHtml(i18n.modalTitle) + '</h3>' +
			'<button type="button" class="tcbf-transport-modal__close">&times;</button>' +
			'</div>' +
			'<div class="tcbf-transport-modal__body">' +
			'<label class="tcbf-transport-modal__label" for="tcbf-transport-address-input">' + escHtml(i18n.addressLabel) + '</label>' +
			'<input type="text" id="tcbf-transport-address-input" class="tcbf-transport-modal__input" placeholder="' + escAttr(i18n.addressLabel) + '" autocomplete="off" />' +
			'<div class="tcbf-transport-modal__map" id="tcbf-transport-map"></div>' +
			'<div class="tcbf-transport-modal__quote" id="tcbf-transport-quote" style="display:none">' +
			'<div class="tcbf-transport-modal__quote-row">' +
			'<span class="tcbf-transport-modal__quote-label">' + escHtml(i18n.quoteLabel) + ':</span>' +
			'<span class="tcbf-transport-modal__quote-value" id="tcbf-transport-quote-price"></span>' +
			'</div>' +
			'<div class="tcbf-transport-modal__quote-row">' +
			'<span class="tcbf-transport-modal__quote-label">' + escHtml(i18n.zoneLabel) + ':</span>' +
			'<span class="tcbf-transport-modal__quote-value" id="tcbf-transport-quote-zone"></span>' +
			'</div>' +
			'</div>' +
			'</div>' +
			'<div class="tcbf-transport-modal__footer">' +
			'<button type="button" class="button tcbf-transport-modal__cancel">' + escHtml(i18n.cancelBtn) + '</button>' +
			'<button type="button" class="button button-primary tcbf-transport-modal__confirm" disabled>' + escHtml(i18n.confirmBtn) + '</button>' +
			'</div>' +
			'</div>' +
			'</div>';

		$modal = $(modalHtml).appendTo('body');

		// Bind modal events
		$modal.find('.tcbf-transport-modal__close, .tcbf-transport-modal__cancel').on('click', function () {
			closeAddressModal(true);
		});

		$modal.find('.tcbf-transport-modal__confirm').on('click', function () {
			confirmAddress();
		});

		// Close on overlay click
		$modal.find('.tcbf-transport-modal-overlay').on('click', function (e) {
			if (e.target === this) {
				closeAddressModal(true);
			}
		});

		// Close on Escape key
		$(document).on('keydown.tcbfModal', function (e) {
			if (e.key === 'Escape') {
				closeAddressModal(true);
			}
		});

		// Initialize Google Maps autocomplete
		initAutocomplete();

		// Pre-fill if address exists
		if (config.address && config.address.address) {
			$('#tcbf-transport-address-input').val(config.address.address);
			selectedPlace = {
				address: config.address.address,
				lat: config.address.lat,
				lng: config.address.lng
			};
			$modal.find('.tcbf-transport-modal__confirm').prop('disabled', false);
			fetchQuote(config.address.lat, config.address.lng);
		}

		// Prevent body scroll
		$('body').addClass('tcbf-modal-open');
	}

	function closeAddressModal(revertToggle) {
		if ($modal) {
			$modal.remove();
			$modal = null;
		}

		$(document).off('keydown.tcbfModal');
		$('body').removeClass('tcbf-modal-open');

		// If closing without confirming and there was a pending toggle, revert it
		if (revertToggle && pendingCartKey) {
			var $input = $('.tcbf-transport-toggle__input[data-cart-key="' + pendingCartKey + '"]');
			$input.prop('checked', false);
		}

		pendingCartKey = null;
		selectedPlace = null;
	}

	function confirmAddress() {
		if (!selectedPlace || !selectedPlace.lat || !selectedPlace.lng) {
			return;
		}

		var $confirmBtn = $modal.find('.tcbf-transport-modal__confirm');
		$confirmBtn.prop('disabled', true).text(i18n.loading);

		$.post(config.ajaxUrl, {
			action:   'tcbf_transport_set_address',
			address:  selectedPlace.address,
			lat:      selectedPlace.lat,
			lng:      selectedPlace.lng,
			cart_key: pendingCartKey || '',
			nonce:    config.nonce
		}, function (response) {
			if (!response.success) {
				showError(response.data ? response.data.message : i18n.errorGeneric);
				$confirmBtn.prop('disabled', false).text(i18n.confirmBtn);
				return;
			}

			var data = response.data;

			// Update global config
			config.hasAddress = true;
			config.address = data.address;

			// Close modal
			closeAddressModal(false);

			// Refresh cart
			if (data.fragments) {
				applyFragments(data.fragments);
			}
			$(document.body).trigger('wc_update_cart');

		}).fail(function () {
			showError(i18n.errorGeneric);
			$confirmBtn.prop('disabled', false).text(i18n.confirmBtn);
		});
	}

	/* ================================================================
	 * Google Maps Autocomplete
	 * ================================================================ */

	function initAutocomplete() {
		var $input = document.getElementById('tcbf-transport-address-input');
		if (!$input) return;

		if (config.hasMapsKey && typeof google !== 'undefined' && google.maps && google.maps.places) {
			autocomplete = new google.maps.places.Autocomplete($input, {
				types: ['address'],
				fields: ['formatted_address', 'geometry']
			});

			autocomplete.addListener('place_changed', function () {
				var place = autocomplete.getPlace();
				if (!place.geometry || !place.geometry.location) {
					selectedPlace = null;
					$modal.find('.tcbf-transport-modal__confirm').prop('disabled', true);
					return;
				}

				selectedPlace = {
					address: place.formatted_address || $input.value,
					lat: place.geometry.location.lat(),
					lng: place.geometry.location.lng()
				};

				$modal.find('.tcbf-transport-modal__confirm').prop('disabled', false);
				fetchQuote(selectedPlace.lat, selectedPlace.lng);
				initMap(selectedPlace.lat, selectedPlace.lng);
			});
		} else {
			// Fallback: manual input (no autocomplete)
			// User types address, we enable confirm (address will be geocoded server-side)
			$($input).on('input', function () {
				var val = $(this).val().trim();
				if (val.length > 5) {
					$modal.find('.tcbf-transport-modal__confirm').prop('disabled', false);
					selectedPlace = { address: val, lat: 0, lng: 0 };
				} else {
					$modal.find('.tcbf-transport-modal__confirm').prop('disabled', true);
					selectedPlace = null;
				}
			});
		}
	}

	function initMap(lat, lng) {
		var $mapContainer = document.getElementById('tcbf-transport-map');
		if (!$mapContainer) return;

		if (typeof google === 'undefined' || !google.maps) {
			$mapContainer.style.display = 'none';
			return;
		}

		$mapContainer.style.display = 'block';

		var map = new google.maps.Map($mapContainer, {
			center: { lat: lat, lng: lng },
			zoom: 14,
			disableDefaultUI: true,
			zoomControl: true
		});

		new google.maps.Marker({
			position: { lat: lat, lng: lng },
			map: map
		});
	}

	/* ================================================================
	 * Quote preview
	 * ================================================================ */

	function fetchQuote(lat, lng) {
		var $quoteContainer = $('#tcbf-transport-quote');
		var $priceEl = $('#tcbf-transport-quote-price');
		var $zoneEl = $('#tcbf-transport-quote-zone');

		$quoteContainer.show();
		$priceEl.text(i18n.loading);
		$zoneEl.text('');

		$.post(config.ajaxUrl, {
			action: 'tcbf_transport_quote',
			lat:    lat,
			lng:    lng,
			nonce:  config.nonce
		}, function (response) {
			if (!response.success || !response.data) {
				$priceEl.text('--');
				return;
			}

			var data = response.data;
			var quote = data.quote || {};
			var price = quote.price_total || 0;

			$priceEl.text(formatPrice(price));

			if (data.zone_name) {
				$zoneEl.text(data.zone_name);
			} else {
				$zoneEl.text(i18n.outsideZones);
			}
		}).fail(function () {
			$priceEl.text('--');
		});
	}

	/* ================================================================
	 * UI helpers
	 * ================================================================ */

	function updateToggleDisplay($toggle, enabled, price, zone) {
		var $price   = $toggle.find('.tcbf-transport-toggle__price');
		var $address = $toggle.find('.tcbf-transport-toggle__address');

		if (enabled && price) {
			$price.text(formatPrice(price)).show();
		} else {
			$price.hide().text('');
		}

		if (enabled && config.address && config.address.address) {
			var addr = config.address.address;
			if (addr.length > 50) addr = addr.substring(0, 47) + '...';
			$address.text(addr).show();
		} else {
			$address.hide().text('');
		}
	}

	function applyFragments(fragments) {
		if (!fragments) return;
		$.each(fragments, function (selector, content) {
			$(selector).replaceWith(content);
		});
	}

	function formatPrice(amount) {
		if (typeof amount !== 'number') {
			amount = parseFloat(amount) || 0;
		}
		return amount.toFixed(2) + ' \u20AC'; // EUR symbol
	}

	function showError(message) {
		if (!message) message = i18n.errorGeneric || 'Error';
		// Use WC notice if available, otherwise simple alert
		if (typeof wc_add_notice === 'function') {
			wc_add_notice(message, 'error');
		} else {
			// Inject error notice at top of cart
			var $notice = $('<div class="woocommerce-error tcbf-transport-error" role="alert">' + escHtml(message) + '</div>');
			$('.woocommerce-cart-form').before($notice);
			setTimeout(function () { $notice.fadeOut(400, function () { $(this).remove(); }); }, 5000);
		}
	}

	function escHtml(str) {
		if (!str) return '';
		var div = document.createElement('div');
		div.textContent = str;
		return div.innerHTML;
	}

	function escAttr(str) {
		if (!str) return '';
		return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
	}

})(jQuery);
