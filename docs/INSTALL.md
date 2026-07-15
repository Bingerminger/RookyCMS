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

- Der Ordner `demo-data/` liegt dann ebenfalls im Web-Pfad. Das ist unkritisch (er enthält nur die öffentlichen Demo-Inhalte), kann aber per Webserver-Regel gesperrt werden.
- RookyCMS setzt selbst keine Cache-Header für Assets; CSS/JS tragen einen mtime-basierten Cache-Buster (`?v=`), damit Browser nach Updates nie auf alten Versionen hängen bleiben.

## Variante 3: Apache

Webroot auf `public/` zeigen lassen:

```apache
<VirtualHost *:80>
    ServerName dashboard.example.lan
    DocumentRoot /var/www/rookycms/public
    <Directory /var/www/rookycms/public>
        Require all granted
        AllowOverride None
    </Directory>
</VirtualHost>
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
  <neue-version>/ <installation>/
```

Alt-Datenbestände ohne Eintrags-IDs migriert die App beim ersten Aufruf selbst (mit Pre-Migration-Backup in `data/backups/`).

## Sicherung

- Automatisch: `public/data/backups/` — vor Schreibvorgängen, gedrosselt (max. 1 pro 5 Minuten), Rotation auf die letzten 15.
- Manuell reicht es, `public/data/`, `public/uploads/` und `public/config.php` zu kopieren — das ist die komplette Instanz.
