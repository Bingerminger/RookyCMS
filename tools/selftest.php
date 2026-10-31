<?php
declare(strict_types=1);

/**
 * RookyCMS — Selbsttest ohne Abhängigkeiten.
 *
 *   php tools/selftest.php
 *
 * Bindet die Zusagen aus README/docs an echte Prüfungen: XSS-sichere
 * Markdown-Pipeline, Zeilenenden, Link-Schemata, Persistenz (Seed, Lock,
 * Normalisierung, korrupte Datei), die HTTP-API samt Passwort-Drosselung
 * und die Release-Stempel (VERSION ↔ CHANGELOG). Die API läuft dafür in
 * einer Wegwerf-Kopie unter `php -S` — die echte Instanz bleibt unberührt.
 *
 * Exit-Code 0 = alles grün. Läuft auch in der CI (.github/workflows/ci.yml).
 */

// Nur Kommandozeile: liegt das Projekt komplett im Web-Pfad (NAS), darf
// niemand den Test samt Testserver per URL anstoßen.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/public/CmsManager.php';
require $root . '/public/Category.php';
require $root . '/public/Size.php';

$fails = 0;
$count = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $fails, $count;
    $count++;
    if (!$ok) {
        $fails++;
    }
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok || $detail === '' ? '' : " — {$detail}") . "\n";
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

function rcopy(string $from, string $to, array $skip = []): void
{
    @mkdir($to, 0755, true);
    foreach (scandir($from) ?: [] as $name) {
        if ($name === '.' || $name === '..' || in_array($name, $skip, true)) {
            continue;
        }
        is_dir("$from/$name") ? rcopy("$from/$name", "$to/$name") : copy("$from/$name", "$to/$name");
    }
}

// ---------------------------------------------------------------------------
echo "Markdown & Links\n";

$md = CmsManager::parseMarkdown(...);
check('Text wird escaped', !str_contains($md('<img src=x onerror=alert(1)>'), '<img'));
foreach ([
    'javascript:alert`1`', 'JavaScript:alert`1`', "java\tscript:alert`1`", ' javascript:x',
    'data:text/html;base64,PHNjcmlwdD4=', 'vbscript:msgbox', 'file:///etc/passwd',
] as $evil) {
    $html = $md("[klick]($evil)");
    check('kein href für ' . json_encode($evil), !str_contains($html, 'href') && str_contains($html, 'klick'), $html);
}
check('https-Link bleibt', str_contains($md('[a](https://example.com/x?y=1&z=2)'), 'href="https://example.com/x?y=1&amp;z=2"'));
check('relativer Link bleibt', str_contains($md('[a](/status#oben)'), 'href="/status#oben"'));
check('Link öffnet mit noopener', str_contains($md('[a](https://example.com)'), 'target="_blank" rel="noopener"'));
check('URL kann Attribut nicht verlassen', !str_contains($md('[a](https://x.de/"onmouseover="alert(1))'), '"onmouseover'));

$crlf = $md("# Titel\r\n\r\nErster Absatz\r\nzweite Zeile\r\n\r\n- a\r\n- b");
check('CRLF: Absatz und Zeilenumbruch', str_contains($crlf, "<p>Erster Absatz<br />\nzweite Zeile</p>"), $crlf);
check('CRLF: kein \r im HTML', !str_contains($crlf, "\r"));
check('CRLF: Liste', str_contains($crlf, '<ul class="md-list"><li>a</li>'));
$under = $md("# Kopf\nZeile eins\nZeile zwei");
check('Text direkt unter Überschrift behält Umbrüche', $under === "<h1>Kopf</h1>\n<p>Zeile eins<br />\nZeile zwei</p>", $under);
$after = $md("- a\n- b\nText danach");
check('Text direkt nach Liste wird Absatz', str_ends_with($after, "</ul>\n<p>Text danach</p>"), $after);
check('Fett/Kursiv/Code/Strike', $md('**f** *k* `c` ~~s~~') === '<p><strong>f</strong> <em>k</em> <code>c</code> <del>s</del></p>');
$nul = $md("a \x00LINK7\x00 b [x](https://e.de)");
check('NUL-Platzhalter aus dem Text sind wirkungslos', str_contains($nul, 'LINK7') && str_contains($nul, 'href="https://e.de"'), $nul);
check('stripMarkdown liefert Klartext', CmsManager::stripMarkdown("**fett** [Link](https://e.de)\r\n- a") === "fett Link\n• a");

foreach (['https://e.de' => true, 'http://192.0.2.1:8080/' => true, 'mailto:a@b.de' => true, 'tel:+49' => true,
          'smb://nas/share' => true, '#anker' => true, 'seite.html' => true, '' => false,
          'javascript:x' => false, 'data:,x' => false, "\x01javascript:x" => false] as $url => $want) {
    check('isSafeUrl(' . json_encode((string)$url) . ') = ' . ($want ? 'ja' : 'nein'), CmsManager::isSafeUrl((string)$url) === $want);
}

