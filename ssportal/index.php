<?php
// SatuSehat Portal - Native PHP Frontend
// Zero-build, completely offline-compatible SPA utilizing vanilla HTML, CSS, and JS.
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SatuSehat Integration Portal</title>
    <style>
        :root {
            --primary-color: #a855f7;
            --primary-hover: #9333ea;
            --bg-color: #0b0a16;
            --card-bg: rgba(22, 18, 38, 0.65);
            --text-main: #f3f4f6;
            --text-muted: #a78bfa;
            --border-color: rgba(168, 85, 247, 0.2);
            --success: #10b981;
            --danger: #f43f5e;
            --warning: #fbbf24;
            --info: #3b82f6;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg-color);
            background-image: radial-gradient(circle at top right, rgba(168, 85, 247, 0.1), transparent 40%), radial-gradient(circle at bottom left, rgba(244, 63, 94, 0.06), transparent 40%);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            padding-bottom: 3rem;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 2rem;
            width: 100%;
        }

        /* Glassmorphism Styles */
        .glass {
            background: var(--card-bg);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.37);
        }

        /* Top Header */
        .header {
            padding: 1.5rem 2rem;
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            font-size: 1.6rem;
            font-weight: 700;
            background: linear-gradient(135deg, #c084fc, #a855f7);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .user-meta {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .logout-btn {
            background: transparent;
            border: 1px solid rgba(244, 63, 94, 0.3);
            color: var(--danger);
            font-weight: 600;
            padding: 0.4rem 0.8rem;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.85rem;
        }

        .logout-btn:hover {
            background: rgba(244, 63, 94, 0.1);
            border-color: var(--danger);
        }

        /* Auth Card */
        .auth-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 80vh;
        }

        .auth-card {
            width: 100%;
            max-width: 400px;
            padding: 2.5rem;
            text-align: center;
        }

        .auth-card h2 {
            margin-bottom: 1.5rem;
            font-size: 1.6rem;
            color: var(--primary-color);
        }

        /* Navigation Menu Tabs */
        .nav-tabs {
            display: flex;
            gap: 0.5rem;
            padding: 0.75rem;
            margin-bottom: 2rem;
            overflow-x: auto;
        }

        .nav-tab {
            padding: 0.6rem 1.2rem;
            border: none;
            background: transparent;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            border-radius: 8px;
            transition: all 0.2s;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            border: 1px solid transparent;
        }

        .nav-tab:hover {
            color: #fff;
            background: rgba(255, 255, 255, 0.05);
        }

        .nav-tab.active {
            color: #fff;
            background: rgba(168, 85, 247, 0.2);
            border-color: var(--primary-color);
        }

        /* Sections Grid */
        .section-panel {
            display: none;
            animation: fadeIn 0.3s ease-out;
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .section-panel.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Forms */
        .form-group {
            margin-bottom: 1.25rem;
            text-align: left;
        }

        .form-group label {
            display: block;
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: rgba(22, 18, 38, 0.85);
            color: #fff;
            font-family: inherit;
            font-size: 0.95rem;
            transition: all 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(168, 85, 247, 0.25);
        }

        /* Buttons */
        .btn {
            display: inline-block;
            width: 100%;
            padding: 0.75rem 1.25rem;
            font-size: 0.95rem;
            font-weight: 600;
            color: #fff;
            background-color: var(--primary-color);
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
        }

        .btn:hover:not(:disabled) {
            background-color: var(--primary-hover);
        }

        .btn:active:not(:disabled) {
            transform: translateY(1px);
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-secondary {
            background-color: transparent;
            color: var(--primary-color);
            border: 1px solid var(--primary-color);
        }

        .btn-secondary:hover:not(:disabled) {
            background-color: var(--primary-color);
            color: #fff;
        }

        .btn-danger {
            background-color: var(--danger);
        }

        /* Error/Status Alerts */
        .error-msg {
            color: var(--danger);
            font-size: 0.9rem;
            margin-top: 0.5rem;
            padding: 0.75rem;
            background: rgba(244, 63, 94, 0.1);
            border-radius: 6px;
            border-left: 3px solid var(--danger);
        }

        /* Patient Search Card */
        .sub-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 0.5rem;
        }

        .sub-tab {
            padding: 0.4rem 0.8rem;
            border: none;
            background: transparent;
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--text-muted);
            cursor: pointer;
            border-radius: 4px;
        }

        .sub-tab.active {
            background: rgba(255, 255, 255, 0.05);
            color: #fff;
        }

        /* Patient Details Result Card */
        .result-card {
            padding: 2rem;
            margin-bottom: 2rem;
            animation: fadeIn 0.3s ease-out;
        }

        .result-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .result-item strong {
            display: block;
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .result-item span {
            font-size: 0.95rem;
            color: #e5e7eb;
        }

        /* Sync Center layout */
        .sync-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .sync-stat-card {
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        .sync-stat-title {
            font-size: 0.75rem;
            color: var(--text-muted);
            font-weight: bold;
            margin-bottom: 0.25rem;
        }

        .sync-stat-value {
            font-size: 1.4rem;
            font-weight: bold;
        }

        /* Progress Bar */
        .progress-container {
            margin-top: 1rem;
            margin-bottom: 1rem;
        }

        .progress-bar-bg {
            height: 10px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 5px;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #c084fc, #a855f7);
            width: 0%;
            transition: width 0.3s ease;
        }

        /* Logging Console */
        .log-console {
            background: #090810;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            max-height: 250px;
            overflow-y: auto;
            font-family: monospace;
            font-size: 0.8rem;
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }

        .log-line {
            display: flex;
            gap: 0.75rem;
        }

        .log-time {
            color: var(--text-muted);
        }

        .log-text.info { color: #89b4fa; }
        .log-text.success { color: #a6e3a1; }
        .log-text.error { color: #f38ba8; }

        /* Dynamic Chart styles */
        .chart-container {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .chart-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 1rem;
            height: 200px;
            align-items: end;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 0.5rem;
        }

        .chart-column {
            display: flex;
            flex-direction: column;
            height: 100%;
            justify-content: flex-end;
            position: relative;
        }

        .chart-bar-container {
            display: flex;
            height: 100%;
            align-items: end;
            gap: 4px;
        }

        .chart-bar {
            width: 100%;
            border-radius: 4px 4px 0 0;
            transition: height 0.3s ease;
        }

        .chart-bar.sync-bar {
            background-color: rgba(74, 222, 128, 0.85);
        }

        .chart-bar.pending-bar {
            background-color: rgba(248, 113, 113, 0.85);
        }

        .chart-label {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-align: center;
            margin-top: 0.5rem;
            white-space: nowrap;
        }

        .chart-value {
            position: absolute;
            top: -25px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 0.7rem;
            color: #a6adc8;
        }

        /* Progress List and Tables */
        .table-responsive {
            overflow-x: auto;
            width: 100%;
            margin-top: 1rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
            text-align: left;
        }

        th {
            padding: 0.75rem;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border-color);
            font-weight: 600;
        }

        td {
            padding: 0.75rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            vertical-align: middle;
        }

        .badge {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: bold;
        }

        .badge.success { background: rgba(74, 222, 128, 0.15); color: #4ade80; }
        .badge.warning { background: rgba(251, 191, 36, 0.15); color: #fbbf24; }
        .badge.danger { background: rgba(244, 63, 94, 0.15); color: #f43f5e; }

        /* Spinner */
        .spinner {
            width: 32px;
            height: 32px;
            border: 3px solid rgba(168, 85, 247, 0.1);
            border-radius: 50%;
            border-top-color: var(--primary-color);
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Modal Overlay */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.6);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            backdrop-filter: blur(4px);
        }

        .modal-content {
            width: 90%;
            max-width: 700px;
            background: #110e1a;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 2rem;
            animation: fadeIn 0.25s ease-out;
            max-height: 85vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: bold;
            color: var(--primary-color);
        }

        .modal-close {
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 1.5rem;
            cursor: pointer;
        }

        /* Accordions */
        .accordion-item {
            border-bottom: 1px solid var(--border-color);
        }

        .accordion-header {
            display: grid;
            grid-template-columns: 180px 100px 1fr;
            padding: 0.75rem 1rem;
            cursor: pointer;
            align-items: center;
            font-size: 0.85rem;
            transition: background 0.2s;
        }

        .accordion-header:hover {
            background: rgba(255,255,255,0.02);
        }

        .accordion-body {
            padding: 0 1rem 1rem 1rem;
            font-size: 0.8rem;
            border-top: 1px dashed rgba(255, 255, 255, 0.05);
            background: rgba(0, 0, 0, 0.2);
        }
    </style>
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

            <!-- Top Navigation Tab Bar -->
            <div class="glass nav-tabs" id="main-nav">
                <button class="nav-tab active" onclick="switchSection('analytics')">📈 Analytics</button>
                <button class="nav-tab" onclick="switchSection('patient_search')">🔍 Patient Search</button>
                <button class="nav-tab" onclick="switchSection('sync')">🔄 Sync Center</button>
                <button class="nav-tab" onclick="switchSection('troubleshoot')">🗺️ Mapping Center</button>
                <button class="nav-tab" onclick="switchSection('logs')">📋 Log Viewer</button>
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
                        <div style="display: flex; gap: 1.5rem; mt: 1rem; justify-content: center; font-size: 0.8rem; margin-top: 1rem;">
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

                <div style="display: grid; grid-template-columns: 1.5fr 1fr 1fr; gap: 1rem; marginBottom: 1.5rem; margin-bottom: 1rem;">
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

    <script>
        // API Base
        const API_BASE = '../php-service/api_satusehat_portal.php';

        // Global states
        let jwtToken = localStorage.getItem('ss_token') || null;
        let userRole = 'user';
        let currentSection = 'analytics';
        let activeSearchTab = 'rm';
        let selectedResource = 'patient';
        let selectedMappingType = 'location';

        // Local searches/results
        let currentPatientResult = null;
        let syncStatsData = null;
        let cancelRequested = false;

        // Logging & Lists Pagination States
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
            document.getElementById('logs-date-input').value = new Date().toISOString().split('T')[0];
            
            if (jwtToken) {
                setupAuthContext();
            } else {
                showView('auth-view');
            }

            // Bind forms
            document.getElementById('login-form').addEventListener('submit', handleLoginSubmit);
            document.getElementById('search-form').addEventListener('submit', handleSearchSubmit);
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

            // Restrict admin elements if normal user
            const nav = document.getElementById('main-nav');
            if (userRole !== 'admin') {
                nav.innerHTML = '<button class="nav-tab active">🔍 Patient Search</button>';
                switchSection('patient_search');
            } else {
                nav.innerHTML = `
                    <button class="nav-tab active" onclick="switchSection('analytics')">📈 Analytics</button>
                    <button class="nav-tab" onclick="switchSection('patient_search')">🔍 Patient Search</button>
                    <button class="nav-tab" onclick="switchSection('sync')">🔄 Sync Center</button>
                    <button class="nav-tab" onclick="switchSection('troubleshoot')">🗺️ Mapping Center</button>
                    <button class="nav-tab" onclick="switchSection('logs')">📋 Log Viewer</button>
                `;
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
            // Reset input values
            document.getElementById('password').value = '';
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
        // PANEL 1: ANALYTICS & TRENDS
        // ==========================================
        async function loadAnalytics() {
            const loader = document.getElementById('analytics-loader');
            const errDiv = document.getElementById('analytics-error');
            const content = document.getElementById('analytics-content');
            
            loader.style.display = 'block';
            errDiv.style.display = 'none';
            content.style.opacity = '0.3';

            try {
                const res = await apiFetch(`${API_BASE}?action=getAnalyticsStats`);
                if (res.success) {
                    renderAnalyticsTrends(res.trends);
                    renderAnalyticsErrors(res.top_errors);
                    renderAnalyticsCoverage(res.coverage);
                    content.style.opacity = '1';
                } else {
                    throw new Error(res.message || 'Analytics error');
                }
            } catch (err) {
                errDiv.innerText = err.message;
                errDiv.style.display = 'block';
            } finally {
                loader.style.display = 'none';
            }
        }

        function renderAnalyticsTrends(trends) {
            const grid = document.getElementById('chart-grid');
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
            } finally {
                loader.style.display = 'none';
            }
        }

        function renderPatientDetails(p) {
            const grid = document.getElementById('patient-details-grid');
            grid.innerHTML = '';

            // Handle both Local db structure (SatuSehat client) and FHIR resource structures
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

        // Patient mapping to FHIR for creation helper
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
            
            // Sequential workflow requires single visit ID (no_rawat)
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
                        
                        // Composite key visual badges
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

        // Helper console logs append
        function appendConsole(text, type) {
            const consoleBox = document.getElementById('sync-console');
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

            // Sequential workflow sync
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
            
            // Map tab styles
            const tabs = ['location', 'practitioner', 'medication', 'vaccine'];
            tabs.forEach(t => {
                const el = document.getElementById(`mapping-tab-${t}`);
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

            document.getElementById('mapping-search-input').value = '';
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

            loader.style.display = 'block';
            tableContainer.style.opacity = '0.3';
            pagination.innerHTML = '';

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
                    tableContainer.style.opacity = '1';
                }
            } catch (err) {
                tbody.innerHTML = `<tr><td colspan="3" style="color: var(--danger); text-align: center;">Error: ${err.message}</td></tr>`;
            } finally {
                loader.style.display = 'none';
            }
        }

        function renderMappingPagination(total, currentPage) {
            const container = document.getElementById('mapping-pagination');
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

            loader.style.display = 'block';
            listEl.innerHTML = '';
            pagination.innerHTML = '';
            errDiv.style.display = 'none';

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
                                <div style="color: #cdd6f4; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; padding-right: 1rem; fontFamily: monospace;">
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
                errDiv.innerText = err.message;
                errDiv.style.display = 'block';
            } finally {
                loader.style.display = 'none';
            }
        }

        function toggleLogAccordion(idx) {
            const body = document.getElementById(`log-body-${idx}`);
            const header = document.getElementById(`log-header-${idx}`);
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

            container.appendChild(nextBtn);
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
                // Editable payload view
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
                // Static inspection view
                bodyEl.innerText = JSON.stringify(jsonPayload, null, 2);
                actionsEl.innerHTML = `<button class="btn btn-secondary" style="width: auto;" onclick="closeModal()">Close</button>`;
            }
        }

        function closeModal() {
            document.getElementById('modal-container').style.display = 'none';
        }
    </script>
</body>
</html>
