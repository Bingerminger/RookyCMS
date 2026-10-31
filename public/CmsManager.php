<?php
declare(strict_types=1);

/**
 * RookyCMS — Datenzugriff & Geschäftslogik.
 *
 * Speicherung: JSON-Datei, keine Datenbank.
 * Robustheit:
 *  - Stabile String-IDs pro Eintrag (unabhängig von der Position).
 *  - Alle Mutationen laufen als Read-Modify-Write unter einem exklusiven
 *    Lock auf einer separaten .lock-Datei (kein Lost Update).
 *  - Geschrieben wird atomar: Tempdatei + rename() — die Datei ist zu
 *    jedem Zeitpunkt entweder alt oder neu, nie halb.
 *  - Automatische Backup-Rotation in data/backups/.
 *  - Korrupte JSON wird gemeldet (Exception) statt stillschweigend als
 *    leere Liste interpretiert (verhinderte Datenverlust beim Folge-Save).
 */
final class CmsManager
{
    private const int MAX_FILESIZE = 2 * 1024 * 1024;
    private const array ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** Bilder beim Upload auf diese Kantenlänge verkleinern (0 = aus). */
    private const int MAX_IMAGE_DIM = 1600;

    /** Backup höchstens alle N Sekunden anlegen, danach rotieren. */
    private const int BACKUP_MIN_INTERVAL = 300;
    private const int BACKUP_KEEP = 15;

    private string $backupDir;

