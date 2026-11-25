#!/usr/bin/env node
// Monitoring aller Kundenseiten (Entscheidung #07).
// Nutzung: node tools/monitor.mjs [pfad/zu/kunden.json]
// kunden.json (NICHT ins Repo, enthält Tokens) – Vorlage: tools/kunden.example.json
// Prüft: Erreichbarkeit, Antwortzeit, SSL-Ablauf, Sicherheits-Header und – mit Token – den Status-Endpunkt.
import { readFileSync } from 'node:fs';
import tls from 'node:tls';

const file = process.argv[2] || new URL('./kunden.json', import.meta.url);
const sites = JSON.parse(readFileSync(file, 'utf8'));
const LATEST_CORE = process.env.HYPETECH_CORE_LATEST || null;

const sslDays = (host) =>
	new Promise((resolve) => {
		const s = tls.connect({ host, port: 443, servername: host, timeout: 8000 }, () => {
			const cert = s.getPeerCertificate();
			s.end();
			resolve(Math.floor((new Date(cert.valid_to) - Date.now()) / 864e5));
		});
		s.on('error', () => resolve(null));
		s.on('timeout', () => { s.destroy(); resolve(null); });
	});

async function check(site) {
	const url = new URL(site.url);
	const problems = [];
	const t0 = Date.now();
	let res;
	try {
		res = await fetch(url, { redirect: 'follow', signal: AbortSignal.timeout(15000) });
	} catch (e) {
		return { name: site.name, ok: false, problems: [`nicht erreichbar (${e.cause?.code || e.message})`] };
	}
	const ms = Date.now() - t0;
	if (!res.ok) problems.push(`HTTP ${res.status}`);
	if (ms > 2500) problems.push(`langsam (${ms} ms)`);
	for (const h of ['x-content-type-options', 'referrer-policy', 'x-frame-options']) {
		if (!res.headers.get(h)) problems.push(`Header fehlt: ${h}`);
	}
	const days = url.protocol === 'https:' ? await sslDays(url.hostname) : null;
	if (days === null) problems.push('SSL nicht prüfbar');
	else if (days < 14) problems.push(`SSL läuft in ${days} Tagen ab`);

	let status = null;
	if (site.token) {
		try {
			const r = await fetch(new URL('/wp-json/hypetech/v1/status', url), {
				headers: { 'X-Hypetech-Token': site.token },
				signal: AbortSignal.timeout(20000),
			});
			status = r.ok ? await r.json() : null;
			if (!status) problems.push(`Status-Endpunkt: HTTP ${r.status}`);
		} catch {
			problems.push('Status-Endpunkt nicht erreichbar');
		}
	}
	if (status) {
		if (status.wp_update) problems.push(`WordPress-Update offen (${status.wp_update})`);
		if (status.plugin_updates?.length) problems.push(`Plugin-Updates: ${status.plugin_updates.join(', ')}`);
		if (!status.file_edit_off) problems.push('DISALLOW_FILE_EDIT fehlt');
		if (!status.smtp) problems.push('SMTP nicht eingerichtet');
		if (!status.two_factor) problems.push('Two Factor nicht aktiv');
		if (status.admins_ohne_2fa > 0) problems.push(`${status.admins_ohne_2fa} Admin(s) ohne 2FA`);
		if (!status.indexierbar && site.live !== false) problems.push('Suchmaschinen ausgesperrt');
		if (LATEST_CORE && status.core !== LATEST_CORE) problems.push(`hypetech-core ${status.core} (aktuell ${LATEST_CORE})`);
	}
	return { name: site.name, ok: problems.length === 0, ms, ssl: days, status, problems };
}

const results = await Promise.all(sites.map(check));
let bad = 0;
for (const r of results) {
	const info = [r.ms && `${r.ms} ms`, r.ssl != null && `SSL ${r.ssl} T.`, r.status && `WP ${r.status.wp}`, r.status && `Core ${r.status.core}`]
		.filter(Boolean)
		.join(' · ');
	console.log(`${r.ok ? '✔' : '✖'} ${r.name.padEnd(28)} ${info}`);
	for (const p of r.problems) console.log(`    – ${p}`);
	if (!r.ok) bad++;
}
console.log(`\n${results.length} Seiten geprüft, ${bad} mit Handlungsbedarf.`);
process.exit(bad ? 1 : 0);
