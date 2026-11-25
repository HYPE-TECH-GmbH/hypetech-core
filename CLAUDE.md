# hypetech-core – Regeln für Claude Code

Kern-Plugin, das auf **allen** Kundenwebsites läuft. Jede Änderung wirkt auf alle Kunden → besonders sorgfältig.

- Gesamtarchitektur und Entscheidungen: `../Wordpress_Vorlage/docs/ENTSCHEIDUNGEN.md` und `docs/SPEC.md` dort.
- PHP 8.2, WordPress-Coding-Standards, alle Funktionen mit Präfix `hypetech_`, deutsche Kommentare und Texte.
- Keine Abhängigkeiten (kein Composer, kein Build-Schritt). Editor-JS als Plain-JS mit `*.asset.php`.
- Eingaben immer `sanitize_*`, Ausgaben immer `esc_*`. Keine personenbezogenen Daten speichern oder loggen.
- Keine externen Requests außer dem Update-Manifest.
- Rückwärtskompatibel bleiben: Optionen, Rollen-Namen, Block-Attribute und REST-Routen nie umbenennen – Kundenseiten hängen daran.
- Nach Änderungen: in einem Kundenprojekt/der Vorlage `npm run check` (lintet den Kern mit) und lokal testen; danach Release-Ablauf aus `README.md`.
- Version erhöhen (SemVer): Fix = Patch, neue Funktion = Minor, Bruch = Major (nur nach Rücksprache).
