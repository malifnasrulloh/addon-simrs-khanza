import { useState, useRef, useEffect } from 'react';

const API_BASE = window.PORTAL_CONFIG?.API_URL || '/php-service/api_satusehat_portal.php';

const resourcesList = [
  { id: 'patient', name: 'Patient (NIK -> IHS)' },
  { id: 'encounter', name: 'Encounter (Visit Registration)' },
  { id: 'episodeofcare', name: 'Episode Of Care' },
  { id: 'condition', name: 'Condition (Diagnosis)' },
  { id: 'observationttv', name: 'Observation TTV (Vitals)' },
  { id: 'procedure', name: 'Procedure' },
  { id: 'allergyintolerance', name: 'Allergy Intolerance' },
  { id: 'immunization', name: 'Immunization' },
  { id: 'medication', name: 'Medication (Drug Catalog)' },
  { id: 'medicationrequest', name: 'Medication Request (Prescription)' },
  { id: 'medicationdispense', name: 'Medication Dispense' },
  { id: 'medicationstatement', name: 'Medication Statement' },
];

export default function Dashboard({ token, setToken }) {
  const parseJwt = (t) => {
    try {
      return JSON.parse(atob(t.split('.')[1]));
    } catch (e) {
      return null;
    }
  };

  const userClaims = parseJwt(token) || {};
  const username = userClaims.user || 'Admin';
  const role = userClaims.role || 'user';

  const [activeTab, setActiveTab] = useState('rm');

  // Search States
  const [noRm, setNoRm] = useState('');
  const [nik, setNik] = useState('');
  const [nikIbu, setNikIbu] = useState('');
  const [birthdate, setBirthdate] = useState('');

  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState(null);
  const [error, setError] = useState('');

  const [createMode, setCreateMode] = useState(false);
  const [showModal, setShowModal] = useState(false);
  const [createPayload, setCreatePayload] = useState(null);
  const [showRawJson, setShowRawJson] = useState(false);
  const [payloadText, setPayloadText] = useState('');

  // Sync Manager States
  const [selectedResource, setSelectedResource] = useState('patient');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [noRawat, setNoRawat] = useState('');
  
  const [syncStats, setSyncStats] = useState(null);
  const [syncing, setSyncing] = useState(false);
  const [syncLogs, setSyncLogs] = useState([]);
  const [syncProgress, setSyncProgress] = useState(0);
  const [syncedCount, setSyncedCount] = useState(0);
  const [failedCount, setFailedCount] = useState(0);
  const [cancelRequested, setCancelRequested] = useState(false);

  // Phase 2 Interactive Records States
  const [records, setRecords] = useState([]);
  const [totalCount, setTotalCount] = useState(0);
  const [page, setPage] = useState(1);
  const [recordsLimit] = useState(20);
  const [statusFilter, setStatusFilter] = useState('all');
  const [searchTerm, setSearchTerm] = useState('');
  const [syncingRecordId, setSyncingRecordId] = useState(null);

  const cancelRef = useRef(false);

  useEffect(() => {
    if (activeTab === 'sync' && role === 'admin') {
      fetchSyncStats();
      fetchRecords(1);
    }
  }, [selectedResource, activeTab, statusFilter, dateFrom, dateTo]);

  const fetchSyncStats = async () => {
    setLoading(true);
    setError('');
    try {
      let url = `${API_BASE}?action=getSyncStats&resource=${selectedResource}`;
      if (noRawat) url += `&no_rawat=${encodeURIComponent(noRawat)}`;
      if (dateFrom) url += `&dateFrom=${dateFrom}`;
      if (dateTo) url += `&dateTo=${dateTo}`;

      const data = await fetchApi(url);
      if (data.success) {
        setSyncStats(data.stats);
      }
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  const fetchRecords = async (targetPage = page) => {
    setLoading(true);
    setError('');
    try {
      let url = `${API_BASE}?action=getPendingRecords&resource=${selectedResource}&page=${targetPage}&limit=${recordsLimit}&status=${statusFilter}`;
      if (dateFrom) url += `&dateFrom=${dateFrom}`;
      if (dateTo) url += `&dateTo=${dateTo}`;
      if (searchTerm) url += `&search=${encodeURIComponent(searchTerm)}`;

      const data = await fetchApi(url);
      if (data.success) {
        setRecords(data.records || []);
        setTotalCount(data.total_count || 0);
        setPage(data.page || 1);
      }
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  const handleRowSync = async (record) => {
    if (syncingRecordId) return;
    setSyncingRecordId(record.id);
    setError('');
    const targetIdentifier = record.no_rawat || record.nik || record.id || '';
    if (!targetIdentifier) {
      setError('Missing unique record identifier.');
      setSyncingRecordId(null);
      return;
    }

    try {
      const timeStr = new Date().toLocaleTimeString();
      setSyncLogs(prev => [...prev, { time: timeStr, text: `Triggering single record sync for [${selectedResource}] matching key: ${targetIdentifier}...`, type: 'info' }]);
      
      const url = `${API_BASE}?action=triggerBatchSync&resource=${selectedResource}&no_rawat=${encodeURIComponent(targetIdentifier)}`;
      const response = await fetchApi(url, { method: 'POST' });

      if (response.success) {
        const summary = response.summary || {};
        const syncedList = response.synced || [];
        let success = 0;
        let failed = 0;

        if (selectedResource === 'patient') {
          if (syncedList.length > 0 && syncedList[0].status === 'success') {
            success = 1;
            setSyncLogs(prev => [...prev, { time: new Date().toLocaleTimeString(), text: `SUCCESS: Mapped Patient ${record.patient_name || ''} to IHS ${syncedList[0].ihs}`, type: 'success' }]);
          } else {
            failed = 1;
            setSyncLogs(prev => [...prev, { time: new Date().toLocaleTimeString(), text: `FAILED: Patient ${record.patient_name || ''} - ${syncedList[0]?.message || 'Not Found'}`, type: 'error' }]);
          }
        } else {
          success = summary.success ?? 0;
          failed = summary.failed ?? 0;
          
          if (success > 0) {
            setSyncLogs(prev => [...prev, { time: new Date().toLocaleTimeString(), text: `SUCCESS: Synchronized record successfully.`, type: 'success' }]);
          } else {
            setSyncLogs(prev => [...prev, { time: new Date().toLocaleTimeString(), text: `FAILED: Record sync failed. ${summary.errors?.[0] || ''}`, type: 'error' }]);
          }
        }

        // Refresh stats and records list
        fetchSyncStats();
        fetchRecords(page);
      } else {
        throw new Error(response.message || 'Single sync failed.');
      }
    } catch (err) {
      setSyncLogs(prev => [...prev, { time: new Date().toLocaleTimeString(), text: `ERROR: ${err.message}`, type: 'error' }]);
    } finally {
      setSyncingRecordId(null);
    }
  };

  const handleRowWorkflow = async (record) => {
    if (syncingRecordId) return;
    setSyncingRecordId(record.id);
    setError('');
    const noRawatVal = record.no_rawat;
    if (!noRawatVal) {
      setError('Cannot execute workflow. No Visit ID (no_rawat) is associated with this record.');
      setSyncingRecordId(null);
      return;
    }

    try {
      const timeStr = new Date().toLocaleTimeString();
      setSyncLogs(prev => [...prev, { time: timeStr, text: `Triggering sequential clinical workflow sync for Rawat Record: ${noRawatVal}...`, type: 'info' }]);
      
      const response = await fetchApi(`${API_BASE}?action=triggerBatchSync&resource=workflow&no_rawat=${encodeURIComponent(noRawatVal)}`, {
        method: 'POST'
      });

      if (response.success && response.workflow) {
        const wf = response.workflow;
        const logs = [];
        Object.keys(wf).forEach(step => {
          const res = wf[step];
          const stepTimeStr = new Date().toLocaleTimeString();
          if (res.status === 'success' || res.status === 'already_mapped' || res.status === 'processed') {
            logs.push({
              time: stepTimeStr,
              text: `SUCCESS: Step [${step}] Completed. Status: ${res.status}. IHS: ${res.ihs || ''}`,
              type: 'success'
            });
          } else {
            logs.push({
              time: stepTimeStr,
              text: `FAILED: Step [${step}] failed. Message: ${res.message || 'Error occurred'}`,
              type: 'error'
            });
          }
        });
        setSyncLogs(prev => [...prev, ...logs]);
        fetchSyncStats();
        fetchRecords(page);
      } else {
        throw new Error(response.message || 'Workflow sync failed.');
      }
    } catch (err) {
      setSyncLogs(prev => [...prev, { time: new Date().toLocaleTimeString(), text: `ERROR: ${err.message}`, type: 'error' }]);
    } finally {
      setSyncingRecordId(null);
    }
  };

  const handleStartSync = async () => {
    if (syncing) return;
    setSyncing(true);
    setCancelRequested(false);
    cancelRef.current = false;
    setSyncProgress(0);
    setSyncedCount(0);
    setFailedCount(0);

    const initialLogs = [{ time: new Date().toLocaleTimeString(), text: `Starting sync process for resource: ${selectedResource}...`, type: 'info' }];
    setSyncLogs(initialLogs);

    // If resource is sequential workflow sync
    if (selectedResource === 'workflow') {
      if (!noRawat) {
        setSyncLogs(prev => [...prev, { time: new Date().toLocaleTimeString(), text: 'ERROR: no_rawat parameter is strictly required for sequential workflow sync.', type: 'error' }]);
        setSyncing(false);
        return;
      }
      try {
        setSyncLogs(prev => [...prev, { time: new Date().toLocaleTimeString(), text: `Triggering sequential workflow sync for Rawat Record: ${noRawat}`, type: 'info' }]);
        const response = await fetchApi(`${API_BASE}?action=triggerBatchSync&resource=workflow&no_rawat=${encodeURIComponent(noRawat)}`, {
          method: 'POST'
        });
        if (response.success && response.workflow) {
          const wf = response.workflow;
          const logs = [];
          Object.keys(wf).forEach(step => {
            const res = wf[step];
            const timeStr = new Date().toLocaleTimeString();
            if (res.status === 'success' || res.status === 'already_mapped' || res.status === 'processed') {
              logs.push({
                time: timeStr,
                text: `SUCCESS: Step [${step}] Completed. Status: ${res.status}. IHS: ${res.ihs || ''}`,
                type: 'success'
              });
            } else {
              logs.push({
                time: timeStr,
                text: `FAILED: Step [${step}] failed. Message: ${res.message || 'Error occurred'}`,
                type: 'error'
              });
            }
          });
          setSyncLogs(prev => [...prev, ...logs]);
          setSyncProgress(100);
        } else {
          throw new Error(response.message || 'Workflow synchronization returned failure status.');
        }
      } catch (err) {
        setSyncLogs(prev => [...prev, { time: new Date().toLocaleTimeString(), text: `ERROR: ${err.message}`, type: 'error' }]);
      } finally {
        setSyncing(false);
      }
      return;
    }

    let currentPending = syncStats?.pending ?? 0;
    if (noRawat) {
      currentPending = syncStats?.pending ?? 1;
    }
    
    if (currentPending === 0) {
      setSyncLogs(prev => [...prev, { time: new Date().toLocaleTimeString(), text: 'No pending records found to synchronize.', type: 'success' }]);
      setSyncing(false);
      return;
    }

    let processed = 0;
    const batchLimit = 5;

    while (processed < currentPending || noRawat) {
      if (cancelRef.current) {
        setSyncLogs(prev => [...prev, { time: new Date().toLocaleTimeString(), text: 'Batch sync process cancelled by administrator.', type: 'error' }]);
        break;
      }

      try {
        let url = `${API_BASE}?action=triggerBatchSync&resource=${selectedResource}&limit=${batchLimit}`;
        if (noRawat) url += `&no_rawat=${encodeURIComponent(noRawat)}`;
        if (dateFrom) url += `&dateFrom=${dateFrom}`;
        if (dateTo) url += `&dateTo=${dateTo}`;

        const response = await fetchApi(url, { method: 'POST' });

        if (response.success) {
          const syncedList = response.synced || [];
          const summary = response.summary || {};
          
          let batchSuccess = 0;
          let batchFail = 0;
          const newLogs = [];
          const timeStr = new Date().toLocaleTimeString();

          if (selectedResource === 'patient') {
            if (syncedList.length === 0) {
              setSyncLogs(prev => [...prev, { time: timeStr, text: 'No records were mapped in this run.', type: 'info' }]);
              break;
            }
            syncedList.forEach(item => {
              if (item.status === 'success') {
                batchSuccess++;
                newLogs.push({ time: timeStr, text: `SUCCESS: Mapped ${item.name} (RM: ${item.rm}) to IHS ${item.ihs}`, type: 'success' });
              } else {
                batchFail++;
                newLogs.push({ time: timeStr, text: `FAILED: Patient ${item.name} (RM: ${item.rm}) - ${item.message || 'Not Found'}`, type: 'error' });
              }
            });
            processed += syncedList.length;
          } else {
            // General Clinical Processors return a summary object
            const success = summary.success ?? 0;
            const failed = summary.failed ?? 0;
            batchSuccess += success;
            batchFail += failed;
            
            newLogs.push({
              time: timeStr,
              text: `Processed Batch: ${success} Synced Successfully, ${failed} Failed.`,
              type: success > 0 ? 'success' : 'info'
            });

            if (summary.errors && summary.errors.length > 0) {
              summary.errors.forEach(err => {
                newLogs.push({ time: timeStr, text: `ERROR Details: ${err}`, type: 'error' });
              });
            }

            processed += batchLimit;
            if (success === 0 && failed === 0) {
              setSyncLogs(prev => [...prev, { time: timeStr, text: 'Batch sync execution completed. No more pending items found.', type: 'success' }]);
              break;
            }
          }

          setSyncedCount(prev => prev + batchSuccess);
          setFailedCount(prev => prev + batchFail);
          setSyncLogs(prev => [...prev, ...newLogs]);

          const pct = noRawat ? 100 : Math.min(100, Math.round((processed / currentPending) * 100));
          setSyncProgress(pct);

          // Refresh statistics
          fetchSyncStats();

          if (noRawat) break; // Single record sync is done
        } else {
          throw new Error(response.message || "Failed to sync batch");
        }
      } catch (err) {
        setSyncLogs(prev => [...prev, { time: new Date().toLocaleTimeString(), text: `ERROR: ${err.message}`, type: 'error' }]);
        break;
      }

      await new Promise(r => setTimeout(r, 600));
    }

    setSyncing(false);
  };

  const handleCancelSync = () => {
    cancelRef.current = true;
    setCancelRequested(true);
  };

  const handleLogout = () => {
    setToken(null);
  };

  const fetchApi = async (url, options = {}) => {
    const res = await fetch(url, {
      ...options,
      headers: {
        ...options.headers,
        'Authorization': `Bearer ${token}`
      }
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.message || 'API Request Failed');
    return data;
  };

  const handleSearch = async (e) => {
    e.preventDefault();
    setLoading(true);
    setError('');
    setResult(null);
    setCreateMode(false);
    setShowModal(false);
    setShowRawJson(false);

    try {
      if (activeTab === 'rm') {
        const data = await fetchApi(`${API_BASE}?action=searchLocal&no_rm=${noRm}`);
        setResult(data.data);
      } else {
        const query = activeTab === 'nik' ? `&nik=${nik}` : `&nik_ibu=${nikIbu}&birthdate=${birthdate}`;
        const data = await fetchApi(`${API_BASE}?action=searchSatuSehat${query}`);

        if (data.data.entry && data.data.entry.length > 0) {
          setResult(data.data.entry[0].resource);
        } else {
          setError('Patient not found in Satu Sehat. You can create them if you have their full details.');
          setCreateMode(true);
        }
      }
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  const mapLocalToFHIR = (patientData) => {
    return {
      resourceType: "Patient",
      meta: { profile: ["https://fhir.kemkes.go.id/r4/StructureDefinition/Patient"] },
      identifier: [
        {
          use: "official",
          system: "https://fhir.kemkes.go.id/id/nik",
          value: patientData.nik || "0000000000000000"
        }
      ],
      active: true,
      name: [{
        use: "official",
        text: patientData.nm_pasien || "N/A"
      }],
      telecom: [
        {
          system: "phone",
          value: patientData.no_tlp || "",
          use: "mobile"
        }
      ],
      gender: patientData.jk === 'L' ? 'male' : (patientData.jk === 'P' ? 'female' : 'unknown'),
      birthDate: patientData.tgl_lahir || "1900-01-01",
      address: [
        {
          use: "home",
          line: [patientData.alamat || ""]
        }
      ],
      maritalStatus: {
        text: patientData.stts_nikah || "BELUM MENIKAH"
      },
      contact: [
        {
          relationship: [
            {
              coding: [
                {
                  system: "http://terminology.hl7.org/CodeSystem/v2-0131",
                  code: "MTH"
                }
              ]
            }
          ],
          name: {
            use: "official",
            text: patientData.nm_ibu || "N/A"
          }
        }
      ]
    };
  };

  const prepareCreate = async () => {
    setLoading(true);
    setError('');

    try {
      let localPatient = null;

      if (activeTab === 'rm' && result) {
        localPatient = result;
      } else {
        const searchNik = activeTab === 'nik' ? nik : nikIbu;
        if (searchNik) {
          try {
            const data = await fetchApi(`${API_BASE}?action=searchLocal&nik=${searchNik}`);
            if (data.success && data.data) {
              localPatient = data.data;
            }
          } catch (e) {
            console.warn("Patient not found in local database for creation mapping", e);
          }
        }
      }

      let payloadObj = null;
      if (localPatient) {
        payloadObj = mapLocalToFHIR(localPatient);
      } else {
        payloadObj = {
          resourceType: "Patient",
          meta: { profile: ["https://fhir.kemkes.go.id/r4/StructureDefinition/Patient"] },
          identifier: [{ use: "official", system: "https://fhir.kemkes.go.id/id/nik", value: activeTab === 'nik' ? nik : nikIbu }],
          active: true,
          name: [{ use: "official", text: "" }],
          gender: "unknown",
          birthDate: birthdate || ""
        };
      }

      setCreatePayload(payloadObj);
      setPayloadText(JSON.stringify(payloadObj, null, 2));
      setShowModal(true);
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  const handleCreateConfirm = async () => {
    setLoading(true);
    setError('');

    try {
      let finalPayload = null;
      try {
        finalPayload = JSON.parse(payloadText);
      } catch (e) {
        throw new Error("Invalid JSON structure. Please fix any syntax errors in your payload.");
      }

      const data = await fetchApi(`${API_BASE}?action=createPatient`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(finalPayload)
      });
      setResult(data.data);
      setShowModal(false);
      setCreateMode(false);
      setActiveTab('nik');
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="container">
      <div className="header glass" style={{ padding: '1.5rem 2rem', marginBottom: '2rem' }}>
        <h1>SatuSehat Integration Portal</h1>
        <button className="logout-btn" onClick={handleLogout}>Logout</button>
      </div>

      <div className="glass search-section">
        <div className="tabs">
          <button className={`tab ${activeTab === 'rm' ? 'active' : ''}`} onClick={() => { setActiveTab('rm'); setResult(null); setError(''); }}>No Rekam Medis</button>
          <button className={`tab ${activeTab === 'nik' ? 'active' : ''}`} onClick={() => { setActiveTab('nik'); setResult(null); setError(''); }}>NIK</button>
          <button className={`tab ${activeTab === 'nik_ibu' ? 'active' : ''}`} onClick={() => { setActiveTab('nik_ibu'); setResult(null); setError(''); }}>NIK Ibu (Bayi)</button>
          {role === 'admin' && (
            <button className={`tab ${activeTab === 'sync' ? 'active' : ''}`} onClick={() => { setActiveTab('sync'); setResult(null); setError(''); fetchSyncStats(); }}>SatuSehat Sync Engine</button>
          )}
        </div>

        {activeTab !== 'sync' && (
          <form onSubmit={handleSearch}>
            {activeTab === 'rm' && (
              <div className="form-group">
                <label>No. Rekam Medis</label>
                <input type="text" className="form-control" value={noRm} onChange={e => setNoRm(e.target.value)} required />
              </div>
            )}
            {activeTab === 'nik' && (
              <div className="form-group">
                <label>NIK</label>
                <input type="text" className="form-control" value={nik} onChange={e => setNik(e.target.value)} required />
              </div>
            )}
            {activeTab === 'nik_ibu' && (
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '1rem' }}>
                <div className="form-group">
                  <label>NIK Ibu</label>
                  <input type="text" className="form-control" value={nikIbu} onChange={e => setNikIbu(e.target.value)} required />
                </div>
                <div className="form-group">
                  <label>Tanggal Lahir (YYYY-MM-DD)</label>
                  <input type="date" className="form-control" value={birthdate} onChange={e => setBirthdate(e.target.value)} required />
                </div>
              </div>
            )}

            <div style={{ marginTop: '1rem' }}>
              <button type="submit" className="btn" disabled={loading} style={{ width: 'auto' }}>
                {loading ? 'Searching...' : 'Search Patient'}
              </button>
            </div>
          </form>
        )}        {activeTab === 'sync' && (
          <div className="sync-dashboard-container" style={{ textAlign: 'left' }}>
            <h2 style={{ marginTop: 0, color: 'var(--primary-color)' }}>Clinical Resource Synchronization</h2>
            <p style={{ color: 'var(--text-muted)', fontSize: '0.9rem', marginBottom: '1.5rem' }}>
              Orchestrate batch transfers or execute sequence-based patient visits workflows directly into SatuSehat production endpoint.
            </p>

            <div style={{ display: 'grid', gridTemplateColumns: '1.5fr 1fr 1fr', gap: '1rem', marginBottom: '1.5rem' }}>
              <div className="form-group">
                <label>Select Target Resource / Flow</label>
                <select 
                  className="form-control" 
                  style={{ background: 'rgba(22, 18, 38, 0.9)', color: '#fff', border: '1px solid var(--border-color)' }}
                  value={selectedResource}
                  onChange={e => {
                    setSelectedResource(e.target.value);
                    setSyncStats(null);
                    setRecords([]);
                    setPage(1);
                  }}
                >
                  {resourcesList.map(res => (
                    <option key={res.id} value={res.id}>{res.name}</option>
                  ))}
                  <option value="workflow">🔥 Sequential Workflow Sync (Encounter Sequence)</option>
                </select>
              </div>

              <div className="form-group">
                <label>Target Single Visit (no_rawat)</label>
                <input 
                  type="text" 
                  className="form-control" 
                  placeholder="e.g. 2026/05/25/000001"
                  style={{ background: 'rgba(22, 18, 38, 0.9)', color: '#fff', border: '1px solid var(--border-color)' }}
                  value={noRawat}
                  onChange={e => setNoRawat(e.target.value)}
                />
              </div>

              <div className="form-group" style={{ display: 'flex', gap: '0.5rem', alignItems: 'flex-end' }}>
                <button className="btn btn-secondary" onClick={fetchSyncStats} style={{ height: '42px', flex: 1 }}>
                  Load Stats
                </button>
              </div>
            </div>

            {selectedResource !== 'workflow' && (
              <div style={{ display: 'grid', gridTemplateColumns: '1.2fr 1.2fr 1fr', gap: '1rem', marginBottom: '1.5rem', background: 'rgba(255,255,255,0.02)', padding: '1rem', borderRadius: '8px', border: '1px solid var(--border-color)' }}>
                <div className="form-group" style={{ marginBottom: 0 }}>
                  <label>Date From</label>
                  <input type="date" className="form-control" style={{ background: 'rgba(22, 18, 38, 0.9)', color: '#fff', border: '1px solid var(--border-color)' }} value={dateFrom} onChange={e => setDateFrom(e.target.value)} />
                </div>
                <div className="form-group" style={{ marginBottom: 0 }}>
                  <label>Date To</label>
                  <input type="date" className="form-control" style={{ background: 'rgba(22, 18, 38, 0.9)', color: '#fff', border: '1px solid var(--border-color)' }} value={dateTo} onChange={e => setDateTo(e.target.value)} />
                </div>
                <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'flex-end' }}>
                  <button className="btn btn-secondary" onClick={() => { setDateFrom(''); setDateTo(''); }} style={{ height: '42px', fontSize: '0.85rem', flex: 1 }}>
                    Use Defaults
                  </button>
                </div>
              </div>
            )}

            {syncStats && selectedResource !== 'workflow' && (
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: '1rem', marginBottom: '1.5rem' }}>
                <div style={{ padding: '1rem', background: 'rgba(255, 255, 255, 0.05)', borderRadius: '8px', border: '1px solid var(--border-color)' }}>
                  <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>TOTAL RECORDS</div>
                  <div style={{ fontSize: '1.5rem', fontWeight: 'bold', color: '#cdd6f4' }}>{syncStats.total ?? 0}</div>
                </div>
                <div style={{ padding: '1rem', background: 'rgba(74, 222, 128, 0.08)', borderRadius: '8px', border: '1px solid rgba(74, 222, 128, 0.2)' }}>
                  <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>SYNCED</div>
                  <div style={{ fontSize: '1.5rem', fontWeight: 'bold', color: '#4ade80' }}>{syncStats.synced ?? 0}</div>
                </div>
                <div style={{ padding: '1rem', background: 'rgba(251, 191, 36, 0.08)', borderRadius: '8px', border: '1px solid rgba(251, 191, 36, 0.2)' }}>
                  <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>PENDING</div>
                  <div style={{ fontSize: '1.5rem', fontWeight: 'bold', color: '#fbbf24' }}>{syncStats.pending ?? 0}</div>
                </div>
                <div style={{ padding: '1rem', background: 'rgba(248, 113, 113, 0.08)', borderRadius: '8px', border: '1px solid rgba(248, 113, 113, 0.2)' }}>
                  <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>BLOCKED / UNMAPPED</div>
                  <div style={{ fontSize: '1.5rem', fontWeight: 'bold', color: '#f87171' }}>{(syncStats.blocked ?? 0) + (syncStats.unmapped ?? 0) + (syncStats.unpaid ?? 0)}</div>
                </div>
              </div>
            )}

            <div style={{ display: 'flex', gap: '1rem', marginBottom: '1.5rem', alignItems: 'center' }}>
              {!syncing ? (
                <button className="btn" style={{ width: 'auto' }} onClick={handleStartSync} disabled={loading}>
                  {selectedResource === 'workflow' ? 'Run Sequential Workflow' : (noRawat ? 'Sync Single Rawat' : 'Sync Filtered Batch')}
                </button>
              ) : (
                <button className="btn" style={{ width: 'auto', background: 'var(--danger)' }} onClick={handleCancelSync}>
                  {cancelRequested ? 'Cancelling...' : 'Cancel Sync'}
                </button>
              )}

              {syncing && selectedResource !== 'workflow' && (
                <div style={{ display: 'flex', gap: '1rem', fontSize: '0.9rem', color: 'var(--text-muted)' }}>
                  <span>Success/Updated: <strong style={{ color: '#4ade80' }}>{syncedCount}</strong></span>
                  <span>Failed: <strong style={{ color: '#f87171' }}>{failedCount}</strong></span>
                </div>
              )}
            </div>

            {syncing && (
              <div style={{ marginBottom: '1.5rem' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.8rem', marginBottom: '0.3rem', color: 'var(--text-muted)' }}>
                  <span>Syncing Progress...</span>
                  <span>{syncProgress}%</span>
                </div>
                <div style={{ height: '6px', background: 'rgba(255, 255, 255, 0.1)', borderRadius: '3px', overflow: 'hidden' }}>
                  <div style={{ height: '100%', background: 'var(--primary-color)', width: `${syncProgress}%`, transition: 'width 0.3s ease' }}></div>
                </div>
              </div>
            )}

            {/* Interactive Grid Table view */}
            {selectedResource !== 'workflow' && (
              <div style={{ marginTop: '2rem', marginBottom: '2rem' }}>
                <h3 style={{ color: '#cdd6f4', marginBottom: '1rem' }}>Interactive Records Explorer</h3>
                
                {/* Search & Filter Controls */}
                <div style={{ display: 'flex', gap: '1rem', marginBottom: '1rem', flexWrap: 'wrap' }}>
                  <div style={{ flex: '1 1 250px' }}>
                    <input
                      type="text"
                      className="form-control"
                      placeholder="Search patient, RM, NIK, or drug..."
                      value={searchTerm}
                      onChange={e => setSearchTerm(e.target.value)}
                      onKeyDown={e => e.key === 'Enter' && fetchRecords(1)}
                      style={{ background: 'rgba(22, 18, 38, 0.9)', color: '#fff', border: '1px solid var(--border-color)' }}
                    />
                  </div>
                  <div style={{ width: '150px' }}>
                    <select
                      className="form-control"
                      value={statusFilter}
                      onChange={e => setStatusFilter(e.target.value)}
                      style={{ background: 'rgba(22, 18, 38, 0.9)', color: '#fff', border: '1px solid var(--border-color)' }}
                    >
                      <option value="all">All Statuses</option>
                      <option value="pending">Pending</option>
                      <option value="synced">Synced</option>
                      <option value="blocked">Blocked</option>
                    </select>
                  </div>
                  <div>
                    <button className="btn btn-secondary" onClick={() => fetchRecords(1)} style={{ height: '42px' }}>
                      Apply Filters
                    </button>
                  </div>
                </div>

                {/* Table list */}
                <div style={{ overflowX: 'auto', border: '1px solid var(--border-color)', borderRadius: '12px', background: 'rgba(255, 255, 255, 0.01)' }}>
                  <table style={{ width: '100%', borderCollapse: 'collapse', textAlign: 'left', fontSize: '0.85rem' }}>
                    <thead>
                      <tr style={{ borderBottom: '1px solid var(--border-color)', background: 'rgba(255,255,255,0.02)' }}>
                        <th style={{ padding: '0.75rem 1rem', color: 'var(--text-muted)' }}>Date</th>
                        <th style={{ padding: '0.75rem 1rem', color: 'var(--text-muted)' }}>Patient Details</th>
                        <th style={{ padding: '0.75rem 1rem', color: 'var(--text-muted)' }}>Visit ID (Rawat)</th>
                        <th style={{ padding: '0.75rem 1rem', color: 'var(--text-muted)' }}>Details</th>
                        <th style={{ padding: '0.75rem 1rem', color: 'var(--text-muted)' }}>Status</th>
                        <th style={{ padding: '0.75rem 1rem', color: 'var(--text-muted)', textAlign: 'right' }}>Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      {records.length === 0 ? (
                        <tr>
                          <td colSpan="6" style={{ padding: '2rem', textAlign: 'center', color: 'var(--text-muted)' }}>
                            No records found matching current criteria.
                          </td>
                        </tr>
                      ) : (
                        records.map(record => (
                          <tr key={record.id} style={{ borderBottom: '1px solid rgba(255,255,255,0.05)' }}>
                            <td style={{ padding: '0.75rem 1rem', whiteSpace: 'nowrap' }}>{record.date || 'N/A'}</td>
                            <td style={{ padding: '0.75rem 1rem' }}>
                              <div style={{ fontWeight: 'bold', color: '#cdd6f4' }}>{record.patient_name || 'System Catalog'}</div>
                              <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                                {record.nik ? `NIK: ${record.nik}` : ''} {record.rm ? ` | RM: ${record.rm}` : ''}
                              </div>
                            </td>
                            <td style={{ padding: '0.75rem 1rem', fontFamily: 'monospace' }}>{record.no_rawat || '-'}</td>
                            <td style={{ padding: '0.75rem 1rem', color: '#a6adc8', maxWidth: '250px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={record.details}>
                              {record.details}
                            </td>
                            <td style={{ padding: '0.75rem 1rem' }}>
                              {record.status === 'synced' && (
                                <span style={{ padding: '0.2rem 0.6rem', borderRadius: '20px', fontSize: '0.75rem', background: 'rgba(74, 222, 128, 0.15)', color: '#4ade80', border: '1px solid rgba(74, 222, 128, 0.3)', textShadow: '0 0 10px rgba(74, 222, 128, 0.4)' }}>
                                  Synced
                                </span>
                              )}
                              {record.status === 'pending' && (
                                <span style={{ padding: '0.2rem 0.6rem', borderRadius: '20px', fontSize: '0.75rem', background: 'rgba(251, 191, 36, 0.15)', color: '#fbbf24', border: '1px solid rgba(251, 191, 36, 0.3)', textShadow: '0 0 10px rgba(251, 191, 36, 0.4)' }}>
                                  Pending
                                </span>
                              )}
                              {record.status === 'blocked' && (
                                <span style={{ padding: '0.2rem 0.6rem', borderRadius: '20px', fontSize: '0.75rem', background: 'rgba(248, 113, 113, 0.15)', color: '#f87171', border: '1px solid rgba(248, 113, 113, 0.3)', textShadow: '0 0 10px rgba(248, 113, 113, 0.4)' }}>
                                  Blocked
                                </span>
                              )}
                            </td>
                            <td style={{ padding: '0.75rem 1rem', textAlign: 'right' }}>
                              <div style={{ display: 'inline-flex', gap: '0.5rem' }}>
                                <button
                                  className="btn btn-secondary"
                                  onClick={() => handleRowSync(record)}
                                  disabled={syncingRecordId !== null || syncing}
                                  style={{ padding: '0.2rem 0.6rem', fontSize: '0.75rem', height: '28px' }}
                                >
                                  {syncingRecordId === record.id ? 'Syncing...' : 'Sync'}
                                </button>
                                {record.no_rawat && (
                                  <button
                                    className="btn btn-secondary"
                                    onClick={() => handleRowWorkflow(record)}
                                    disabled={syncingRecordId !== null || syncing}
                                    style={{ padding: '0.2rem 0.6rem', fontSize: '0.75rem', height: '28px', borderColor: 'var(--primary-color)', color: 'var(--primary-color)' }}
                                  >
                                    Workflow
                                  </button>
                                )}
                              </div>
                            </td>
                          </tr>
                        ))
                      )}
                    </tbody>
                  </table>
                </div>

                {/* Pagination footer */}
                {totalCount > recordsLimit && (
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: '1rem' }}>
                    <div style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>
                      Showing {((page - 1) * recordsLimit) + 1} to {Math.min(page * recordsLimit, totalCount)} of {totalCount} records
                    </div>
                    <div style={{ display: 'flex', gap: '0.5rem' }}>
                      <button
                        className="btn btn-secondary"
                        disabled={page <= 1 || loading}
                        onClick={() => fetchRecords(page - 1)}
                        style={{ padding: '0.2rem 0.8rem', fontSize: '0.8rem', height: '32px' }}
                      >
                        Previous
                      </button>
                      <span style={{ display: 'flex', alignItems: 'center', padding: '0 0.5rem', fontSize: '0.85rem', color: '#cdd6f4' }}>
                        Page {page} of {Math.ceil(totalCount / recordsLimit)}
                      </span>
                      <button
                        className="btn btn-secondary"
                        disabled={page >= Math.ceil(totalCount / recordsLimit) || loading}
                        onClick={() => fetchRecords(page + 1)}
                        style={{ padding: '0.2rem 0.8rem', fontSize: '0.8rem', height: '32px' }}
                      >
                        Next
                      </button>
                    </div>
                  </div>
                )}
              </div>
            )}

            <div style={{ display: 'flex', flexDirection: 'column' }}>
              <div style={{ fontSize: '0.85rem', fontWeight: 'bold', marginBottom: '0.5rem', color: 'var(--text-muted)' }}>Activity Log Console</div>
              <div
                style={{
                  height: '280px',
                  background: '#0d0b18',
                  border: '1px solid var(--border-color)',
                  borderRadius: '12px',
                  padding: '1rem',
                  fontFamily: 'monospace',
                  fontSize: '0.8rem',
                  overflowY: 'auto',
                  display: 'flex',
                  flexDirection: 'column',
                  gap: '0.3rem',
                  boxShadow: 'inset 0 2px 8px rgba(0,0,0,0.8)'
                }}
                ref={el => { if (el) el.scrollTop = el.scrollHeight; }}
              >
                {syncLogs.length === 0 ? (
                  <span style={{ color: '#585b70' }}>Console inactive. Configure options and click sync.</span>
                ) : (
                  syncLogs.map((log, index) => (
                    <div key={index} style={{ display: 'flex', gap: '0.5rem' }}>
                      <span style={{ color: '#585b70' }}>[{log.time}]</span>
                      <span style={{
                        color: log.type === 'success' ? '#a6e3a1' : (log.type === 'error' ? '#f38ba8' : '#cdd6f4')
                      }}>{log.text}</span>
                    </div>
                  ))
                )}
              </div>
            </div>
          </div>
        )}
        {error && <div className="error-msg" style={{ marginTop: '1rem', textAlign: 'left' }}>{error}</div>}

        {createMode && activeTab !== 'sync' && (
          <div style={{ marginTop: '1rem', padding: '1rem', background: 'rgba(239, 68, 68, 0.1)', borderRadius: '8px' }}>
            <p style={{ margin: '0 0 1rem 0' }}>This patient does not exist in Satu Sehat.</p>
            <button className="btn btn-secondary" style={{ width: 'auto' }} onClick={prepareCreate}>
              Prepare Patient Creation Payload
            </button>
          </div>
        )}
      </div>

      {result && !showModal && (
        <div className="glass result-card">
          <h2 style={{ marginTop: 0 }}>Patient Details</h2>
          <div className="result-grid">
            {activeTab === 'rm' ? (
              <>
                <div className="result-item"><strong>Name</strong>{result.nm_pasien}</div>
                <div className="result-item"><strong>NIK</strong>{result.nik}</div>
                <div className="result-item"><strong>Birthdate</strong>{result.tgl_lahir}</div>
                <div className="result-item"><strong>Gender</strong>{result.jk === 'L' ? 'Male' : 'Female'}</div>
                <div className="result-item"><strong>Address</strong>{result.alamat}</div>
                <div className="result-item"><strong>Phone</strong>{result.no_tlp || '-'}</div>
                <div className="result-item" style={{ gridColumn: '1 / -1' }}>
                  <strong>IHS Number</strong>
                  {result.ihspasien ? (
                    <span className="badge success">{result.ihspasien}</span>
                  ) : (
                    <span className="badge">Not Synced</span>
                  )}
                </div>
              </>
            ) : (
              <>
                <div className="result-item">
                  <strong>Name</strong>
                  {result.name?.[0]?.text || 'N/A'}
                </div>
                <div className="result-item">
                  <strong>IHS Number</strong>
                  <span className="badge success">{result.id}</span>
                </div>
                <div className="result-item">
                  <strong>Birthdate</strong>
                  {result.birthDate || 'N/A'}
                </div>
                <div className="result-item">
                  <strong>Gender</strong>
                  {result.gender || 'N/A'}
                </div>
              </>
            )}
          </div>

          {activeTab === 'rm' && !result.ihspasien && (
            <div style={{ marginTop: '1.5rem', paddingTop: '1.5rem', borderTop: '1px solid var(--border-color)' }}>
              <p style={{ marginBottom: '1rem', fontSize: '0.9rem' }}>This patient's IHS Number is not mapped locally. Search in Satu Sehat to map it, or Create a new record.</p>
              <div style={{ display: 'flex', gap: '1rem' }}>
                <button
                  className="btn btn-secondary"
                  style={{ width: 'auto' }}
                  onClick={() => {
                    setActiveTab('nik');
                    setNik(result.nik);
                    setResult(null);
                  }}
                >
                  Find in Satu Sehat via NIK
                </button>
                <button
                  className="btn btn-secondary"
                  style={{ width: 'auto', borderColor: 'var(--danger)', color: 'var(--danger)' }}
                  onClick={prepareCreate}
                >
                  Create in Satu Sehat
                </button>
              </div>
            </div>
          )}

          <div style={{ marginTop: '1.5rem', paddingTop: '1.5rem', borderTop: '1px solid var(--border-color)', textAlign: 'left' }}>
            <button
              className="btn btn-secondary"
              style={{ width: 'auto' }}
              onClick={() => setShowRawJson(!showRawJson)}
            >
              {showRawJson ? '⚠️ Hide Raw FHIR JSON Response' : '👁️ Show Raw FHIR JSON Response'}
            </button>
            {showRawJson && (
              <pre style={{
                marginTop: '1rem',
                padding: '1rem',
                background: '#1e1e2e',
                color: '#cdd6f4',
                borderRadius: '8px',
                overflowX: 'auto',
                maxHeight: '400px',
                fontSize: '0.85rem',
                lineHeight: '1.4',
                border: '1px solid rgba(255,255,255,0.1)',
                fontFamily: 'monospace'
              }}>
                <code>{JSON.stringify(result, null, 2)}</code>
              </pre>
            )}
          </div>
        </div>
      )}

      {/* Confirmation Modal */}
      {showModal && (
        <div className="modal-overlay">
          <div className="glass modal-content" style={{ background: 'rgba(11, 10, 22, 0.95)', border: '1px solid var(--border-color)' }}>
            <h2 style={{ marginTop: 0, color: 'var(--primary-color)' }}>Confirm FHIR Payload</h2>
            <p style={{ fontSize: '0.9rem', color: 'var(--text-muted)' }}>
              Please review the auto-generated JSON payload mapped from your SIMRS database. You can manually edit it before submitting.
            </p>

            <textarea
              className="form-control"
              style={{ height: '300px', fontFamily: 'monospace', fontSize: '0.85rem', marginBottom: '1rem', background: '#0d0b18', color: '#cdd6f4', border: '1px solid var(--border-color)' }}
              value={payloadText}
              onChange={(e) => setPayloadText(e.target.value)}
            />

            <div style={{ display: 'flex', gap: '1rem', justifyContent: 'flex-end' }}>
              <button className="btn btn-secondary" style={{ width: 'auto' }} onClick={() => setShowModal(false)}>Cancel</button>
              <button className="btn" style={{ width: 'auto' }} onClick={handleCreateConfirm} disabled={loading}>
                {loading ? 'Submitting...' : 'Confirm & Submit to Satu Sehat'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
