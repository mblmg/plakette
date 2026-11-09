#!/usr/bin/env node
/*
 * Outside judge for the cookie-banner verdict of Plakette. Independent of the plugin's own detection:
 * a fresh Chromium profile visits pages WITHOUT clicking and reports everything the browser stores or contacts.
 *
 *   node tests/consent-probe.mjs probe http://127.0.0.1:9413 [/path …]   → JSON evidence of one first visit
 *   node tests/consent-probe.mjs judge [--port 9413] [--php 8.4] [--ui]   → starts a seeded Playground, runs the scenarios,
 *                                                                          compares probe evidence with privacy_consent
 * Results: tests/out/consent-judge/*.json, screenshots with --ui in .studio/.
 * Needs: Playwright (NODE_PATH=/usr/local/lib/node_modules), Chromium at /usr/local/bin/chromium.
 */
import { createRequire } from 'node:module';
import { spawn, execSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire( import.meta.url );
let pw;
try {
	pw = require( 'playwright' );
} catch ( e ) {
	pw = require( '/usr/local/lib/node_modules/playwright' );
}
const ROOT = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );

/* ------------------------------------------------------------------ the judge's own rules (deliberately simple) */

// Technically necessary per § 25 Abs. 2 Nr. 2 TDDDG – only things a visitor has explicitly asked for.
const NECESSARY = /^(plakette_consent|wordpress_test_cookie|wp-settings-.*|wordpress_logged_in_.*|wordpress_sec_.*|woocommerce_cart_hash|woocommerce_items_in_cart|wp_woocommerce_session_.*|cf_clearance|__cf_bm)$/;
// Disputed: reported separately, not counted (VG Wort counting pixel, see report notes).
const DISPUTED_HOST = /(^|\.)met\.vgwort\.de$/;

const sleep = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

async function browser() {
	return pw.chromium.launch( { executablePath: '/usr/local/bin/chromium', args: [ '--no-sandbox' ] } );
}

