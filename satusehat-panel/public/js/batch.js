/* ============================================================
   Batch send - multi-patient sequential bundle transmission
   ============================================================ */

'use strict';

import { api, extractError } from './api.js';
import { $, escapeHtml, toast, rememberFocus, restoreFocus, trapFocus, untrapFocus } from './ui.js';
import { state, bus } from './state.js';
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
        else hideBatchModal(); // finished run: the button becomes 'Tutup'
    });
    $('batch-close').addEventListener('click', () => { if (!batch.running) hideBatchModal(); });
    $('batch-backdrop').addEventListener('click', (e) => {
        if (e.target.id === 'batch-backdrop' && !batch.running) hideBatchModal();
    });
    $('batch-start').addEventListener('click', startBatchRun);
    $('batch-retry').addEventListener('click', () => {
        if (batch.running) return;
        const failed = batch.queue.filter(item => (batch.results[item.noRawat]?.status) === 'fail' || (batch.results[item.noRawat]?.status) === 'partial');
        if (!failed.length) return;
        // Keep the outcomes of the first run — the final summary then covers
        // the whole session, not just the retried subset.
        batch.queue = failed;
        failed.forEach(item => { if (batch.results[item.noRawat]) delete batch.results[item.noRawat]; });
        batch.canceled = false;
        batch.running = true;
        state.batchRunning = true;
        $('batch-retry').hidden = true;
        $('batch-foot-error').textContent = '';
        $('batch-subtitle').textContent = `${failed.length} pasien gagal — dicoba ulang`;
        renderBatchList();
        runBatch();
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
    // Confirm-before-send: the modal opens armed but idle — the run starts
    // only when the user presses "Mulai kirim".
    batch.running = false;
    state.batchRunning = false;
    renderBatchList();
    $('batch-subtitle').textContent = `${batch.queue.length} pasien dipilih — kirim berurutan`;
    $('batch-progress-text').textContent = 'Menunggu konfirmasi untuk memulai...';
    $('batch-foot-error').textContent = '';
    if ($('batch-retry')) $('batch-retry').hidden = true;
    $('batch-start').hidden = false;
    $('batch-cancel').disabled = false;
    $('batch-cancel').textContent = 'Batal';
    $('batch-modal').hidden = false;
    $('batch-backdrop').hidden = false;
    rememberFocus();
    trapFocus($('batch-modal'));
    $('batch-start').focus();
}

function startBatchRun() {
    if (batch.running) return;
    batch.canceled = false;
    batch.running = true;
    state.batchRunning = true;
    $('batch-start').hidden = true;
    $('batch-cancel').disabled = false;
    $('batch-cancel').textContent = 'Batalkan';
    $('batch-progress-text').textContent = 'Mengirim...';
    renderBatchList();
    runBatch();
}

async function runBatch() {
    try {
        for (let i = 0; i < batch.queue.length; i++) {
            if (batch.canceled) break;
            const item = batch.queue[i];
            batch.results[item.noRawat] = { status: 'sending' };
            renderBatchList();
            // Inter-request delay: avoid hammering SATUSEHAT during a run.
            if (i > 0) await sleep(500);
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
                const res = await api(`/api/patients/${raw}/send`, {
                    method: 'POST',
                    body: JSON.stringify({
                        resources,
                        // Payload-editor overrides per patient must survive a
                        // batch send — drawer sends honor them, batch didn't.
                        custom_payloads: state.selected[item.noRawat] || {},
                    }),
                });
                if (res.success) {
                    batch.results[item.noRawat] = { status: 'ok', detail: res.sent_count ? `${res.sent_count} resource` : 'terkirim' };
                } else if (res.entry_status === 'partial') {
                    // HTTP-level ok but some entries rejected — retryable.
                    batch.results[item.noRawat] = { status: 'partial', detail: `${res.failed_count || 0} entri gagal — kirim ulang hanya mencoba entri yang gagal` };
                } else {
                    batch.results[item.noRawat] = { status: 'fail', detail: res.response ? extractError(res.response) : (res.message || 'Gagal mengirim') };
                }
            } catch (e) {
                batch.results[item.noRawat] = { status: 'fail', detail: e.message };
            }
            renderBatchList();
        }
    } catch (e) {
        // Surface run-level failures instead of leaving the modal hanging.
        $('batch-foot-error').textContent = e.message || 'Terjadi kesalahan tak terduga';
    }
    const values = Object.values(batch.results);
    const finished = values.filter(r => r.status === 'ok').length;
    const failed = values.filter(r => r.status === 'fail' || r.status === 'partial').length;
    const skipped = values.filter(r => r.status === 'skip').length;
    const retry = $('batch-retry');
    if (retry) retry.hidden = failed === 0;
    const seg = [`${finished} berhasil`, `${failed} gagal`];
    if (skipped) seg.push(`${skipped} dilewati`);
    $('batch-progress-text').textContent = `${batch.canceled ? 'Dibatalkan' : 'Selesai'}: ${seg.join(', ')}`;
    // 'Tutup' stays enabled — the click handler closes the modal when idle.
    $('batch-cancel').textContent = 'Tutup';
    batch.running = false;
    state.batchRunning = false;
    updateBatchBar();
    bus.emit('patients:reload');
}

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

function renderBatchList() {
    const list = $('batch-list');
    if (!list) return;
    const STATUS_META = {
        pending: ['Antri', ''],
        sending: ['Mengirim...', 'sending'],
        ok: ['Terkirim', 'ok'],
        fail: ['Gagal', 'fail'],
        partial: ['Sebagian gagal', 'fail'],
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
    resetBatchRun();
    untrapFocus($('batch-modal'));
    restoreFocus();
}

/** Force the run state back to idle (logout, session expiry, reopen). */
export function resetBatchRun() {
    batch.running = false;
    batch.canceled = false;
    state.batchRunning = false;
}
