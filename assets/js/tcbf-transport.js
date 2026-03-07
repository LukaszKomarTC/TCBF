/**
 * TCBF Transport v4.0 — Order-level mode selector + unified/split modal
 *
 * Architecture:
 * - Single service card below cart table (delegated event binding)
 * - Order-level mode selector: Delivery only / Pickup only / Both
 * - Combined modal with two layout modes:
 *   A) Unified (same address or single direction): one map, one address
 *   B) Split (Both + different address): stacked delivery + pickup sections
 * - "Same address for pickup" checkbox: right-side controls, only when mode=Both
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
	var pickupMap = null;
	var pickupMarker = null;
	var deliveryPlace = null;
	var pickupPlace = null;
	var quoteDebounce = null;
	var splitMapsInitialized = false;
	var unifiedMapInitialized = false;

	/* ================================================================
	 * Initialization — delegated events survive DOM replacement
	 * ================================================================ */

	$(document).on('click', '#tcbf-configure-transport', function (e) {
		e.preventDefault();
		openConfigureModal();
	});

	$(document).on('click', '#tcbf-remove-transport', function (e) {
		e.preventDefault();
		if (confirm(i18n.removeConfirm || 'Remove transport for all bikes?')) {
			removeAllTransport();
		}
	});

	// Refresh transport state after WooCommerce AJAX cart updates
	$(document.body).on('updated_cart_totals', function () {
		$.ajax({
			url: config.ajaxUrl, method: 'POST', dataType: 'json',
			data: {
				action: 'tcbf_transport_refresh_state',
				nonce: config.nonce
			}
		}).done(function (response) {
			if (response && response.success && response.data) {
				config.bikes = response.data.bikes || [];
				config.datesUniform = response.data.datesUniform;
				config.summary = response.data.summary || {};
			}
		});
	});

	function removeAllTransport() {
		var $card = $('#tcbf-service-card');
		$card.addClass('tcbf-service-card--loading');

		$.ajax({
			url: config.ajaxUrl, method: 'POST', dataType: 'json',
			data: {
				action: 'tcbf_transport_bulk_configure',
				enable_delivery: 0,
				enable_pickup: 0,
				bike_keys: JSON.stringify([]),
				nonce: config.nonce
			}
		}).done(function () {
			$(document.body).trigger('wc_update_cart');
		}).fail(function () {
			$card.removeClass('tcbf-service-card--loading');
			showError(i18n.errorGeneric);
		});
	}

	/* ================================================================
	 * Mode helpers
	 * ================================================================ */

	function getSelectedMode() {
		if (!$modal) return 'both';
		var val = $modal.find('input[name="tcbf_service_mode"]:checked').val();
		return val || 'both';
	}

	function modeIncludesDelivery(mode) {
		return mode === 'delivery' || mode === 'both';
	}

	function modeIncludesPickup(mode) {
		return mode === 'pickup' || mode === 'both';
	}

	function isSameAddressMode() {
		return $modal && $('#tcbf-same-address').is(':checked');
	}

	/* ================================================================
	 * Configure modal
	 * ================================================================ */

	function openConfigureModal() {
		if ($modal) {
			$modal.remove();
		}

		// Date gate
		if (!config.datesUniform) {
			return;
		}

		var summary = config.summary || {};
		var bikes = config.bikes || [];

		// Derive initial mode from existing config
		var hasDelivery = (summary.delivery_count || 0) > 0;
		var hasPickup = (summary.pickup_count || 0) > 0;
		var initialMode = 'both';
		if (hasDelivery && !hasPickup) initialMode = 'delivery';
		else if (!hasDelivery && hasPickup) initialMode = 'pickup';

		var sameAddr = summary.link_return ? true : (!hasPickup || !hasDelivery || initialMode !== 'both');
		var deliveryWindow = summary.delivery_window || 'morning';
		var pickupWindow = summary.pickup_window || 'morning';

		// Restore addresses
		deliveryPlace = null;
		pickupPlace = null;
		splitMapsInitialized = false;

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
			var primaryLine = bike.model || '';
			if (bike.size) {
				primaryLine += ' \u2014 ' + (i18n.sizeLabel || 'size') + ' ' + bike.size;
			}
			var secondaryLine = '';
			if (bike.start_date && bike.end_date) {
				secondaryLine = formatDateDisplay(bike.start_date) + ' \u2192 ' + formatDateDisplay(bike.end_date);
			}
			if (bike.rider) {
				secondaryLine = secondaryLine ? (bike.rider + ' \u00B7 ' + secondaryLine) : bike.rider;
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

		// Window buttons helper
		function windowBtns(direction, activeWindow) {
			return '<div class="tcbf-transport-modal__window-buttons" data-direction="' + direction + '">' +
				'<button type="button" class="tcbf-transport-modal__window-btn' + (activeWindow !== 'afternoon' ? ' tcbf-transport-modal__window-btn--active' : '') + '" data-window="morning">' + escHtml(i18n.windowMorning) + '</button>' +
				'<button type="button" class="tcbf-transport-modal__window-btn' + (activeWindow === 'afternoon' ? ' tcbf-transport-modal__window-btn--active' : '') + '" data-window="afternoon">' + escHtml(i18n.windowAfternoon) + '</button>' +
				'</div>';
		}

		// Mode selector helper
		function modeRadio(value, label, checked) {
			return '<label class="tcbf-modal__mode-option' + (checked ? ' tcbf-modal__mode-option--active' : '') + '">' +
				'<input type="radio" name="tcbf_service_mode" value="' + value + '"' + (checked ? ' checked' : '') + ' />' +
				'<span>' + escHtml(label) + '</span>' +
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

			// === MODE SELECTOR ===
			'<div class="tcbf-modal__mode-selector">' +
			'<label class="tcbf-transport-modal__label">' + escHtml(i18n.modeLabel || 'Transport service needed') + '</label>' +
			'<div class="tcbf-modal__mode-options">' +
			modeRadio('delivery', i18n.modeDeliveryOnly || 'Delivery only', initialMode === 'delivery') +
			modeRadio('pickup', i18n.modePickupOnly || 'Pickup only', initialMode === 'pickup') +
			modeRadio('both', i18n.modeBoth || 'Delivery + pickup', initialMode === 'both') +
			'</div>' +
			'</div>' +

			// === UNIFIED MODE (same address or single direction) ===
			'<div class="tcbf-modal__unified" id="tcbf-unified-mode">' +
			'<div class="tcbf-modal__unified-layout">' +
			// Map
			'<div class="tcbf-modal__unified-map">' +
			'<div class="tcbf-transport-modal__map" id="tcbf-delivery-map"></div>' +
			'</div>' +
			// Controls
			'<div class="tcbf-modal__unified-controls">' +
			'<label class="tcbf-transport-modal__label tcbf-modal__addr-label" for="tcbf-delivery-address-input">' + escHtml(i18n.addressLabel) + '</label>' +
			'<input type="text" id="tcbf-delivery-address-input" class="tcbf-transport-modal__input" placeholder="Hotel, address..." autocomplete="off" />' +

			// Same address checkbox (inside controls, only visible when mode=both)
			'<div class="tcbf-modal__same-address" id="tcbf-same-address-wrap">' +
			'<label class="tcbf-modal__same-address-label">' +
			'<input type="checkbox" id="tcbf-same-address" ' + (sameAddr ? 'checked' : '') + ' />' +
			'<span>' + escHtml(i18n.sameAddressLabel) + '</span>' +
			'</label>' +
			'</div>' +

			// Delivery window
			'<div class="tcbf-modal__window-row tcbf-modal__delivery-window">' +
			'<label class="tcbf-transport-modal__label">' + escHtml(i18n.deliverySection) + ' \u2014 ' + escHtml(i18n.windowLabel) + '</label>' +
			windowBtns('delivery', deliveryWindow) +
			'</div>' +

			// Pickup window (in unified mode, shown when mode=both and same address)
			'<div class="tcbf-modal__window-row tcbf-modal__pickup-window">' +
			'<label class="tcbf-transport-modal__label">' + escHtml(i18n.pickupSection) + ' \u2014 ' + escHtml(i18n.windowLabel) + '</label>' +
			windowBtns('pickup', pickupWindow) +
			'</div>' +

			'</div>' + // unified-controls
			'</div>' + // unified-layout
			'</div>' + // unified mode

			// === SPLIT MODE (Both + different address) ===
			'<div class="tcbf-modal__split" id="tcbf-split-mode" style="display:none">' +

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
			windowBtns('delivery', deliveryWindow) +
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

			// Same address toggle (inside pickup controls, above address input)
			'<div class="tcbf-modal__same-address tcbf-modal__same-address--split">' +
			'<label class="tcbf-modal__same-address-label">' +
			'<input type="checkbox" class="tcbf-same-address-mirror" />' +
			'<span>' + escHtml(i18n.sameAddressLabel) + '</span>' +
			'</label>' +
			'</div>' +

			'<label class="tcbf-transport-modal__label" for="tcbf-split-pickup-address">' + escHtml(i18n.pickupAddressLabel) + '</label>' +
			'<input type="text" id="tcbf-split-pickup-address" class="tcbf-transport-modal__input" placeholder="Hotel, address..." autocomplete="off" />' +
			'<div class="tcbf-modal__window-row">' +
			'<label class="tcbf-transport-modal__label">' + escHtml(i18n.windowLabel) + '</label>' +
			windowBtns('pickup', pickupWindow) +
			'</div>' +
			'</div>' + // direction-controls
			'</div>' + // direction-layout
			'</div>' + // pickup section

			'</div>' + // split mode

			// === Bike checklist (always below transport sections) ===
			'<div class="tcbf-modal__bikes">' +
			'<label class="tcbf-transport-modal__label">' + escHtml(i18n.bikesIncludedLabel || i18n.bikesLabel) + '</label>' +
			'<div class="tcbf-modal__bike-list">' +
			bikeChecklistHtml +
			'</div>' +
			'</div>' +

			// === Quote preview (global, at bottom) ===
			'<div class="tcbf-transport-modal__quote" id="tcbf-quote" style="display:none">' +

			// Per-direction rows (visible when mode=both and 2 quotes returned)
			'<div class="tcbf-transport-modal__quote-row" id="tcbf-quote-delivery-row" style="display:none">' +
			'<span class="tcbf-transport-modal__quote-label" id="tcbf-quote-delivery-label"></span>' +
			'<span class="tcbf-transport-modal__quote-value" id="tcbf-quote-delivery-price"></span>' +
			'</div>' +
			'<div class="tcbf-transport-modal__quote-row" id="tcbf-quote-pickup-row" style="display:none">' +
			'<span class="tcbf-transport-modal__quote-label" id="tcbf-quote-pickup-label"></span>' +
			'<span class="tcbf-transport-modal__quote-value" id="tcbf-quote-pickup-price"></span>' +
			'</div>' +
			'<div class="tcbf-transport-modal__quote-sep" id="tcbf-quote-sep" style="display:none"></div>' +

			// Total / single-direction row
			'<div class="tcbf-transport-modal__quote-row">' +
			'<span class="tcbf-transport-modal__quote-label" id="tcbf-quote-total-label">' + escHtml(i18n.quoteLabel) + ':</span>' +
			'<span class="tcbf-transport-modal__quote-value" id="tcbf-quote-price"></span>' +
			'</div>' +

			// Simple zone row (visible for single-direction only)
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

		// Mode selector
		$modal.on('change', 'input[name="tcbf_service_mode"]', function () {
			// Update active class on labels
			$modal.find('.tcbf-modal__mode-option').removeClass('tcbf-modal__mode-option--active');
			$(this).closest('.tcbf-modal__mode-option').addClass('tcbf-modal__mode-option--active');
			applyModeVisibility();
			updateConfirmState();
			updateQuotePreview();
		});

		// Same address toggle (unified controls checkbox)
		$('#tcbf-same-address').on('change', function () {
			var same = $(this).is(':checked');
			// Sync mirror in split mode
			$modal.find('.tcbf-same-address-mirror').prop('checked', same);
			handleSameAddressChange(same);
		});

		// Same address mirror (in split mode)
		$modal.on('change', '.tcbf-same-address-mirror', function () {
			var same = $(this).is(':checked');
			$('#tcbf-same-address').prop('checked', same);
			handleSameAddressChange(same);
		});

		// Bike checklist changes
		$modal.on('change', '.tcbf-modal__bike-check', function () {
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

		// Apply initial mode visibility
		applyModeVisibility();

		// Init maps for the visible mode
		initVisibleMaps();

		updateConfirmState();
		updateQuotePreview();
		$('body').addClass('tcbf-modal-open');
	}

	function handleSameAddressChange(same) {
		if (same) {
			var splitAddr = $('#tcbf-split-delivery-address').val();
			if (splitAddr && !$('#tcbf-delivery-address-input').val()) {
				$('#tcbf-delivery-address-input').val(splitAddr);
			}
		} else {
			var unifiedAddr = $('#tcbf-delivery-address-input').val();
			if (unifiedAddr && !$('#tcbf-split-delivery-address').val()) {
				$('#tcbf-split-delivery-address').val(unifiedAddr);
			}
		}
		// Re-run full visibility logic (shows/hides unified vs split, mirror checkbox, etc.)
		applyModeVisibility();
		updateConfirmState();
		updateQuotePreview();
	}

	function applyModeVisibility() {
		if (!$modal) return;
		var mode = getSelectedMode();
		var isBoth = mode === 'both';
		var sameAddr = isSameAddressMode();

		// Same address checkbox — only visible when mode = both
		$('#tcbf-same-address-wrap').toggle(isBoth);
		$('.tcbf-modal__same-address--split').toggle(isBoth && !sameAddr);

		// Address label adapts to mode
		var addrLabel = i18n.addressLabel; // default: delivery address
		if (mode === 'pickup') {
			addrLabel = i18n.pickupAddressLabel || i18n.addressLabel;
		}
		$modal.find('.tcbf-modal__addr-label').text(addrLabel);

		if (isBoth && !sameAddr) {
			// Split mode
			$('#tcbf-unified-mode').hide();
			$('#tcbf-split-mode').show();
			initSplitMaps();
		} else {
			// Unified mode (single direction or both+same address)
			$('#tcbf-unified-mode').show();
			$('#tcbf-split-mode').hide();
			initUnifiedMap();
		}

		// Window visibility in unified mode
		$modal.find('.tcbf-modal__delivery-window').toggle(modeIncludesDelivery(mode));
		// Pickup window in unified: visible for pickup-only or both+same-address
		$modal.find('.tcbf-modal__pickup-window').toggle(modeIncludesPickup(mode) && (!isBoth || sameAddr));
	}

	function initVisibleMaps() {
		var mode = getSelectedMode();
		var sameAddr = isSameAddressMode();
		if (mode === 'both' && !sameAddr) {
			initSplitMaps();
		} else {
			initUnifiedMap();
		}
	}

	function closeModal() {
		if ($modal) {
			$modal.remove();
			$modal = null;
		}
		deliveryMap = null;
		deliveryMarker = null;
		pickupMap = null;
		pickupMarker = null;
		splitMapsInitialized = false;
		unifiedMapInitialized = false;
		$(document).off('keydown.tcbfModal');
		$('body').removeClass('tcbf-modal-open');
	}

	/* ================================================================
	 * Map initialization
	 * ================================================================ */

	function initUnifiedMap() {
		if (unifiedMapInitialized) return;
		unifiedMapInitialized = true;

		var container = document.getElementById('tcbf-delivery-map');
		if (!container) return;
		// Unified input always stores into deliveryPlace regardless of mode.
		// sendBulkConfigure() routes it to the correct backend field.
		var lat = (deliveryPlace && deliveryPlace.lat) ? deliveryPlace.lat : 41.98;
		var lng = (deliveryPlace && deliveryPlace.lng) ? deliveryPlace.lng : 2.82;
		var zoom = (deliveryPlace && deliveryPlace.lat) ? 14 : 9;

		initGoogleMap(container, lat, lng, zoom, 'delivery', function (m, mk) {
			deliveryMap = m;
			deliveryMarker = mk;
		});
		var input = document.getElementById('tcbf-delivery-address-input');
		initAutocompleteProvider(input, 'delivery');
	}

	function initSplitMaps() {
		if (splitMapsInitialized) return;
		splitMapsInitialized = true;

		var dContainer = document.getElementById('tcbf-split-delivery-map');
		var dInput = document.getElementById('tcbf-split-delivery-address');
		var dLat = (deliveryPlace && deliveryPlace.lat) ? deliveryPlace.lat : 41.98;
		var dLng = (deliveryPlace && deliveryPlace.lng) ? deliveryPlace.lng : 2.82;
		var dZoom = (deliveryPlace && deliveryPlace.lat) ? 14 : 9;

		initGoogleMap(dContainer, dLat, dLng, dZoom, 'delivery', function () {});
		initAutocompleteProvider(dInput, 'delivery');

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
			updateQuotePreview();
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
		var mode = getSelectedMode();
		var sameAddr = isSameAddressMode();
		// In unified mode, the single address input serves whichever direction
		if (mode !== 'both' || sameAddr) {
			return $('#tcbf-delivery-address-input');
		}
		// Split mode
		if (direction === 'delivery') {
			return $('#tcbf-split-delivery-address');
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

		var mode = getSelectedMode();
		var sameAddr = isSameAddressMode();
		var wantDelivery = modeIncludesDelivery(mode);
		var wantPickup = modeIncludesPickup(mode);

		// Determine which addresses are available
		var hasDAddr = false;
		var hasPAddr = false;

		if (wantDelivery) {
			var dp = deliveryPlace;
			hasDAddr = dp && dp.lat && dp.lng;
		}
		if (wantPickup) {
			// In unified mode (pickup-only or both+same), address is always in deliveryPlace.
			// In split mode (both+different), pickup address is in pickupPlace.
			var pp = (mode === 'both' && !sameAddr) ? pickupPlace : deliveryPlace;
			hasPAddr = pp && pp.lat && pp.lng;
		}

		if (!hasDAddr && !hasPAddr) {
			$('#tcbf-quote').hide();
			return;
		}

		fetchCombinedQuote(hasDAddr && wantDelivery, hasPAddr && wantPickup, bikeCount);
	}

	function fetchCombinedQuote(hasDelivery, hasPickup, bikeCount) {
		if (quoteDebounce) clearTimeout(quoteDebounce);

		quoteDebounce = setTimeout(function () {
			var $quote = $('#tcbf-quote');
			var $price = $('#tcbf-quote-price');
			var $zone = $('#tcbf-quote-zone');
			var $zoneRow = $('#tcbf-quote-zone-row');
			var $totalLabel = $('#tcbf-quote-total-label');
			var $delRow = $('#tcbf-quote-delivery-row');
			var $pickRow = $('#tcbf-quote-pickup-row');
			var $sep = $('#tcbf-quote-sep');

			// Reset breakdown rows
			$delRow.hide(); $pickRow.hide(); $sep.hide();
			$zoneRow.show();
			$totalLabel.text(escHtml(i18n.quoteLabel) + ':');

			$quote.show();
			$price.text(i18n.loading);
			$zone.text('');

			var mode = getSelectedMode();
			var sameAddr = isSameAddressMode();
			var requests = [];
			var directions = []; // track which direction each request maps to

			var bothActive = (hasDelivery && hasPickup) ? 1 : 0;

			if (hasDelivery && deliveryPlace && deliveryPlace.lat) {
				var dWin = getWindowForDirection('delivery');
				directions.push('delivery');
				requests.push($.ajax({
					url: config.ajaxUrl, method: 'POST', dataType: 'json',
					data: {
						action: 'tcbf_transport_quote',
						lat: deliveryPlace.lat, lng: deliveryPlace.lng,
						direction: 'delivery', window: dWin,
						both_active: bothActive,
						nonce: config.nonce
					}
				}));
			}

			if (hasPickup) {
				var pPlace = (mode === 'both' && !sameAddr) ? pickupPlace : deliveryPlace;
				if (pPlace && pPlace.lat) {
					var pWin = getWindowForDirection('pickup');
					directions.push('pickup');
					requests.push($.ajax({
						url: config.ajaxUrl, method: 'POST', dataType: 'json',
						data: {
							action: 'tcbf_transport_quote',
							lat: pPlace.lat, lng: pPlace.lng,
							direction: 'pickup', window: pWin,
							both_active: bothActive,
							nonce: config.nonce
						}
					}));
				}
			}

			if (requests.length === 0) { $quote.hide(); return; }

			$.when.apply($, requests).then(function () {
				var responses = requests.length === 1 ? [arguments] : Array.prototype.slice.call(arguments);
				var totalPrice = 0;
				var errorMsg = null;
				var perDirection = {}; // { delivery: {price, zone}, pickup: {price, zone} }

				for (var i = 0; i < responses.length; i++) {
					var data = responses[i][0];
					var dir = directions[i];

					if (data && data.success && data.data) {
						var q = data.data.quote || {};
						var price = parseFloat(q.price_total || 0);
						totalPrice += price;
						perDirection[dir] = {
							price: price,
							zone: data.data.zone_name || ''
						};
					} else if (data && !data.success && data.data && data.data.message) {
						errorMsg = data.data.message;
					}
				}

				if (errorMsg) {
					$price.text(errorMsg);
					$zone.text('');
					return;
				}

				var hasBothDirections = perDirection.delivery && perDirection.pickup;

				if (hasBothDirections) {
					// Show per-direction breakdown
					var dLabel = escHtml(i18n.deliverySection);
					if (perDirection.delivery.zone) dLabel += ' \u00B7 ' + escHtml(perDirection.delivery.zone);
					$('#tcbf-quote-delivery-label').text(dLabel + ':');
					var dText = formatPrice(perDirection.delivery.price);
					if (bikeCount > 1) dText += ' (' + formatPrice(perDirection.delivery.price / bikeCount) + ' ' + i18n.perBikeLabel + ')';
					$('#tcbf-quote-delivery-price').text(dText);

					var pLabel = escHtml(i18n.pickupSection);
					if (perDirection.pickup.zone) pLabel += ' \u00B7 ' + escHtml(perDirection.pickup.zone);
					$('#tcbf-quote-pickup-label').text(pLabel + ':');
					var pText = formatPrice(perDirection.pickup.price);
					if (bikeCount > 1) pText += ' (' + formatPrice(perDirection.pickup.price / bikeCount) + ' ' + i18n.perBikeLabel + ')';
					$('#tcbf-quote-pickup-price').text(pText);

					$delRow.show(); $pickRow.show(); $sep.show();
					$zoneRow.hide();
					$totalLabel.text(escHtml(i18n.totalLabel || 'Total') + ':');
				}

				// Total row (always)
				if (totalPrice > 0) {
					var text = formatPrice(totalPrice);
					if (bikeCount > 1) {
						text += ' (' + formatPrice(totalPrice / bikeCount) + ' ' + i18n.perBikeLabel + ')';
					}
					$price.text(text);
				} else {
					$price.text('--');
				}

				// Zone row (single-direction only)
				if (!hasBothDirections) {
					var singleDir = perDirection.delivery || perDirection.pickup;
					$zone.text(singleDir && singleDir.zone ? singleDir.zone : (i18n.outsideZones || ''));
				}
			}).fail(function (jqXHR) {
				if (typeof console !== 'undefined') console.error('[TCBF Transport] Quote AJAX failed', jqXHR && jqXHR.status, jqXHR && jqXHR.responseText);
				$price.text('--');
			});
		}, 300);
	}

	/* ================================================================
	 * Bulk configure (confirm)
	 * ================================================================ */

	function doBulkConfigure() {
		if (!$modal) return;

		var mode = getSelectedMode();
		var sameAddr = isSameAddressMode();
		var wantDelivery = modeIncludesDelivery(mode);
		var wantPickup = modeIncludesPickup(mode);

		// For pickup-only mode, the unified address input is for pickup
		var effectiveDeliveryPlace = (mode === 'pickup') ? null : deliveryPlace;
		var effectivePickupPlace;

		if (mode === 'pickup') {
			effectivePickupPlace = deliveryPlace; // unified input holds pickup address
		} else if (mode === 'both' && sameAddr) {
			effectivePickupPlace = deliveryPlace;
		} else if (mode === 'both' && !sameAddr) {
			effectivePickupPlace = pickupPlace;
		} else {
			effectivePickupPlace = null;
		}

		// Validate delivery address
		if (wantDelivery) {
			if (!effectiveDeliveryPlace || (!effectiveDeliveryPlace.lat && !effectiveDeliveryPlace.lng)) {
				var typedDelivery = getActiveInputForDirection('delivery').val().trim();
				if (typedDelivery.length > 5) {
					geocodeAndConfigure(typedDelivery, 'delivery');
					return;
				}
				showError(i18n.errorGeneric);
				return;
			}
		}

		// Validate pickup address
		if (wantPickup) {
			if (!effectivePickupPlace || (!effectivePickupPlace.lat && !effectivePickupPlace.lng)) {
				var typedPickup;
				if (mode === 'pickup' || sameAddr) {
					typedPickup = $('#tcbf-delivery-address-input').val().trim();
				} else {
					typedPickup = $('#tcbf-split-pickup-address').val().trim();
				}
				if (typedPickup.length > 5) {
					geocodeAndConfigure(typedPickup, 'pickup');
					return;
				}
				showError(i18n.errorGeneric);
				return;
			}
		}

		sendBulkConfigure();
	}

	function geocodeAndConfigure(address, forDirection) {
		var $confirmBtn = $modal.find('.tcbf-transport-modal__confirm');
		$confirmBtn.prop('disabled', true).text(i18n.geocoding || i18n.loading);

		$.ajax({
			url: config.ajaxUrl, method: 'POST', dataType: 'json',
			data: {
				action: 'tcbf_transport_geocode',
				address: address,
				nonce: config.nonce
			}
		}).done(function (response) {
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

		var mode = getSelectedMode();
		var sameAddr = isSameAddressMode();
		var wantDelivery = modeIncludesDelivery(mode) ? 1 : 0;
		var wantPickup = modeIncludesPickup(mode) ? 1 : 0;
		var bikeKeys = getSelectedBikeKeys();

		var postData = {
			action: 'tcbf_transport_bulk_configure',
			enable_delivery: wantDelivery,
			enable_pickup: wantPickup,
			same_address: (mode === 'both' && sameAddr) ? 1 : 0,
			bike_keys: JSON.stringify(bikeKeys),
			nonce: config.nonce
		};

		// Delivery address data
		if (wantDelivery && deliveryPlace) {
			postData.delivery_address = deliveryPlace.address;
			postData.delivery_lat = deliveryPlace.lat;
			postData.delivery_lng = deliveryPlace.lng;
			postData.delivery_place_id = deliveryPlace.place_id || '';
			postData.delivery_window = getWindowForDirection('delivery');
		}

		// Pickup address data
		if (wantPickup) {
			postData.pickup_window = getWindowForDirection('pickup');

			if (mode === 'pickup') {
				// In pickup-only mode, address is from the unified input (stored as deliveryPlace)
				if (deliveryPlace) {
					postData.pickup_address = deliveryPlace.address;
					postData.pickup_lat = deliveryPlace.lat;
					postData.pickup_lng = deliveryPlace.lng;
					postData.pickup_place_id = deliveryPlace.place_id || '';
				}
			} else if (mode === 'both' && !sameAddr && pickupPlace) {
				postData.pickup_address = pickupPlace.address;
				postData.pickup_lat = pickupPlace.lat;
				postData.pickup_lng = pickupPlace.lng;
				postData.pickup_place_id = pickupPlace.place_id || '';
			}
			// When same_address=1, backend uses delivery address for pickup
		}

		$.ajax({ url: config.ajaxUrl, method: 'POST', dataType: 'json', data: postData })
		.done(function (response) {
			if (!response || !response.success) {
				var msg = (response && response.data && response.data.message)
					? response.data.message
					: i18n.errorGeneric;
				showError(msg);
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
		})
		.fail(function (jqXHR) {
			var msg = i18n.errorGeneric;
			// Try to extract error from response body
			try {
				var resp = JSON.parse(jqXHR.responseText);
				if (resp && resp.data && resp.data.message) {
					msg = resp.data.message;
				}
			} catch (e) {}
			showError(msg + ' (HTTP ' + jqXHR.status + ')');
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
		var mode = getSelectedMode();
		var sameAddr = isSameAddressMode();
		var wantDelivery = modeIncludesDelivery(mode);
		var wantPickup = modeIncludesPickup(mode);

		// Check address validity based on mode
		if (mode === 'both' && !sameAddr) {
			// Split mode: need both addresses
			var dVal = $('#tcbf-split-delivery-address').val().trim();
			if (dVal.length < 3 && (!deliveryPlace || !deliveryPlace.lat)) valid = false;
			var pVal = $('#tcbf-split-pickup-address').val().trim();
			if (pVal.length < 3 && (!pickupPlace || !pickupPlace.lat)) valid = false;
		} else {
			// Unified mode: address always stored in deliveryPlace
			var uVal = $('#tcbf-delivery-address-input').val().trim();
			if (uVal.length < 3 && (!deliveryPlace || !deliveryPlace.lat)) {
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
