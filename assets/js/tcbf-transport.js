/**
 * TCBF Transport v2 — Dual-direction toggles + map-first address picker
 *
 * Architecture:
 * - Two toggles per rental: Delivery + Return (pickup)
 * - Each direction has its own modal with address, map, window selector
 * - Map always visible with pin-drop + drag support
 * - Google Places Autocomplete with server-side geocode fallback
 * - Quote preview with availability info
 * - "Use same address for return" checkbox in delivery modal
 *
 * Depends on: jQuery, tcbfTransport (wp_localize_script)
 * Optional: Google Maps Places API
 */
(function ($) {
	'use strict';

	if (typeof tcbfTransport === 'undefined') {
		return;
	}

	var config = tcbfTransport;
	var i18n   = config.i18n || {};
	var $modal = null;
	var map = null;
	var marker = null;
	var autocomplete = null;
	var pendingCartKey = null;
	var pendingDirection = null;
	var selectedPlace = null;
	var quoteDebounce = null;

	/* ================================================================
	 * Initialization
	 * ================================================================ */

	$(document).ready(function () {
		bindToggleEvents();
	});

	$(document.body).on('updated_wc_div updated_cart_totals', function () {
		bindToggleEvents();
	});

	/* ================================================================
	 * Toggle events
	 * ================================================================ */

	function bindToggleEvents() {
		$('.tcbf-transport-toggle__input')
			.off('change.tcbfTransport')
			.on('change.tcbfTransport', function () {
				var $input    = $(this);
				var cartKey   = $input.data('cart-key');
				var direction = $input.data('direction');
				var enabled   = $input.is(':checked') ? '1' : '0';
				var $toggle   = $input.closest('.tcbf-transport-toggle');

				$toggle.addClass('tcbf-transport-toggle--loading');

				$.post(config.ajaxUrl, {
					action:    'tcbf_transport_toggle',
					cart_key:  cartKey,
					direction: direction,
					enabled:   enabled,
					nonce:     config.nonce
				}, function (response) {
					$toggle.removeClass('tcbf-transport-toggle--loading');

					if (!response.success) {
						showError(response.data ? response.data.message : i18n.errorGeneric);
						$input.prop('checked', !$input.is(':checked'));
						return;
					}

					var data = response.data;

					if (data.action === 'needs_address') {
						pendingCartKey = cartKey;
						pendingDirection = direction;
						openAddressModal(direction);
						return;
					}

					if (data.action === 'added') {
						updateToggleDisplay($toggle, true, data.price, data.zone, direction);
					}

					if (data.action === 'removed') {
						updateToggleDisplay($toggle, false, null, null, direction);
					}

					if (data.fragments) {
						applyFragments(data.fragments);
					}
					$(document.body).trigger('wc_update_cart');

				}).fail(function () {
					$toggle.removeClass('tcbf-transport-toggle--loading');
					showError(i18n.errorGeneric);
					$input.prop('checked', !$input.is(':checked'));
				});
			});
	}

	/* ================================================================
	 * Address picker modal
	 * ================================================================ */

	function openAddressModal(direction) {
		if ($modal) {
			$modal.remove();
		}

		var isDelivery = (direction === 'delivery');
		var title = isDelivery ? i18n.deliveryTitle : i18n.returnTitle;
		var existingAddr = isDelivery ? config.deliveryAddress : config.returnAddress;
		var existingWindow = isDelivery ? config.deliveryWindow : config.returnWindow;

		var linkReturnHtml = '';
		if (isDelivery) {
			var linkChecked = config.linkReturn ? 'checked' : '';
			linkReturnHtml =
				'<label class="tcbf-transport-modal__link-return">' +
				'<input type="checkbox" id="tcbf-transport-link-return" ' + linkChecked + ' />' +
				'<span>' + escHtml(i18n.linkReturnLabel) + '</span>' +
				'</label>';
		}

		var modalHtml =
			'<div class="tcbf-transport-modal-overlay">' +
			'<div class="tcbf-transport-modal">' +
			'<div class="tcbf-transport-modal__header">' +
			'<h3>' + escHtml(title) + '</h3>' +
			'<button type="button" class="tcbf-transport-modal__close">&times;</button>' +
			'</div>' +
			'<div class="tcbf-transport-modal__body">' +
			'<div class="tcbf-transport-modal__map-wrap">' +
			'<div class="tcbf-transport-modal__map" id="tcbf-transport-map"></div>' +
			'</div>' +
			'<div class="tcbf-transport-modal__controls">' +
			'<label class="tcbf-transport-modal__label" for="tcbf-transport-address-input">' + escHtml(i18n.addressLabel) + '</label>' +
			'<input type="text" id="tcbf-transport-address-input" class="tcbf-transport-modal__input" placeholder="' + escAttr(i18n.addressLabel) + '" autocomplete="off" />' +
			'<div class="tcbf-transport-modal__window-row">' +
			'<label class="tcbf-transport-modal__label">' + escHtml(i18n.windowLabel) + '</label>' +
			'<div class="tcbf-transport-modal__window-buttons">' +
			'<button type="button" class="tcbf-transport-modal__window-btn' + (existingWindow !== 'afternoon' ? ' tcbf-transport-modal__window-btn--active' : '') + '" data-window="morning">' + escHtml(i18n.windowMorning) + '</button>' +
			'<button type="button" class="tcbf-transport-modal__window-btn' + (existingWindow === 'afternoon' ? ' tcbf-transport-modal__window-btn--active' : '') + '" data-window="afternoon">' + escHtml(i18n.windowAfternoon) + '</button>' +
			'</div>' +
			'</div>' +
			linkReturnHtml +
			'<div class="tcbf-transport-modal__quote" id="tcbf-transport-quote" style="display:none">' +
			'<div class="tcbf-transport-modal__quote-row">' +
			'<span class="tcbf-transport-modal__quote-label">' + escHtml(i18n.quoteLabel) + ':</span>' +
			'<span class="tcbf-transport-modal__quote-value" id="tcbf-transport-quote-price"></span>' +
			'</div>' +
			'<div class="tcbf-transport-modal__quote-row">' +
			'<span class="tcbf-transport-modal__quote-label">' + escHtml(i18n.zoneLabel) + ':</span>' +
			'<span class="tcbf-transport-modal__quote-value" id="tcbf-transport-quote-zone"></span>' +
			'</div>' +
			'<div class="tcbf-transport-modal__quote-row" id="tcbf-transport-availability-row" style="display:none">' +
			'<span class="tcbf-transport-modal__quote-label">' + escHtml(i18n.availabilityLabel) + ':</span>' +
			'<span class="tcbf-transport-modal__quote-value" id="tcbf-transport-availability"></span>' +
			'</div>' +
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
			confirmAddress(direction);
		});

		// Close on overlay click
		$modal.on('click', function (e) {
			if ($(e.target).hasClass('tcbf-transport-modal-overlay')) {
				closeAddressModal(true);
			}
		});

		$(document).on('keydown.tcbfModal', function (e) {
			if (e.key === 'Escape') {
				closeAddressModal(true);
			}
		});

		// Window selector buttons
		$modal.on('click', '.tcbf-transport-modal__window-btn', function () {
			$modal.find('.tcbf-transport-modal__window-btn').removeClass('tcbf-transport-modal__window-btn--active');
			$(this).addClass('tcbf-transport-modal__window-btn--active');
			// Re-fetch quote with new window
			if (selectedPlace && selectedPlace.lat && selectedPlace.lng) {
				var win = $(this).data('window');
				fetchQuote(selectedPlace.lat, selectedPlace.lng, direction, win);
			}
		});

		// Initialize map and autocomplete
		initMapAndAutocomplete(existingAddr, direction);

		$('body').addClass('tcbf-modal-open');
	}

	function closeAddressModal(revertToggle) {
		if ($modal) {
			$modal.remove();
			$modal = null;
		}

		map = null;
		marker = null;
		autocomplete = null;

		$(document).off('keydown.tcbfModal');
		$('body').removeClass('tcbf-modal-open');

		if (revertToggle && pendingCartKey && pendingDirection) {
			var $input = $('.tcbf-transport-toggle__input[data-cart-key="' + pendingCartKey + '"][data-direction="' + pendingDirection + '"]');
			$input.prop('checked', false);
		}

		pendingCartKey = null;
		pendingDirection = null;
		selectedPlace = null;
	}

	function confirmAddress(direction) {
		if (!selectedPlace || (!selectedPlace.lat && !selectedPlace.lng)) {
			// Try server-side geocoding for manual input
			var typedAddress = $('#tcbf-transport-address-input').val().trim();
			if (typedAddress.length > 5) {
				geocodeAndConfirm(typedAddress, direction);
				return;
			}
			return;
		}

		doConfirm(direction);
	}

	function geocodeAndConfirm(address, direction) {
		var $confirmBtn = $modal.find('.tcbf-transport-modal__confirm');
		$confirmBtn.prop('disabled', true).text(i18n.geocoding || i18n.loading);

		$.post(config.ajaxUrl, {
			action:  'tcbf_transport_geocode',
			address: address,
			nonce:   config.nonce
		}, function (response) {
			if (!response.success || !response.data) {
				showError(i18n.geocodeFailed || i18n.errorGeneric);
				$confirmBtn.prop('disabled', false).text(i18n.confirmBtn);
				return;
			}

			var data = response.data;
			selectedPlace = {
				address: data.formatted_address || address,
				lat: data.lat,
				lng: data.lng,
				place_id: data.place_id || ''
			};

			$('#tcbf-transport-address-input').val(selectedPlace.address);

			// Update map
			if (map && selectedPlace.lat && selectedPlace.lng) {
				var pos = { lat: selectedPlace.lat, lng: selectedPlace.lng };
				map.setCenter(pos);
				map.setZoom(14);
				if (marker) {
					marker.setPosition(pos);
				}
			}

			doConfirm(direction);
		}).fail(function () {
			showError(i18n.geocodeFailed || i18n.errorGeneric);
			$confirmBtn.prop('disabled', false).text(i18n.confirmBtn);
		});
	}

	function doConfirm(direction) {
		if (!selectedPlace || !selectedPlace.lat || !selectedPlace.lng) {
			return;
		}

		var $confirmBtn = $modal.find('.tcbf-transport-modal__confirm');
		$confirmBtn.prop('disabled', true).text(i18n.loading);

		var selectedWindow = getSelectedWindow();
		var linkReturn = 0;
		var $linkCheck = $('#tcbf-transport-link-return');
		if ($linkCheck.length && $linkCheck.is(':checked')) {
			linkReturn = 1;
		}

		$.post(config.ajaxUrl, {
			action:      'tcbf_transport_set_address',
			address:     selectedPlace.address,
			lat:         selectedPlace.lat,
			lng:         selectedPlace.lng,
			place_id:    selectedPlace.place_id || '',
			direction:   direction,
			window:      selectedWindow,
			cart_key:    pendingCartKey || '',
			link_return: linkReturn,
			nonce:       config.nonce
		}, function (response) {
			if (!response.success) {
				showError(response.data ? response.data.message : i18n.errorGeneric);
				$confirmBtn.prop('disabled', false).text(i18n.confirmBtn);
				return;
			}

			var data = response.data;

			// Update config
			if (direction === 'delivery') {
				config.deliveryAddress = data.address;
				config.deliveryWindow = selectedWindow;
				if (linkReturn) {
					config.returnAddress = data.address;
					config.returnWindow = selectedWindow;
					config.linkReturn = 1;
				}
			} else {
				config.returnAddress = data.address;
				config.returnWindow = selectedWindow;
			}

			closeAddressModal(false);

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
	 * Google Maps + Autocomplete (wrapped in adapter for future migration)
	 * ================================================================ */

	function initMapAndAutocomplete(existingAddr, direction) {
		var $mapContainer = document.getElementById('tcbf-transport-map');
		var $input = document.getElementById('tcbf-transport-address-input');

		// Default center (Girona area)
		var defaultLat = 41.98;
		var defaultLng = 2.82;
		var defaultZoom = 9;

		if (existingAddr && existingAddr.lat && existingAddr.lng) {
			defaultLat = existingAddr.lat;
			defaultLng = existingAddr.lng;
			defaultZoom = 14;
		}

		// Pre-fill
		if (existingAddr && existingAddr.address) {
			$('#tcbf-transport-address-input').val(existingAddr.address);
			selectedPlace = {
				address: existingAddr.address,
				lat: existingAddr.lat,
				lng: existingAddr.lng,
				place_id: existingAddr.place_id || ''
			};
			$modal.find('.tcbf-transport-modal__confirm').prop('disabled', false);
		}

		// Initialize map (with retry for Google Maps load)
		initGoogleMap($mapContainer, defaultLat, defaultLng, defaultZoom, direction);

		// Initialize autocomplete
		initAutocompleteProvider($input, direction);

		// Fetch initial quote if address exists
		if (selectedPlace && selectedPlace.lat && selectedPlace.lng) {
			var win = getSelectedWindow();
			fetchQuote(selectedPlace.lat, selectedPlace.lng, direction, win);
		}
	}

	function initGoogleMap(container, lat, lng, zoom, direction) {
		if (!container) return;

		// Retry up to 4 times (~2 seconds) waiting for google.maps
		var attempts = 0;
		function tryInit() {
			if (typeof google !== 'undefined' && google.maps) {
				container.style.display = 'block';

				map = new google.maps.Map(container, {
					center: { lat: lat, lng: lng },
					zoom: zoom,
					disableDefaultUI: true,
					zoomControl: true,
					mapTypeControl: false,
					streetViewControl: false
				});

				// Place marker
				marker = new google.maps.Marker({
					position: { lat: lat, lng: lng },
					map: map,
					draggable: true
				});

				// Click to move marker
				map.addListener('click', function (e) {
					var pos = { lat: e.latLng.lat(), lng: e.latLng.lng() };
					marker.setPosition(pos);
					onMapPinMoved(pos, direction);
				});

				// Drag marker
				marker.addListener('dragend', function () {
					var pos = { lat: marker.getPosition().lat(), lng: marker.getPosition().lng() };
					onMapPinMoved(pos, direction);
				});

			} else if (attempts < 4) {
				attempts++;
				setTimeout(tryInit, 500);
			} else {
				container.style.display = 'none';
			}
		}
		tryInit();
	}

	function onMapPinMoved(pos, direction) {
		// Reverse geocode to get address
		if (typeof google !== 'undefined' && google.maps && google.maps.Geocoder) {
			var geocoder = new google.maps.Geocoder();
			geocoder.geocode({ location: pos }, function (results, status) {
				if (status === 'OK' && results[0]) {
					selectedPlace = {
						address: results[0].formatted_address,
						lat: pos.lat,
						lng: pos.lng,
						place_id: results[0].place_id || ''
					};
					$('#tcbf-transport-address-input').val(selectedPlace.address);
				} else {
					selectedPlace = {
						address: pos.lat.toFixed(6) + ', ' + pos.lng.toFixed(6),
						lat: pos.lat,
						lng: pos.lng,
						place_id: ''
					};
					$('#tcbf-transport-address-input').val(selectedPlace.address);
				}
				$modal.find('.tcbf-transport-modal__confirm').prop('disabled', false);
				var win = getSelectedWindow();
				fetchQuote(pos.lat, pos.lng, direction, win);
			});
		} else {
			// No geocoder, just use coordinates
			selectedPlace = {
				address: pos.lat.toFixed(6) + ', ' + pos.lng.toFixed(6),
				lat: pos.lat,
				lng: pos.lng,
				place_id: ''
			};
			$('#tcbf-transport-address-input').val(selectedPlace.address);
			$modal.find('.tcbf-transport-modal__confirm').prop('disabled', false);
			var win = getSelectedWindow();
			fetchQuote(pos.lat, pos.lng, direction, win);
		}
	}

	// TODO: Migrate to PlaceAutocompleteElement when Places API v2 is required
	function initAutocompleteProvider($input, direction) {
		if (!$input) return;

		if (config.hasMapsKey && typeof google !== 'undefined' && google.maps && google.maps.places) {
			try {
				autocomplete = new google.maps.places.Autocomplete($input, {
					fields: ['formatted_address', 'geometry', 'place_id', 'name'],
					componentRestrictions: { country: 'es' }
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
						lng: place.geometry.location.lng(),
						place_id: place.place_id || ''
					};

					$modal.find('.tcbf-transport-modal__confirm').prop('disabled', false);

					// Update map
					if (map && marker) {
						var pos = { lat: selectedPlace.lat, lng: selectedPlace.lng };
						map.setCenter(pos);
						map.setZoom(14);
						marker.setPosition(pos);
					}

					var win = getSelectedWindow();
					fetchQuote(selectedPlace.lat, selectedPlace.lng, direction, win);
				});
			} catch (e) {
				bindManualInput($input, direction);
			}
		} else {
			bindManualInput($input, direction);
		}
	}

	function bindManualInput(inputEl, direction) {
		$(inputEl).on('input', function () {
			var val = $(this).val().trim();
			if (val.length > 5) {
				$modal.find('.tcbf-transport-modal__confirm').prop('disabled', false);
				selectedPlace = { address: val, lat: 0, lng: 0, place_id: '' };
			} else {
				$modal.find('.tcbf-transport-modal__confirm').prop('disabled', true);
				selectedPlace = null;
			}
		});
	}

	/* ================================================================
	 * Quote preview
	 * ================================================================ */

	function fetchQuote(lat, lng, direction, window) {
		if (quoteDebounce) {
			clearTimeout(quoteDebounce);
		}

		quoteDebounce = setTimeout(function () {
			var $quoteContainer = $('#tcbf-transport-quote');
			var $priceEl = $('#tcbf-transport-quote-price');
			var $zoneEl = $('#tcbf-transport-quote-zone');
			var $availRow = $('#tcbf-transport-availability-row');
			var $availEl = $('#tcbf-transport-availability');

			$quoteContainer.show();
			$priceEl.text(i18n.loading);
			$zoneEl.text('');

			$.post(config.ajaxUrl, {
				action:    'tcbf_transport_quote',
				lat:       lat,
				lng:       lng,
				direction: direction || 'delivery',
				window:    window || 'morning',
				nonce:     config.nonce
			}, function (response) {
				if (!response.success || !response.data) {
					$priceEl.text('--');
					return;
				}

				var data = response.data;
				var quote = data.quote || {};
				var total = quote.price_total || 0;
				var perBike = quote.per_bike_price || total;
				var bikeQty = quote.bike_qty || 1;

				if (bikeQty > 1) {
					$priceEl.text(formatPrice(total) + ' (' + formatPrice(perBike) + ' ' + i18n.perBikeLabel + ')');
				} else {
					$priceEl.text(formatPrice(total));
				}

				if (data.zone_name) {
					$zoneEl.text(data.zone_name);
				} else {
					$zoneEl.text(i18n.outsideZones);
				}

				if (data.remaining !== null && data.remaining !== undefined) {
					$availRow.show();
					$availEl.text(data.remaining);
				} else {
					$availRow.hide();
				}
			}).fail(function () {
				$priceEl.text('--');
			});
		}, 300);
	}

	/* ================================================================
	 * UI helpers
	 * ================================================================ */

	function getSelectedWindow() {
		if (!$modal) return 'morning';
		var $active = $modal.find('.tcbf-transport-modal__window-btn--active');
		return $active.length ? $active.data('window') : 'morning';
	}

	function updateToggleDisplay($toggle, enabled, price, zone, direction) {
		var $price   = $toggle.find('.tcbf-transport-toggle__price');
		var $address = $toggle.find('.tcbf-transport-toggle__address');

		if (enabled && price) {
			$price.text(formatPrice(price)).show();
		} else {
			$price.hide().text('');
		}

		var addrData = (direction === 'delivery') ? config.deliveryAddress : config.returnAddress;
		if (enabled && addrData && addrData.address) {
			var addr = addrData.address;
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
		return amount.toFixed(2) + ' \u20AC';
	}

	function showError(message) {
		if (!message) message = i18n.errorGeneric || 'Error';
		var $notice = $('<div class="woocommerce-error tcbf-transport-error" role="alert">' + escHtml(message) + '</div>');
		$('.woocommerce-cart-form').before($notice);
		setTimeout(function () { $notice.fadeOut(400, function () { $(this).remove(); }); }, 5000);
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
