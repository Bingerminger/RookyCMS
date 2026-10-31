<?php
declare(strict_types=1);

require_once __DIR__ . '/CmsManager.php';
require_once __DIR__ . '/Category.php';
require_once __DIR__ . '/Size.php';
require_once __DIR__ . '/config.load.php';

header('X-Content-Type-Options: nosniff');

/** Release-Version (Datei VERSION im Projektroot) — Kennung im <head> und Fußzeile. */
$release = trim((string)@file_get_contents(__DIR__ . '/../VERSION')) ?: 'dev';

/**
 * Quellcode genau dieser Version (AGPL-3.0 §13: wer die App im Netz
 * nutzt, erreicht ihren Quelltext). Wer eine veränderte Fassung
 * anbietet, trägt hier sein eigenes Repository ein.
 */
const ROOKY_REPO = 'https://github.com/Bingerminger/RookyCMS';
$sourceUrl = preg_match('/^\d+\.\d+\.\d+$/', $release) === 1 ? ROOKY_REPO . '/tree/v' . $release : ROOKY_REPO;

$entries   = [];
$version   = 'empty';
$loadError = null;

try {
    $cms     = new CmsManager(__DIR__ . '/data/entries.json', __DIR__ . '/uploads/', __DIR__ . '/../demo-data');
    $entries = $cms->getEntriesForClient();
    $version = $cms->getVersion();
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}

