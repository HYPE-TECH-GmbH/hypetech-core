/**
 * Einwilligung (Entscheidung #25): Banner, Auswahl im Cookie, Skripte und Videos erst nach Zustimmung.
 * Plain JS, keine Abhängigkeiten.
 */
(() => {
	const banner = document.getElementById('einwilligung');
	const datenEl = document.getElementById('ht-ew-daten');
	if (!banner || !datenEl) return;
	const daten = JSON.parse(datenEl.textContent);
	const NAME = 'hypetech_einwilligung';
	const bekannt = new Set(daten.dienste.map((d) => d.id));
	const geladen = new Set(); // Dienste, deren Skripte in dieser Seite schon laufen

	// Cookie: "<version>|<zeitpunkt>|<dienst,dienst>". Andere Version = neu fragen.
	const lesen = () => {
		const m = document.cookie.match(/(?:^|; )hypetech_einwilligung=([^;]*)/);
		if (!m) return null;
		const [version, , ids = ''] = decodeURIComponent(m[1]).split('|');
		return version === daten.version ? new Set(ids.split(',').filter(Boolean)) : null;
	};
	let erteilt = lesen();
	const hat = (id) => !!erteilt && erteilt.has(id);

	const anwenden = () => {
		document.querySelectorAll('script[type="text/plain"][data-hypetech-dienst]').forEach((alt) => {
			const id = alt.dataset.hypetechDienst;
			if (!hat(id)) return;
			const neu = document.createElement('script');
			for (const a of alt.attributes) if (!['type', 'data-hypetech-dienst', 'data-src'].includes(a.name)) neu.setAttribute(a.name, a.value);
			if (alt.dataset.src) neu.src = alt.dataset.src;
			else neu.textContent = alt.textContent;
			alt.replaceWith(neu);
			geladen.add(id);
		});
	};

	const schliessen = () => {
		banner.hidden = true;
	};

	const speichern = (ids) => {
		const vorher = erteilt;
		erteilt = new Set(ids);
		const wert = [daten.version, Math.floor(Date.now() / 1000), [...erteilt].join(',')].join('|');
		document.cookie = `${NAME}=${encodeURIComponent(wert)}; Max-Age=${daten.laufzeit}; Path=/; SameSite=Lax${location.protocol === 'https:' ? '; Secure' : ''}`;
		schliessen();
		// Widerruf eines bereits laufenden Dienstes: nur ein Neuladen stoppt ihn sicher.
		if (vorher && [...geladen].some((id) => !erteilt.has(id))) {
			location.reload();
			return;
		}
		anwenden();
		document.dispatchEvent(new CustomEvent('hypetech:einwilligung', { detail: { dienste: [...erteilt] } }));
	};

	// ------------------------------------------------------------------ Banner
	const details = banner.querySelector('.ht-ew__details');
	const boxen = [...banner.querySelectorAll('input[name="dienst"]')];
	const knopf = (aktion) => banner.querySelector(`[data-aktion="${aktion}"]`);
	const detailsZeigen = (offen) => {
		details.hidden = !offen;
		knopf('speichern').hidden = !offen;
		knopf('einstellungen').setAttribute('aria-expanded', String(offen));
	};
	let ausloeser = null;
	const oeffnen = (fokus) => {
		boxen.forEach((b) => (b.checked = hat(b.value)));
		detailsZeigen(!!erteilt); // beim erneuten Öffnen direkt die Auswahl zeigen
		banner.hidden = false;
		if (fokus) banner.querySelector('.ht-ew__titel').focus();
	};

	banner.addEventListener('click', (e) => {
		const aktion = e.target.closest('[data-aktion]')?.dataset.aktion;
		if (aktion === 'alle') speichern(daten.dienste.map((d) => d.id));
		else if (aktion === 'ablehnen') speichern([]);
		else if (aktion === 'speichern') speichern(boxen.filter((b) => b.checked).map((b) => b.value));
		else if (aktion === 'einstellungen') detailsZeigen(details.hidden);
		else return;
		if (aktion !== 'einstellungen' && ausloeser) {
			ausloeser.focus();
			ausloeser = null;
		}
	});
	banner.addEventListener('keydown', (e) => {
		if (e.key === 'Escape' && erteilt) {
			schliessen();
			ausloeser?.focus();
		}
	});
	document.addEventListener('click', (e) => {
		const link = e.target.closest('a[href$="#einwilligung"], [data-hypetech-einwilligung-oeffnen]');
		if (!link || banner.contains(link)) return;
		e.preventDefault();
		ausloeser = link;
		oeffnen(true);
	});

	// ------------------------------------------------------------------ Videos (Zwei-Klick)
	const quelle = (href) => {
		let m = href.match(/(?:youtu\.be\/|youtube(?:-nocookie)?\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/|live\/))([\w-]{11})/);
		if (m) return { dienst: 'youtube', name: 'YouTube', src: `https://www.youtube-nocookie.com/embed/${m[1]}?autoplay=1&rel=0` };
		m = href.match(/vimeo\.com\/(?:video\/)?(\d+)/);
		if (m) return { dienst: 'vimeo', name: 'Vimeo', src: `https://player.vimeo.com/video/${m[1]}?autoplay=1&dnt=1` };
		return null;
	};
	const einbetten = (figur, link, v, titel) => {
		const f = document.createElement('iframe');
		f.src = v.src;
		f.title = titel;
		f.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
		f.allowFullscreen = true;
		f.className = 'ht-video__frame';
		figur.querySelector('.ht-video__hinweis')?.remove();
		figur.classList.remove('ht-video--hinweis');
		figur.classList.add('ht-video--laeuft');
		link.replaceWith(f);
		f.focus();
	};
	const element = (tag, klasse, text) => {
		const el = document.createElement(tag);
		if (klasse) el.className = klasse;
		if (text) el.textContent = text;
		return el;
	};
	const hinweis = (figur, link, v, titel) => {
		let box = figur.querySelector('.ht-video__hinweis');
		if (box) return box.querySelector('button').focus();
		box = element('div', 'ht-video__hinweis');
		box.setAttribute('role', 'group');
		box.setAttribute('aria-label', titel);
		box.append(element('p', '', daten.texte.hinweis.replace('%s', v.name)));
		const reihe = element('div', 'ht-video__knoepfe');
		const einmal = element('button', 'ht-video__knopf', daten.texte.einmal);
		einmal.type = 'button';
		einmal.addEventListener('click', () => einbetten(figur, link, v, titel));
		reihe.append(einmal);
		if (bekannt.has(v.dienst)) {
			const immer = element('button', 'ht-video__knopf ht-video__knopf--zweit', daten.texte.immer.replace('%s', v.name));
			immer.type = 'button';
			immer.addEventListener('click', () => {
				speichern([...(erteilt || []), v.dienst]);
				einbetten(figur, link, v, titel);
			});
			reihe.append(immer);
		}
		box.append(reihe);
		if (daten.datenschutz) {
			const mehr = element('a', 'ht-video__mehr', daten.texte.mehr);
			mehr.href = daten.datenschutz;
			box.append(mehr);
		}
		figur.classList.add('ht-video--hinweis');
		figur.append(box);
		einmal.focus();
	};
	document.querySelectorAll('.ht-video a[href]').forEach((link) => {
		const v = quelle(link.href);
		if (!v) return;
		const figur = link.closest('.ht-video');
		const titel = link.querySelector('img')?.alt || v.name;
		link.setAttribute('aria-label', `${daten.texte.abspielen}: ${titel}`);
		link.addEventListener('click', (e) => {
			e.preventDefault();
			if (hat(v.dienst)) einbetten(figur, link, v, titel);
			else hinweis(figur, link, v, titel);
		});
	});

	window.hypetechEinwilligung = { hat, oeffnen: () => oeffnen(true) };
	if (erteilt) anwenden();
	else oeffnen(false); // erster Besuch: Banner zeigen, Fokus nicht stehlen
})();
