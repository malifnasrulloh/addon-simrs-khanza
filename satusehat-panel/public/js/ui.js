/* ============================================================
   UI primitives - DOM helpers, toast, focus trap, avatar,
   skeleton, empty states, inline icon set
   ============================================================ */

'use strict';

export function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = String(str ?? '');
    return div.innerHTML;
}

export function debounce(fn, ms) {
    let t;
    return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
}

export function prefersReducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

const $ = (id) => document.getElementById(id);
export { $ };

/* ── Icons (24px stroke glyphs, consistent 1.8 weight) ────── */
const ICON_PATHS = {
    chevron: 'm9 18 6-6-6-6',
    search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
    refresh: '<path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/>',
    sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
    doc: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 13h6M9 17h6"/>',
    eye: '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
    check: '<path d="M20 6 9 17l-5-5"/>',
    alert: '<circle cx="12" cy="12" r="9"/><path d="M12 8v4m0 4h.01"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 8h.01M12 12v4"/>',
    user: '<circle cx="12" cy="12" r="4"/><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>',
    calendar: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    grid: '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    sliders: '<path d="M4 6h16M4 12h10M4 17h7"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
};

export function icon(name, cls = 'icon') {
    const body = ICON_PATHS[name] || ICON_PATHS.info;
    return `<svg class="${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">${body}</svg>`;
}

/* ── Toast ────────────────────────────────────────────────── */
export function toast(title, body, type = 'info') {
    const wrap = $('toast-wrap');
    const el = document.createElement('div');
    el.className = `toast toast-${type}`;
    el.setAttribute('role', type === 'error' ? 'alert' : 'status');
    el.innerHTML = `${icon(type === 'success' ? 'check' : type === 'error' ? 'alert' : 'info', 'icon t-icon')}
        <div><div class="t-title"></div>${body ? '<div class="t-body"></div>' : ''}</div>`;
    el.querySelector('.t-title').textContent = title;
    if (body) el.querySelector('.t-body').textContent = body;
    wrap.appendChild(el);
    setTimeout(() => {
        el.classList.add('leaving');
        setTimeout(() => el.remove(), 160);
    }, 4200);
}

/* ── Focus memory + trap (modal/drawer/palette) ───────────── */
const focusStack = [];
export function rememberFocus(el = document.activeElement) {
    focusStack.push(el);
}
export function restoreFocus() {
    const el = focusStack.pop();
    if (el && el.isConnected) el.focus();
}

const trapHandlers = new WeakMap();

export function trapFocus(container) {
    const focusables = () => [...container.querySelectorAll(
        'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
    )].filter(el => !el.disabled && el.offsetParent !== null);
    const first = () => focusables()[0];
    const last = () => focusables()[focusables().length - 1];
    const onKey = (e) => {
        if (e.key !== 'Tab') return;
        const f = focusables();
        if (!f.length) return;
        if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
        else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
    };
    if (!trapHandlers.has(container)) {
        container.addEventListener('keydown', onKey);
        trapHandlers.set(container, onKey);
    }
    // Async content (skeleton -> data): keep trying until something focusable.
    const initial = first();
    if (initial) initial.focus();
    else {
        const fallback = container.querySelector('.btn-icon') || $('drawer-close') || container;
        if (fallback && fallback.focus) fallback.focus();
        const h = setInterval(() => {
            const n = first();
            if (n) { clearInterval(h); n.focus(); }
        }, 50);
        setTimeout(() => clearInterval(h), 5000);
    }
}

/** Remove the keydown handler installed by trapFocus (call on close). */
export function untrapFocus(container) {
    const handler = trapHandlers.get(container);
    if (handler) {
        container.removeEventListener('keydown', handler);
        trapHandlers.delete(container);
    }
}

/* ── Avatar ───────────────────────────────────────────────── */
function hashHue(str) {
    let h = 0;
    for (let i = 0; i < str.length; i++) h = (h * 31 + str.charCodeAt(i)) >>> 0;
    return h % 360;
}
function initials(name) {
    const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    const first = parts[0][0];
    const last = parts.length > 1 ? parts[parts.length - 1][0] : '';
    return (first + last).toUpperCase();
}
export function avatarHtml(name, hueSeed, sizeClass = '') {
    return `<span class="avatar ${sizeClass}" style="--av-hue:${hashHue(hueSeed || name)}" aria-hidden="true">${escapeHtml(initials(name))}</span>`;
}

/* ── Skeleton ─────────────────────────────────────────────── */
export function skeletonRows() {
    return Array.from({ length: 6 }, (_, i) => `
        <tr class="patient-row" aria-hidden="true" style="cursor:default">
            <td class="td-check"><span class="skeleton" style="width:16px;height:16px;display:inline-block"></span></td>
            <td class="td-patient"><span class="skeleton" style="width:${150 - i * 12}px;height:14px;display:inline-block"></span></td>
            <td class="td-rawat"><span class="skeleton" style="width:92px;height:12px;display:inline-block"></span></td>
            <td class="td-date"><span class="skeleton" style="width:76px;height:12px;display:inline-block"></span></td>
            <td class="td-type"><span class="skeleton" style="width:52px;height:12px;display:inline-block"></span></td>
            <td class="td-billing"><span class="skeleton" style="width:64px;height:14px;display:inline-block"></span></td>
            <td class="td-resource"><span class="skeleton" style="width:130px;height:14px;display:inline-block"></span></td>
            <td class="td-actions"><span class="skeleton" style="width:16px;height:16px;display:inline-block"></span></td>
        </tr>`).join('');
}

/* ── Empty state ──────────────────────────────────────────── */
export function emptyStateHtml({ iconName = 'info', title, body, actionHtml = '' }) {
    return `
        <div class="empty-state">
            <div class="empty-icon" aria-hidden="true">${icon(iconName)}</div>
            <div class="empty-title">${escapeHtml(title)}</div>
            ${body ? `<div class="small muted">${escapeHtml(body)}</div>` : ''}
            ${actionHtml ? `<div class="empty-action">${actionHtml}</div>` : ''}
        </div>`;
}
