/* ============================================================
   Audit view - send history timeline + summary + date filter
   ============================================================ */

'use strict';

import { api } from '../api.js';
import { $, escapeHtml, debounce, emptyStateHtml } from '../ui.js';
import { state, dayLabel, timeLabel } from '../state.js';

const auditReload = debounce(loadAudit, 300);

export function initAuditView() {
    $('btn-audit').addEventListener('click', (e) => { e.preventDefault(); location.hash = '#/audit'; });
    $('btn-audit-back').addEventListener('click', () => { location.hash = '#/'; });
    $('audit-since').addEventListener('input', auditReload);
    $('audit-until').addEventListener('input', auditReload);
    $('audit-status').addEventListener('change', renderTimeline);
}

export function showAuditView() {
    $('table-wrap').hidden = true;
    $('audit-view').hidden = false;
    $('settings-view').hidden = true;
    loadAudit();
}

export async function loadAudit() {
    const list = $('audit-list');
    list.innerHTML = '<div class="empty-state">Memuat audit log...</div>';
    $('audit-summary').hidden = true;

    const qs = new URLSearchParams({ limit: '500' });
    const since = $('audit-since').value;
    const until = $('audit-until').value;
    if (since) qs.set('since', since);
    if (until) qs.set('until', until);

    try {
        const data = await api(`/api/audit?${qs}`);
        state.audit = data.data || [];
        if (!state.audit.length) {
            list.innerHTML = emptyStateHtml({
                iconName: 'clock',
                title: 'Belum ada aktivitas',
                body: 'Log pengiriman bundle akan muncul di sini.',
            });
            return;
        }
        renderSummary();
        renderTimeline();
    } catch (e) {
        list.innerHTML = emptyStateHtml({
            iconName: 'alert',
            title: 'Gagal memuat audit',
            body: e.message,
            actionHtml: `<button class="btn btn-ghost btn-sm" type="button" id="audit-retry">Coba lagi</button>`,
        });
        const b = $('audit-retry');
        if (b) b.addEventListener('click', loadAudit);
    }
}

function renderSummary() {
    const total = state.audit.length;
    const ok = state.audit.filter(l => l.status === 'success').length;
    $('audit-summary').hidden = false;
    $('audit-summary').innerHTML = `
        <div class="audit-stat"><span class="a-k">Total kirim</span><span class="a-v">${total}</span></div>
        <div class="audit-stat success"><span class="a-k">Berhasil</span><span class="a-v">${ok}</span></div>
        <div class="audit-stat failed"><span class="a-k">Gagal</span><span class="a-v">${total - ok}</span></div>`;
}

function renderTimeline() {
    const list = $('audit-list');
    const status = $('audit-status').value;
    const logs = status === 'all'
        ? state.audit
        : state.audit.filter(l => (status === 'success') === (l.status === 'success'));

    if (!logs.length) {
        list.innerHTML = emptyStateHtml({
            iconName: 'filter',
            title: 'Tidak ada log dengan filter ini',
            body: 'Coba ubah rentang tanggal atau status.',
        });
        return;
    }

    // Group by day, keep server order (newest first)
    const days = [];
    const dayIdx = new Map();
    logs.forEach(l => {
        const key = String(l.created_at || '').slice(0, 10);
        if (!dayIdx.has(key)) {
            dayIdx.set(key, days.length);
            days.push({ key, items: [] });
        }
        days[dayIdx.get(key)].items.push(l);
    });

    list.innerHTML = days.map(d => `
        <div class="audit-day">${escapeHtml(dayLabel(d.key))}</div>
        ${d.items.map(l => `
            <div class="audit-item" data-audit-id="${l.id}" style="cursor:pointer" title="Klik untuk lihat detail payload">
                <span class="audit-dot ${l.status === 'success' ? 'success' : 'failed'}" aria-hidden="true"></span>
                <div class="audit-meta">
                    <span class="patient-name mono">${escapeHtml(l.patient_id || '-')}</span>
                    <span class="small muted">${escapeHtml(l.resource_type || 'Bundle')}</span>
                    ${l.error_message ? `<span class="small muted" style="color:var(--danger)">${escapeHtml(l.error_message)}</span>` : ''}
                </div>
                <span class="audit-status ${l.status === 'success' ? 'success' : 'failed'}">${l.status === 'success' ? 'Berhasil' : 'Gagal'}</span>
                <span class="audit-time">${escapeHtml(timeLabel(l.created_at))}</span>
            </div>`).join('')}
    `).join('');

    list.querySelectorAll('.audit-item[data-audit-id]').forEach(item => {
        item.addEventListener('click', () => showAuditDetail(parseInt(item.dataset.auditId, 10)));
    });
}

async function showAuditDetail(id) {
    try {
        const res = await api(`/api/audit/${id}`);
        if (!res.data) return;
        const log = res.data;

        let reqFormatted = '';
        let respFormatted = '';
        try { reqFormatted = JSON.stringify(JSON.parse(log.request_payload), null, 2); }
        catch (e) { reqFormatted = log.request_payload || '-'; }

        try { respFormatted = JSON.stringify(JSON.parse(log.response_payload), null, 2); }
        catch (e) { respFormatted = log.response_payload || '-'; }

        // Use payload editor modal or alert
        const modal = $('payload-modal');
        if (modal) {
            $('modal-title').textContent = `Audit Log #${id} (${log.patient_id})`;
            $('modal-subtitle').textContent = `Status: ${log.status} · ${log.created_at}`;
            $('payload-editor').value = `=== REQUEST BUNDLE ===\n${reqFormatted}\n\n=== RESPONSE SATUSEHAT ===\n${respFormatted}`;
            $('payload-modal').hidden = false;
            $('modal-backdrop').hidden = false;
        }
    } catch (e) {
        // Log detail load failed
    }
}
