# Architektur

## Überblick

```
public/                    ← Webroot
├── index.php              HTML-Shell + Initialdaten (rendert keine Kacheln)
├── api.php                AJAX-API (entries, verify, preview, save, delete, reorder)
├── config.load.php        lädt config.php, legt sie ggf. aus der Vorlage an
├── config.example.php     Konfigurations-Vorlage (im Repo)
├── config.php             echte Konfiguration (pro Instanz, nicht im Repo)
├── CmsManager.php         Persistenz & Geschäftslogik
├── Category.php, Size.php Enums
├── css/styles.css         Aurora (Glas-Optik) + drei leichte Stile, Masonry-Bento
├── js/app.js              CMS-Klasse (Zustand, Rendern, Modal, DnD, Polling, Demo-Modus)
├── .htaccess              Apache: data/, backup/ und Punktdateien → 404
├── uploads/               hochgeladene Bilder (pro Instanz)
└── data/
    ├── entries.json       der komplette Datenbestand
    ├── entries.json.lock  Lock-Datei für serialisierte Schreibzugriffe
    ├── auth-throttle.json Fehlversuche je Client (nur Hashes der Adresse)
    └── backups/           automatische Rotation (max. 15)
demo-data/                 versionierte Demo-Kacheln + -Bilder (Seed beim Erststart)
tools/selftest.php         Selbsttest ohne Abhängigkeiten (Markdown, Links, Persistenz, HTTP-API)
tools/build-demo.sh        rendert die öffentliche Demo als statische Seite
VERSION                    Release-Version (meta generator, Fußzeile, Quellcode-Link)
```

## Rendering-Modell: Server liefert Zustand, Client rendert

`index.php` liefert nur die **Shell** (Topbar, Filter-Chips, leeres Grid, universelles Modal) plus die Initialdaten als JSON (`window.cmsEntries`, `window.cmsConfig`). Alle Kacheln rendert der Client (`js/app.js`, eine `CMS`-Klasse) aus seinem Zustand — per Template-String mit Event-Delegation am Grid.

Jede Mutation (`save`, `delete`, `reorder`) antwortet mit dem **kompletten neuen Zustand** `{version, entries}`; der Client übernimmt ihn und rendert neu. Es gibt keinen Seiten-Reload im Betrieb: kein Flackern, keine verlorenen Formulareingaben, keine Scroll-Sprünge.

**Markdown wird ausschließlich serverseitig geparst.** Jeder Eintrag geht mit zwei zusätzlichen Feldern an den Client: `html` (XSS-sicher gerendertes Markdown) und `plain` (Klartext für Suche und Tooltips). Der Client injiziert nur dieses Server-HTML und escaped alle übrigen Interpolationen selbst (`esc()`).

## Leises Polling

Der Client fragt alle `AUTO_REFRESH_SECONDS` den Endpoint `api.php?action=entries&since=<version>` ab. `version` ist ein **Content-Hash** (`xxh128`) der Datendatei — nicht die mtime, die nur Sekundenauflösung hat. Ist nichts passiert, antwortet der Server winzig mit `{changed:false}` und es wird nichts gerendert.

Das Polling pausiert bei offenem Modal, während eines Drags und solange der Tab verdeckt bzw. das Display aus ist; bei `visibilitychange → visible` (Tablet-Wakeup) wird sofort gepollt.

## Persistenz-Robustheit (JSON-Datei, trotzdem sicher)

- **Stabile IDs:** Jeder Eintrag trägt eine dauerhafte 8-Hex-`id`. Reorder/Delete arbeiten über IDs — kein Index-Drift zwischen Client und Server. Alt-Daten ohne `id` werden beim ersten Zugriff einmalig migriert (unter Lock, mit Pre-Migration-Backup).
- **Durchgehendes Locking:** Alle Mutationen laufen als Read-Modify-Write unter einem exklusiven `flock` auf `entries.json.lock` — kein Lost Update bei parallelen Zugriffen.
- **Atomares Schreiben:** Tempdatei + `rename()`. Die Datendatei ist zu jedem Zeitpunkt vollständig alt oder vollständig neu; ein Stromausfall mitten im Schreiben kann sie nicht zerstören.
- **Backup-Rotation:** Vor Schreibvorgängen entsteht `data/backups/entries-<ts>.json` (gedrosselt auf 1 pro 5 Minuten, die letzten 15 bleiben).
- **Korrupte Daten werden gemeldet, nie „repariert":** Nicht parsebare JSON wirft eine Exception → Fehlerbanner bzw. HTTP 500. Sie wird bewusst **nicht** als leere Liste interpretiert, sonst würde der nächste Save den leeren Zustand persistieren und die Daten wären weg.
- **Normalisierung beim Schreiben:** `order` wird stets auf `0..n` normalisiert, Kategorien/Größen laufen durch die Enums (`tryFromOrAll` / `tryFromOrNormal`).

