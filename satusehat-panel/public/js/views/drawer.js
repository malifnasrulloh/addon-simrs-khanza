/* ============================================================
   Drawer - patient detail, grouped FHIR resources, bundle send
   ============================================================ */

'use strict';

import { api, extractError } from '../api.js';
import { $, escapeHtml, toast, rememberFocus, restoreFocus, trapFocus, untrapFocus, emptyStateHtml, icon, initials, hashHue } from '../ui.js';
import { state, bus, patientStatus } from '../state.js';
import { openPayloadEditor } from '../payload.js';

const RESOURCE_LABELS = {
    Encounter: 'Kunjungan Pasien',
    EpisodeOfCare: 'Program Kesehatan (EoC)',
    Condition: 'Diagnosa & Keluhan Utama',
    Procedure: 'Tindakan / Prosedur Medis',
    AllergyIntolerance: 'Alergi & Intoleransi',
    Observation: 'Observasi Klinis / TTV / Lab / Rad',
    DiagnosticReport: 'Laporan Diagnostik Lab / Rad',
    ServiceRequest: 'Permintaan Pemeriksaan Lab / Rad',
    Specimen: 'Spesimen Pemeriksaan',
    Medication: 'Master Data Obat (KFA)',
    MedicationRequest: 'Resep Obat (Dokter)',
    MedicationDispense: 'Pelayanan / Dispense Obat',
    MedicationStatement: 'Pernyataan Penggunaan Obat',
    Immunization: 'Imunisasi / Vaksinasi',
    CarePlan: 'Rencana Perawatan (CarePlan)',
    ClinicalImpression: 'Penilaian Klinis (Triase)',
    Composition: 'Ringkasan Pulang (Discharge)',
    QuestionnaireResponse: 'Telaah Resep Farmasi',
    ImagingStudy: 'Pemeriksaan Radiologi (DICOM)',
};

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

    const cleanRawat = String(noRawat || '').trim();
    state.detailNoRawat = cleanRawat;
    const drawerEl = $('drawer');
    if (drawerEl) {
        drawerEl.setAttribute('data-no-rawat', cleanRawat);
    }

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

    const raw = cleanRawat.replace(/\//g, '%2F');
    try {
        const data = await api(`/api/patients/${raw}`, { signal: drawerAbort.signal });
        if (drawerAbort !== seq) return; // stale response — a newer drawer opened
        if (!data || !data.data || !data.data.patient) {
            throw new Error(data?.error || 'Data detail pasien tidak ditemukan.');
        }
        state.detail = data.data;
        if (data.data.patient && data.data.patient.no_rawat) {
            state.detailNoRawat = String(data.data.patient.no_rawat).trim();
            if (drawerEl) drawerEl.setAttribute('data-no-rawat', state.detailNoRawat);
        }
        renderSummary(state.detail.patient);
        renderResourceList(state.detail.resources || []);
        renderOverrideBanner();
    } catch (e) {
        if (e.name === 'AbortError') return; // superseded by a newer request
        $('patient-summary').innerHTML = emptyStateHtml({
            iconName: 'alert',
            title: 'Gagal memuat detail',
            body: e.message,
            actionHtml: `<button class="btn btn-ghost btn-sm" type="button" id="drawer-retry">Coba lagi</button>`,
        });
        $('drawer-retry').addEventListener('click', () => openDrawer(cleanRawat));
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
    av.style.setProperty('--av-hue', hashHue(p.no_rkm_medis));
    av.textContent = initials(p.nm_pasien);
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

/* ── Flat Resource List ───────────────────────────────────── */
function resourceRow(r, allResources = []) {
    const sent = !!r.sent;
    const available = !!r.available;
    const checked = available && !sent ? 'checked' : '';
    const labelIndo = RESOURCE_LABELS[r.type] || r.type;
    const statusLabel = sent ? 'Sudah terkirim' : (available ? 'Siap kirim' : 'Tidak ada data');
    const badgeClass = sent ? 'badge-ok' : (available ? 'badge-ready' : 'badge-neutral');

    let depTip = '';
    if (!sent && available && r.depends_on && r.depends_on.length) {
        const unsentDeps = r.depends_on.filter(d => {
            const match = allResources.find(x => x.type === d);
            return !match || !match.sent;
        });
        if (unsentDeps.length) {
            const depNames = unsentDeps.map(d => RESOURCE_LABELS[d] || d).join(' & ');
            depTip = `<span class="blocker-tip mono muted" style="font-size:var(--text-xs);margin-top:2px">🔗 Prasyarat: ${escapeHtml(depNames)}</span>`;
        }
    }

    return `<div class="resource-item${sent ? ' is-sent' : ''}${!available ? ' is-empty' : ''}" data-type="${escapeHtml(r.type)}">
        <input type="checkbox" class="resource-check" data-resource="${escapeHtml(r.type)}" ${checked} ${(!available || sent) ? 'disabled' : ''} aria-label="Pilih ${escapeHtml(r.type)}">
        <div class="resource-info">
            <div class="resource-type-row">
                <strong class="resource-type">${escapeHtml(r.type)}</strong>
                <span class="resource-label-id">${escapeHtml(labelIndo)}</span>
            </div>
            <div class="resource-status-row">
                <span class="badge ${badgeClass}"><span class="dot" aria-hidden="true"></span>${statusLabel}</span>
                ${depTip}
            </div>
        </div>
        <button class="btn btn-ghost btn-sm resource-preview" type="button" data-resource="${escapeHtml(r.type)}" title="Lihat / edit payload JSON" ${available ? '' : 'disabled'}>
            ${icon('eye')} <span class="small">JSON</span>
        </button>
    </div>`;
}

function onResourceCheckChange(e) {
    const cb = e.target;
    const type = cb.dataset.resource;
    const isChecked = cb.checked;
    const allResources = state.detail?.resources || [];

    if (isChecked) {
        // Cascade check: automatically select prerequisite resources if not already sent
        const r = allResources.find(x => x.type === type);
        const deps = r?.depends_on || [];
        const autoChecked = [];

        deps.forEach(depType => {
            const depR = allResources.find(x => x.type === depType);
            if (depR && !depR.sent) {
                const depCb = document.querySelector(`.resource-check[data-resource="${depType}"]`);
                if (depCb && !depCb.disabled && !depCb.checked) {
                    depCb.checked = true;
                    autoChecked.push(RESOURCE_LABELS[depType] || depType);
                }
            }
        });

        if (autoChecked.length) {
            toast('Prasyarat Dipilih', `${autoChecked.join(', ')} otomatis disertakan dalam bundle`, 'info');
        }
    } else {
        // Cascade uncheck: uncheck child resources that require this prerequisite
        const autoUnchecked = [];
        allResources.forEach(otherR => {
            const deps = otherR.depends_on || [];
            if (deps.includes(type) && !otherR.sent) {
                const otherCb = document.querySelector(`.resource-check[data-resource="${otherR.type}"]`);
                if (otherCb && otherCb.checked) {
                    otherCb.checked = false;
                    autoUnchecked.push(RESOURCE_LABELS[otherR.type] || otherR.type);
                }
            }
        });

        if (autoUnchecked.length) {
            toast('Prasyarat Dibatalkan', `${autoUnchecked.join(', ')} dibatalkan karena memerlukan ${RESOURCE_LABELS[type] || type}`, 'warning');
        }
    }

    updateSendSummary();
}

function renderResourceList(resources) {
    const list = $('resource-list');
    // Active resources = available in SIMRS OR already sent to SATUSEHAT
    const active = resources.filter(r => r.available || r.sent);
    // Truly unavailable = no data in SIMRS AND never sent
    const unavailable = resources.filter(r => !r.available && !r.sent);

    if (!active.length && !unavailable.length) {
        list.innerHTML = emptyStateHtml({
            iconName: 'doc',
            title: 'Belum ada data klinis',
            body: 'Tidak ada resource yang tersedia untuk kunjungan ini.',
        });
        updateSendSummary();
        return;
    }

    const readyCount = active.filter(r => r.available && !r.sent).length;
    const sentCount = active.filter(r => r.sent).length;

    let html = `
        <div class="res-flat-container">
            <div class="res-flat-summary-bar">
                <span class="small font-medium">${active.length} resource kunjungan ini</span>
                <span class="small muted">${sentCount} terkirim · ${readyCount} siap kirim</span>
            </div>
            <div class="res-flat-list">
                ${active.map(r => resourceRow(r, resources)).join('')}
            </div>
    `;

    if (unavailable.length) {
        html += `
            <details class="res-unavailable-details">
                <summary class="small muted">
                    <span>${unavailable.length} resource tidak memiliki data klinis di kunjungan ini</span>
                </summary>
                <div class="res-flat-list unavailable-list">
                    ${unavailable.map(r => resourceRow(r, resources)).join('')}
                </div>
            </details>
        `;
    }

    html += `</div>`;
    list.innerHTML = html;

    list.querySelectorAll('.resource-check').forEach(cb => cb.addEventListener('change', onResourceCheckChange));
    list.querySelectorAll('.resource-preview').forEach(btn => {
        btn.addEventListener('click', () => openPayloadEditor(btn.dataset.resource));
    });
    updateSendSummary();
}

export function updateSendSummary() {
    const checked = [...document.querySelectorAll('.resource-check:checked')].map(cb => cb.dataset.resource);
    const summary = $('send-summary');
    const btn = $('btn-send');

    if (!checked.length) {
        summary.textContent = 'Belum ada resource dipilih';
        btn.disabled = true;
        return;
    }

    // Dependency verification: ensure all prerequisites of checked items are satisfied
    const allResources = state.detail?.resources || [];
    const missing = [];

    checked.forEach(type => {
        const r = allResources.find(x => x.type === type);
        const deps = r?.depends_on || [];
        deps.forEach(dep => {
            const depR = allResources.find(x => x.type === dep);
            const isSent = !!depR?.sent;
            const isChecked = checked.includes(dep);
            if (!isSent && !isChecked) {
                missing.push(`${type} butuh ${dep}`);
            }
        });
    });

    if (missing.length) {
        summary.innerHTML = `<span style="color:var(--danger);font-weight:600">⚠️ Prasyarat belum dipilih: ${escapeHtml(missing.join(', '))}</span>`;
        btn.disabled = true;
    } else {
        summary.innerHTML = `${checked.length} resource akan dikirim via <strong>Bundle transaction</strong>`;
        btn.disabled = false;
    }
}

/* ── Send bundle ──────────────────────────────────────────── */
async function sendBundle() {
    const drawerEl = $('drawer');
    const noRawat = (state.detailNoRawat || drawerEl?.getAttribute('data-no-rawat') || state.detail?.patient?.no_rawat || '').trim();
    if (!noRawat) {
        toast('Peringatan', 'Nomor rawat pasien tidak ditemukan. Buka ulang detail pasien.', 'warning');
        return;
    }

    const checked = [...document.querySelectorAll('.resource-check:checked')].map(cb => cb.dataset.resource);
    if (!checked.length) {
        toast('Peringatan', 'Pilih minimal satu resource untuk dikirim.', 'warning');
        return;
    }

    const btn = $('btn-send');
    const label = btn.querySelector('.btn-label');
    const spinner = btn.querySelector('.spinner');

    btn.disabled = true;
    btn.classList.add('loading');
    if (spinner) spinner.hidden = false;
    if (label) label.textContent = 'Mengirim...';

    try {
        const raw = noRawat.replace(/\//g, '%2F');
        const customPayloads = state.selected[noRawat] || {};
        toast('Mengirim Bundle...', `Mengirim ${checked.length} resource via Bundle transaksi`, 'info');

        const res = await api(`/api/patients/${raw}/send`, {
            method: 'POST',
            body: JSON.stringify({ resources: checked, custom_payloads: customPayloads }),
        });

        if (res.success) {
            toast('Bundle Terkirim', `${res.sent_count || checked.length} resource berhasil dikirim`, 'success');
            bus.emit('patients:reload');
            await openDrawer(noRawat);
        } else {
            const errMsg = res.response ? extractError(res.response) : (res.error || res.message || 'Gagal mengirim bundle');
            toast('Bundle Gagal', errMsg, 'error');
        }
    } catch (e) {
        toast('Error', e.message, 'error');
    } finally {
        btn.disabled = false;
        btn.classList.remove('loading');
        if (spinner) spinner.hidden = true;
        if (label) label.textContent = 'Kirim via Bundle';
        updateSendSummary();
    }
}
