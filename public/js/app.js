/**
 * RookyCMS — Frontend.
 *
 * Architektur: Der Client hält den Zustand (this.entries, vom Server
 * geliefert) und rendert das Grid daraus. Jede Mutation (save/delete/
 * reorder) antwortet mit dem kompletten neuen Zustand — kein
 * location.reload() mehr, kein Flackern, keine verlorenen Eingaben.
 *
 * - Ein Modal für Auth / Create / Edit / Delete.
 * - Passwort wird NICHT gespeichert: jede Schutzaktion (Neu, Bearbeiten,
 *   Löschen, Verschieben) fragt es frisch ab; es lebt nur im RAM für
 *   genau diese eine Aktion und wird nach Abschluss/Abbruch verworfen.
 *   Nach dem Speichern ist man also automatisch wieder abgemeldet.
 * - Einträge haben stabile String-IDs (kein Index-Drift bei Reorder).
 * - Leises Polling: nur bei geänderter Version wird neu gerendert.
 */

const THEME_KEY = 'cms-theme';
const STYLE_KEY = 'cms-style';

// localStorage kann blockiert sein (Safari privat, gesperrte Website-Daten)
// und wirft dann schon beim Zugriff — Theme/Stil gelten dann nur bis zum
// Neuladen, die App läuft trotzdem.
const store = {
    get(key) { try { return localStorage.getItem(key); } catch { return null; } },
    set(key, value) { try { localStorage.setItem(key, value); } catch { /* nur für diese Sitzung */ } },
};

// Öffentliche Demo (GitHub Pages, kein PHP): tools/build-demo.sh rendert
// index.php einmal mit den Demo-Daten vor und setzt data-demo an <html>.
// Dann beantwortet demoFetch() die API lokal: alles lässt sich ausprobieren,
// gespeichert wird nichts. Eine normale Installation läuft hier nie hinein.
const DEMO = document.documentElement.hasAttribute('data-demo');
const DEMO_PASSWORD = '1234';
const DEMO_REPO = 'https://github.com/Bingerminger/RookyCMS';
const DEMO_MSG = 'In der Demo wird nichts gespeichert — RookyCMS selbst installieren: siehe GitHub.';

// Auswählbare Stile. Jeder hat im CSS eine Tag- und Nachtvariante
// ([data-style="…"][data-theme="…"]); swatch = zwei repräsentative
// Farben (Fläche + Akzent) für die Vorschau im Popover.
const STYLES = [
    { id: 'aurora', label: 'Aurora',  swatch: ['#1a1540', '#e8833a'] },
    { id: 'mono',   label: 'Graphit', swatch: ['#0a0a0a', '#f5f5f7'] },
    { id: 'fluent', label: 'Fluent',  swatch: ['#202020', '#4cc2ff'] },
    { id: 'ubuntu', label: 'Ubuntu',  swatch: ['#2c1620', '#e95420'] },
    { id: 'nord',   label: 'Nord',    swatch: ['#2e3440', '#88c0d0'] },
];

class CMS {
    constructor() {
        this.entries = window.cmsEntries || [];
        this.version = window.cmsConfig?.version || '';
        this.filter = 'all';
        this.search = '';
        this.modal = document.getElementById('modal');
        this.grid = document.getElementById('bento');
        this.toasts = document.getElementById('toasts');
        this.pendingAuthFn = null;
        this.pwd = null; // Passwort nur im RAM, nur für die laufende Aktion
        this.draggingId = null;
        this.firstRender = true;
        this.previewSeq = 0;
        // Demo: zuletzt „gespeicherte" Reihenfolge, damit ein abgebrochenes
        // Verschieben wie im Echtbetrieb zurückspringt.
        this.demoSaved = this.entries.slice();

        // Altbestand aufräumen: frühere Versionen hielten das Passwort
        // im sessionStorage — auf Bestandsgeräten entfernen.
        try { sessionStorage.removeItem('cms-pwd'); } catch { /* egal */ }

        // Kategorie-Metadaten (Label/Icon/Hue) aus den PHP-gerenderten Chips lesen
        this.categories = {};
        document.querySelectorAll('#filter-chips .chip').forEach(chip => {
            const label = Array.from(chip.childNodes)
                .filter(n => n.nodeType === Node.TEXT_NODE)
                .map(n => n.textContent.trim())
                .join(' ').trim();
            this.categories[chip.dataset.category] = {
                label: label || chip.dataset.category,
                icon: chip.querySelector('.chip__icon')?.textContent || '',
                hue: chip.style.getPropertyValue('--hue').trim() || '220',
            };
        });

        this.init();
    }

