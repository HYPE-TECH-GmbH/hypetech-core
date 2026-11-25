// Minimaler ZIP-Writer ohne Abhängigkeiten (Deflate über node:zlib). Für Theme-/Plugin-ZIPs zum Hochladen in WordPress.
// Identische Kopie liegt in der Kunden-Vorlage unter bin/lib/zip.mjs.
import { deflateRawSync } from 'node:zlib';
import { readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { join, relative, sep } from 'node:path';

const CRC_TABLE = new Uint32Array(256).map((_, n) => {
	let c = n;
	for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
	return c >>> 0;
});
const crc32 = (buf) => {
	let c = 0xffffffff;
	for (const b of buf) c = CRC_TABLE[(c ^ b) & 0xff] ^ (c >>> 8);
	return (c ^ 0xffffffff) >>> 0;
};

/** Alle Dateien eines Ordners rekursiv, ohne ausgeschlossene Muster. */
export function listFiles(dir, exclude = []) {
	const out = [];
	const walk = (d) => {
		for (const name of readdirSync(d)) {
			const full = join(d, name);
			const rel = relative(dir, full).split(sep).join('/');
			if (exclude.some((re) => re.test(rel))) continue;
			statSync(full).isDirectory() ? walk(full) : out.push(full);
		}
	};
	walk(dir);
	return out;
}

/**
 * ZIP schreiben.
 * @param {string} target  Zieldatei
 * @param {{path:string, data:Buffer}[]} entries  Pfad im ZIP (mit /) und Inhalt
 */
export function writeZip(target, entries) {
	const local = [];
	const central = [];
	let offset = 0;
	const now = new Date();
	const dosTime = (now.getHours() << 11) | (now.getMinutes() << 5) | (now.getSeconds() >> 1);
	const dosDate = ((now.getFullYear() - 1980) << 9) | ((now.getMonth() + 1) << 5) | now.getDate();

	for (const { path, data } of entries) {
		const name = Buffer.from(path, 'utf8');
		const compressed = deflateRawSync(data, { level: 9 });
		const crc = crc32(data);
		const head = Buffer.alloc(30);
		head.writeUInt32LE(0x04034b50, 0);
		head.writeUInt16LE(20, 4);
		head.writeUInt16LE(0x0800, 6); // UTF-8-Dateinamen
		head.writeUInt16LE(8, 8);
		head.writeUInt16LE(dosTime, 10);
		head.writeUInt16LE(dosDate, 12);
		head.writeUInt32LE(crc, 14);
		head.writeUInt32LE(compressed.length, 18);
		head.writeUInt32LE(data.length, 22);
		head.writeUInt16LE(name.length, 26);
		local.push(head, name, compressed);

		const cen = Buffer.alloc(46);
		cen.writeUInt32LE(0x02014b50, 0);
		cen.writeUInt16LE(20, 4);
		cen.writeUInt16LE(20, 6);
		cen.writeUInt16LE(0x0800, 8);
		cen.writeUInt16LE(8, 10);
		cen.writeUInt16LE(dosTime, 12);
		cen.writeUInt16LE(dosDate, 14);
		cen.writeUInt32LE(crc, 16);
		cen.writeUInt32LE(compressed.length, 20);
		cen.writeUInt32LE(data.length, 24);
		cen.writeUInt16LE(name.length, 28);
		cen.writeUInt32LE(offset, 42);
		central.push(cen, name);
		offset += head.length + name.length + compressed.length;
	}
	const cenSize = central.reduce((s, b) => s + b.length, 0);
	const end = Buffer.alloc(22);
	end.writeUInt32LE(0x06054b50, 0);
	end.writeUInt16LE(entries.length, 8);
	end.writeUInt16LE(entries.length, 10);
	end.writeUInt32LE(cenSize, 12);
	end.writeUInt32LE(offset, 16);
	writeFileSync(target, Buffer.concat([...local, ...central, end]));
}

/** Ordner als ZIP mit Wurzelordner `rootName/` packen. */
export function zipDir(dir, target, rootName, exclude = []) {
	const files = listFiles(dir, exclude);
	writeZip(
		target,
		files.map((f) => ({ path: `${rootName}/${relative(dir, f).split(sep).join('/')}`, data: readFileSync(f) })),
	);
	return files.length;
}
