/**
 * TCBF Transport Info — Zone map initialisation
 *
 * Renders a read-only Leaflet map showing coverage zones.
 * No popup open/close logic — PopupMaker handles that.
 */
(function () {
	'use strict';

	/** Zone circle colours (rotate through these) */
	var ZONE_COLORS = ['#1a6b3a', '#2980b9', '#c0392b', '#8e44ad', '#d35400', '#16a085'];

	function initMap() {
		var mapEl = document.getElementById('tcbf-transport-zone-map');
		if (!mapEl || typeof L === 'undefined') return;

		var zones = (typeof tcbfTeaserZones !== 'undefined') ? tcbfTeaserZones : [];
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

		// When PopupMaker reveals the content, Leaflet may need a size recalculation.
		// Listen for the container becoming visible and invalidate.
		var observer = new MutationObserver(function () {
			if (mapEl.offsetParent !== null) {
				map.invalidateSize();
			}
		});
		var popupEl = mapEl.closest('.pum-container') || mapEl.closest('[aria-modal]');
		if (popupEl) {
			observer.observe(popupEl, { attributes: true, attributeFilter: ['class', 'style', 'aria-hidden'] });
		}
	}

	// Initialise when DOM is ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initMap);
	} else {
		initMap();
	}
})();
