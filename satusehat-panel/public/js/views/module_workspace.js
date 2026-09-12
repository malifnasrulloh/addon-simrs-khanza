/* ============================================================
   SATUSEHAT Admin Panel - Dynamic Module Workspace View
   ============================================================ */

'use strict';

import { $, escapeHtml, toast, rememberFocus, restoreFocus, trapFocus, untrapFocus } from '../ui.js';
import { api, BASE } from '../api.js';
import { fetchModules } from './launchpad.js';

let currentModuleId = null;
let currentManifest = null;
let currentViewModule = null;
let activeFilters = {};
let selectedItems = new Set();
let workspaceSeq = 0;

export function initModuleWorkspace() {
    // Quick switcher toggle
    const trigger = $('module-switcher-trigger');
    const dropdown = $('quick-switcher-dropdown');

    if (trigger && dropdown) {
        trigger.addEventListener('click', (e) => {
            if (e.target.closest('.quick-switcher-dropdown')) return;
            dropdown.hidden = !dropdown.hidden;
        });

        document.addEventListener('click', (e) => {
            if (!trigger.contains(e.target)) {
                dropdown.hidden = true;
            }
        });
    }

    // Filter toolbar inputs
    ['mod-filter-since', 'mod-filter-until', 'mod-filter-billing', 'mod-filter-sync'].forEach(id => {
        const el = $(id);
        if (el) {
            // Use 'input' for date fields to catch changes immediately, 'change' for selects
            const eventType = (id === 'mod-filter-since' || id === 'mod-filter-until') ? 'input' : 'change';
            el.addEventListener(eventType, onFilterChange);
        }
    });

    const search = $('mod-filter-search');
    if (search) {
        let debounce = null;
        search.addEventListener('input', () => {
            clearTimeout(debounce);
            debounce = setTimeout(onFilterChange, 350);
        });
    }

    const resetBtn = $('btn-mod-filter-reset');
    if (resetBtn) {
        resetBtn.addEventListener('click', () => {
            const today = new Date().toISOString().slice(0, 10);
            $('mod-filter-since').value = today;
            $('mod-filter-until').value = today;
            $('mod-filter-billing').value = 'all';
            $('mod-filter-sync').value = 'all';
            $('mod-filter-search').value = '';
            onFilterChange();
        });
    }

    // Response drawer tabs
    ['summary', 'req', 'resp'].forEach(tab => {
        const btn = $(`tab-btn-${tab}`);
        if (btn) {
            btn.addEventListener('click', () => switchResponseTab(tab));
        }
    });

    const respClose = $('resp-drawer-close');
    if (respClose) respClose.addEventListener('click', hideResponseDrawer);
    const respBtnClose = $('resp-drawer-btn-close');
    if (respBtnClose) respBtnClose.addEventListener('click', hideResponseDrawer);

    // Response drawer backdrop click to close
    const respBackdrop = $('response-backdrop');
    if (respBackdrop) respBackdrop.addEventListener('click', hideResponseDrawer);

    // Response drawer re-send button
    const respBtnSend = $('resp-drawer-btn-send');
    if (respBtnSend) {
        respBtnSend.addEventListener('click', async () => {
            if (!currentModuleId || selectedItems.size === 0) return;
            let itemKey = Array.from(selectedItems)[0];
            try { itemKey = JSON.parse(itemKey); } catch {}
            respBtnSend.disabled = true;
            respBtnSend.textContent = 'Mengirim...';
            try {
                const res = await api(`/api/modules/${currentModuleId}/send`, {
                    method: 'POST',
                    body: JSON.stringify({ items: [itemKey], since: activeFilters.since, until: activeFilters.until })
                });
                if (res.success) {
                    toast('Berhasil', 'Resource dikirim ulang', 'success');
                    hideResponseDrawer();
                    if (currentViewModule && typeof currentViewModule.reload === 'function') {
                        await currentViewModule.reload(activeFilters);
                    }
                } else {
                    toast('Gagal', res.error || 'Gagal kirim ulang', 'error');
                }
            } catch (e) {
                toast('Error', e.message, 'error');
            } finally {
                respBtnSend.disabled = false;
                respBtnSend.textContent = 'Kirim Ulang';
            }
        });
    }

    // Batch send selected button
    const batchSendBtn = $('btn-module-send-selected');
    if (batchSendBtn) {
        batchSendBtn.addEventListener('click', async () => {
            if (!currentModuleId || selectedItems.size === 0) return;
            const items = Array.from(selectedItems).map(x => {
                try { return JSON.parse(x); } catch { return x; }
            });
            batchSendBtn.disabled = true;
            const origText = batchSendBtn.querySelector('.btn-label')?.textContent || 'Kirim Terpilih';
            if (batchSendBtn.querySelector('.btn-label')) {
                batchSendBtn.querySelector('.btn-label').textContent = `Mengirim ${items.length}...`;
            }
            try {
                toast('Mengirim...', `Mengirim ${items.length} item ${currentManifest?.title || currentModuleId}`, 'info');
                const res = await api(`/api/modules/${currentModuleId}/send`, {
                    method: 'POST',
                    body: JSON.stringify({ items, since: activeFilters.since, until: activeFilters.until })
                });
                if (res.success) {
                    toast('Selesai', `${res.success_count || items.length} item berhasil dikirim`, 'success');
                    selectedItems.clear();
                    updateBatchSendButton();
                } else {
                    toast('Peringatan', `Terkirim: ${res.success_count || 0}, Gagal: ${res.fail_count || 0}`, 'warning');
                }
                if (currentViewModule && typeof currentViewModule.reload === 'function') {
                    await currentViewModule.reload(activeFilters);
                }
            } catch (e) {
                toast('Error', e.message, 'error');
            } finally {
                batchSendBtn.disabled = false;
                if (batchSendBtn.querySelector('.btn-label')) {
                    batchSendBtn.querySelector('.btn-label').textContent = origText;
                }
            }
        });
    }

    // Add keyboard navigation for module table rows
    document.addEventListener('keydown', (e) => {
        if (e.target.closest('#module-table-wrap') && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
            e.preventDefault();
            const rows = document.querySelectorAll('#module-table-wrap tbody tr.patient-row:not([hidden])');
            const active = document.activeElement;
            let idx = Array.from(rows).findIndex(r => r === active || r.contains(active));
            if (idx === -1) idx = 0;
            if (e.key === 'ArrowDown' && idx < rows.length - 1) idx++;
            else if (e.key === 'ArrowUp' && idx > 0) idx--;
            rows[idx]?.focus();
        }
    });
}

