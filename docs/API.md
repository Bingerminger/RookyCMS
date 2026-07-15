# API

Alle Endpoints laufen über `public/api.php?action=<name>`. Antworten sind JSON (`Cache-Control: no-store`), Erfolgsantworten tragen `"success": true`.

## Authentifizierung

Schreibende Actions erwarten das Passwort **bei jedem Request** — als Header `X-Auth-Password` oder POST-Feld `password`. Der Server vergleicht timing-safe mit `hash_equals()`. Es gibt keine Session und keine Tokens; 401 bedeutet „Passwort erneut abfragen".

## Übersicht

| Action | Methode | Auth | Zweck |
|--------|---------|------|-------|
| `entries` | GET | – | Bestand lesen / Polling |
| `verify` | POST | Passwort | Passwort prüfen (fürs Modal) |
| `preview` | POST | – | Markdown → HTML (Editor-Vorschau) |
| `save` | POST | ✓ | Eintrag anlegen oder aktualisieren |
| `delete` | POST | ✓ | Eintrag löschen |
| `reorder` | POST | ✓ | Reihenfolge setzen |

Alle Mutationen antworten mit dem kompletten neuen Zustand `{version, entries}` — der Client rendert daraus ohne Nachladen.

---

### `entries` — Bestand & Polling

```
GET api.php?action=entries[&since=<version>]
```

Ohne `since` (oder bei geänderter Version):

```json
{ "success": true, "changed": true, "version": "a1b2…", "entries": [ … ] }
```

Mit unverändertem `since` bleibt die Antwort winzig:

```json
{ "success": true, "changed": false, "version": "a1b2…" }
```

`version` ist ein Content-Hash (`xxh128`) der Datendatei. Jeder Eintrag enthält zusätzlich `html` (server-gerendertes Markdown) und `plain` (Klartext für Suche/Tooltip).

Beispiel-Polling per curl:

```bash
curl 'http://localhost:8000/api.php?action=entries&since=abc123'
```

### `verify` — Passwort prüfen

```
POST api.php?action=verify
password=<pw>
```

`200 {"success":true}` oder `401 {"success":false,"error":"Falsches Passwort"}`.

### `preview` — Markdown-Vorschau

```
POST api.php?action=preview
text=<markdown>
```

Antwortet mit `text/html` (dem fertig escapten Fragment) statt JSON.

### `save` — anlegen / aktualisieren

`multipart/form-data`, Auth erforderlich. Felder:

| Feld | Bedeutung |
|------|-----------|
| `id` | leer = anlegen, sonst = Eintrag aktualisieren |
| `title` | Pflicht (422, wenn leer) |
| `text`, `link`, `category`, `size`, `tags` (komma-getrennt), `featured` (`1`/`0`) | Inhalt |
| `image` | optionaler Datei-Upload (JPEG/PNG/GIF/WebP, max. 2 MB) |
| `delete_image` | `1` = vorhandenes Bild entfernen |

Antwort: `{"success":true,"id":"<id>","version":"…","entries":[…]}`. Fehler: 401 (Auth), 404 (unbekannte id), 422 (Validierung/Upload), 500.

### `delete` — löschen

```
POST api.php?action=delete
id=<id>
```

Entfernt den Eintrag samt zugehörigem Upload-Bild. Antwort wie `save` (ohne `id`).

### `reorder` — Reihenfolge setzen

```
POST api.php?action=reorder
order=["id1","id2", …]   (JSON-Array als String)
```

Nicht genannte IDs werden hinten angehängt (nichts geht verloren). Antwort wie `save`.

---

## Fehlerformat

```json
{ "success": false, "error": "<Meldung>" }
```

| Code | Bedeutung |
|------|-----------|
| 400 | unbekannte Action |
| 401 | Passwort fehlt/falsch |
| 404 | Eintrag nicht gefunden |
| 405 | falsche HTTP-Methode |
| 422 | Validierungs-/Upload-Fehler |
| 500 | Serverfehler (z. B. korrupte Datendatei) |
