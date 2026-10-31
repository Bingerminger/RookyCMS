<?php
declare(strict_types=1);

/**
 * RookyCMS — AJAX-API.
 *
 * Authentifizierung: Passwort wird pro Request mitgesendet
 * (X-Auth-Password Header oder POST-Feld "password").
 * Es gibt keinen Server-Session-Login. Fehlversuche werden je Client
 * gedrosselt (checkPassword), damit sich eine kurze PIN nicht in
 * Minuten durchprobieren lässt.
 *
 * Mutationen (save/delete/reorder) antworten immer mit dem kompletten
 * neuen Zustand ({version, entries}) — der Client rendert daraus, ohne
 * die Seite neu zu laden.
 */

require_once __DIR__ . '/CmsManager.php';
require_once __DIR__ . '/Category.php';
require_once __DIR__ . '/Size.php';
require_once __DIR__ . '/config.load.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/** Drosselung falscher Passwörter, siehe checkPassword(). */
const AUTH_MAX_FAILS    = 5;   // Fehlversuche bis zur ersten Pause
const AUTH_LOCK_SECONDS = 60;  // erste Pause, danach jeweils doppelt
const AUTH_LOCK_MAX     = 900; // längste Pause (15 min)

$action = is_string($_GET['action'] ?? null) ? $_GET['action'] : postString('action');

try {
    $cms = new CmsManager(__DIR__ . '/data/entries.json', __DIR__ . '/uploads/', __DIR__ . '/../demo-data');
    match ($action) {
        'entries' => actionEntries($cms),
        'verify'  => actionVerify(),
        'preview' => actionPreview(),
        'save'    => actionSave($cms),
        'delete'  => actionDelete($cms),
        'reorder' => actionReorder($cms),
        default   => jsonError('Unbekannte Aktion', 400),
    };
} catch (Throwable $e) {
    error_log('[RookyCMS] ' . $e->getMessage());
    jsonError($e->getMessage(), 500);
}

// -----------------------------------------------------------------------------
// Actions
// -----------------------------------------------------------------------------

/**
 * Polling-Endpoint: Client sendet die zuletzt bekannte Version mit;
 * ist nichts passiert, bleibt die Antwort winzig.
 */
function actionEntries(CmsManager $cms): never
{
    $since = (string)($_GET['since'] ?? '');
    $version = $cms->getVersion();
    if ($since !== '' && $since === $version) {
        jsonOk(['changed' => false, 'version' => $version]);
    }
    jsonOk([
        'changed' => true,
        'version' => $version,
        'entries' => $cms->getEntriesForClient(),
    ]);
}

function actionVerify(): never
{
    requireMethod('POST');
    checkPassword(postString('password'), 'Falsches Passwort');
    jsonOk();
}

function actionPreview(): never
{
    requireMethod('POST');
    header_remove('Content-Type');
    header('Content-Type: text/html; charset=UTF-8');
    echo CmsManager::parseMarkdown(postString('text'));
    exit;
}

function actionSave(CmsManager $cms): never
{
    requireMethod('POST');
    requireAuth();

    $id = trim(postString('id'));
    $isUpdate = $id !== '';

    $data = [
        'title'    => postString('title'),
        'text'     => postString('text'),
        'link'     => trim(postString('link')),
        'category' => Category::tryFromOrAll(postString('category'))->value,
        'size'     => Size::tryFromOrNormal(postString('size'))->value,
        'featured' => !in_array(postString('featured'), ['', '0'], true),
        'tags'     => parseTags(postString('tags')),
    ];

    if (trim($data['title']) === '') {
        jsonError('Titel darf nicht leer sein', 422);
    }
    if ($data['link'] !== '' && !CmsManager::isSafeUrl($data['link'])) {
        jsonError('Link muss mit http:// oder https:// beginnen (oder ein relativer Pfad sein)', 422);
    }

    $newImage    = null;
    $deleteImage = ($_POST['delete_image'] ?? '') === '1';

    if (isset($_FILES['image'])) {
        try {
            $newImage = $cms->uploadImage($_FILES['image']);
        } catch (InvalidArgumentException $e) {
            jsonError($e->getMessage(), 422);
        }
    }

    $result = $isUpdate
        ? $cms->updateEntry($id, $data, $newImage, $deleteImage)
        : $cms->addEntry($data + ['image' => $newImage]);

    if (!($result['success'] ?? false)) {
        jsonError($result['error'] ?? 'Speichern fehlgeschlagen', $isUpdate ? 404 : 500);
    }
    jsonState($cms, ['id' => $result['id'] ?? null]);
}

