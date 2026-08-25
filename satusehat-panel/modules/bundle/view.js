/* ============================================================
   Module View: Bundle Transaction Dispatch
   ============================================================ */

import { $, escapeHtml, toast } from '../../js/ui.js';
import { api } from '../../js/api.js';
import { openDrawer } from '../../js/views/drawer.js';

let currentContainer = null;
let currentOptions = null;
let patients = [];

export async function mount(container, options) {
    currentContainer = container;
    currentOptions = options;
    await loadData();
}

export async function reload(filters) {
    if (currentOptions) {
        currentOptions.filters = filters;
        await loadData();
    }
}

async function loadData() {
    if (!currentContainer) return;
    currentContainer.innerHTML = `<div class="empty-state"><span class="spinner"></span> Memuat daftar pasien...</div>`;

    const f = currentOptions.filters || {};
    const qs = new URLSearchParams({
        since: f.since || '',
        until: f.until || '',
        status_bayar: f.status_bayar || 'all',
        status_sync: f.status_sync || 'all',
        search: f.search || '',
        page: String(f.page || 1),
    });

    try {
        const res = await api(`/api/modules/bundle/list?${qs.toString()}`);
        patients = res.data || [];
        renderTable(patients, res.meta || {});
    } catch (e) {
        currentContainer.innerHTML = `<div class="empty-state">Gagal memuat data: ${escapeHtml(e.message)}</div>`;
    }
}

function renderTable(list, meta) {
    if (!list.length) {
        currentContainer.innerHTML = `<div class="empty-state">Tidak ada pasien yang sesuai filter.</div>`;
        return;
    }

    currentContainer.innerHTML = `
        <table class="patient-table">
            <thead>
                <tr>
                    <th style="width:40px"><input type="checkbox" id="mod-check-all"></th>
                    <th>Pasien</th>
                    <th>No. Rawat</th>
                    <th>Tanggal</th>
                    <th>Kunjungan</th>
                    <th>Billing</th>
                    <th>Status Resource</th>
                    <th style="width:100px;text-align:right">Aksi</th>
                </tr>
            </thead>
            <tbody>
                ${list.map(p => {
                    const isPaid = (p.status_bayar || '').toLowerCase().includes('sudah');
                    return `
                        <tr data-no-rawat="${escapeHtml(p.no_rawat)}">
                            <td><input type="checkbox" class="row-check" value="${escapeHtml(p.no_rawat)}"></td>
                            <td>
                                <strong>${escapeHtml(p.nm_pasien || '-')}</strong>
                                <span class="blocker-tip mono">RM: ${escapeHtml(p.no_rkm_medis || '-')} · NIK: ${escapeHtml(p.no_ktp || '-')}</span>
                            </td>
                            <td class="mono small">${escapeHtml(p.no_rawat)}</td>
                            <td class="small">${escapeHtml(p.tgl_registrasi || '-')}</td>
                            <td><span class="badge badge-neutral">${escapeHtml(p.status_lanjut || '-')}</span></td>
                            <td><span class="badge ${isPaid ? 'badge-success' : 'badge-danger'}"><span class="dot"></span>${escapeHtml(p.status_bayar || '-')}</span></td>
                            <td>
                                <span class="badge ${p.resource_counts?.ready > 0 ? 'badge-warning' : 'badge-neutral'}">
                                    ${p.resource_counts?.sent || 0} terkirim / ${p.resource_counts?.ready || 0} siap
                                </span>
                            </td>
                            <td style="text-align:right">
                                <button class="btn btn-primary btn-sm btn-open-drawer" type="button" data-rawat="${escapeHtml(p.no_rawat)}">
                                    Buka Bundle &rarr;
                                </button>
                            </td>
                        </tr>
                    `;
                }).join('')}
            </tbody>
        </table>
    `;

    // Bind checkbox & button events
    const checkAll = currentContainer.querySelector('#mod-check-all');
    const rowChecks = currentContainer.querySelectorAll('.row-check');
    const selected = new Set();

    if (checkAll) {
        checkAll.addEventListener('change', () => {
            rowChecks.forEach(cb => {
                cb.checked = checkAll.checked;
                if (checkAll.checked) selected.add(cb.value);
                else selected.delete(cb.value);
            });
            if (currentOptions?.onSelectChange) currentOptions.onSelectChange(selected);
        });
    }

    rowChecks.forEach(cb => {
        cb.addEventListener('change', () => {
            if (cb.checked) selected.add(cb.value);
            else selected.delete(cb.value);
            if (currentOptions?.onSelectChange) currentOptions.onSelectChange(selected);
        });
    });

    currentContainer.querySelectorAll('.btn-open-drawer').forEach(btn => {
        btn.addEventListener('click', () => {
            openDrawer(btn.dataset.rawat);
        });
    });
}
