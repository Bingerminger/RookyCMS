# Installation & Betrieb

## Anforderungen

- **PHP 8.3 oder neuer** (getestet mit 8.3, 8.4 und 8.5)
- PHP-Extensions: `json`, `fileinfo` (Standard); **`gd` optional** — verkleinert große Upload-Bilder automatisch auf 1600 px
- Schreibrecht des Webserver-Users auf `public/data/` und `public/uploads/` (werden automatisch angelegt)
- Kein Composer, keine Datenbank, kein Build-Schritt

## Variante 1: PHP-Built-in-Server (Entwicklung / Ausprobieren)

```bash
cd public
php -S localhost:8000
```

`http://localhost:8000` aufrufen. Beim ersten Start entstehen `config.php` (aus der Vorlage, Demo-Passwort `1234`) und der Demo-Datenbestand automatisch.

## Variante 2: NAS (z. B. Synology Web Station)

1. Projektordner in eine Web-Freigabe legen (z. B. `web/rookycms/`).
2. In der Web Station ein PHP-Profil 8.3+ zuweisen; `fileinfo` ist Standard, `gd` empfohlen.
3. Aufruf im Browser: `http://<nas>/rookycms/public`.

Hinweise für diesen Unterverzeichnis-Betrieb:

- Die Web Station läuft mit Apache (nginx davor) und wertet `.htaccess` aus. Die mitgelieferte `public/.htaccess` sperrt damit `public/data/` (Datenbestand + Backups) und `public/backup/` — kein Handgriff nötig. Kontrolle nach der Installation: `http://<nas>/rookycms/public/data/entries.json` muss 404 liefern.
- Der Ordner `demo-data/` liegt dann ebenfalls im Web-Pfad. Das ist unkritisch (er enthält nur die öffentlichen Demo-Inhalte). `tools/` und `.github/` braucht der Server nicht — einfach weglassen; `tools/selftest.php` antwortet über das Web ohnehin nur mit 404.
- RookyCMS setzt selbst keine Cache-Header für Assets; CSS/JS tragen einen mtime-basierten Cache-Buster (`?v=`), damit Browser nach Updates nie auf alten Versionen hängen bleiben.

## Variante 3: Apache

Webroot auf `public/` zeigen lassen. `AllowOverride FileInfo` genügt, damit die mitgelieferte `public/.htaccess` den Datenordner sperrt:

```apache
<VirtualHost *:80>
    ServerName dashboard.example.lan
    DocumentRoot /var/www/rookycms/public
    <Directory /var/www/rookycms/public>
        Require all granted
        AllowOverride FileInfo
    </Directory>
</VirtualHost>
```

Wer `.htaccess` grundsätzlich abschaltet (`AllowOverride None`), sperrt die Ordner direkt im VirtualHost:

```apache
    <DirectoryMatch "^/var/www/rookycms/public/(data|backup)">
        Require all denied
    </DirectoryMatch>
```

## Variante 4: nginx + PHP-FPM

```nginx
server {
    listen 80;
    server_name dashboard.example.lan;
    root /var/www/rookycms/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php;
    }
    # Datenbestand, Backups und Punktdateien nie ausliefern
    location ~ ^/(data|backup)(/|$) { return 404; }
    location ~ /\.                  { return 404; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }
}
```

## Erststart & Demo-Daten

Beim allerersten Aufruf ohne Datenbestand:

1. `config.load.php` kopiert `config.example.php` → `config.php`.
2. `CmsManager::seedIfEmpty()` übernimmt `demo-data/entries.json` als Startbestand und kopiert die Demo-Bilder nach `public/uploads/`.

Der Seed läuft **nur**, wenn `public/data/entries.json` fehlt — eine bestehende Instanz (auch eine bewusst leergeräumte, deren Datei ja existiert) wird nie angefasst. Wer ohne Demo starten will: nach dem ersten Start alle Demo-Kacheln löschen, oder vor dem ersten Aufruf eine leere Liste anlegen (`echo '[]' > public/data/entries.json`).

**Direkt nach der Installation: Passwort in `public/config.php` ändern.**

## Update auf eine neue Version

Nur der Code wird ersetzt — der Instanz-Zustand bleibt:

```bash
rsync -a --delete \
  --exclude 'public/config.php' \
  --exclude 'public/data/' \
  --exclude 'public/uploads/' \
  --exclude 'public/backup/' \
  --exclude 'tools/' --exclude 'dist/' --exclude '.git/' --exclude '.github/' \
  <neue-version>/ <installation>/
```

Alt-Datenbestände ohne Eintrags-IDs migriert die App beim ersten Aufruf selbst (mit Pre-Migration-Backup in `data/backups/`).

Welche Version läuft, steht in der Fußzeile und im Seitenkopf (`<meta name="generator" content="RookyCMS X.Y.Z">`):

```bash
curl -s http://<host>/<pfad>/ | grep -o 'RookyCMS [0-9.]*'
curl -s -o /dev/null -w '%{http_code}\n' http://<host>/<pfad>/data/entries.json   # 403/404, nie 200
```

**Update auf 1.2.0:** Kacheltexte, die im Browser gespeichert wurden, tragen Windows-Zeilenenden (CRLF). Bis 1.1.0 gingen dadurch Absätze verloren; ab 1.2.0 werden sie beim Anzeigen und beim nächsten Speichern vereinheitlicht — betroffene Kacheln zeigen ihre Absätze danach wie vorgesehen, ohne Zutun.

**Update auf 1.3.0:** Die Stile Graphit, Fluent, Ubuntu und Nord sind durch Klar, Minze und Schiefer ersetzt; eine gespeicherte Auswahl wechselt beim ersten Aufruf von selbst auf den Nachfolger (Graphit/Fluent → Klar, Nord → Schiefer, Ubuntu → Minze). Tag/Nacht startet auf jedem Gerät einmal im Automatikmodus und folgt dann dem Gerät — wer es fest will, tippt auf Sonne/Mond. Auf dem Anzeigegerät einmal neu laden.

## Sicherung

- Automatisch: `public/data/backups/` — vor Schreibvorgängen, gedrosselt (max. 1 pro 5 Minuten), Rotation auf die letzten 15.
- Manuell reicht es, `public/data/`, `public/uploads/` und `public/config.php` zu kopieren — das ist die komplette Instanz.