    public function __construct(
        private readonly string $dataFile,
        private readonly string $uploadDir,
        private readonly ?string $seedDir = null,
    ) {
        $dataDir = dirname($this->dataFile);
        $this->backupDir = $dataDir . '/backups';
        foreach ([$dataDir, $this->uploadDir, $this->backupDir] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException("Verzeichnis nicht anlegbar: {$dir}");
            }
        }
        $this->seedIfEmpty();
    }

    /**
     * Erststart ohne Datenbestand: Liegt ein Seed-Verzeichnis mit
     * entries.json bereit (die Demo-Daten aus dem Repository), wird es
     * als Startbestand übernommen — inklusive der Demo-Bilder. Eine
     * Instanz mit vorhandener entries.json ist davon nie betroffen.
     */
    private function seedIfEmpty(): void
    {
        if ($this->seedDir === null || is_file($this->dataFile)) {
            return;
        }
        $seedEntries = $this->seedDir . '/entries.json';
        if (!is_file($seedEntries)) {
            return;
        }
        $this->withLock(function () use ($seedEntries): void {
            if (is_file($this->dataFile)) {
                return; // ein paralleler Request hat bereits geseedet
            }
            foreach (glob($this->seedDir . '/uploads/*') ?: [] as $img) {
                $dest = $this->uploadDir . basename($img);
                if (!is_file($dest)) {
                    @copy($img, $dest);
                }
            }
            @copy($seedEntries, $this->dataFile);
        });
    }

    // -------------------------------------------------------------------
    // Lesen
    // -------------------------------------------------------------------

    /**
     * Alle Einträge, nach `order` sortiert. Migriert Alt-Daten ohne `id`
     * einmalig (unter Lock, mit Pre-Migration-Backup) — die Prüfung läuft
     * auf den ROHDATEN, weil normalize() fehlende IDs bereits auffüllt.
     *
     * @return list<array<string,mixed>>
     * @throws RuntimeException bei korrupter Datei
     */
    public function getEntries(): array
    {
        $raw = $this->readRaw();

        if ($this->needsMigration($raw)) {
            return $this->mutate(fn(array $e) => $e, migration: true);
        }
        return $this->prepare($raw);
    }

    public function getEntry(string $id): ?array
    {
        foreach ($this->getEntries() as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Änderungs-Token für das Client-Polling. Inhaltsbasiert (Hash),
     * damit auch zwei Writes innerhalb derselben Sekunde unterscheidbar
     * sind — mtime hat nur Sekundenauflösung.
     */
    public function getVersion(): string
    {
        clearstatcache(true, $this->dataFile);
        if (!is_file($this->dataFile)) {
            return 'empty';
        }
        return (string)hash_file('xxh128', $this->dataFile);
    }

    /**
     * Einträge in der Form, die der Client zum Rendern braucht:
     * Rohdaten (fürs Edit-Formular) + serverseitig gerendertes,
     * XSS-sicheres HTML + Klartext für Suche/Tooltip.
     *
     * @return list<array<string,mixed>>
     */
    public function getEntriesForClient(): array
    {
        return array_map(static function (array $e): array {
            $e['html']  = self::parseMarkdown($e['text']);
            $e['plain'] = self::stripMarkdown($e['text']);
            // Ziel des „Öffnen"-Links nur mit erlaubtem Schema (Altdaten
            // könnten noch javascript: o. ä. tragen); `link` bleibt fürs Formular roh.
            $e['href']  = self::isSafeUrl($e['link']) ? $e['link'] : '';
            return $e;
        }, $this->getEntries());
    }

    // -------------------------------------------------------------------
    // Mutationen (alle unter durchgehendem Lock)
    // -------------------------------------------------------------------

    public function addEntry(array $data): array
    {
        $id = null;
        $this->mutate(function (array $entries) use ($data, &$id): array {
            $id = $this->newId($entries);
            $entries[] = $this->normalize([
                'id'         => $id,
                'title'      => self::cleanText((string)($data['title'] ?? '')),
                'text'       => self::cleanText((string)($data['text']  ?? '')),
                'link'       => self::cleanText((string)($data['link']  ?? '')),
                'image'      => $data['image']    ?? null,
                'category'   => $data['category'] ?? Category::All->value,
                'size'       => $data['size']     ?? Size::Normal->value,
                'tags'       => $data['tags']     ?? [],
                'featured'   => (bool)($data['featured'] ?? false),
                'order'      => count($entries),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return $entries;
        });
        return ['success' => true, 'id' => $id];
    }

    public function updateEntry(
        string $id,
        array $data,
        ?string $newImage = null,
        bool $deleteImage = false,
    ): array {
        $found = false;
        $this->mutate(function (array $entries) use ($id, $data, $newImage, $deleteImage, &$found): array {
            foreach ($entries as $i => $current) {
                if ($current['id'] !== $id) {
                    continue;
                }
                $found = true;
                $merged = [
                    'id'         => $id,
                    'title'      => self::cleanText((string)($data['title'] ?? $current['title'] ?? '')),
                    'text'       => self::cleanText((string)($data['text']  ?? $current['text']  ?? '')),
                    'link'       => self::cleanText((string)($data['link']  ?? $current['link']  ?? '')),
                    'category'   => $data['category'] ?? $current['category'] ?? Category::All->value,
                    'size'       => $data['size']     ?? $current['size']     ?? Size::Normal->value,
                    'tags'       => $data['tags']     ?? $current['tags']     ?? [],
                    'featured'   => (bool)($data['featured'] ?? $current['featured'] ?? false),
                    'order'      => (int)($current['order'] ?? $i),
                    'created_at' => $current['created_at'] ?? date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                    'image'      => $current['image'] ?? null,
                ];
                // Ein neues Bild ersetzt das alte (auch wenn vorher „Entfernen"
                // geklickt wurde); sonst entfernt delete_image das vorhandene.
                if ($newImage !== null) {
                    $this->unlinkImage($current['image'] ?? null);
                    $merged['image'] = $newImage;
                } elseif ($deleteImage) {
                    $this->unlinkImage($current['image'] ?? null);
                    $merged['image'] = null;
                }
                $entries[$i] = $this->normalize($merged);
                break;
            }
            return $entries;
        });
        if (!$found) {
            $this->unlinkImage($newImage); // Upload ohne Ziel nicht liegen lassen
        }
        return $found
            ? ['success' => true, 'id' => $id]
            : ['success' => false, 'error' => 'Eintrag nicht gefunden'];
    }

    public function deleteEntry(string $id): bool
    {
        $found = false;
        $this->mutate(function (array $entries) use ($id, &$found): array {
            $kept = [];
            foreach ($entries as $entry) {
                if ($entry['id'] === $id) {
                    $found = true;
                    $this->unlinkImage($entry['image'] ?? null);
                    continue;
                }
                $kept[] = $entry;
            }
            foreach ($kept as $i => &$entry) {
                $entry['order'] = $i;
            }
            return $kept;
        });
        return $found;
    }

    /**
     * @param list<string> $order Eintrag-IDs in gewünschter Reihenfolge.
     */
    public function reorderEntries(array $order): bool
    {
        $this->mutate(function (array $entries) use ($order): array {
            $byId = [];
            foreach ($entries as $entry) {
                $byId[$entry['id']] = $entry;
            }
            $new = [];
            foreach ($order as $id) {
                $id = (string)$id;
                if (isset($byId[$id])) {
                    $byId[$id]['order'] = count($new);
                    $new[] = $byId[$id];
                    unset($byId[$id]);
                }
            }
            // Nicht genannte Einträge hinten anhängen, damit nichts verloren geht.
            foreach ($byId as $entry) {
                $entry['order'] = count($new);
                $new[] = $entry;
            }
            return $new;
        });
        return true;
    }

    // -------------------------------------------------------------------
    // Persistenz-Kern
    // -------------------------------------------------------------------

    /**
     * Read-Modify-Write unter exklusivem Lock, atomar geschrieben.
     * $fn erhält die aktuelle (normalisierte, sortierte) Liste und gibt
     * die neue Liste zurück.
     *
     * @param callable(list<array>):list<array> $fn
     * @return list<array<string,mixed>> die persistierte neue Liste
     */
    private function mutate(callable $fn, bool $migration = false): array
    {
        return $this->withLock(function () use ($fn, $migration): array {
            $entries = $this->readEntries();
            if ($migration && is_file($this->dataFile)) {
                @copy($this->dataFile, $this->backupDir . '/entries-pre-migration-' . date('Ymd-His') . '.json');
            }
            $new = $fn($entries);
            $new = array_values(array_map($this->normalize(...), $new));
            usort($new, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);
            foreach ($new as $i => &$entry) {
                $entry['order'] = $i;
            }
            unset($entry);
            $this->writeAtomic($new);
            return $new;
        });
    }

    private function withLock(callable $fn): mixed
    {
        $lockFile = $this->dataFile . '.lock';
        $fp = fopen($lockFile, 'c');
        if ($fp === false) {
            throw new RuntimeException('Lock-Datei nicht anlegbar');
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                throw new RuntimeException('Lock nicht erhalten');
            }
            try {
                return $fn();
            } finally {
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }

    /**
     * Liest die Datei roh (dekodiert, unnormalisiert). Korrupte JSON wirft
     * eine RuntimeException — sie darf nie als "leer" fehlinterpretiert
     * werden (sonst würde der nächste Save die leere Liste persistieren).
     *
     * @return list<array<string,mixed>>
     */
    private function readRaw(): array
    {
        if (!is_file($this->dataFile)) {
            return [];
        }
        $raw = file_get_contents($this->dataFile);
        if ($raw === false) {
            throw new RuntimeException('Datendatei nicht lesbar');
        }
        if (trim($raw) === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                'Datendatei ist beschädigt (' . $e->getMessage() . '). ' .
                'Letzte Sicherungen liegen in data/backups/.'
            );
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Datendatei hat ein unerwartetes Format.');
        }
        return array_values($decoded);
    }

    /**
     * Normalisiert und sortiert rohe Einträge.
     *
     * @return list<array<string,mixed>>
     */
    private function prepare(array $raw): array
    {
        $entries = array_values(array_map($this->normalize(...), $raw));
        usort($entries, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);
        return $entries;
    }

    /** @return list<array<string,mixed>> */
    private function readEntries(): array
    {
        return $this->prepare($this->readRaw());
    }

    private function writeAtomic(array $entries): void
    {
        $payload = json_encode(
            array_values($entries),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($payload === false) {
            throw new RuntimeException('JSON-Kodierung fehlgeschlagen');
        }

        $this->backupIfDue();

        $dir = dirname($this->dataFile);
        $tmp = tempnam($dir, '.entries-');
        if ($tmp === false) {
            throw new RuntimeException('Tempdatei nicht anlegbar');
        }
        try {
            if (file_put_contents($tmp, $payload) === false) {
                throw new RuntimeException('Schreiben fehlgeschlagen');
            }
            @chmod($tmp, 0644);
            if (!rename($tmp, $this->dataFile)) {
                throw new RuntimeException('Ersetzen der Datendatei fehlgeschlagen');
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /** Rotierendes Backup, gedrosselt auf BACKUP_MIN_INTERVAL. */
    private function backupIfDue(): void
    {
        if (!is_file($this->dataFile)) {
            return;
        }
        $backups = glob($this->backupDir . '/entries-*.json') ?: [];
        sort($backups);
        $latest = end($backups);
        if ($latest !== false && (time() - (int)filemtime($latest)) < self::BACKUP_MIN_INTERVAL) {
            return;
        }
        @copy($this->dataFile, $this->backupDir . '/entries-' . date('Ymd-His') . '.json');
        $backups = glob($this->backupDir . '/entries-*.json') ?: [];
        sort($backups);
        while (count($backups) > self::BACKUP_KEEP) {
            @unlink(array_shift($backups));
        }
    }

    // -------------------------------------------------------------------
    // IDs & Migration
    // -------------------------------------------------------------------

    private function needsMigration(array $entries): bool
    {
        foreach ($entries as $entry) {
            if (($entry['id'] ?? '') === '') {
                return true;
            }
        }
        return false;
    }

    private function newId(array $entries): string
    {
        $existing = array_column($entries, 'id');
        do {
            $id = bin2hex(random_bytes(4));
        } while (in_array($id, $existing, true));
        return $id;
    }

    // -------------------------------------------------------------------
    // Uploads
    // -------------------------------------------------------------------

    /**
     * Datei aus $_FILES validieren, ggf. verkleinern und speichern.
     * Wirft InvalidArgumentException bei Fehlern.
     */
    public function uploadImage(array $file): ?string
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Datei zu groß (Server-Limit)',
                UPLOAD_ERR_PARTIAL                        => 'Upload unvollständig — bitte erneut versuchen',
                default                                   => 'Upload fehlgeschlagen (Code ' . $error . ')',
            });
        }
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return null;
        }
        // MIME über finfo, nicht über vom Client gemeldeten Typ
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if ($mime === false || !in_array($mime, self::ALLOWED_MIME, true)) {
            throw new InvalidArgumentException('Ungültiger Dateityp');
        }
        if (($file['size'] ?? 0) > self::MAX_FILESIZE) {
            throw new InvalidArgumentException('Datei zu groß (max. 2 MB)');
        }
        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        };
        $filename = sprintf('%d_%s.%s', time(), bin2hex(random_bytes(6)), $ext);
        $dest = $this->uploadDir . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new InvalidArgumentException('Upload fehlgeschlagen');
        }
        $this->downscaleImage($dest, $mime);
        return $filename;
    }

    /**
     * Verkleinert zu große Bilder in-place (spart Tablet-Bandbreite und
     * Decode-Zeit). GIFs bleiben unangetastet (Animation), fehlendes GD
     * wird still übersprungen — das Original ist bereits gespeichert.
     */
    private function downscaleImage(string $path, string $mime): void
    {
        if (self::MAX_IMAGE_DIM <= 0 || $mime === 'image/gif' || !function_exists('imagecreatefromstring')) {
            return;
        }
        $info = @getimagesize($path);
        if ($info === false) {
            return;
        }
        [$w, $h] = $info;
        $max = max($w, $h);
        if ($max <= self::MAX_IMAGE_DIM) {
            return;
        }
        $raw = @file_get_contents($path);
        $src = $raw !== false ? @imagecreatefromstring($raw) : false;
        if ($src === false) {
            return;
        }
        $scale = self::MAX_IMAGE_DIM / $max;
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        // Transparenz für PNG/WebP erhalten
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $tmp = $path . '.tmp';
        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($dst, $tmp, 82),
            'image/png'  => imagepng($dst, $tmp, 6),
            'image/webp' => function_exists('imagewebp') ? imagewebp($dst, $tmp, 82) : false,
            default      => false,
        };
        imagedestroy($src);
        imagedestroy($dst);
        if ($ok && is_file($tmp)) {
            @rename($tmp, $path);
        } elseif (is_file($tmp)) {
            @unlink($tmp);
        }
    }

    private function unlinkImage(?string $name): void
    {
        if ($name === null || $name === '') return;
        $path = $this->uploadDir . basename($name);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    // -------------------------------------------------------------------
    // Normalisierung
    // -------------------------------------------------------------------

    /**
     * Normiert einen Eintrag: erzwingt erwartete Felder & Typen,
     * mappt Featured ohne Size auf "large" (Bento-Default).
     * Vergibt fehlende IDs (Migration von Alt-Daten).
     */
    private function normalize(array $entry): array
    {
        $cat  = Category::tryFromOrAll($entry['category'] ?? null)->value;
        $size = Size::tryFromOrNormal($entry['size'] ?? null);

        $featured = (bool)($entry['featured'] ?? false);
        // Backwards-Compat: alte Featured-Einträge ohne Size kriegen "large"
        if ($featured && !isset($entry['size'])) {
            $size = Size::Large;
        }

        $tags = $entry['tags'] ?? [];
        if (!is_array($tags)) $tags = [];
        $tags = array_values(array_filter(array_map(
            static fn($t) => trim((string)$t),
            $tags
        ), static fn($t) => $t !== ''));

        $id = (string)($entry['id'] ?? '');
        if ($id === '') {
            $id = bin2hex(random_bytes(4));
        }

        $image = $entry['image'] ?? null;

        return [
            'id'         => $id,
            'title'      => (string)($entry['title'] ?? ''),
            'text'       => (string)($entry['text']  ?? ''),
            'link'       => (string)($entry['link']  ?? ''),
            'image'      => $image !== null && $image !== '' ? (string)$image : null,
            'category'   => $cat,
            'size'       => $size->value,
            'tags'       => $tags,
            'featured'   => $featured,
            'order'      => (int)($entry['order'] ?? 0),
            'created_at' => (string)($entry['created_at'] ?? date('Y-m-d H:i:s')),
            'updated_at' => isset($entry['updated_at']) ? (string)$entry['updated_at'] : null,
        ];
    }

    /**
     * Entfernt Markdown-Syntax und gibt reinen Text zurück
     * (für title-Attribute / Tooltips / Suche).
     */
    public static function stripMarkdown(string $text): string
    {
        $text = self::cleanText($text);
        // Links: [text](url) → text
        $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $text) ?? $text;
        // Bold/Italic/Code/Strike
        $text = preg_replace('/\*\*(.+?)\*\*/s', '$1', $text) ?? $text;
        $text = preg_replace('/\*(.+?)\*/s', '$1', $text) ?? $text;
        $text = preg_replace('/`(.+?)`/', '$1', $text) ?? $text;
        $text = preg_replace('/~~(.+?)~~/', '$1', $text) ?? $text;
        // Heading-Marker am Zeilenanfang
        $text = preg_replace('/^#{1,6}\s+/m', '', $text) ?? $text;
        // Listen-Marker
        $text = preg_replace('/^-\s+/m', '• ', $text) ?? $text;
        // Mehrfach-Newlines normalisieren
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        return trim($text);
    }

    /**
     * Einheitliche Zeilenenden, ohne NUL-Bytes, außen getrimmt.
     *
     * Browser schicken Textarea-Inhalte mit CRLF. Ohne Normalisierung
     * greifen die Absatz-Regeln (\n\n) nicht, und Zeilen hinter einer
     * Überschrift liefen zusammen. NUL ist das Platzhalter-Zeichen der
     * Link-Pipeline und darf deshalb nie aus dem Text selbst kommen.
     */
    public static function cleanText(string $text): string
    {
        return trim(str_replace(["\r\n", "\r", "\x00"], ["\n", "\n", ''], $text));
    }

    /** Link-Schemata, die als href ausgeliefert werden (alles andere fällt weg). */
    private const array SAFE_URL_SCHEMES = ['http', 'https', 'mailto', 'tel', 'ftp', 'smb', 'ssh', 'vnc', 'rdp'];

    /**
     * Prüft, ob eine URL als Link ausgeliefert werden darf: erlaubte
     * Schemata oder relative Pfade. `javascript:`, `data:`, `vbscript:`
     * & Co. fallen durch — auch verschleiert, denn Browser ignorieren
     * Steuer- und Leerzeichen im Schema (`java\tscript:`), also prüfen
     * wir ohne sie.
     */
    public static function isSafeUrl(string $url): bool
    {
        $probe = preg_replace('/[\x00-\x20\x7f]+/', '', $url) ?? '';
        if ($probe === '') {
            return false;
        }
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $probe, $m) !== 1) {
            return true; // kein Schema → relativ (/pfad, #anker, seite.html)
        }
        return in_array(strtolower($m[1]), self::SAFE_URL_SCHEMES, true);
    }

    // ---------- Markdown ----------

    /**
     * Sicheres, minimales Markdown → HTML.
     * - Escape vor allem
     * - Headings/Listen/Inline
     * - Links über Platzhalter (kein wiederholter substr_replace),
     *   nur mit erlaubtem Schema (isSafeUrl), sonst bleibt der Linktext
     */
    public static function parseMarkdown(string $text): string
    {
        $text = self::cleanText($text);

        // Schritt 1: Links zuerst extrahieren (vor Escape, damit Klammern erhalten bleiben),
        // durch Platzhalter ersetzen.
        $links = [];
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)\s]+)\)/',
            static function (array $m) use (&$links): string {
                $idx = count($links);
                $links[$idx] = ['text' => $m[1], 'url' => $m[2]];
                return "\x00LINK{$idx}\x00";
            },
            $text
        ) ?? $text;

        // Schritt 2: Vollständig escapen
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // Schritt 3: Überschriften (länger zuerst)
        foreach ([6,5,4,3,2,1] as $level) {
            $hashes = str_repeat('#', $level);
            $text = preg_replace(
                '/^' . $hashes . '\s+(.+?)$/m',
                "<h{$level}>$1</h{$level}>",
                $text
            );
        }

        // Schritt 4: Inline-Formatierung
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/', '<em>$1</em>', $text);
        $text = preg_replace('/`(.+?)`/', '<code>$1</code>', $text);
        $text = preg_replace('/~~(.+?)~~/', '<del>$1</del>', $text);

        // Schritt 5: Listen
        $text = preg_replace('/^-\s+(.+)$/m', '<li>$1</li>', $text);
        $text = preg_replace_callback(
            '/(<li>.*?<\/li>)(\s*<li>.*?<\/li>)*/s',
            static fn(array $m): string => '<ul class="md-list">' . $m[0] . '</ul>',
            $text
        );

        // Schritt 6: Links wieder einsetzen
        $text = preg_replace_callback(
            '/\x00LINK(\d+)\x00/',
            static function (array $m) use ($links): string {
                $link = $links[(int)$m[1]] ?? null;
                if ($link === null) {
                    return '';
                }
                $linkText = htmlspecialchars($link['text'], ENT_QUOTES, 'UTF-8');
                if (!self::isSafeUrl($link['url'])) {
                    return $linkText;
                }
                $linkUrl = htmlspecialchars($link['url'], ENT_QUOTES, 'UTF-8');
                return '<a href="' . $linkUrl . '" target="_blank" rel="noopener">' . $linkText . '</a>';
            },
            $text
        ) ?? $text;

        // Schritt 7: Zeilenumbrüche — zeilenweise, damit auch Text direkt
        // unter einer Überschrift oder Liste (ohne Leerzeile) seine
        // Umbrüche behält. Leerzeile = Absatzgrenze, einzelne = <br>;
        // Überschriften und Listenzeilen bleiben eigene Blöcke.
        $out = [];
        foreach (preg_split('/\n{2,}/', $text) ?: [] as $block) {
            $para = [];
            foreach (explode("\n", $block) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (preg_match('/^<(h\d|ul|li)[ >]/', $line)) {
                    if ($para !== []) {
                        $out[] = '<p>' . implode("<br />\n", $para) . '</p>';
                        $para = [];
                    }
                    $out[] = $line;
                } else {
                    $para[] = $line;
                }
            }
            if ($para !== []) {
                $out[] = '<p>' . implode("<br />\n", $para) . '</p>';
            }
        }
        return implode("\n", $out);
    }
}
