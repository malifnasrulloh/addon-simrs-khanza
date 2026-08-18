/* ============================================================
   Command palette (Ctrl/Cmd+K)
   ============================================================ */

'use strict';

import { $, escapeHtml, icon, rememberFocus, restoreFocus, trapFocus, untrapFocus } from './ui.js';
import { state, toISODate, todayISO } from './state.js';
import { openDrawer } from './views/drawer.js';
import { loadPatients } from './views/patients.js';

const palette = {
    open: false,
    items: [],
    index: 0,
};

export function initPalette() {
    $('btn-palette').addEventListener('click', openPalette);
    $('palette-backdrop').addEventListener('click', (e) => {
        if (e.target.id === 'palette-backdrop') closePalette();
    });
    $('palette-input').addEventListener('input', () => { palette.index = 0; renderPalette(); });
    $('palette-input').addEventListener('keydown', (e) => {
        const filtered = currentItems();
        if (e.key === 'ArrowDown') { e.preventDefault(); palette.index = Math.min(palette.index + 1, filtered.length - 1); renderPalette(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); palette.index = Math.max(palette.index - 1, 0); renderPalette(); }
        else if (e.key === 'Enter') { e.preventDefault(); runPaletteItem(palette.index); }
    });
}

function currentItems() {
    const q = $('palette-input').value.trim().toLowerCase();
    return q ? palette.items.filter(i => `${i.title} ${i.sub || ''}`.toLowerCase().includes(q)) : palette.items;
}

function setDatePreset(days) {
    const until = new Date();
    const since = new Date();
    since.setDate(until.getDate() - (days - 1));
    state.dateRange = days ? { since: toISODate(since), until: toISODate(until), preset: String(days) } : { since: '', until: '', preset: '' };
    $('date-since').value = state.dateRange.since;
    $('date-until').value = state.dateRange.until;
    $('range-presets').querySelectorAll('.seg-btn').forEach(btn => {
        btn.setAttribute('aria-pressed', String(btn.dataset.preset === (state.dateRange.preset || '')));
    });
    loadPatients();
}

function paletteCommands() {
    const cmds = [
        { id: 'patients', title: 'Buka daftar pasien', icon: icon('grid', 'p-icon'), run: () => { location.hash = '#/'; } },
        { id: 'audit', title: 'Buka audit log', icon: icon('doc', 'p-icon'), run: () => { location.hash = '#/audit'; } },
        { id: 'settings', title: 'Pengaturan Satu Sehat', icon: icon('sliders', 'p-icon'), run: () => { location.hash = '#/settings'; } },
        { id: 'refresh', title: 'Muat ulang data pasien', icon: icon('refresh', 'p-icon'), run: () => loadPatients() },
        { id: 'range7', title: 'Filter: 7 hari terakhir', sub: 'Rentang tanggal', icon: icon('calendar', 'p-icon'), run: () => setDatePreset(7) },
        { id: 'range30', title: 'Filter: 30 hari terakhir', sub: 'Rentang tanggal', icon: icon('calendar', 'p-icon'), run: () => setDatePreset(30) },
        { id: 'range90', title: 'Filter: 90 hari terakhir', sub: 'Rentang tanggal', icon: icon('calendar', 'p-icon'), run: () => setDatePreset(90) },
        { id: 'rangeAll', title: 'Filter: semua tanggal', sub: 'Rentang tanggal', icon: icon('calendar', 'p-icon'), run: () => setDatePreset(0) },
        { id: 'theme', title: 'Ganti tema gelap / terang', icon: icon('sun', 'p-icon'), run: () => $('theme-toggle').click() },
    ];
    state.patients.slice(0, 6).forEach(p => {
        cmds.push({
            id: `p-${p.no_rawat}`,
            title: `${p.nm_pasien}, ${p.no_rawat}`,
            sub: `RM ${p.no_rkm_medis}`,
            icon: icon('user', 'p-icon'),
            run: () => openDrawer(p.no_rawat),
        });
    });
    return cmds;
}

export function openPalette() {
    palette.items = paletteCommands();
    palette.index = 0;
    palette.open = true;
    rememberFocus();
    $('palette-backdrop').hidden = false;
    $('palette-input').value = '';
    renderPalette();
    trapFocus($('palette-backdrop'));
    $('palette-input').focus();
}

export function closePalette() {
    palette.open = false;
    $('palette-backdrop').hidden = true;
    untrapFocus($('palette-backdrop'));
    restoreFocus();
}

function renderPalette() {
    const filtered = currentItems();
    if (palette.index >= filtered.length) palette.index = 0;
    const list = $('palette-list');
    if (!filtered.length) {
        list.innerHTML = '<div class="palette-empty">Tidak ada hasil untuk pencarian ini</div>';
        return;
    }
    list.innerHTML = filtered.map((item, i) => `
        <button class="palette-item ${i === palette.index ? 'active' : ''}" role="option" aria-selected="${i === palette.index}" data-idx="${i}" type="button">
            ${item.icon}
            <span>${escapeHtml(item.title)}${item.sub ? ` <span class="muted small">${escapeHtml(item.sub)}</span>` : ''}</span>
        </button>`).join('');
    list.querySelectorAll('.palette-item').forEach(btn => {
        btn.addEventListener('click', () => runPaletteItem(parseInt(btn.dataset.idx, 10)));
    });
    const active = list.querySelector('.palette-item.active');
    if (active) active.scrollIntoView({ block: 'nearest' });
}

function runPaletteItem(idx) {
    const filtered = currentItems();
    const item = filtered[idx];
    if (!item) return;
    closePalette();
    item.run();
}