function actionDelete(CmsManager $cms): never
{
    requireMethod('POST');
    requireAuth();
    $id = trim(postString('id'));
    if ($id === '' || $cms->getEntry($id) === null) {
        jsonError('Eintrag nicht gefunden', 404);
    }
    if (!$cms->deleteEntry($id)) {
        jsonError('Löschen fehlgeschlagen', 500);
    }
    jsonState($cms);
}

function actionReorder(CmsManager $cms): never
{
    requireMethod('POST');
    requireAuth();
    $order = json_decode(postString('order'), true);
    if (!is_array($order) || array_filter($order, static fn($id) => !is_string($id)) !== []) {
        jsonError('Ungültige Reihenfolge', 422);
    }
    if (!$cms->reorderEntries($order)) {
        jsonError('Sortieren fehlgeschlagen', 500);
    }
    jsonState($cms);
}

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

function requireMethod(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        jsonError('Methode nicht erlaubt', 405);
    }
}

function requireAuth(): void
{
    $pwd = $_SERVER['HTTP_X_AUTH_PASSWORD'] ?? postString('password');
    checkPassword((string)$pwd, 'Nicht autorisiert');
}

/**
 * Passwort timing-safe prüfen — mit Drosselung je Client: nach
 * AUTH_MAX_FAILS Fehlversuchen in Folge ist für AUTH_LOCK_SECONDS
 * Pause (429), jede weitere Sperre dauert doppelt so lang (max.
 * AUTH_LOCK_MAX). Ein Treffer löscht den Zähler. Gespeichert wird nur
 * ein Hash der Client-Adresse, nach einem Tag Ruhe verfällt er.
 * Ohne Schreibrecht auf data/ entfällt die Drosselung (die Prüfung nie).
 */
function checkPassword(string $given, string $failMessage): void
{
    $file = __DIR__ . '/data/auth-throttle.json';
    $key  = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '-'));
    $now  = time();

    $fp = @fopen($file, 'c+');
    if ($fp === false || !flock($fp, LOCK_EX)) {
        if (!hash_equals(ADMIN_PASSWORD, $given)) {
            jsonError($failMessage, 401);
        }
        return;
    }
    $map = json_decode((string)stream_get_contents($fp), true);
    $map = is_array($map) ? $map : [];
    $map = array_filter($map, static fn($r) => is_array($r) && ($r['last'] ?? 0) > $now - 86400);
    $rec = $map[$key] ?? ['fails' => 0, 'locks' => 0, 'until' => 0, 'last' => 0];

    $wait = (int)$rec['until'] - $now;
    $ok = false;
    if ($wait <= 0) {
        $ok = hash_equals(ADMIN_PASSWORD, $given);
        if ($ok) {
            unset($map[$key]);
        } else {
            $rec['fails']++;
            $rec['last'] = $now;
            if ($rec['fails'] >= AUTH_MAX_FAILS) {
                // Dieser Versuch wurde geprüft und bekommt noch sein 401 —
                // die Pause gilt ab dem nächsten.
                $rec['until'] = $now + min(AUTH_LOCK_SECONDS * 2 ** $rec['locks'], AUTH_LOCK_MAX);
                $rec['locks']++;
                $rec['fails'] = 0;
            }
            $map[$key] = $rec;
        }
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string)json_encode($map));
    }
    flock($fp, LOCK_UN);
    fclose($fp);

    if ($ok) {
        return;
    }
    if ($wait > 0) {
        header('Retry-After: ' . $wait);
        jsonError("Zu viele Fehlversuche — bitte in {$wait} s erneut versuchen", 429);
    }
    jsonError($failMessage, 401);
}

/** POST-Feld als String — Arrays (feld[]=…) zählen als leer statt als TypeError. */
function postString(string $key): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? $v : '';
}

function parseTags(string $raw): array
{
    if (trim($raw) === '') return [];
    return array_values(array_filter(
        array_map(static fn($t) => trim($t), explode(',', $raw)),
        static fn($t) => $t !== ''
    ));
}

/** Antwort nach einer Mutation: kompletter neuer Zustand für den Client. */
function jsonState(CmsManager $cms, array $extra = []): never
{
    jsonOk($extra + [
        'version' => $cms->getVersion(),
        'entries' => $cms->getEntriesForClient(),
    ]);
}

function jsonOk(array $data = []): never
{
    echo json_encode(['success' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function jsonError(string $message, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
