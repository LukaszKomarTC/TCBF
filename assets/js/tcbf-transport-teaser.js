/**
 * TCBF Transport Homepage Teaser — Popup open/close
 */
(function () {
	'use strict';

	var overlay = document.getElementById('tcbf-transport-popup-overlay');
	if (!overlay) return;

	/** Open popup */
	function open() {
		overlay.setAttribute('aria-hidden', 'false');
		document.body.style.overflow = 'hidden';
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