/** One first visit (fresh profile) of the given pages, no clicks. Optionally click "accept all" afterwards. */
export async function probe( base, paths, opts = {} ) {
	const b = opts.browser || ( await browser() );
	const ctx = await b.newContext( { userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 consent-judge' } );
	const own = new URL( base ).hostname;
	const requests = new Map();
	ctx.on( 'request', ( r ) => {
		try {
			const h = new URL( r.url() ).hostname;
			if ( h && h !== own && /^https?:/.test( r.url() ) ) {
				requests.set( h, ( requests.get( h ) || 0 ) + 1 );
			}
		} catch ( e ) {}
	} );
	const page = await ctx.newPage();
	const storage = {};
	const banners = [];
	for ( const p of paths ) {
		const url = new URL( p, base ).href;
		try {
			await page.goto( url, { waitUntil: 'load', timeout: 45000 } );
		} catch ( e ) {
			// a hanging third-party request must not stop the probe
		}
		await sleep( opts.wait || 5000 );
		banners.push( { page: p, banner: await page.locator( '#plakette-consent:not([hidden])' ).count() > 0 } );
		for ( const f of page.frames() ) {
			try {
				const keys = await f.evaluate( () => {
					const out = [];
					for ( const area of [ 'localStorage', 'sessionStorage' ] ) {
						try {
							const st = window[ area ];
							for ( let i = 0; i < st.length; i++ ) {
								out.push( area + ':' + st.key( i ) );
							}
						} catch ( e ) {}
					}
					return { origin: location.origin, keys: out };
				} );
				if ( keys.origin && keys.origin !== 'null' ) {
					storage[ keys.origin ] = [ ...new Set( [ ...( storage[ keys.origin ] || [] ), ...keys.keys ] ) ];
				}
			} catch ( e ) {}
		}
	}
	const firstVisit = { cookies: await ctx.cookies(), storage, requests: Object.fromEntries( requests ), banners };
	let afterAccept = null;
	if ( opts.accept ) {
		const btn = page.locator( '#plakette-consent [data-plk-cc="all"]' );
		if ( await btn.count() && await btn.isVisible() ) {
			requests.clear();
			await btn.click();
			await sleep( 4000 );
			afterAccept = { cookies: await ctx.cookies(), requests: Object.fromEntries( requests ) };
		}
	}
	await ctx.close();
	if ( ! opts.browser ) {
		await b.close();
	}
	return evaluate( own, firstVisit, afterAccept );
}

function evaluate( own, fv, aa ) {
	const cookieRows = ( list ) => list.map( ( c ) => ( { name: c.name, domain: c.domain, httpOnly: c.httpOnly, thirdParty: ! c.domain.replace( /^\./, '' ).endsWith( own ) && c.domain.replace( /^\./, '' ) !== own } ) );
	const cookies = cookieRows( fv.cookies );
	const nonNecessaryCookies = cookies.filter( ( c ) => c.thirdParty || ! NECESSARY.test( c.name ) );
	const storageEntries = [];
	Object.entries( fv.storage ).forEach( ( [ origin, keys ] ) => keys.forEach( ( k ) => storageEntries.push( origin + ' ' + k ) ) );
	const hosts = Object.keys( fv.requests );
	const consentHosts = hosts.filter( ( h ) => ! DISPUTED_HOST.test( h ) );
	const disputed = hosts.filter( ( h ) => DISPUTED_HOST.test( h ) );
	const out = {
		cookies,
		nonNecessaryCookies,
		storage: storageEntries,
		thirdPartyHosts: fv.requests,
		consentHosts,
		disputedHosts: disputed,
		bannerShownOn: fv.banners.filter( ( x ) => x.banner ).map( ( x ) => x.page ),
		firstVisitNeedsConsent: nonNecessaryCookies.length + storageEntries.length + consentHosts.length > 0,
	};
	if ( aa ) {
		const newHosts = Object.keys( aa.requests ).filter( ( h ) => ! DISPUTED_HOST.test( h ) );
		const before = new Set( fv.cookies.map( ( c ) => c.name + '@' + c.domain ) );
		const newCookies = aa.cookies.filter( ( c ) => ! before.has( c.name + '@' + c.domain ) && ! NECESSARY.test( c.name ) ).map( ( c ) => c.name + '@' + c.domain );
		out.afterAccept = { hosts: newHosts, cookies: newCookies, somethingNeededConsent: newHosts.length + newCookies.length > 0 };
	}
	return out;
}

/* ------------------------------------------------------------------ judge: scenarios against a seeded Playground */

function arg( name, def ) {
	const i = process.argv.indexOf( '--' + name );
	return i > -1 ? process.argv[ i + 1 ] : def;
}

async function http( url, opts ) {
	const r = await fetch( url, opts );
	return r.text();
}

async function audit( base ) {
	const j = JSON.parse( await http( base + '/plk-tests/run.php?step=audit' ) );
	const row = ( id ) => {
		const r = j.rows.find( ( x ) => x.split( /\s+/ )[ 1 ] === id ) || '';
		const m = r.match( /^(\S+)\s+(\S+)\s+(\S+)\s+(.*)$/ );
		return m ? { status: m[ 1 ], kind: m[ 3 ], detail: m[ 4 ] } : null;
	};
	return { consent: row( 'privacy_consent' ), third: row( 'privacy_third_party' ), raw: j };
}

/** Logged-in admin session for REST calls and screenshots. */
async function adminPage( b, base ) {
	const ctx = await b.newContext( { viewport: { width: 1280, height: 900 } } );
	const page = await ctx.newPage();
	// Test-only login (tests/mu/plk-privacy-cases.php): after fix-all the security fixes rename "admin" and limit logins.
	await page.goto( base + '/?plk_login=1' );
	await page.goto( base + '/wp-admin/admin.php?page=plakette' );
	await page.waitForSelector( '#plakette-app .plk-panel', { timeout: 60000 } );
	return { ctx, page };
}

/** Applies the fix of privacy_consent with the items that are pre-selected in the admin (like a user who clicks the button). */
async function fixDefault( page ) {
	return page.evaluate( async () => {
		const C = window.PLAKETTE;
		const call = async ( p, body ) => ( await fetch( C.rest + p, { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': C.nonce, 'Content-Type': 'application/json' }, body: body ? JSON.stringify( body ) : undefined } ) ).json();
		const state = await call( '/audit', {} );
		const c = state.report.checks.find( ( x ) => x.id === 'privacy_consent' );
		const items = ( c.items || [] ).filter( ( it ) => it.checked !== false ).map( ( it ) => ( { id: it.id, value: '' } ) );
		const res = await call( '/fix', { check: 'privacy_consent', run: 'judge' + Date.now(), items } );
		return { status: c.status, items: c.items, picked: items.map( ( i ) => i.id ), message: res.message || res.code };
	} );
}

async function judge() {
	const port = arg( 'port', '9413' );
	const php = arg( 'php', '8.4' );
	const base = `http://127.0.0.1:${ port }`;
	const out = path.join( ROOT, 'tests/out/consent-judge' );
	fs.mkdirSync( out, { recursive: true } );
	fs.writeFileSync( path.join( out, 'bp.json' ), JSON.stringify( { steps: [ { step: 'activatePlugin', pluginPath: 'plakette/plakette.php' } ] } ) );
	const server = spawn( 'npx', [ '-y', '@wp-playground/cli@latest', 'server', `--port=${ port }`, `--php=${ php }`, '--workers=2', '--login',
		`--mount=${ ROOT }/src/plakette:/wordpress/wp-content/plugins/plakette`,
		`--mount=${ ROOT }/tests/mu:/wordpress/wp-content/mu-plugins`,
		`--mount=${ ROOT }/tests:/wordpress/plk-tests`,
		'--define-bool', 'WP_DEBUG', 'true', '--define-bool', 'WP_DEBUG_LOG', 'true', '--define-bool', 'WP_DEBUG_DISPLAY', 'false',
		`--blueprint=${ path.join( out, 'bp.json' ) }` ], { stdio: [ 'ignore', fs.openSync( path.join( out, 'server.log' ), 'w' ), 'inherit' ], detached: true } );
	const stop = () => {
		try {
			process.kill( -server.pid );
		} catch ( e ) {}
		try {
			execSync( `ps -eo pid,args | awk '/wp-playground-cli server --port=${ port }/ && !/awk/ {print $1}' | xargs -r kill`, { stdio: 'ignore' } );
		} catch ( e ) {}
	};
	process.on( 'exit', stop );
	process.on( 'SIGINT', () => process.exit( 1 ) );
	for ( let i = 0; i < 150; i++ ) {
		try {
			if ( ( await fetch( base + '/' ) ).status === 200 ) {
				break;
			}
		} catch ( e ) {}
		await sleep( 2000 );
	}
	console.log( ( await http( base + '/plk-tests/seed.php' ) ).trim().split( '\n' ).pop() );

	// Pages: home, the post with the embeds, one more post.
	const posts = JSON.parse( await http( base + '/?rest_route=/wp/v2/posts&per_page=5&_fields=link,title' ) );
	const video = posts.find( ( p ) => /Hochzeitstorte \(Video\)/.test( p.title.rendered ) ) || posts[ 0 ];
	const other = posts.find( ( p ) => p !== video ) || posts[ 0 ];
	const pages = [ '/', video.link.replace( base, '' ), other.link.replace( base, '' ) ];

	const b = await browser();
	const results = [];
	const verdict = ( a ) => {
		const s = a.consent;
		if ( ! s ) {
			return 'missing';
		}
		if ( s.status === 'fail' ) {
			return 'needed';
		}
		if ( /nötig und aktiv/.test( s.detail ) ) {
			return 'needed-active';
		}
		if ( /überflüssig/.test( s.detail ) ) {
			return 'superfluous';
		}
		if ( s.status === 'pass' && /Kein Cookie-Banner nötig/.test( s.detail ) ) {
			return 'not-needed';
		}
		return s.status + ':' + s.detail.slice( 0, 60 );
	};
	const record = ( name, a, p, expect, ok, extra = {} ) => {
		const r = { scenario: name, plugin: a.consent, pluginVerdict: verdict( a ), probe: p, expect, ok, ...extra };
		results.push( r );
		fs.writeFileSync( path.join( out, results.length + '-' + name + '.json' ), JSON.stringify( r, null, 2 ) );
		console.log( `${ ok ? 'PASS' : 'FAIL' }  ${ name }: plugin=${ r.pluginVerdict } | probe: ${ p.nonNecessaryCookies.length } nicht notwendige Cookies, ${ p.storage.length } Speicher-Einträge, ${ p.consentHosts.length } fremde Hosts (${ p.consentHosts.join( ', ' ) })${ p.disputedHosts.length ? ', strittig: ' + p.disputedHosts.join( ', ' ) : '' }${ p.bannerShownOn.length ? ', Banner auf ' + p.bannerShownOn.join( ' ' ) : '' }${ p.afterAccept ? ', nach „Alle akzeptieren“: ' + p.afterAccept.hosts.join( ', ' ) : '' }` );
	};

	// 1. Before: defects from the seed (Google Analytics, YouTube/Maps, Google Fonts, CDN, Gravatar …).
	let a = await audit( base );
	let p = await probe( base, pages, { browser: b } );
	record( '1-vorher', a, p, 'Banner nötig (Plugin: fail), Probe zeigt Einwilligungs-Pflichtiges', a.consent?.status === 'fail' && p.firstVisitNeedsConsent );

	// Optional: the admin browser probe (same measurement the user starts) + screenshots.
	if ( process.argv.includes( '--ui' ) ) {
		const { ctx, page } = await adminPage( b, base );
		await page.click( '[data-action="probe"]' );
		await page.waitForSelector( '[data-action="probe"]:not([disabled])', { timeout: 120000 } );
		await page.click( '[data-action="jump"][data-id="privacy_consent"]' );
		await sleep( 800 );
		await page.screenshot( { path: path.join( ROOT, '.studio/privacy-consent-check.png' ), fullPage: true } );
		const st = await page.evaluate( () => fetch( window.PLAKETTE.rest + '/report', { headers: { 'X-WP-Nonce': window.PLAKETTE.nonce } } ).then( ( r ) => r.json() ) );
		fs.writeFileSync( path.join( out, 'admin-probe.json' ), JSON.stringify( st.probe, null, 2 ) );
		const c = st.report.checks.find( ( x ) => x.id === 'privacy_consent' );
		console.log( `      Admin-Messung: ${ st.probe.cookies.length } Cookies, ${ st.probe.storage.length } Speicher, ${ st.probe.hosts.length } Hosts (${ st.probe.hosts.map( ( h ) => h.host ).join( ', ' ) }) → ${ c.status } ${ c.detail.slice( 0, 120 ) }` );
		await ctx.close();
	}

	// 2. Fix everything (same as tests/e2e.sh): afterwards no banner may be needed.
	const fix = JSON.parse( await http( base + '/plk-tests/run.php?step=fixall' ) );
	fs.writeFileSync( path.join( out, 'fixall.json' ), JSON.stringify( fix, null, 2 ) );
	await sleep( 1500 );
	a = await audit( base );
	p = await probe( base, pages, { browser: b, accept: true } );
	record( '2-nach-fixall', a, p, 'Kein Banner nötig, Probe sauber', verdict( a ) === 'not-needed' && ! p.firstVisitNeedsConsent && ! p.bannerShownOn.length );

	// 3. The owner keeps a Meta pixel: banner needed, only avoidable by giving the pixel up.
	await http( base + '/?plk_case=pixel&on=1' );
	a = await audit( base );
	p = await probe( base, pages, { browser: b } );
	record( '3-pixel-ohne-banner', a, p, 'fail mit „Ohne Banner geht es nur, wenn du auf Meta Pixel … verzichtest“, Probe sieht facebook', a.consent?.status === 'fail' && /verzichtest/.test( a.consent.detail ) && /Meta Pixel/.test( a.consent.detail ) && p.consentHosts.some( ( h ) => /facebook/.test( h ) ) );

	const admin = await adminPage( b, base );
	const applied = await fixDefault( admin.page );
	fs.writeFileSync( path.join( out, 'pixel-fix.json' ), JSON.stringify( applied, null, 2 ) );
	if ( process.argv.includes( '--ui' ) ) {
		await admin.page.goto( base + '/wp-admin/admin.php?page=plakette' );
		await admin.page.waitForSelector( '.plk-stats', { timeout: 30000 } ).catch( () => {} );
	}
	a = await audit( base );
	p = await probe( base, pages, { browser: b, accept: true } );
	record( '4-pixel-mit-banner', a, p, 'Banner nötig und aktiv: erster Besuch sauber, Banner sichtbar, nach Zustimmung lädt der Pixel', verdict( a ) === 'needed-active' && ! p.firstVisitNeedsConsent && p.bannerShownOn.length > 0 && p.afterAccept && p.afterAccept.hosts.some( ( h ) => /facebook/.test( h ) ), { applied } );

	// 5. The owner drops the pixel: the banner is superfluous → switch it off.
	await http( base + '/?plk_case=pixel&on=0' );
	a = await audit( base );
	p = await probe( base, pages, { browser: b } );
	record( '5-banner-ueberfluessig', a, p, 'warn „überflüssig“, Probe: nichts braucht Einwilligung', verdict( a ) === 'superfluous' && ! p.firstVisitNeedsConsent );
	const off = await fixDefault( admin.page );
	a = await audit( base );
	p = await probe( base, pages, { browser: b } );
	record( '6-banner-aus', a, p, 'Kein Banner nötig', verdict( a ) === 'not-needed' && ! p.firstVisitNeedsConsent && ! p.bannerShownOn.length, { applied: off } );

	// Statistics: count some page views like real visitors would, then screenshot the panel.
	if ( process.argv.includes( '--ui' ) ) {
		const vctx = await b.newContext( { userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1' } );
		const vp = await vctx.newPage();
		await vp.addInitScript( () => Object.defineProperty( navigator, 'webdriver', { get: () => false } ) );
		for ( const pth of [ ...pages, ...pages, '/' ] ) {
			await vp.goto( base + pth, { referer: 'https://www.google.com/' } ).catch( () => {} );
			await sleep( 600 );
		}
		await vctx.close();
		await admin.page.goto( base + '/wp-admin/admin.php?page=plakette' );
		await admin.page.waitForSelector( '.plk-stats', { timeout: 30000 } ).catch( () => {} );
		const el = admin.page.locator( '.plk-stats' );
		if ( await el.count() ) {
			await el.screenshot( { path: path.join( ROOT, '.studio/privacy-stats.png' ) } );
		}
	}
	await admin.ctx.close();

	// 7. Undo everything: back to the original verdict.
	const undo = JSON.parse( await http( base + '/plk-tests/run.php?step=undoall' ) );
	a = await audit( base );
	p = await probe( base, pages, { browser: b } );
	record( '7-nach-undo', a, p, 'wie vorher: Banner nötig', a.consent?.status === 'fail' && p.firstVisitNeedsConsent, { undo: undo.undo } );

	await b.close();
	const log = await http( base + '/wp-content/debug.log' ).catch( () => '' );
	fs.writeFileSync( path.join( out, 'debug.log' ), log );
	const warnings = log.split( '\n' ).filter( ( l ) => /plakette/.test( l ) && /PHP (Warning|Notice|Fatal|Deprecated|Parse)/.test( l ) );
	const passed = results.filter( ( r ) => r.ok ).length;
	console.log( `\nUrteil: ${ passed } von ${ results.length } Szenarien stimmen (Plugin-Urteil = Beweis der Probe). PHP-Meldungen aus dem Plugin: ${ warnings.length }` );
	warnings.slice( 0, 10 ).forEach( ( w ) => console.log( '  ' + w.slice( 0, 220 ) ) );
	stop();
	process.exit( passed === results.length && ! warnings.length ? 0 : 1 );
}

const mode = process.argv[ 2 ];
if ( mode === 'probe' ) {
	const base = process.argv[ 3 ];
	const paths = process.argv.slice( 4 ).filter( ( x ) => ! x.startsWith( '--' ) );
	probe( base, paths.length ? paths : [ '/' ], { accept: process.argv.includes( '--accept' ) } ).then( ( r ) => console.log( JSON.stringify( r, null, 2 ) ) );
} else if ( mode === 'judge' ) {
	judge().catch( ( e ) => {
		console.error( e );
		process.exit( 2 );
	} );
} else {
	console.log( 'Usage: node tests/consent-probe.mjs probe <base-url> [/path …] [--accept] | judge [--port 9413] [--php 8.4] [--ui]' );
}
