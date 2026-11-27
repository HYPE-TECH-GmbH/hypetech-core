# Changelog hypetech-core

Format: neueste Version oben. Jede Version = GitHub-Release mit `hypetech-core.zip` und `update.json` (siehe README).

## 1.3.0 – 2026-11-27
- **Beiträge für Kunden** (Entscheidung #21): mit `kunde_darf_beitraege` in site.json legt die Kundenrolle eigene Beiträge an, bearbeitet und löscht sie (frei aus den erlaubten Blöcken). Seiten bleiben gesperrt.
- Importer: `kategorien`, `beitraege` (content/beitraege/*.html, Beitragsbild, Auszug, Datum, Kategorien, Terme), `beitragsseite`; Optionen `sprache`, `zeitzone`, `permalinks`, `kategorie_basis`; SEO-Felder je Seite/Beitrag (`beschreibung`, `seo_titel`, `seo_bild`, `noindex`, `seo_typ`).
- **SEO-Modul** (Entscheidung #22): Meta-Beschreibung, Canonical (auch Archive/Blätterseiten), Robots, Open Graph/Twitter, JSON-LD (Organisation, WebSite, WebPage, Breadcrumb, BlogPosting, Service), Sitemap nur Seiten/Beiträge/Kategorien. Aus, wenn The SEO Framework aktiv ist.
- **Mehrsprachigkeit** (Entscheidung #24): Importer richtet Polylang ein (`sprachen`, je Eintrag `sprache` + `gruppe` → Übersetzungen verknüpft, Sprachpakete). SEO sprachbewusst (Startseite je Sprache, og:locale:alternate).
- Kontaktformular in der Seitensprache (`hypetech_t()`, Deutsch/Englisch), optionales Firmenfeld (`showCompany`), eigene Beschriftung/Platzhalter fürs Nachrichtenfeld.

## 1.2.2 – 2026-11-26
- Neue Plugin-Beschreibung in der WordPress-Pluginliste.

## 1.2.1 – 2026-11-26
- Cache-Control für Besucher-Seiten (5 Min. am CDN, `hypetech_cache_control`): Hostinger-CDN hielt HTML sonst 7 Tage, Deploys waren unsichtbar.

## 1.2.0 – 2026-11-26
- Importer: Videos aus `briefing/assets/medien/<name>.(mp4|webm)` über `site.json → medien`, Platzhalter `{{medien:name}}` im Inhalt.

## 1.1.0 – 25.11.2026
- **Sicherheit:** Strukturprüfung beim Speichern – Kunden können Abschnitte nicht mehr über „Vorlage bearbeiten“ entsperren und umbauen (im Pilot im echten Editor nachgewiesen).
- „Vorlage bearbeiten“ für Kunden ausgeblendet, aufgehobene Sperren werden sofort zurückgesetzt.
- **Fix:** Kunden sahen „Zur Überprüfung einreichen“ – Speichern hätte die Seite offline genommen. Jetzt „Aktualisieren“; Offline-Nehmen ist gesperrt.
- Kunden landen nach dem Login in der Seitenliste.
- XML-RPC antwortet mit 403, Hinweis „Zu viele Fehlversuche“ bleibt sichtbar, Shortlink-Header entfernt.
- Platzhalterbilder: Beschriftung oben rechts statt mittig.

## 1.0.0 – 25.11.2026
- Erste Version: Kundenrolle, Editor-Sperren (contentOnly + optional Abschnittsverwaltung), Admin-Aufräumen,
  Sicherheits-Basis, Frontend-Aufräumen, WebP-Ausgabe, SMTP, Kontaktformular-Block, Selbst-Updater, Status-Endpunkt.
