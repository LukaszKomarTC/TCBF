/**
 * TCBF Transport Homepage Teaser — Popup open/close + zone map
 */
(function () {
	'use strict';

	var overlay = document.getElementById('tcbf-transport-popup-overlay');
	if (!overlay) return;

	var map = null;
	var mapInitialised = false;

	/** Zone circle colours (rotate through these) */
	var ZONE_COLORS = ['#1a6b3a', '#2980b9', '#c0392b', '#8e44ad', '#d35400', '#16a085'];

	/** Initialise the Leaflet zone map (called once on first popup open) */
	function initMap() {
		if (mapInitialised) return;
		mapInitialised = true;

		var mapEl = document.getElementById('tcbf-transport-zone-map');
		if (!mapEl || typeof L === 'undefined') return;

		var zones = (typeof tcbfTeaserZones !== 'undefined') ? tcbfTeaserZones : [];
		if (!zones.length) {
			mapEl.style.display = 'none';
			return;
		}

		map = L.map(mapEl, {
			scrollWheelZoom: false,
			dragging: true,
			zoomControl: true,
			attributionControl: true
		});

		L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
			maxZoom: 18
		}).addTo(map);

		var bounds = L.latLngBounds();

		zones.forEach(function (zone, idx) {
			if (!zone.lat || !zone.lng || !zone.radius_km) return;

			var color = ZONE_COLORS[idx % ZONE_COLORS.length];
			var radiusM = zone.radius_km * 1000;

			var circle = L.circle([zone.lat, zone.lng], {
				radius: radiusM,
				color: color,
				fillColor: color,
				fillOpacity: 0.12,
				weight: 2
			}).addTo(map);

			// Label tooltip
			circle.bindTooltip(zone.name + ' (~' + zone.radius_km + ' km)', {
				permanent: false,
				direction: 'top',
				className: 'tcbf-zone-tooltip'
			});

			// Center marker
			L.circleMarker([zone.lat, zone.lng], {
				radius: 4,
				color: color,
				fillColor: color,
				fillOpacity: 1,
				weight: 0
			}).addTo(map);

			bounds.extend(circle.getBounds());
		});

		if (bounds.isValid()) {
			map.fitBounds(bounds, { padding: [30, 30] });
		}
	}

	/** Open popup */
	function open() {
		overlay.setAttribute('aria-hidden', 'false');
		document.body.style.overflow = 'hidden';

		// Delay map init to allow DOM layout to settle
		setTimeout(function () {
			initMap();
			if (map) map.invalidateSize();
		}, 150);
	}

	/** Close popup */
	function close() {
		overlay.setAttribute('aria-hidden', 'true');
		document.body.style.overflow = '';
	}

	// CTA button(s)
	var openers = document.querySelectorAll('[data-tcbf-open-transport-popup]');
	for (var i = 0; i < openers.length; i++) {
		openers[i].addEventListener('click', open);
	}

	// Close button(s)
	var closers = document.querySelectorAll('[data-tcbf-close-transport-popup]');
	for (var j = 0; j < closers.length; j++) {
		closers[j].addEventListener('click', close);
	}

	// Click outside popup to close
	overlay.addEventListener('click', function (e) {
		if (e.target === overlay) close();
	});

	// Escape key
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && overlay.getAttribute('aria-hidden') === 'false') {
			close();
		}
	});
})();
