/* ============================================================
   Settings view - Satu Sehat credentials
   ============================================================ */

'use strict';

import { api } from '../api.js';
import { $, toast } from '../ui.js';

export function initSettingsView() {
    $('btn-settings-back').addEventListener('click', () => { location.hash = '#/'; });
    $('btn-settings-save').addEventListener('click', saveSettings);
}

export function showSettingsView() {
    $('table-wrap').hidden = true;
    $('audit-view').hidden = true;
    $('settings-view').hidden = false;
    loadSettings();
}

async function loadSettings() {
    const msg = $('settings-msg');
    msg.textContent = 'Memuat...';
    try {
        const data = await api('/api/settings');
        const s = data.data || {};
        $('set-org').value = s.organization_id || '';
        $('set-client').value = s.client_id || '';
        $('set-secret').value = '';
        $('set-secret').placeholder = s.has_secret ? '•••• (tersimpan - kosongkan bila tidak berubah)' : 'Client Secret';
        $('set-env').value = s.environment === 'sandbox' ? 'sandbox' : 'production';
        msg.textContent = s.has_secret ? 'Kredensial tersimpan.' : 'Kredensial belum diatur.';
    } catch (e) {
        msg.textContent = 'Gagal memuat: ' + e.message;
    }
}

async function saveSettings() {
    const msg = $('settings-msg');
    const btn = $('btn-settings-save');
    const spinner = btn.querySelector('.spinner');
    const label = btn.querySelector('.btn-label');
    btn.disabled = true;
    spinner.hidden = false;
    label.textContent = 'Menyimpan...';
    try {
        const data = await api('/api/settings', {
            method: 'POST',
            body: JSON.stringify({
                organization_id: $('set-org').value.trim(),
                client_id: $('set-client').value.trim(),
                client_secret: $('set-secret').value,
                environment: $('set-env').value,
            }),
        });
        if (data.success) {
            toast('Pengaturan disimpan', 'Kredensial Satu Sehat diperbarui', 'success');
            await loadSettings();
        } else {
            msg.textContent = data.error || 'Gagal menyimpan';
        }
    } catch (e) {
        msg.textContent = 'Gagal: ' + e.message;
    } finally {
        btn.disabled = false;
        spinner.hidden = true;
        label.textContent = 'Simpan';
    }
}
