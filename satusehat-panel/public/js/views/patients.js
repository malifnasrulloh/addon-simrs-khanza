/* ============================================================
   Patients view - list, filters (incl. date range), stats,
   expandable per-patient dropdown rows, batch selection
   ============================================================ */

'use strict';

import { api } from '../api.js';
import {
    $, escapeHtml, debounce, toast, avatarHtml,
    skeletonRows, emptyStateHtml, icon,
} from '../ui.js';
import {
    state, patientStatus, countReadyResources, readinessLevel,
    toISODate, rangeLabel,
} from '../state.js';
import { openDrawer } from './drawer.js';

// Monotonic guard: only the newest loadPatients request may paint.
let patientsSeq = 0;

const searchInput = debounce((e) => {
    state.filters.search = e.target.value;
    state.page = 1;
    loadPatients(1);
}, 300);

const dateApply = debounce(() => {
    applyDateInputs();
}, 300);

export function initPatientsView() {
    $('search').addEventListener('input', searchInput);
    $('filter-billing').addEventListener('change', (e) => { state.filters.billing = e.target.value; renderTable(); });
    $('filter-type').addEventListener('change', (e) => { state.filters.type = e.target.value; renderTable(); });
    $('filter-resource').addEventListener('change', (e) => { state.filters.resource = e.target.value; renderTable(); });

    $('range-presets').querySelectorAll('.seg-btn').forEach(btn => {
        btn.addEventListener('click', () => applyDatePreset(btn.dataset.preset));
    });
    $('date-since').addEventListener('input', dateApply);
    $('date-until').addEventListener('input', dateApply);

    const prevBtn = $('btn-page-prev');
    const nextBtn = $('btn-page-next');
    if (prevBtn) {
        prevBtn.addEventListener('click', () => {
            if (state.page > 1) {
                state.page--;
                loadPatients(state.page);
            }
        });
    }
    if (nextBtn) {
        nextBtn.addEventListener('click', () => {
            if (state.page < state.paginationMeta.pages) {
                state.page++;
                loadPatients(state.page);
            }
        });
    }

    $('batch-select-all').addEventListener('change', (e) => {
        const filtered = applyFilters();
        if (e.target.checked) filtered.forEach(p => state.batchSel.add(p.no_rawat));
        else filtered.forEach(p => state.batchSel.delete(p.no_rawat));
        renderTable();
    });

    $('btn-refresh').addEventListener('click', () => {
        loadPatients(state.page);
        toast('Memuat ulang', 'Data pasien disegarkan', 'info');
    });
}

export function showPatientsView() {
    $('table-wrap').hidden = false;
    $('audit-view').hidden = true;
    $('settings-view').hidden = true;
    $('list-title').textContent = 'Daftar Pasien';
    updateSubtitle();
}

export function setConn(st, label) {
    const el = $('conn-status');
    el.dataset.state = st;
    el.querySelector('.label').textContent = label;
}

function updateSubtitle() {
    const rl = rangeLabel(state.dateRange.since, state.dateRange.until);
    const total = state.paginationMeta.total || state.patients.length;
    const shown = applyFilters().length;
    $('list-subtitle').textContent = total
        ? `Menampilkan ${shown} dari ${total} pasien · ${rl}`
        : `Memuat data... · ${rl}`;
}

export async function loadPatients(targetPage = 1) {
    const seq = ++patientsSeq;
    state.page = targetPage;
    const tbody = $('patient-tbody');
    const alert = $('list-alert');
    alert.hidden = true;
    tbody.innerHTML = skeletonRows();
    updateSubtitle();
    // Batch selection survives page turns and reloads, but a changed server
    // filter (date range / search) invalidates it — prune then.
    const filterKey = `${state.dateRange.since}|${state.dateRange.until}|${state.filters.search}`;
    if (state.lastFilterKey !== filterKey) {
        state.batchSel.clear();
        state.lastFilterKey = filterKey;
    }
    updateBatchBar();

    const qs = new URLSearchParams();
    if (state.dateRange.since) qs.set('since', state.dateRange.since);
    if (state.dateRange.until) qs.set('until', state.dateRange.until);
    if (state.filters.search) qs.set('search', state.filters.search);
    qs.set('page', String(state.page));
    qs.set('per_page', String(state.perPage));

    try {
        const data = await api(`/api/patients?${qs}`);
        if (seq !== patientsSeq) return; // a newer load won this race
        state.patients = data.data || [];
        if (data.meta) {
            state.paginationMeta = data.meta;
        } else {
            state.paginationMeta = { total: state.patients.length, page: 1, per_page: 50, pages: 1 };
        }
        state.stats = data.stats || null;
        renderStats();
        renderTable();
        renderPagination();
        setConn('ok', 'Terhubung ke SIMRS');
    } catch (e) {
        if (seq !== patientsSeq) return;
        setConn('error', 'Gagal terhubung');
        state.patients = [];
        renderStats();
        tbody.innerHTML = '';
        alert.hidden = false;
        alert.innerHTML = `
            ${icon('alert')}
            <span>${escapeHtml(e.message)}</span>
            <button class="btn btn-danger btn-sm" type="button" id="alert-retry">Coba lagi</button>`;
        $('alert-retry').addEventListener('click', () => loadPatients(targetPage));
    }
}

