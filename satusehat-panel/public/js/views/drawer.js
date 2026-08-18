/* ============================================================
   Drawer - patient detail, grouped FHIR resources, bundle send
   ============================================================ */

'use strict';

import { api, extractError } from '../api.js';
import { $, escapeHtml, toast, rememberFocus, restoreFocus, trapFocus, untrapFocus, avatarHtml, emptyStateHtml, icon } from '../ui.js';
import { state, bus, patientStatus } from '../state.js';
import { openPayloadEditor } from '../payload.js';

const RESOURCE_GROUPS = [
    { id: 'visit', title: 'Kunjungan', match: (t) => ['Encounter', 'EpisodeOfCare'].includes(t), color: 'var(--data-1)' },
    { id: 'clinical', title: 'Data Klinis', match: (t) => ['Condition', 'Procedure', 'AllergyIntolerance', 'Immunization', 'CarePlan', 'ClinicalImpression', 'Composition', 'QuestionnaireResponse'].includes(t), color: 'var(--data-2)' },
    { id: 'medication', title: 'Obat', match: (t) => t.startsWith('Medication'), color: 'var(--data-3)' },
    { id: 'other', title: 'Lainnya', match: () => true, color: 'var(--data-0)' },
];

export function initDrawerView() {
    $('drawer-close').addEventListener('click', hideDrawer);
    $('drawer-backdrop').addEventListener('click', hideDrawer);
    $('btn-check-all').addEventListener('click', () => {
        document.querySelectorAll('.resource-check:not(:disabled)').forEach(cb => { cb.checked = true; });
        updateSendSummary();
    });
    $('btn-uncheck-all').addEventListener('click', () => {
        document.querySelectorAll('.resource-check').forEach(cb => { cb.checked = false; });
        updateSendSummary();
    });
    $('btn-send').addEventListener('click', sendBundle);
    $('btn-revert-overrides').addEventListener('click', () => {
        if (!state.detailNoRawat) return;
        delete state.selected[state.detailNoRawat];
        renderOverrideBanner();
        toast('Perubahan payload dibatalkan', 'Payload asli akan digunakan', 'info');
    });
    bus.on('payload:changed', (noRawat) => {
        if (noRawat === state.detailNoRawat) renderOverrideBanner();
    });
}

/* ── Confirm-before-send (two-step arm) ───────────────────── */
let sendArmed = false;
let sendArmTimer = null;

function armSend() {
    sendArmed = true;
    const btn = $('btn-send');
    btn.classList.add('armed');
    btn.querySelector('.btn-label').textContent = 'Konfirmasi kirim';
    clearTimeout(sendArmTimer);
    sendArmTimer = setTimeout(disarmSend, 8000);
}

export function disarmSend() {
    sendArmed = false;
    clearTimeout(sendArmTimer);
    const btn = $('btn-send');
    if (!btn) return;
    btn.classList.remove('armed');
    const label = btn.querySelector('.btn-label');
    if (label && !btn.disabled) label.textContent = 'Kirim via Bundle';
}

/* ── Custom-payload override indicator (U3) ───────────────── */
function renderOverrideBanner() {
    const banner = $('override-banner');
    if (!banner) return;
    const overrides = state.selected[state.detailNoRawat] || {};
    const keys = Object.keys(overrides);
    if (!keys.length) { banner.hidden = true; return; }
    banner.hidden = false;
    banner.querySelector('.override-banner-text').textContent =
        `${keys.length} payload diubah manual untuk pasien ini — kirim ulang akan memakai payload asli untuk sisanya.`;
}

let drawerAbort = null;