// ---------------------------------------------------------------------------
echo "Persistenz\n";

$tmp = sys_get_temp_dir() . '/rookycms-selftest-' . bin2hex(random_bytes(4));
mkdir($tmp);
register_shutdown_function(static function () use ($tmp): void { rrmdir($tmp); });

$cms = new CmsManager("$tmp/data/entries.json", "$tmp/uploads/", $root . '/demo-data');
$demoCount = count(json_decode((string)file_get_contents($root . '/demo-data/entries.json'), true));
check("Erststart übernimmt die Demo-Daten ($demoCount Kacheln)", count($cms->getEntries()) === $demoCount);
check('Demo-Bilder liegen in uploads/', count(glob("$tmp/uploads/*") ?: []) === count(glob($root . '/demo-data/uploads/*') ?: []));

$r = $cms->addEntry(['title' => '  Neu ', 'text' => "a\r\nb", 'category' => 'quatsch', 'size' => 'riesig', 'tags' => [' x ', '']]);
$e = $cms->getEntry($r['id']);
check('neue ID: 8 Hex-Zeichen', (bool)preg_match('/^[0-9a-f]{8}$/', $r['id']));
check('Eintrag normalisiert (Trim, LF, Enum-Fallbacks, Tags)', $e !== null && $e['title'] === 'Neu' && $e['text'] === "a\nb"
    && $e['category'] === 'all' && $e['size'] === 'normal' && $e['tags'] === ['x']);
check('order lückenlos 0..n', array_column($cms->getEntries(), 'order') === range(0, $demoCount));

$ids = array_column($cms->getEntries(), 'id');
$cms->reorderEntries([$r['id'], 'gibt-es-nicht']);
$after = array_column($cms->getEntries(), 'id');
check('Reorder: genannte zuerst, Rest bleibt erhalten', $after[0] === $r['id'] && count($after) === count($ids) && !in_array('gibt-es-nicht', $after, true));

check('Update unbekannter ID scheitert', $cms->updateEntry('ffffffff', ['title' => 'x'])['success'] === false);
check('Delete', $cms->deleteEntry($r['id']) && $cms->getEntry($r['id']) === null);
check('Client-Daten tragen html/plain/href', isset($cms->getEntriesForClient()[0]['html'], $cms->getEntriesForClient()[0]['plain'])
    && array_key_exists('href', $cms->getEntriesForClient()[0]));
check('Backup angelegt', count(glob("$tmp/data/backups/entries-*.json") ?: []) >= 1);

$v1 = $cms->getVersion();
$cms->addEntry(['title' => 'Version']);
check('Version ändert sich mit dem Inhalt', $cms->getVersion() !== $v1);

file_put_contents("$tmp/data/entries.json", '[{"title": kaputt');
try {
    $cms->getEntries();
    check('korrupte Datei wirft Exception', false);
} catch (RuntimeException) {
    check('korrupte Datei wirft Exception', true);
}
check('korrupte Datei bleibt unangetastet', file_get_contents("$tmp/data/entries.json") === '[{"title": kaputt');

file_put_contents("$tmp/data/entries.json", '[{"title":"alt","text":"t","order":0}]');
$migrated = $cms->getEntries();
check('Alt-Daten ohne ID werden migriert', ($migrated[0]['id'] ?? '') !== '' && glob("$tmp/data/backups/entries-pre-migration-*.json"));

// ---------------------------------------------------------------------------
echo "HTTP-API (php -S, Wegwerf-Kopie)\n";

$app = "$tmp/app";
rcopy($root . '/public', "$app/public", ['config.php', 'data', 'uploads', 'backup', '.DS_Store']);
rcopy($root . '/demo-data', "$app/demo-data");
copy($root . '/VERSION', "$app/VERSION");
file_put_contents("$app/public/config.php", "<?php\nconst ADMIN_PASSWORD = 'test-pw';\nconst AUTO_REFRESH_SECONDS = 60;\nconst SITE_TITLE = 'Selbsttest';\n");

$port = random_int(20000, 45000);
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', "$app/public"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
register_shutdown_function(static function () use ($proc): void { proc_terminate($proc); });
$base = "http://127.0.0.1:$port";

/** @return array{0:int,1:string,2:array<string>} Status, Body, Header */
function http(string $url, ?array $post = null, array $headers = []): array
{
    $opts = ['http' => ['ignore_errors' => true, 'timeout' => 5, 'method' => $post === null ? 'GET' : 'POST']];
    if ($post !== null) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $opts['http']['content'] = http_build_query($post);
    }
    $opts['http']['header'] = implode("\r\n", $headers);
    $body = @file_get_contents($url, false, stream_context_create($opts));
    $hdr = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', $hdr[0] ?? '', $m);
    return [(int)($m[1] ?? 0), (string)$body, $hdr];
}

for ($i = 0; $i < 50 && http("$base/api.php?action=entries")[0] !== 200; $i++) {
    usleep(100_000);
}

