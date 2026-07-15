# Changelog

Alle nennenswerten Änderungen an RookyCMS.

## [1.1.0] — 2026-07-15

### Hinzugefügt

- **Stil-Auswahl** über ein Palette-Icon neben dem Tag/Nacht-Schalter: ein Popover
  mit Farbvorschau bietet fünf visuelle Stile, jeder mit eigener Tag- und
  Nachtansicht. Die Auswahl schaltet sofort um und bleibt gespeichert; der
  Tag/Nacht-Schalter kehrt jeden Stil unabhängig um.
  - **Aurora** (bisheriger Standard, bunt), **Graphit** (reines Schwarz/Weiß,
    Apple-Stil), **Fluent** (Windows 11, Mica-Grau + Blau), **Ubuntu**
    (Aubergine + Orange), **Nord** (arktisches Blau-Grau)
- Zweite Theming-Dimension `data-style` neben `data-theme`; jeder Stil ist reine
  CSS-Custom-Property-Konfiguration — das übrige CSS bleibt unberührt

### Geändert

- Theme **und** Stil werden vor dem ersten Paint gesetzt (kleines Inline-Script im
  `<head>`) — kein Aufblitzen des Standard-Themes mehr beim Laden
- Getönte Stile dämpfen Inhaltsfotos (Graphit entsättigt sie vollständig), damit
  ihr ruhiger Charakter trägt; Aurora bleibt voll farbig
- Auf schmalen Screens entfällt der Tastatur-Hinweis (`/`) im Suchfeld, damit
  Stil-, Theme- und „Neu"-Button ohne horizontalen Überlauf in eine Zeile passen

## [1.0.0] — 2026-07-15

Erstveröffentlichung. RookyCMS ist aus einem seit Jahren privat betriebenen
Hausinfo-Dashboard („SimpleCMS") hervorgegangen; dies ist die erste
generalisierte, veröffentlichbare Fassung.

### Kern

- Ein-Seiten-Dashboard mit Bento-Masonry-Grid (1–6 Spalten, vier Kachelgrößen),
  Glas-Optik über statische Gradients — bewusst ohne `backdrop-filter` und ohne
  Endlos-Animationen (keine GPU-Dauerlast auf Tablets)
- CRUD komplett über ein universelles Modal, ohne Seiten-Reload; Drag & Drop
  mit stabilen Eintrags-IDs; Suche + Kategorie-Filter mit Live-Zählern;
  Dark/Light-Theme
- Markdown pro Kachel (Überschriften, fett/kursiv/code/strike, Links, Listen),
  serverseitig und XSS-sicher gerendert, mit Editor-Vorschau
- JSON-Datei statt Datenbank — robust durch exklusives Locking, atomares
  Schreiben, automatische Backup-Rotation und harte Fehler bei korrupten Daten
- Leises Polling über Content-Hash-Versionen (winzige Antworten, Rendern nur
  bei echten Änderungen), Sofort-Refresh beim Tablet-Wakeup
- Passwort-Modell fürs öffentlich hängende Wand-Tablet: keine Sessions, kein
  Passwort-Cache — jede Schreibaktion fragt frisch ab (`hash_equals`,
  `X-Auth-Password`)
- Upload-Härtung: `finfo`-MIME-Check, 2-MB-Limit, generierte Dateinamen,
  optionales GD-Downscaling auf 1600 px

### Neu in dieser Fassung

- `config.example.php` + `config.load.php`: frisch geklont ist die App sofort
  lauffähig — `config.php` entsteht automatisch aus der Vorlage (Demo-PIN `1234`)
- Demo-Datenbestand in `demo-data/` (17 Beispiel-Kacheln inkl. Bildern), wird
  beim Erststart automatisch übernommen; bestehende Instanzen bleiben unberührt
- Vollständige Doku: README, INSTALL, ARCHITECTURE, API, SECURITY
- MIT-Lizenz
