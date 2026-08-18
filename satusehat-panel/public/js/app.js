/* ============================================================
   SATUSEHAT Admin Panel - app v3 (ES modules, zero build)
   Boot, auth, hash routing, keyboard, theme, login ambient
   ============================================================ */

'use strict';

import { $, toast, prefersReducedMotion } from './ui.js';
import { state, bus } from './state.js';
import { base, api, setCsrfToken } from './api.js';
import { initPatientsView, showPatientsView, loadPatients } from './views/patients.js';
import { initDrawerView, hideDrawer } from './views/drawer.js';
import { initAuditView, showAuditView } from './views/audit.js';
import { initSettingsView, showSettingsView } from './views/settings.js';
import { initPayloadEditor, hidePayloadModal } from './payload.js';
import { initBatchView, hideBatchModal, resetBatchRun } from './batch.js';
import { initPalette, openPalette, closePalette } from './palette.js';

/* ── Theme ────────────────────────────────────────────────── */
function initTheme() {
    // index.html already set data-theme pre-paint (saved pref or system);
    // here we only need to enforce the stored preference over it.
    const saved = localStorage.getItem('sh-theme');
    if (saved === 'dark' || saved === 'light') {
        document.documentElement.dataset.theme = saved;
    }
    syncThemeMeta();
}
function syncThemeMeta() {
    const dark = document.documentElement.dataset.theme === 'dark'
        || (!document.documentElement.dataset.theme && window.matchMedia('(prefers-color-scheme: dark)').matches);
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', dark ? '#15181d' : '#ffffff');
}
$('theme-toggle').addEventListener('click', () => {
    const html = document.documentElement;
    const next = html.dataset.theme === 'dark' ? 'light' : 'dark';
    html.dataset.theme = next;
    localStorage.setItem('sh-theme', next);
    syncThemeMeta();
});

/* ── Auth ─────────────────────────────────────────────────── */
async function checkAuth() {
    try {
        const res = await fetch(base('/api/auth/status'), { credentials: 'same-origin' });
        const data = await res.json();
        // Capture the CSRF token issued with the status call — the first
        // POST (first bundle send) must not go out without it.
        if (data.csrf_token) setCsrfToken(data.csrf_token);
        return !!data.authed;
    } catch (e) {
        return false;
    }
}
function showLogin() {
    $('login-screen').hidden = false;
    $('app-root').hidden = true;
    closeDrawerAndModals();
    // Fresh session: clear stale credentials, selections and payload
    // overrides so the next login starts from a clean state.
    state.batchSel.clear();
    state.selected = {};
    state.detail = null;
    state.detailNoRawat = null;
    const pw = $('login-password');
    if (pw) { pw.value = ''; pw.focus(); }
}
function showApp() {
    $('login-screen').hidden = true;
    $('app-root').hidden = false;
}
function closeDrawerAndModals() {
    hideDrawer();
    hidePayloadModal();
    hideBatchModal();
    closePalette();
    closeRail();
}
window.addEventListener('auth:expired', () => {
    toast('Sesi berakhir', 'Silakan masuk kembali', 'error');
    showLogin();
});

$('btn-logout').addEventListener('click', async () => {
    try {
        await api('/api/auth/logout', { method: 'POST' });
    } catch (e) { /* session may already be gone — still return to login */ }
    showLogin();
});

$('login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = $('login-btn');
    const label = btn.querySelector('.btn-label');
    const spinner = btn.querySelector('.spinner');
    const err = $('login-error');
    err.hidden = true;
    btn.disabled = true;
    spinner.hidden = false;
    label.textContent = 'Memeriksa...';
    try {
        const res = await fetch(base('/api/auth/login'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                password: $('login-password').value,
                username: ($('login-username') && $('login-username').value) || '',
            }),
        });
        const data = await res.json();
        if (data.success) {
            // The login response rotates the session token — adopt the fresh
            // CSRF token, or the first POST after login would 403.
            if (data.csrf_token) setCsrfToken(data.csrf_token);
            showApp();
            route();
            loadPatients();
            toast('Terhubung', 'Panel siap digunakan', 'success');
        } else {
            err.textContent = data.error || 'Login gagal';
            err.hidden = false;
            shakeLogin();
        }
    } catch (err2) {
        err.textContent = 'Gagal terhubung ke server';
        err.hidden = false;
        shakeLogin();
    } finally {
        btn.disabled = false;
        spinner.hidden = true;
        label.textContent = 'Masuk';
    }
});

function shakeLogin() {
    if (prefersReducedMotion()) return;
    const card = document.querySelector('.login-card');
    card.classList.remove('shake');
    void card.offsetWidth;
    card.classList.add('shake');
}

/* ── Routing ──────────────────────────────────────────────── */
function route() {
    const h = location.hash || '#/';
    if (h.startsWith('#/audit')) showAuditView();
    else if (h.startsWith('#/settings')) showSettingsView();
    else showPatientsView();
}
window.addEventListener('hashchange', route);

