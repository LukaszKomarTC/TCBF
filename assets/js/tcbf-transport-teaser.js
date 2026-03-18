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

		// Compute bounds first so the map has a valid view before any layers are added.
		// Without an initial center/zoom, Leaflet throws "Set map center and zoom first"
		// when circles attempt coordinate projection during addTo().
		var bounds = L.latLngBounds();
		var validZones = [];

		zones.forEach(function (zone, idx) {
			if (!zone.lat || !zone.lng || !zone.radius_km) return;
			var radiusM = zone.radius_km * 1000;
			var center = L.latLng(zone.lat, zone.lng);
			// Pre-calculate circle bounds using simple offset (good enough for fitBounds)
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

		map = L.map(mapEl, {
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

			var circle = L.circle([zone.lat, zone.lng], {
				radius: item.radiusM,
				color: color,
				fillColor: color,
				fillOpacity: 0.12,
				weight: 2
			}).addTo(map);

			circle.bindTooltip(zone.name + ' (~' + zone.radius_km + ' km)', {
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
