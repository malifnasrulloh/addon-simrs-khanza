/* ============================================================
   Module View: QuestionnaireResponse (Telaah Resep Farmasi)
   ============================================================ */

import { $, escapeHtml, toast } from '../../js/ui.js';
import { api } from '../../js/api.js';

let currentContainer = null;
let currentOptions = null;
let items = [];

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
    currentContainer.innerHTML = `<div class="empty-state"><span class="spinner"></span> Memuat data Telaah Resep...</div>`;

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
        const res = await api(`/api/modules/questionnaire_response/list?${qs.toString()}`);
        items = res.data || [];
        renderTable(items);
    } catch (e) {
        currentContainer.innerHTML = `<div class="empty-state">Gagal memuat data: ${escapeHtml(e.message)}</div>`;
    }
}

function renderTable(list) {
    if (!list.length) {
        currentContainer.innerHTML = `<div class="empty-state">Tidak ada telaah resep yang sesuai filter.</div>`;
        return;
    }

    currentContainer.innerHTML = `
        <table class="patient-table">
            <thead>
                <tr>
                    <th style="width:40px"><input type="checkbox" id="mod-check-all"></th>
                    <th>Pasien & No. Rawat</th>
                    <th>No. Resep</th>
                    <th>Waktu Peresepan</th>
                    <th>Dokter Peresep</th>
                    <th>Status SATUSEHAT</th>
                    <th style="width:160px;text-align:right">Aksi</th>
                </tr>
            </thead>
            <tbody>
                ${list.map(r => {
                    const st = r.status_info || {};
                    const keyStr = `${r.no_rawat}|${r.no_resep}`;
                    const canSend = st.status === 'ready' || st.status === 'failed';
                    return `
                        <tr data-key="${escapeHtml(keyStr)}">
                            <td><input type="checkbox" class="row-check" value="${escapeHtml(JSON.stringify(r.item_key))}" ${canSend ? '' : 'disabled'}></td>
                            <td>
                                <strong>${escapeHtml(r.nm_pasien || '-')}</strong>
                                <span class="blocker-tip mono">No. Rawat: ${escapeHtml(r.no_rawat)} · RM: ${escapeHtml(r.no_rkm_medis || '-')}</span>
                            </td>
                            <td>
                                <strong>${escapeHtml(r.no_resep)}</strong>
                                <span class="blocker-tip">${escapeHtml(r.status_lanjut || 'Ralan')}</span>
                            </td>
                            <td class="small">${escapeHtml(r.tgl_peresepan || '-')} <span class="muted">${escapeHtml(r.jam_peresepan || '')}</span></td>
                            <td><span class="small">${escapeHtml(r.nm_dokter || '-')}</span></td>
                            <td>
                                <span class="badge ${st.badge || 'badge-neutral'}">
                                    <span class="dot"></span>${escapeHtml(st.label || '-')}
                                </span>
                                ${st.blocker_reason ? `<span class="blocker-tip" style="color:var(--danger)">⚠️ ${escapeHtml(st.blocker_reason)}</span>` : ''}
                                ${st.satusehat_id ? `<span class="blocker-tip mono muted">ID: ${escapeHtml(st.satusehat_id)}</span>` : ''}
                            </td>
                            <td style="text-align:right">
                                <div style="display:inline-flex;gap:4px">
                                    <button class="btn btn-ghost btn-sm btn-inspect" type="button" data-key="${escapeHtml(keyStr)}" title="Lihat JSON / Respon">
                                        JSON
                                    </button>
                                    <button class="btn btn-primary btn-sm btn-send-single" type="button" data-key="${escapeHtml(JSON.stringify(r.item_key))}" data-keystr="${escapeHtml(keyStr)}" ${canSend ? '' : 'disabled'}>
                                        Kirim
                                    </button>
                                </div>
                            </td>
                        </tr>
                    `;
                }).join('')}
            </tbody>
        </table>
    `;

    // Bind checkboxes & actions
    const checkAll = currentContainer.querySelector('#mod-check-all');
    const rowChecks = currentContainer.querySelectorAll('.row-check');
    const selected = new Set();

    if (checkAll) {
        checkAll.addEventListener('change', () => {
            rowChecks.forEach(cb => {
                if (!cb.disabled) {
                    cb.checked = checkAll.checked;
                    if (checkAll.checked) selected.add(cb.value);
                    else selected.delete(cb.value);
                }
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

    currentContainer.querySelectorAll('.btn-inspect').forEach(btn => {
        btn.addEventListener('click', async () => {
            const keyStr = btn.dataset.key;
            const r = list.find(x => `${x.no_rawat}|${x.no_resep}` === keyStr) || {};
            try {
                const prev = await api(`/api/modules/questionnaire_response/preview/${encodeURIComponent(keyStr)}`);
                if (currentOptions?.onInspect) {
                    currentOptions.onInspect({
                        title: `QuestionnaireResponse (Resep ${r.no_resep || ''})`,
                        subtitle: `${r.nm_pasien || ''} · ${r.no_rawat || ''}`,
                        status: r.status_info?.status,
                        status_label: r.status_info?.label,
                        satusehat_id: r.status_info?.satusehat_id,
                        blocker_reason: r.status_info?.blocker_reason,
                        request_json: prev.data,
                        response_json: null,
                    });
                }
            } catch (e) {
                toast('Gagal memuat preview', e.message, 'error');
            }
        });
    });

    currentContainer.querySelectorAll('.btn-send-single').forEach(btn => {
        btn.addEventListener('click', async () => {
            const itemKey = JSON.parse(btn.dataset.key);
            const keyStr = btn.dataset.keystr;
            btn.disabled = true;
            try {
                toast('Mengirim...', `Mengirim Telaah Resep ${itemKey.no_resep}`, 'info');
                const res = await api('/api/modules/questionnaire_response/send', {
                    method: 'POST',
                    body: JSON.stringify({ items: [itemKey] })
                });
                if (res.success && res.results?.[keyStr]?.satusehat_id) {
                    toast('Berhasil!', `QuestionnaireResponse terkirim. ID: ${res.results[keyStr].satusehat_id}`, 'success');
                    await loadData();
                } else {
                    const err = res.results?.[keyStr]?.issue_text || res.error || 'Pengiriman gagal';
                    toast('Gagal', err, 'error');
                }
            } catch (e) {
                toast('Error', e.message, 'error');
            } finally {
                btn.disabled = false;
            }
        });
    });
}
