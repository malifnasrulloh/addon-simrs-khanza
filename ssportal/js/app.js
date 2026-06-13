// SatuSehat Portal - Vanilla JS Controller
const API_BASE = '../php-service/api_satusehat_portal.php';

// Global state
let jwtToken = localStorage.getItem('ss_token') || null;
let userRole = 'user';
let currentSection = 'analytics';
let activeSearchTab = 'rm';
let selectedResource = 'patient';
let selectedMappingType = 'location';

// Local search results & cache
let currentPatientResult = null;
let syncStatsData = null;
let cancelRequested = false;
let dbAnalyzerData = null; // Store last DB analysis results

// Pagination states
let syncRecordsPage = 1;
let mappingPage = 1;
let logsPage = 1;

// Parse claims
function parseJwt(token) {
    try {
        const base64Url = token.split('.')[1];
        const base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/');
        const jsonPayload = decodeURIComponent(atob(base64).split('').map(function(c) {
            return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
        }).join(''));
        return JSON.parse(jsonPayload);
    } catch (e) {
        return null;
    }
}

// Fetch wrapper
async function apiFetch(url, options = {}) {
    if (!options.headers) options.headers = {};
    if (jwtToken) {
        options.headers['Authorization'] = `Bearer ${jwtToken}`;
    }
    try {
        const res = await fetch(url, options);
        if (res.status === 401) {
            handleLogout();
            throw new Error('Your session has expired. Please sign in again.');
        }
        const data = await res.json();
        if (!res.ok) {
            throw new Error(data.message || 'API request failed');
        }
        return data;
    } catch (err) {
        console.error("API Error: ", err);
        throw err;
    }
}

// Initialize App
document.addEventListener('DOMContentLoaded', () => {
    // Set current logs date to today
    const dateInput = document.getElementById('logs-date-input');
    if (dateInput) {
        dateInput.value = new Date().toISOString().split('T')[0];
    }
    
    if (jwtToken) {
        setupAuthContext();
    } else {
        showView('auth-view');
    }

    // Bind forms
    const loginForm = document.getElementById('login-form');
    if (loginForm) loginForm.addEventListener('submit', handleLoginSubmit);

    const searchForm = document.getElementById('search-form');
    if (searchForm) searchForm.addEventListener('submit', handleSearchSubmit);
});

// Setup User Auth context
function setupAuthContext() {
    const claims = parseJwt(jwtToken);
    if (!claims) {
        handleLogout();
        return;
    }
    userRole = claims.role || 'user';
    document.getElementById('display-user').innerText = claims.user || 'Admin';
    document.getElementById('display-role').innerText = userRole;

    // Restrict elements if normal user
    const nav = document.getElementById('main-nav');
    const diagWidget = document.getElementById('dashboard-diagnostics');
    
    if (userRole !== 'admin') {
        nav.innerHTML = '<button class="nav-tab active">🔍 Patient Search</button>';
        if (diagWidget) diagWidget.style.display = 'none';
        switchSection('patient_search');
    } else {
        nav.innerHTML = `
            <button class="nav-tab active" onclick="switchSection('analytics')">📈 Analytics</button>
            <button class="nav-tab" onclick="switchSection('patient_search')">🔍 Patient Search</button>
            <button class="nav-tab" onclick="switchSection('sync')">🔄 Sync Center</button>
            <button class="nav-tab" onclick="switchSection('troubleshoot')">🗺️ Mapping Center</button>
            <button class="nav-tab" onclick="switchSection('logs')">📋 Log Viewer</button>
        `;
        if (diagWidget) diagWidget.style.display = 'grid';
        runDiagnostics();
        switchSection('analytics');
    }
    showView('main-view');
}

// Show specific main div
function showView(viewId) {
    document.getElementById('auth-view').style.display = viewId === 'auth-view' ? 'flex' : 'none';
    document.getElementById('main-view').style.display = viewId === 'main-view' ? 'block' : 'none';
}

// Handle Login
async function handleLoginSubmit(e) {
    e.preventDefault();
    const btn = document.getElementById('login-btn');
    const errDiv = document.getElementById('login-error');
    btn.disabled = true;
    errDiv.style.display = 'none';

    const u = document.getElementById('username').value;
    const p = document.getElementById('password').value;

    try {
        const res = await apiFetch(`${API_BASE}?action=login`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username: u, password: p })
        });
        if (res.success && res.token) {
            jwtToken = res.token;
            localStorage.setItem('ss_token', jwtToken);
            setupAuthContext();
        } else {
            throw new Error(res.message || 'Authentication failed');
        }
    } catch (err) {
        errDiv.innerText = err.message;
        errDiv.style.display = 'block';
    } finally {
        btn.disabled = false;
    }
}

function handleLogout() {
    jwtToken = null;
    localStorage.removeItem('ss_token');
    showView('auth-view');
    const passInput = document.getElementById('password');
    if (passInput) passInput.value = '';
}

// Navigation tab click switching
function switchSection(secId) {
    currentSection = secId;
    
    // Toggle active classes on tab buttons
    const tabs = document.querySelectorAll('#main-nav .nav-tab');
    tabs.forEach(t => {
        if (t.innerText.toLowerCase().includes(secId.replace('_', ' '))) {
            t.classList.add('active');
        } else {
            t.classList.remove('active');
        }
    });

    // Toggle panels
    const panels = document.querySelectorAll('.section-panel');
    panels.forEach(p => {
        if (p.id === `section-${secId}`) {
            p.classList.add('active');
        } else {
            p.classList.remove('active');
        }
    });

    // Clear search result card if leaving patient search
    if (secId !== 'patient_search') {
        document.getElementById('patient-results-card').style.display = 'none';
    }

    // Trigger statistics loading
    if (secId === 'analytics' && userRole === 'admin') {
        loadAnalytics();
        runDiagnostics();
    } else if (secId === 'sync' && userRole === 'admin') {
        loadSyncStats();
        loadSyncRecords(1);
    } else if (secId === 'troubleshoot' && userRole === 'admin') {
        switchMappingType('location');
    } else if (secId === 'logs' && userRole === 'admin') {
        loadLogs(1);
    }
}

