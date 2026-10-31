# Sicherheit

## Bedrohungsmodell

RookyCMS ist für den Betrieb im **vertrauenswürdigen lokalen Netz** (Heim-Intranet, kleines Büro) entworfen. Das typische Anzeigegerät — ein Wand-Tablet — ist physisch für jeden im Haushalt erreichbar; genau daraus leiten sich die Design-Entscheidungen ab:

- Lesen darf jeder im Netz, Schreiben nur, wer das Passwort kennt.
- Auf dem Anzeigegerät darf **niemals eine angemeldete Sitzung zurückbleiben**.
- Eine kurze PIN soll sich nicht in Minuten durchprobieren lassen.

**RookyCMS ist nicht dafür gedacht, ungeschützt im öffentlichen Internet zu stehen.** Wer es exponieren will, sollte mindestens einen Reverse Proxy mit HTTPS und zusätzlicher Auth-Schicht (Basic Auth, VPN, Zero-Trust-Tunnel) davorschalten — das Kachel-Passwort wandert sonst im Klartext übers Netz.

## Authentifizierung: bewusst ohne Sessions

- **Kein Server-Session-Login, kein Passwort-Cache im Client.** Jede Schutzaktion (Anlegen, Bearbeiten, Löschen, Verschieben) fragt das Passwort frisch ab.
- Das Passwort lebt im Client nur im RAM für die eine laufende Aktion und wird nach Abschluss oder Abbruch verworfen — nach dem Speichern ist man automatisch „abgemeldet".
- Übertragung pro Request als Header `X-Auth-Password` (oder POST-Feld); Vergleich serverseitig timing-safe per `hash_equals()`.
- Eine 401-Antwort mitten in einer offenen Aktion (etwa weil das Passwort inzwischen geändert wurde) verwirft das Passwort im Client und öffnet erneut den Passwort-Dialog; danach geht es mit den unveränderten Eingaben weiter.

Warum kein „Angemeldet bleiben"? Ein Wand-Tablet mit persistenter Session wäre eine offene Admin-Konsole für jeden Gast. Der kleine Reibungsverlust (PIN pro Aktion) ist hier das Feature.

### Drosselung von Fehlversuchen (seit 1.2.0)

- Nach **5 falschen Passwörtern in Folge** antwortet die API für **60 Sekunden** mit `429` (`Retry-After`), jede weitere Sperre dauert doppelt so lang, höchstens 15 Minuten. Während einer Sperre wird auch das richtige Passwort nicht geprüft.
- Ein Treffer setzt den Zähler zurück; nach einem Tag ohne Fehlversuch verfällt er.
- Gezählt wird je Client-Adresse (`REMOTE_ADDR`). In `public/data/auth-throttle.json` steht nur ein **SHA-256-Hash** der Adresse, keine IP im Klartext — Datensparsamkeit, keine Anonymisierung (ein Heimnetz hat nur wenige Adressen; die Datei liegt deshalb wie alles in `data/` hinter der Sperre unten).
- `X-Forwarded-For` wird bewusst ignoriert — sonst könnte jeder die Sperre mit erfundenen Adressen umgehen. Steht ein Reverse Proxy davor, der die Client-Adresse nicht durchreicht, teilen sich alle Geräte einen Zähler: Wer fünfmal danebenliegt, sperrt dann für eine Minute auch das Tablet.
- Ohne Schreibrecht auf `public/data/` entfällt die Drosselung; die Passwortprüfung selbst nie.

## Datenordner nicht ausliefern

`public/data/` enthält den kompletten Datenbestand samt Backups — auch von bereits gelöschten Kacheln — und die Drossel-Datei. Die App liest das selbst; direkt per URL soll es niemand abrufen können:

- **Apache** (z. B. Synology Web Station): Die mitgelieferte `public/.htaccess` beantwortet `data/`, `backup/` und Punktdateien mit 404 (braucht nur `AllowOverride FileInfo`).
- **nginx** liest keine `.htaccess` — die nötige `location`-Regel steht in [INSTALL.md](INSTALL.md).
- Prüfen: `curl -I http://<host>/<pfad>/data/entries.json` muss 403 oder 404 liefern, nicht 200.