function renderPagination() {
    const bar = $('pagination-bar');
    if (!bar) return;
    const meta = state.paginationMeta;
    if (!meta || meta.total === 0) {
        bar.hidden = true;
        return;
    }
    bar.hidden = false;
    const start = (meta.page - 1) * meta.per_page + 1;
    const end = Math.min(meta.page * meta.per_page, meta.total);
    $('pagination-info').textContent = `Menampilkan ${start}-${end} dari ${meta.total} pasien`;
    $('pagination-page').textContent = `Halaman ${meta.page} dari ${meta.pages}`;
    $('btn-page-prev').disabled = meta.page <= 1;
    $('btn-page-next').disabled = meta.page >= meta.pages;
}

/* ── Stats (server-computed over the filtered range) ──────── */
function renderStats() {
    const s = state.stats;
    if (s && s.total !== undefined) {
        $('stat-total').textContent = String(s.total);
        $('stat-paid').textContent = String(s.paid ?? 0);
        $('stat-unpaid').textContent = String(s.unpaid ?? 0);
        $('stat-ready').textContent = String(s.ready ?? 0);
        return;
    }
    // Fallback (legacy server): current page only.
    const paid = state.patients.filter(p => patientStatus(p) === 'paid').length;
    const ready = state.patients.filter(p => countReadyResources(p.resource_counts) > 0).length;
    $('stat-total').textContent = state.patients.length;
    $('stat-paid').textContent = paid;
    $('stat-unpaid').textContent = state.patients.length - paid;
    $('stat-ready').textContent = ready;
}

/* ── Client-side filters ──────────────────────────────────── */
function applyFilters() {
    const { search, billing, type, resource } = state.filters;
    return state.patients.filter(p => {
        if (billing !== 'all' && p.status_bayar !== billing) return false;
        if (type !== 'all' && p.status_lanjut !== type) return false;
        if (search) {
            const q = search.toLowerCase();
            const hay = `${p.no_rawat} ${p.nm_pasien} ${p.no_rkm_medis}`.toLowerCase();
            if (!hay.includes(q)) return false;
        }
        if (resource !== 'all') {
            const ready = countReadyResources(p.resource_counts);
            if (resource === 'ready' && ready === 0) return false;
            if (resource === 'sent' && ready > 0) return false;
        }
        return true;
    });
}

/* ── Date range (server-side) ─────────────────────────────── */
function syncDateControls() {
    const { since, until, preset } = state.dateRange;
    $('date-since').value = since;
    $('date-until').value = until;
    $('range-presets').querySelectorAll('.seg-btn').forEach(btn => {
        btn.setAttribute('aria-pressed', String(btn.dataset.preset === (preset || '')));
    });
}

function applyDatePreset(preset) {
    if (preset === '') {
        state.dateRange = { since: '', until: '', preset: '' };
    } else {
        const days = parseInt(preset, 10);
        const until = new Date();
        const since = new Date();
        since.setDate(until.getDate() - (days - 1));
        state.dateRange = { since: toISODate(since), until: toISODate(until), preset: String(days) };
    }
    syncDateControls();
    state.page = 1;
    loadPatients(1);
}

function applyDateInputs() {
    let since = $('date-since').value;
    let until = $('date-until').value;
    if (since && until && since > until) [since, until] = [until, since];
    if (since === '' && until === '') {
        state.dateRange = { since: '', until: '', preset: '' };
    } else {
        state.dateRange = { since, until, preset: 'custom' };
    }
    syncDateControls();
    state.page = 1;
    loadPatients(1);
}

