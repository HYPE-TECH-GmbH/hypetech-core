#!/usr/bin/env node
// Release bauen: dist/hypetech-core.zip + dist/update.json
// Nutzung: node bin/release.mjs "Changelog-Text"
// Danach beides als Assets an ein GitHub-Release (Tag v<Version>) hängen. Die Kundenseiten holen sich das Update selbst.
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { zipDir } from './lib/zip.mjs';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const main = readFileSync(join(root, 'hypetech-core.php'), 'utf8');
const version = main.match(/^\s*\*\s*Version:\s*(\S+)/m)?.[1];
const constVersion = main.match(/HYPETECH_CORE_VERSION = '([^']+)'/)?.[1];
if (!version || version !== constVersion) {
	console.error(`Version im Plugin-Header (${version}) und HYPETECH_CORE_VERSION (${constVersion}) müssen gleich sein.`);
	process.exit(1);
}
const org = process.env.HYPETECH_GITHUB_ORG || '<ORG>';
const dist = join(root, 'dist');
mkdirSync(dist, { recursive: true });

const count = zipDir(root, join(dist, 'hypetech-core.zip'), 'hypetech-core', [
	/^\.git/, /^dist/, /^node_modules/, /^tools/, /^bin/, /^\.claude/, /\.md$/, /^package(-lock)?\.json$/,
]);
writeFileSync(
	join(dist, 'update.json'),
	JSON.stringify(
		{
			version,
			download_url: `https://github.com/${org}/hypetech-core/releases/download/v${version}/hypetech-core.zip`,
			requires: main.match(/Requires at least:\s*(\S+)/)?.[1],
			requires_php: main.match(/Requires PHP:\s*(\S+)/)?.[1],
			tested: process.env.WP_TESTED || '6.9',
			changelog: process.argv[2] || '',
		},
		null,
		2,
	),
);
console.log(`hypetech-core ${version}: ${count} Dateien → dist/hypetech-core.zip + dist/update.json`);
if (org === '<ORG>') console.warn('Hinweis: HYPETECH_GITHUB_ORG nicht gesetzt – download_url enthält <ORG>.');