    init() {
        if (DEMO) this.showDemoBanner();
        this.bindTheme();
        this.bindStyle();
        this.bindSearch();
        this.bindFilters();
        this.bindGrid();
        this.bindNewButtons();
        this.bindModal();
        this.bindMarkdownEditor();
        this.bindFileDrop();
        this.bindKeyboard();
        this.render();
        this.setupPolling();

        // Bei Größen-/Orientierungswechsel ändern sich Umbrüche → neu messen.
        // WICHTIG: nur auf BREITEN-Änderung reagieren — iOS Safari feuert
        // resize beim Scrollen (Adressleiste minimiert sich), und ein
        // Relayout dabei ließ die Seite zucken und nach oben springen.
        let resizeTimer = null;
        let lastWidth = window.innerWidth;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(() => {
                if (window.innerWidth !== lastWidth) {
                    lastWidth = window.innerWidth;
                    this.layoutTiles();
                }
            }, 120);
        });
    }

    // ---------- State ----------
    applyState(state) {
        if (!state || !Array.isArray(state.entries)) return;
        this.entries = state.entries;
        this.version = state.version || this.version;
        this.render();
    }

    findEntry(id) {
        return this.entries.find(e => e.id === id) || null;
    }

    // ---------- Rendering ----------
    esc(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    /** Dateiname für url('…'): encodeURIComponent lässt ' ( ) stehen — die beenden sonst den CSS-String. */
    uploadPath(name) {
        return encodeURIComponent(name).replace(/['()]/g, c => '%' + c.charCodeAt(0).toString(16).toUpperCase());
    }

    formatDate(iso) {
        const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || '');
        return m ? `${m[3]}.${m[2]}.${m[1]}` : '';
    }

    visibleEntries() {
        return this.entries.filter(e => {
            const matchCat = this.filter === 'all' || e.category === this.filter;
            if (!matchCat) return false;
            if (!this.search) return true;
            const hay = `${e.title}\n${e.plain || ''}\n${(e.tags || []).join(' ')}`.toLowerCase();
            return hay.includes(this.search);
        });
    }

    tileHTML(e) {
        const cat = this.categories[e.category] || this.categories.all || { label: e.category, hue: '220' };
        const tooltip = this.esc((e.title + '\n\n' + (e.plain || '')).trim());
        const tags = (e.tags || []).slice(0, 4)
            .map(t => `<span class="tag">#${this.esc(t)}</span>`).join('');
        const cover = e.image
            ? `<div class="tile__cover" style="background-image:url('uploads/${this.uploadPath(e.image)}')"></div>`
            : '';
        // href prüft der Server (CmsManager::isSafeUrl) — javascript: & Co. kommen leer an
        const link = e.href
            ? `<a class="tile__link" href="${this.esc(e.href)}" target="_blank" rel="noopener" draggable="false">Öffnen
                 <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 17 17 7M9 7h8v8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg></a>`
            : '<span></span>';

        return `<article
            class="tile size-${this.esc(e.size)} tile--${this.esc(e.category)}${e.image ? ' has-image' : ''}"
            data-id="${this.esc(e.id)}" data-category="${this.esc(e.category)}"
            style="--hue: ${this.esc(cat.hue)}" draggable="true"
            title="${tooltip}" aria-label="${tooltip}">
            ${cover}
            <div class="tile__head">
                <span class="tile__badge"><span class="dot"></span>${this.esc(cat.label)}</span>
                ${tags ? `<div class="tile__tags">${tags}</div>` : ''}
                ${e.featured ? '<span class="tile__star" title="Hervorgehoben">★</span>' : ''}
                <div class="tile__actions">
                    <button type="button" class="iconbtn iconbtn--ghost js-edit" title="Bearbeiten" aria-label="Bearbeiten" draggable="false">
                        <svg viewBox="0 0 24 24"><path d="M4 20h4l10-10-4-4L4 16v4Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="m13 6 4 4" stroke="currentColor" stroke-width="2"/></svg>
                    </button>
                    <button type="button" class="iconbtn iconbtn--ghost js-delete" title="Löschen" aria-label="Löschen" draggable="false">
                        <svg viewBox="0 0 24 24"><path d="M5 7h14M10 7V4h4v3M7 7l1 13h8l1-13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <button type="button" class="iconbtn iconbtn--ghost js-grip" title="Verschieben" aria-label="Verschieben">
                        <svg viewBox="0 0 24 24"><g fill="currentColor"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></g></svg>
                    </button>
                </div>
            </div>
            <h3 class="tile__title">${this.esc(e.title)}</h3>
            <div class="tile__body">${e.html || ''}</div>
            <div class="tile__foot">
                ${link}
                <span class="tile__date">${this.esc(this.formatDate(e.created_at))}</span>
            </div>
        </article>`;
    }

    render() {
        // Scroll-Position über den Neuaufbau retten — sonst springt die
        // Seite bei Polling-Updates oder nach Save/Delete nach oben.
        const sx = window.scrollX, sy = window.scrollY;

        const visible = this.visibleEntries();
        this.grid.classList.toggle('bento--animate', this.firstRender);
        this.grid.innerHTML = visible.map(e => this.tileHTML(e)).join('');
        this.firstRender = false;

        const empty = document.getElementById('empty');
        if (empty) empty.hidden = this.entries.length > 0;
        const none = document.getElementById('noresults');
        if (none) none.hidden = visible.length > 0 || this.entries.length === 0;

        this.updateCategoryCounts();
        this.layoutTiles();
        // instant statt smooth — das CSS hat scroll-behavior: smooth,
        // der Restore darf aber keine sichtbare Scroll-Animation auslösen
        const html = document.documentElement;
        const prevBehavior = html.style.scrollBehavior;
        html.style.scrollBehavior = 'auto';
        window.scrollTo(sx, sy);
        html.style.scrollBehavior = prevBehavior;
    }

    /**
     * Masonry: misst die natürliche Inhaltshöhe jeder Kachel und setzt
     * ihren grid-row-span im 2px-Raster (ROW_UNIT) — so zeigt jede
     * Kachel ihren KOMPLETTEN Inhalt, das Grid packt trotzdem dicht.
     * ROW_UNIT muss zu grid-auto-rows, GAP zu --gap im CSS passen.
     */
    layoutTiles() {
        const ROW_UNIT = 2;
        const GAP = 12; // muss zu --gap im CSS passen
        const tiles = [...this.grid.children];
        if (!tiles.length) return;
        // Batch: erst alle zurücksetzen, dann alle messen, dann alle setzen
        tiles.forEach(t => { t.style.gridRowEnd = 'auto'; });
        const heights = tiles.map(t => t.getBoundingClientRect().height);
        tiles.forEach((t, i) => {
            t.style.gridRowEnd = 'span ' + Math.max(1, Math.ceil((heights[i] + GAP) / ROW_UNIT));
        });
    }

    updateCategoryCounts() {
        const counts = { all: this.entries.length };
        this.entries.forEach(e => {
            counts[e.category] = (counts[e.category] || 0) + 1;
        });
        document.querySelectorAll('[data-count]').forEach(el => {
            el.textContent = counts[el.dataset.count] ?? 0;
        });
    }

    // ---------- Theme ----------
    bindTheme() {
        const saved = store.get(THEME_KEY);
        document.documentElement.setAttribute('data-theme', saved === 'light' ? 'light' : 'dark');
        document.getElementById('theme-toggle').addEventListener('click', () => {
            const now = document.documentElement.getAttribute('data-theme');
            const next = now === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            store.set(THEME_KEY, next);
        });
    }

    // ---------- Stil-Umschalter (Popover) ----------
    // Zweite Theming-Dimension: data-style. Die Auswahl schaltet sofort um
    // und bleibt in localStorage; der Tag/Nacht-Schalter kehrt jeden Stil
    // unabhängig um. Das Passwort ist hier nicht im Spiel (reine Ansicht).
    bindStyle() {
        const valid = new Set(STYLES.map(s => s.id));
        let current = store.get(STYLE_KEY) || 'aurora';
        if (!valid.has(current)) current = 'aurora';
        document.documentElement.setAttribute('data-style', current);

        const toggle = document.getElementById('style-toggle');
        const menu = document.getElementById('style-menu');
        if (!toggle || !menu) return;

        menu.innerHTML = STYLES.map(s => `
            <button type="button" class="style-opt" role="menuitemradio" data-style-id="${s.id}" aria-checked="false">
                <span class="style-opt__sw" style="background: linear-gradient(135deg, ${s.swatch[0]} 0%, ${s.swatch[0]} 48%, ${s.swatch[1]} 52%, ${s.swatch[1]} 100%)"></span>
                <span class="style-opt__name">${s.label}</span>
                <svg class="style-opt__check" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 5 5 9-11" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>`).join('');

        const opts = Array.from(menu.querySelectorAll('.style-opt'));
        const markActive = (id) => opts.forEach(o => {
            const active = o.dataset.styleId === id;
            o.classList.toggle('is-active', active);
            o.setAttribute('aria-checked', active ? 'true' : 'false');
        });
        markActive(current);

        const open = () => { menu.hidden = false; toggle.setAttribute('aria-expanded', 'true'); };
        const close = () => { menu.hidden = true;  toggle.setAttribute('aria-expanded', 'false'); };

        toggle.addEventListener('click', (e) => {
            e.stopPropagation();
            menu.hidden ? open() : close();
        });

        opts.forEach(o => o.addEventListener('click', () => {
            const id = o.dataset.styleId;
            document.documentElement.setAttribute('data-style', id);
            store.set(STYLE_KEY, id);
            markActive(id);
            close();
        }));

        // Klick außerhalb bzw. Escape schließt das Popover
        document.addEventListener('click', (e) => {
            if (!menu.hidden && !menu.contains(e.target) && !toggle.contains(e.target)) close();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !menu.hidden) close();
        });
    }

    // ---------- Search & Filter ----------
    bindSearch() {
        const input = document.getElementById('search');
        let timer = null;
        input.addEventListener('input', (e) => {
            clearTimeout(timer);
            timer = setTimeout(() => {
                this.search = e.target.value.trim().toLowerCase();
                this.render();
            }, 200);
        });
    }

    bindFilters() {
        document.querySelectorAll('#filter-chips .chip').forEach(chip => {
            chip.addEventListener('click', () => {
                document.querySelectorAll('#filter-chips .chip')
                    .forEach(c => c.classList.remove('is-active'));
                chip.classList.add('is-active');
                this.filter = chip.dataset.category;
                this.render();
            });
        });
    }

    // ---------- Grid: Delegation für Edit/Delete + Drag & Drop ----------
    bindGrid() {
        this.grid.addEventListener('click', (e) => {
            const tile = e.target.closest('.tile');
            if (!tile) return;
            if (e.target.closest('.js-edit'))   this.openEdit(tile.dataset.id);
            if (e.target.closest('.js-delete')) this.openDelete(tile.dataset.id);
        });

        this.grid.addEventListener('dragstart', (e) => {
            const tile = e.target.closest('.tile');
            if (!tile) return;
            // Drag nicht von Edit/Delete-Buttons aus starten (Grip ist ok)
            if (e.target.closest('.tile__actions') && !e.target.closest('.js-grip')) {
                e.preventDefault();
                return;
            }
            this.draggingId = tile.dataset.id;
            tile.classList.add('is-dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', this.draggingId);
        });

        this.grid.addEventListener('dragend', () => {
            this.draggingId = null;
            this.grid.querySelectorAll('.is-dragging, .drag-over')
                .forEach(t => t.classList.remove('is-dragging', 'drag-over'));
        });

        // dragover auf dem Grid erlaubt auch Drops in den Freiraum (ans Ende)
        this.grid.addEventListener('dragover', (e) => {
            if (!this.draggingId) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            const tile = e.target.closest('.tile');
            this.grid.querySelectorAll('.drag-over').forEach(t => t.classList.remove('drag-over'));
            if (tile && tile.dataset.id !== this.draggingId) {
                tile.classList.add('drag-over');
            }
        });

        this.grid.addEventListener('drop', (e) => {
            if (!this.draggingId) return;
            e.preventDefault();
            const targetTile = e.target.closest('.tile');
            this.moveEntry(this.draggingId, targetTile, e);
        });
    }

    /**
     * Verschiebt einen Eintrag im Datenmodell und rendert neu — dadurch
     * entspricht die Anzeige immer exakt der gespeicherten Reihenfolge
     * (behebt den Bug beim Verschieben der letzten Kachel: Drop in den
     * Freiraum hängt ans Ende an, und es gibt keinen Index-Drift mehr).
     */
    moveEntry(id, targetTile, event) {
        const from = this.entries.findIndex(en => en.id === id);
        if (from < 0) return;

        let to;
        if (!targetTile || targetTile.dataset.id === id) {
            to = this.entries.length - 1; // Freiraum → ans Ende
        } else {
            const targetId = targetTile.dataset.id;
            const rect = targetTile.getBoundingClientRect();
            const after = (event.clientY - rect.top) > rect.height / 2
                       || (event.clientX - rect.left) > rect.width / 2;
            to = this.entries.findIndex(en => en.id === targetId);
            if (to < 0) return;
            if (after) to += 1;
            if (from < to) to -= 1; // Entnahme verschiebt die Zielposition
        }

        if (to === from) return;
        const [moved] = this.entries.splice(from, 1);
        this.entries.splice(to, 0, moved);
        this.render();
        this.persistOrder();
    }

    persistOrder() {
        const ids = this.entries.map(en => en.id);
        this.requireAuth(async () => {
            try {
                const r = await this.fetchJSON('api.php?action=reorder', {
                    method: 'POST',
                    body: new URLSearchParams({ order: JSON.stringify(ids) }),
                    authed: true,
                });
                if (r && r.success) {
                    this.applyState(r);
                    this.toast(DEMO ? 'Reihenfolge geändert — in der Demo bis zum Neuladen' : 'Reihenfolge gespeichert', 'success');
                }
            } catch (err) {
                this.toast(err.message || 'Sortieren fehlgeschlagen', 'error');
                this.refreshFromServer(true); // Server-Zustand zurückholen statt Reload
            } finally {
                // Auth-Dialog schließen + Passwort verwerfen (Reorder hat
                // keinen eigenen Modal-Slot, der das übernehmen würde)
                this.closeModal();
            }
        });
    }

    bindNewButtons() {
        const open = () => this.openCreate();
        document.getElementById('new-entry')?.addEventListener('click', open);
        document.getElementById('new-entry-empty')?.addEventListener('click', open);
    }

    // ---------- Modal ----------
    bindModal() {
        this.modal.querySelectorAll('[data-close]').forEach(el =>
            el.addEventListener('click', () => this.closeModal())
        );

        document.getElementById('auth-form').addEventListener('submit', (e) => {
            e.preventDefault();
            this.verifyAndContinue(document.getElementById('auth-password').value);
        });

        const form = document.getElementById('entry-form');
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            this.submitEntry(form);
        });

        document.getElementById('confirm-delete').addEventListener('click', () => {
            this.confirmDelete();
        });
    }

    bindKeyboard() {
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !this.modal.hidden) {
                this.closeModal();
            }
            const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName);
            if (e.key === '/' && !typing && this.modal.hidden) {
                e.preventDefault();
                document.getElementById('search').focus();
            }
            if ((e.key === 'n' || e.key === 'N') && !e.metaKey && !e.ctrlKey && !e.altKey
                && !typing && this.modal.hidden) {
                e.preventDefault();
                this.openCreate();
            }
        });
    }

    showSlot(name) {
        this.modal.querySelectorAll('.modal__slot').forEach(s => {
            s.classList.toggle('is-active', s.dataset.slot === name);
        });
    }

    openModal(slot) {
        this.showSlot(slot);
        this.modal.hidden = false;
        this.modal.setAttribute('aria-hidden', 'false');
        requestAnimationFrame(() => {
            const focusable = this.modal.querySelector('.modal__slot.is-active input, .modal__slot.is-active textarea, .modal__slot.is-active select, .modal__slot.is-active button.btn--primary, .modal__slot.is-active button.btn--danger');
            focusable?.focus();
        });
    }

    closeModal() {
        // Wurde der Passwort-Dialog abgebrochen (bevor die Aktion lief)?
        const authAborted = this.pendingAuthFn !== null;
        this.modal.hidden = true;
        this.modal.setAttribute('aria-hidden', 'true');
        this.pendingAuthFn = null;
        // Abmelden: nach Speichern/Löschen wie auch bei Abbruch wird das
        // Passwort verworfen — die nächste Aktion fragt wieder danach.
        this.pwd = null;
        document.getElementById('auth-password').value = '';
        if (authAborted) {
            // z. B. Drag&Drop lokal umsortiert, aber Passwort abgebrochen:
            // Server-Zustand zurückholen, damit die Anzeige wieder stimmt.
            this.refreshFromServer(true);
        }
    }

    // ---------- Auth: gate ----------
    /**
     * Fragt IMMER das Passwort ab (kein Cache): erst nach erfolgreichem
     * verify läuft fn(). Das Passwort steht dann in this.pwd für die
     * unmittelbar folgende Aktion und wird danach verworfen.
     */
    requireAuth(fn) {
        this.pwd = null;
        this.pendingAuthFn = fn;
        this.openModal('auth');
    }

    /**
     * 401 mitten in einer offenen Aktion (Passwort inzwischen geändert):
     * erneut fragen, dann mit unveränderten Eingaben weitermachen —
     * slot ist der Dialog, zu dem es danach zurückgeht.
     */
    reauth(slot, retry) {
        this.pwd = null;
        this.pendingAuthFn = () => { this.showSlot(slot); retry(); };
        this.showSlot('auth');
        requestAnimationFrame(() => document.getElementById('auth-password').focus());
    }

    async verifyAndContinue(password) {
        if (!password) return;
        try {
            const r = await this.fetchJSON('api.php?action=verify', {
                method: 'POST',
                body: new URLSearchParams({ password }),
            });
            if (r && r.success) {
                this.pwd = password;
                document.getElementById('auth-password').value = '';
                const fn = this.pendingAuthFn;
                this.pendingAuthFn = null;
                if (fn) fn();
            }
        } catch (err) {
            this.pwd = null;
            this.toast(err.message || 'Falsches Passwort', 'error');
            document.getElementById('auth-password').value = '';
            document.getElementById('auth-password').focus();
        }
    }

    // ---------- CRUD ----------
    openCreate() {
        this.requireAuth(() => {
            this.resetForm();
            document.querySelector('[data-form-title]').textContent = 'Neue Kachel';
            document.querySelector('[data-form-sub]').textContent = 'Markdown wird unterstützt.';
            this.openModal('form');
        });
    }

    openEdit(id) {
        this.requireAuth(() => {
            const entry = this.findEntry(id);
            if (!entry) {
                this.toast('Eintrag nicht gefunden', 'error');
                return;
            }
            this.resetForm();
            const form = document.getElementById('entry-form');
            form.querySelector('[name=id]').value = entry.id;
            form.querySelector('[name=title]').value    = entry.title    || '';
            form.querySelector('[name=text]').value     = entry.text     || '';
            form.querySelector('[name=link]').value     = entry.link     || '';
            form.querySelector('[name=category]').value = entry.category || 'all';
            form.querySelector('[name=size]').value     = entry.size     || 'normal';
            form.querySelector('[name=tags]').value     = (entry.tags || []).join(', ');
            form.querySelector('[name=featured]').checked = !!entry.featured;

            if (entry.image) {
                const preview = document.getElementById('filedrop-preview');
                const remove  = document.getElementById('filedrop-remove');
                preview.src = 'uploads/' + encodeURIComponent(entry.image);
                preview.hidden = false;
                remove.hidden = false;
                document.querySelector('.filedrop__hint').style.display = 'none';
            }

            document.querySelector('[data-form-title]').textContent = 'Kachel bearbeiten';
            document.querySelector('[data-form-sub]').textContent = `Zuletzt geändert: ${entry.updated_at || entry.created_at || '?'}`;
            this.openModal('form');
        });
    }

    resetForm() {
        const form = document.getElementById('entry-form');
        form.reset();
        form.querySelector('[name=id]').value = '';
        form.querySelector('[name=delete_image]').value = '0';
        document.getElementById('filedrop-preview').hidden = true;
        document.getElementById('filedrop-preview').src = '';
        document.getElementById('filedrop-remove').hidden = true;
        document.querySelector('.filedrop__hint').style.display = '';
        document.getElementById('md-preview').hidden = true;
        document.getElementById('md-preview').innerHTML = '';
        document.getElementById('md-toggle').textContent = 'Vorschau';
    }

    async submitEntry(form) {
        const fd = new FormData(form);
        if (!fd.has('featured')) fd.append('featured', '0');

        const submitBtn = form.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Speichere…';

        try {
            const r = await this.fetchJSON('api.php?action=save', {
                method: 'POST',
                body: fd,
                authed: true,
            });
            if (r && r.success) {
                this.applyState(r);
                this.toast('Kachel gespeichert', 'success');
                this.closeModal();
            }
        } catch (err) {
            this.toast(err.message || 'Speichern fehlgeschlagen', 'error');
            if (err.status === 401) this.reauth('form', () => this.submitEntry(form));
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Speichern';
        }
    }

    openDelete(id) {
        this.requireAuth(() => {
            const entry = this.findEntry(id);
            const title = entry?.title || id;
            document.querySelector('[data-delete-summary]').textContent =
                `„${title}" wird unwiderruflich entfernt. Zugehörige Bilder werden mitgelöscht.`;
            document.getElementById('confirm-delete').dataset.id = id;
            this.openModal('delete');
        });
    }

    async confirmDelete() {
        const id = document.getElementById('confirm-delete').dataset.id;
        if (!id) return;
        try {
            const r = await this.fetchJSON('api.php?action=delete', {
                method: 'POST',
                body: new URLSearchParams({ id }),
                authed: true,
            });
            if (r && r.success) {
                this.applyState(r);
                this.toast('Kachel gelöscht', 'success');
                this.closeModal();
            }
        } catch (err) {
            this.toast(err.message || 'Löschen fehlgeschlagen', 'error');
            if (err.status === 401) this.reauth('delete', () => this.confirmDelete());
        }
    }

    // ---------- Markdown editor ----------
    bindMarkdownEditor() {
        const ta = document.getElementById('f-text');
        const pv = document.getElementById('md-preview');
        const tg = document.getElementById('md-toggle');
        let timer = null;

        const update = async () => {
            if (pv.hidden) return;
            if (DEMO) {
                pv.textContent = 'Die Vorschau rendert der PHP-Server — in der statischen Demo gibt es keinen.';
                return;
            }
            // Antworten können sich überholen: nur die jüngste Anfrage zählt
            const seq = ++this.previewSeq;
            try {
                const r = await fetch('api.php?action=preview', {
                    method: 'POST',
                    body: new URLSearchParams({ text: ta.value }),
                });
                const html = r.ok ? await r.text() : null;
                if (html !== null && seq === this.previewSeq) pv.innerHTML = html;
            } catch { /* ignore */ }
        };

        ta.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(update, 250);
        });

        tg.addEventListener('click', () => {
            pv.hidden = !pv.hidden;
            tg.textContent = pv.hidden ? 'Vorschau' : 'Vorschau ausblenden';
            update();
        });
    }

    // ---------- File drop ----------
    bindFileDrop() {
        const drop    = document.getElementById('filedrop');
        const input   = document.getElementById('f-image');
        const preview = document.getElementById('filedrop-preview');
        const remove  = document.getElementById('filedrop-remove');
        const delFlag = document.getElementById('f-delete-image');
        const hint    = document.querySelector('.filedrop__hint');

        // Abgelehnte Datei aus dem Feld nehmen — sonst ginge sie beim
        // Speichern trotzdem mit hoch.
        const reject = (msg) => {
            input.value = '';
            this.toast(msg, 'error');
        };
        const handleFile = (file) => {
            if (!file) return;
            if (!file.type.startsWith('image/')) {
                reject('Nur Bilder erlaubt');
                return;
            }
            if (file.size > 2 * 1024 * 1024) {
                reject('Datei zu groß (max. 2 MB)');
                return;
            }
            const reader = new FileReader();
            reader.onload = (e) => {
                preview.src = e.target.result;
                preview.hidden = false;
                remove.hidden = false;
                hint.style.display = 'none';
                delFlag.value = '0';
            };
            reader.readAsDataURL(file);
        };

        input.addEventListener('change', () => handleFile(input.files[0]));

        ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, (e) => {
            e.preventDefault();
            drop.classList.add('is-over');
        }));
        ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, (e) => {
            e.preventDefault();
            drop.classList.remove('is-over');
        }));
        drop.addEventListener('drop', (e) => {
            const file = e.dataTransfer.files[0];
            if (file) {
                input.files = e.dataTransfer.files;
                handleFile(file);
            }
        });

        remove.addEventListener('click', (e) => {
            e.stopPropagation();
            input.value = '';
            preview.src = '';
            preview.hidden = true;
            remove.hidden = true;
            hint.style.display = '';
            delFlag.value = '1';
        });
    }

    // ---------- Leises Polling (statt Seiten-Reload) ----------
    setupPolling() {
        if (DEMO) return; // statischer Abzug — es gibt nichts nachzuladen
        const sec = window.cmsConfig?.pollSeconds || 0;
        if (sec > 0) {
            // Im Hintergrund (Tab verdeckt, Display aus) nicht pollen —
            // visibilitychange holt beim Zurückkommen sofort nach.
            setInterval(() => { if (!document.hidden) this.refreshFromServer(); }, sec * 1000);
        }
        // Beim Zurückkehren zum Tab (z. B. Tablet-Wakeup) sofort aktualisieren
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                this.refreshFromServer();
            }
        });
    }

    async refreshFromServer(force = false) {
        // Nicht mitten in einer Interaktion neu rendern
        if (!force && (!this.modal.hidden || this.draggingId)) return;
        try {
            const r = await this.fetchJSON(
                'api.php?action=entries&since=' + encodeURIComponent(force ? '' : this.version)
            );
            if (r && r.success && r.changed) {
                this.applyState(r);
            }
        } catch { /* Netz weg o. ä. — nächster Tick versucht es wieder */ }
    }

    // ---------- Fetch helper ----------
    async fetchJSON(url, opts = {}) {
        if (DEMO) return this.demoFetch(url, opts);
        const headers = opts.headers || {};
        if (opts.authed && this.pwd) {
            headers['X-Auth-Password'] = this.pwd;
        }
        const r = await fetch(url, { ...opts, headers });
        let data = null;
        const ct = r.headers.get('content-type') || '';
        if (ct.includes('application/json')) {
            try {
                data = await r.json();
            } catch { /* kaputte Antwort → generischer Fehler unten */ }
        }
        if (!r.ok) {
            if (r.status === 401) {
                this.pwd = null;
            }
            const err = new Error(data?.error || `HTTP ${r.status}`);
            err.status = r.status;
            throw err;
        }
        if (data === null) {
            throw new Error('Unerwartete Server-Antwort');
        }
        return data;
    }

    // ---------- Öffentliche Demo ----------
    /** Beantwortet die API-Aufrufe der Demo lokal (Antwortformat wie api.php). */
    async demoFetch(url, opts) {
        const action = new URL(url, location.href).searchParams.get('action');
        const body = opts.body instanceof URLSearchParams ? opts.body : null;
        switch (action) {
            case 'entries': {
                const since = new URL(url, location.href).searchParams.get('since');
                return since
                    ? { success: true, changed: false, version: this.version }
                    : { success: true, changed: true, version: this.version, entries: this.demoSaved.slice() };
            }
            case 'verify':
                if (body?.get('password') !== DEMO_PASSWORD) {
                    throw new Error(`Falsches Passwort — in der Demo lautet es ${DEMO_PASSWORD}`);
                }
                return { success: true };
            case 'reorder':
                this.demoSaved = this.entries.slice();
                return { success: true, version: this.version, entries: this.demoSaved.slice() };
            default: // save, delete
                throw new Error(DEMO_MSG);
        }
    }

    showDemoBanner() {
        const bar = document.createElement('div');
        bar.className = 'demo-banner';
        bar.innerHTML = '<strong>Live-Demo</strong> — Filter, Suche, Stile, Verschieben und Bearbeiten ausprobieren '
            + `(Passwort <code>${DEMO_PASSWORD}</code>). Gespeichert wird nichts. `
            + `<a href="${DEMO_REPO}" target="_blank" rel="noopener">RookyCMS selbst installieren ›</a>`;
        document.querySelector('main.page')?.prepend(bar);
    }

    // ---------- Toasts ----------
    toast(message, kind = 'info', ttl = 3500) {
        const t = document.createElement('div');
        t.className = `toast toast--${kind}`;
        t.textContent = message;
        this.toasts.appendChild(t);
        setTimeout(() => {
            t.classList.add('is-leaving');
            setTimeout(() => t.remove(), 300);
        }, ttl);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    window.cms = new CMS();
});
