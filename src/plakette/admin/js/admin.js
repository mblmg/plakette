/* Plakette – admin screen. Plain JavaScript, no build step. */
( function () {
	'use strict';

	const C = window.PLAKETTE;
	const app = document.getElementById( 'plakette-app' );
	if ( ! C || ! app ) {
		return;
	}

	let S = C.state || {};
	const ui = {
		filter: 'all',
		closed: new Set(), // major problems the user collapsed (they are open by default)
		opened: new Set(), // other rows the user opened
		showPass: false,
		showSkip: false,
		busy: null, // 'audit' | 'all' | 'measure' | 'probe' | 'psi' | check id
		probing: false,
		probeStep: '',
		progress: '',
		drafts: {}, // check id → { value, items: { id: { value, checked } }, expanded }
		stamp: false,
		error: '',
		psiError: '',
		measuring: false,
		forms: {}, // scope ('check:<id>' | 'set:<section>') → { key: value }
		openSettings: new Set(),
	};

	/* ------------------------------------------------------------ helpers */

	const nf = new Intl.NumberFormat( 'de-DE', { maximumFractionDigits: 1 } );
	const nf2 = new Intl.NumberFormat( 'de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 } );

	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ] ) );
	}
	function ms( v ) {
		if ( v == null ) {
			return '–';
		}
		return v < 1000 ? Math.round( v ) + ' ms' : nf.format( v / 1000 ) + ' s';
	}
	function bytes( v ) {
		if ( v == null ) {
			return '–';
		}
		if ( v >= 1048576 ) {
			return nf.format( v / 1048576 ) + ' MB';
		}
		return Math.round( v / 1024 ) + ' KB';
	}
	function when( ts ) {
		const d = new Date( ts * 1000 );
		return d.toLocaleDateString( 'de-DE', { day: 'numeric', month: 'long', year: 'numeric' } ) + ' um ' + d.toLocaleTimeString( 'de-DE', { hour: '2-digit', minute: '2-digit' } );
	}
	function plural( n, one, many ) {
		return n + ' ' + ( n === 1 ? one : many );
	}
	function runId() {
		return 'r' + Date.now().toString( 36 ) + Math.random().toString( 36 ).slice( 2, 7 );
	}

	async function api( path, method, body ) {
		const res = await fetch( C.rest + path, {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': C.nonce, 'Content-Type': 'application/json' },
			body: body ? JSON.stringify( body ) : undefined,
		} );
		let json = null;
		try {
			json = await res.json();
		} catch ( e ) {
			throw new Error( 'Der Server hat keine gültige Antwort geschickt (HTTP ' + res.status + ').' );
		}
		if ( ! res.ok ) {
			throw new Error( ( json && json.message ) || 'Unbekannter Fehler (HTTP ' + res.status + ').' );
		}
		return json;
	}

	function setState( next ) {
		[ 'report', 'measure', 'psi', 'log', 'history', 'psiKey', 'areas', 'settings', 'updater', 'probe', 'probeSetup', 'stats' ].forEach( ( k ) => {
			if ( next && k in next ) {
				S[ k ] = next[ k ];
			}
		} );
	}

	const checks = () => ( S.report && S.report.checks ) || [];
	const byId = ( id ) => checks().find( ( c ) => c.id === id );
	const severity = ( c ) => {
		if ( c.status === 'fail' ) {
			return c.weight >= 6 ? 'major' : 'minor';
		}
		return c.status === 'warn' ? 'note' : c.status;
	};
	const kindOf = ( c ) => c.kind || 'auto';
	const AREA_FALLBACK = { privacy: 'Datenschutz', security: 'Sicherheit', speed: 'Geschwindigkeit', seo: 'SEO' };
	const areas = () => S.areas || AREA_FALLBACK;
	const areaLabel = ( key ) => areas()[ key ] || key;
	const fixable = ( c ) => ( c.status === 'fail' || c.status === 'warn' ) && ! c.blocked && kindOf( c ) !== 'none';

	function draft( c ) {
		if ( ! ui.drafts[ c.id ] ) {
			const d = { value: c.input ? c.input.value || '' : '', items: {}, expanded: false };
			( c.items || [] ).forEach( ( it ) => {
				d.items[ it.id ] = { value: it.value || '', checked: it.checked !== false };
			} );
			ui.drafts[ c.id ] = d;
		}
		return ui.drafts[ c.id ];
	}

	/** Checks the "apply all safe fixes" button handles. */
	function safeList() {
		return checks().filter( ( c ) => {
			if ( ! fixable( c ) || c.risk !== 'safe' ) {
				return false;
			}
			const k = kindOf( c );
			if ( k === 'auto' ) {
				return true;
			}
			if ( k === 'input' ) {
				return !! ( c.input && c.input.value );
			}
			if ( k === 'items' ) {
				return ( c.items || [] ).length > 0;
			}
			if ( k === 'form' ) {
				const vals = formValues( 'check:' + c.id, ( c.form && c.form.fields ) || [] );
				return ( ( c.form && c.form.fields ) || [] ).every( ( f ) => ! f.required || String( vals[ f.key ] || '' ).trim() );
			}
			return false;
		} );
	}

	/* ------------------------------------------------------------ plakette */

	function arc( cx, cy, r1, r2, a0, a1 ) {
		const p = ( r, a ) => [ cx + r * Math.cos( a ), cy + r * Math.sin( a ) ];
		const large = a1 - a0 > Math.PI ? 1 : 0;
		const [ x1, y1 ] = p( r2, a0 );
		const [ x2, y2 ] = p( r2, a1 );
		const [ x3, y3 ] = p( r1, a1 );
		const [ x4, y4 ] = p( r1, a0 );
		return `M${ x1.toFixed( 2 ) } ${ y1.toFixed( 2 ) }A${ r2 } ${ r2 } 0 ${ large } 1 ${ x2.toFixed( 2 ) } ${ y2.toFixed( 2 ) }L${ x3.toFixed( 2 ) } ${ y3.toFixed( 2 ) }A${ r1 } ${ r1 } 0 ${ large } 0 ${ x4.toFixed( 2 ) } ${ y4.toFixed( 2 ) }Z`;
	}

	function plakette() {
		const list = checks().filter( ( c ) => c.status !== 'skip' );
		const n = list.length || 24;
		const gap = n > 40 ? 0.012 : 0.022;
		const step = ( Math.PI * 2 ) / n;
		const score = S.report ? S.report.scores.total : null;
		let segs = '';
		for ( let i = 0; i < n; i++ ) {
			const c = list[ i ];
			const a0 = -Math.PI / 2 + i * step + gap;
			const a1 = -Math.PI / 2 + ( i + 1 ) * step - gap;
			const cls = c ? 'is-' + severity( c ) : 'is-empty';
			const label = c ? ( c.status === 'pass' ? c.title + ': in Ordnung' : c.problem ) : '';
			segs += `<path class="plk-seg ${ cls }" d="${ arc( 110, 110, 80, 102, a0, a1 ) }" style="--i:${ i }"${ c ? ` data-action="jump" data-id="${ esc( c.id ) }"` : '' }><title>${ esc( label ) }</title></path>`;
		}
		const date = S.report ? new Date( S.report.time * 1000 ).toLocaleDateString( 'de-DE', { month: 'short', year: 'numeric' } ) : 'ungeprüft';
		return `<svg class="plk-plakette${ ui.stamp ? ' is-stamping' : '' }" viewBox="0 0 220 220" role="img" aria-label="${ score == null ? 'Noch nicht geprüft' : 'Gesamtergebnis ' + score + ' von 100 Punkten' }">
			<circle cx="110" cy="110" r="106" class="plk-plakette-rim"/>
			${ segs }
			<circle cx="110" cy="110" r="72" class="plk-plakette-face"/>
			<text x="110" y="120" class="plk-plakette-score" text-anchor="middle">${ score == null ? '–' : score }</text>
			<text x="110" y="146" class="plk-plakette-unit" text-anchor="middle">${ score == null ? '' : 'von 100' }</text>
			<text x="110" y="166" class="plk-plakette-date" text-anchor="middle">${ esc( date ) }</text>
		</svg>`;
	}

	function meterBar( label, value ) {
		return `<div class="plk-meter">
			<span class="plk-meter-label">${ label }</span>
			<span class="plk-meter-track" aria-hidden="true"><span class="plk-meter-fill" style="width:${ value }%"></span></span>
			<span class="plk-meter-value">${ value }</span>
		</div>`;
	}

	/* ------------------------------------------------------------ header */

	function header() {
		const r = S.report;
		if ( ui.busy === 'audit' ) {
			return `<header class="plk-head">${ plakette() }<div class="plk-head-text">
				<h2 class="plk-title">Prüfung läuft …</h2>
				<p class="plk-lead">Plakette ruft deine Startseite und einige Beiträge so auf, wie ein Besucher sie sieht, und prüft ${ S.report ? S.report.checks.length : 'über 70' } Punkte. Das dauert meist 10 bis 40 Sekunden. Dabei wird nichts verändert.</p>
				<div class="plk-progress" role="progressbar" aria-label="Prüfung läuft"><span></span></div>
			</div></header>`;
		}
		if ( ! r ) {
			return `<header class="plk-head is-empty">${ plakette() }<div class="plk-head-text">
				<h2 class="plk-title">Hauptuntersuchung für ${ esc( C.name || 'deine Website' ) }</h2>
				<p class="plk-lead">Plakette schaut sich deine Website an wie ein Besucher, wie Google und wie eine Datenschutz-Aufsicht: Datenschutz, Sicherheit und Recht, Geschwindigkeit und SEO – über 70 Prüfpunkte. Jeden Mangel erklärt Plakette in einfachen Worten, und für fast jeden gibt es einen Knopf, der ihn behebt. Jede Änderung lässt sich wieder rückgängig machen.</p>
				<p class="plk-lead-small">Die Prüfung selbst verändert nichts an deiner Website.</p>
				<div class="plk-actions"><button class="plk-btn is-primary is-large" data-action="audit">Website jetzt prüfen</button></div>
				${ ui.error ? `<p class="plk-error" role="alert">${ esc( ui.error ) }</p>` : '' }
			</div></header>`;
		}
		const count = { major: 0, minor: 0, note: 0, pass: 0 };
		checks().forEach( ( c ) => {
			const s = severity( c );
			if ( s in count ) {
				count[ s ]++;
			}
		} );
		const parts = [];
		if ( count.major ) {
			parts.push( `<b class="t-major">${ plural( count.major, 'erheblicher Mangel', 'erhebliche Mängel' ) }</b>` );
		}
		if ( count.minor ) {
			parts.push( `<b class="t-minor">${ plural( count.minor, 'geringer Mangel', 'geringe Mängel' ) }</b>` );
		}
		if ( count.note ) {
			parts.push( `<b class="t-note">${ plural( count.note, 'Hinweis', 'Hinweise' ) }</b>` );
		}
		parts.push( `${ plural( count.pass, 'Punkt', 'Punkte' ) } in Ordnung` );
		const summary = parts.length > 1 ? parts.slice( 0, -1 ).join( ', ' ) + ' und ' + parts[ parts.length - 1 ] : parts[ 0 ];
		const safe = safeList();
		const hist = S.history || [];
		const prev = hist.length > 1 ? hist[ hist.length - 2 ] : null;
		const verdict = count.major ? 'Mit erheblichen Mängeln' : count.minor ? 'Mit geringen Mängeln' : count.note ? 'Fast ohne Mängel' : 'Ohne Mängel';

		return `<header class="plk-head">${ plakette() }<div class="plk-head-text">
			<p class="plk-kicker">Prüfbericht für <a href="${ esc( r.site ) }" target="_blank" rel="noopener">${ esc( r.name || r.site ) }</a>, geprüft am ${ esc( when( r.time ) ) }</p>
			<h2 class="plk-title">${ verdict }</h2>
			<p class="plk-lead">${ summary }.${ prev ? ` <span class="plk-prev">Davor: ${ prev.total } Punkte.</span>` : '' }</p>
			<div class="plk-meters">${ Object.keys( areas() ).map( ( k ) => ( k in r.scores ? meterBar( areaLabel( k ), r.scores[ k ] ) : '' ) ).join( '' ) }</div>
			${ ! r.home.ok ? `<p class="plk-error" role="alert">Deine Startseite konnte nicht abgerufen werden${ r.home.error ? ' (' + esc( r.home.error ) + ')' : r.home.status ? ' (HTTP ' + r.home.status + ')' : '' }. Einige Prüfungen wurden deshalb übersprungen. Häufige Ursache: Der Server blockiert Anfragen an sich selbst („Loopback“). Frag deinen Hoster danach.</p>` : '' }
			<div class="plk-actions">
				${ safe.length ? `<button class="plk-btn is-primary" data-action="fix-all" ${ ui.busy ? 'disabled' : '' }>${ plural( safe.length, 'sichere Korrektur', 'sichere Korrekturen' ) } anwenden</button>` : '' }
				<button class="plk-btn" data-action="audit" ${ ui.busy ? 'disabled' : '' }>Neu prüfen</button>
			</div>
			${ ui.busy === 'all' ? `<p class="plk-progress-text" role="status">${ esc( ui.progress ) }</p>` : '' }
			${ safe.length && ! ui.busy ? `<p class="plk-hint">„Sicher“ heißt: Diese Änderungen passen für fast jede Website und lassen sich im Protokoll rückgängig machen. Punkte, die du dir vorher ansehen solltest, sind nicht dabei.</p>` : '' }
			${ ui.error ? `<p class="plk-error" role="alert">${ esc( ui.error ) }</p>` : '' }
		</div></header>`;
	}

	/* ------------------------------------------------------------ instruments */

	const METRICS = [
		{ key: 'lcp', label: 'Größter Inhalt sichtbar nach', good: 2500, poor: 4000, max: 6000, fmt: ms, help: 'Wann das größte Bild oder der größte Textblock erscheint. Google: gut bis 2,5 s.' },
		{ key: 'cls', label: 'Verrutschen beim Laden', good: 0.1, poor: 0.25, max: 0.5, fmt: ( v ) => ( v == null ? '–' : nf2.format( v ) ), help: 'Wie stark Inhalte springen, während die Seite lädt. Google: gut bis 0,10.' },
		{ key: 'ttfb', label: 'Erste Antwort des Servers', good: 800, poor: 1800, max: 2500, fmt: ms, help: 'Wie lange der Server braucht, bis er antwortet. Gut: unter 0,8 s.' },
		{ key: 'bytes', label: 'Seitengewicht', good: 1600000, poor: 3000000, max: 4000000, fmt: bytes, help: 'Alles, was für die Startseite geladen wird. Faustregel: unter 1,6 MB.' },
	];

	function rating( m, v ) {
		if ( v == null ) {
			return [ 'none', 'nicht gemessen' ];
		}
		if ( v <= m.good ) {
			return [ 'good', 'gut' ];
		}
		return v <= m.poor ? [ 'mid', 'verbesserungswürdig' ] : [ 'poor', 'schlecht' ];
	}

	function instruments() {
		const m = S.measure || {};
		const has = m && m.time;
		const values = {
			lcp: has ? m.lcp : null,
			cls: has ? m.cls : null,
			ttfb: has && m.ttfb != null ? m.ttfb : S.report && S.report.home.ok ? S.report.home.time_ms : null,
			bytes: has ? m.bytes : null,
		};
		const gauges = METRICS.map( ( def ) => {
			const v = values[ def.key ];
			const [ cls, word ] = rating( def, v );
			const pos = v == null ? 0 : Math.min( 100, ( v / def.max ) * 100 );
			const extra = def.key === 'bytes' && has && m.requests ? ` <span class="plk-gauge-sub">${ m.requests } Dateien</span>` : '';
			return `<div class="plk-gauge is-${ cls }">
				<div class="plk-gauge-label">${ def.label }</div>
				<div class="plk-gauge-value">${ def.fmt( v ) }${ extra }</div>
				<div class="plk-gauge-scale" aria-hidden="true">
					<span class="z-good" style="width:${ ( def.good / def.max ) * 100 }%"></span><span class="z-mid" style="width:${ ( ( def.poor - def.good ) / def.max ) * 100 }%"></span><span class="z-poor"></span>
					${ v == null ? '' : `<i style="left:${ pos }%"></i>` }
				</div>
				<div class="plk-gauge-word">${ word }</div>
				<p class="plk-gauge-help">${ def.help }</p>
			</div>`;
		} ).join( '' );

		const psi = S.psi;
		let psiHtml = '';
		if ( ui.busy === 'psi' ) {
			psiHtml = '<p class="plk-psi" role="status">Google prüft deine Seite. Das dauert etwa 20 bis 60 Sekunden …</p>';
		} else if ( ui.psiError ) {
			psiHtml = `<p class="plk-psi plk-error" role="alert">${ esc( ui.psiError ) }</p>`;
		} else if ( psi && psi.time ) {
			const f = psi.field || {};
			const fl = f.largest_contentful_paint_ms;
			psiHtml = `<p class="plk-psi">Google PageSpeed, Smartphone (${ esc( when( psi.time ) ) }): <b>Leistung ${ psi.performance }</b> und <b>SEO ${ psi.seo }</b> von 100, größter Inhalt nach ${ ms( psi.lcp ) }.${ fl ? ` Bei echten Besuchern der letzten 28 Tage: ${ ms( fl.p75 ) }.` : '' }</p>`;
		}

		const webUrl = 'https://pagespeed.web.dev/analysis?url=' + encodeURIComponent( C.home ) + '&form_factor=mobile';
		if ( ui.psiError || ( ! psi && ! S.psiKey && ui.busy !== 'psi' && ui.showKey ) ) {
			psiHtml += `<div class="plk-psi-help">
				<p><a href="${ esc( webUrl ) }" target="_blank" rel="noopener">Prüfung direkt bei Google PageSpeed öffnen<span class="screen-reader-text"> (neues Fenster)</span></a> – klappt immer, das Ergebnis siehst du dort.</p>
				<details${ ui.psiError ? ' open' : '' }><summary>Ergebnis lieber hier sehen? Eigenen Google-Schlüssel eintragen</summary>
					<p>Google vergibt kostenlose Schlüssel für diese Abfrage. So geht es: Auf <a href="https://developers.google.com/speed/docs/insights/v5/get-started#APIKey" target="_blank" rel="noopener">developers.google.com</a> auf „Get a Key“ klicken, mit deinem Google-Konto anmelden, den Schlüssel kopieren und hier einfügen.</p>
					<div class="plk-form"><label for="plk-psi-key">Google-API-Schlüssel</label><input type="text" id="plk-psi-key" autocomplete="off" spellcheck="false" placeholder="AIza…"><button class="plk-btn is-primary" data-action="psi-key" ${ ui.busy ? 'disabled' : '' }>Schlüssel speichern und prüfen</button></div>
				</details>
			</div>`;
		}

		const measuredText = has
			? `Gemessen am ${ esc( when( m.time ) ) } in deinem Browser, im Format eines Smartphones. Werte über deine Internetverbindung – im Mobilfunknetz ist es langsamer.`
			: 'Noch nicht im Browser gemessen. Plakette öffnet dazu deine Startseite im Format eines Smartphones und misst, wie schnell sie erscheint.';

		return `<section class="plk-panel plk-instruments" aria-labelledby="plk-instr-h">
			<div class="plk-panel-head">
				<h2 id="plk-instr-h">Messung auf dem Smartphone</h2>
				<div class="plk-actions">
					<button class="plk-btn ${ has ? '' : 'is-primary' }" data-action="measure" ${ ui.busy ? 'disabled' : '' }>${ has ? 'Erneut messen' : 'Ladezeit messen' }</button>
					<button class="plk-btn is-quiet" data-action="psi" ${ ui.busy ? 'disabled' : '' }>Zweitmeinung von Google</button>
				</div>
			</div>
			<p class="plk-panel-note">${ measuredText }</p>
			<div class="plk-instr-body">
				<div class="plk-gauges">${ gauges }</div>
				${ ui.measuring ? `<figure class="plk-phone"><div class="plk-phone-screen"><iframe title="Startseite wird gemessen" id="plk-frame" width="390" height="780"></iframe></div><figcaption role="status">Startseite wird geladen und gemessen …</figcaption></figure>` : '' }
			</div>
			${ psiHtml }
		</section>`;
	}

	/* ------------------------------------------------------------ list */

	const GROUPS = [
		{ key: 'major', title: 'Erhebliche Mängel', note: 'Diese Punkte sind ein Risiko – rechtlich, für die Sicherheit, bei Google oder für die Ladezeit. Kümmere dich zuerst um sie.' },
		{ key: 'minor', title: 'Geringe Mängel', note: 'Kleinere Verbesserungen, die sich trotzdem lohnen.' },
		{ key: 'note', title: 'Hinweise', note: 'Kein Fehler, aber hier ist noch Luft nach oben.' },
	];

	function list() {
		if ( ! S.report || ui.busy === 'audit' ) {
			return '';
		}
		const visible = checks().filter( ( c ) => ui.filter === 'all' || c.area === ui.filter );
		const tab = ( key, label ) => `<button class="plk-tab" aria-pressed="${ ui.filter === key }" data-action="filter" data-filter="${ key }">${ label }</button>`;
		let html = `<section class="plk-panel plk-report" aria-labelledby="plk-list-h">
			<div class="plk-panel-head"><h2 id="plk-list-h">Mängelliste</h2><div class="plk-tabs" role="group" aria-label="Bereich">${ tab( 'all', 'Alles' ) }${ Object.keys( areas() ).map( ( k ) => tab( k, areaLabel( k ) ) ).join( '' ) }</div></div>`;

		let problems = 0;
		GROUPS.forEach( ( g ) => {
			const rows = visible.filter( ( c ) => severity( c ) === g.key );
			if ( ! rows.length ) {
				return;
			}
			problems += rows.length;
			html += `<div class="plk-group is-${ g.key }"><h3 class="plk-group-title">${ g.title } <span class="plk-count">${ rows.length }</span></h3><p class="plk-group-note">${ g.note }</p><ul class="plk-rows">${ rows.map( row ).join( '' ) }</ul></div>`;
		} );
		if ( ! problems ) {
			html += '<p class="plk-clean">In diesem Bereich wurde kein Mangel gefunden. Prüfe nach größeren Änderungen an deiner Website einfach noch einmal.</p>';
		}
		const pass = visible.filter( ( c ) => c.status === 'pass' );
		const skip = visible.filter( ( c ) => c.status === 'skip' );
		if ( pass.length ) {
			html += `<div class="plk-group is-pass"><h3 class="plk-group-title"><button class="plk-fold" aria-expanded="${ ui.showPass }" data-action="show-pass">In Ordnung <span class="plk-count">${ pass.length }</span></button></h3>${ ui.showPass ? `<ul class="plk-rows">${ pass.map( row ).join( '' ) }</ul>` : '' }</div>`;
		}
		if ( skip.length ) {
			html += `<div class="plk-group is-skip"><h3 class="plk-group-title"><button class="plk-fold" aria-expanded="${ ui.showSkip }" data-action="show-skip">Nicht geprüft <span class="plk-count">${ skip.length }</span></button></h3>${ ui.showSkip ? `<ul class="plk-rows">${ skip.map( row ).join( '' ) }</ul>` : '' }</div>`;
		}
		return html + '</section>';
	}

	// Only major problems start expanded; everything else opens with one click.
	const openByDefault = ( c ) => severity( c ) === 'major';
	function isOpen( c ) {
		return openByDefault( c ) ? ! ui.closed.has( c.id ) : ui.opened.has( c.id );
	}

	function row( c ) {
		const sev = severity( c );
		const open = isOpen( c );
		const k = kindOf( c );
		const title = c.status === 'pass' || c.status === 'skip' ? c.title : c.problem;
		const busy = ui.busy === c.id;
		const quick = open || ! fixable( c ) || k !== 'auto' ? '' : `<button class="plk-btn is-small" data-action="fix" data-id="${ esc( c.id ) }" ${ ui.busy ? 'disabled' : '' }>${ esc( c.fix_label ) }</button>`;
		return `<li class="plk-row is-${ sev }${ open ? ' is-open' : '' }" id="plk-row-${ esc( c.id ) }">
			<div class="plk-row-head">
				<button class="plk-row-toggle" aria-expanded="${ open }" aria-controls="plk-body-${ esc( c.id ) }" data-action="toggle" data-id="${ esc( c.id ) }">
					<span class="plk-mark" aria-hidden="true"></span>
					<span class="plk-row-text"><span class="plk-row-title">${ esc( title ) }</span><span class="plk-row-detail">${ esc( c.detail ) }</span></span>
				</button>
				<span class="plk-area is-${ esc( c.area ) }" title="${ esc( c.group || '' ) }">${ esc( areaLabel( c.area ) ) }</span>
				${ quick }
			</div>
			${ open ? `<div class="plk-row-body" id="plk-body-${ esc( c.id ) }">${ body( c ) }${ busy ? '<p class="plk-working" role="status">Wird erledigt …</p>' : '' }</div>` : '' }
		</li>`;
	}

	function body( c ) {
		const k = kindOf( c );
		let html = `<div class="plk-explain"><p>${ esc( c.explain ) }</p>`;
		if ( c.notes && c.notes.length ) {
			html += `<ul class="plk-notes">${ c.notes.map( ( n ) => `<li>${ esc( n ) }</li>` ).join( '' ) }</ul>`;
		}
		if ( c.links && c.links.length ) {
			html += `<p class="plk-links">${ c.links.map( ( l ) => `<a href="${ esc( l.url ) }" target="_blank" rel="noopener">${ esc( l.label ) }<span class="screen-reader-text"> (neues Fenster)</span></a>` ).join( ' · ' ) }</p>`;
		}
		if ( ! fixable( c ) ) {
			if ( c.blocked ) {
				html += `<p class="plk-blocked"><b>So behebst du es:</b> ${ esc( c.blocked ) }</p>`;
			}
			if ( c.status === 'pass' && c.letter ) {
				html += '';
			}
			return html + '</div>';
		}
		html += `<p class="plk-does"><b>${ k === 'letter' ? 'So geht es:' : 'Was der Knopf ändert:' }</b> ${ esc( c.fix_does ) }</p>`;
		if ( k !== 'letter' ) {
			html += c.undoable === false
				? `<p class="plk-risk is-hard">${ esc( c.undo_note || 'Achtung: Gelöschtes lässt sich nicht wiederherstellen. Lege vorher eine Sicherung an, wenn du unsicher bist.' ) }</p>`
				: c.risk === 'review'
					? '<p class="plk-risk is-review">Schau dir deine Website danach kurz an. Gefällt dir etwas nicht, machst du es im Protokoll mit einem Klick rückgängig.</p>'
					: '<p class="plk-risk">Lässt sich im Protokoll jederzeit rückgängig machen.</p>';
		}
		html += '</div>';

		const disabled = ui.busy ? 'disabled' : '';
		if ( k === 'letter' ) {
			return html + `<div class="plk-letter"><label for="plk-letter-${ esc( c.id ) }">Nachricht an deinen Hoster</label><textarea id="plk-letter-${ esc( c.id ) }" readonly rows="11">${ esc( c.letter || '' ) }</textarea><button class="plk-btn is-primary" data-action="copy" data-id="${ esc( c.id ) }">Nachricht kopieren</button></div>`;
		}
		if ( k === 'input' && c.input ) {
			const d = draft( c );
			const max = c.input.max || 160;
			const field = c.input.multiline
				? `<textarea id="plk-in-${ esc( c.id ) }" rows="3" maxlength="${ max + 40 }" data-input="${ esc( c.id ) }" placeholder="${ esc( c.input.placeholder || '' ) }">${ esc( d.value ) }</textarea>`
				: `<input type="text" id="plk-in-${ esc( c.id ) }" maxlength="${ max + 40 }" data-input="${ esc( c.id ) }" value="${ esc( d.value ) }" placeholder="${ esc( c.input.placeholder || '' ) }">`;
			return html + `<div class="plk-form"><label for="plk-in-${ esc( c.id ) }">${ esc( c.input.label ) }</label>${ field }<span class="plk-counter${ d.value.length > max ? ' is-over' : '' }" data-counter="${ esc( c.id ) }">${ d.value.length } von ${ max } Zeichen</span><button class="plk-btn is-primary" data-action="fix" data-id="${ esc( c.id ) }" ${ disabled }>${ esc( c.fix_label ) }</button></div>`;
		}
		if ( k === 'items' ) {
			return html + itemsForm( c, disabled );
		}
		if ( k === 'form' && c.form ) {
			const scope = 'check:' + c.id;
			const vals = formValues( scope, c.form.fields || [] );
			return html + `<div class="plk-form plk-fields">${ ( c.form.intro ? `<p class="plk-help">${ esc( c.form.intro ) }</p>` : '' ) + ( c.form.fields || [] ).map( ( f ) => fieldHtml( scope, f, vals[ f.key ] ) ).join( '' ) }<button class="plk-btn is-primary" data-action="fix" data-id="${ esc( c.id ) }" ${ disabled }>${ esc( c.fix_label ) }</button></div>`;
		}
		return html + `<div class="plk-form"><button class="plk-btn is-primary" data-action="fix" data-id="${ esc( c.id ) }" ${ disabled }>${ esc( c.fix_label ) }</button></div>`;
	}

	function itemsForm( c, disabled ) {
		const d = draft( c );
		const items = c.items || [];
		const shown = d.expanded ? items : items.slice( 0, 8 );
		const selected = items.filter( ( it ) => d.items[ it.id ] && d.items[ it.id ].checked ).length;
		const f = c.item_field;
		const lis = shown.map( ( it ) => {
			const st = d.items[ it.id ] || { value: it.value || '', checked: true };
			const fid = `plk-it-${ c.id }-${ it.id }`.replace( /[^a-z0-9_-]/gi, '-' );
			const input = f
				? f.multiline
					? `<textarea id="${ fid }" rows="2" maxlength="${ ( f.max || 160 ) + 40 }" data-item="${ esc( it.id ) }" data-check="${ esc( c.id ) }" aria-label="${ esc( f.label ) }: ${ esc( it.label ) }">${ esc( st.value ) }</textarea>`
					: `<input id="${ fid }" type="text" maxlength="${ ( f.max || 160 ) + 40 }" data-item="${ esc( it.id ) }" data-check="${ esc( c.id ) }" value="${ esc( st.value ) }" aria-label="${ esc( f.label ) }: ${ esc( it.label ) }">`
				: '';
			return `<li class="plk-item">
				<input type="checkbox" ${ st.checked ? 'checked' : '' } data-pick="${ esc( it.id ) }" data-check="${ esc( c.id ) }" aria-label="${ esc( it.label ) } auswählen">
				${ it.thumb ? `<img src="${ esc( it.thumb ) }" alt="" loading="lazy" width="48" height="48">` : '' }
				<div class="plk-item-main">
					<div class="plk-item-label">${ esc( it.label ) }${ it.link ? ` <a href="${ esc( it.link ) }" target="_blank" rel="noopener">ansehen<span class="screen-reader-text"> (neues Fenster)</span></a>` : '' }${ it.edit ? ` <a href="${ esc( it.edit ) }" target="_blank" rel="noopener">bearbeiten<span class="screen-reader-text"> (neues Fenster)</span></a>` : '' }</div>
					<div class="plk-item-sub">${ esc( it.sub || '' ) }</div>
					${ input }
				</div>
			</li>`;
		} ).join( '' );
		return `<div class="plk-items">
			${ f ? `<p class="plk-items-field">${ esc( f.label ) }</p>` : '' }
			<ul>${ lis }</ul>
			<div class="plk-items-foot">
				${ items.length > 8 && ! d.expanded ? `<button class="plk-btn is-quiet" data-action="expand" data-id="${ esc( c.id ) }">Alle ${ items.length } anzeigen</button>` : '' }
				<button class="plk-btn is-quiet" data-action="pick-all" data-id="${ esc( c.id ) }">${ selected === items.length ? 'Keine auswählen' : 'Alle auswählen' }</button>
				<button class="plk-btn is-primary" data-action="fix" data-id="${ esc( c.id ) }" ${ disabled || ! selected ? 'disabled' : '' }>${ esc( c.fix_label ) } (${ selected })</button>
			</div>
		</div>`;
	}

	/* ------------------------------------------------------------ fields (forms in checks and settings) */

	function formValues( scope, fields ) {
		if ( ! ui.forms[ scope ] ) {
			const v = {};
			fields.forEach( ( f ) => {
				v[ f.key ] = f.value == null ? ( f.default == null ? '' : f.default ) : f.value;
			} );
			ui.forms[ scope ] = v;
		}
		return ui.forms[ scope ];
	}

	function fieldHtml( scope, f, value ) {
		const id = `plk-f-${ scope }-${ f.key }`.replace( /[^a-z0-9_-]/gi, '-' );
		const attrs = `id="${ id }" data-field="${ esc( f.key ) }" data-scope="${ esc( scope ) }"`;
		const type = f.type || 'text';
		const ph = f.placeholder ? ` placeholder="${ esc( f.placeholder ) }"` : '';
		const help = f.help ? `<p class="plk-help" id="${ id }-help">${ esc( f.help ) }</p>` : '';
		const described = f.help ? ` aria-describedby="${ id }-help"` : '';
		const label = `<label for="${ id }">${ esc( f.label ) }${ f.required ? ' <span class="plk-req" aria-hidden="true">*</span>' : '' }</label>`;
		const v = value == null ? '' : value;
		if ( type === 'toggle' ) {
			return `<div class="plk-field is-toggle"><label class="plk-switch"><input type="checkbox" ${ attrs }${ described } ${ v && v !== '0' ? 'checked' : '' }><span>${ esc( f.label ) }</span></label>${ help }</div>`;
		}
		if ( type === 'info' ) {
			return `<div class="plk-field is-info">${ f.label ? `<p class="plk-field-label">${ esc( f.label ) }</p>` : '' }<div class="plk-field-info">${ f.html || '' }</div>${ help }</div>`;
		}
		let input;
		if ( type === 'textarea' ) {
			input = `<textarea ${ attrs } rows="${ f.rows || 3 }"${ ph }${ described }${ f.required ? ' required' : '' }>${ esc( v ) }</textarea>`;
		} else if ( type === 'select' ) {
			const opts = Object.keys( f.options || {} ).map( ( o ) => `<option value="${ esc( o ) }" ${ String( v ) === o ? 'selected' : '' }>${ esc( f.options[ o ] ) }</option>` ).join( '' );
			input = `<select ${ attrs }${ described }>${ opts }</select>`;
		} else {
			const t = [ 'email', 'url', 'tel', 'number' ].indexOf( type ) >= 0 ? type : 'text';
			input = `<input type="${ t }" ${ attrs } value="${ esc( v ) }"${ ph }${ described }${ f.required ? ' required' : '' }>`;
		}
		return `<div class="plk-field is-${ esc( type ) }">${ label }${ input }${ help }</div>`;
	}

	/* ------------------------------------------------------------ settings */

	function settingsSection() {
		const sections = S.settings || [];
		const up = S.updater || {};
		const upText = up.latest
			? ( up.latest === up.installed ? `Version ${ esc( up.installed ) } – das ist die neueste.` : `Installiert: ${ esc( up.installed ) }, auf GitHub: <b>${ esc( up.latest ) }</b>. Das Update erscheint unter Plugins.` )
			: `Installiert: Version ${ esc( up.installed || '' ) }.${ up.checked ? ' GitHub war zuletzt nicht erreichbar oder es gibt noch kein Release.' : '' }`;
		const blocks = sections.map( ( sec ) => {
			const scope = 'set:' + sec.id;
			const vals = formValues( scope, sec.fields );
			const open = ui.openSettings.has( sec.id );
			return `<div class="plk-set${ open ? ' is-open' : '' }">
				<h3><button class="plk-fold" aria-expanded="${ open }" data-action="settings-toggle" data-id="${ esc( sec.id ) }">${ esc( sec.title ) }</button></h3>
				${ open ? `<div class="plk-set-body">${ sec.desc ? `<p class="plk-panel-note">${ esc( sec.desc ) }</p>` : '' }
					${ sec.id === 'general' ? `<p class="plk-update">${ upText } <button class="plk-btn is-small is-quiet" data-action="update-check" ${ ui.busy ? 'disabled' : '' }>Jetzt nachsehen</button> <a href="${ esc( up.repo || '#' ) }" target="_blank" rel="noopener">GitHub<span class="screen-reader-text"> (neues Fenster)</span></a></p>` : '' }
					<div class="plk-fields">${ sec.fields.map( ( f ) => fieldHtml( scope, f, vals[ f.key ] ) ).join( '' ) }</div>
					<button class="plk-btn is-primary" data-action="save-settings" data-id="${ esc( sec.id ) }" ${ ui.busy ? 'disabled' : '' }>Speichern</button>
				</div>` : '' }
			</div>`;
		} ).join( '' );
		return `<section class="plk-panel plk-settings" aria-labelledby="plk-set-h">
			<div class="plk-panel-head"><h2 id="plk-set-h">Einstellungen</h2></div>
			${ blocks }
		</section>`;
	}

	async function saveSettings( section ) {
		ui.busy = 'settings';
		render();
		try {
			const res = await api( '/settings', 'POST', { section, values: ui.forms[ 'set:' + section ] || {} } );
			setState( res );
			delete ui.forms[ 'set:' + section ];
			Object.keys( ui.forms ).forEach( ( k ) => {
				if ( k.indexOf( 'check:' ) === 0 ) {
					delete ui.forms[ k ];
				}
			} );
			toast( res.message || 'Gespeichert.' );
		} catch ( e ) {
			toast( e.message, null, true );
		}
		ui.busy = null;
		render();
	}

	async function updateCheck() {
		ui.busy = 'update';
		render();
		try {
			setState( await api( '/update-check', 'POST', {} ) );
		} catch ( e ) {
			toast( e.message, null, true );
		}
		ui.busy = null;
		render();
	}

	/* ------------------------------------------------------------ log */

	function logSection() {
		const runs = S.log || [];
		if ( ! S.report ) {
			return '';
		}
		const items = runs.map( ( r ) => {
			const d = new Date( r.created.replace( ' ', 'T' ) );
			const time = isNaN( d ) ? r.created : d.toLocaleDateString( 'de-DE', { day: 'numeric', month: 'short' } ) + ', ' + d.toLocaleTimeString( 'de-DE', { hour: '2-digit', minute: '2-digit' } );
			const action = r.undone
				? '<span class="plk-undone">rückgängig gemacht</span>'
				: r.undoable
					? `<button class="plk-btn is-small is-quiet" data-action="undo" data-run="${ esc( r.run_id ) }" data-check="${ esc( r.check_id ) }" ${ ui.busy ? 'disabled' : '' }>Rückgängig</button>`
					: '<span class="plk-undone">endgültig</span>';
			return `<li class="${ r.undone ? 'is-undone' : '' }"><time>${ esc( time ) }</time><span>${ r.labels.map( esc ).join( '<br>' ) }</span>${ action }</li>`;
		} ).join( '' );
		return `<section class="plk-panel plk-log" aria-labelledby="plk-log-h">
			<div class="plk-panel-head"><h2 id="plk-log-h">Protokoll</h2></div>
			<p class="plk-panel-note">Jede Änderung, die Plakette vornimmt, steht hier. Mit „Rückgängig“ stellst du den vorherigen Zustand wieder her.</p>
			${ runs.length ? `<ol class="plk-log-list">${ items }</ol>` : '<p class="plk-empty">Noch keine Änderungen. Sobald du einen Mangel behebst, erscheint er hier.</p>' }
		</section>`;
	}

	/* ------------------------------------------------------------ toast */

	let toastTimer = null;
	function toast( message, undo, isError ) {
		let el = document.getElementById( 'plk-toast' );
		if ( ! el ) {
			el = document.createElement( 'div' );
			el.id = 'plk-toast';
			el.className = 'plk-toast';
			el.setAttribute( 'role', 'status' );
			document.body.appendChild( el );
			el.addEventListener( 'click', onClick );
		}
		el.classList.toggle( 'is-error', !! isError );
		el.innerHTML = `<span>${ esc( message ) }</span>${ undo ? `<button class="plk-btn is-small is-quiet" data-action="undo" data-run="${ esc( undo.run ) }" data-check="${ esc( undo.check ) }">Rückgängig</button>` : '' }<button class="plk-toast-close" data-action="toast-close" aria-label="Meldung schließen">×</button>`;
		el.classList.add( 'is-visible' );
		const wait = undo || isError ? 20000 : 9000;
		const hide = () => {
			clearTimeout( toastTimer );
			toastTimer = setTimeout( () => {
				// Stay while the pointer or keyboard focus is on the message.
				if ( el.matches( ':hover' ) || el.contains( document.activeElement ) ) {
					hide();
				} else {
					el.classList.remove( 'is-visible' );
				}
			}, wait );
		};
		hide();
	}

	/* ------------------------------------------------------------ render */

	function render() {
		const focusId = document.activeElement && document.activeElement.id;
		const focusAction = document.activeElement && document.activeElement.dataset ? document.activeElement.dataset.action + '|' + ( document.activeElement.dataset.id || '' ) : '';
		app.innerHTML = header() + instruments() + privacyPanel() + list() + statsPanel() + logSection() + settingsSection();
		let target = focusId ? document.getElementById( focusId ) : null;
		if ( ! target && focusAction ) {
			const [ a, id ] = focusAction.split( '|' );
			target = app.querySelector( `[data-action="${ a }"]${ id ? `[data-id="${ CSS.escape( id ) }"]` : '' }` );
		}
		if ( target && target.focus ) {
			target.focus( { preventScroll: true } );
		}
		if ( ui.stamp ) {
			setTimeout( () => {
				ui.stamp = false;
			}, 50 );
		}
	}

	/* ------------------------------------------------------------ actions */

	async function runAudit() {
		ui.busy = 'audit';
		ui.error = '';
		render();
		try {
			setState( await api( '/audit', 'POST', {} ) );
			ui.drafts = {};
			ui.stamp = true;
		} catch ( e ) {
			ui.error = 'Die Prüfung ist fehlgeschlagen: ' + e.message;
		}
		ui.busy = null;
		render();
	}

	/** Applies one check's fix. Items are sent in batches; all batches share one protocol entry. */
	async function applyFix( c, opts ) {
		opts = opts || {};
		const run = runId();
		const k = kindOf( c );
		let result = null;
		if ( k === 'items' ) {
			const d = draft( c );
			const picked = ( c.items || [] )
				.filter( ( it ) => ( opts.allItems ? true : d.items[ it.id ] && d.items[ it.id ].checked ) )
				.map( ( it ) => ( { id: it.id, value: d.items[ it.id ] ? d.items[ it.id ].value : it.value || '' } ) );
			const size = c.batch || 50;
			for ( let i = 0; i < picked.length; i += size ) {
				if ( picked.length > size ) {
					ui.progress = ( opts.prefix || '' ) + `${ c.fix_label }: ${ Math.min( i + size, picked.length ) } von ${ picked.length }`;
					updateProgress();
				}
				result = await api( '/fix', 'POST', { check: c.id, run, items: picked.slice( i, i + size ), refresh: i + size >= picked.length } );
			}
		} else {
			const body = { check: c.id, run };
			if ( k === 'input' ) {
				body.value = draft( c ).value;
			}
			if ( k === 'form' ) {
				body.value = formValues( 'check:' + c.id, ( c.form && c.form.fields ) || [] );
			}
			result = await api( '/fix', 'POST', body );
		}
		if ( result ) {
			setState( result );
			delete ui.drafts[ c.id ];
			delete ui.forms[ 'check:' + c.id ];
			Object.keys( ui.forms ).forEach( ( key ) => {
				if ( key.indexOf( 'set:' ) === 0 ) {
					delete ui.forms[ key ]; // a form in a check may have changed settings (e.g. company data)
				}
			} );
		}
		return result;
	}

	function updateProgress() {
		const el = app.querySelector( '.plk-progress-text' );
		if ( el ) {
			el.textContent = ui.progress;
		}
	}

	async function fixOne( id ) {
		const c = byId( id );
		if ( ! c ) {
			return;
		}
		ui.busy = id;
		ui.progress = '';
		render();
		try {
			const res = await applyFix( c );
			const after = byId( id );
			toast( res.message, { run: res.run, check: id } );
			if ( after && after.status === 'pass' ) {
				ui.opened.delete( id );
				ui.closed.delete( id );
			}
		} catch ( e ) {
			toast( e.message, null, true );
		}
		ui.busy = null;
		render();
	}

	async function fixAll() {
		const list = safeList();
		ui.busy = 'all';
		ui.error = '';
		let done = 0;
		const failed = [];
		for ( let i = 0; i < list.length; i++ ) {
			const c = byId( list[ i ].id ) || list[ i ];
			ui.progress = `${ i + 1 } von ${ list.length }: ${ c.fix_label }`;
			render();
			try {
				await applyFix( c, { allItems: true, prefix: `${ i + 1 } von ${ list.length }: ` } );
				done++;
			} catch ( e ) {
				failed.push( c.title + ': ' + e.message );
			}
		}
		ui.progress = 'Alles erledigt. Plakette prüft deine Website noch einmal …';
		render();
		try {
			setState( await api( '/audit', 'POST', {} ) );
			ui.stamp = true;
		} catch ( e ) {
			// The individual results are already shown.
		}
		ui.busy = null;
		render();
		if ( failed.length ) {
			ui.error = 'Nicht geklappt: ' + failed.join( ' ' );
			render();
		}
		toast( `${ plural( done, 'Korrektur', 'Korrekturen' ) } angewendet. Alles steht im Protokoll und lässt sich einzeln rückgängig machen.` );
	}

	async function undo( run, check ) {
		ui.busy = 'undo';
		render();
		try {
			const res = await api( '/undo', 'POST', { run, check } );
			setState( res );
			toast( res.message, null, res.ok === false );
		} catch ( e ) {
			toast( e.message, null, true );
		}
		ui.busy = null;
		render();
	}

	async function psi( key ) {
		ui.busy = 'psi';
		ui.psiError = '';
		render();
		try {
			setState( await api( '/psi', 'POST', key ? { key } : {} ) );
		} catch ( e ) {
			ui.psiError = e.message;
		}
		ui.busy = null;
		render();
	}

	/**
	 * Loads the home page in a visible phone-sized frame and reads the browser's own measurements:
	 * Largest Contentful Paint, Cumulative Layout Shift, First Contentful Paint, server response, all transferred bytes.
	 */
	async function measure() {
		ui.busy = 'measure';
		ui.measuring = true;
		render();
		const frame = document.getElementById( 'plk-frame' );
		const url = C.home + ( C.home.indexOf( '?' ) === -1 ? '?' : '&' ) + 'plakette_measure=' + Date.now();
		try {
			const data = await new Promise( ( resolve, reject ) => {
				const timer = setTimeout( () => reject( new Error( 'Die Startseite hat nicht innerhalb von 45 Sekunden fertig geladen.' ) ), 45000 );
				frame.addEventListener( 'load', () => {
					// Give late images and layout shifts a moment.
					setTimeout( () => {
						clearTimeout( timer );
						try {
							resolve( collect( frame.contentWindow ) );
						} catch ( e ) {
							reject( new Error( 'Die Seite ließ sich nicht auswerten: ' + e.message ) );
						}
					}, 3000 );
				}, { once: true } );
				frame.src = url;
			} );
			data.url = C.home;
			setState( await api( '/measure', 'POST', data ) );
			toast( 'Messung abgeschlossen.' );
		} catch ( e ) {
			toast( e.message, null, true );
		}
		ui.busy = null;
		ui.measuring = false;
		render();
	}

	function collect( w ) {
		const perf = w.performance;
		const nav = perf.getEntriesByType( 'navigation' )[ 0 ] || {};
		const out = { lcp: null, cls: 0, fcp: null, ttfb: nav.responseStart || null, load: nav.loadEventEnd || nav.duration || null, bytes: 0, requests: 1, blocking: 0, byType: {}, lcpTag: '', lcpImage: null };
		const paint = perf.getEntriesByName( 'first-contentful-paint' )[ 0 ];
		out.fcp = paint ? paint.startTime : null;

		const read = ( type ) => {
			let entries = [];
			try {
				const po = new w.PerformanceObserver( () => {} );
				po.observe( { type, buffered: true } );
				entries = po.takeRecords();
				po.disconnect();
			} catch ( e ) {}
			return entries;
		};
		const lcps = read( 'largest-contentful-paint' );
		const last = lcps[ lcps.length - 1 ];
		if ( last ) {
			out.lcp = last.renderTime || last.loadTime || last.startTime;
			const el = last.element;
			out.lcpTag = el ? el.tagName.toLowerCase() : '';
			if ( el && el.tagName === 'IMG' ) {
				out.lcpImage = {
					url: el.currentSrc || el.src,
					srcset: el.getAttribute( 'srcset' ) || '',
					sizes: el.getAttribute( 'sizes' ) || '',
					loading: ( el.getAttribute( 'loading' ) || '' ).toLowerCase(),
					priority: ( el.getAttribute( 'fetchpriority' ) || '' ).toLowerCase(),
				};
			} else if ( last.url ) {
				out.lcpImage = { url: last.url, srcset: '', sizes: '', loading: '', priority: '' };
			}
		}
		read( 'layout-shift' ).forEach( ( e ) => {
			if ( ! e.hadRecentInput ) {
				out.cls += e.value;
			}
		} );
		out.cls = Math.round( out.cls * 1000 ) / 1000;

		out.bytes = nav.transferSize || nav.encodedBodySize || 0;
		perf.getEntriesByType( 'resource' ).forEach( ( r ) => {
			const size = r.transferSize || r.encodedBodySize || 0;
			out.bytes += size;
			out.requests++;
			out.byType[ r.initiatorType ] = ( out.byType[ r.initiatorType ] || 0 ) + size;
			if ( r.renderBlockingStatus === 'blocking' ) {
				out.blocking++;
			}
		} );
		return out;
	}


	/* ------------------------------------------------------------ privacy measurement (browser probe) */

	function privacyPanel() {
		const p = S.probe;
		const fresh = S.probeSetup && S.probeSetup.fresh;
		let summary = 'Noch nicht gemessen. Plakette öffnet deine Startseite und einige Unterseiten wie ein Erstbesucher – ohne Klick – und schaut nach, was der Browser speichert und welche fremden Server er kontaktiert. Das ist der Maßstab der Datenschutz-Aufsicht.';
		if ( p && p.time ) {
			summary = `Gemessen am ${ esc( when( p.time ) ) } auf ${ plural( ( p.pages || [] ).length, 'Seite', 'Seiten' ) }: <b>${ plural( ( p.cookies || [] ).length, 'neues Cookie', 'neue Cookies' ) }</b>, <b>${ plural( ( p.storage || [] ).length, 'Speicher-Eintrag', 'Speicher-Einträge' ) }</b>, <b>${ plural( ( p.hosts || [] ).length, 'fremder Server', 'fremde Server' ) }</b>.${ fresh ? '' : ' <span class="plk-stale">Seitdem hat sich etwas geändert – miss erneut.</span>' }`;
		}
		let details = '';
		if ( p && p.time && ( ( p.cookies || [] ).length || ( p.storage || [] ).length || ( p.hosts || [] ).length ) ) {
			const path = ( u ) => {
				try {
					const x = new URL( u );
					return x.pathname + x.search.replace( /[?&]plakette_[a-z]+=[^&]*/g, '' );
				} catch ( e ) {
					return u;
				}
			};
			const rows = [].concat(
				( p.cookies || [] ).map( ( c ) => [ 'Cookie', c.name, path( c.page ) ] ),
				( p.storage || [] ).map( ( st ) => [ { local: 'localStorage', session: 'sessionStorage', idb: 'IndexedDB' }[ st.area ] || st.area, st.key, path( st.page ) ] ),
				( p.hosts || [] ).map( ( h ) => [ 'Fremder Server', h.host + ( h.type ? ' (' + h.type + ')' : '' ), path( h.page ) ] )
			);
			details = `<details class="plk-probe-raw"><summary>Rohdaten ansehen (${ rows.length })</summary><table class="plk-table"><thead><tr><th scope="col">Art</th><th scope="col">Name</th><th scope="col">Seite</th></tr></thead><tbody>${ rows.map( ( r ) => `<tr><td>${ esc( r[ 0 ] ) }</td><td><code>${ esc( r[ 1 ] ) }</code></td><td>${ esc( r[ 2 ] ) }</td></tr>` ).join( '' ) }</tbody></table><p class="plk-help">Die Bewertung jedes Eintrags steht beim Prüfpunkt „Kein Cookie-Banner nötig“.</p></details>`;
		}
		return `<section class="plk-panel plk-probe" aria-labelledby="plk-probe-h">
			<div class="plk-panel-head">
				<h2 id="plk-probe-h">Datenschutz-Messung: Braucht deine Website einen Cookie-Banner?</h2>
				<div class="plk-actions">
					<button class="plk-btn ${ p ? '' : 'is-primary' }" data-action="probe" ${ ui.busy ? 'disabled' : '' }>${ p ? 'Erneut messen' : 'Datenschutz-Messung starten' }</button>
					${ byId( 'privacy_consent' ) ? '<button class="plk-btn is-quiet" data-action="jump" data-id="privacy_consent">Zum Ergebnis</button>' : '' }
				</div>
			</div>
			<p class="plk-panel-note">${ summary }</p>
			${ details }
			${ ui.probing ? `<figure class="plk-phone plk-probe-frame"><div class="plk-phone-screen"><iframe title="Seite wird wie von einem Erstbesucher geladen" id="plk-probe-frame" width="390" height="600"></iframe></div><figcaption role="status">${ esc( ui.probeStep ) }</figcaption></figure>` : '' }
		</section>`;
	}

	const sleep = ( t ) => new Promise( ( r ) => setTimeout( r, t ) );

	function cookieMap() {
		const out = {};
		document.cookie.split( ';' ).forEach( ( part ) => {
			const i = part.indexOf( '=' );
			const name = ( i < 0 ? part : part.slice( 0, i ) ).trim();
			if ( name ) {
				out[ name ] = i < 0 ? '' : part.slice( i + 1 );
			}
		} );
		return out;
	}
	function storageKeys( w, area ) {
		const out = [];
		try {
			const st = w[ area + 'Storage' ];
			for ( let i = 0; i < st.length; i++ ) {
				out.push( st.key( i ) );
			}
		} catch ( e ) {}
		return out;
	}
	async function idbNames( w ) {
		try {
			if ( w.indexedDB && w.indexedDB.databases ) {
				return ( await w.indexedDB.databases() ).map( ( d ) => d.name );
			}
		} catch ( e ) {}
		return [];
	}
	function killCookie( name ) {
		const parts = location.hostname.split( '.' );
		const paths = [ '/', location.pathname.replace( /\/[^/]*$/, '/' ) ];
		paths.forEach( ( path ) => {
			document.cookie = name + '=;max-age=0;path=' + path;
			for ( let i = 0; i < parts.length - 1; i++ ) {
				document.cookie = name + '=;max-age=0;path=' + path + ';domain=.' + parts.slice( i ).join( '.' );
			}
		} );
	}

	/** Third-party hosts from the resource timing of a window and its same-origin subframes. */
	function frameHosts( w, page, out ) {
		try {
			w.performance.getEntriesByType( 'resource' ).forEach( ( r ) => {
				let host = '';
				try {
					host = new URL( r.name ).hostname;
				} catch ( e ) {}
				if ( host && host !== location.hostname ) {
					out.push( { host, type: r.initiatorType, page } );
				}
			} );
			for ( let i = 0; i < w.frames.length; i++ ) {
				try {
					if ( w.frames[ i ].location.hostname === location.hostname ) {
						frameHosts( w.frames[ i ], page, out );
					}
				} catch ( e ) {}
			}
		} catch ( e ) {}
	}

	/**
	 * Privacy measurement: hides consent cookies, loads home + sample pages as a first visitor (no clicks),
	 * records new cookies, storage keys and third-party hosts, then restores the browser to its previous state.
	 * Only names and hosts are sent to the server, never values.
	 */
	async function privacyProbe() {
		const setup = S.probeSetup || { urls: [ C.home ], consentCookie: '^plakette_consent$' };
		const consentRe = new RegExp( setup.consentCookie );
		ui.busy = 'probe';
		ui.probing = true;
		ui.probeStep = 'Vorbereiten …';
		render();
		const before = cookieMap();
		const stash = {};
		Object.keys( before ).forEach( ( n ) => {
			if ( consentRe.test( n ) ) {
				stash[ n ] = before[ n ];
				killCookie( n );
			}
		} );
		const base = cookieMap();
		const baseLocal = new Set( storageKeys( window, 'local' ) );
		const baseSession = new Set( storageKeys( window, 'session' ) );
		const baseIdb = new Set( await idbNames( window ) );
		const result = { pages: [], cookies: [], storage: [], hosts: [], errors: [] };
		const frame = document.getElementById( 'plk-probe-frame' );
		const urls = ( setup.urls || [ C.home ] ).slice( 0, 4 );
		try {
			for ( let i = 0; i < urls.length; i++ ) {
				const page = urls[ i ];
				ui.probeStep = `Seite ${ i + 1 } von ${ urls.length } wird wie von einem Erstbesucher geladen (ohne Klick) …`;
				const cap = app.querySelector( '.plk-probe-frame figcaption' );
				if ( cap ) {
					cap.textContent = ui.probeStep;
				}
				await new Promise( ( resolve ) => {
					const timer = setTimeout( () => {
						result.errors.push( 'Zeitüberschreitung: ' + page );
						resolve();
					}, 30000 );
					frame.addEventListener( 'load', () => {
						clearTimeout( timer );
						resolve();
					}, { once: true } );
					frame.src = page + ( page.indexOf( '?' ) === -1 ? '?' : '&' ) + 'plakette_measure=' + Date.now();
				} );
				await sleep( i === 0 ? 5000 : 3500 );
				result.pages.push( page );
				const w = frame.contentWindow;
				const now = cookieMap();
				Object.keys( now ).forEach( ( n ) => {
					if ( ! ( n in base ) || now[ n ] !== base[ n ] ) {
						if ( ! result.cookies.some( ( c ) => c.name === n ) ) {
							result.cookies.push( { name: n, page } );
						}
					}
				} );
				[ [ 'local', baseLocal ], [ 'session', baseSession ] ].forEach( ( [ area, known ] ) => {
					storageKeys( w, area ).forEach( ( k ) => {
						if ( ! known.has( k ) && ! result.storage.some( ( st ) => st.area === area && st.key === k ) ) {
							result.storage.push( { area, key: k, page } );
						}
					} );
				} );
				( await idbNames( w ) ).forEach( ( k ) => {
					if ( ! baseIdb.has( k ) && ! result.storage.some( ( st ) => st.area === 'idb' && st.key === k ) ) {
						result.storage.push( { area: 'idb', key: k, page } );
					}
				} );
				const hosts = [];
				frameHosts( w, page, hosts );
				hosts.forEach( ( h ) => {
					if ( ! result.hosts.some( ( x ) => x.host === h.host ) ) {
						result.hosts.push( h );
					}
				} );
			}
		} catch ( e ) {
			result.errors.push( e.message );
		} finally {
			frame.src = 'about:blank';
			// Leave the browser as it was: remove what the visit added, put the consent cookies back.
			result.cookies.forEach( ( c ) => {
				if ( ! ( c.name in before ) ) {
					killCookie( c.name );
				}
			} );
			result.storage.forEach( ( st ) => {
				try {
					if ( st.area === 'local' ) {
						localStorage.removeItem( st.key );
					} else if ( st.area === 'session' ) {
						sessionStorage.removeItem( st.key );
					}
				} catch ( e ) {}
			} );
			Object.keys( stash ).forEach( ( n ) => {
				document.cookie = n + '=' + stash[ n ] + ';path=/;max-age=' + 180 * 86400 + ';SameSite=Lax' + ( location.protocol === 'https:' ? ';Secure' : '' );
			} );
		}
		try {
			setState( await api( '/privacy-probe', 'POST', result ) );
			const c = byId( 'privacy_consent' );
			toast( c ? ( c.status === 'pass' ? c.title : c.problem ) + ' – Details beim Prüfpunkt.' : 'Messung gespeichert.' );
		} catch ( e ) {
			toast( e.message, null, true );
		}
		ui.busy = null;
		ui.probing = false;
		render();
	}

	/* ------------------------------------------------------------ Plakette-Statistik */

	function statsPanel() {
		const st = S.stats;
		if ( ! st ) {
			return '';
		}
		const days = Object.keys( st.daily || {} );
		const max = Math.max( 1, ...days.map( ( d ) => st.daily[ d ] ) );
		const dayLabel = ( d ) => new Date( d + 'T12:00:00' ).toLocaleDateString( 'de-DE', { day: 'numeric', month: 'short' } );
		const bars = days.map( ( d ) => {
			const n = st.daily[ d ];
			return `<li><span class="plk-sbar" style="height:${ Math.round( ( n / max ) * 100 ) }%"></span><span class="screen-reader-text">${ esc( dayLabel( d ) ) }: ${ n } Aufrufe</span></li>`;
		} ).join( '' );
		const table = ( title, rows, label ) => {
			const top = Math.max( 1, ...( rows || [] ).map( ( r ) => r.n ) );
			return `<div class="plk-stats-col"><h3>${ title }</h3>${ rows && rows.length ? `<table class="plk-table"><thead><tr><th scope="col">${ label }</th><th scope="col">Aufrufe</th></tr></thead><tbody>${ rows.map( ( r ) => `<tr><td>${ esc( r.k || ( label === 'Herkunft' ? 'direkt / unbekannt' : '–' ) ) }</td><td><span class="plk-hbar" style="width:${ Math.round( ( r.n / top ) * 100 ) }%"></span> ${ r.n }</td></tr>` ).join( '' ) }</tbody></table>` : '<p class="plk-help">Noch keine Daten.</p>' }</div>`;
		};
		const devices = ( st.devices || [] ).map( ( r ) => ( { k: { mobile: 'Smartphone', tablet: 'Tablet', desktop: 'Computer' }[ r.k ] || r.k, n: r.n } ) );
		return `<section class="plk-panel plk-stats" aria-labelledby="plk-stats-h">
			<style>.plk-stats-days{display:flex;align-items:flex-end;gap:2px;height:120px;margin:0 0 4px;padding:0;list-style:none;border-bottom:1px solid #8c8f94}.plk-stats-days li{flex:1;height:100%;display:flex;align-items:flex-end}.plk-sbar{display:block;width:100%;min-height:1px;background:#2271b1}.plk-stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-top:16px}.plk-hbar{display:inline-block;height:.7em;background:#2271b1;vertical-align:middle;max-width:60%}.plk-stats-axis{display:flex;justify-content:space-between;font-size:12px;color:#50575e}</style>
			<div class="plk-panel-head"><h2 id="plk-stats-h">Besucher – Plakette-Statistik</h2></div>
			<p class="plk-panel-note">${ plural( st.total, 'Seitenaufruf', 'Seitenaufrufe' ) } in den letzten ${ st.days } Tagen. Gezählt ohne Cookies und ohne IP-Adresse – deshalb ist keine Einwilligung nötig. Angemeldete Nutzer und Suchmaschinen-Bots zählen nicht.</p>
			<ul class="plk-stats-days" aria-label="Seitenaufrufe pro Tag">${ bars }</ul>
			<div class="plk-stats-axis" aria-hidden="true"><span>${ esc( dayLabel( days[ 0 ] ) ) }</span><span>${ esc( dayLabel( days[ days.length - 1 ] ) ) }</span></div>
			<div class="plk-stats-grid">${ table( 'Meistgelesene Seiten', st.paths, 'Seite' ) }${ table( 'Woher Besucher kommen', st.referrers, 'Herkunft' ) }${ table( 'Geräte', devices, 'Gerät' ) }</div>
		</section>`;
	}

	async function copyLetter( id ) {
		const ta = document.getElementById( 'plk-letter-' + id );
		if ( ! ta ) {
			return;
		}
		try {
			await navigator.clipboard.writeText( ta.value );
		} catch ( e ) {
			ta.select();
			document.execCommand( 'copy' );
		}
		toast( 'Nachricht kopiert. Füge sie in eine E-Mail an deinen Hoster oder in dessen Kontaktformular ein.' );
	}

	/* ------------------------------------------------------------ events */

	function onClick( e ) {
		const t = e.target.closest( '[data-action]' );
		if ( ! t || t.disabled ) {
			return;
		}
		const a = t.dataset.action;
		const id = t.dataset.id;
		if ( a === 'audit' ) {
			runAudit();
		} else if ( a === 'fix-all' ) {
			fixAll();
		} else if ( a === 'fix' ) {
			fixOne( id );
		} else if ( a === 'undo' ) {
			undo( t.dataset.run, t.dataset.check );
		} else if ( a === 'measure' ) {
			measure();
		} else if ( a === 'probe' ) {
			privacyProbe();
		} else if ( a === 'psi' ) {
			psi();
		} else if ( a === 'psi-key' ) {
			const input = document.getElementById( 'plk-psi-key' );
			if ( input && input.value.trim() ) {
				psi( input.value.trim() );
			} else if ( input ) {
				input.focus();
			}
		} else if ( a === 'save-settings' ) {
			saveSettings( id );
		} else if ( a === 'update-check' ) {
			updateCheck();
		} else if ( a === 'settings-toggle' ) {
			if ( ui.openSettings.has( id ) ) {
				ui.openSettings.delete( id );
			} else {
				ui.openSettings.add( id );
			}
			render();
		} else if ( a === 'copy' ) {
			copyLetter( id );
		} else if ( a === 'toast-close' ) {
			t.closest( '.plk-toast' ).classList.remove( 'is-visible' );
		} else if ( a === 'filter' ) {
			ui.filter = t.dataset.filter;
			render();
		} else if ( a === 'show-pass' ) {
			ui.showPass = ! ui.showPass;
			render();
		} else if ( a === 'show-skip' ) {
			ui.showSkip = ! ui.showSkip;
			render();
		} else if ( a === 'toggle' ) {
			const c = byId( id );
			const open = isOpen( c );
			const set = openByDefault( c ) ? ui.closed : ui.opened;
			if ( ( set === ui.opened ) === open ) {
				set.delete( id );
			} else {
				set.add( id );
			}
			render();
		} else if ( a === 'expand' ) {
			draft( byId( id ) ).expanded = true;
			render();
		} else if ( a === 'pick-all' ) {
			const c = byId( id );
			const d = draft( c );
			const all = ( c.items || [] ).every( ( it ) => d.items[ it.id ] && d.items[ it.id ].checked );
			( c.items || [] ).forEach( ( it ) => {
				d.items[ it.id ] = d.items[ it.id ] || { value: it.value || '' };
				d.items[ it.id ].checked = ! all;
			} );
			render();
		} else if ( a === 'jump' ) {
			const c = byId( id );
			if ( ! c ) {
				return;
			}
			ui.filter = 'all';
			if ( c.status === 'pass' ) {
				ui.showPass = true;
			}
			if ( c.status === 'skip' ) {
				ui.showSkip = true;
			}
			if ( openByDefault( c ) ) {
				ui.closed.delete( id );
			} else {
				ui.opened.add( id );
			}
			render();
			const el = document.getElementById( 'plk-row-' + id );
			if ( el ) {
				el.scrollIntoView( { behavior: matchMedia( '(prefers-reduced-motion: reduce)' ).matches ? 'auto' : 'smooth', block: 'center' } );
				el.querySelector( '.plk-row-toggle' ).focus( { preventScroll: true } );
			}
		}
	}

	function onField( t ) {
		const scope = t.dataset.scope;
		ui.forms[ scope ] = ui.forms[ scope ] || {};
		ui.forms[ scope ][ t.dataset.field ] = t.type === 'checkbox' ? t.checked : t.value;
	}

	function onInput( e ) {
		const t = e.target;
		if ( t.dataset.field ) {
			onField( t );
		} else if ( t.dataset.input ) {
			const c = byId( t.dataset.input );
			draft( c ).value = t.value;
			const counter = app.querySelector( `[data-counter="${ CSS.escape( c.id ) }"]` );
			if ( counter ) {
				const max = ( c.input && c.input.max ) || 160;
				counter.textContent = `${ t.value.length } von ${ max } Zeichen`;
				counter.classList.toggle( 'is-over', t.value.length > max );
			}
		} else if ( t.dataset.item ) {
			const d = draft( byId( t.dataset.check ) );
			d.items[ t.dataset.item ] = d.items[ t.dataset.item ] || { checked: true };
			d.items[ t.dataset.item ].value = t.value;
		}
	}

	function onChange( e ) {
		const t = e.target;
		if ( t.dataset.field ) {
			onField( t );
		} else if ( t.dataset.pick ) {
			const c = byId( t.dataset.check );
			const d = draft( c );
			d.items[ t.dataset.pick ] = d.items[ t.dataset.pick ] || { value: '' };
			d.items[ t.dataset.pick ].checked = t.checked;
			const selected = ( c.items || [] ).filter( ( it ) => d.items[ it.id ] && d.items[ it.id ].checked ).length;
			const btn = app.querySelector( `[data-action="fix"][data-id="${ CSS.escape( c.id ) }"]` );
			if ( btn ) {
				btn.textContent = `${ c.fix_label } (${ selected })`;
				btn.disabled = ! selected || !! ui.busy;
			}
		}
	}

	app.addEventListener( 'click', onClick );
	app.addEventListener( 'input', onInput );
	app.addEventListener( 'change', onChange );
	render();
} )();
