/* ============================================================
   Payload editor modal - preview/edit resource JSON before send
   ============================================================ */

'use strict';

import { api } from './api.js';
import { $, toast, rememberFocus, restoreFocus, trapFocus, untrapFocus } from './ui.js';
import { state, bus } from './state.js';

let editingResource = null;

export function initPayloadEditor() {
    $('modal-format').addEventListener('click', () => {
        const editor = $('payload-editor');
        try {
            editor.value = JSON.stringify(JSON.parse(editor.value), null, 2);
            setEditorStatus('JSON diformat', 'ok');
        } catch (err) {
            setEditorStatus('JSON tidak valid, tidak dapat diformat. ' + err.message, 'error');
        }
    });
    $('modal-save').addEventListener('click', () => {
        const editor = $('payload-editor');
        try {
            const parsed = JSON.parse(editor.value);
            if (!state.selected[state.detailNoRawat]) {
                state.selected[state.detailNoRawat] = {};
            }
            state.selected[state.detailNoRawat][editingResource] = parsed;
            toast('Payload disimpan', `${editingResource} akan dikirim dengan payload ini`, 'success');
            bus.emit('payload:changed', state.detailNoRawat);
            hidePayloadModal();
        } catch (err) {
            setEditorStatus('JSON tidak valid, tidak dapat menyimpan. ' + err.message, 'error');
        }
    });
    $('modal-cancel').addEventListener('click', hidePayloadModal);
    $('modal-close').addEventListener('click', hidePayloadModal);
    $('payload-editor').addEventListener('input', (e) => {
        try { JSON.parse(e.target.value); setEditorStatus('JSON valid', 'ok'); }
        catch (err) { setEditorStatus('JSON belum valid: ' + err.message, 'error'); }
    });
}

// Rapid opens of different resources must not paint out of order.
let editorSeq = 0;

export async function openPayloadEditor(resource) {
    if (!state.detail) return;
    const seq = ++editorSeq;
    editingResource = resource;
    // An earlier audit-detail view may have hidden these (and closed via
    // Escape, bypassing its own restore) — always reset to edit mode.
    $('modal-save').hidden = false;
    $('modal-format').hidden = false;
    $('audit-entries').hidden = true;
    $('audit-entries').innerHTML = '';
    $('modal-title').textContent = resource;
    $('modal-subtitle').textContent = `${state.detailNoRawat} - payload siap kirim`;
    $('payload-editor').value = 'Memuat payload...';
    setEditorStatus('');
    // Focus bookkeeping must happen BEFORE trapFocus() moves focus into the
    // modal — otherwise restoreFocus() restores focus to a hidden element.
    rememberFocus();
    showPayloadModal();
    try {
        const raw = state.detailNoRawat.replace(/\//g, '%2F');
        const data = await api(`/api/patients/${raw}/resources/${resource}`);
        if (seq !== editorSeq) return; // a newer editor request won
        if (!data || data.data === undefined) {
            throw new Error(data?.error || 'Payload tidak tersedia untuk resource ini.');
        }
        const payload = data.data;
        if (!state.selected[state.detailNoRawat]) {
            state.selected[state.detailNoRawat] = {};
        }
        if (!state.selected[state.detailNoRawat][resource]) {
            state.selected[state.detailNoRawat][resource] = payload;
        }
        $('payload-editor').value = JSON.stringify(state.selected[state.detailNoRawat][resource], null, 2);
        setEditorStatus('JSON valid', 'ok');
    } catch (e) {
        $('payload-editor').value = '';
        setEditorStatus(e.message, 'error');
        toast('Gagal memuat payload', e.message, 'error');
    }
}

function showPayloadModal() {
    $('payload-modal').hidden = false;
    $('modal-backdrop').hidden = false;
    trapFocus($('payload-modal'));
}

export function hidePayloadModal() {
    $('payload-modal').hidden = true;
    $('modal-backdrop').hidden = true;
    // Any close path (X, backdrop, Escape, cancel) must restore edit-mode
    // controls — an audit-detail view hides them while read-only.
    $('modal-save').hidden = false;
    $('modal-format').hidden = false;
    $('audit-entries').hidden = true;
    $('audit-entries').innerHTML = '';
    untrapFocus($('payload-modal'));
    restoreFocus();
}

function setEditorStatus(msg, type) {
    const el = $('editor-status');
    el.textContent = msg;
    el.className = `editor-status ${type || ''}`;
}