[$st, $body] = http("$base/api.php?action=entries");
$j = json_decode($body, true);
check('entries: 200 + Demo-Bestand', $st === 200 && count($j['entries'] ?? []) === $demoCount, "Status $st");
[$st, $body] = http("$base/api.php?action=entries&since=" . urlencode($j['version'] ?? ''));
check('entries mit unveränderter Version: changed=false ohne Einträge', $st === 200 && str_contains($body, '"changed":false') && !str_contains($body, '"entries"'));

$auth = ['X-Auth-Password: test-pw'];
[$st] = http("$base/api.php?action=save", ['title' => 'x']);
check('save ohne Passwort: 401', $st === 401, "Status $st");
[$st] = http("$base/api.php?action=save", ['title' => 'x', 'link' => 'javascript:alert(1)'], $auth);
check('save mit javascript:-Link: 422', $st === 422, "Status $st");
[$st] = http("$base/api.php?action=save", ['title' => 'x', 'category[]' => 'a', 'tags[]' => 'b'], $auth);
check('Array-Parameter: kein 500', $st === 200, "Status $st");
[$st, $body] = http("$base/api.php?action=save", ['title' => '<!--<script>', 'text' => "Zeile 1\r\nZeile 2"], $auth);
$saved = json_decode($body, true);
$new = array_values(array_filter($saved['entries'] ?? [], static fn($e) => $e['id'] === ($saved['id'] ?? '')))[0] ?? [];
check('save mit Passwort: 200 + kompletter Zustand', $st === 200 && count($saved['entries'] ?? []) === $demoCount + 2);
check('gespeicherter Text hat LF-Zeilenenden', ($new['text'] ?? '') === "Zeile 1\nZeile 2");

[$st, $page] = http("$base/");
$release = trim((string)file_get_contents($root . '/VERSION'));
check('Seite: 200 + Versionskennung', $st === 200 && str_contains($page, "content=\"RookyCMS $release\""), "Status $st");
$hexed = substr((string)json_encode('<!--<script>', JSON_HEX_TAG), 1, -1); // die \u-Escape-Form
check('Kachelinhalt kann den Script-Block nicht aufbrechen', !str_contains($page, '<!--<script>') && str_contains($page, $hexed),
    'roh: ' . (int)str_contains($page, '<!--<script>') . ', escaped: ' . (int)str_contains($page, $hexed));
check('nosniff-Header', in_array('X-Content-Type-Options: nosniff', http("$base/api.php?action=entries")[2], true));

[$st] = http("$base/api.php?action=delete", ['id' => $saved['id'] ?? ''], $auth);
check('delete: 200', $st === 200, "Status $st");
[$st] = http("$base/api.php?action=reorder", ['order' => '[1,2]'], $auth);
check('reorder mit Nicht-Strings: 422', $st === 422, "Status $st");
[$st] = http("$base/api.php?action=entries", ['x' => 1]);
[$st2] = http("$base/api.php?action=save");
check('falsche Methode: 405', $st2 === 405, "Status $st2");

$codes = [];
for ($i = 0; $i < 6; $i++) {
    $codes[] = http("$base/api.php?action=verify", ['password' => 'falsch'])[0];
}
check('5 Fehlversuche → 401, danach 429', $codes === [401, 401, 401, 401, 401, 429], json_encode($codes));
[$st, , $hdr] = http("$base/api.php?action=verify", ['password' => 'test-pw']);
check('während der Sperre hilft auch das richtige Passwort nicht', $st === 429 && (bool)preg_grep('/^Retry-After: \d+/', $hdr));
check('Drossel-Datei speichert keine Klartext-IP', !str_contains((string)@file_get_contents("$app/public/data/auth-throttle.json"), '127.0.0.1'));

// ---------------------------------------------------------------------------
echo "Release-Stempel\n";

preg_match('/^## \[(\d+\.\d+\.\d+)\] — (\d{4}-\d{2}-\d{2})/m', (string)file_get_contents($root . '/CHANGELOG.md'), $m);
check("VERSION ($release) = oberster CHANGELOG-Abschnitt (" . ($m[1] ?? '–') . ')', ($m[1] ?? '') === $release);
check('LICENSE ist die GNU AGPL v3', str_contains((string)file_get_contents($root . '/LICENSE'), 'GNU AFFERO GENERAL PUBLIC LICENSE'));
check('README nennt die AGPL', str_contains((string)file_get_contents($root . '/README.md'), '[GNU AGPL v3.0 oder neuer](LICENSE)'));
check("Fußzeile verlinkt den Quellcode von v$release (AGPL §13)", str_contains($page, "https://github.com/Bingerminger/RookyCMS/tree/v$release"));

echo "\n" . ($fails === 0 ? "Alles grün: $count Prüfungen." : "$fails von $count Prüfungen rot.") . "\n";
exit($fails === 0 ? 0 : 1);
