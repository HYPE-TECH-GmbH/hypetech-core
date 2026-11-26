# Changelog hypetech-core

Format: neueste Version oben. Jede Version = GitHub-Release mit `hypetech-core.zip` und `update.json` (siehe README).

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