// ==========================================
// SYSTEM CONNECTION DIAGNOSTICS & DB ANALYZER
// ==========================================
async function runDiagnostics() {
    if (userRole !== 'admin') return;

    // Get indicator elements
    const dbDot = document.getElementById('diag-db-dot');
    const dbMsg = document.getElementById('diag-db-msg');
    const ssDot = document.getElementById('diag-ss-dot');
    const ssMsg = document.getElementById('diag-ss-msg');
    const sqliteDot = document.getElementById('diag-sqlite-dot');
    const sqliteMsg = document.getElementById('diag-sqlite-msg');
    const pacsDot = document.getElementById('diag-pacs-dot');
    const pacsMsg = document.getElementById('diag-pacs-msg');

    try {
        const res = await apiFetch(`${API_BASE}?action=getDiagnostics`);
        if (res.success && res.diagnostics) {
            const diag = res.diagnostics;
            dbAnalyzerData = res.db_analyzer || null;

            // Update Database Indicators
            updateIndicator(dbDot, dbMsg, diag.database);
            // If the database has table warnings, downgrade status
            if (dbAnalyzerData) {
                let hasErrors = false;
                let hasWarnings = false;
                Object.values(dbAnalyzerData).forEach(table => {
                    if (table.status === 'error') hasErrors = true;
                    if (table.status === 'warning') hasWarnings = true;
                });
                if (hasErrors) {
                    dbDot.className = 'diag-dot error';
                    dbMsg.innerText = 'DB Structure Error';
                } else if (hasWarnings) {
                    dbDot.className = 'diag-dot warning';
                    dbMsg.innerText = 'DB Structure Warning';
                }
            }

            // Update SQLite, SatuSehat, Orthanc PACS indicators
            updateIndicator(sqliteDot, sqliteMsg, diag.sqlite);
            updateIndicator(ssDot, ssMsg, diag.satusehat);
            updateIndicator(pacsDot, pacsMsg, diag.orthanc);
        }
    } catch (err) {
        console.warn("Diagnostics error: ", err);
    }
}

function updateIndicator(dotEl, msgEl, statusNode) {
    if (!dotEl || !msgEl || !statusNode) return;
    dotEl.className = `diag-dot ${statusNode.status}`;
    msgEl.innerText = statusNode.message || 'Unknown';
    msgEl.title = statusNode.message || '';
}

function showDbStructureDetails() {
    if (!dbAnalyzerData) {
        alert("Diagnostics data is loading. Please wait...");
        return;
    }

    const modal = document.getElementById('modal-container');
    const titleEl = document.getElementById('modal-title');
    const bodyEl = document.getElementById('modal-body');
    const actionsEl = document.getElementById('modal-actions');

    titleEl.innerText = "Database Structure Diagnostics";
    
    let tableHtml = `
        <div style="margin-bottom: 1rem; font-size: 0.85rem; color: var(--text-muted);">
            Scans and verifies integration database table presence and key column definitions.
        </div>
        <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem; text-align: left;">
            <thead>
                <tr>
                    <th style="padding: 0.5rem; border-bottom: 1px solid var(--border-color);">Table Name</th>
                    <th style="padding: 0.5rem; border-bottom: 1px solid var(--border-color);">Status</th>
                    <th style="padding: 0.5rem; border-bottom: 1px solid var(--border-color);">Check details</th>
                </tr>
            </thead>
            <tbody>
    `;

    Object.entries(dbAnalyzerData).forEach(([tableName, result]) => {
        let statusBadge = '';
        if (result.status === 'healthy') {
            statusBadge = '<span class="badge success">Healthy</span>';
        } else if (result.status === 'warning') {
            statusBadge = '<span class="badge warning">Warning</span>';
        } else {
            statusBadge = '<span class="badge danger">Error</span>';
        }

        tableHtml += `
            <tr>
                <td style="padding: 0.5rem; border-bottom: 1px dashed rgba(255,255,255,0.05); font-family: monospace;">${tableName}</td>
                <td style="padding: 0.5rem; border-bottom: 1px dashed rgba(255,255,255,0.05);">${statusBadge}</td>
                <td style="padding: 0.5rem; border-bottom: 1px dashed rgba(255,255,255,0.05); color: #cdd6f4;">${result.message}</td>
            </tr>
        `;
    });

    tableHtml += `</tbody></table>`;

    bodyEl.innerHTML = tableHtml;
    actionsEl.innerHTML = `<button class="btn btn-secondary" style="width: auto;" onclick="closeModal()">Close</button>`;
    modal.style.display = 'flex';
}

// ==========================================
// PANEL 1: ANALYTICS & TRENDS
// ==========================================
async function loadAnalytics() {
    const loader = document.getElementById('analytics-loader');
    const errDiv = document.getElementById('analytics-error');
    const content = document.getElementById('analytics-content');
    
    if (loader) loader.style.display = 'block';
    if (errDiv) errDiv.style.display = 'none';
    if (content) content.style.opacity = '0.3';

    try {
        const res = await apiFetch(`${API_BASE}?action=getAnalyticsStats`);
        if (res.success) {
            renderAnalyticsTrends(res.trends);
            renderAnalyticsErrors(res.top_errors);
            renderAnalyticsCoverage(res.coverage);
            if (content) content.style.opacity = '1';
        } else {
            throw new Error(res.message || 'Analytics error');
        }
    } catch (err) {
        if (errDiv) {
            errDiv.innerText = err.message;
            errDiv.style.display = 'block';
        }
    } finally {
        if (loader) loader.style.display = 'none';
    }
}

function renderAnalyticsTrends(trends) {
    const grid = document.getElementById('chart-grid');
    if (!grid) return;
    grid.innerHTML = '';
    
    if (!trends || trends.length === 0) {
        grid.innerHTML = '<div style="grid-column: 1/span 7; text-align: center; color: var(--text-muted);">No trend stats recorded.</div>';
        return;
    }

    const maxVal = Math.max(...trends.map(x => x.total), 1);
    
    trends.forEach(t => {
        const syncHeight = (t.synced / maxVal) * 100;
        const pendingHeight = (t.pending / maxVal) * 100;

        const columnDiv = document.createElement('div');
        columnDiv.className = 'chart-column';
        columnDiv.innerHTML = `
            <div class="chart-bar-container">
                <div class="chart-bar sync-bar" style="height: ${syncHeight}%" title="Synced: ${t.synced}"></div>
                <div class="chart-bar pending-bar" style="height: ${pendingHeight}%" title="Pending: ${t.pending}"></div>
            </div>
            <div class="chart-label">${t.date.substring(5)}</div>
            <div class="chart-value">${t.total}</div>
        `;
        grid.appendChild(columnDiv);
    });
}

