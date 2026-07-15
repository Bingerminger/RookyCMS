# Sicherheit

## Bedrohungsmodell

RookyCMS ist für den Betrieb im **vertrauenswürdigen lokalen Netz** (Heim-Intranet, kleines Büro) entworfen. Das typische Anzeigegerät — ein Wand-Tablet — ist physisch für jeden im Haushalt erreichbar; genau daraus leiten sich die Design-Entscheidungen ab:

- Lesen darf jeder im Netz, Schreiben nur, wer das Passwort kennt.
- Auf dem Anzeigegerät darf **niemals eine angemeldete Sitzung zurückbleiben**.

**RookyCMS ist nicht dafür gedacht, ungeschützt im öffentlichen Internet zu stehen.** Wer es exponieren will, sollte mindestens einen Reverse Proxy mit HTTPS und zusätzlicher Auth-Schicht (Basic Auth, VPN, Zero-Trust-Tunnel) davorschalten — das Kachel-Passwort wandert sonst im Klartext übers Netz.

## Authentifizierung: bewusst ohne Sessions

- **Kein Server-Session-Login, kein Passwort-Cache im Client.** Jede Schutzaktion (Anlegen, Bearbeiten, Löschen, Verschieben) fragt das Passwort frisch ab.
- Das Passwort lebt im Client nur im RAM für die eine laufende Aktion und wird nach Abschluss oder Abbruch verworfen — nach dem Speichern ist man automatisch „abgemeldet".
- Übertragung pro Request als Header `X-Auth-Password` (oder POST-Feld); Vergleich serverseitig timing-safe per `hash_equals()`.
- Eine 401-Antwort verwirft das Passwort im Client und öffnet erneut den Passwort-Dialog.

Warum kein „Angemeldet bleiben"? Ein Wand-Tablet mit persistenter Session wäre eine offene Admin-Konsole für jeden Gast. Der kleine Reibungsverlust (PIN pro Aktion) ist hier das Feature.

## Upload-Härtung

- MIME-Typ wird per `finfo` aus dem Dateiinhalt bestimmt — der vom Client gemeldete Typ wird ignoriert.
- Whitelist: JPEG, PNG, GIF, WebP; Limit 2 MB; PHP-Upload-Fehlercodes werden einzeln geprüft.
- Dateinamen werden **generiert** (`<time>_<random>.<ext>`) — kein Pfad-/Namens-Einfluss des Clients, beim Löschen zusätzlich `basename()`.
- Optionales Downscaling (GD) reduziert Dekodier-Last auf dem Anzeigegerät.

## XSS-Schutz

- Markdown wird ausschließlich **serverseitig** geparst; die Pipeline escaped erst den gesamten Text und setzt erst danach Formatierung und (einzeln escapte) Links ein — es existiert kein Pfad für unescapetes Nutzer-HTML.
- Links aus Markdown erhalten `target="_blank" rel="noopener"`.
- Im Client laufen alle übrigen Interpolationen durch `esc()`; als `innerHTML` wird nur das Server-HTML verwendet.
- Serverseitige Ausgaben in der Shell: `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')`.

## Datenintegrität

- Alle Mutationen: exklusives `flock` über die gesamte Read-Modify-Write-Sequenz, geschrieben wird atomar (Tempdatei + `rename()`).
- Korrupte JSON → Exception und Fehlerbanner, **nie** stille Interpretation als leere Liste (das würde beim nächsten Save die Daten vernichten).
- Automatische Backup-Rotation in `data/backups/` (max. 15, gedrosselt).

## Instanz-Geheimnisse & Repository

`public/config.php` (echtes Passwort), `public/data/`, `public/uploads/` sind per `.gitignore` vom Repository ausgeschlossen — im Repo liegen nur `config.example.php` (Demo-PIN `1234`) und die öffentlichen Demo-Daten. Wer forkt/deployt: eigenes Passwort setzen, private Kacheln nie in `demo-data/` committen.

## Meldungen

Sicherheitsprobleme bitte als GitHub-Issue melden (bei sensiblen Details gern mit Hinweis, dann wird ein privater Kanal vereinbart).