/* ── Render table ─────────────────────────────────────────── */
function resourceChips(rc, limit = 5) {
    return Object.entries(rc || {})
        .filter(([, c]) => c > 0)
        .slice(0, limit)
        .map(([t, c]) => `<span class="chip chip-ready" title="${escapeHtml(t)}">${escapeHtml(t)} <b>${c}</b></span>`)
        .join('');
}

function rowHtml(p, i) {
    const isPaid = patientStatus(p) === 'paid';
    const resReady = countReadyResources(p.resource_counts);
    const resTotal = Object.keys(p.resource_counts || {}).length;
    const lvl = readinessLevel(p.resource_counts);
    const chips = resourceChips(p.resource_counts);
    const sel = state.batchSel.has(p.no_rawat);
    const open = state.expanded === p.no_rawat;
    const delay = Math.min(i * 20, 400);
    const entering = open ? '' : ` row-entering" style="animation-delay:${delay}ms`;
    return `<tr class="patient-row${sel ? ' row-selected' : ''}${open ? ' is-open' : ''}${entering}" data-no-rawat="${escapeHtml(p.no_rawat)}" tabindex="0" role="button" aria-expanded="${open}" aria-label="Pasien ${escapeHtml(p.nm_pasien)}, ${escapeHtml(p.no_rawat)}. Enter untuk membuka ringkasan.">
        <td class="td-check"><input type="checkbox" class="batch-check row-batch" data-no-rawat="${escapeHtml(p.no_rawat)}" aria-label="Pilih ${escapeHtml(p.nm_pasien)} untuk batch" ${sel ? 'checked' : ''} ${state.batchRunning ? 'disabled' : ''}></td>
        <td class="td-patient">
            <div class="patient-cell">
                ${avatarHtml(p.nm_pasien, p.no_rkm_medis)}
                <div class="patient-id">
                    <div class="patient-name">${escapeHtml(p.nm_pasien)}</div>
                    <div class="patient-rm">RM ${escapeHtml(p.no_rkm_medis)}</div>
                </div>
            </div>
        </td>
        <td class="td-rawat no-rawat">${escapeHtml(p.no_rawat)}</td>
        <td class="td-date"><div class="tgl tabular">${escapeHtml(p.tgl_registrasi)}</div><div class="jam tabular">${escapeHtml(p.jam_reg || '')}</div></td>
        <td class="td-type"><span class="badge badge-neutral">${escapeHtml(p.status_lanjut || '-')}</span></td>
        <td class="td-billing"><span class="badge ${isPaid ? 'badge-paid' : 'badge-unpaid'}"><span class="dot" aria-hidden="true"></span>${escapeHtml(p.status_bayar || '-')}</span></td>
        <td class="td-resource">${resReady > 0
            ? `<div class="res-cell"><div class="chips">${chips}</div><span class="res-meta"><span class="progress-track" data-lvl="${lvl}" aria-hidden="true"><i></i><i></i><i></i></span>${resReady}/${resTotal} jenis data</span></div>`
            : '<span class="muted small">Belum ada data</span>'}</td>
        <td class="td-actions">${icon('chevron', 'icon chev')}</td>
    </tr>`;
}

function detailRowHtml(p) {
    const resReady = countReadyResources(p.resource_counts);
    const resTotal = Object.keys(p.resource_counts || {}).length;
    const chips = resourceChips(p.resource_counts, 8);
    const jk = p.jk === 'L' ? 'Laki-laki' : p.jk === 'P' ? 'Perempuan' : (p.jk || '-');
    const keluar = p.tgl_keluar
        ? `${escapeHtml(p.tgl_keluar)} <span class="muted small">${escapeHtml(p.jam_keluar || '')}</span>`
        : '<span class="muted small">Masih dalam perawatan</span>';
    return `<tr class="row-detail" data-rawat="${escapeHtml(p.no_rawat)}">
        <td colspan="8">
            <div class="row-detail-inner">
                <div class="rd-grid">
                    <div class="rd-cell"><span class="rd-label">No. RM</span><span class="rd-value mono">${escapeHtml(p.no_rkm_medis || '-')}</span></div>
                    <div class="rd-cell"><span class="rd-label">NIK</span><span class="rd-value mono">${escapeHtml(p.no_ktp || '-')}</span></div>
                    <div class="rd-cell"><span class="rd-label">Tanggal lahir</span><span class="rd-value">${escapeHtml(p.tgl_lahir || '-')}</span></div>
                    <div class="rd-cell"><span class="rd-label">Jenis kelamin</span><span class="rd-value">${escapeHtml(jk)}</span></div>
                    <div class="rd-cell"><span class="rd-label">Poli</span><span class="rd-value mono">${escapeHtml(p.kd_poli || '-')}</span></div>
                    <div class="rd-cell"><span class="rd-label">Tanggal keluar</span><span class="rd-value">${keluar}</span></div>
                    <div class="rd-cell rd-cell-wide">
                        <span class="rd-label">Resource ${resReady}/${resTotal} jenis data</span>
                        <div class="rd-res-chips">${chips || '<span class="muted small">Tidak ada data klinis untuk kunjungan ini</span>'}</div>
                    </div>
                </div>
                <div class="rd-actions">
                    <button class="btn btn-primary btn-sm" type="button" data-open-detail="${escapeHtml(p.no_rawat)}">${icon('doc')} Buka detail lengkap</button>
                </div>
            </div>
        </td>
    </tr>`;
}

