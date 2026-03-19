/**
 * TCBF Transport Info — Zone map initialisation
 *
 * Renders a read-only Leaflet map showing coverage zones.
 * Defers rendering until the PopupMaker popup is visible so Leaflet
 * can correctly measure the container dimensions.
 */
(function () {
	'use strict';

	var DEBUG = true;
	function dbg(msg, data) {
		if (!DEBUG) return;
		if (data !== undefined) {
			console.log('[TCBF-MAP] ' + msg, data);
		} else {
			console.log('[TCBF-MAP] ' + msg);
		}
	}

	/** Zone circle colours (rotate through these) */
	var ZONE_COLORS = ['#1a6b3a', '#2980b9', '#c0392b', '#8e44ad', '#d35400', '#16a085'];

	var mapInitialised = false;

	function initMap(caller) {
		dbg('initMap() called from: ' + (caller || 'unknown'));

		if (mapInitialised) {
			dbg('Already initialised — skipping.');
			return;
		}

		var mapEl = document.getElementById('tcbf-transport-zone-map');
		dbg('Map element found:', !!mapEl);
		dbg('Leaflet (L) loaded:', typeof L !== 'undefined');
		if (!mapEl || typeof L === 'undefined') {
			if (!mapEl) dbg('BLOCKED: #tcbf-transport-zone-map not found in DOM');
			if (typeof L === 'undefined') dbg('BLOCKED: Leaflet (L) not loaded');
			return;
		}

		// Show debug info on the map placeholder
		mapEl.style.border = '3px dashed red';
		mapEl.setAttribute('data-debug', 'Map container found');

		dbg('offsetParent:', mapEl.offsetParent);
		dbg('offsetWidth:', mapEl.offsetWidth);
		dbg('offsetHeight:', mapEl.offsetHeight);
		dbg('getBoundingClientRect:', mapEl.getBoundingClientRect());
		dbg('computed display:', window.getComputedStyle(mapEl).display);
		dbg('computed visibility:', window.getComputedStyle(mapEl).visibility);

		// Walk up DOM to find any hidden ancestors
		var el = mapEl;
		while (el) {
			var style = window.getComputedStyle(el);
			if (style.display === 'none') {
				dbg('HIDDEN ANCESTOR (display:none):', { tag: el.tagName, id: el.id, class: el.className });
			}
			if (style.visibility === 'hidden') {
				dbg('HIDDEN ANCESTOR (visibility:hidden):', { tag: el.tagName, id: el.id, class: el.className });
			}
			el = el.parentElement;
		}

		// Don't init if container is hidden (zero dimensions) — wait for popup open.
		if (mapEl.offsetParent === null) {
			dbg('BLOCKED: offsetParent is null (element or ancestor hidden). Waiting for popup...');
			return;
		}

		var raw = (typeof tcbfTeaserZones !== 'undefined') ? tcbfTeaserZones : [];
		dbg('tcbfTeaserZones raw:', raw);
		var zones = Array.isArray(raw) ? raw : Object.values(raw);
		dbg('Zones count:', zones.length);
		if (!zones.length) {
			dbg('BLOCKED: No zones data — hiding map.');
			mapEl.style.display = 'none';
			return;
		}

		// Compute bounds first so the map has a valid view before any layers are added.
		var bounds = L.latLngBounds();
		var validZones = [];

		zones.forEach(function (zone, idx) {
			if (!zone.lat || !zone.lng || !zone.radius_km) return;
			var radiusM = zone.radius_km * 1000;
			var center = L.latLng(zone.lat, zone.lng);
			var latOffset = (radiusM / 111320);
			var lngOffset = (radiusM / (111320 * Math.cos(zone.lat * Math.PI / 180)));
			bounds.extend([center.lat - latOffset, center.lng - lngOffset]);
			bounds.extend([center.lat + latOffset, center.lng + lngOffset]);
			validZones.push({ zone: zone, idx: idx, radiusM: radiusM });
		});

		if (!bounds.isValid() || !validZones.length) {
			mapEl.style.display = 'none';
			return;
		}

		mapInitialised = true;
		dbg('SUCCESS: Initialising Leaflet map now!');
		mapEl.style.border = '3px solid green';

		var map = L.map(mapEl, {
			scrollWheelZoom: false,
			dragging: true,
			zoomControl: true,
			attributionControl: true,
			center: bounds.getCenter(),
			zoom: 8
		});

		L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
			maxZoom: 18
		}).addTo(map);

		map.fitBounds(bounds, { padding: [30, 30] });

		validZones.forEach(function (item) {
			var zone = item.zone;
			var color = ZONE_COLORS[item.idx % ZONE_COLORS.length];

			L.circle([zone.lat, zone.lng], {
				radius: item.radiusM,
				color: color,
				fillColor: color,
				fillOpacity: 0.12,
				weight: 2
			}).addTo(map).bindTooltip(zone.name + ' (~' + zone.radius_km + ' km)', {
				permanent: false,
				direction: 'top',
				className: 'tcbf-zone-tooltip'
			});

			L.circleMarker([zone.lat, zone.lng], {
				radius: 4,
				color: color,
				fillColor: color,
				fillOpacity: 1,
				weight: 0
			}).addTo(map);
		});

		// After first render inside the popup, Leaflet may still need a size
		// recalculation (e.g. CSS transitions). Schedule one more invalidation.
		setTimeout(function () { map.invalidateSize(); map.fitBounds(bounds, { padding: [30, 30] }); }, 300);
	}

	function onReady() {
		dbg('onReady() fired. document.readyState:', document.readyState);

		// Try immediately (works if map is NOT inside a hidden popup).
		initMap('onReady-immediate');

		// PopupMaker fires 'pumAfterOpen' on the document when a popup opens.
		// This is the most reliable hook for knowing the container is visible.
		if (typeof jQuery !== 'undefined') {
			dbg('jQuery available — binding pumAfterOpen listener');
			jQuery(document).on('pumAfterOpen', function (event, popup) {
				dbg('pumAfterOpen event fired!', { popup: popup });
				initMap('pumAfterOpen');
				// Extra retry after CSS transitions settle
				setTimeout(function () { initMap('pumAfterOpen-delayed'); }, 500);
			});

			// Also try pumBeforeOpen in case after-open has timing issues
			jQuery(document).on('pumBeforeOpen', function (event, popup) {
				dbg('pumBeforeOpen event fired!', { popup: popup });
				setTimeout(function () { initMap('pumBeforeOpen-delayed-300'); }, 300);
				setTimeout(function () { initMap('pumBeforeOpen-delayed-800'); }, 800);
			});
		} else {
			dbg('WARNING: jQuery not available — pumAfterOpen listener NOT bound');
		}

		// Fallback: MutationObserver on the popup container.
		var mapEl = document.getElementById('tcbf-transport-zone-map');
		dbg('MutationObserver setup — mapEl found:', !!mapEl);
		if (mapEl) {
			var popupEl = mapEl.closest('.pum-container') || mapEl.closest('.pum') || mapEl.closest('[aria-modal]');
			dbg('Popup ancestor found:', popupEl ? { tag: popupEl.tagName, id: popupEl.id, class: popupEl.className } : 'NONE');
			if (popupEl) {
				var observer = new MutationObserver(function (mutations) {
					dbg('MutationObserver fired', mutations.map(function(m) { return m.attributeName; }));
					if (mapEl.offsetParent !== null) {
						initMap('MutationObserver');
					}
				});
				observer.observe(popupEl, { attributes: true, attributeFilter: ['class', 'style', 'aria-hidden'] });
			} else {
				dbg('WARNING: No popup ancestor found — MutationObserver NOT set up.');
				dbg('Map container ancestors:', (function() {
					var path = [];
					var p = mapEl.parentElement;
					while (p) {
						path.push(p.tagName + (p.id ? '#' + p.id : '') + (p.className ? '.' + String(p.className).split(' ').join('.') : ''));
						p = p.parentElement;
					}
					return path;
				})());

				// Fallback: periodic retry for cases where popup detection fails
				dbg('Setting up periodic retry fallback (every 1s for 30s)');
				var retryCount = 0;
				var retryInterval = setInterval(function () {
					retryCount++;
					if (mapInitialised || retryCount > 30) {
						clearInterval(retryInterval);
						dbg('Periodic retry stopped. Initialised:', mapInitialised, 'Retries:', retryCount);
						return;
					}
					initMap('periodic-retry-' + retryCount);
				}, 1000);
			}
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', onReady);
	} else {
		onReady();
	}
})();
