# Changelog

Alle nennenswerten Änderungen an RookyCMS.

## [1.3.0] — 2026-10-31

Neue leichte Stile und Tag/Nacht von selbst. Aurora bleibt, wie es ist;
dazu kommen drei zeitgemäße, flache Stile, und die Tageszeit-Ansicht folgt
jetzt automatisch dem Gerät.

### Hinzugefügt

- **Drei leichte Stile**, jeder mit Tag- und Nachtansicht: **Klar**
  (ruhiges Grau, weiße Karten, Systemblau — angelehnt an Apple), **Minze**
  (frisch, rund, randlose Karten — wie cat-o-fit) und **Schiefer**
  (sachlich, Kategorie als Randstreifen, Beschriftung in Mono — wie der
  Energietracker). Flache Karten mit feiner Kante und weichem Schatten, die
  Kategoriefarbe steckt im Badge, Kachelbilder erscheinen als Kopfband in
  voller Farbe. Weiterhin ohne Blur und ohne Dauer-Animation.
- **Tag/Nacht automatisch:** Ohne eigene Wahl folgt das Dashboard dem
  Hell/Dunkel-Modus des Geräts — auch während es offen ist, etwa wenn das
  Wand-Tablet abends umschaltet. Der Sonne/Mond-Knopf schaltet wie im
  Energietracker reihum: automatisch (kleines „A" am Knopf) → immer hell →
  immer dunkel; jeder Klick meldet kurz, was jetzt gilt. Die Statusleiste
  (`theme-color`) und Scrollbalken passen sich mit an.
- **Unterstützen:** freiwilliger „☕ Kaffee spendieren"-Link in der Fußzeile,
  dazu Badge und Abschnitt in der README und der Sponsor-Knopf auf GitHub
  (`.github/FUNDING.yml`) — wie im Energietracker.
- Selbsttest prüft, dass Stil-Liste, Vor-Paint-Skript und CSS
  zusammenpassen.

### Geändert

- **Graphit, Fluent, Ubuntu und Nord entfallen.** Eine gespeicherte Auswahl
  wird übernommen: Graphit und Fluent werden zu Klar, Nord zu Schiefer,
  Ubuntu zu Minze. Nach dem Update startet jedes Gerät einmal im
  Automatikmodus für Tag/Nacht.
- Galerie in der README mit allen Stilen in Tag- und Nachtansicht.

### Behoben

- **Kacheln konnten sich um einige Pixel überlappen**, wenn der Browser
  feste Scrollbalken zeigt (Desktop): Das Masonry-Layout maß die Kacheln,
  während der Scrollbalken kurz verschwunden war. Jetzt wird ohne
  Zurücksetzen gemessen und bei geänderter Breite nachgemessen; nach einem
  Stilwechsel wird neu ausgerichtet.

## [1.2.1] — 2026-10-31

Live-Demo, AGPL-Lizenz und ein gründliches Review — neu veröffentlicht.
Inhaltlich identisch mit 1.2.0: Die Git-Historie wurde bereinigt (unter
anderem ist ein früheres Demo-Bild unklarer Herkunft nun auch aus allen alten
Ständen entfernt). Weil GitHub das Release 1.2.0 als unveränderlich gesperrt
hatte, musste es dafür zurückgezogen werden; die Versionsnummer 1.2.0 wird
nicht mehr vergeben. Alle Änderungen stehen unter 1.2.0.

- Tags `v1.0.0` und `v1.1.0` zeigen auf die bereinigten Stände; ihre Inhalte
  sind bis auf das ersetzte Demo-Bild unverändert.

## [1.2.0] — 2026-10-31 (zurückgezogen, siehe 1.2.1)

Live-Demo, AGPL-Lizenz und ein gründliches Review. Ausprobieren geht jetzt
ohne Installation, und eine Reihe von Fehlern aus dem Alltag am Wand-Tablet
ist behoben.

### Hinzugefügt

- **Öffentliche Live-Demo auf GitHub Pages** —
  <https://bingerminger.github.io/RookyCMS/> mit den 17 Demo-Kacheln, allen
  fünf Stilen, Suche, Filtern, Verschieben und dem Bearbeiten-Modal
  (Passwort `1234`). Gespeichert wird in der Demo nichts, ein Banner verlinkt
  die eigene Installation. Gebaut wie bei Sternenflunk und im Energietracker:
  `tools/build-demo.sh` rendert die Seite einmal mit dem echten PHP-Server,
  `app.js` beantwortet die API im Demo-Modus (`data-demo`) selbst;
  `.github/workflows/pages.yml` baut aus dem neuesten Release-Tag.
- **Drosselung von Fehlversuchen:** Nach fünf falschen Passwörtern in Folge
  ist für 60 Sekunden Pause (429), jede weitere Sperre dauert doppelt so
  lang, höchstens 15 Minuten. Gespeichert wird nur ein Hash der
  Client-Adresse.
- **Fußzeile mit Version und Quellcode-Link** auf genau die laufende Version;
  die Version steht zusätzlich in `<meta name="generator">` (neue Datei
  `VERSION`).
- **Hinweis „Keine Kachel passt zu Suche und Filter"** statt einer leeren
  Seite.
- **Selbsttest** `php tools/selftest.php` (Markdown- und Link-Sicherheit,
  Persistenz, HTTP-API samt Drosselung, Release-Stempel) und CI-Workflow
  `.github/workflows/ci.yml` auf PHP 8.3 und 8.4.
- Neue Galerie in der README: alle Stile, Tag/Nacht, Stil-Auswahl, Modal,
  Smartphone. Die Willkommenskachel der Demo erklärt die Stil-Auswahl.

### Sicherheit

- **Links mit `javascript:`, `data:` & Co. werden nicht mehr ausgeliefert** —
  weder im Markdown noch im Feld „Link" (dort 422 beim Speichern). Erlaubt
  sind `http(s)`, `mailto`, `tel`, `ftp`, `smb`, `ssh`, `vnc`, `rdp` und
  relative Pfade, auch verschleierte Schreibweisen fallen durch.
- **`public/data/` ist unter Apache gesperrt** (neue `public/.htaccess`, nur
  `AllowOverride FileInfo` nötig): Datenbestand und Backups — auch gelöschter
  Kacheln — waren bisher direkt per URL lesbar. Für nginx steht die Regel in
  `docs/INSTALL.md`.
- Initialdaten im `<script>`-Block sind gegen Kachelinhalte wie
  `<!--<script>` kodiert, die den Script-Block aufbrechen konnten;
  `X-Content-Type-Options: nosniff` auf Seite und API.
- Ein eingeschleustes NUL-Zeichen im Kacheltext konnte die ganze Seite mit
  einem Fehler stilllegen.
- `tools/selftest.php` läuft nur auf der Kommandozeile.

### Behoben

- **Absätze gehen nicht mehr verloren:** Browser speichern Textfelder mit
  Windows-Zeilenenden; Absätze und Umbrüche solcher Kacheln wurden falsch
  oder gar nicht erkannt. Zeilenenden werden jetzt beim Anzeigen und
  Speichern vereinheitlicht. Auch Text direkt unter einer Überschrift oder
  Liste behält seine Zeilenumbrüche.
- **App startet auch mit gesperrtem Website-Speicher** (Safari-Privatmodus,
  blockierte Cookies) — bisher blieb das Dashboard leer.
- **Passwort mitten in der Aktion abgelaufen** (401, etwa nach einer
  Passwortänderung): Der Passwort-Dialog öffnet sich erneut, danach geht es
  mit den unveränderten Eingaben weiter — bisher blieb nur eine Fehlermeldung.
- **Lange Adressen und Wörter** im Kacheltext brechen um, statt rechts
  abgeschnitten zu werden.
- Eine zu große oder falsche Bilddatei wird nach der Ablehnung aus dem Feld
  genommen, statt beim Speichern doch hochgeladen zu werden. Ein neues Bild
  ersetzt das alte auch nach „Entfernen"; Uploads ohne Ziel werden
  aufgeräumt.
- iPad/iPhone zoomen beim Tippen ins Textfeld nicht mehr hinein; das Modal
  passt sich der eingeblendeten Tastatur an.
- Die Markdown-Vorschau zeigt immer den neuesten Stand (überholte Antworten
  werden verworfen).
- Formularfelder als Array (`feld[]=…`) führen nicht mehr zu einem
  Serverfehler.

### Geändert

- **Lizenz: GNU AGPL v3.0 oder neuer** (wie Energietracker und Sternenflunk).
  Versionen bis 1.1.0 bleiben unter MIT erhältlich. Neu: `CREDITS.md` und
  `TRADEMARKS.md`.
- Polling ruht, solange der Tab verdeckt oder das Display aus ist; beim
  Aufwachen wird wie bisher sofort aktualisiert.
- Ein Demo-Bild ist durch ein selbst erzeugtes Muster ersetzt — alle
  Demo-Inhalte sind jetzt eigene Werke.
- Doku: Installationsanleitung mit Prüfbefehlen (Version, Datenordner),
  Sicherheitsseite mit Drosselung und Link-Schemata, API mit `href`, 429 und
  Demo-Modus.

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