export function renderTable() {
    const tbody = $('patient-tbody');
    const filtered = applyFilters();
    updateSubtitle();

    if (!filtered.length) {
        const hasServerData = state.patients.length > 0;
        tbody.innerHTML = `<tr><td colspan="8">${emptyStateHtml({
            iconName: 'search',
            title: hasServerData ? 'Tidak ada pasien yang cocok' : 'Tidak ada pasien di rentang ini',
            body: hasServerData
                ? 'Coba ubah kata kunci atau filter lainnya.'
                : 'Perbesar rentang tanggal atau muat ulang data.',
            actionHtml: `<button class="btn btn-ghost btn-sm" type="button" id="empty-reset">${hasServerData ? 'Reset filter' : 'Reset rentang tanggal'}</button>`,
        })}</td></tr>`;
        $('empty-reset').addEventListener('click', () => {
            if (hasServerData) resetClientFilters();
            else applyDatePreset('90');
        });
        syncSelectAllHeader([]);
        updateBatchBar();
        return;
    }

    syncSelectAllHeader(filtered);
    updateBatchBar();

    const rows = [];
    filtered.forEach((p, i) => {
        rows.push(rowHtml(p, i));
        if (state.expanded === p.no_rawat) rows.push(detailRowHtml(p));
    });
    tbody.innerHTML = rows.join('');

    tbody.querySelectorAll('tr.patient-row[data-no-rawat]').forEach(tr => {
        tr.addEventListener('click', (e) => {
            if (e.target.closest('button')) return;
            if (e.target.closest('input')) return;
            toggleRow(tr);
        });
        tr.addEventListener('keydown', (e) => {
            if (e.target.closest('input')) return;
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                toggleRow(tr);
            }
        });
    });
    tbody.querySelectorAll('[data-open-detail]').forEach(btn => {
        btn.addEventListener('click', () => openDrawer(btn.dataset.openDetail));
    });

    tbody.querySelectorAll('.row-batch').forEach(cb => {
        cb.addEventListener('change', (e) => {
            const rawat = cb.dataset.noRawat;
            if (cb.checked) state.batchSel.add(rawat);
            else state.batchSel.delete(rawat);
            cb.closest('tr').classList.toggle('row-selected', cb.checked);
            syncSelectAllHeader();
            updateBatchBar();
        });
    });
}

function toggleRow(tr) {
    const rawat = tr.dataset.noRawat;
    state.expanded = state.expanded === rawat ? null : rawat;
    renderTable();
}

function resetClientFilters() {
    state.filters = { search: '', billing: 'all', type: 'all', resource: 'all' };
    $('search').value = '';
    $('filter-billing').value = 'all';
    $('filter-type').value = 'all';
    $('filter-resource').value = 'all';
    renderTable();
}

/* ── Batch selection UI ───────────────────────────────────── */
function syncSelectAllHeader(filtered) {
    const allCb = $('batch-select-all');
    if (!allCb) return;
    const list = filtered || applyFilters();
    const visible = list.map(p => p.no_rawat);
    const visibleAll = visible.length > 0 && visible.every(r => state.batchSel.has(r));
    const visibleSome = visible.some(r => state.batchSel.has(r));
    allCb.checked = visibleAll;
    allCb.indeterminate = visibleSome && !visibleAll;
    allCb.disabled = state.batchRunning;
}

export function updateBatchBar() {
    const btn = $('btn-clear-batch');
    const count = state.batchSel.size;
    btn.hidden = count === 0;
    btn.textContent = count ? `Kirim batch (${count})` : 'Kirim batch';
}