export async function openDrawer(noRawat) {
    if (drawerAbort) drawerAbort.abort(); // stale-response race (D4): a newer
    drawerAbort = new AbortController();   // open wins, older fetch is ignored
    const seq = drawerAbort;
    state.detailNoRawat = noRawat;
    // Only snapshot focus on a FRESH open — re-opening (e.g. right after a
    // send) would push a second entry onto the focus stack otherwise.
    if ($('drawer').hidden) rememberFocus();
    $('drawer-backdrop').hidden = false;
    $('drawer').hidden = false;
    $('drawer-title').textContent = 'Detail Pasien';
    $('drawer-subtitle').textContent = 'Memuat data...';
    $('drawer-avatar').textContent = '';
    $('patient-summary').innerHTML = '<span class="skeleton" style="width:100%;height:120px;display:block;border-radius:var(--radius-l)"></span>';
    $('resource-list').innerHTML = '<div class="empty-state"><span class="skeleton" style="width:100%;height:160px;display:block;border-radius:var(--radius-m)"></span></div>';
    updateSendSummary();
    trapFocus($('drawer'));

    const raw = noRawat.replace(/\//g, '%2F');
    try {
        const data = await api(`/api/patients/${raw}`, { signal: drawerAbort.signal });
        if (drawerAbort !== seq) return; // stale response — a newer drawer opened
        if (!data || !data.data || !data.data.patient) {
            throw new Error(data?.error || 'Data detail pasien tidak ditemukan.');
        }
        state.detail = data.data;
        renderSummary(state.detail.patient);
        renderResourceGroups(state.detail.resources || []);
        renderOverrideBanner();
    } catch (e) {
        if (e.name === 'AbortError') return; // superseded by a newer request
        $('patient-summary').innerHTML = emptyStateHtml({
            iconName: 'alert',
            title: 'Gagal memuat detail',
            body: e.message,
            actionHtml: `<button class="btn btn-ghost btn-sm" type="button" id="drawer-retry">Coba lagi</button>`,
        });
        $('drawer-retry').addEventListener('click', () => openDrawer(noRawat));
    }
}

export function hideDrawer() {
    if (drawerAbort) drawerAbort.abort(); // drop any in-flight detail fetch —
    drawerAbort = null;                    // its paint must not re-open data
    $('drawer').hidden = true;
    $('drawer-backdrop').hidden = true;
    state.detail = null;
    state.detailNoRawat = null;
    const banner = $('override-banner');
    if (banner) banner.hidden = true;
    disarmSend();
    untrapFocus($('drawer'));
    restoreFocus();
}

/* ── Summary ──────────────────────────────────────────────── */
function renderSummary(p) {
    const paid = patientStatus(p) === 'paid';
    const jk = p.jk === 'L' ? 'Laki-laki' : p.jk === 'P' ? 'Perempuan' : (p.jk || '-');
    $('drawer-title').textContent = p.nm_pasien;
    $('drawer-subtitle').textContent = `RM ${p.no_rkm_medis} · ${p.no_rawat}`;
    const av = $('drawer-avatar');
    av.style.setProperty('--av-hue', '');
    av.outerHTML = avatarHtml(p.nm_pasien, p.no_rkm_medis, 'avatar avatar-lg');
    const cells = [
        ['No. Rawat', p.no_rawat, true],
        ['No. RM', p.no_rkm_medis, true],
        ['NIK', p.no_ktp || '-', true],
        ['Tanggal lahir', p.tgl_lahir || '-', false],
        ['Jenis kelamin', jk, false],
        ['Poli', p.kd_poli || '-', true],
        ['Tanggal masuk', `${p.tgl_registrasi} ${p.jam_reg || ''}`, false],
        ['Tanggal keluar', p.tgl_keluar ? `${p.tgl_keluar} ${p.jam_keluar || ''}` : 'Masih dirawat', false],
    ];
    $('patient-summary').innerHTML = `
        <div class="psum-grid">
            ${cells.map(([label, val, mono]) => `
                <div class="psum-cell">
                    <span class="psum-label">${escapeHtml(label)}</span>
                    <span class="psum-value ${mono ? 'mono' : ''}">${escapeHtml(val)}</span>
                </div>`).join('')}
            <div class="psum-cell">
                <span class="psum-label">Status billing</span>
                <span><span class="badge ${paid ? 'badge-paid' : 'badge-unpaid'}"><span class="dot" aria-hidden="true"></span>${escapeHtml(p.status_bayar || '-')}</span></span>
            </div>
            <div class="psum-cell">
                <span class="psum-label">Kunjungan</span>
                <span><span class="badge badge-neutral">${escapeHtml(p.status_lanjut || '-')}</span></span>
            </div>
        </div>`;
}

/* ── Resource groups ──────────────────────────────────────── */
function buildResourceGroups(available) {
    const open = state.activeGroup || 'visit';
    return RESOURCE_GROUPS
        .map(g => ({ ...g, items: available.filter(r => g.match(r.type)) }))
        .filter(g => g.items.length > 0)
        .map(g => ({ ...g, open: g.id === open }));
}

function resourceRow(r) {
    const sent = !!r.sent;
    const available = !!r.available;
    const checked = available && !sent ? 'checked' : '';
    const statusLabel = sent ? 'Sudah terkirim' : (available ? 'Siap kirim' : 'Tidak ada data');
    const statusClass = sent ? 'sent' : (available ? 'ready' : '');
    return `<div class="resource-item${sent || !available ? ' disabled' : ''}" data-type="${escapeHtml(r.type)}">
        <input type="checkbox" class="resource-check" data-resource="${escapeHtml(r.type)}" ${checked} ${(!available || sent) ? 'disabled' : ''} aria-label="Pilih ${escapeHtml(r.type)}">
        <div class="resource-info">
            <div class="resource-type">${escapeHtml(r.type)}</div>
            <div class="resource-status ${statusClass}">${statusLabel}</div>
        </div>
        <button class="btn-icon resource-preview" data-resource="${escapeHtml(r.type)}" title="Lihat / edit payload" ${available ? '' : 'disabled'}>
            ${icon('eye')}
        </button>
    </div>`;
}

function renderResourceGroups(resources) {
    const list = $('resource-list');
    const available = resources.filter(r => r.available);
    const unavailable = resources.filter(r => !r.available);

    if (!available.length && !unavailable.length) {
        list.innerHTML = emptyStateHtml({
            iconName: 'doc',
            title: 'Belum ada data klinis',
            body: 'Tidak ada resource yang tersedia untuk kunjungan ini.',
        });
        updateSendSummary();
        return;
    }

    const groups = buildResourceGroups(available);
    list.innerHTML = groups.map(g => `
        <section class="res-group ${g.open ? 'open' : ''}" style="--cat-color:${g.color}" data-group="${escapeHtml(g.id)}">
            <button class="res-group-head" type="button" aria-expanded="${g.open}" aria-controls="rg-${escapeHtml(g.id)}">
                <span class="g-dot" aria-hidden="true"></span>
                <span class="g-title">${escapeHtml(g.title)}</span>
                <span class="g-count">${g.items.length}</span>
                ${icon('chevron', 'icon g-chevron')}
            </button>
            <div class="res-grid" id="rg-${escapeHtml(g.id)}" ${g.open ? '' : 'hidden'}>
                ${g.items.map(resourceRow).join('')}
            </div>
        </section>`).join('');

    if (unavailable.length) {
        const el = document.createElement('div');
        el.className = 'empty-state small';
        el.style.padding = 'var(--space-3)';
        el.textContent = `${unavailable.length} resource tidak tersedia (tidak ada data klinis)`;
        list.appendChild(el);
    }

    list.querySelectorAll('.res-group-head').forEach(head => {
        head.addEventListener('click', () => toggleGroup(head.closest('.res-group')));
    });
    list.querySelectorAll('.resource-check').forEach(cb => cb.addEventListener('change', updateSendSummary));
    list.querySelectorAll('.resource-preview').forEach(btn => {
        btn.addEventListener('click', () => openPayloadEditor(btn.dataset.resource));
    });
    updateSendSummary();
}

function toggleGroup(groupEl) {
    const id = groupEl.dataset.group;
    const open = !groupEl.classList.contains('open');
    state.activeGroup = open ? id : null;
    groupEl.classList.toggle('open', open);
    groupEl.querySelector('.res-group-head').setAttribute('aria-expanded', String(open));
    groupEl.querySelector('.res-grid').hidden = !open;
}

export function updateSendSummary() {
    const checked = [...document.querySelectorAll('.resource-check:checked')].map(cb => cb.dataset.resource);
    const summary = $('send-summary');
    const btn = $('btn-send');
    disarmSend(); // selection changed — require re-confirmation
    if (!checked.length) {
        summary.textContent = 'Belum ada resource dipilih';
        btn.disabled = true;
    } else {
        summary.innerHTML = `${checked.length} resource akan dikirim via <strong>Bundle transaction</strong>`;
        btn.disabled = false;
    }
}

/* ── Send bundle ──────────────────────────────────────────── */
async function sendBundle() {
    if (!state.detailNoRawat) return;
    // Two-step confirmation: first click arms, second click sends.
    if (!sendArmed) { armSend(); return; }
    disarmSend();
    const checked = [...document.querySelectorAll('.resource-check:checked')].map(cb => cb.dataset.resource);
    if (!checked.length) return;
    const btn = $('btn-send');
    const label = btn.querySelector('.btn-label');
    const spinner = btn.querySelector('.spinner');
    btn.disabled = true;
    btn.classList.add('loading');
    spinner.hidden = false;
    label.textContent = 'Mengirim...';
    try {
        const raw = state.detailNoRawat.replace(/\//g, '%2F');
        const customPayloads = state.selected[state.detailNoRawat] || {};
        const res = await api(`/api/patients/${raw}/send`, {
            method: 'POST',
            body: JSON.stringify({ resources: checked, custom_payloads: customPayloads }),
        });
        if (res.success) {
            toast('Bundle terkirim', `${res.sent_count} resource dikirim`, 'success');
            bus.emit('patients:reload');
            await openDrawer(state.detailNoRawat);
        } else {
            const errMsg = res.response ? extractError(res.response) : (res.message || 'Gagal mengirim bundle');
            toast('Bundle gagal', errMsg, 'error');
        }
    } catch (e) {
        toast('Error', e.message, 'error');
    } finally {
        btn.disabled = false;
        btn.classList.remove('loading');
        spinner.hidden = true;
        label.textContent = 'Kirim via Bundle';
        updateSendSummary();
    }
}