export async function showModuleView(moduleId, queryParams = {}) {
    currentModuleId = moduleId;
    const seq = ++workspaceSeq;

    // View containers
    const root = $('module-view');
    const launchpad = $('launchpad-view');
    const patientView = $('patient-list-view');
    const auditView = $('audit-view');
    const settingsView = $('settings-view');
    const rail = $('filter-rail');

    if (root) root.hidden = false;
    if (launchpad) launchpad.hidden = true;
    if (patientView) patientView.hidden = true;
    if (auditView) auditView.hidden = true;
    if (settingsView) settingsView.hidden = true;
    if (rail) rail.hidden = true;
    document.querySelector('.layout')?.classList.remove('has-rail');

    // Find manifest
    const modules = await fetchModules();
    currentManifest = modules.find(m => m.id === moduleId);
    if (!currentManifest) {
        $('module-table-wrap').innerHTML = `<div class="empty-state">Modul <code>${escapeHtml(moduleId)}</code> tidak ditemukan.</div>`;
        return;
    }

    // Update Header & Breadcrumb
    $('module-title').textContent = currentManifest.title;
    $('module-subtitle').textContent = currentManifest.description || `Workspace modul ${currentManifest.fhir_resource || ''}`;

    renderQuickSwitcher(modules, moduleId);

    // Initialize filter inputs from query params or defaults
    const today = new Date().toISOString().slice(0, 10);
    activeFilters = {
        since: queryParams.since || today,
        until: queryParams.until || today,
        status_bayar: queryParams.status_bayar || 'all',
        status_sync: queryParams.status_sync || 'all',
        search: queryParams.search || '',
        page: parseInt(queryParams.page || '1', 10),
    };

    $('mod-filter-since').value = activeFilters.since;
    $('mod-filter-until').value = activeFilters.until;
    $('mod-filter-billing').value = activeFilters.status_bayar;
    $('mod-filter-sync').value = activeFilters.status_sync;
    $('mod-filter-search').value = activeFilters.search;

    selectedItems.clear();
    updateBatchSendButton();

    // Dynamically load module view.js
    const tableWrap = $('module-table-wrap');
    tableWrap.innerHTML = `<div class="empty-state"><span class="spinner"></span> Memuat modul...</div>`;

    try {
        const moduleDir = currentManifest.dir || moduleId;
        // Native dynamic ES module import with base path support for subfolder deployments
        const viewUrl = `${BASE}/modules/${moduleDir}/view.js`;
        currentViewModule = await import(viewUrl);

        if (seq !== workspaceSeq) return;

        if (typeof currentViewModule.mount === 'function') {
            await currentViewModule.mount(tableWrap, {
                manifest: currentManifest,
                filters: activeFilters,
                onSelectChange: (selected) => {
                    selectedItems = selected;
                    updateBatchSendButton();
                },
                onInspect: showResponseDrawer,
                onToast: toast,
            });
        }
    } catch (err) {
        if (seq !== workspaceSeq) return;
        tableWrap.innerHTML = `<div class="empty-state">Gagal memuat tampilan modul: ${escapeHtml(err.message)}</div>`;
    }
}