/** Kurzform zum HTML-Escapen */
function e(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Asset-URL mit Cache-Buster: die Datei-mtime ändert sich bei jedem
 * Deploy, damit Browser (der Synology-Webserver sendet kein
 * Cache-Control) nie auf alten CSS/JS-Versionen hängen bleiben.
 */
function asset(string $path): string {
    $file = __DIR__ . '/' . $path;
    return $path . '?v=' . (is_file($file) ? (string)filemtime($file) : '0');
}
?>
<!DOCTYPE html>
<html lang="de" data-theme="dark" data-style="aurora">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="dark light">
    <meta name="theme-color" content="#0a0a0f">
    <meta name="generator" content="RookyCMS <?= e($release) ?>">
    <title><?= e(SITE_TITLE) ?></title>
    <link rel="icon" href="favicon.png" type="image/png">
    <!-- Theme + Stil vor dem ersten Paint setzen (kein Aufblitzen des Defaults) -->
    <script>
        (function () {
            try {
                var el = document.documentElement;
                var t = localStorage.getItem('cms-theme');
                var s = localStorage.getItem('cms-style');
                if (t === 'dark' || t === 'light') el.setAttribute('data-theme', t);
                if (s) el.setAttribute('data-style', s);
            } catch (e) { /* localStorage kann blockiert sein — Defaults bleiben */ }
        })();
    </script>
    <link rel="stylesheet" href="<?= e(asset('css/styles.css')) ?>">
</head>
<body>

<!-- Statischer Aurora-Hintergrund (einmal gerendert, keine Animation) -->
<div class="aurora" aria-hidden="true"></div>

<header class="topbar">
    <div class="topbar__inner">
        <div class="brand">
            <span class="brand__mark" aria-hidden="true">
                <svg viewBox="0 0 32 32" width="24" height="24">
                    <defs>
                        <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0" stop-color="#f7b955"/>
                            <stop offset="1" stop-color="#e26a4a"/>
                        </linearGradient>
                    </defs>
                    <rect x="2" y="2" width="13" height="13" rx="3" fill="url(#bg)" opacity=".95"/>
                    <rect x="17" y="2" width="13" height="7" rx="3" fill="url(#bg)" opacity=".55"/>
                    <rect x="17" y="11" width="13" height="13" rx="3" fill="url(#bg)" opacity=".75"/>
                    <rect x="2" y="17" width="13" height="13" rx="3" fill="url(#bg)" opacity=".4"/>
                </svg>
            </span>
            <div class="brand__text">
                <strong><?= e(SITE_TITLE) ?></strong>
            </div>
        </div>

        <nav class="chips" id="filter-chips" aria-label="Kategoriefilter">
            <button type="button" class="chip is-active" data-category="all">
                <span class="chip__icon"><?= e(Category::All->icon()) ?></span>
                <?= e(Category::All->label()) ?>
                <span class="chip__count" data-count="all"></span>
            </button>
            <?php foreach (Category::selectable() as $cat): ?>
                <button type="button" class="chip" data-category="<?= e($cat->value) ?>" style="--hue: <?= $cat->hue() ?>">
                    <span class="chip__icon"><?= e($cat->icon()) ?></span>
                    <?= e($cat->label()) ?>
                    <span class="chip__count" data-count="<?= e($cat->value) ?>"></span>
                </button>
            <?php endforeach; ?>
        </nav>

        <div class="topbar__tools">
            <label class="search" aria-label="Suchen">
                <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true">
                    <circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"/>
                    <path d="m20 20-3.5-3.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                <input type="search" id="search" placeholder="Suchen…" autocomplete="off">
                <kbd>/</kbd>
            </label>

            <div class="style-picker">
                <button type="button" class="iconbtn" id="style-toggle" title="Stil wählen" aria-label="Stil wählen" aria-haspopup="true" aria-expanded="false">
                    <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
                        <path d="M12 3a9 9 0 0 0 0 18c1.7 0 2-1.3 1.2-2.2-.8-.9-.5-2.3.8-2.3H17a4 4 0 0 0 4-4c0-4.4-4-7.5-9-7.5Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                        <circle cx="7.5" cy="11" r="1.1" fill="currentColor"/>
                        <circle cx="12" cy="8" r="1.1" fill="currentColor"/>
                        <circle cx="16" cy="11" r="1.1" fill="currentColor"/>
                    </svg>
                </button>
                <div class="style-menu" id="style-menu" role="menu" aria-label="Stil wählen" hidden></div>
            </div>

            <button type="button" class="iconbtn" id="theme-toggle" title="Theme wechseln" aria-label="Theme wechseln">
                <svg class="i-sun"  viewBox="0 0 24 24" width="16" height="16"><circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="2"/><g stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 3v2"/><path d="M12 19v2"/><path d="M3 12h2"/><path d="M19 12h2"/><path d="m5.6 5.6 1.4 1.4"/><path d="m17 17 1.4 1.4"/><path d="m5.6 18.4 1.4-1.4"/><path d="m17 7 1.4-1.4"/></g></svg>
                <svg class="i-moon" viewBox="0 0 24 24" width="16" height="16"><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
            </button>

            <button type="button" class="btn btn--primary" id="new-entry" title="Neue Kachel (N)">
                <svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
                <span>Neu</span>
            </button>
        </div>
    </div>
</header>

<main class="page">
    <?php if ($loadError !== null): ?>
        <div class="banner banner--error" role="alert">
            <strong>Daten konnten nicht geladen werden.</strong>
            <span><?= e($loadError) ?></span>
        </div>
    <?php endif; ?>
    <div class="bento" id="bento" aria-live="polite"></div>
    <p class="noresults" id="noresults" hidden>Keine Kachel passt zu Suche und Filter.</p>
    <div class="empty" id="empty" hidden>
        <div class="empty__art" aria-hidden="true">
            <svg viewBox="0 0 64 64" width="64" height="64"><rect x="6" y="6" width="22" height="22" rx="5" fill="none" stroke="currentColor" stroke-width="2" opacity=".5"/><rect x="34" y="6" width="22" height="22" rx="5" fill="none" stroke="currentColor" stroke-width="2" opacity=".35"/><rect x="6" y="34" width="22" height="22" rx="5" fill="none" stroke="currentColor" stroke-width="2" opacity=".35"/><rect x="34" y="34" width="22" height="22" rx="5" fill="none" stroke="currentColor" stroke-width="2" opacity=".7" stroke-dasharray="4 3"/></svg>
        </div>
        <h2>Noch keine Kacheln</h2>
        <p>Lege deine erste Kachel an — ein Monitoring-Link, eine Warnung, eine Wetterquelle.</p>
        <button type="button" class="btn btn--primary" id="new-entry-empty">+ Erste Kachel anlegen</button>
    </div>
</main>

<footer class="site-foot">
    RookyCMS <?= e($release) ?> · freie Software unter
    <a href="https://www.gnu.org/licenses/agpl-3.0.html" target="_blank" rel="noopener">AGPL-3.0</a> ·
    <a href="<?= e($sourceUrl) ?>" target="_blank" rel="noopener">Quellcode</a>
</footer>

<!-- =====================================================================
     Universelles Modal: Edit / Create / Delete / Password
     =[REDACTED:API key param] -->
<div class="modal" id="modal" hidden aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal__backdrop" data-close></div>
    <div class="modal__panel" role="document">
        <button type="button" class="modal__close" data-close aria-label="Schließen">
            <svg viewBox="0 0 24 24" width="18" height="18"><path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        </button>

        <!-- Slot: Passwort-Prompt -->
        <section class="modal__slot" data-slot="auth">
            <header class="modal__head">
                <h2>Hauspasswort</h2>
                <p>Diese Aktion erfordert die Eingabe des Passworts.</p>
            </header>
            <form id="auth-form" autocomplete="off">
                <div class="field">
                    <label for="auth-password">Passwort</label>
                    <input type="password" id="auth-password" required autocomplete="current-password">
                </div>
                <div class="modal__actions">
                    <button type="button" class="btn btn--ghost" data-close>Abbrechen</button>
                    <button type="submit" class="btn btn--primary">Bestätigen</button>
                </div>
            </form>
        </section>

        <!-- Slot: Create / Edit -->
        <section class="modal__slot" data-slot="form">
            <header class="modal__head">
                <h2 data-form-title>Neue Kachel</h2>
                <p data-form-sub>Markdown wird unterstützt.</p>
            </header>

            <form id="entry-form" enctype="multipart/form-data">
                <input type="hidden" name="id" value="">

                <div class="grid-2">
                    <div class="field field--span">
                        <label for="f-title">Titel</label>
                        <input type="text" id="f-title" name="title" required maxlength="120" placeholder="z. B. Monitoring [Hausnetz]">
                    </div>

                    <div class="field">
                        <label for="f-category">Kategorie</label>
                        <select id="f-category" name="category">
                            <?php foreach (Category::cases() as $c): ?>
                                <option value="<?= e($c->value) ?>"><?= e($c->icon()) ?> <?= e($c->label()) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="f-size">Größe (Bento)</label>
                        <select id="f-size" name="size">
                            <?php foreach (Size::cases() as $s): ?>
                                <option value="<?= e($s->value) ?>"><?= e($s->label()) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field field--span">
                        <label for="f-text">
                            Text <span class="muted">— Markdown: <code>**fett**</code> <code>*kursiv*</code> <code>[Link](url)</code> <code># H1</code> <code>- Listen</code></span>
                        </label>
                        <div class="md-editor">
                            <textarea id="f-text" name="text" rows="6" required placeholder="# Überschrift&#10;&#10;**Wichtig:** kurzer Text.&#10;&#10;[Quelle](https://example.com)"></textarea>
                            <div class="md-editor__preview" id="md-preview" hidden></div>
                            <button type="button" class="btn btn--ghost btn--sm" id="md-toggle">Vorschau</button>
                        </div>
                    </div>

                    <div class="field field--span">
                        <label for="f-link">Link (optional)</label>
                        <input type="url" id="f-link" name="link" placeholder="https://…">
                    </div>

                    <div class="field field--span">
                        <label for="f-tags">Tags (komma-getrennt)</label>
                        <input type="text" id="f-tags" name="tags" placeholder="monitoring, netzwerk, intern">
                    </div>

                    <div class="field field--span">
                        <label>Bild (max. 2 MB)</label>
                        <div class="filedrop" id="filedrop">
                            <input type="file" id="f-image" name="image" accept="image/*">
                            <div class="filedrop__hint">
                                <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M12 16V4m0 0-4 4m4-4 4 4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <span>Datei hierher ziehen oder <u>auswählen</u></span>
                            </div>
                            <img class="filedrop__preview" id="filedrop-preview" alt="" hidden>
                            <button type="button" class="filedrop__remove" id="filedrop-remove" hidden>Entfernen</button>
                            <input type="hidden" name="delete_image" id="f-delete-image" value="0">
                        </div>
                    </div>

                    <div class="field field--span">
                        <label class="checkbox">
                            <input type="checkbox" id="f-featured" name="featured" value="1">
                            <span>Hervorheben (Stern-Markierung)</span>
                        </label>
                    </div>
                </div>

                <div class="modal__actions">
                    <button type="button" class="btn btn--ghost" data-close>Abbrechen</button>
                    <button type="submit" class="btn btn--primary">Speichern</button>
                </div>
            </form>
        </section>

        <!-- Slot: Delete -->
        <section class="modal__slot" data-slot="delete">
            <header class="modal__head">
                <h2>Kachel löschen?</h2>
                <p data-delete-summary>Diese Aktion kann nicht rückgängig gemacht werden.</p>
            </header>
            <div class="modal__actions">
                <button type="button" class="btn btn--ghost" data-close>Abbrechen</button>
                <button type="button" class="btn btn--danger" id="confirm-delete">Endgültig löschen</button>
            </div>
        </section>
    </div>
</div>

<!-- Toasts -->
<div class="toasts" id="toasts" aria-live="polite" aria-atomic="true"></div>

<?php
// JSON im <script>: <, >, & und Anführungszeichen als \u-Escapes, damit kein
// Kachelinhalt (etwa „<!--<script>“) den Script-Block des Parsers aushebelt.
const ROOKY_JSON_IN_SCRIPT = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
?>
<script>
    window.cmsConfig = {
        pollSeconds: <?= (int)AUTO_REFRESH_SECONDS ?>,
        version: <?= json_encode($version, ROOKY_JSON_IN_SCRIPT) ?>
    };
    window.cmsEntries = <?= json_encode($entries, ROOKY_JSON_IN_SCRIPT) ?>;
</script>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>

</body>
</html>