## Demo-Seed (Erststart)

`CmsManager::seedIfEmpty()` läuft im Konstruktor: Fehlt `entries.json` **und** existiert `demo-data/entries.json`, wird — unter Lock, mit Double-Check gegen parallele Requests — der Demo-Bestand kopiert und die Demo-Bilder wandern nach `public/uploads/` (ohne bestehende Dateien zu überschreiben). Eine Instanz mit vorhandener Datendatei ist nie betroffen; ohne `demo-data/` startet die App wie zuvor leer.

## Uploads

- MIME-Prüfung per `finfo` (nie über den vom Client gemeldeten Typ), erlaubt: JPEG, PNG, GIF, WebP
- Limit 2 MB, Upload-Fehlercodes werden einzeln behandelt, Dateinamen werden generiert (`<time>_<random>.<ext>`)
- Bilder über 1600 px Kantenlänge verkleinert GD in-place (JPEG 82 %, PNG Level 6, WebP 82 %; GIFs bleiben wegen Animation unangetastet; ohne GD wird still übersprungen)
- Beim Löschen/Ersetzen eines Eintrags-Bilds wird die alte Datei entfernt (`basename()`-gehärtet)

## Markdown-Pipeline (XSS-sicher by construction)

Reihenfolge in `CmsManager::parseMarkdown()`:

0. **Text vereinheitlichen** (`cleanText()`): CRLF/CR → LF (Browser schicken Textareas mit CRLF), NUL-Bytes raus (NUL ist das Platzhalter-Zeichen)
1. **Links extrahieren** (`[Text](URL)` → Platzhalter `\x00LINKn\x00`)
2. **Alles escapen** (`htmlspecialchars`, ENT_QUOTES, UTF-8)
3. Überschriften (`#`–`######`), Inline-Formate (fett/kursiv/code/strike), Listen
4. **Links wieder einsetzen** — nur mit erlaubtem Schema (`isSafeUrl()`, sonst bleibt der Linktext), Text und URL einzeln escaped, Ziel `target="_blank" rel="noopener"`
5. Absätze zeilenweise: Leerzeile = neuer `<p>`, einfacher Umbruch = `<br>`; Überschriften und Listen bleiben eigene Blöcke — auch Text direkt darunter (ohne Leerzeile) wird ein Absatz

Dadurch kann kein Nutzer-HTML durchrutschen — es gibt keinen Pfad, auf dem unescapeter Text ins DOM gelangt. `tools/selftest.php` prüft die Pipeline samt verschleierter Schemata.

## Masonry-Bento-Layout

Das Grid nutzt `grid-auto-rows: 2px`; **JS misst jede Kachel** (`layoutTiles()`) und setzt ihren `grid-row-end: span n` auf die Inhaltshöhe. Dadurch zeigt jede Kachel immer ihren kompletten Inhalt (kein Clamp, kein Fade, kein Abschneiden) und die Spalten packen sich dicht.

- Die JS-Konstanten `ROW_UNIT`/`GAP` müssen zu `grid-auto-rows`/`--gap` im CSS passen.
- Spalten: 1/2/3/4/5/6 ab 0/640/960/1280/1600/2000 px; Grundgröße `min-height: 220px` (tall/large: 452px), Kachel-Schriftgröße `--tile-fs: 0.875rem`.
- `wide`/`large` belegen 2 Spalten, `tall`/`large` bekommen die doppelte Grundhöhe; auf Mobile wird alles 1×1.
- Spalten sind `minmax(0, 1fr)`, der Kacheltext bricht mit `overflow-wrap: anywhere` um — eine lange URL kann weder eine Spalte über den Viewport drücken noch unter dem `overflow: hidden` der Kachel verschwinden.
- Gemessen wird ohne Zurücksetzen (seit 1.3.0): Das Grid streckt die Kacheln nicht (`align-items: start`), ihre Höhe ist auch mit gesetztem Span die Inhaltshöhe. Bis 1.2 fiel das Grid zum Messen kurz zusammen, der Scrollbalken verschwand und die Kacheln wurden zu niedrig gemessen — bei festen Scrollbalken (Desktop) ragten sie dann einige Pixel ineinander. Ändert sich die Breite durchs Setzen (Scrollbalken kommt), misst `layoutTiles()` ein zweites Mal. Nach jedem Stilwechsel wird neu gemessen.

## Stile und Tag/Nacht

Zwei Attribute an `<html>`: `data-style` (aurora, klar, minze, schiefer) und `data-theme` (light, dark).

