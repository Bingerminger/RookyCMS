# CLAUDE.md

Hinweise für Claude Code (oder andere AI-Agents) zur Arbeit mit diesem Repository.

## Project Overview

**RookyCMS** ist ein leichtgewichtiges PHP-CMS für Hausinformations- und Monitoring-Dashboards. Eine einzige Übersichtsseite mit Bento-Grid-Kacheln, file-basierte JSON-Speicherung, ohne Datenbank, ohne Composer. Typische Zielumgebung: lokales Intranet (z. B. NAS mit PHP 8.3+), Hauptanzeige ein Wand-/Küchen-Tablet.

Lizenz seit 1.2.0: **AGPL-3.0-or-later** (bis 1.1.0 MIT). Die Fußzeile verlinkt den Quellcode der laufenden Version (§13) — `ROOKY_REPO` in `index.php`.

## Instanz vs. Repository (wichtig!)

Der Code ist öffentlich (**GitHub-Repo `Bingerminger/RookyCMS` ist PUBLIC**, auch Commit-, Tag- und Release-Texte), die Instanz ist privat. Per `.gitignore` bleiben **immer** lokal:

- `public/config.php` (echtes Passwort/Titel — im Repo liegt nur `config.example.php`)
- `public/data/` (echte Einträge + Backups), `public/uploads/` (echte Bilder), `public/backup/`
- `scripts/` (private Deploy-Infrastruktur), `.claude/` und `CLAUDE.local.md` (private Betriebsnotizen)
- `dist/` (Bauergebnis der Demo)

**Diese Pfade nie committen, keine internen Hostnamen/IPs/Passwörter in getrackte Dateien schreiben.** Versionierte Beispieldaten leben ausschließlich in `demo-data/` — und nur eigene Inhalte (erzeugte Bilder, erfundene Texte, externe öffentliche Links).

## Architecture

**Webroot:** `public/`
**Konfiguration:** `public/config.php` — wird beim ersten Aufruf automatisch aus `config.example.php` erzeugt (`config.load.php`).
**Datenspeicher:** `public/data/entries.json` (JSON-Liste; Mutationen unter exklusivem Lock auf `entries.json.lock`, geschrieben atomar via Tempdatei + `rename()`)
**Demo-Seed:** Fehlt `entries.json` beim Start und existiert `demo-data/entries.json`, wird der Demo-Bestand (inkl. `demo-data/uploads/` → `public/uploads/`) einmalig übernommen. Bestehende Instanzen sind nie betroffen.
**Backups:** `public/data/backups/` (automatische Rotation, max. 15, gedrosselt auf 1/5 min)
**Upload-Verzeichnis:** `public/uploads/` (wird automatisch angelegt; Bilder > 1600 px werden beim Upload per GD verkleinert, falls GD vorhanden)

**Rendering-Modell:** `index.php` liefert nur die Shell (Topbar, Filter-Chips, Modal) plus Initialdaten als JSON (`window.cmsEntries`). Die Kacheln rendert ausschließlich der Client (`js/app.js`) aus dem Zustand. Markdown-HTML kommt dabei immer vom Server (`html`-Feld pro Eintrag, XSS-sicher escaped) — der Client parst kein Markdown.

### Eintragsstruktur

| Feld         | Typ       | Beschreibung |
|--------------|-----------|--------------|
| `id`         | string    | **Stabile ID** (8 Hex-Zeichen), identifiziert den Eintrag über Reorder/Delete hinweg. Alt-Daten ohne `id` werden beim ersten Zugriff automatisch migriert (mit Pre-Migration-Backup). |
| `title`      | string    | Titel der Kachel |
| `text`       | string    | Markdown-Inhalt |
| `link`       | string    | Optionale URL |
| `image`      | string\|null | Dateiname im `uploads/`-Verzeichnis |
| `category`   | string    | siehe `Category` Enum |
| `size`       | string    | `normal` / `wide` / `tall` / `large` (Bento) |
| `tags`       | string[]  | |
| `featured`   | bool      | Stern-Markierung (kein Hero-Bereich) |
| `order`      | int       | Sortierreihenfolge (wird beim Schreiben stets auf 0..n normalisiert) |
| `created_at` | string    | ISO-Datum |
| `updated_at` | string\|null | ISO-Datum |