function renderAnalyticsErrors(errors) {
    const container = document.getElementById('top-errors-list');
    if (!container) return;
    container.innerHTML = '';
    
    if (!errors || errors.length === 0) {
        container.innerHTML = '<div style="padding: 1.5rem; text-align: center; color: var(--text-muted);">🎉 No errors logged in the last 3 days!</div>';
        return;
    }

    errors.forEach(err => {
        const errDiv = document.createElement('div');
        errDiv.style.borderBottom = '1px solid rgba(255,255,255,0.05)';
        errDiv.style.paddingBottom = '0.75rem';
        errDiv.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.25rem;">
                <span style="font-size: 0.85rem; color: #f38ba8; font-weight: 500; font-family: monospace; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 80%;" title="${err.reason}">
                    ${err.reason}
                </span>
                <span class="badge" style="background-color: rgba(243, 139, 168, 0.15); color: #f38ba8;">
                    ${err.count} times
                </span>
            </div>
        `;
        container.appendChild(errDiv);
    });
}

function renderAnalyticsCoverage(coverage) {
    const container = document.getElementById('coverage-rates-list');
    if (!container) return;
    container.innerHTML = '';
    
    if (!coverage) return;

    Object.entries(coverage).forEach(([resName, stats]) => {
        const barColor = stats.percent === 100 ? '#4ade80' : (stats.percent > 50 ? '#fbbf24' : '#f43f5e');
        
        const itemDiv = document.createElement('div');
        itemDiv.innerHTML = `
            <div style="display: flex; justify-content: space-between; font-size: 0.8rem; margin-bottom: 0.25rem;">
                <span style="text-transform: capitalize; color: #cdd6f4;">${resName.replace(/_/g, ' ')}</span>
                <span style="color: var(--text-muted);">${stats.synced}/${stats.total} (${stats.percent}%)</span>
            </div>
            <div style="height: 6px; background-color: rgba(255,255,255,0.05); border-radius: 3px; overflow: hidden;">
                <div style="height: 100%; width: ${stats.percent}%; background-color: ${barColor}; transition: width 0.3s ease;"></div>
            </div>
        `;
        container.appendChild(itemDiv);
    });
}

// ==========================================
// PANEL 2: PATIENT SEARCH
// ==========================================
function switchSearchTab(tabName) {
    activeSearchTab = tabName;
    
    // Sub tab classes
    const subTabs = document.querySelectorAll('.sub-tab');
    subTabs.forEach(st => {
        if (st.innerText.toLowerCase().includes(tabName.replace('_', ' '))) {
            st.classList.add('active');
        } else {
            st.classList.remove('active');
        }
    });

    // Form inputs display logic
    document.getElementById('search-group-rm').style.display = tabName === 'rm' ? 'block' : 'none';
    document.getElementById('search-group-nik').style.display = tabName === 'nik' ? 'block' : 'none';
    document.getElementById('search-group-nik_ibu').style.display = tabName === 'nik_ibu' ? 'block' : 'none';

    // Reset UI outcomes
    document.getElementById('search-error').style.display = 'none';
    document.getElementById('patient-results-card').style.display = 'none';
    document.getElementById('patient-creation-prompt').style.display = 'none';
}

async function handleSearchSubmit(e) {
    e.preventDefault();
    const loader = document.getElementById('search-loader');
    const errDiv = document.getElementById('search-error');
    const resultCard = document.getElementById('patient-results-card');
    const createPrompt = document.getElementById('patient-creation-prompt');
    
    loader.style.display = 'block';
    errDiv.style.display = 'none';
    resultCard.style.display = 'none';
    createPrompt.style.display = 'none';
    currentPatientResult = null;

    try {
        if (activeSearchTab === 'rm') {
            const rm = document.getElementById('search-rm').value;
            const res = await apiFetch(`${API_BASE}?action=searchLocal&no_rm=${encodeURIComponent(rm)}`);
            if (res.success && res.data) {
                currentPatientResult = res.data;
                renderPatientDetails(res.data);
            } else {
                throw new Error(res.message || 'No patient record found locally.');
            }
        } else {
            let query = '';
            if (activeSearchTab === 'nik') {
                query = `&nik=${encodeURIComponent(document.getElementById('search-nik').value)}`;
            } else {
                query = `&nik_ibu=${encodeURIComponent(document.getElementById('search-nik-ibu').value)}&birthdate=${encodeURIComponent(document.getElementById('search-birthdate').value)}`;
            }

            const res = await apiFetch(`${API_BASE}?action=searchSatuSehat${query}`);
            if (res.success && res.data && res.data.entry && res.data.entry.length > 0) {
                currentPatientResult = res.data.entry[0].resource;
                renderPatientDetails(currentPatientResult);
            } else {
                errDiv.innerText = 'Patient not found in SatuSehat registry.';
                errDiv.style.display = 'block';
                createPrompt.style.display = 'block';
            }
        }
    } catch (err) {
        errDiv.innerText = err.message;
        errDiv.style.display = 'block';
    } fillOut: {
        loader.style.display = 'none';
    }
}

function renderPatientDetails(p) {
    const grid = document.getElementById('patient-details-grid');
    grid.innerHTML = '';

    const name = p.name ? (p.name[0]?.text || p.name[0]?.given?.join(' ') || 'N/A') : (p.nm_pasien || 'N/A');
    const nikVal = p.nik || (p.identifier ? p.identifier.find(x => x.system?.includes('nik'))?.value : 'N/A') || 'N/A';
    const gender = p.gender || (p.jk === 'L' ? 'male' : (p.jk === 'P' ? 'female' : 'unknown'));
    const dob = p.birthDate || p.tgl_lahir || 'N/A';
    const telecom = p.telecom ? p.telecom[0]?.value : (p.no_tlp || 'N/A');
    const address = p.address ? (p.address[0]?.line?.join(', ') || p.address[0]?.text) : (p.alamat || 'N/A');
    
    const ihsVal = p.id || 'N/A';

    const fields = [
        { label: 'Patient Name', value: name },
        { label: 'NIK / Resident ID', value: nikVal },
        { label: 'IHS Number', value: ihsVal },
        { label: 'Date of Birth', value: dob },
        { label: 'Gender', value: gender },
        { label: 'Telecom', value: telecom },
        { label: 'Address', value: address }
    ];

    fields.forEach(f => {
        const item = document.createElement('div');
        item.className = 'result-item';
        item.innerHTML = `<strong>${f.label}</strong><span>${f.value}</span>`;
        grid.appendChild(item);
    });

    document.getElementById('patient-results-card').style.display = 'block';
}

function showCurrentRawJson() {
    if (!currentPatientResult) return;
    showModal('FHIR Patient Resource Data', currentPatientResult);
}

function mapLocalToFHIR(localData) {
    return {
        resourceType: "Patient",
        meta: { profile: ["https://fhir.kemkes.go.id/r4/StructureDefinition/Patient"] },
        identifier: [
            {
                use: "official",
                system: "https://fhir.kemkes.go.id/id/nik",
                value: localData.nik || "0000000000000000"
            }
        ],
        active: true,
        name: [{
            use: "official",
            text: localData.nm_pasien || "N/A"
        }],
        telecom: [
            {
                system: "phone",
                value: localData.no_tlp || "",
                use: "mobile"
            }
        ],
        gender: localData.jk === 'L' ? 'male' : (localData.jk === 'P' ? 'female' : 'unknown'),
        birthDate: localData.tgl_lahir || "1900-01-01",
        address: [
            {
                use: "home",
                line: [localData.alamat || ""]
            }
        ]
    };
}

async function preparePatientCreate() {
    const errDiv = document.getElementById('search-error');
    const loader = document.getElementById('search-loader');
    loader.style.display = 'block';
    errDiv.style.display = 'none';

    try {
        let localPatient = null;
        const searchNik = activeSearchTab === 'nik' ? document.getElementById('search-nik').value : document.getElementById('search-nik-ibu').value;

        if (searchNik) {
            try {
                const data = await apiFetch(`${API_BASE}?action=searchLocal&nik=${encodeURIComponent(searchNik)}`);
                if (data.success && data.data) {
                    localPatient = data.data;
                }
            } catch (e) {
                console.warn("Patient not found locally for mapping initialization");
            }
        }

        let payloadObj = null;
        if (localPatient) {
            payloadObj = mapLocalToFHIR(localPatient);
        } else {
            payloadObj = {
                resourceType: "Patient",
                meta: { profile: ["https://fhir.kemkes.go.id/r4/StructureDefinition/Patient"] },
                identifier: [{ use: "official", system: "https://fhir.kemkes.go.id/id/nik", value: searchNik }],
                active: true,
                name: [{ use: "official", text: "" }],
                gender: "unknown",
                birthDate: document.getElementById('search-birthdate').value || ""
            };
        }

        showModal('Confirm Patient Creation Payload', payloadObj, async (editedText) => {
            try {
                const parsedObj = JSON.parse(editedText);
                loader.style.display = 'block';
                const res = await apiFetch(`${API_BASE}?action=createPatient`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(parsedObj)
                });
                if (res.success && res.data) {
                    currentPatientResult = res.data;
                    renderPatientDetails(res.data);
                    document.getElementById('patient-creation-prompt').style.display = 'none';
                } else {
                    throw new Error(res.message || 'Create Patient returned unsuccessful.');
                }
            } catch (err) {
                alert('Error: ' + err.message);
            } finally {
                loader.style.display = 'none';
            }
        });
    } catch (err) {
        errDiv.innerText = err.message;
        errDiv.style.display = 'block';
    } finally {
        loader.style.display = 'none';
    }
}

// ==========================================
// PANEL 3: SYNC CENTER
// ==========================================
function handleResourceChange(val) {
    selectedResource = val;
    const dateFilters = document.getElementById('sync-date-filters');
    if (val === 'workflow') {
        dateFilters.style.opacity = '0.3';
        dateFilters.style.pointerEvents = 'none';
    } else {
        dateFilters.style.opacity = '1';
        dateFilters.style.pointerEvents = 'auto';
    }

    syncStatsData = null;
    document.getElementById('sync-stats-cards').style.display = 'none';
    document.getElementById('sync-records-tbody').innerHTML = '';
    document.getElementById('sync-table-pagination').innerHTML = '';
}

function resetSyncDates() {
    document.getElementById('sync-date-from').value = '';
    document.getElementById('sync-date-to').value = '';
}

async function loadSyncStats() {
    const res = selectedResource;
    const noRawat = document.getElementById('sync-norawat-input').value;
    const dateFrom = document.getElementById('sync-date-from').value;
    const dateTo = document.getElementById('sync-date-to').value;

    let url = `${API_BASE}?action=getSyncStats&resource=${res}`;
    if (noRawat) url += `&no_rawat=${encodeURIComponent(noRawat)}`;
    if (dateFrom) url += `&dateFrom=${dateFrom}`;
    if (dateTo) url += `&dateTo=${dateTo}`;

    try {
        const data = await apiFetch(url);
        if (data.success && data.stats) {
            syncStatsData = data.stats;
            document.getElementById('stats-total').innerText = syncStatsData.total || 0;
            document.getElementById('stats-synced').innerText = syncStatsData.synced || 0;
            document.getElementById('stats-pending').innerText = syncStatsData.pending || 0;
            document.getElementById('stats-blocked').innerText = (syncStatsData.blocked || 0) + (syncStatsData.unmapped || 0) + (syncStatsData.unpaid || 0);
            document.getElementById('sync-stats-cards').style.display = 'grid';
            loadSyncRecords(1);
        }
    } catch (err) {
        alert('Stats Error: ' + err.message);
    }
}

async function loadSyncRecords(targetPage = 1) {
    syncRecordsPage = targetPage;
    const res = selectedResource;
    const dateFrom = document.getElementById('sync-date-from').value;
    const dateTo = document.getElementById('sync-date-to').value;
    const searchPatient = document.getElementById('sync-patient-search').value;
    const keywordSearch = document.getElementById('sync-keyword-search').value;
    const status = document.getElementById('sync-status-select').value;

    let url = `${API_BASE}?action=getPendingRecords&resource=${res}&page=${targetPage}&limit=10&status=${status}`;
    if (dateFrom) url += `&dateFrom=${dateFrom}`;
    if (dateTo) url += `&dateTo=${dateTo}`;
    if (searchPatient) url += `&search_patient=${encodeURIComponent(searchPatient)}`;
    if (keywordSearch) url += `&search=${encodeURIComponent(keywordSearch)}`;

    const tbody = document.getElementById('sync-records-tbody');
    tbody.innerHTML = '<tr><td colspan="5" style="text-align: center;">Loading records...</td></tr>';

    try {
        const data = await apiFetch(url);
        if (data.success) {
            tbody.innerHTML = '';
            const records = data.records || [];
            
            if (records.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: var(--text-muted);">No records found matching filters.</td></tr>';
                return;
            }

            records.forEach(r => {
                const tr = document.createElement('tr');
                let keyText = r.no_rawat || r.id;
                let metaBadges = '';
                if (r.noorder) metaBadges += `<span class="badge" style="background: rgba(255,255,255,0.05); color: #fff; margin-right: 0.25rem;">Order: ${r.noorder}</span>`;
                if (r.kd_jenis_prw) metaBadges += `<span class="badge" style="background: rgba(255,255,255,0.05); color: #fff; margin-right: 0.25rem;">Proc: ${r.kd_jenis_prw}</span>`;
                if (r.id_template) metaBadges += `<span class="badge" style="background: rgba(255,255,255,0.05); color: #fff;">Template: ${r.id_template}</span>`;

                const pDetails = `
                    <div><strong>${r.patient_name || 'N/A'}</strong></div>
                    <div style="font-size: 0.8rem; color: var(--text-muted)">RM: ${r.no_rkm_medis || 'N/A'} | NIK: ${r.nik || 'N/A'}</div>
                `;

                const sDetails = `
                    <div>${r.tgl_registrasi || r.tanggal || 'N/A'} ${r.jam_registrasi || ''}</div>
                    <div style="font-size: 0.8rem; color: var(--text-muted)">${r.nm_sps || r.nm_poli || 'N/A'}</div>
                `;

                let statusBadge = '';
                if (r.status === 'success' || r.satu_sehat_id) statusBadge = '<span class="badge success">Synced</span>';
                else if (r.status === 'failed') statusBadge = `<span class="badge danger" title="${r.message || ''}">Failed</span>`;
                else statusBadge = '<span class="badge warning">Pending</span>';

                const actionBtn = `
                    <div style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                        <button class="btn btn-secondary" style="width: auto; height: 32px; padding: 0 0.75rem; font-size: 0.8rem;" onclick="syncSingleRecord('${r.no_rawat || r.nik || r.id}')">Sync</button>
                        <button class="btn" style="width: auto; height: 32px; padding: 0 0.75rem; font-size: 0.8rem;" onclick="syncWorkflow('${r.no_rawat}')" ${!r.no_rawat ? 'disabled' : ''}>Flow</button>
                    </div>
                `;

                tr.innerHTML = `
                    <td style="font-family: monospace;">
                        <div>${keyText}</div>
                        <div style="margin-top: 0.25rem;">${metaBadges}</div>
                    </td>
                    <td>${pDetails}</td>
                    <td>${sDetails}</td>
                    <td>${statusBadge}</td>
                    <td style="text-align: right;">${actionBtn}</td>
                `;
                tbody.appendChild(tr);
            });

            renderSyncPagination(data.total_count, data.page);
        }
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="5" style="color: var(--danger); text-align: center;">Error: ${err.message}</td></tr>`;
    }
}

function handleSyncSearchKey(e) {
    if (e.key === 'Enter') {
        loadSyncRecords(1);
    }
}

function renderSyncPagination(total, currentPage) {
    const container = document.getElementById('sync-table-pagination');
    if (!container) return;
    container.innerHTML = '';
    
    const limit = 10;
    const totalPages = Math.ceil(total / limit);
    if (totalPages <= 1) return;

    container.innerHTML = `<span style="font-size: 0.8rem; color: var(--text-muted)">Found ${total} records.</span>`;
    
    const btnGroup = document.createElement('div');
    btnGroup.style.display = 'flex';
    btnGroup.style.gap = '0.5rem';

    const prevBtn = document.createElement('button');
    prevBtn.className = 'btn btn-secondary';
    prevBtn.style.padding = '0.2rem 0.6rem';
    prevBtn.style.fontSize = '0.8rem';
    prevBtn.style.width = 'auto';
    prevBtn.innerText = 'Prev';
    prevBtn.disabled = currentPage <= 1;
    prevBtn.onclick = () => loadSyncRecords(currentPage - 1);
    btnGroup.appendChild(prevBtn);

    const label = document.createElement('span');
    label.style.display = 'flex';
    label.style.alignItems = 'center';
    label.style.padding = '0 0.5rem';
    label.style.fontSize = '0.8rem';
    label.innerText = `Page ${currentPage} of ${totalPages}`;
    btnGroup.appendChild(label);

    const nextBtn = document.createElement('button');
    nextBtn.className = 'btn btn-secondary';
    nextBtn.style.padding = '0.2rem 0.6rem';
    nextBtn.style.fontSize = '0.8rem';
    nextBtn.style.width = 'auto';
    nextBtn.innerText = 'Next';
    nextBtn.disabled = currentPage >= totalPages;
    nextBtn.onclick = () => loadSyncRecords(currentPage + 1);
    btnGroup.appendChild(nextBtn);

    container.appendChild(btnGroup);
}

// Trigger Sync operations from table
async function syncSingleRecord(key) {
    appendConsole(`Triggering single sync for [${selectedResource}] matching key: ${key}...`, 'info');
    try {
        const res = await apiFetch(`${API_BASE}?action=triggerBatchSync&resource=${selectedResource}&no_rawat=${encodeURIComponent(key)}`, {
            method: 'POST'
        });
        if (res.success) {
            appendConsole(`SUCCESS: Synchronized single record successfully.`, 'success');
            loadSyncStats();
        } else {
            throw new Error(res.message || 'Sync failed.');
        }
    } catch (err) {
        appendConsole(`ERROR: ${err.message}`, 'error');
    }
}

async function syncWorkflow(noRawat) {
    if (!noRawat) return;
    appendConsole(`Triggering sequential clinical workflow sync for Rawat Record: ${noRawat}...`, 'info');
    try {
        const res = await apiFetch(`${API_BASE}?action=triggerBatchSync&resource=workflow&no_rawat=${encodeURIComponent(noRawat)}`, {
            method: 'POST'
        });
        if (res.success && res.workflow) {
            Object.keys(res.workflow).forEach(step => {
                const stepData = res.workflow[step];
                if (stepData.status === 'success' || stepData.status === 'already_mapped' || stepData.status === 'processed') {
                    appendConsole(`SUCCESS: Step [${step}] Completed. IHS: ${stepData.ihs || ''}`, 'success');
                } else {
                    appendConsole(`FAILED: Step [${step}] failed. Message: ${stepData.message || 'Error occurred'}`, 'error');
                }
            });
            loadSyncStats();
        } else {
            throw new Error(res.message || 'Workflow run failed.');
        }
    } catch (err) {
        appendConsole(`ERROR: ${err.message}`, 'error');
    }
}

function appendConsole(text, type) {
    const consoleBox = document.getElementById('sync-console');
    if (!consoleBox) return;
    consoleBox.style.display = 'flex';
    
    const line = document.createElement('div');
    line.className = 'log-line';
    line.innerHTML = `
        <span class="log-time">${new Date().toLocaleTimeString()}</span>
        <span class="log-text ${type}">${text}</span>
    `;
    consoleBox.appendChild(line);
    consoleBox.scrollTop = consoleBox.scrollHeight;
}

// Batch Synchronizer execution
async function startBatchSync() {
    cancelRequested = false;
    
    const btn = document.getElementById('sync-action-btn');
    const cancelBtn = document.getElementById('sync-cancel-btn');
    const progressContainer = document.getElementById('sync-progress-container');
    const progressFill = document.getElementById('sync-progress-fill');
    const progressText = document.getElementById('sync-progress-text');
    const countsContainer = document.getElementById('sync-counts');
    const successText = document.getElementById('sync-success-count');
    const failedText = document.getElementById('sync-failed-count');
    const consoleBox = document.getElementById('sync-console');

    btn.disabled = true;
    cancelBtn.style.display = 'inline-block';
    progressContainer.style.display = 'block';
    countsContainer.style.display = 'flex';
    consoleBox.innerHTML = '';
    
    let successCount = 0;
    let failedCount = 0;
    successText.innerText = '0';
    failedText.innerText = '0';

    const noRawat = document.getElementById('sync-norawat-input').value;
    const dateFrom = document.getElementById('sync-date-from').value;
    const dateTo = document.getElementById('sync-date-to').value;

    appendConsole(`Starting sync run for ${selectedResource}...`, 'info');

    if (selectedResource === 'workflow') {
        if (!noRawat) {
            appendConsole('ERROR: Target Single Visit (no_rawat) is strictly required for Sequential Workflow Sync.', 'error');
            btn.disabled = false;
            cancelBtn.style.display = 'none';
            return;
        }
        await syncWorkflow(noRawat);
        progressFill.style.width = '100%';
        progressText.innerText = '100%';
        btn.disabled = false;
        cancelBtn.style.display = 'none';
        return;
    }

    let pendingCount = syncStatsData?.pending ?? 0;
    if (noRawat) {
        pendingCount = syncStatsData?.pending ?? 1;
    }

    if (pendingCount === 0) {
        appendConsole('No pending records found to synchronize.', 'success');
        btn.disabled = false;
        cancelBtn.style.display = 'none';
        return;
    }

    let processed = 0;
    const batchLimit = 5;

    while (processed < pendingCount || noRawat) {
        if (cancelRequested) {
            appendConsole('Sync execution canceled by user request.', 'error');
            break;
        }

        try {
            let url = `${API_BASE}?action=triggerBatchSync&resource=${selectedResource}&limit=${batchLimit}`;
            if (noRawat) url += `&no_rawat=${encodeURIComponent(noRawat)}`;
            if (dateFrom) url += `&dateFrom=${dateFrom}`;
            if (dateTo) url += `&dateTo=${dateTo}`;

            const response = await apiFetch(url, { method: 'POST' });
            if (response.success) {
                const syncedList = response.synced || [];
                const summary = response.summary || {};
                let batchSuccess = 0;
                let batchFail = 0;

                if (selectedResource === 'patient') {
                    if (syncedList.length === 0) {
                        appendConsole('No more local patient records available for mapping.', 'info');
                        break;
                    }
                    syncedList.forEach(item => {
                        if (item.status === 'success') {
                            batchSuccess++;
                            appendConsole(`SUCCESS: Mapped Patient ${item.name} (RM: ${item.rm}) to IHS ${item.ihs}`, 'success');
                        } else {
                            batchFail++;
                            appendConsole(`FAILED: Patient ${item.name} (RM: ${item.rm}) - ${item.message || 'Not Found'}`, 'error');
                        }
                    });
                    processed += syncedList.length;
                } else {
                    const success = summary.success ?? 0;
                    const failed = summary.failed ?? 0;
                    batchSuccess += success;
                    batchFail += failed;

                    appendConsole(`Processed Batch: ${success} Synced Successfully, ${failed} Failed.`, success > 0 ? 'success' : 'info');
                    if (summary.errors && summary.errors.length > 0) {
                        summary.errors.forEach(err => appendConsole(`ERROR details: ${err}`, 'error'));
                    }
                    processed += batchLimit;
                    
                    if (success === 0 && failed === 0) {
                        appendConsole('Batch sync execution completed. No more pending items found.', 'success');
                        break;
                    }
                }

                successCount += batchSuccess;
                failedCount += batchFail;
                successText.innerText = successCount;
                failedText.innerText = failedCount;

                const pct = noRawat ? 100 : Math.min(100, Math.round((processed / pendingCount) * 100));
                progressFill.style.width = `${pct}%`;
                progressText.innerText = `${pct}%`;

                loadSyncStats();
                if (noRawat) break;
            } else {
                throw new Error(response.message || 'Batch Sync Request failed.');
            }
        } catch (err) {
            appendConsole(`ERROR: ${err.message}`, 'error');
            break;
        }

        // Add 600ms delay to prevent rate limits
        await new Promise(r => setTimeout(r, 600));
    }

    btn.disabled = false;
    cancelBtn.style.display = 'none';
}

function cancelBatchSync() {
    cancelRequested = true;
    appendConsole('Cancellation requested...', 'info');
}

// ==========================================
// PANEL 4: TROUBLESHOOT & MAPPING CENTER
// ==========================================
function switchMappingType(type) {
    selectedMappingType = type;
    
    const tabs = ['location', 'practitioner', 'medication', 'vaccine'];
    tabs.forEach(t => {
        const el = document.getElementById(`mapping-tab-${t}`);
        if (!el) return;
        if (t === type) {
            el.classList.add('active');
            el.style.background = 'rgba(168, 85, 247, 0.2)';
            el.style.borderColor = 'var(--primary-color)';
            el.style.color = '#fff';
        } else {
            el.classList.remove('active');
            el.style.background = 'rgba(255,255,255,0.05)';
            el.style.borderColor = 'rgba(255,255,255,0.1)';
            el.style.color = 'var(--text-muted)';
        }
    });

    const searchInput = document.getElementById('mapping-search-input');
    if (searchInput) searchInput.value = '';
    loadMappingList(1);
}

function handleMappingSearchKey(e) {
    if (e.key === 'Enter') {
        loadMappingList(1);
    }
}

async function loadMappingList(targetPage = 1) {
    mappingPage = targetPage;
    const loader = document.getElementById('mapping-loader');
    const tableContainer = document.getElementById('mapping-table-container');
    const tbody = document.getElementById('mapping-tbody');
    const pagination = document.getElementById('mapping-pagination');
    const searchVal = document.getElementById('mapping-search-input').value;

    if (loader) loader.style.display = 'block';
    if (tableContainer) tableContainer.style.opacity = '0.3';
    if (pagination) pagination.innerHTML = '';

    let url = `${API_BASE}?action=getUnmappedEntities&type=${selectedMappingType}&page=${targetPage}&limit=10`;
    if (searchVal) {
        url += `&search=${encodeURIComponent(searchVal)}`;
    }

    try {
        const res = await apiFetch(url);
        if (res.success) {
            tbody.innerHTML = '';
            const records = res.records || [];

            if (records.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="3" style="text-align: center; color: var(--text-muted); padding: 3rem;">
                            No unmapped ${selectedMappingType}s found matching filters. Everything is set up correctly!
                        </td>
                    </tr>
                `;
                return;
            }

            records.forEach(r => {
                const tr = document.createElement('tr');
                let placeholderText = '';
                if (selectedMappingType === 'location') placeholderText = 'Location UUID (e.g. 10002891)';
                else if (selectedMappingType === 'practitioner') placeholderText = 'Practitioner IHS ID';
                else if (selectedMappingType === 'medication') placeholderText = 'KFA Drug Code';
                else placeholderText = 'KFA Vaccine Code';

                const actionDiv = `
                    <div style="display: flex; gap: 0.5rem;">
                        <input type="text" id="map-input-${r.key}" class="form-control" placeholder="${placeholderText}" style="height: 34px; padding: 0 0.5rem; font-size: 0.85rem; background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.15)">
                        <button class="btn" onclick="saveMapping('${r.key}')" style="width: auto; height: 34px; padding: 0 0.85rem; font-size: 0.85rem;">Save</button>
                    </div>
                `;

                tr.innerHTML = `
                    <td style="font-family: monospace; color: var(--primary-color);">${r.key}</td>
                    <td>
                        <div style="font-weight: 500">${r.name}</div>
                        ${r.extra ? `<div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.25rem;">${r.extra}</div>` : ''}
                    </td>
                    <td>${actionDiv}</td>
                `;
                tbody.appendChild(tr);
            });

            renderMappingPagination(res.total_count, res.page);
            if (tableContainer) tableContainer.style.opacity = '1';
        }
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="3" style="color: var(--danger); text-align: center;">Error: ${err.message}</td></tr>`;
    } finally {
        if (loader) loader.style.display = 'none';
    }
}

function renderMappingPagination(total, currentPage) {
    const container = document.getElementById('mapping-pagination');
    if (!container) return;
    container.innerHTML = '';
    
    const limit = 10;
    const totalPages = Math.ceil(total / limit);
    if (totalPages <= 1) return;

    container.innerHTML = `<span style="font-size: 0.8rem; color: var(--text-muted)">Showing ${(currentPage-1)*10+1} - ${Math.min(currentPage*10, total)} of ${total} entries</span>`;
    
    const btnGroup = document.createElement('div');
    btnGroup.style.display = 'flex';
    btnGroup.style.gap = '0.5rem';

    const prevBtn = document.createElement('button');
    prevBtn.className = 'btn btn-secondary';
    prevBtn.style.padding = '0.2rem 0.6rem';
    prevBtn.style.fontSize = '0.8rem';
    prevBtn.style.width = 'auto';
    prevBtn.innerText = 'Prev';
    prevBtn.disabled = currentPage <= 1;
    prevBtn.onclick = () => loadMappingList(currentPage - 1);
    btnGroup.appendChild(prevBtn);

    const label = document.createElement('span');
    label.style.display = 'flex';
    label.style.alignItems = 'center';
    label.style.padding = '0 0.5rem';
    label.style.fontSize = '0.8rem';
    label.innerText = `Page ${currentPage} of ${totalPages}`;
    btnGroup.appendChild(label);

    const nextBtn = document.createElement('button');
    nextBtn.className = 'btn btn-secondary';
    nextBtn.style.padding = '0.2rem 0.6rem';
    nextBtn.style.fontSize = '0.8rem';
    nextBtn.style.width = 'auto';
    nextBtn.innerText = 'Next';
    nextBtn.disabled = currentPage >= totalPages;
    nextBtn.onclick = () => loadMappingList(currentPage + 1);
    btnGroup.appendChild(nextBtn);

    container.appendChild(btnGroup);
}

async function saveMapping(key) {
    const val = document.getElementById(`map-input-${key}`).value;
    if (!val || !val.trim()) {
        alert('Code/ID cannot be empty.');
        return;
    }

    try {
        const res = await apiFetch(`${API_BASE}?action=saveMapping`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ type: selectedMappingType, key, value: val })
        });
        if (res.success) {
            alert('Mapping saved successfully!');
            loadMappingList(mappingPage);
        } else {
            throw new Error(res.message || 'Mapping error');
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
}

// ==========================================
// PANEL 5: SYSTEM LOG VIEWER
// ==========================================
function handleLogsSearchKey(e) {
    if (e.key === 'Enter') {
        loadLogs(1);
    }
}

async function loadLogs(targetPage = 1) {
    logsPage = targetPage;
    const loader = document.getElementById('logs-loader');
    const listEl = document.getElementById('logs-list-element');
    const pagination = document.getElementById('logs-pagination');
    const errDiv = document.getElementById('logs-error');

    if (loader) loader.style.display = 'block';
    if (listEl) listEl.innerHTML = '';
    if (pagination) pagination.innerHTML = '';
    if (errDiv) errDiv.style.display = 'none';

    const date = document.getElementById('logs-date-input').value;
    const level = document.getElementById('logs-level-select').value;
    const search = document.getElementById('logs-search-input').value;

    const url = `${API_BASE}?action=getLogs&date=${date}&level=${level}&search=${encodeURIComponent(search)}&page=${targetPage}&limit=50`;

    try {
        const res = await apiFetch(url);
        if (res.success) {
            const logs = res.logs || [];
            if (logs.length === 0) {
                listEl.innerHTML = `<div style="text-align: center; padding: 3rem; color: var(--text-muted);">No log entries match the criteria for ${date}.</div>`;
                return;
            }

            logs.forEach((logItem, idx) => {
                const levelColors = { 'ERROR': '#f38ba8', 'WARNING': '#f9e2af', 'INFO': '#89b4fa', 'DEBUG': '#a6adc8' };
                const levelBgColors = { 'ERROR': 'rgba(243, 139, 168, 0.1)', 'WARNING': 'rgba(249, 226, 175, 0.1)', 'INFO': 'rgba(137, 180, 250, 0.1)', 'DEBUG': 'rgba(166, 173, 200, 0.1)' };
                
                const color = levelColors[logItem.level] || '#cdd6f4';
                const bgColor = levelBgColors[logItem.level] || 'rgba(255,255,255,0.05)';

                const logRow = document.createElement('div');
                logRow.className = 'accordion-item';

                const headerId = `log-header-${idx}`;
                const bodyId = `log-body-${idx}`;

                logRow.innerHTML = `
                    <div class="accordion-header" id="${headerId}" onclick="toggleLogAccordion(${idx})">
                        <div style="color: var(--text-muted); font-family: monospace;">${logItem.timestamp}</div>
                        <div>
                            <span style="background-color: ${bgColor}; color: ${color}; padding: 0.15rem 0.5rem; border-radius: 4px; font-size: 0.7rem; font-weight: bold; display: inline-block; text-align: center; width: 70px;">
                                ${logItem.level}
                            </span>
                        </div>
                        <div style="color: #cdd6f4; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; padding-right: 1rem; font-family: monospace;">
                            ${logItem.message}
                            ${logItem.payload ? `<span style="color: var(--primary-color); margin-left: 0.5rem; font-size: 0.75rem;">[inspect payload]</span>` : ''}
                        </div>
                    </div>
                    <div class="accordion-body" id="${bodyId}" style="display: none;">
                        <div style="margin-top: 0.75rem; color: #cdd6f4; line-height: 1.5;">
                            <strong>Message:</strong> <span style="font-family: monospace;">${logItem.message}</span>
                        </div>
                        ${logItem.payload ? `
                            <div style="margin-top: 0.75rem;">
                                <strong style="color: var(--primary-color)">FHIR / API Transaction Payload:</strong>
                                <pre style="margin-top: 0.5rem; padding: 1rem; background: #1e1e2e; color: #cdd6f4; border-radius: 6px; overflow-x: auto; max-height: 300px; font-size: 0.75rem; font-family: monospace;">${JSON.stringify(logItem.payload, null, 2)}</pre>
                            </div>
                        ` : ''}
                    </div>
                `;
                listEl.appendChild(logRow);
            });

            renderLogsPagination(res.total_count, res.page);
        } else {
            throw new Error(res.message || 'Log request returned failed.');
        }
    } catch (err) {
        if (errDiv) {
            errDiv.innerText = err.message;
            errDiv.style.display = 'block';
        }
    } finally {
        if (loader) loader.style.display = 'none';
    }
}

function toggleLogAccordion(idx) {
    const body = document.getElementById(`log-body-${idx}`);
    const header = document.getElementById(`log-header-${idx}`);
    if (!body || !header) return;
    if (body.style.display === 'none') {
        body.style.display = 'block';
        header.style.background = 'rgba(255,255,255,0.02)';
    } else {
        body.style.display = 'none';
        header.style.background = 'transparent';
    }
}

function renderLogsPagination(total, currentPage) {
    const container = document.getElementById('logs-pagination');
    if (!container) return;
    container.innerHTML = '';
    
    const limit = 50;
    const totalPages = Math.ceil(total / limit);
    if (totalPages <= 1) return;

    container.innerHTML = `<span style="font-size: 0.8rem; color: var(--text-muted)">Found ${total} log entries.</span>`;
    
    const btnGroup = document.createElement('div');
    btnGroup.style.display = 'flex';
    btnGroup.style.gap = '0.5rem';

    const prevBtn = document.createElement('button');
    prevBtn.className = 'btn btn-secondary';
    prevBtn.style.padding = '0.2rem 0.6rem';
    prevBtn.style.fontSize = '0.8rem';
    prevBtn.style.width = 'auto';
    prevBtn.innerText = 'Prev';
    prevBtn.disabled = currentPage <= 1;
    prevBtn.onclick = () => loadLogs(currentPage - 1);
    btnGroup.appendChild(prevBtn);

    const label = document.createElement('span');
    label.style.display = 'flex';
    label.style.alignItems = 'center';
    label.style.padding = '0 0.5rem';
    label.style.fontSize = '0.8rem';
    label.innerText = `Page ${currentPage} of ${totalPages}`;
    btnGroup.appendChild(label);

    const nextBtn = document.createElement('button');
    nextBtn.className = 'btn btn-secondary';
    nextBtn.style.padding = '0.2rem 0.6rem';
    nextBtn.style.fontSize = '0.8rem';
    nextBtn.style.width = 'auto';
    nextBtn.innerText = 'Next';
    nextBtn.disabled = currentPage >= totalPages;
    nextBtn.onclick = () => loadLogs(currentPage + 1);
    btnGroup.appendChild(nextBtn);

    container.appendChild(btnGroup);
}

// ==========================================
// MODALS OVERLAY DIALOG CONTROLS
// ==========================================
function showModal(title, jsonPayload, saveCallback = null) {
    const overlay = document.getElementById('modal-container');
    const titleEl = document.getElementById('modal-title');
    const bodyEl = document.getElementById('modal-body');
    const actionsEl = document.getElementById('modal-actions');

    titleEl.innerText = title;
    overlay.style.display = 'flex';

    if (saveCallback) {
        bodyEl.innerHTML = `<textarea id="modal-textarea" style="width: 100%; height: 250px; background: #1e1e2e; color: #fff; border: 1px solid var(--border-color); font-family: monospace; font-size: 0.8rem; padding: 0.5rem;">${JSON.stringify(jsonPayload, null, 2)}</textarea>`;
        actionsEl.innerHTML = `
            <button class="btn btn-secondary" style="width: auto; margin-right: 0.5rem;" onclick="closeModal()">Cancel</button>
            <button class="btn" style="width: auto;" id="modal-save-btn">Confirm creation</button>
        `;
        document.getElementById('modal-save-btn').onclick = () => {
            const text = document.getElementById('modal-textarea').value;
            saveCallback(text);
            closeModal();
        };
    } else {
        bodyEl.innerText = JSON.stringify(jsonPayload, null, 2);
        actionsEl.innerHTML = `<button class="btn btn-secondary" style="width: auto;" onclick="closeModal()">Close</button>`;
    }
}

function closeModal() {
    document.getElementById('modal-container').style.display = 'none';
}
