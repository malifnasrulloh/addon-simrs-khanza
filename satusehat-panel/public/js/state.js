/* ============================================================
   Shared state + derived helpers
   ============================================================ */

'use strict';

export const state = {
    patients: [],
    detail: null,
    selected: {},          // no_rawat -> { resourceType: payloadOverride }
    detailNoRawat: null,
    filters: { search: '', billing: 'all', type: 'all', resource: 'all' },
    dateRange: { since: todayISO(), until: todayISO(), preset: '1' },
    audit: [],
    activeGroup: null,
    batchSel: new Set(),
    batchRunning: false,
    stats: null,             // server-side rail totals for the current filter
    lastFilterKey: '',       // server-filter fingerprint for batch-selection pruning
    expanded: null,          // no_rawat of the expanded dropdown row
    page: 1,
    perPage: 50,
    paginationMeta: { total: 0, page: 1, per_page: 50, pages: 1 },
};

/* Tiny pub/sub - decouples views (no circular imports). */
const busListeners = {};
export const bus = {
    on(ev, fn) { (busListeners[ev] = busListeners[ev] || []).push(fn); },
    emit(ev, ...args) { (busListeners[ev] || []).forEach(fn => fn(...args)); },
};

export function patientStatus(p) {
    return (p.status_bayar || '').toLowerCase().includes('sudah') ? 'paid' : 'unpaid';
}

export function countReadyResources(rc) {
    return rc ? Object.values(rc).filter(c => c > 0).length : 0;
}

export function readinessLevel(rc) {
    const ready = countReadyResources(rc);
    if (ready === 0) return 0;
    const total = Object.keys(rc || {}).length;
    return ready >= total ? 3 : (ready > total / 2 ? 2 : 1);
}

/** Local-date ISO string (avoids UTC drift from toISOString). */
export function toISODate(d) {
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${d.getFullYear()}-${m}-${day}`;
}

export function todayISO() { return toISODate(new Date()); }

const DAY_MS = 86400000;

/** Human label for a date range, e.g. "7 hari terakhir" / "20 Mei - 26 Mei". */
export function rangeLabel(since, until) {
    if (!since && !until) return 'semua data';
    const fmt = (iso) => {
        const [y, m, d] = iso.split('-').map(Number);
        return new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(y, m - 1, d));
    };
    if (since && until) {
        if (until === todayISO()) {
            const diff = Math.round((Date.parse(until) - Date.parse(since)) / DAY_MS) + 1;
            return diff <= 90 ? `${diff} hari terakhir` : `${fmt(since)} - ${fmt(until)}`;
        }
        return `${fmt(since)} - ${fmt(until)}`;
    }
    return since ? `sejak ${fmt(since)}` : `sampai ${fmt(until)}`;
}

export function dayLabel(isoDateTime) {
    const d = new Date(String(isoDateTime).replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return String(isoDateTime || '');
    const today = new Date();
    const startOf = (x) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
    const diffDays = Math.round((startOf(today) - startOf(d)) / DAY_MS);
    if (diffDays === 0) return 'Hari ini';
    if (diffDays === 1) return 'Kemarin';
    return new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'long', year: 'numeric' }).format(d);
}

export function timeLabel(isoDateTime) {
    const d = new Date(String(isoDateTime).replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return String(isoDateTime || '');
    return new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit' }).format(d);
}
