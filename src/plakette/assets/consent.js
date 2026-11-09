/*
 * Plakette – Cookie-Einwilligung und Zwei-Klick-Einbettungen.
 * Kein Build, keine Abhängigkeiten. Alle Entscheidungen fallen im Browser, damit Seiten-Caches funktionieren.
 * Cookie "plakette_consent": {"v": Version, "t": Zeitpunkt, "c": {statistics, marketing, media}, "a": Banner beantwortet}
 * API: window.plaketteConsent.open() / .allowed('media') ; Ereignis "plakette:consent" auf document.
 */
(function () {
	'use strict';

	var d = document;
	var NAME = 'plakette_consent';
	var MAX_AGE = 182 * 86400;
	var cfg = null;
	var cfgEl = d.getElementById('plakette-consent-config');
	if (cfgEl) {
		try { cfg = JSON.parse(cfgEl.textContent); } catch (e) { cfg = null; }
	}
	var root = d.getElementById('plakette-consent');
	var reopen = d.querySelector('.plk-cc-reopen');
	var lastFocus = null;

	function read() {
		var m = d.cookie.match(/(?:^|;\s*)plakette_consent=([^;]*)/);
		if (!m) { return null; }
		try {
			var o = JSON.parse(decodeURIComponent(m[1]));
			return o && typeof o.c === 'object' && o.c ? o : null;
		} catch (e) { return null; }
	}

	/** Valid consent for the current list of services (null = ask again). */
	function current() {
		var o = read();
		if (!o) { return null; }
		if (cfg && o.v !== cfg.v) { return null; }
		return o;
	}

	function allowed(cat) {
		var o = current();
		return !!(o && o.c[cat]);
	}

	function write(c, answered) {
		var o = { v: cfg ? cfg.v : '', t: Math.floor(Date.now() / 1000), c: c, a: answered ? 1 : 0 };
		d.cookie = NAME + '=' + encodeURIComponent(JSON.stringify(o)) + ';path=/;max-age=' + MAX_AGE + ';SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');
		return o;
	}

	/** Remove cookies of common trackers after a revocation (best effort). */
	function cleanup() {
		var re = /^(_ga|_gid|_gat|_gcl|_fbp|_fbc|_hj|_clck|_clsk|_pk_|_ttp|_uet|IDE|NID)/;
		var host = location.hostname.split('.');
		d.cookie.split(';').forEach(function (part) {
			var name = part.split('=')[0].trim();
			if (!re.test(name)) { return; }
			for (var i = 0; i < host.length - 1; i++) {
				var dom = host.slice(i).join('.');
				d.cookie = name + '=;path=/;max-age=0;domain=.' + dom;
			}
			d.cookie = name + '=;path=/;max-age=0';
		});
	}

	/* ---------------------------------------------------------------- scripts held back on the server */

	function activate(list, i) {
		for (; i < list.length; i++) {
			var old = list[i];
			if (!old.parentNode || !allowed(old.getAttribute('data-plakette-consent'))) { continue; }
			var s = d.createElement('script');
			for (var a = 0; a < old.attributes.length; a++) {
				var n = old.attributes[a].name;
				if (n !== 'type' && n !== 'data-plakette-src' && n !== 'data-plakette-type' && n !== 'data-plakette-consent') {
					s.setAttribute(n, old.attributes[a].value);
				}
			}
			var type = old.getAttribute('data-plakette-type');
			if (type) { s.setAttribute('type', type); }
			var src = old.getAttribute('data-plakette-src');
			if (src) {
				if (!old.hasAttribute('async')) {
					// Keep the order: the next script waits until this one has loaded.
					s.async = false;
					s.onload = s.onerror = (function (next) { return function () { activate(list, next); }; })(i + 1);
					s.src = src;
					old.parentNode.replaceChild(s, old);
					return;
				}
				s.src = src;
			} else {
				s.text = old.text;
			}
			old.parentNode.replaceChild(s, old);
		}
	}

	/* ---------------------------------------------------------------- two-click placeholders */

	function loadEmbed(box, play) {
		var f = box.querySelector('iframe[data-plakette-src]');
		if (!f) { return; }
		var src = f.getAttribute('data-plakette-src');
		if (play && /youtube-nocookie\.com\/embed\/|player\.vimeo\.com\//.test(src)) {
			src += (src.indexOf('?') < 0 ? '?' : '&') + 'autoplay=1';
		}
		f.removeAttribute('data-plakette-src');
		f.removeAttribute('hidden');
		f.setAttribute('src', src);
		['.plk-embed__box', '.plk-embed__thumb'].forEach(function (sel) {
			var el = box.querySelector(sel);
			if (el) { el.parentNode.removeChild(el); }
		});
		box.classList.add('is-loaded');
		if (play) { f.focus(); }
	}

	function loadAllEmbeds() {
		if (!allowed('media')) { return; }
		var list = d.querySelectorAll('.plk-embed:not(.is-loaded)');
		for (var i = 0; i < list.length; i++) { loadEmbed(list[i], false); }
	}

	function apply() {
		activate(d.querySelectorAll('script[type="text/plain"][data-plakette-consent]'), 0);
		loadAllEmbeds();
		var o = current();
		var ev;
		try { ev = new CustomEvent('plakette:consent', { detail: o ? o.c : {} }); } catch (e) { ev = null; }
		if (ev) { d.dispatchEvent(ev); }
	}

	/** Store a decision. Revoking something that already ran needs a reload. */
	function decide(c, answered) {
		var before = current();
		write(c, answered);
		if (before) {
			for (var k in before.c) {
				if (Object.prototype.hasOwnProperty.call(before.c, k) && before.c[k] && !c[k]) {
					cleanup();
					location.reload();
					return;
				}
			}
		}
		apply();
	}

	function cats() { return cfg && cfg.cats ? cfg.cats : ['statistics', 'marketing', 'media']; }

	/* ---------------------------------------------------------------- banner */

	function q(sel) { return root ? root.querySelector(sel) : null; }

	function setPrefs(open) {
		var prefs = q('#plk-cc-prefs');
		var btn = q('[data-plk-cc="settings"]');
		var save = q('[data-plk-cc="save"]');
		if (!prefs) { return; }
		prefs.hidden = !open;
		if (btn) { btn.setAttribute('aria-expanded', open ? 'true' : 'false'); btn.hidden = open; }
		if (save) { save.hidden = !open; }
		if (open) {
			var o = current();
			var boxes = prefs.querySelectorAll('input[data-plk-cat]');
			for (var i = 0; i < boxes.length; i++) {
				boxes[i].checked = !!(o && o.c[boxes[i].getAttribute('data-plk-cat')]);
			}
		}
	}

	function show(prefs, focus) {
		if (!root) { return; }
		if (focus) { lastFocus = d.activeElement; }
		root.hidden = false;
		if (reopen) { reopen.hidden = true; }
		setPrefs(!!prefs);
		d.documentElement.classList.add('plk-cc-open');
		if (focus) {
			var t = q('#plk-cc-title');
			if (t) { t.focus(); }
		}
	}

	function hide() {
		if (!root) { return; }
		root.hidden = true;
		d.documentElement.classList.remove('plk-cc-open');
		if (reopen && cfg && cfg.reopen) { reopen.hidden = false; }
		if (lastFocus && lastFocus.focus && d.body.contains(lastFocus)) { lastFocus.focus(); }
		lastFocus = null;
	}

	function choose(all) {
		var c = {};
		cats().forEach(function (k) { c[k] = !!all; });
		return c;
	}

	function fromToggles() {
		var c = choose(false);
		var boxes = root.querySelectorAll('input[data-plk-cat]');
		for (var i = 0; i < boxes.length; i++) { c[boxes[i].getAttribute('data-plk-cat')] = boxes[i].checked; }
		return c;
	}

	d.addEventListener('click', function (e) {
		var t = e.target;
		if (!t || !t.closest) { return; }

		var opener = t.closest('[data-plakette-consent-open],a[href="#plakette-consent"]');
		if (opener && root) {
			e.preventDefault();
			show(true, true);
			return;
		}

		var act = t.closest('[data-plk-cc]');
		if (act && root && root.contains(act)) {
			var what = act.getAttribute('data-plk-cc');
			if (what === 'settings') {
				setPrefs(true);
				var first = q('input[data-plk-cat]');
				if (first) { first.focus(); }
				return;
			}
			var c = what === 'all' ? choose(true) : (what === 'save' ? fromToggles() : choose(false));
			hide();
			decide(c, true);
			return;
		}

		var load = t.closest('.plk-embed__load');
		if (load) {
			var box = load.closest('.plk-embed');
			var always = box && box.querySelector('.plk-embed__always input');
			if (always && always.checked) {
				var o = current();
				var cc = o ? o.c : choose(false);
				cc.media = true;
				decide(cc, o ? o.a : 0);
			}
			if (box && !box.classList.contains('is-loaded')) { loadEmbed(box, true); }
		}
	});

	d.addEventListener('keydown', function (e) {
		if ((e.key === 'Escape' || e.key === 'Esc') && root && !root.hidden) {
			var o = current();
			if (o && o.a) { hide(); }
		}
	});

	window.plaketteConsent = {
		open: function () { show(true, true); },
		allowed: allowed
	};

	if (root && cfg) {
		var o = current();
		if (!o || !o.a) {
			show(false, false);
		} else if (reopen && cfg.reopen) {
			reopen.hidden = false;
		}
	}
	apply();
})();
