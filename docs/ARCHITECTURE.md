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
├── css/styles.css         Aurora, Glas-Optik, Masonry-Bento
├── js/app.js              CMS-Klasse (Zustand, Rendern, Modal, DnD, Polling)
├── uploads/               hochgeladene Bilder (pro Instanz)
└── data/
    ├── entries.json       der komplette Datenbestand
    ├── entries.json.lock  Lock-Datei für serialisierte Schreibzugriffe
    └── backups/           automatische Rotation (max. 15)
demo-data/                 versionierte Demo-Kacheln + -Bilder (Seed beim Erststart)
```

## Rendering-Modell: Server liefert Zustand, Client rendert

`index.php` liefert nur die **Shell** (Topbar, Filter-Chips, leeres Grid, universelles Modal) plus die Initialdaten als JSON (`window.cmsEntries`, `window.cmsConfig`). Alle Kacheln rendert der Client (`js/app.js`, eine `CMS`-Klasse) aus seinem Zustand — per Template-String mit Event-Delegation am Grid.

Jede Mutation (`save`, `delete`, `reorder`) antwortet mit dem **kompletten neuen Zustand** `{version, entries}`; der Client übernimmt ihn und rendert neu. Es gibt keinen Seiten-Reload im Betrieb: kein Flackern, keine verlorenen Formulareingaben, keine Scroll-Sprünge.

**Markdown wird ausschließlich serverseitig geparst.** Jeder Eintrag geht mit zwei zusätzlichen Feldern an den Client: `html` (XSS-sicher gerendertes Markdown) und `plain` (Klartext für Suche und Tooltips). Der Client injiziert nur dieses Server-HTML und escaped alle übrigen Interpolationen selbst (`esc()`).

## Leises Polling

Der Client fragt alle `AUTO_REFRESH_SECONDS` den Endpoint `api.php?action=entries&since=<version>` ab. `version` ist ein **Content-Hash** (`xxh128`) der Datendatei — nicht die mtime, die nur Sekundenauflösung hat. Ist nichts passiert, antwortet der Server winzig mit `{changed:false}` und es wird nichts gerendert.

Das Polling pausiert bei offenem Modal und während eines Drags; bei `visibilitychange → visible` (Tablet-Wakeup) wird sofort gepollt.

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

1. **Links extrahieren** (`[Text](URL)` → Platzhalter `\x00LINKn\x00`)
2. **Alles escapen** (`htmlspecialchars`, ENT_QUOTES, UTF-8)
3. Überschriften (`#`–`######`), Inline-Formate (fett/kursiv/code/strike), Listen
4. **Links wieder einsetzen** — Text und URL werden dabei einzeln escaped, Ziel `target="_blank" rel="noopener"`
5. Absätze: Doppel-Newline = `<p>`, einfache = `<br>`

Dadurch kann kein Nutzer-HTML durchrutschen — es gibt keinen Pfad, auf dem unescapeter Text ins DOM gelangt.

## Masonry-Bento-Layout

Das Grid nutzt `grid-auto-rows: 2px`; **JS misst jede Kachel** (`layoutTiles()`) und setzt ihren `grid-row-end: span n` auf die Inhaltshöhe. Dadurch zeigt jede Kachel immer ihren kompletten Inhalt (kein Clamp, kein Fade, kein Abschneiden) und die Spalten packen sich dicht.

- Die JS-Konstanten `ROW_UNIT`/`GAP` müssen zu `grid-auto-rows`/`--gap` im CSS passen.
- Spalten: 1/2/3/4/5/6 ab 0/640/960/1280/1600/2000 px; Grundgröße `min-height: 220px` (tall/large: 452px), Kachel-Schriftgröße `--tile-fs: 0.875rem`.
- `wide`/`large` belegen 2 Spalten, `tall`/`large` bekommen die doppelte Grundhöhe; auf Mobile wird alles 1×1.

## Performance-Prinzipien

Diese Regeln sind der Grund, warum das Dashboard auf Tablets dauerhaft flüssig läuft — nicht rückgängig machen:

- **Kein `backdrop-filter`, nirgends.** Echtzeit-Blur pro Kachel war die Hauptursache für GPU-Dauerlast. Die Glas-Optik entsteht aus halbtransparenten Farbschichten.
- **Statischer Aurora-Hintergrund:** reine Gradients, keine Animation, kein `filter:blur` — einmal gerastert, kostet danach nichts.
- **Keine Endlos-Animationen;** Übergänge nur auf compositor-freundlichen Eigenschaften (transform, opacity, box-shadow, Farben). Kachel-Einblendung nur beim ersten Render.
- **Keine externen Font-/CDN-Requests** — Systemschriften; das Intranet läuft ggf. ohne Internet.
