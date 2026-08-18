/* ============================================================
   Audit view v2 - filters, pagination, stats, per-entry detail
   ============================================================ */

'use strict';

import { api, base } from '../api.js';
import { $, escapeHtml, debounce, emptyStateHtml, rememberFocus, trapFocus, toast } from '../ui.js';
import { state, dayLabel, timeLabel } from '../state.js';

const auditReload = debounce(() => { state.auditPage = 1; loadAudit(); }, 300);
const auditPageReload = debounce(loadAudit, 150);
// Monotonic guards: the list and the stats fetch run concurrently from
// showAuditView() — a SHARED counter would let the second request (stats)
// invalidate the first (list) every time, dropping the timeline paint.
let auditListSeq = 0;
let auditStatsSeq = 0;

export function initAuditView() {
    // #btn-audit is a plain anchor (href="#/audit") — no JS needed.
    $('btn-audit-back').addEventListener('click', () => { location.hash = '#/'; });
    $('audit-since').addEventListener('input', auditReload);
    $('audit-until').addEventListener('input', auditReload);
    $('audit-status').addEventListener('change', auditReload);
    $('audit-rule').addEventListener('input', auditReload);
    $('audit-prev').addEventListener('click', () => { if (state.auditPage > 1) { state.auditPage--; auditPageReload(); } });
    $('audit-next').addEventListener('click', () => { if (state.auditPage < (state.auditPages || 1)) { state.auditPage++; auditPageReload(); } });
    $('btn-audit-export').addEventListener('click', () => {
        const qs = new URLSearchParams();
        const since = $('audit-since').value, until = $('audit-until').value, status = $('audit-status').value;
        if (since) qs.set('since', since);
        if (until) qs.set('until', until);
        if (status && status !== 'all') qs.set('status', status);
        // base() already carries ?r=... — only append & when there are filters.
        const url = base('/api/audit/export');
        window.location.href = qs.toString() ? url + '&' + qs.toString() : url;
    });
}

export function showAuditView() {
    $('table-wrap').hidden = true;
    $('audit-view').hidden = false;
    $('settings-view').hidden = true;
    state.auditPage = state.auditPage || 1;
    loadAudit();
    loadAuditStats();
}

