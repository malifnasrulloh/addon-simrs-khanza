/* ============================================================
   API layer - base path (drop-in mode) + fetch helpers
   ============================================================ */

'use strict';

// The panel runs as a real PHP file (index.php) inside a pasted folder.
// API calls go through index.php?r=/api/... which any PHP server executes.
// BASE stays '' so assets are folder-relative; only API fetches get the
// index.php?r= prefix.
export const BASE = String(window.PANEL_BASE || '').replace(/\/$/, '');

export function base(p) {
    if (!p || !p.startsWith('/api/')) return BASE + (p || '');
    const qIdx = p.indexOf('?');
    // Pass the route path as-is into r= (Router rawurldecodes it).
    // Do NOT encodeURIComponent the path — it already contains %2F
    // from noRawat encoding and double-encoding would break routing.
    if (qIdx === -1) {
        return BASE + '/index.php?r=' + p;
    }
    const routePath = p.substring(0, qIdx);
    const queryStr = p.substring(qIdx + 1);
    return BASE + '/index.php?r=' + routePath + '&' + queryStr;
}

let csrfToken = '';

export function setCsrfToken(token) {
    if (token) csrfToken = token;
}

export function getCsrfToken() {
    return csrfToken;
}

export async function api(path, opts = {}) {
    const headers = { 'Content-Type': 'application/json', ...(opts.headers || {}) };
    if (csrfToken && opts.method && opts.method.toUpperCase() !== 'GET') {
        headers['X-CSRF-Token'] = csrfToken;
    }

    const res = await fetch(base(path), {
        credentials: 'same-origin',
        ...opts,
        headers,
    });
    if (res.status === 401) {
        window.dispatchEvent(new CustomEvent('auth:expired'));
        throw new Error('Sesi berakhir, silakan login kembali');
    }
    const data = await res.json().catch(() => ({}));
    if (data.csrf_token) {
        setCsrfToken(data.csrf_token);
    }
    if (!res.ok || data.success === false) throw new Error(data.error || `HTTP ${res.status}`);
    return data;
}

/** Extract a human-readable message from a FHIR OperationOutcome. */
export function extractError(operationOutcome) {
    if (Array.isArray(operationOutcome) && operationOutcome[0]?.issue) {
        return operationOutcome[0].issue[0].details?.text
            || operationOutcome[0].issue[0].diagnostics
            || 'Bundle ditolak server';
    }
    if (operationOutcome?.issue?.[0]?.details?.text) return operationOutcome.issue[0].details.text;
    if (operationOutcome?.issue?.[0]?.diagnostics) return operationOutcome.issue[0].diagnostics;
    return 'Bundle ditolak server';
}
