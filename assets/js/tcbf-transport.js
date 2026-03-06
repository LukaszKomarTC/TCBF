/**
 * TCBF Transport v3.1 — Order-level service card + unified/split modal
 *
 * Architecture:
 * - Single service card below cart table
 * - Combined modal with two layout modes:
 *   A) Unified (same address): one map, one address, two window selectors
 *   B) Split (different address): stacked delivery + pickup sections, each with own map/address/window
 * - Bike checklist with model, size, dates (always below transport sections)
 * - Global quote/availability block at bottom
 * - Bulk configure AJAX: one request creates/removes all transport items
 * - Date uniformity gate: modal blocked when bikes have mixed dates
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
		}, function () {
			$(document.body).trigger('wc_update_cart');
		}).fail(function () {
			$card.removeClass('tcbf-service-card--loading');
			showError(i18n.errorGeneric);
		});
	}

	/* ================================================================
	 * Configure modal
	 * ================================================================ */

	function openConfigureModal() {
		if ($modal) {
			$modal.remove();
		}

		// Date gate (defensive — card should already hide the button)
		if (!config.datesUniform) {
			return;
		}

		var summary = config.summary || {};
		var bikes = config.bikes || [];

		// Restore previous settings
		var hasDelivery = (summary.delivery_count || 0) > 0;
		var hasPickup = (summary.pickup_count || 0) > 0;
		var sameAddr = summary.link_return ? true : (!hasPickup || !hasDelivery);
		var deliveryWindow = summary.delivery_window || 'morning';
		var pickupWindow = summary.pickup_window || 'morning';

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

		// Build bike checklist HTML (enriched)
		var bikeChecklistHtml = '';
		for (var i = 0; i < bikes.length; i++) {
			var bike = bikes[i];
			var primaryLine = bike.model || '';
			if (bike.size) {
				primaryLine += ' — ' + (i18n.sizeLabel || 'size') + ' ' + bike.size;
			}
			var secondaryLine = '';
			if (bike.start_date && bike.end_date) {
				secondaryLine = formatDateDisplay(bike.start_date) + ' → ' + formatDateDisplay(bike.end_date);
			}
			if (bike.rider) {
				secondaryLine = secondaryLine ? (bike.rider + ' · ' + secondaryLine) : bike.rider;
			}

			bikeChecklistHtml +=
				'<label class="tcbf-modal__bike-item">' +
				'<input type="checkbox" class="tcbf-modal__bike-check" data-bike-key="' + escAttr(bike.key) + '" checked />' +
				'<div class="tcbf-modal__bike-info">' +
				'<span class="tcbf-modal__bike-model">' + escHtml(primaryLine) + '</span>' +
				(secondaryLine ? '<span class="tcbf-modal__bike-dates">' + escHtml(secondaryLine) + '</span>' : '') +
				'</div>' +
				'</label>';
		}

		// Build modal HTML
		var modalHtml =
			'<div class="tcbf-transport-modal-overlay">' +
			'<div class="tcbf-transport-modal" id="tcbf-modal">' +

			// Header
			'<div class="tcbf-transport-modal__header">' +
			'<h3>' + escHtml(i18n.modalTitle) + '</h3>' +
			'<button type="button" class="tcbf-transport-modal__close">&times;</button>' +
			'</div>' +

			'<div class="tcbf-transport-modal__body">' +

			// === UNIFIED MODE (same address) ===
			'<div class="tcbf-modal__unified" id="tcbf-unified-mode"' + (!sameAddr ? ' style="display:none"' : '') + '>' +
			'<div class="tcbf-modal__unified-layout">' +
			// Map
			'<div class="tcbf-modal__unified-map">' +
			'<div class="tcbf-transport-modal__map" id="tcbf-delivery-map"></div>' +
			'</div>' +
			// Controls
			'<div class="tcbf-modal__unified-controls">' +
			'<label class="tcbf-transport-modal__label" for="tcbf-delivery-address-input">' + escHtml(i18n.addressLabel) + '</label>' +
			'<input type="text" id="tcbf-delivery-address-input" class="tcbf-transport-modal__input" placeholder="Hotel, address..." autocomplete="off" />' +

			// Delivery window
			'<div class="tcbf-modal__window-row">' +
			'<label class="tcbf-transport-modal__label">' + escHtml(i18n.deliverySection) + ' — ' + escHtml(i18n.windowLabel) + '</label>' +
			'<div class="tcbf-transport-modal__window-buttons" data-direction="delivery">' +
			'<button type="button" class="tcbf-transport-modal__window-btn' + (deliveryWindow !== 'afternoon' ? ' tcbf-transport-modal__window-btn--active' : '') + '" data-window="morning">' + escHtml(i18n.windowMorning) + '</button>' +
			'<button type="button" class="tcbf-transport-modal__window-btn' + (deliveryWindow === 'afternoon' ? ' tcbf-transport-modal__window-btn--active' : '') + '" data-window="afternoon">' + escHtml(i18n.windowAfternoon) + '</button>' +
			'</div>' +
			'</div>' +

			// Pickup window
			'<div class="tcbf-modal__window-row">' +
			'<label class="tcbf-transport-modal__label">' + escHtml(i18n.pickupSection) + ' — ' + escHtml(i18n.windowLabel) + '</label>' +
			'<div class="tcbf-transport-modal__window-buttons" data-direction="pickup">' +
			'<button type="button" class="tcbf-transport-modal__window-btn' + (pickupWindow !== 'afternoon' ? ' tcbf-transport-modal__window-btn--active' : '') + '" data-window="morning">' + escHtml(i18n.windowMorning) + '</button>' +
			'<button type="button" class="tcbf-transport-modal__window-btn' + (pickupWindow === 'afternoon' ? ' tcbf-transport-modal__window-btn--active' : '') + '" data-window="afternoon">' + escHtml(i18n.windowAfternoon) + '</button>' +
			'</div>' +
			'</div>' +

			'</div>' + // unified-controls
			'</div>' + // unified-layout
			'</div>' + // unified mode

			// === SPLIT MODE (different address) ===
			'<div class="tcbf-modal__split" id="tcbf-split-mode"' + (sameAddr ? ' style="display:none"' : '') + '>' +

			// Delivery section
			'<div class="tcbf-modal__direction-section">' +
			'<h4 class="tcbf-modal__direction-title">' + escHtml(i18n.deliverySectionFull || i18n.deliverySection) + '</h4>' +
			'<div class="tcbf-modal__direction-layout">' +
			'<div class="tcbf-modal__direction-map">' +
			'<div class="tcbf-transport-modal__map" id="tcbf-split-delivery-map"></div>' +
			'</div>' +
			'<div class="tcbf-modal__direction-controls">' +
			'<label class="tcbf-transport-modal__label" for="tcbf-split-delivery-address">' + escHtml(i18n.addressLabel) + '</label>' +
			'<input type="text" id="tcbf-split-delivery-address" class="tcbf-transport-modal__input" placeholder="Hotel, address..." autocomplete="off" />' +
			'<div class="tcbf-modal__window-row">' +
			'<label class="tcbf-transport-modal__label">' + escHtml(i18n.windowLabel) + '</label>' +
			'<div class="tcbf-transport-modal__window-buttons" data-direction="delivery">' +
			'<button type="button" class="tcbf-transport-modal__window-btn' + (deliveryWindow !== 'afternoon' ? ' tcbf-transport-modal__window-btn--active' : '') + '" data-window="morning">' + escHtml(i18n.windowMorning) + '</button>' +
			'<button type="button" class="tcbf-transport-modal__window-btn' + (deliveryWindow === 'afternoon' ? ' tcbf-transport-modal__window-btn--active' : '') + '" data-window="afternoon">' + escHtml(i18n.windowAfternoon) + '</button>' +
			'</div>' +
			'</div>' +
			'</div>' + // direction-controls
			'</div>' + // direction-layout
			'</div>' + // delivery section

			// Separator
			'<div class="tcbf-modal__separator">' +
			'<span>' + escHtml(i18n.differentPickupSeparator || i18n.pickupSection) + '</span>' +
			'</div>' +

			// Pickup section
			'<div class="tcbf-modal__direction-section">' +
			'<h4 class="tcbf-modal__direction-title">' + escHtml(i18n.pickupSectionFull || i18n.pickupSection) + '</h4>' +
			'<div class="tcbf-modal__direction-layout">' +
			'<div class="tcbf-modal__direction-map">' +
			'<div class="tcbf-transport-modal__map" id="tcbf-split-pickup-map"></div>' +
			'</div>' +
			'<div class="tcbf-modal__direction-controls">' +
			'<label class="tcbf-transport-modal__label" for="tcbf-split-pickup-address">' + escHtml(i18n.pickupAddressLabel) + '</label>' +
			'<input type="text" id="tcbf-split-pickup-address" class="tcbf-transport-modal__input" placeholder="Hotel, address..." autocomplete="off" />' +
			'<div class="tcbf-modal__window-row">' +
			'<label class="tcbf-transport-modal__label">' + escHtml(i18n.windowLabel) + '</label>' +
			'<div class="tcbf-transport-modal__window-buttons" data-direction="pickup">' +
			'<button type="button" class="tcbf-transport-modal__window-btn' + (pickupWindow !== 'afternoon' ? ' tcbf-transport-modal__window-btn--active' : '') + '" data-window="morning">' + escHtml(i18n.windowMorning) + '</button>' +
			'<button type="button" class="tcbf-transport-modal__window-btn' + (pickupWindow === 'afternoon' ? ' tcbf-transport-modal__window-btn--active' : '') + '" data-window="afternoon">' + escHtml(i18n.windowAfternoon) + '</button>' +
			'</div>' +
			'</div>' +
			'</div>' + // direction-controls
			'</div>' + // direction-layout
			'</div>' + // pickup section

			'</div>' + // split mode

			// === Same address toggle (between modes) ===
			'<div class="tcbf-modal__same-address">' +
			'<label class="tcbf-modal__same-address-label">' +
			'<input type="checkbox" id="tcbf-same-address" ' + (sameAddr ? 'checked' : '') + ' />' +
			'<span>' + escHtml(i18n.sameAddressLabel) + '</span>' +
			'</label>' +
			'</div>' +

			// === Bike checklist (always below transport sections) ===
			'<div class="tcbf-modal__bikes">' +
			'<label class="tcbf-transport-modal__label">' + escHtml(i18n.bikesIncludedLabel || i18n.bikesLabel) + '</label>' +
			'<div class="tcbf-modal__bike-list">' +
			bikeChecklistHtml +
			'</div>' +
			'</div>' +

			// === Quote preview (global, at bottom) ===
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

			'</div>' + // body

			// Footer
			'<div class="tcbf-transport-modal__footer">' +
			'<button type="button" class="button tcbf-transport-modal__cancel">' + escHtml(i18n.cancelBtn) + '</button>' +
			'<button type="button" class="button button-primary tcbf-transport-modal__confirm">' + escHtml(i18n.confirmBtn) + '</button>' +
			'</div>' +

			'</div>' + // modal
			'</div>';  // overlay

		$modal = $(modalHtml).appendTo('body');

		// Bind events
		$modal.find('.tcbf-transport-modal__close, .tcbf-transport-modal__cancel').on('click', closeModal);
		$modal.on('click', function (e) {
			if ($(e.target).hasClass('tcbf-transport-modal-overlay')) closeModal();
		});
		$(document).on('keydown.tcbfModal', function (e) {
			if (e.key === 'Escape') closeModal();
		});

		// Confirm
		$modal.find('.tcbf-transport-modal__confirm').on('click', doBulkConfigure);

		// Window buttons
		$modal.on('click', '.tcbf-transport-modal__window-btn', function () {
			var $group = $(this).closest('.tcbf-transport-modal__window-buttons');
			$group.find('.tcbf-transport-modal__window-btn').removeClass('tcbf-transport-modal__window-btn--active');
			$(this).addClass('tcbf-transport-modal__window-btn--active');
			updateQuotePreview();
		});

		// Same address toggle → switch between unified and split
		$('#tcbf-same-address').on('change', function () {
			var same = $(this).is(':checked');
			if (same) {
				$('#tcbf-unified-mode').show();
				$('#tcbf-split-mode').hide();
				// Sync address from split delivery if it was entered there
				var splitAddr = $('#tcbf-split-delivery-address').val();
				if (splitAddr && !$('#tcbf-delivery-address-input').val()) {
					$('#tcbf-delivery-address-input').val(splitAddr);
				}
			} else {
				$('#tcbf-unified-mode').hide();
				$('#tcbf-split-mode').show();
				// Sync address to split fields
				var unifiedAddr = $('#tcbf-delivery-address-input').val();
				if (unifiedAddr && !$('#tcbf-split-delivery-address').val()) {
					$('#tcbf-split-delivery-address').val(unifiedAddr);
				}
				// Init split maps if needed
				initSplitMaps();
			}
			updateConfirmState();
			updateQuotePreview();
		});

		// Pre-fill addresses
		if (deliveryPlace && deliveryPlace.address) {
			$('#tcbf-delivery-address-input').val(deliveryPlace.address);
			$('#tcbf-split-delivery-address').val(deliveryPlace.address);
		}
		if (pickupPlace && pickupPlace.address) {
			$('#tcbf-split-pickup-address').val(pickupPlace.address);
		}

		// Init maps
		if (sameAddr) {
			initUnifiedMap();
		} else {
			initSplitMaps();
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
	 * Map initialization
	 * ================================================================ */

	function initUnifiedMap() {
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

	var splitMapsInitialized = false;
	function initSplitMaps() {
		if (splitMapsInitialized) return;
		splitMapsInitialized = true;

		// Delivery map in split mode
		var dContainer = document.getElementById('tcbf-split-delivery-map');
		var dInput = document.getElementById('tcbf-split-delivery-address');
		var dLat = (deliveryPlace && deliveryPlace.lat) ? deliveryPlace.lat : 41.98;
		var dLng = (deliveryPlace && deliveryPlace.lng) ? deliveryPlace.lng : 2.82;
		var dZoom = (deliveryPlace && deliveryPlace.lat) ? 14 : 9;

		initGoogleMap(dContainer, dLat, dLng, dZoom, 'delivery', function (m, mk) {
			// In split mode, delivery map uses separate elements but same deliveryPlace
			// We don't override deliveryMap/deliveryMarker since unified mode owns those
		});
		initAutocompleteProvider(dInput, 'delivery');

		// Pickup map
		var pContainer = document.getElementById('tcbf-split-pickup-map');
		var pInput = document.getElementById('tcbf-split-pickup-address');
		var pLat = (pickupPlace && pickupPlace.lat) ? pickupPlace.lat : 41.98;
		var pLng = (pickupPlace && pickupPlace.lng) ? pickupPlace.lng : 2.82;
		var pZoom = (pickupPlace && pickupPlace.lat) ? 14 : 9;

		initGoogleMap(pContainer, pLat, pLng, pZoom, 'pickup', function (m, mk) {
			pickupMap = m;
			pickupMarker = mk;
		});
		initAutocompleteProvider(pInput, 'pickup');
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
						lat: pos.lat, lng: pos.lng,
						place_id: results[0].place_id || ''
					};
				} else {
					place = {
						address: pos.lat.toFixed(6) + ', ' + pos.lng.toFixed(6),
						lat: pos.lat, lng: pos.lng, place_id: ''
					};
				}
				setPlaceForDirection(direction, place);
				getActiveInputForDirection(direction).val(place.address);
				updateConfirmState();
				updateQuotePreview();
			});
		} else {
			var place = {
				address: pos.lat.toFixed(6) + ', ' + pos.lng.toFixed(6),
				lat: pos.lat, lng: pos.lng, place_id: ''
			};
			setPlaceForDirection(direction, place);
			getActiveInputForDirection(direction).val(place.address);
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

					// Update associated map
					updateMapForDirection(direction, place);
					updateConfirmState();
					updateQuotePreview();
				});
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

	function getActiveInputForDirection(direction) {
		var isSame = isSameAddressMode();
		if (direction === 'delivery') {
			return isSame ? $('#tcbf-delivery-address-input') : $('#tcbf-split-delivery-address');
		}
		return $('#tcbf-split-pickup-address');
	}

	function updateMapForDirection(direction, place) {
		var m, mk;
		if (direction === 'delivery') {
			m = deliveryMap;
			mk = deliveryMarker;
		} else {
			m = pickupMap;
			mk = pickupMarker;
		}
		if (m && mk && place && place.lat && place.lng) {
			var pos = { lat: place.lat, lng: place.lng };
			m.setCenter(pos);
			m.setZoom(14);
			mk.setPosition(pos);
		}
	}

	function isSameAddressMode() {
		return $modal && $('#tcbf-same-address').is(':checked');
	}

	/* ================================================================
	 * Quote preview
	 * ================================================================ */

	function updateQuotePreview() {
		if (!$modal) return;

		var bikeCount = getSelectedBikeCount();
		if (bikeCount <= 0) {
			$('#tcbf-quote').hide();
			return;
		}

		var sameAddr = isSameAddressMode();
		var dPlace = deliveryPlace;
		var pPlace = sameAddr ? deliveryPlace : pickupPlace;
		var hasDAddr = dPlace && dPlace.lat && dPlace.lng;
		var hasPAddr = pPlace && pPlace.lat && pPlace.lng;

		if (!hasDAddr && !hasPAddr) {
			$('#tcbf-quote').hide();
			return;
		}

		fetchCombinedQuote(hasDAddr, hasPAddr, bikeCount);
	}

	function fetchCombinedQuote(hasDelivery, hasPickup, bikeCount) {
		if (quoteDebounce) clearTimeout(quoteDebounce);

		quoteDebounce = setTimeout(function () {
			var $quote = $('#tcbf-quote');
			var $price = $('#tcbf-quote-price');
			var $zone = $('#tcbf-quote-zone');

			$quote.show();
			$price.text(i18n.loading);
			$zone.text('');

			var sameAddr = isSameAddressMode();
			var requests = [];

			if (hasDelivery && deliveryPlace && deliveryPlace.lat) {
				var dWin = getWindowForDirection('delivery');
				requests.push($.post(config.ajaxUrl, {
					action: 'tcbf_transport_quote',
					lat: deliveryPlace.lat, lng: deliveryPlace.lng,
					direction: 'delivery', window: dWin,
					nonce: config.nonce
				}));
			}

			if (hasPickup) {
				var pPlace = sameAddr ? deliveryPlace : pickupPlace;
				if (pPlace && pPlace.lat) {
					var pWin = getWindowForDirection('pickup');
					requests.push($.post(config.ajaxUrl, {
						action: 'tcbf_transport_quote',
						lat: pPlace.lat, lng: pPlace.lng,
						direction: 'pickup', window: pWin,
						nonce: config.nonce
					}));
				}
			}

			if (requests.length === 0) { $quote.hide(); return; }

			$.when.apply($, requests).then(function () {
				var responses = requests.length === 1 ? [arguments] : Array.prototype.slice.call(arguments);
				var totalPrice = 0;
				var zoneNames = [];

				for (var i = 0; i < responses.length; i++) {
					var data = responses[i][0];
					if (data && data.success && data.data) {
						var q = data.data.quote || {};
						totalPrice += parseFloat(q.price_total || 0);
						if (data.data.zone_name && zoneNames.indexOf(data.data.zone_name) === -1) {
							zoneNames.push(data.data.zone_name);
						}
					}
				}

				if (totalPrice > 0) {
					var text = formatPrice(totalPrice);
					if (bikeCount > 1) {
						text += ' (' + formatPrice(totalPrice / bikeCount) + ' ' + i18n.perBikeLabel + ')';
					}
					$price.text(text);
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

		var sameAddress = isSameAddressMode();

		// Validate delivery address
		if (!deliveryPlace || (!deliveryPlace.lat && !deliveryPlace.lng)) {
			var typedDelivery = getActiveInputForDirection('delivery').val().trim();
			if (typedDelivery.length > 5) {
				geocodeAndConfigure(typedDelivery, 'delivery');
				return;
			}
			showError(i18n.errorGeneric);
			return;
		}

		// Validate pickup address (if different)
		if (!sameAddress && (!pickupPlace || (!pickupPlace.lat && !pickupPlace.lng))) {
			var typedPickup = $('#tcbf-split-pickup-address').val().trim();
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
				lat: data.lat, lng: data.lng,
				place_id: data.place_id || ''
			};
			setPlaceForDirection(forDirection, place);
			getActiveInputForDirection(forDirection).val(place.address);
			sendBulkConfigure();
		}).fail(function () {
			showError(i18n.geocodeFailed || i18n.errorGeneric);
			$confirmBtn.prop('disabled', false).text(i18n.confirmBtn);
		});
	}

	function sendBulkConfigure() {
		var $confirmBtn = $modal.find('.tcbf-transport-modal__confirm');
		$confirmBtn.prop('disabled', true).text(i18n.saving || i18n.loading);

		var sameAddress = isSameAddressMode();
		var bikeKeys = getSelectedBikeKeys();

		var postData = {
			action: 'tcbf_transport_bulk_configure',
			enable_delivery: 1,
			enable_pickup: 1,
			same_address: sameAddress ? 1 : 0,
			bike_keys: JSON.stringify(bikeKeys),
			nonce: config.nonce
		};

		// Delivery address
		if (deliveryPlace) {
			postData.delivery_address = deliveryPlace.address;
			postData.delivery_lat = deliveryPlace.lat;
			postData.delivery_lng = deliveryPlace.lng;
			postData.delivery_place_id = deliveryPlace.place_id || '';
			postData.delivery_window = getWindowForDirection('delivery');
		}

		// Pickup
		postData.pickup_window = getWindowForDirection('pickup');
		if (!sameAddress && pickupPlace) {
			postData.pickup_address = pickupPlace.address;
			postData.pickup_lat = pickupPlace.lat;
			postData.pickup_lng = pickupPlace.lng;
			postData.pickup_place_id = pickupPlace.place_id || '';
		}

		$.post(config.ajaxUrl, postData, function (response) {
			if (!response.success) {
				showError(response.data ? response.data.message : i18n.errorGeneric);
				$confirmBtn.prop('disabled', false).text(i18n.confirmBtn);
				return;
			}

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
		var $active = $modal.find('[data-direction="' + direction + '"] .tcbf-transport-modal__window-btn--active');
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

		var valid = true;
		var sameAddress = isSameAddressMode();

		// Need at least delivery address
		if (sameAddress) {
			var dVal = $('#tcbf-delivery-address-input').val().trim();
			if (dVal.length < 3 && (!deliveryPlace || !deliveryPlace.lat)) {
				valid = false;
			}
		} else {
			var dVal2 = $('#tcbf-split-delivery-address').val().trim();
			if (dVal2.length < 3 && (!deliveryPlace || !deliveryPlace.lat)) {
				valid = false;
			}
			var pVal = $('#tcbf-split-pickup-address').val().trim();
			if (pVal.length < 3 && (!pickupPlace || !pickupPlace.lat)) {
				valid = false;
			}
		}

		if (getSelectedBikeCount() <= 0) {
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
		if (typeof amount !== 'number') amount = parseFloat(amount) || 0;
		return amount.toFixed(2) + ' \u20AC';
	}

	function formatDateDisplay(dateStr) {
		// Convert YYYY-MM-DD to DD/MM/YYYY
		if (!dateStr || dateStr.length < 10) return dateStr || '';
		var parts = dateStr.split('-');
		return parts[2] + '/' + parts[1] + '/' + parts[0];
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
