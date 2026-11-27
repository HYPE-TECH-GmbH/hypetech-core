# hypetech-core

Kern-Plugin für alle HYPE:TECH-Kundenwebsites. Eine Codebasis, viele Seiten: Sicherheitsfixes werden hier einmal gebaut und von allen Seiten automatisch installiert.

| Datei | Aufgabe |
|---|---|
| `includes/role.php` | Rolle `hypetech_kunde`, Recht „Seiten anlegen“ abgetrennt, Löschen/Templates/Navigationen gesperrt |
| `includes/admin.php` | Admin-Menü für Kunden, Dashboard-Widget, Kommentare aus |
| `includes/editor.php` | Erlaubte Blöcke, `templateLock`, `canLockBlocks:false`, kein Code-Editor, keine Core-/Remote-Patterns |
| `includes/security.php` | XML-RPC 403, User-Enumeration, Login-Drosselung, Header, Auto-Updates |
| `includes/frontend.php` | Emojis/oEmbed/Feeds/jQuery raus, Block-CSS nur bei Bedarf |
| `includes/images.php` | WebP/AVIF-Ausgabe, 2560-px-Grenze, ungenutzte Größen weg |
| `includes/smtp.php` | SMTP aus `wp-config.php`-Konstanten |
| `includes/contact.php` + `blocks/contact-form/` | Kontaktformular (REST + Fallback ohne JS, Honeypot, Zeit-Token, Rate-Limit, keine Speicherung) |
| `includes/importer.php` | Import von `content/` aus Kundenprojekten (WP-CLI + Admin-Upload) |
| `includes/updater.php` | Selbst-Update über `update.json` aus GitHub-Releases |
| `includes/consent.php` + `assets/einwilligung.*` | Cookie-Banner/Einwilligung, Zwei-Klick-Videos, Skripte erst nach Zustimmung (Entscheidung #25) |
| `includes/seo.php` | Meta, Canonical, Open Graph, JSON-LD, Sitemap (Entscheidung #22) |
| `includes/status.php` | `/wp-json/hypetech/v1/status` für Monitoring (nur mit `HYPETECH_STATUS_TOKEN`) |

## Entwickeln
Lokal wird das Plugin von jedem Kundenprojekt per `npm run setup` aus diesem Ordner eingebunden (Geschwisterordner oder `HYPETECH_CORE_PATH`). Änderungen sind sofort in allen lokal laufenden Projekten sichtbar. Prüfen: in einem Projekt `npm run check` (lintet auch den Kern).

## Release
1. Version in `hypetech-core.php` an **zwei** Stellen erhöhen (Header `Version:` und `HYPETECH_CORE_VERSION`), `CHANGELOG.md` ergänzen, ebenso `blocks/contact-form/*.asset.php` bei JS-Änderungen.
2. `HYPETECH_GITHUB_ORG=<org> npm run release -- "Kurzbeschreibung"` → `dist/hypetech-core.zip` + `dist/update.json`.
3. GitHub-Release `v<Version>` anlegen, beide Dateien als Assets anhängen. Die Seiten holen das Update innerhalb von ~12 h automatisch.
4. Das Repo ist **öffentlich** (Entscheidung #27), damit Kundenseiten Releases ohne Zugangsdaten laden. Deshalb: nie Geheimnisse, Kundendaten oder `tools/kunden.json` committen; Commits mit der GitHub-noreply-Adresse (ist in diesem Repo lokal eingestellt). `HYPETECH_CORE_UPDATE_JSON` in `wp-config.php` kann die Quelle pro Seite überschreiben.

## Monitoring
`tools/kunden.json` (nicht im Repo, Vorlage `kunden.example.json`) mit Name, URL, Token → `npm run monitor`. Prüft Erreichbarkeit, Antwortzeit, SSL-Ablauf, Header und per Token: WP-/Plugin-Updates, Kern-Version, SMTP, 2FA, Indexierung.