An den Client geht zusätzlich pro Eintrag: `html` (server-gerendertes Markdown), `plain` (Klartext für Suche/Tooltip) und `href` (geprüftes Linkziel für „Öffnen", leer bei unzulässigem Schema). Der Client rendert den Kachel-Link nur aus `href`, nie aus `link`.

### Kernkomponenten

- `public/index.php` — HTML-Shell: Topbar (einzeilig: Brand + Chips + Suche/Buttons), leeres Bento-Grid, universelles Modal, Initialdaten. Rendert keine Kacheln serverseitig; zeigt bei korrupter Datendatei ein Fehlerbanner.
- `public/api.php` — AJAX-Endpoints: `entries` (Polling mit `since`-Version), `verify`, `save`, `delete`, `reorder`, `preview`. Mutationen antworten immer mit dem kompletten neuen Zustand (`{version, entries}`). Passwortprüfung zentral in `checkPassword()` (inkl. Drosselung), POST-Felder nur über `postString()`.
- `public/.htaccess` — Apache: `data/`, `backup/`, Punktdateien → 404 (nur FileInfo-Direktiven; `Require` würde bei enger `AllowOverride` alles mit 500 beantworten).
- `public/CmsManager.php` — Persistenz-Kern: `mutate()` (Read-Modify-Write unter Lock), atomares Schreiben, Backup-Rotation, ID-Migration, Demo-Seed (`seedIfEmpty()`), `getVersion()` (Content-Hash `xxh128` fürs Polling), Upload inkl. Downscaling, Markdown-Parsing (statisch).
- `public/Category.php` — Enum mit Label/Icon/Hue je Kategorie.
- `public/Size.php` — Enum mit den vier Bento-Größen.
- `public/config.example.php` — Konfigurations-Vorlage (Demo-Passwort `1234`, Poll-Intervall, Seitentitel).
- `public/config.load.php` — lädt `config.php`, legt sie bei Bedarf aus der Vorlage an.
- `demo-data/` — versionierte Demo-Einträge + Demo-Bilder für den Erststart.
- `public/css/styles.css` — **Statischer** Aurora-Hintergrund (reine Gradients, keine Animation, kein `filter:blur`), Glas-Optik über halbtransparente Farbschichten (**kein `backdrop-filter` — nirgends**), Bento-Grid als **Masonry** (Spalten 1/2/3/4/5/6 ab 0/640/960/1280/1600/2000 px, Kachel-Grundgröße `min-height: 220px` bzw. 452px für tall/large, `--tile-fs: 0.875rem`; `grid-auto-rows: 2px`, row-spans setzt JS), Systemschriften (keine externen Requests). Unter 1120 px werden die Filter-Chips eine eigene, umbrechende Zeile; unter 640 px wird die Such-/Button-Zeile eine eigene volle Zeile, linksbündig unter dem Logo (Smartphone!).
- `public/js/app.js` — `CMS`-Klasse: Zustand → `render()` (Template-String, Event-Delegation am Grid), `layoutTiles()` (misst jede Kachel und setzt ihren `grid-row-span` auf die Inhaltshöhe — `ROW_UNIT`/`GAP` müssen zu `grid-auto-rows`/`--gap` im CSS passen), Modal-Steuerung, AJAX, Drag&Drop auf Datenmodell-Basis, leises Polling, Toasts. `localStorage` nur über `store` (try/catch — gesperrter Speicher darf die App nicht stoppen). Demo-Modus über `DEMO`/`demoFetch()`.
- `VERSION` — einzige Quelle der Release-Nummer (meta generator, Fußzeile, Quellcode-Link, Deploy-Prüfung, Selbsttest gegen CHANGELOG).
- `tools/selftest.php` — Selbsttest ohne Abhängigkeiten (nur CLI); `tools/build-demo.sh` — statische Demo für GitHub Pages.
- `CREDITS.md`, `TRADEMARKS.md` — Herkunft/Lizenzen, beschreibend genannte Marken (Stile Graphit/Fluent/Ubuntu/Nord).

## Development Commands

```bash
cd public
php -S localhost:8000
```

Aufruf: `http://localhost:8000` — beim allerersten Start entstehen `config.php` (aus der Vorlage) und der Demo-Datenbestand automatisch.

Passwort: siehe `config.php` (`ADMIN_PASSWORD`; Demo-Vorgabe `1234`).

Schnelle Checks: `php -l public/*.php` und `node --check public/js/app.js`.

**Selbsttest (vor jedem Commit):** `php tools/selftest.php` — muss „Alles grün" melden. Neue prüfbare Zusagen in README/docs bekommen dort eine Prüfung (sonst driften Text und Code auseinander). Die CI (`.github/workflows/ci.yml`) läuft ihn auf PHP 8.3 + 8.4.

**Demo lokal bauen/ansehen:** `bash tools/build-demo.sh` → `dist/demo/`, dann `php -S 127.0.0.1:8090 -t dist/demo`.

## Key Behaviors

### Authentifizierung

- **Kein Server-Session-Login und kein Client-Passwort-Cache.**
- JEDE Schutzaktion (Neu anlegen, Bearbeiten, Löschen, Verschieben) fragt das Passwort frisch via Modal ab — bewusst so gewollt (Wand-Tablet ist öffentlich zugänglich). Nicht wieder cachen!
- Das Passwort lebt nur im RAM (`this.pwd`) für die eine laufende Aktion und wird nach Abschluss (Speichern/Löschen/Reorder) oder Abbruch verworfen — `closeModal()` meldet ab.
- Bei jedem API-Call wird es als Header `X-Auth-Password` (oder POST-Feld `password`) mitgesendet; Server vergleicht jedes Mal mit `hash_equals()`.
- 401-Antwort → Client verwirft das Passwort und fragt erneut (`reauth()`; danach geht es mit unveränderten Eingaben weiter).
- Drosselung: 5 Fehlversuche → 429 für 60 s, dann verdoppelnd bis 15 min (`AUTH_*` in `api.php`, Datei `data/auth-throttle.json` mit Adress-Hashes). `X-Forwarded-For` bewusst nicht auswerten (fälschbar).
- Innerhalb einer offenen Formular-Aktion bleibt das Passwort für Retrys erhalten (z. B. nach Validierungsfehler), damit man Eingaben nicht verliert.

### CRUD (alles über das Modal, ohne Seiten-Reload)

- **Anlegen:** Button „Neu" oder Taste `N`.
- **Bearbeiten:** Bleistift-Icon auf der Kachel.
- **Löschen:** Mülleimer-Icon → Bestätigungs-Modal.
- **Verschieben:** Drag & Drop; der Drop verändert das `entries`-Array (splice) und rendert neu — Anzeige == persistierte Reihenfolge. Drop in den Freiraum hängt ans Ende. IDs sind stabil, daher kein Index-Drift zwischen Client und Server.
- Jede Mutation wendet die Server-Antwort (`{version, entries}`) direkt auf den Client-Zustand an.

### Aktualisierung (leises Polling, kein Reload)

- Kein HTTP-`Refresh`-Header.
- Client pollt alle `AUTO_REFRESH_SECONDS` `api.php?action=entries&since=<version>`; `version` ist ein Content-Hash. `changed:false` → nichts passiert.
- Pausiert automatisch bei offenem Modal und während eines Drags; bei `visibilitychange → visible` (Tablet-Wakeup) wird sofort gepollt.

### Markdown

Unterstützt: `#`–`######` Überschriften, `**fett**`, `*kursiv*`, `` `code` ``, `~~strike~~`, `[Text](URL)`, `- Listen`, Absatztrennung durch Leerzeilen.

Sicheres Parsing (nur serverseitig): Text vereinheitlichen (`cleanText()`: CRLF → LF, NUL raus — Browser schicken Textareas mit CRLF!), Links extrahieren (Platzhalter), den gesamten Text escapen, Inline-Patterns anwenden, Links wieder einsetzen — nur mit erlaubtem Schema (`isSafeUrl()`: http(s), mailto, tel, ftp, smb, ssh, vnc, rdp, relativ), sonst bleibt der Linktext. Absätze werden zeilenweise gebildet (Text direkt unter Überschrift/Liste wird eigener Absatz).

## Important Notes

### Security

- `hash_equals()` für Passwortvergleich.
- Datei-Uploads: MIME-Check via `finfo` (nicht via Client-Header), 2 MB Limit, Upload-Fehlercodes geprüft, Dateinamen generiert.
- JSON-Mutationen: exklusives Lock (`entries.json.lock`) über die gesamte Read-Modify-Write-Sequenz + atomares `rename()`.
- Korrupte JSON wirft eine Exception (Fehlerbanner/500) und wird **nie** als leere Liste interpretiert — verhindert Datenverlust durch Folge-Saves. Backups liegen in `data/backups/`.
- `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` serverseitig; im Client werden alle Text-Interpolationen durch `esc()` escaped, `html` kommt ausschließlich vom Server.

### Performance-Prinzipien (nicht rückgängig machen!)

- **Kein `backdrop-filter`** und **keine Endlos-Animationen** im CSS — das war die Ursache für GPU-Dauerlast („zäh"). Aurora ist statisch, Glow-Effekte sind Gradients statt `filter:blur`.
- **Keine externen Font-/CDN-Requests** — Intranet läuft ggf. ohne Internet.
- Kachel-Einblendanimation nur beim ersten Render (`bento--animate`), nicht bei Polling-Updates.
- **Inhalts-Vollständigkeit und Original-Optik haben Vorrang vor One-Page:** Jede Kachel zeigt IMMER ihren kompletten Inhalt (Masonry, kein Clamp/Fade/Abschneiden). Kachelgrößen/Typo bleiben beim 220px-Grundraster mit 0.875rem — Scrollen ist ausdrücklich ok. Keinen automatischen Schrift-Verkleinerungs-Fit einführen, Kacheln nicht verkleinern.
- Referenz-Viewports für Checks: 1180×820, 820×1180 (Tablet), 390×844 (Smartphone — Chips müssen umbrechen, Suchzeile linksbündig unterm Logo, kein horizontaler Overflow).
- Grid-Spalten immer `minmax(0, 1fr)`, nie nacktes `1fr` (sonst drückt ein langes Wort die Spalte über den Viewport); Kacheltext bricht mit `overflow-wrap: anywhere`.

## Öffentliche Demo & Releases

- **Live-Demo:** https://bingerminger.github.io/RookyCMS/ — `tools/build-demo.sh` rendert `index.php` einmal mit dem echten Server und den Demo-Daten, `app.js` beantwortet im Demo-Modus (`data-demo` an `<html>`) die API selbst (`demoFetch()`), Speichern/Löschen sind gesperrt. **Neue API-Actions oder Lesewege brauchen dort eine Antwort.** `.github/workflows/pages.yml` baut aus dem neuesten Release-Tag (bei Release, von Hand); die Pages-Umgebung braucht dafür die Deploy-Regel `tag: v*`.
- **Releases:** SemVer. Pro Version: `VERSION` anheben, Abschnitt `## [X.Y.Z] — JJJJ-MM-TT` oben in `CHANGELOG.md` (dessen erster Satz wird der Release-Titel), Selbsttest grün, annotierter Tag `vX.Y.Z`, GitHub-Release mit dem CHANGELOG-Abschnitt als Text (löst den Demo-Bau aus). Release-Texte sind öffentlich: keine privaten Daten, Mengen oder Infrastruktur.
- **Commits ohne Claude-Signatur** (kein `Co-Authored-By`, kein „Generated with").

### Project-Stack

- **PHP 8.3+** (entwickelt auf 8.4/8.5; keine Features jenseits 8.3 genutzt)
- Reines HTML5, CSS3 (keine Frameworks)
- Vanilla JS (kein jQuery)
- Moderne PHP-Features: Enums, Readonly Properties, Constructor Promotion, typed class constants, First-class-Callable-Syntax

## Weitere Doku

- `README.md` — Nutzer-Doku (Features, Quickstart, Bedienung)
- `docs/INSTALL.md` — Installation (NAS/Apache/nginx/PHP-Built-in)
- `docs/ARCHITECTURE.md` — Rendering-Modell, Persistenz, Polling im Detail
- `docs/API.md` — alle Endpoints mit Parametern und Antworten
- `docs/SECURITY.md` — Auth-Modell, Drosselung, Link-Schemata, Bedrohungsannahmen
- `CHANGELOG.md` — Versionshistorie
- `CREDITS.md`, `TRADEMARKS.md` — Herkunft und Markenhinweise
