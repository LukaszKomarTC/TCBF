/**
 * TCBF Transport v3 — Order-level service card + combined modal
 *
 * Architecture:
 * - Single service card below cart table (not per-bike)
 * - Combined modal: delivery + pickup on one page
 * - "Same address for pickup" toggle (default on)
 * - Bike checklist (all selected by default)
 * - Bulk configure AJAX: one request creates/removes all transport items
 * - Google Places Autocomplete with server-side geocode fallback
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
	var deliveryMap = null;
	var deliveryMarker = null;
	var deliveryAutocomplete = null;
	var pickupMap = null;
	var pickupMarker = null;
	var pickupAutocomplete = null;
	var deliveryPlace = null;
	var pickupPlace = null;
	var quoteDebounce = null;

	/* ================================================================
	 * Initialization
	 * ================================================================ */

	$(document).ready(function () {
		bindServiceCardEvents();
	});

	$(document.body).on('updated_wc_div updated_cart_totals', function () {
		bindServiceCardEvents();
	});

	/* ================================================================
	 * Service card events
	 * ================================================================ */

	function bindServiceCardEvents() {
		$('#tcbf-configure-transport')
			.off('click.tcbfTransport')
			.on('click.tcbfTransport', function () {
				openConfigureModal();
			});

		$('#tcbf-remove-transport')
			.off('click.tcbfTransport')
			.on('click.tcbfTransport', function () {
				if (confirm(i18n.removeConfirm || 'Remove transport for all bikes?')) {
					removeAllTransport();
				}
			});
	}

	function removeAllTransport() {
		var $card = $('#tcbf-service-card');
		$card.addClass('tcbf-service-card--loading');

		$.post(config.ajaxUrl, {
			action: 'tcbf_transport_bulk_configure',
			enable_delivery: 0,
			enable_pickup: 0,
			bike_keys: JSON.stringify([]),
			nonce: config.nonce
		}, function (response) {
			// Force full cart refresh
			$(document.body).trigger('wc_update_cart');
		}).fail(function () {
			$card.removeClass('tcbf-service-card--loading');
			showError(i18n.errorGeneric);
		});
	}

	/* ================================================================
	 * Combined configure modal
	 * ================================================================ */

	function openConfigureModal() {
		if ($modal) {
			$modal.remove();
		}

		var summary = config.summary || {};
		var bikes = config.bikes || [];

		// Restore previous settings
		var hasDelivery = (summary.delivery_count || 0) > 0;
		var hasPickup = (summary.pickup_count || 0) > 0;
		var sameAddr = summary.link_return ? true : (!hasPickup || !hasDelivery);
		var deliveryWindow = summary.delivery_window || 'morning';
		var pickupWindow = summary.pickup_window || 'morning';

		// Restore places from summary
		deliveryPlace = null;
		pickupPlace = null;
		if (summary.delivery_address) {
			deliveryPlace = {
				address: summary.delivery_address.address || '',
				lat: summary.delivery_address.lat || 0,
				lng: summary.delivery_address.lng || 0,
				place_id: summary.delivery_address.place_id || ''
			};
		}
		if (summary.pickup_address && !sameAddr) {
			pickupPlace = {
				address: summary.pickup_address.address || '',
				lat: summary.pickup_address.lat || 0,
				lng: summary.pickup_address.lng || 0,
				place_id: summary.pickup_address.place_id || ''
			};
		}

		// Build bike checklist HTML
		var bikeChecklistHtml = '';
		for (var i = 0; i < bikes.length; i++) {
			var bike = bikes[i];
			var label = bike.participant ? (bike.participant + ' — ' + bike.name) : bike.name;
			bikeChecklistHtml +=
				'<label class="tcbf-modal__bike-item">' +
				'<input type="checkbox" class="tcbf-modal__bike-check" data-bike-key="' + escAttr(bike.key) + '" checked />' +
				'<span>' + escHtml(label) + '</span>' +
				'</label>';
		}

		var modalHtml =
			'<div class="tcbf-transport-modal-overlay">' +
			'<div class="tcbf-transport-modal">' +

			// Header
			'<div class="tcbf-transport-modal__header">' +
			'<h3>' + escHtml(i18n.modalTitle) + '</h3>' +
			'<button type="button" class="tcbf-transport-modal__close">&times;</button>' +
			'</div>' +

			'<div class="tcbf-transport-modal__body">' +

			// Left: Map
			'<div class="tcbf-transport-modal__map-wrap">' +
			'<div class="tcbf-transport-modal__map" id="tcbf-delivery-map"></div>' +
			'<div class="tcbf-transport-modal__map tcbf-transport-modal__map--pickup" id="tcbf-pickup-map" style="display:none"></div>' +
			'</div>' +

			// Right: Controls
			'<div class="tcbf-transport-modal__controls">' +

			// === Delivery section ===
			'<div class="tcbf-modal__section" id="tcbf-delivery-section">' +
			'<div class="tcbf-modal__section-header">' +
			'<label class="tcbf-modal__section-toggle">' +
			'<input type="checkbox" id="tcbf-enable-delivery" ' + (hasDelivery || !hasPickup ? 'checked' : '') + ' />' +
			'<span>' + escHtml(i18n.deliverySection) + '</span>' +
			'</label>' +
			'</div>' +
			'<div class="tcbf-modal__section-body" id="tcbf-delivery-body">' +
			'<label class="tcbf-transport-modal__label" for="tcbf-delivery-address-input">' + escHtml(i18n.addressLabel) + '</label>' +
			'<input type="text" id="tcbf-delivery-address-input" class="tcbf-transport-modal__input" placeholder="Hotel, address..." autocomplete="off" />' +
			'<div class="tcbf-modal__window-row">' +
			'<label class="tcbf-transport-modal__label">' + escHtml(i18n.windowLabel) + '</label>' +
			'<div class="tcbf-transport-modal__window-buttons" data-direction="delivery">' +
			'<button type="button" class="tcbf-transport-modal__window-btn' + (deliveryWindow !== 'afternoon' ? ' tcbf-transport-modal__window-btn--active' : '') + '" data-window="morning">' + escHtml(i18n.windowMorning) + '</button>' +
			'<button type="button" class="tcbf-transport-modal__window-btn' + (deliveryWindow === 'afternoon' ? ' tcbf-transport-modal__window-btn--active' : '') + '" data-window="afternoon">' + escHtml(i18n.windowAfternoon) + '</button>' +
			'</div>' +
			'</div>' +
			'</div>' +
			'</div>' +

			// === Same address toggle ===
			'<div class="tcbf-modal__same-address">' +
			'<label class="tcbf-modal__same-address-label">' +
			'<input type="checkbox" id="tcbf-same-address" ' + (sameAddr ? 'checked' : '') + ' />' +
			'<span>' + escHtml(i18n.sameAddressLabel) + '</span>' +
			'</label>' +
			'</div>' +

			// === Pickup section ===
			'<div class="tcbf-modal__section" id="tcbf-pickup-section">' +
			'<div class="tcbf-modal__section-header">' +
			'<label class="tcbf-modal__section-toggle">' +
			'<input type="checkbox" id="tcbf-enable-pickup" ' + (hasPickup || !hasDelivery ? 'checked' : '') + ' />' +
			'<span>' + escHtml(i18n.pickupSection) + '</span>' +
			'</label>' +
			'</div>' +
			'<div class="tcbf-modal__section-body" id="tcbf-pickup-body"' + (sameAddr ? ' style="display:none"' : '') + '>' +
			'<label class="tcbf-transport-modal__label" for="tcbf-pickup-address-input">' + escHtml(i18n.pickupAddressLabel) + '</label>' +
			'<input type="text" id="tcbf-pickup-address-input" class="tcbf-transport-modal__input" placeholder="Hotel, address..." autocomplete="off" />' +
			'<div class="tcbf-modal__window-row">' +
			'<label class="tcbf-transport-modal__label">' + escHtml(i18n.windowLabel) + '</label>' +
			'<div class="tcbf-transport-modal__window-buttons" data-direction="pickup">' +
			'<button type="button" class="tcbf-transport-modal__window-btn' + (pickupWindow !== 'afternoon' ? ' tcbf-transport-modal__window-btn--active' : '') + '" data-window="morning">' + escHtml(i18n.windowMorning) + '</button>' +
			'<button type="button" class="tcbf-transport-modal__window-btn' + (pickupWindow === 'afternoon' ? ' tcbf-transport-modal__window-btn--active' : '') + '" data-window="afternoon">' + escHtml(i18n.windowAfternoon) + '</button>' +
			'</div>' +
			'</div>' +
			'</div>' +
			'</div>' +

			// Pickup window when same address
			'<div class="tcbf-modal__pickup-window-only" id="tcbf-pickup-window-only"' + (!sameAddr ? ' style="display:none"' : '') + '>' +
			'<label class="tcbf-transport-modal__label">' + escHtml(i18n.pickupSection) + ' — ' + escHtml(i18n.windowLabel) + '</label>' +
			'<div class="tcbf-transport-modal__window-buttons" data-direction="pickup-only">' +
			'<button type="button" class="tcbf-transport-modal__window-btn' + (pickupWindow !== 'afternoon' ? ' tcbf-transport-modal__window-btn--active' : '') + '" data-window="morning">' + escHtml(i18n.windowMorning) + '</button>' +
			'<button type="button" class="tcbf-transport-modal__window-btn' + (pickupWindow === 'afternoon' ? ' tcbf-transport-modal__window-btn--active' : '') + '" data-window="afternoon">' + escHtml(i18n.windowAfternoon) + '</button>' +
			'</div>' +
			'</div>' +

			// === Quote preview ===
			'<div class="tcbf-transport-modal__quote" id="tcbf-quote" style="display:none">' +
			'<div class="tcbf-transport-modal__quote-row">' +
			'<span class="tcbf-transport-modal__quote-label">' + escHtml(i18n.quoteLabel) + ':</span>' +
			'<span class="tcbf-transport-modal__quote-value" id="tcbf-quote-price"></span>' +
			'</div>' +
			'<div class="tcbf-transport-modal__quote-row" id="tcbf-quote-zone-row">' +
			'<span class="tcbf-transport-modal__quote-label">' + escHtml(i18n.zoneLabel) + ':</span>' +
			'<span class="tcbf-transport-modal__quote-value" id="tcbf-quote-zone"></span>' +
			'</div>' +
			'</div>' +

			// === Bike checklist ===
			'<div class="tcbf-modal__bikes">' +
			'<label class="tcbf-transport-modal__label">' + escHtml(i18n.bikesLabel) + '</label>' +
			'<div class="tcbf-modal__bike-list">' +
			bikeChecklistHtml +
			'</div>' +
			'</div>' +

			'</div>' + // controls
			'</div>' + // body

			// Footer
			'<div class="tcbf-transport-modal__footer">' +
			'<button type="button" class="button tcbf-transport-modal__cancel">' + escHtml(i18n.cancelBtn) + '</button>' +
			'<button type="button" class="button button-primary tcbf-transport-modal__confirm">' + escHtml(i18n.confirmBtn) + '</button>' +
			'</div>' +

			'</div>' + // modal
			'</div>';  // overlay

		$modal = $(modalHtml).appendTo('body');

		// Bind modal events
		$modal.find('.tcbf-transport-modal__close, .tcbf-transport-modal__cancel').on('click', function () {
			closeModal();
		});

		$modal.on('click', function (e) {
			if ($(e.target).hasClass('tcbf-transport-modal-overlay')) {
				closeModal();
			}
		});

		$(document).on('keydown.tcbfModal', function (e) {
			if (e.key === 'Escape') {
				closeModal();
			}
		});

		// Confirm button
		$modal.find('.tcbf-transport-modal__confirm').on('click', function () {
			doBulkConfigure();
		});

		// Window selector buttons
		$modal.on('click', '.tcbf-transport-modal__window-btn', function () {
			var $group = $(this).closest('.tcbf-transport-modal__window-buttons');
			$group.find('.tcbf-transport-modal__window-btn').removeClass('tcbf-transport-modal__window-btn--active');
			$(this).addClass('tcbf-transport-modal__window-btn--active');
			updateQuotePreview();
		});

		// Same address toggle
		$('#tcbf-same-address').on('change', function () {
			var same = $(this).is(':checked');
			if (same) {
				$('#tcbf-pickup-body').slideUp(200);
				$('#tcbf-pickup-map').hide();
				$('#tcbf-delivery-map').show();
				$('#tcbf-pickup-window-only').slideDown(200);
			} else {
				$('#tcbf-pickup-body').slideDown(200);
				$('#tcbf-pickup-window-only').slideUp(200);
				// Show pickup map if pickup is enabled
				if ($('#tcbf-enable-pickup').is(':checked')) {
					initPickupMap();
				}
			}
			updateQuotePreview();
		});

		// Enable/disable delivery
		$('#tcbf-enable-delivery').on('change', function () {
			var enabled = $(this).is(':checked');
			$('#tcbf-delivery-body').toggle(enabled);
			if (enabled) {
				$('#tcbf-delivery-map').show();
			}
			updateConfirmState();
			updateQuotePreview();
		});

		// Enable/disable pickup
		$('#tcbf-enable-pickup').on('change', function () {
			var enabled = $(this).is(':checked');
			var sameAddr = $('#tcbf-same-address').is(':checked');
			if (sameAddr) {
				$('#tcbf-pickup-window-only').toggle(enabled);
			} else {
				$('#tcbf-pickup-body').toggle(enabled);
			}
			updateConfirmState();
			updateQuotePreview();
		});

		// Pre-fill existing addresses
		if (deliveryPlace && deliveryPlace.address) {
			$('#tcbf-delivery-address-input').val(deliveryPlace.address);
		}
		if (pickupPlace && pickupPlace.address) {
			$('#tcbf-pickup-address-input').val(pickupPlace.address);
		}

		// Initialize maps and autocomplete
		initDeliveryMap();
		if (!sameAddr && (hasPickup || pickupPlace)) {
			initPickupMap();
		}

		updateConfirmState();
		updateQuotePreview();

		$('body').addClass('tcbf-modal-open');
	}

	function closeModal() {
		if ($modal) {
			$modal.remove();
			$modal = null;
		}
		deliveryMap = null;
		deliveryMarker = null;
		deliveryAutocomplete = null;
		pickupMap = null;
		pickupMarker = null;
		pickupAutocomplete = null;
		$(document).off('keydown.tcbfModal');
		$('body').removeClass('tcbf-modal-open');
	}

	/* ================================================================
	 * Map & Autocomplete initialization
	 * ================================================================ */

	function initDeliveryMap() {
		var container = document.getElementById('tcbf-delivery-map');
		var input = document.getElementById('tcbf-delivery-address-input');

		var lat = (deliveryPlace && deliveryPlace.lat) ? deliveryPlace.lat : 41.98;
		var lng = (deliveryPlace && deliveryPlace.lng) ? deliveryPlace.lng : 2.82;
		var zoom = (deliveryPlace && deliveryPlace.lat) ? 14 : 9;

		initGoogleMap(container, lat, lng, zoom, 'delivery', function (m, mk) {
			deliveryMap = m;
			deliveryMarker = mk;
		});

		initAutocompleteProvider(input, 'delivery');
	}

	function initPickupMap() {
		var container = document.getElementById('tcbf-pickup-map');
		var input = document.getElementById('tcbf-pickup-address-input');

		if (!container) return;
		container.style.display = 'block';

		var lat = (pickupPlace && pickupPlace.lat) ? pickupPlace.lat : 41.98;
		var lng = (pickupPlace && pickupPlace.lng) ? pickupPlace.lng : 2.82;
		var zoom = (pickupPlace && pickupPlace.lat) ? 14 : 9;

		initGoogleMap(container, lat, lng, zoom, 'pickup', function (m, mk) {
			pickupMap = m;
			pickupMarker = mk;
		});

		initAutocompleteProvider(input, 'pickup');
	}

	function initGoogleMap(container, lat, lng, zoom, direction, callback) {
		if (!container) return;

		var attempts = 0;
		function tryInit() {
			if (typeof google !== 'undefined' && google.maps) {
				container.style.display = 'block';

				var m = new google.maps.Map(container, {
					center: { lat: lat, lng: lng },
					zoom: zoom,
					disableDefaultUI: true,
					zoomControl: true,
					mapTypeControl: false,
					streetViewControl: false
				});

				var mk = new google.maps.Marker({
					position: { lat: lat, lng: lng },
					map: m,
					draggable: true
				});

				m.addListener('click', function (e) {
					var pos = { lat: e.latLng.lat(), lng: e.latLng.lng() };
					mk.setPosition(pos);
					onMapPinMoved(pos, direction);
				});

				mk.addListener('dragend', function () {
					var pos = { lat: mk.getPosition().lat(), lng: mk.getPosition().lng() };
					onMapPinMoved(pos, direction);
				});

				if (callback) callback(m, mk);
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
		if (typeof google !== 'undefined' && google.maps && google.maps.Geocoder) {
			var geocoder = new google.maps.Geocoder();
			geocoder.geocode({ location: pos }, function (results, status) {
				var place;
				if (status === 'OK' && results[0]) {
					place = {
						address: results[0].formatted_address,
						lat: pos.lat,
						lng: pos.lng,
						place_id: results[0].place_id || ''
					};
				} else {
					place = {
						address: pos.lat.toFixed(6) + ', ' + pos.lng.toFixed(6),
						lat: pos.lat,
						lng: pos.lng,
						place_id: ''
					};
				}

				setPlaceForDirection(direction, place);
				var inputId = (direction === 'pickup') ? 'tcbf-pickup-address-input' : 'tcbf-delivery-address-input';
				$('#' + inputId).val(place.address);
				updateConfirmState();
				updateQuotePreview();
			});
		} else {
			var place = {
				address: pos.lat.toFixed(6) + ', ' + pos.lng.toFixed(6),
				lat: pos.lat,
				lng: pos.lng,
				place_id: ''
			};
			setPlaceForDirection(direction, place);
			var inputId = (direction === 'pickup') ? 'tcbf-pickup-address-input' : 'tcbf-delivery-address-input';
			$('#' + inputId).val(place.address);
			updateConfirmState();
			updateQuotePreview();
		}
	}

	function initAutocompleteProvider(input, direction) {
		if (!input) return;

		if (config.hasMapsKey && typeof google !== 'undefined' && google.maps && google.maps.places) {
			try {
				var ac = new google.maps.places.Autocomplete(input, {
					fields: ['formatted_address', 'geometry', 'place_id', 'name'],
					componentRestrictions: { country: 'es' }
				});

				ac.addListener('place_changed', function () {
					var gPlace = ac.getPlace();
					if (!gPlace.geometry || !gPlace.geometry.location) {
						setPlaceForDirection(direction, null);
						updateConfirmState();
						return;
					}

					var place = {
						address: gPlace.formatted_address || input.value,
						lat: gPlace.geometry.location.lat(),
						lng: gPlace.geometry.location.lng(),
						place_id: gPlace.place_id || ''
					};

					setPlaceForDirection(direction, place);

					// Update map
					var m = (direction === 'pickup') ? pickupMap : deliveryMap;
					var mk = (direction === 'pickup') ? pickupMarker : deliveryMarker;
					if (m && mk) {
						var pos = { lat: place.lat, lng: place.lng };
						m.setCenter(pos);
						m.setZoom(14);
						mk.setPosition(pos);
					}

					updateConfirmState();
					updateQuotePreview();
				});

				if (direction === 'delivery') {
					deliveryAutocomplete = ac;
				} else {
					pickupAutocomplete = ac;
				}
			} catch (e) {
				bindManualInput(input, direction);
			}
		} else {
			bindManualInput(input, direction);
		}
	}

	function bindManualInput(inputEl, direction) {
		$(inputEl).on('input', function () {
			var val = $(this).val().trim();
			if (val.length > 5) {
				setPlaceForDirection(direction, { address: val, lat: 0, lng: 0, place_id: '' });
			} else {
				setPlaceForDirection(direction, null);
			}
			updateConfirmState();
		});
	}

	function setPlaceForDirection(direction, place) {
		if (direction === 'pickup') {
			pickupPlace = place;
		} else {
			deliveryPlace = place;
		}
	}

	/* ================================================================
	 * Quote preview
	 * ================================================================ */

	function updateQuotePreview() {
		if (!$modal) return;

		var enableDelivery = $('#tcbf-enable-delivery').is(':checked');
		var enablePickup = $('#tcbf-enable-pickup').is(':checked');

		if (!enableDelivery && !enablePickup) {
			$('#tcbf-quote').hide();
			return;
		}

		// Get the number of selected bikes
		var bikeCount = getSelectedBikeCount();
		if (bikeCount <= 0) {
			$('#tcbf-quote').hide();
			return;
		}

		// Fetch delivery quote
		if (enableDelivery && deliveryPlace && deliveryPlace.lat && deliveryPlace.lng) {
			var deliveryWindow = getWindowForDirection('delivery');
			fetchCombinedQuote(enableDelivery, enablePickup, bikeCount, deliveryWindow);
		} else if (enablePickup && !enableDelivery) {
			var sameAddr = $('#tcbf-same-address').is(':checked');
			var pPlace = sameAddr ? deliveryPlace : pickupPlace;
			if (pPlace && pPlace.lat && pPlace.lng) {
				var pickupWindow = getWindowForDirection('pickup');
				fetchCombinedQuote(false, true, bikeCount, pickupWindow);
			} else {
				$('#tcbf-quote').hide();
			}
		} else {
			$('#tcbf-quote').hide();
		}
	}

	function fetchCombinedQuote(enableDelivery, enablePickup, bikeCount, window) {
		if (quoteDebounce) {
			clearTimeout(quoteDebounce);
		}

		quoteDebounce = setTimeout(function () {
			var $quote = $('#tcbf-quote');
			var $price = $('#tcbf-quote-price');
			var $zone = $('#tcbf-quote-zone');

			$quote.show();
			$price.text(i18n.loading);
			$zone.text('');

			var totalPrice = 0;
			var zoneNames = [];
			var requests = [];

			if (enableDelivery && deliveryPlace && deliveryPlace.lat) {
				var dWin = getWindowForDirection('delivery');
				requests.push(
					$.post(config.ajaxUrl, {
						action: 'tcbf_transport_quote',
						lat: deliveryPlace.lat,
						lng: deliveryPlace.lng,
						direction: 'delivery',
						window: dWin,
						nonce: config.nonce
					})
				);
			}

			if (enablePickup) {
				var sameAddr = $('#tcbf-same-address').is(':checked');
				var pPlace = sameAddr ? deliveryPlace : pickupPlace;
				if (pPlace && pPlace.lat) {
					var pWin = getWindowForDirection('pickup');
					requests.push(
						$.post(config.ajaxUrl, {
							action: 'tcbf_transport_quote',
							lat: pPlace.lat,
							lng: pPlace.lng,
							direction: 'pickup',
							window: pWin,
							nonce: config.nonce
						})
					);
				}
			}

			if (requests.length === 0) {
				$quote.hide();
				return;
			}

			$.when.apply($, requests).then(function () {
				// Handle single vs multiple responses
				var responses = requests.length === 1 ? [arguments] : Array.prototype.slice.call(arguments);

				for (var i = 0; i < responses.length; i++) {
					var resp = responses[i];
					var data = resp[0]; // response data
					if (data && data.success && data.data) {
						var q = data.data.quote || {};
						totalPrice += parseFloat(q.price_total || 0);
						if (data.data.zone_name && zoneNames.indexOf(data.data.zone_name) === -1) {
							zoneNames.push(data.data.zone_name);
						}
					}
				}

				if (totalPrice > 0) {
					var priceText = formatPrice(totalPrice);
					var perBike = totalPrice / bikeCount;
					if (bikeCount > 1) {
						priceText += ' (' + formatPrice(perBike) + ' ' + i18n.perBikeLabel + ')';
					}
					$price.text(priceText);
				} else {
					$price.text('--');
				}

				$zone.text(zoneNames.length > 0 ? zoneNames.join(', ') : (i18n.outsideZones || ''));

			}).fail(function () {
				$price.text('--');
			});
		}, 300);
	}

	/* ================================================================
	 * Bulk configure (confirm)
	 * ================================================================ */

	function doBulkConfigure() {
		if (!$modal) return;

		var enableDelivery = $('#tcbf-enable-delivery').is(':checked');
		var enablePickup = $('#tcbf-enable-pickup').is(':checked');
		var sameAddress = $('#tcbf-same-address').is(':checked');

		// Validate: at least one direction
		if (!enableDelivery && !enablePickup) {
			closeModal();
			return;
		}

		// Validate delivery address
		if (enableDelivery && (!deliveryPlace || (!deliveryPlace.lat && !deliveryPlace.lng))) {
			var typedDelivery = $('#tcbf-delivery-address-input').val().trim();
			if (typedDelivery.length > 5) {
				geocodeAndConfigure(typedDelivery, 'delivery');
				return;
			}
			showError(i18n.errorGeneric);
			return;
		}

		// Validate pickup address (if different)
		if (enablePickup && !sameAddress && (!pickupPlace || (!pickupPlace.lat && !pickupPlace.lng))) {
			var typedPickup = $('#tcbf-pickup-address-input').val().trim();
			if (typedPickup.length > 5) {
				geocodeAndConfigure(typedPickup, 'pickup');
				return;
			}
			showError(i18n.errorGeneric);
			return;
		}

		sendBulkConfigure();
	}

	function geocodeAndConfigure(address, forDirection) {
		var $confirmBtn = $modal.find('.tcbf-transport-modal__confirm');
		$confirmBtn.prop('disabled', true).text(i18n.geocoding || i18n.loading);

		$.post(config.ajaxUrl, {
			action: 'tcbf_transport_geocode',
			address: address,
			nonce: config.nonce
		}, function (response) {
			if (!response.success || !response.data) {
				showError(i18n.geocodeFailed || i18n.errorGeneric);
				$confirmBtn.prop('disabled', false).text(i18n.confirmBtn);
				return;
			}

			var data = response.data;
			var place = {
				address: data.formatted_address || address,
				lat: data.lat,
				lng: data.lng,
				place_id: data.place_id || ''
			};

			setPlaceForDirection(forDirection, place);
			var inputId = (forDirection === 'pickup') ? 'tcbf-pickup-address-input' : 'tcbf-delivery-address-input';
			$('#' + inputId).val(place.address);

			// Try again
			sendBulkConfigure();
		}).fail(function () {
			showError(i18n.geocodeFailed || i18n.errorGeneric);
			$confirmBtn.prop('disabled', false).text(i18n.confirmBtn);
		});
	}

	function sendBulkConfigure() {
		var $confirmBtn = $modal.find('.tcbf-transport-modal__confirm');
		$confirmBtn.prop('disabled', true).text(i18n.saving || i18n.loading);

		var enableDelivery = $('#tcbf-enable-delivery').is(':checked');
		var enablePickup = $('#tcbf-enable-pickup').is(':checked');
		var sameAddress = $('#tcbf-same-address').is(':checked');

		var bikeKeys = getSelectedBikeKeys();

		var postData = {
			action: 'tcbf_transport_bulk_configure',
			enable_delivery: enableDelivery ? 1 : 0,
			enable_pickup: enablePickup ? 1 : 0,
			same_address: sameAddress ? 1 : 0,
			bike_keys: JSON.stringify(bikeKeys),
			nonce: config.nonce
		};

		if (enableDelivery && deliveryPlace) {
			postData.delivery_address = deliveryPlace.address;
			postData.delivery_lat = deliveryPlace.lat;
			postData.delivery_lng = deliveryPlace.lng;
			postData.delivery_place_id = deliveryPlace.place_id || '';
			postData.delivery_window = getWindowForDirection('delivery');
		}

		if (enablePickup) {
			if (sameAddress && deliveryPlace) {
				// Pickup uses delivery address; just send window
				postData.pickup_window = getWindowForDirection('pickup');
			} else if (pickupPlace) {
				postData.pickup_address = pickupPlace.address;
				postData.pickup_lat = pickupPlace.lat;
				postData.pickup_lng = pickupPlace.lng;
				postData.pickup_place_id = pickupPlace.place_id || '';
				postData.pickup_window = getWindowForDirection('pickup');
			}
		}

		$.post(config.ajaxUrl, postData, function (response) {
			if (!response.success) {
				showError(response.data ? response.data.message : i18n.errorGeneric);
				$confirmBtn.prop('disabled', false).text(i18n.confirmBtn);
				return;
			}

			// Update config summary
			if (response.data && response.data.summary) {
				config.summary = response.data.summary;
			}

			closeModal();

			if (response.data && response.data.fragments) {
				applyFragments(response.data.fragments);
			}
			$(document.body).trigger('wc_update_cart');

		}).fail(function () {
			showError(i18n.errorGeneric);
			$confirmBtn.prop('disabled', false).text(i18n.confirmBtn);
		});
	}

	/* ================================================================
	 * UI helpers
	 * ================================================================ */

	function getWindowForDirection(direction) {
		if (!$modal) return 'morning';

		if (direction === 'pickup') {
			var sameAddr = $('#tcbf-same-address').is(':checked');
			var selector = sameAddr ? '[data-direction="pickup-only"]' : '[data-direction="pickup"]';
			var $active = $modal.find(selector + ' .tcbf-transport-modal__window-btn--active');
			return $active.length ? $active.data('window') : 'morning';
		}

		var $active = $modal.find('[data-direction="delivery"] .tcbf-transport-modal__window-btn--active');
		return $active.length ? $active.data('window') : 'morning';
	}

	function getSelectedBikeKeys() {
		var keys = [];
		if (!$modal) return keys;
		$modal.find('.tcbf-modal__bike-check:checked').each(function () {
			keys.push($(this).data('bike-key'));
		});
		return keys;
	}

	function getSelectedBikeCount() {
		if (!$modal) return 0;
		return $modal.find('.tcbf-modal__bike-check:checked').length;
	}

	function updateConfirmState() {
		if (!$modal) return;

		var enableDelivery = $('#tcbf-enable-delivery').is(':checked');
		var enablePickup = $('#tcbf-enable-pickup').is(':checked');
		var sameAddress = $('#tcbf-same-address').is(':checked');

		var valid = true;

		if (!enableDelivery && !enablePickup) {
			valid = true; // Will just remove transport
		} else {
			if (enableDelivery) {
				var dVal = $('#tcbf-delivery-address-input').val().trim();
				if (dVal.length < 3 && (!deliveryPlace || !deliveryPlace.lat)) {
					valid = false;
				}
			}
			if (enablePickup && !sameAddress) {
				var pVal = $('#tcbf-pickup-address-input').val().trim();
				if (pVal.length < 3 && (!pickupPlace || !pickupPlace.lat)) {
					valid = false;
				}
			}
		}

		var bikeCount = getSelectedBikeCount();
		if (bikeCount <= 0 && (enableDelivery || enablePickup)) {
			valid = false;
		}

		$modal.find('.tcbf-transport-modal__confirm').prop('disabled', !valid);
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
		if ($modal) {
			$modal.find('.tcbf-transport-modal__footer').before($notice);
		} else {
			$('.woocommerce-cart-form').before($notice);
		}
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
