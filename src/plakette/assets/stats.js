/* Plakette-Statistik: one anonymous page view per load. No cookies, no storage, no fingerprinting. */
(function () {
	'use strict';
	var s = document.currentScript, d = document;
	if (!s || !navigator.sendBeacon || (d.body && d.body.classList.contains('logged-in')) || navigator.webdriver) { return; }
	var r = '';
	try { r = d.referrer ? new URL(d.referrer).hostname : ''; } catch (e) { r = ''; }
	if (r === location.hostname) { r = ''; }
	navigator.sendBeacon(s.getAttribute('data-u'), new Blob([JSON.stringify({ p: location.pathname, r: r })], { type: 'text/plain' }));
})();