export async function loadAudit() {
    const seq = ++auditListSeq;
    const list = $('audit-list');
    list.innerHTML = '<div class="empty-state">Memuat audit log...</div>';
    $('audit-summary').hidden = true;

    const qs = new URLSearchParams({ page: String(state.auditPage || 1), per_page: '25' });
    const since = $('audit-since').value;
    const until = $('audit-until').value;
    const status = $('audit-status').value;
    const rule = $('audit-rule').value.trim();
    if (since) qs.set('since', since);
    if (until) qs.set('until', until);
    if (status && status !== 'all') qs.set('status', status);
    if (/^\d{4,6}$/.test(rule)) qs.set('rule_number', rule);

    try {
        const data = await api(`/api/audit?${qs}`);
        if (seq !== auditListSeq) return; // a newer filter/page request won this race
        state.audit = data.data || [];
        state.auditPages = data.meta?.pages || 1;
        state.auditTotal = data.meta?.total || 0;
        renderPager();
        if (!state.audit.length) {
            list.innerHTML = emptyStateHtml({
                iconName: 'clock',
                title: 'Belum ada aktivitas',
                body: 'Log pengiriman bundle akan muncul di sini.',
            });
            return;
        }
        renderSummary(state.audit.length);
        renderTimeline();
    } catch (e) {
        if (seq !== auditListSeq) return;
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

async function loadAuditStats() {
    const seq = ++auditStatsSeq;
    try {
        const res = await api('/api/audit/stats');
        if (seq !== auditStatsSeq || !res.data) return;
        const t = res.data.totals || {};
        $('audit-stat-total').textContent = String(t.audits ?? '-');
        $('audit-stat-ok').textContent = String(t.success ?? '-');
        $('audit-stat-rate').textContent = t.success_rate === null || t.success_rate === undefined ? '-' : `${t.success_rate}%`;

        const rules = res.data.top_rules || [];
        const box = $('audit-top-rules');
        if (box) {
            box.innerHTML = rules.length
                ? rules.slice(0, 6).map(r => `
                    <div class="audit-rule-row" title="${escapeHtml(r.message || '')}">
                        <span class="mono">${r.rule_number}</span>
                        <span class="small muted">× ${r.count}</span>
                        <span class="small rule-text">${escapeHtml((r.message || '').slice(0, 60))}</span>
                    </div>`).join('')
                : '<span class="small muted">Tidak ada kegagalan rule terbaru.</span>';
        }
    } catch (e) { /* stats optional */ }
}

function renderPager() {
    const page = state.auditPage || 1, pages = state.auditPages || 1;
    $('audit-page-info').textContent = `Halaman ${page} dari ${pages} (${state.auditTotal || 0} log)`;
    $('audit-prev').disabled = page <= 1;
    $('audit-next').disabled = page >= pages;
}

function renderSummary(count) {
    $('audit-summary').hidden = false;
    $('audit-summary').innerHTML = `
        <div class="audit-stat"><span class="a-k">Log tampil</span><span class="a-v">${count}</span></div>`;
}

const STATUS_LABEL = {
    success: ['Berhasil', 'success'],
    partial: ['Sebagian gagal', 'partial'],
    failed: ['Gagal', 'failed'],
};

function renderTimeline() {
    const list = $('audit-list');
    const logs = state.audit;

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
        ${d.items.map(l => {
            const [label, cls] = STATUS_LABEL[l.status] || [l.status, 'failed'];
            const sent = l.sent_count ?? null;
            const fail = l.failed_count ?? null;
            return `
            <div class="audit-item" data-audit-id="${l.id}" role="button" tabindex="0" title="Klik untuk lihat detail per entri">
                <span class="audit-dot ${cls}" aria-hidden="true"></span>
                <div class="audit-meta">
                    <span class="patient-name mono">${escapeHtml(l.patient_id || '-')}</span>
                    <span class="small muted">${escapeHtml(l.resource_type || 'Bundle')}</span>
                    ${l.entry_count ? `<span class="small muted">· ${l.sent_count}/${l.entry_count} entri terkirim${fail ? `, ${fail} gagal` : ''}</span>` : ''}
                    ${l.error_message ? `<span class="small muted" style="color:var(--danger)">${escapeHtml(l.error_message.slice(0, 120))}</span>` : ''}
                </div>
                <span class="audit-status ${cls}">${label}</span>
                <span class="audit-time">${escapeHtml(timeLabel(l.created_at))}</span>
            </div>`;}).join('')}
    `).join('');

    list.querySelectorAll('.audit-item[data-audit-id]').forEach(item => {
        const open = () => showAuditDetail(parseInt(item.dataset.auditId, 10));
        item.addEventListener('click', open);
        // Keyboard parity: Enter/Space on a focused item opens the detail.
        item.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
        });
    });
}

// Rapid clicks on two audit entries must not paint out of order.
let auditDetailSeq = 0;

async function showAuditDetail(id) {
    const seq = ++auditDetailSeq;
    try {
        const res = await api(`/api/audit/${id}`);
        if (!res.data) return;
        if (seq !== auditDetailSeq) return; // a newer detail request won
        const log = res.data;

        let reqFormatted = '';
        let respFormatted = '';
        try { reqFormatted = JSON.stringify(JSON.parse(log.request_payload), null, 2); }
        catch (e) { reqFormatted = log.request_payload || '-'; }
        try { respFormatted = JSON.stringify(JSON.parse(log.response_payload), null, 2); }
        catch (e) { respFormatted = log.response_payload || '-'; }

        const modal = $('payload-modal');
        if (modal) {
            // Read-only: hide the Save button while viewing an audit detail.
            // hidePayloadModal() restores them on every close path.
            const saveBtn = $('modal-save');
            const fmtBtn = $('modal-format');
            if (saveBtn) saveBtn.hidden = true;
            if (fmtBtn) fmtBtn.hidden = true;

            const entries = (log.entries || []).map(e => `
                <tr>
                    <td class="mono">${escapeHtml(e.resource_type || '-')}</td>
                    <td><span class="audit-status ${e.status === 'sent' ? 'success' : 'failed'}">${escapeHtml(e.status)}</span></td>
                    <td class="mono">${e.rule_number ? escapeHtml(String(e.rule_number)) : '-'}</td>
                    <td class="small">${escapeHtml((e.rule_message || e.issue_text || '-').slice(0, 100))}</td>
                </tr>`).join('');

            $('modal-title').textContent = `Audit Log #${id} (${log.patient_id})`;
            $('modal-subtitle').textContent = `Status: ${log.status} · ${log.created_at}`;
            $('payload-editor').value = `=== REQUEST BUNDLE ===\n${reqFormatted}\n\n=== RESPONSE SATUSEHAT ===\n${respFormatted}`;
            const entryBox = $('audit-entries');
            if (entryBox) {
                if (entries) {
                    entryBox.hidden = false;
                    entryBox.innerHTML = `<table class="audit-entries-table">
                        <thead><tr><th>Resource</th><th>Status</th><th>Rule</th><th>Keterangan</th></tr></thead>
                        <tbody>${entries}</tbody></table>`;
                } else {
                    entryBox.hidden = true;
                    entryBox.innerHTML = '';
                }
            }
            $('payload-modal').hidden = false;
            $('modal-backdrop').hidden = false;
            rememberFocus();
            trapFocus($('payload-modal'));
        }
    } catch (e) {
        toast('Gagal memuat detail audit', e.message, 'error');
    }
}