function renderQuickSwitcher(modules, activeId) {
    const dropdown = $('quick-switcher-dropdown');
    if (!dropdown) return;

    dropdown.innerHTML = modules.map(m => `
        <a href="#/module/${escapeHtml(m.id)}" class="quick-switcher-item ${m.id === activeId ? 'active' : ''}">
            <span>${escapeHtml(m.icon || '📦')}</span>
            <span>${escapeHtml(m.title)}</span>
        </a>
    `).join('');
}

function onFilterChange() {
    activeFilters.since = $('mod-filter-since').value;
    activeFilters.until = $('mod-filter-until').value;
    activeFilters.status_bayar = $('mod-filter-billing').value;
    activeFilters.status_sync = $('mod-filter-sync').value;
    activeFilters.search = $('mod-filter-search').value.trim();
    activeFilters.page = 1;

    // Sync to URL hash without reload
    const qs = new URLSearchParams();
    if (activeFilters.since) qs.set('since', activeFilters.since);
    if (activeFilters.until) qs.set('until', activeFilters.until);
    if (activeFilters.status_bayar !== 'all') qs.set('status_bayar', activeFilters.status_bayar);
    if (activeFilters.status_sync !== 'all') qs.set('status_sync', activeFilters.status_sync);
    if (activeFilters.search) qs.set('search', activeFilters.search);

    const hash = `#/module/${currentModuleId}?${qs.toString()}`;
    history.replaceState(null, '', hash);

    if (currentViewModule && typeof currentViewModule.reload === 'function') {
        currentViewModule.reload(activeFilters);
    }
}

function updateBatchSendButton() {
    const btn = $('btn-module-send-selected');
    if (!btn) return;
    const count = selectedItems.size;
    btn.disabled = count === 0;
    btn.querySelector('.btn-label').textContent = count > 0 ? `Kirim Terpilih (${count})` : 'Kirim Terpilih';
}

/* ── Slide-Over Response & Audit Drawer ───────────────────── */
export function showResponseDrawer(itemData) {
    rememberFocus();
    $('response-backdrop').hidden = false;
    $('response-drawer').hidden = false;

    $('resp-drawer-title').textContent = itemData.title || 'Detail Respon SATUSEHAT';
    $('resp-drawer-subtitle').textContent = itemData.subtitle || `${itemData.patient_name || ''} · ${itemData.no_rawat || ''}`;

    // Summary Card
    const isSuccess = itemData.status === 'sent' || itemData.http_code === 200 || itemData.http_code === 201;
    const summaryCard = $('resp-summary-card');
    summaryCard.innerHTML = `
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--space-2)">
            <span class="badge ${isSuccess ? 'badge-success' : 'badge-danger'}">
                <span class="dot"></span>${escapeHtml(itemData.status_label || itemData.status || 'Unknown')}
            </span>
            <span class="mono small">${escapeHtml(itemData.http_code ? `HTTP ${itemData.http_code}` : '-')}</span>
        </div>
        ${itemData.satusehat_id ? `<div class="small"><strong>ID SATUSEHAT:</strong> <code class="mono">${escapeHtml(itemData.satusehat_id)}</code></div>` : ''}
        ${itemData.rule_number ? `<div class="small" style="color:var(--danger)"><strong>Rule Number:</strong> <code class="mono">${escapeHtml(String(itemData.rule_number))}</code></div>` : ''}
        ${itemData.issue_text ? `<div class="small muted" style="margin-top:4px">${escapeHtml(itemData.issue_text)}</div>` : ''}
    `;

    // Blocker notice if any
    const blockerBox = $('resp-blocker-box');
    if (itemData.blocker_reason) {
        blockerBox.hidden = false;
        blockerBox.innerHTML = `<strong>Prasyarat Belum Terpenuhi:</strong> ${escapeHtml(itemData.blocker_reason)}`;
    } else {
        blockerBox.hidden = true;
    }

    // JSON Editors
    $('resp-req-json').value = itemData.request_json ? JSON.stringify(itemData.request_json, null, 2) : '-';
    $('resp-resp-json').value = itemData.response_json ? JSON.stringify(itemData.response_json, null, 2) : '-';

    switchResponseTab('summary');
    trapFocus($('response-drawer'));
}

export function hideResponseDrawer() {
    $('response-drawer').hidden = true;
    $('response-backdrop').hidden = true;
    untrapFocus($('response-drawer'));
    restoreFocus();
}

function switchResponseTab(tab) {
    ['summary', 'req', 'resp'].forEach(t => {
        const btn = $(`tab-btn-${t}`);
        const content = $(`tab-content-${t}`);
        if (btn) btn.classList.toggle('active', t === tab);
        if (content) content.hidden = (t !== tab);
    });
}
