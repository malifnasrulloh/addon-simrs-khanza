/* ============================================================
   SATUSEHAT Admin Panel - Launchpad Hub (Card Grid Launcher)
   ============================================================ */

'use strict';

import { $, escapeHtml } from '../ui.js';
import { api } from '../api.js';

let cachedModules = null;

export async function fetchModules() {
    if (cachedModules) return cachedModules;
    try {
        const res = await api('/api/modules');
        cachedModules = res.data || [];
        return cachedModules;
    } catch (e) {
        return [];
    }
}

export function showLaunchpadView() {
    const root = $('launchpad-view');
    const moduleView = $('module-view');
    const patientView = $('patient-list-view');
    const auditView = $('audit-view');
    const settingsView = $('settings-view');
    const rail = $('filter-rail');

    if (root) root.hidden = false;
    if (moduleView) moduleView.hidden = true;
    if (patientView) patientView.hidden = true;
    if (auditView) auditView.hidden = true;
    if (settingsView) settingsView.hidden = true;
    if (rail) rail.hidden = true; // Launchpad is full-width bento grid

    renderLaunchpad();
}

export async function renderLaunchpad() {
    const container = $('launchpad-categories');
    if (!container) return;

    const modules = await fetchModules();
    if (!modules.length) {
        container.innerHTML = `<div class="empty-state">Tidak ada modul yang ditemukan di <code>modules/</code>.</div>`;
        return;
    }

    // Group by category
    const categories = {};
    const categoryOrder = [
        'Featured',
        'Kunjungan & Registrasi',
        'Rekam Klinis',
        'Farmasi & Obat',
        'Laboratorium',
        'Radiologi & Imaging',
        'Dokumen & Resume',
        'Pengaturan & Audit'
    ];

    modules.forEach(m => {
        const cat = m.category || 'Lainnya';
        if (!categories[cat]) categories[cat] = [];
        categories[cat].push(m);
    });

    const sortedCats = Object.keys(categories).sort((a, b) => {
        const idxA = categoryOrder.indexOf(a);
        const idxB = categoryOrder.indexOf(b);
        const posA = idxA >= 0 ? idxA : 999;
        const posB = idxB >= 0 ? idxB : 999;
        return posA - posB;
    });

    container.innerHTML = sortedCats.map(cat => {
        const items = categories[cat];
        return `
            <section class="launchpad-category" aria-label="${escapeHtml(cat)}">
                <div class="launchpad-cat-title">
                    <span>${escapeHtml(cat)}</span>
                </div>
                <div class="launchpad-grid">
                    ${items.map(m => `
                        <a href="#/module/${escapeHtml(m.id)}" class="launchpad-card ${m.category === 'Featured' ? 'featured' : ''}" data-module-id="${escapeHtml(m.id)}">
                            <div class="card-head">
                                <span class="card-icon" aria-hidden="true">${escapeHtml(m.icon || '📦')}</span>
                                ${m.fhir_resource ? `<span class="badge badge-neutral mono small">${escapeHtml(m.fhir_resource)}</span>` : ''}
                            </div>
                            <div class="card-body">
                                <h3>${escapeHtml(m.title)}</h3>
                                <p>${escapeHtml(m.description || '')}</p>
                            </div>
                            <div class="card-foot">
                                <span>Buka Modul &rarr;</span>
                            </div>
                        </a>
                    `).join('')}
                </div>
            </section>
        `;
    }).join('');
}
