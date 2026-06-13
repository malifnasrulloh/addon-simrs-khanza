<?php
// SatuSehat Portal - Native PHP Frontend Loader
// Zero-build, completely offline-compatible SPA entrypoint.
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SatuSehat Integration Portal</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container" id="app">
        <!-- Auth View Container -->
        <div id="auth-view" class="auth-container" style="display: none;">
            <div class="glass auth-card">
                <h2>SatuSehat Portal</h2>
                <div style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1.5rem;">Access Integration Diagnostics & Controls</div>
                <form id="login-form">
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" id="username" class="form-control" placeholder="Enter username" required autocomplete="username">
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" id="password" class="form-control" placeholder="Enter password" required autocomplete="current-password">
                    </div>
                    <div style="margin-top: 1.5rem;">
                        <button type="submit" id="login-btn" class="btn">Sign In</button>
                    </div>
                </form>
                <div id="login-error" class="error-msg" style="display: none; margin-top: 1rem;"></div>
            </div>
        </div>

        <!-- Main Dashboard View Container -->
        <div id="main-view" style="display: none;">
            <!-- Top Header -->
            <div class="glass header">
                <h1>SatuSehat Integration Portal</h1>
                <div class="user-info">
                    <span class="user-meta">Logged in as: <strong id="display-user">Admin</strong> (<span id="display-role">user</span>)</span>
                    <button class="logout-btn" onclick="handleLogout()">Logout</button>
                </div>
            </div>

            <!-- System Connection Diagnostics Panel -->
            <div class="glass diagnostics-panel" id="dashboard-diagnostics" style="display: none; cursor: pointer;" onclick="showDbStructureDetails()" title="Click to open DB Structure Analyzer details">
                <div class="diag-item">
                    <div class="diag-dot" id="diag-db-dot"></div>
                    <div class="diag-info">
                        <span class="diag-name">MySQL Database</span>
                        <span class="diag-msg" id="diag-db-msg">Checking...</span>
                    </div>
                </div>
                <div class="diag-item">
                    <div class="diag-dot" id="diag-sqlite-dot"></div>
                    <div class="diag-info">
                        <span class="diag-name">SQLite State DB</span>
                        <span class="diag-msg" id="diag-sqlite-msg">Checking...</span>
                    </div>
                </div>
                <div class="diag-item">
                    <div class="diag-dot" id="diag-ss-dot"></div>
                    <div class="diag-info">
                        <span class="diag-name">SatuSehat API</span>
                        <span class="diag-msg" id="diag-ss-msg">Checking...</span>
                    </div>
                </div>
                <div class="diag-item">
                    <div class="diag-dot" id="diag-pacs-dot"></div>
                    <div class="diag-info">
                        <span class="diag-name">Orthanc PACS</span>
                        <span class="diag-msg" id="diag-pacs-msg">Checking...</span>
                    </div>
                </div>
            </div>

            <!-- Top Navigation Tab Bar -->
            <div class="glass nav-tabs" id="main-nav">
                <!-- Tabs will be dynamically updated by Javascript controller based on user authorization roles -->
            </div>

            <!-- Panel 1: Analytics -->
            <div id="section-analytics" class="glass section-panel">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                    <h2 style="color: var(--primary-color);">📈 Analytics & Trends</h2>
                    <button class="btn" style="width: auto; padding: 0.5rem 1rem; font-size: 0.85rem;" onclick="loadAnalytics()">🔄 Refresh Stats</button>
                </div>
                <div id="analytics-error" class="error-msg" style="display: none; margin-bottom: 1rem;"></div>
                <div id="analytics-loader" style="text-align: center; padding: 3rem; display: none;">
                    <div class="spinner" style="margin: 0 auto 1rem auto;"></div>
                    <p style="color: var(--text-muted)">Compiling synchronization analytics...</p>
                </div>
                <div id="analytics-content" class="chart-container">
                    <div class="glass" style="padding: 1.5rem; background: rgba(255,255,255,0.02)">
                        <h3 style="margin-bottom: 1.5rem;">Daily Encounter Sync Trends (Last 7 Days)</h3>
                        <div class="chart-grid" id="chart-grid"></div>
                        <div style="display: flex; gap: 1.5rem; justify-content: center; font-size: 0.8rem; margin-top: 1rem;">
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <div style="width: 12px; height: 12px; background-color: rgba(74, 222, 128, 0.85); border-radius: 3px;"></div>
                                <span>Synced Visits</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <div style="width: 12px; height: 12px; background-color: rgba(248, 113, 113, 0.85); border-radius: 3px;"></div>
                                <span>Pending / Failed Visits</span>
                            </div>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 1.5rem; margin-top: 1.5rem;">
                        <div class="glass" style="padding: 1.5rem; background: rgba(255,255,255,0.02)">
                            <h3 style="margin-bottom: 1rem;">Top Common API Sync Errors (Last 3 Days)</h3>
                            <div id="top-errors-list" style="display: flex; flex-direction: column; gap: 1rem;"></div>
                        </div>
                        <div class="glass" style="padding: 1.5rem; background: rgba(255,255,255,0.02)">
                            <h3 style="margin-bottom: 1rem;">Resource Coverage Rates (Last 7 Days)</h3>
                            <div id="coverage-rates-list" style="display: flex; flex-direction: column; gap: 0.85rem; max-height: 250px; overflow-y: auto; padding-right: 0.5rem;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Panel 2: Patient Search -->
            <div id="section-patient_search" class="glass section-panel">
                <div class="sub-tabs">
                    <button class="sub-tab active" onclick="switchSearchTab('rm')">No Rekam Medis</button>
                    <button class="sub-tab" onclick="switchSearchTab('nik')">NIK</button>
                    <button class="sub-tab" onclick="switchSearchTab('nik_ibu')">NIK Ibu (Bayi)</button>
                </div>
                <form id="search-form">
                    <div id="search-group-rm" class="form-group">
                        <label>No. Rekam Medis</label>
                        <input type="text" id="search-rm" class="form-control" placeholder="e.g. 000001">
                    </div>
                    <div id="search-group-nik" class="form-group" style="display: none;">
                        <label>NIK</label>
                        <input type="text" id="search-nik" class="form-control" placeholder="e.g. 3173000000000001">
                    </div>
                    <div id="search-group-nik_ibu" style="display: none;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div class="form-group">
                                <label>NIK Ibu</label>
                                <input type="text" id="search-nik-ibu" class="form-control" placeholder="e.g. 3173000000000002">
                            </div>
                            <div class="form-group">
                                <label>Tanggal Lahir Bayi</label>
                                <input type="date" id="search-birthdate" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div style="margin-top: 1rem;">
                        <button type="submit" id="search-btn" class="btn" style="width: auto;">Search Patient</button>
                    </div>
                </form>
                <div id="search-error" class="error-msg" style="display: none; margin-top: 1rem;"></div>
                <div id="search-loader" style="text-align: center; padding: 2rem; display: none;">
                    <div class="spinner" style="margin: 0 auto;"></div>
                </div>
                <div id="patient-creation-prompt" style="display: none; margin-top: 1rem; padding: 1rem; background: rgba(239, 68, 68, 0.1); border-radius: 8px;">
                    <p style="margin-bottom: 1rem;">This patient does not exist in Satu Sehat.</p>
                    <button class="btn btn-secondary" style="width: auto;" onclick="preparePatientCreate()">Prepare Patient Creation Payload</button>
                </div>
            </div>

            <!-- Patient Results Display Card (shared, visible below patient search) -->
            <div id="patient-results-card" class="glass result-card" style="display: none; margin-top: 1.5rem;">
                <h2 style="margin-top: 0; margin-bottom: 1.5rem; color: var(--primary-color);">Patient Details</h2>
                <div class="result-grid" id="patient-details-grid"></div>
                <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                    <button class="btn btn-secondary" style="width: auto;" onclick="showCurrentRawJson()">View Raw FHIR JSON</button>
                </div>
            </div>

            <!-- Panel 3: Sync Center -->
            <div id="section-sync" class="glass section-panel">
                <h2 style="color: var(--primary-color); margin-bottom: 0.5rem;">Clinical Resource Synchronization</h2>
                <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1.5rem;">Orchestrate batch transfers or execute sequence-based workflows directly into SatuSehat.</p>

                <div style="display: grid; grid-template-columns: 1.5fr 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div class="form-group">
                        <label>Select Target Resource / Flow</label>
                        <select id="sync-resource-select" class="form-control" onchange="handleResourceChange(this.value)">
                            <optgroup label="🏥 Core Visit & Demographics" style="background: #110e1a; color: #ffb3c1;">
                                <option value="patient">Patient (NIK -> IHS)</option>
                                <option value="encounter">Encounter (Visit Registration)</option>
                                <option value="episodeofcare">Episode Of Care</option>
                                <option value="condition">Condition (Diagnosis)</option>
                                <option value="observationttv">Observation TTV (Vitals)</option>
                                <option value="procedure">Procedure</option>
                                <option value="allergyintolerance">Allergy Intolerance</option>
                                <option value="immunization">Immunization</option>
                            </optgroup>
                            <optgroup label="💊 Medication Services" style="background: #110e1a; color: #ffb3c1;">
                                <option value="medication">Medication (Drug Catalog)</option>
                                <option value="medicationrequest">Medication Request (Prescription)</option>
                                <option value="medicationdispense">Medication Dispense</option>
                                <option value="medicationstatement">Medication Statement</option>
                            </optgroup>
                            <optgroup label="🔬 Laboratory Services (PK & MB)" style="background: #110e1a; color: #ffb3c1;">
                                <option value="servicerequest_lab_pk">Lab PK Service Request</option>
                                <option value="specimen_lab_pk">Lab PK Specimen</option>
                                <option value="observation_lab_pk">Lab PK Observation</option>
                                <option value="diagnosticreport_lab_pk">Lab PK Diagnostic Report</option>
                                <option value="servicerequest_lab_mb">Lab MB Service Request</option>
                                <option value="specimen_lab_mb">Lab MB Specimen</option>
                                <option value="observation_lab_mb">Lab MB Observation</option>
                                <option value="diagnosticreport_lab_mb">Lab MB Diagnostic Report</option>
                            </optgroup>
                            <optgroup label="🩻 Radiology & Clinical Impression" style="background: #110e1a; color: #ffb3c1;">
                                <option value="clinicalimpression">Clinical Impression</option>
                                <option value="servicerequest_rad">Radiology Service Request</option>
                                <option value="specimen_rad">Radiology Specimen</option>
                                <option value="observation_rad">Radiology Observation</option>
                                <option value="diagnosticreport_rad">Radiology Diagnostic Report</option>
                            </optgroup>
                            <optgroup label="🔥 Advanced Workflow" style="background: #110e1a; color: #ffb3c1;">
                                <option value="workflow">Sequential Workflow Sync (Encounter Sequence)</option>
                            </optgroup>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Target Single Visit (no_rawat)</label>
                        <input type="text" id="sync-norawat-input" class="form-control" placeholder="e.g. 2026/05/25/000001">
                    </div>
                    <div class="form-group" style="display: flex; align-items: flex-end;">
                        <button class="btn btn-secondary" onclick="loadSyncStats()" style="height: 42px;">Load Stats & Lists</button>
                    </div>
                </div>

                <div id="sync-date-filters" style="display: grid; grid-template-columns: 1.2fr 1.2fr 1fr; gap: 1rem; margin-bottom: 1.5rem; background: rgba(255,255,255,0.02); padding: 1rem; border-radius: 8px; border: 1px solid var(--border-color);">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label>Date From</label>
                        <input type="date" id="sync-date-from" class="form-control">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label>Date To</label>
                        <input type="date" id="sync-date-to" class="form-control">
                    </div>
                    <div style="display: flex; align-items: flex-end;">
                        <button class="btn btn-secondary" onclick="resetSyncDates()" style="height: 42px;">Reset Dates</button>
                    </div>
                </div>

                <!-- Sync Statistics Summary -->
                <div class="sync-stats-grid" id="sync-stats-cards" style="display: none;">
                    <div class="sync-stat-card" style="background: rgba(255, 255, 255, 0.05);">
                        <div class="sync-stat-title">TOTAL RECORDS</div>
                        <div class="sync-stat-value" id="stats-total" style="color: #cdd6f4;">0</div>
                    </div>
                    <div class="sync-stat-card" style="background: rgba(74, 222, 128, 0.08); border-color: rgba(74, 222, 128, 0.2)">
                        <div class="sync-stat-title">SYNCED</div>
                        <div class="sync-stat-value" id="stats-synced" style="color: #4ade80;">0</div>
                    </div>
                    <div class="sync-stat-card" style="background: rgba(251, 191, 36, 0.08); border-color: rgba(251, 191, 36, 0.2)">
                        <div class="sync-stat-title">PENDING</div>
                        <div class="sync-stat-value" id="stats-pending" style="color: #fbbf24;">0</div>
                    </div>
                    <div class="sync-stat-card" style="background: rgba(248, 113, 113, 0.08); border-color: rgba(248, 113, 113, 0.2)">
                        <div class="sync-stat-title">BLOCKED</div>
                        <div class="sync-stat-value" id="stats-blocked" style="color: #f87171;">0</div>
                    </div>
                </div>

                <!-- Control Buttons -->
                <div style="display: flex; gap: 1rem; margin-bottom: 1.5rem; align-items: center;">
                    <button id="sync-action-btn" class="btn" style="width: auto;" onclick="startBatchSync()">Sync Filtered Batch</button>
                    <button id="sync-cancel-btn" class="btn btn-danger" style="width: auto; display: none;" onclick="cancelBatchSync()">Cancel Sync</button>
                    <div id="sync-counts" style="display: none; gap: 1rem; font-size: 0.9rem; color: var(--text-muted);">
                        <span>Success: <strong id="sync-success-count" style="color: #4ade80">0</strong></span>
                        <span>Failed: <strong id="sync-failed-count" style="color: #f87171">0</strong></span>
                    </div>
                </div>

                <!-- Progress Bar -->
                <div class="progress-container" id="sync-progress-container" style="display: none;">
                    <div style="display: flex; justify-content: space-between; font-size: 0.8rem; margin-bottom: 0.25rem;">
                        <span>Synchronization Progress</span>
                        <span id="sync-progress-text">0%</span>
                    </div>
                    <div class="progress-bar-bg">
                        <div class="progress-bar-fill" id="sync-progress-fill"></div>
                    </div>
                </div>

                <!-- Sync Console Logs -->
                <div class="log-console" id="sync-console" style="display: none;"></div>

                <!-- Interactive Records List -->
                <div id="sync-records-table-container" style="margin-top: 2rem;">
                    <h3 style="margin-bottom: 1rem;">Pending clinical record transactions</h3>
                    <!-- Search patient / keyword inputs -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr 120px 100px; gap: 0.5rem; margin-bottom: 1rem;">
                        <input type="text" id="sync-patient-search" class="form-control" placeholder="Search Patient (RM, NIK, Name)..." onkeydown="handleSyncSearchKey(event)">
                        <input type="text" id="sync-keyword-search" class="form-control" placeholder="Search keys/status keywords..." onkeydown="handleSyncSearchKey(event)">
                        <select id="sync-status-select" class="form-control" onchange="loadSyncRecords(1)">
                            <option value="all">All Status</option>
                            <option value="pending">Pending</option>
                            <option value="success">Success</option>
                            <option value="failed">Failed</option>
                        </select>
                        <button class="btn" onclick="loadSyncRecords(1)">Filter</button>
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Transaction Key</th>
                                    <th>Patient Details</th>
                                    <th>Service/Dates</th>
                                    <th>Status</th>
                                    <th style="text-align: right;">Action</th>
                                </tr>
                            </thead>
                            <tbody id="sync-records-tbody"></tbody>
                        </table>
                    </div>
                    <div id="sync-table-pagination" style="display: flex; justify-content: space-between; align-items: center; margin-top: 1rem;"></div>
                </div>
            </div>

            <!-- Panel 4: Mapping Center -->
            <div id="section-troubleshoot" class="glass section-panel">
                <h2 style="color: var(--primary-color); margin-bottom: 0.5rem;">Troubleshooting & Mapping Center</h2>
                <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1.5rem;">Identify missing master data mappings that block synchronization pipelines. Update matching IDs directly.</p>

                <div style="display: grid; grid-template-columns: 1.2fr 3fr; gap: 1.5rem; min-height: 400px;">
                    <!-- Left Sidebar tabs -->
                    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                        <button class="nav-tab active" id="mapping-tab-location" onclick="switchMappingType('location')" style="width: 100%; text-align: left; border-radius: 8px;">Clinics / Locations</button>
                        <button class="nav-tab" id="mapping-tab-practitioner" onclick="switchMappingType('practitioner')" style="width: 100%; text-align: left; border-radius: 8px;">Practitioners / Doctors</button>
                        <button class="nav-tab" id="mapping-tab-medication" onclick="switchMappingType('medication')" style="width: 100%; text-align: left; border-radius: 8px;">Medications / Drugs</button>
                        <button class="nav-tab" id="mapping-tab-vaccine" onclick="switchMappingType('vaccine')" style="width: 100%; text-align: left; border-radius: 8px;">Vaccines / Immunizations</button>
                    </div>
                    <!-- Right content grid -->
                    <div class="glass" style="padding: 1.25rem; display: flex; flex-direction: column; gap: 1rem; background: rgba(255,255,255,0.02); border-color: rgba(255,255,255,0.05);">
                        <div style="display: flex; gap: 1rem;">
                            <input type="text" id="mapping-search-input" class="form-control" placeholder="Search unmapped elements..." onkeydown="handleMappingSearchKey(event)">
                            <button class="btn" style="width: auto;" onclick="loadMappingList(1)">Search</button>
                        </div>
                        <div id="mapping-loader" style="text-align: center; padding: 2rem; display: none;">
                            <div class="spinner" style="margin: 0 auto;"></div>
                        </div>
                        <div class="table-responsive" id="mapping-table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Local Key</th>
                                        <th>Name / Details</th>
                                        <th>SatuSehat Identifier / Code</th>
                                    </tr>
                                </thead>
                                <tbody id="mapping-tbody"></tbody>
                            </table>
                        </div>
                        <div id="mapping-pagination" style="display: flex; justify-content: space-between; align-items: center; margin-top: auto; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 1rem;"></div>
                    </div>
                </div>
            </div>

            <!-- Panel 5: Log Viewer -->
            <div id="section-logs" class="glass section-panel">
                <h2 style="color: var(--primary-color); margin-bottom: 1.5rem;">📋 System Log Viewer</h2>
                <div style="display: grid; grid-template-columns: 150px 150px 1.5fr 120px; gap: 0.75rem; margin-bottom: 1.5rem;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <input type="date" id="logs-date-input" class="form-control" style="height: 38px;">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <select id="logs-level-select" class="form-control" style="height: 38px;">
                            <option value="all">All Levels</option>
                            <option value="ERROR">ERROR</option>
                            <option value="WARNING">WARNING</option>
                            <option value="INFO">INFO</option>
                            <option value="DEBUG">DEBUG</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <input type="text" id="logs-search-input" class="form-control" placeholder="Search logs (e.g. Encounter, NIK)..." style="height: 38px;" onkeydown="handleLogsSearchKey(event)">
                    </div>
                    <button class="btn" style="height: 38px;" onclick="loadLogs(1)">Apply</button>
                </div>
                <div id="logs-error" class="error-msg" style="display: none; margin-bottom: 1.5rem;"></div>
                <div id="logs-loader" style="text-align: center; padding: 3rem; display: none;">
                    <div class="spinner" style="margin: 0 auto 1rem auto;"></div>
                    <p style="color: var(--text-muted)">Retrieving transactions log from disk...</p>
                </div>
                <div id="logs-container" style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <div style="max-height: 500px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 8px;" id="logs-list-element"></div>
                    <div id="logs-pagination" style="display: flex; justify-content: space-between; align-items: center; margin-top: 1rem;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Dialogs overlay -->
    <div id="modal-container" class="modal-overlay" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title" id="modal-title">FHIR JSON Output</div>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <pre id="modal-body" style="background: #090810; color: #fff; padding: 1.5rem; border-radius: 8px; overflow-x: auto; max-height: 50vh; font-family: monospace; font-size: 0.8rem; text-align: left;"></pre>
            <div id="modal-actions" style="margin-top: 1.5rem; text-align: right;">
                <button class="btn btn-secondary" style="width: auto;" onclick="closeModal()">Close</button>
            </div>
        </div>
    </div>

    <script src="js/app.js"></script>
</body>
</html>