/* ── Mobile filter rail ───────────────────────────────────── */
function railOpen() { return $('filter-rail').classList.contains('open'); }
function openRail() {
    $('filter-rail').classList.add('open');
    $('rail-backdrop').hidden = false;
    $('btn-filter-toggle').setAttribute('aria-expanded', 'true');
}
function closeRail() {
    $('filter-rail').classList.remove('open');
    $('rail-backdrop').hidden = true;
    $('btn-filter-toggle').setAttribute('aria-expanded', 'false');
}
$('btn-filter-toggle').addEventListener('click', () => (railOpen() ? closeRail() : openRail()));
$('rail-close').addEventListener('click', closeRail);
$('rail-backdrop').addEventListener('click', closeRail);

/* ── Keyboard shortcuts ───────────────────────────────────── */
let gChordArmed = false;
document.addEventListener('keydown', (e) => {
    // Cmd/Ctrl+K palette
    if (e.key === 'k' && (e.metaKey || e.ctrlKey)) {
        e.preventDefault();
        if (paletteOpen()) closePalette();
        else openPalette();
    }
    // Cmd/Ctrl+Enter in payload modal = save (only in edit mode — the
    // audit-detail view hides the save button)
    if (e.key === 'Enter' && (e.metaKey || e.ctrlKey) && !$('payload-modal').hidden && !$('modal-save').hidden) {
        e.preventDefault();
        $('modal-save').click();
    }
    // 'g' then 'p'/'a' quick nav (only when not typing)
    if (e.key === 'g' && !gChordArmed && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName) && !e.metaKey && !e.ctrlKey && !e.altKey) {
        gChordArmed = true;
        const onG = (e2) => {
            // Same typing/modifier guard as the 'g' arm: 'gp'/'ga' typed in
            // a field must not yank the view away.
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) return;
            if (e2.metaKey || e2.ctrlKey || e2.altKey) return;
            if (e2.key === 'p' || e2.key === 'a') {
                location.hash = e2.key === 'p' ? '#/' : '#/audit';
                clearTimeout(chordTimer);
                document.removeEventListener('keydown', onG);
                gChordArmed = false;
            }
        };
        const chordTimer = setTimeout(() => {
            document.removeEventListener('keydown', onG);
            gChordArmed = false;
        }, 1200);
        document.addEventListener('keydown', onG);
    }
    // '/' focuses search
    if (e.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) {
        e.preventDefault();
        $('search').focus();
    }
    // Escape cascade
    if (e.key === 'Escape') {
        if (paletteOpen()) closePalette();
        else if (!$('batch-modal').hidden && !state.batchRunning) hideBatchModal();
        else if (!$('payload-modal').hidden) hidePayloadModal();
        else if (!$('drawer').hidden) hideDrawer();
        else if (railOpen()) closeRail();
    }
});
function paletteOpen() { return !$('palette-backdrop').hidden; }

/* ── Data refresh bus ─────────────────────────────────────── */
bus.on('patients:reload', loadPatients);

/* ── Login ambient (signature moment: ECG vital monitor) ──── */
function buildLoginAmbient() {
    const wrap = document.querySelector('.login-ambient');
    if (!wrap) return;
    const reduced = prefersReducedMotion();
    const colors = ['var(--accent)', 'var(--info)', 'var(--data-2)', 'var(--warn)'];
    const bandPath = (t) => {
        let d = 'M0 ' + t;
        for (let i = 1; i <= 12; i++) d += ` ${i % 2 ? 'l' : 'm'}12 ${i % 2 ? -t : t}`;
        return d;
    };
    const positions = [18, 44, 72, 92];
    positions.forEach((pos, i) => {
        const band = document.createElement('div');
        band.className = 'ecg-band';
        band.style.setProperty('--band-y', `${pos}%`);
        band.style.setProperty('--band-color', colors[i % colors.length]);
        band.style.setProperty('--band-dur', `${44 + i * 14}s`);
        band.style.setProperty('--band-delay', `${-i * 9}s`);
        if (reduced) band.style.animation = 'none';
        band.innerHTML = `<svg viewBox="0 0 288 48" preserveAspectRatio="none" aria-hidden="true"><path d="${bandPath(24)}" vector-effect="non-scaling-stroke"/></svg>`;
        wrap.appendChild(band);
    });
}

/* ── Boot ─────────────────────────────────────────────────── */
(async () => {
    initTheme();
    initPatientsView();
    initDrawerView();
    initAuditView();
    initSettingsView();
    initPayloadEditor();
    initBatchView();
    initPalette();
    buildLoginAmbient();

    const authed = await checkAuth();
    if (authed) {
        showApp();
        route();
        loadPatients();
    } else {
        showLogin();
    }
})();