- **Aurora** ist der Standard: Glas-Optik über dem statischen Farbverlauf. Seine Tokens stehen in `:root`/`[data-theme]`.
- **Klar, Minze, Schiefer** sind leicht und flach: Farb-Tokens je Stil und Tageszeit (`[data-style="…"][data-theme="…"]`), das gemeinsame Kartenbild unter `:root:not([data-style="aurora"])` — weiße bzw. dunkle Karten mit feiner Kante und weichem Schatten, Badge in Kategoriefarbe, Bild als Kopfband, schlichte Bedienelemente. Kategorieabhängige Tokens (`--badge-*`, `--chip-*`) werden **an der Kachel bzw. am Chip** deklariert: Ein Custom Property mit `var(--hue)` wird dort aufgelöst, wo es steht — auf `:root` gäbe es kein `--hue`. (Aurora nutzt `--tile-tint-*` auf `:root`; die lösen sich deshalb zu nichts auf. Das ist seit 1.0 so und gehört zu Auroras Optik — nicht „reparieren", ohne das Aussehen bewusst zu ändern.)
- **Tag/Nacht** hat drei Modi wie im Energietracker: automatisch (Standard, kein Eintrag in `localStorage`), immer hell, immer dunkel (`cms-mode`). Das Vor-Paint-Skript in `index.php` setzt beides vor dem ersten Bild (kein Aufblitzen); `bindTheme()` folgt im Automatikmodus dem Gerät live (`matchMedia('(prefers-color-scheme: light)')`), zeigt am Knopf die wirksame Darstellung plus „A" und meldet jeden Wechsel kurz. `theme-color` folgt `--bg-0` des aktiven Stils.
- Gespeicherte Stile aus 1.2 werden abgebildet (Graphit/Fluent → Klar, Nord → Schiefer, Ubuntu → Minze); der alte Schlüssel `cms-theme` wird entfernt, damit jedes Gerät auf „automatisch" startet.
- Die Stil-Liste steht in `STYLES` (app.js) und im Vor-Paint-Skript; `tools/selftest.php` prüft, dass beide und das CSS übereinstimmen.

## Öffentliche Demo (GitHub Pages)

GitHub Pages hat kein PHP. `tools/build-demo.sh` startet deshalb den echten Server auf einer Wegwerf-Kopie (frisches `data/`, Seed aus `demo-data/`), speichert die gerenderte Seite als `index.html` mit `data-demo` an `<html>` und kopiert `css/`, `js/`, die Demo-Bilder und die Lizenz dazu. Der Bau bricht ab, wenn die API nicht antwortet, die Kachelzahl nicht stimmt oder die Seite ein Fehlerbanner zeigt.

Im Browser erkennt `app.js` den Demo-Modus an `data-demo` und beantwortet die API in `demoFetch()` selbst: Lesen, Filtern, Suchen, Stile, Bearbeiten-Formular (Passwort `1234`) und Verschieben funktionieren, Speichern und Löschen werden mit einem Hinweis abgelehnt, Polling und Markdown-Vorschau entfallen. Eine normale Installation führt diesen Zweig nie aus. Neue API-Actions brauchen dort eine Antwort.

`.github/workflows/pages.yml` baut die Demo aus dem neuesten Release-Tag (bei jedem veröffentlichten Release und von Hand); die Pages-Umgebung erlaubt dafür Deploys von Tags `v*`.

## Selbsttest & Release-Stempel

`php tools/selftest.php` läuft ohne Abhängigkeiten: Markdown- und Link-Sicherheit, Persistenz (Seed, Normalisierung, Reorder, korrupte Datei, Migration), die HTTP-API in einer Wegwerf-Kopie unter `php -S` (Auth, 422/405, Script-Block, Drosselung) und den Release-Stempel (`VERSION` = oberster Abschnitt in `CHANGELOG.md`). Die CI (`.github/workflows/ci.yml`) führt ihn auf PHP 8.3 und 8.4 aus, dazu einen Probebau der Demo.

Die Datei `VERSION` ist die einzige Quelle der Release-Nummer: `index.php` schreibt sie in `<meta name="generator">` und in die Fußzeile, deren Quellcode-Link auf genau diesen Tag zeigt (`…/tree/vX.Y.Z`, AGPL-3.0 §13). Die Deploy-Prüfung vergleicht die ausgelieferte Kennung mit `VERSION`.

## Performance-Prinzipien

Diese Regeln sind der Grund, warum das Dashboard auf Tablets dauerhaft flüssig läuft — nicht rückgängig machen:

- **Kein `backdrop-filter`, nirgends.** Echtzeit-Blur pro Kachel war die Hauptursache für GPU-Dauerlast. Die Glas-Optik entsteht aus halbtransparenten Farbschichten.
- **Statischer Aurora-Hintergrund:** reine Gradients, keine Animation, kein `filter:blur` — einmal gerastert, kostet danach nichts.
- **Keine Endlos-Animationen;** Übergänge nur auf compositor-freundlichen Eigenschaften (transform, opacity, box-shadow, Farben). Kachel-Einblendung nur beim ersten Render.
- **Keine externen Font-/CDN-Requests** — Systemschriften; das Intranet läuft ggf. ohne Internet.
