<?php
declare(strict_types=1);

/**
 * RookyCMS — AJAX-API.
 *
 * Authentifizierung: Passwort wird pro Request mitgesendet
 * (X-Auth-Password Header oder POST-Feld "password").
 * Es gibt keinen Server-Session-Login.
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

$action = $_GET['action'] ?? $_POST['action'] ?? '';

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
        'version' => $cms->getVersion(),
        'entries' => $cms->getEntriesForClient(),
    ]);
}

function actionVerify(): never
{
    requireMethod('POST');
    $password = $_POST['password'] ?? '';
    if (!hash_equals(ADMIN_PASSWORD, (string)$password)) {
        jsonError('Falsches Passwort', 401);
    }
    jsonOk();
}

function actionPreview(): never
{
    requireMethod('POST');
    header_remove('Content-Type');
    header('Content-Type: text/html; charset=UTF-8');
    echo CmsManager::parseMarkdown((string)($_POST['text'] ?? ''));
    exit;
}

function actionSave(CmsManager $cms): never
{
    requireMethod('POST');
    requireAuth();

    $id = trim((string)($_POST['id'] ?? ''));
    $isUpdate = $id !== '';

    $data = [
        'title'    => (string)($_POST['title']    ?? ''),
        'text'     => (string)($_POST['text']     ?? ''),
        'link'     => (string)($_POST['link']     ?? ''),
        'category' => Category::tryFromOrAll($_POST['category'] ?? null)->value,
        'size'     => Size::tryFromOrNormal($_POST['size']     ?? null)->value,
        'featured' => isset($_POST['featured']) && $_POST['featured'] !== '0' && $_POST['featured'] !== '',
        'tags'     => parseTags($_POST['tags'] ?? ''),
    ];

    if (trim($data['title']) === '') {
        jsonError('Titel darf nicht leer sein', 422);
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
    $id = trim((string)($_POST['id'] ?? ''));
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
    $raw = $_POST['order'] ?? '';
    $order = json_decode((string)$raw, true);
    if (!is_array($order)) {
        jsonError('Ungültige Reihenfolge', 422);
    }
    if (!$cms->reorderEntries(array_map('strval', $order))) {
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
    $pwd = $_SERVER['HTTP_X_AUTH_PASSWORD'] ?? $_POST['password'] ?? '';
    if (!hash_equals(ADMIN_PASSWORD, (string)$pwd)) {
        jsonError('Nicht autorisiert', 401);
    }
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
    echo json_encode(['success' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $message, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
