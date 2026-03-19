/**
 * TCBF Transport Info — Zone map initialisation
 *
 * Renders a read-only Leaflet map showing coverage zones.
 * Defers rendering until the PopupMaker popup is visible so Leaflet
 * can correctly measure the container dimensions.
 */
(function () {
	'use strict';

	/** Zone circle colours (rotate through these) */
	var ZONE_COLORS = ['#1a6b3a', '#2980b9', '#c0392b', '#8e44ad', '#d35400', '#16a085'];

	var mapInitialised = false;

	function initMap() {
		if (mapInitialised) return;

		var mapEl = document.getElementById('tcbf-transport-zone-map');
		if (!mapEl || typeof L === 'undefined') return;

		// Don't init if container is hidden (zero dimensions) — wait for popup open.
		if (mapEl.offsetParent === null) return;

		var raw = (typeof tcbfTeaserZones !== 'undefined') ? tcbfTeaserZones : [];
		var zones = Array.isArray(raw) ? raw : Object.values(raw);
		if (!zones.length) {
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
		// Try immediately (works if map is NOT inside a hidden popup).
		initMap();

		// PopupMaker fires 'pumAfterOpen' on the document when a popup opens.
		// This is the most reliable hook for knowing the container is visible.
		jQuery(document).on('pumAfterOpen', function () {
			initMap();
		});

		// Fallback: MutationObserver on the popup container.
		var mapEl = document.getElementById('tcbf-transport-zone-map');
		if (mapEl) {
			var popupEl = mapEl.closest('.pum-container') || mapEl.closest('.pum') || mapEl.closest('[aria-modal]');
			if (popupEl) {
				var observer = new MutationObserver(function () {
					if (mapEl.offsetParent !== null) {
						initMap();
					}
				});
				observer.observe(popupEl, { attributes: true, attributeFilter: ['class', 'style', 'aria-hidden'] });
			}
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', onReady);
	} else {
		onReady();
	}
})();
