/* ============================================================
   Batch send - multi-patient sequential bundle transmission
   ============================================================ */

'use strict';

import { api, extractError } from './api.js';
import { $, escapeHtml, toast, rememberFocus, restoreFocus, trapFocus } from './ui.js';
import { state, bus, countReadyResources } from './state.js';
import { updateBatchBar } from './views/patients.js';

const batch = {
    queue: [],
    results: {},
    canceled: false,
    running: false,
};

export function initBatchView() {
    $('btn-clear-batch').addEventListener('click', () => {
        if (state.batchRunning) return;
        openBatchModal();
    });
    $('batch-cancel').addEventListener('click', () => {
        if (batch.running) { batch.canceled = true; $('batch-cancel').disabled = true; }
        else hideBatchModal();
    });
    $('batch-close').addEventListener('click', () => { if (!batch.running) hideBatchModal(); });
    $('batch-backdrop').addEventListener('click', (e) => {
        if (e.target.id === 'batch-backdrop' && !batch.running) hideBatchModal();
    });
}

function openBatchModal() {
    const selected = [...state.batchSel];
    if (!selected.length) return;
    batch.queue = selected.map(noRawat => {
        const p = state.patients.find(x => x.no_rawat === noRawat);
        return { noRawat, name: p ? p.nm_pasien : noRawat };
    });
    batch.results = {};
    batch.canceled = false;
    batch.running = true;
    state.batchRunning = true;
    renderBatchList();
    $('batch-subtitle').textContent = `${batch.queue.length} pasien dipilih - dikirim berurutan`;
    $('batch-progress-text').textContent = 'Menunggu mulai...';
    $('batch-foot-error').textContent = '';
    $('batch-cancel').disabled = false;
    $('batch-cancel').textContent = 'Batalkan';
    $('batch-modal').hidden = false;
    $('batch-backdrop').hidden = false;
    rememberFocus();
    trapFocus($('batch-modal'));
    runBatch();
}

async function runBatch() {
    for (let i = 0; i < batch.queue.length; i++) {
        if (batch.canceled) break;
        const item = batch.queue[i];
        batch.results[item.noRawat] = { status: 'sending' };
        renderBatchList();
        try {
            // Fetch patient detail to get accurate available/sent resource list
            const raw = item.noRawat.replace(/\//g, '%2F');
            let detail;
            try {
                detail = await api(`/api/patients/${raw}`);
            } catch (e) {
                batch.results[item.noRawat] = { status: 'fail', detail: 'Gagal memuat detail: ' + e.message };
                renderBatchList();
                continue;
            }

            const allResources = detail?.data?.resources || [];
            // Only send resources that are available but NOT already sent
            const resources = allResources
                .filter(r => r.available && !r.sent)
                .map(r => r.type);

            if (!resources.length) {
                batch.results[item.noRawat] = { status: 'skip', detail: 'Tidak ada data baru untuk dikirim' };
                renderBatchList();
                continue;
            }
            const res = await api(`/api/patients/${raw}/send`, { method: 'POST', body: JSON.stringify({ resources }) });
            batch.results[item.noRawat] = res.success
                ? { status: 'ok', detail: res.sent_count ? `${res.sent_count} resource` : 'terkirim' }
                : { status: 'fail', detail: res.response ? extractError(res.response) : (res.message || 'Gagal mengirim') };
        } catch (e) {
            batch.results[item.noRawat] = { status: 'fail', detail: e.message };
        }
        renderBatchList();
    }
    const values = Object.values(batch.results);
    const finished = values.filter(r => r.status === 'ok').length;
    const failed = values.filter(r => r.status === 'fail').length;
    const skipped = values.filter(r => r.status === 'skip').length;
    const seg = [`${finished} berhasil`, `${failed} gagal`];
    if (skipped) seg.push(`${skipped} dilewati`);
    $('batch-progress-text').textContent = `${batch.canceled ? 'Dibatalkan' : 'Selesai'}: ${seg.join(', ')}`;
    $('batch-cancel').disabled = true;
    $('batch-cancel').textContent = 'Tutup';
    batch.running = false;
    state.batchRunning = false;
    updateBatchBar();
    bus.emit('patients:reload');
}

function renderBatchList() {
    const list = $('batch-list');
    if (!list) return;
    const STATUS_META = {
        pending: ['Antri', ''],
        sending: ['Mengirim...', 'sending'],
        ok: ['Terkirim', 'ok'],
        fail: ['Gagal', 'fail'],
        skip: ['Dilewati', 'skip'],
    };
    list.innerHTML = batch.queue.map(item => {
        const r = batch.results[item.noRawat] || { status: 'pending' };
        const [label, cls] = STATUS_META[r.status] || STATUS_META.pending;
        return `<div class="batch-item ${r.status}">
            <span class="batch-dot" aria-hidden="true"></span>
            <div class="batch-name">${escapeHtml(item.name)}<div class="batch-rawat">${escapeHtml(item.noRawat)}</div></div>
            <div class="batch-status ${cls}">${label}${r.detail ? ` <span class="batch-detail">- ${escapeHtml(r.detail)}</span>` : ''}</div>
        </div>`;
    }).join('');
}

export function hideBatchModal() {
    $('batch-modal').hidden = true;
    $('batch-backdrop').hidden = true;
    restoreFocus();
}