## Upload-Härtung

- MIME-Typ wird per `finfo` aus dem Dateiinhalt bestimmt — der vom Client gemeldete Typ wird ignoriert.
- Whitelist: JPEG, PNG, GIF, WebP; Limit 2 MB; PHP-Upload-Fehlercodes werden einzeln geprüft.
- Dateinamen werden **generiert** (`<time>_<random>.<ext>`) — kein Pfad-/Namens-Einfluss des Clients, beim Löschen zusätzlich `basename()`.
- Ein Upload, der keinem Eintrag zugeordnet werden kann (Eintrag inzwischen gelöscht), wird wieder entfernt.
- Optionales Downscaling (GD) reduziert Dekodier-Last auf dem Anzeigegerät.

## XSS-Schutz

- Markdown wird ausschließlich **serverseitig** geparst; die Pipeline escaped erst den gesamten Text und setzt erst danach Formatierung und (einzeln escapte) Links ein — es existiert kein Pfad für unescapetes Nutzer-HTML.
- **Link-Schemata (seit 1.2.0):** Als `href` gehen nur `http`, `https`, `mailto`, `tel`, `ftp`, `smb`, `ssh`, `vnc`, `rdp` und relative Pfade durch (`CmsManager::isSafeUrl()`). `javascript:`, `data:`, `vbscript:`, `file:` & Co. bleiben reiner Linktext — auch verschleiert (`java\tscript:`), weil Steuer- und Leerzeichen vor der Prüfung entfernt werden. Das Feld „Link" lehnt solche Adressen beim Speichern mit 422 ab; für Altdaten liefert der Server das geprüfte Linkziel getrennt als `href`.
- Links aus Markdown erhalten `target="_blank" rel="noopener"`.
- Im Client laufen alle übrigen Interpolationen durch `esc()`; als `innerHTML` wird nur das Server-HTML verwendet.
- Die Initialdaten im `<script>`-Block der Seite sind mit `JSON_HEX_TAG`/`JSON_HEX_AMP`/`JSON_HEX_QUOT`/`JSON_HEX_APOS` kodiert — kein Kachelinhalt (etwa `<!--<script>`) kann den Script-Block des HTML-Parsers aufbrechen.
- Serverseitige Ausgaben in der Shell: `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')`; `X-Content-Type-Options: nosniff` auf Seite und API.

## Datenintegrität

- Alle Mutationen: exklusives `flock` über die gesamte Read-Modify-Write-Sequenz, geschrieben wird atomar (Tempdatei + `rename()`).
- Korrupte JSON → Exception und Fehlerbanner, **nie** stille Interpretation als leere Liste (das würde beim nächsten Save die Daten vernichten).
- Automatische Backup-Rotation in `data/backups/` (max. 15, gedrosselt).

## Geprüft, nicht nur behauptet

`php tools/selftest.php` deckt die Zusagen dieser Seite ab — Link-Schemata (auch verschleiert), Escaping, Script-Block, Drosselung (5 × 401, dann 429), Persistenz und korrupte Dateien. Die CI (`.github/workflows/ci.yml`) führt ihn bei jedem Push aus.

## Instanz-Geheimnisse & Repository

`public/config.php` (echtes Passwort), `public/data/`, `public/uploads/` sind per `.gitignore` vom Repository ausgeschlossen — im Repo liegen nur `config.example.php` (Demo-PIN `1234`) und die öffentlichen Demo-Daten. Wer forkt/deployt: eigenes Passwort setzen, private Kacheln nie in `demo-data/` committen.

`tools/` gehört nicht auf den Webserver. Landet es doch dort (ganzes Projekt im Web-Pfad), antwortet `tools/selftest.php` außerhalb der Kommandozeile nur mit 404.

## Meldungen

Sicherheitsprobleme bitte als GitHub-Issue melden (bei sensiblen Details gern mit Hinweis, dann wird ein privater Kanal vereinbart).
