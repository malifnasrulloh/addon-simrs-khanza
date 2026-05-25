import { useState, useRef } from 'react';

const API_BASE = window.PORTAL_CONFIG?.API_URL || '/php-service/api_satusehat_portal.php';

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

  // Background Batch Sync Dashboard States
  const [syncStats, setSyncStats] = useState(null);
  const [syncing, setSyncing] = useState(false);
  const [syncLogs, setSyncLogs] = useState([]);
  const [syncProgress, setSyncProgress] = useState(0);
  const [syncedCount, setSyncedCount] = useState(0);
  const [failedCount, setFailedCount] = useState(0);
  const [cancelRequested, setCancelRequested] = useState(false);
  
  const cancelRef = useRef(false);

  const fetchSyncStats = async () => {
    setLoading(true);
    setError('');
    try {
      const data = await fetchApi(`${API_BASE}?action=getSyncStats`);
      if (data.success) {
        setSyncStats(data.stats);
      }
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
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
    
    const initialLogs = [{ time: new Date().toLocaleTimeString(), text: 'Starting batch sync process...', type: 'info' }];
    setSyncLogs(initialLogs);

    let currentUnmapped = syncStats?.unmapped_patients || 0;
    if (currentUnmapped === 0) {
      setSyncLogs(prev => [...prev, { time: new Date().toLocaleTimeString(), text: 'No unmapped patients found. Database is fully in sync!', type: 'success' }]);
      setSyncing(false);
      return;
    }

    let processed = 0;
    const batchLimit = 5; // Chunk size

    while (processed < currentUnmapped) {
      if (cancelRef.current) {
        setSyncLogs(prev => [...prev, { time: new Date().toLocaleTimeString(), text: 'Batch sync process cancelled by administrator.', type: 'error' }]);
        break;
      }

      try {
        const response = await fetchApi(`${API_BASE}?action=triggerBatchSync&limit=${batchLimit}`, {
          method: 'POST'
        });

        if (response.success && response.synced) {
          const syncedList = response.synced;
          if (syncedList.length === 0) {
            setSyncLogs(prev => [...prev, { time: new Date().toLocaleTimeString(), text: 'All available pending records have been processed.', type: 'success' }]);
            break;
          }

          let batchSuccess = 0;
          let batchFail = 0;
          const newLogs = [];

          syncedList.forEach(item => {
            const timeStr = new Date().toLocaleTimeString();
            if (item.status === 'success') {
              batchSuccess++;
              newLogs.push({
                time: timeStr,
                text: `SUCCESS: Mapped ${item.name} (RM: ${item.rm}) to IHS ${item.ihs}`,
                type: 'success'
              });
            } else {
              batchFail++;
              newLogs.push({
                time: timeStr,
                text: `FAILED: NIK ${item.nik} for ${item.name} (${item.message || 'Not found'})`,
                type: 'error'
              });
            }
          });

          setSyncedCount(prev => prev + batchSuccess);
          setFailedCount(prev => prev + batchFail);
          setSyncLogs(prev => [...prev, ...newLogs]);

          processed += syncedList.length;
          const pct = Math.min(100, Math.round((processed / currentUnmapped) * 100));
          setSyncProgress(pct);

          setSyncStats(prev => ({
            ...prev,
            mapped_patients: prev.mapped_patients + batchSuccess,
            unmapped_patients: Math.max(0, prev.unmapped_patients - batchSuccess)
          }));
        } else {
          throw new Error(response.message || "Failed to sync batch");
        }
      } catch (err) {
        setSyncLogs(prev => [...prev, { time: new Date().toLocaleTimeString(), text: `ERROR: ${err.message}`, type: 'error' }]);
        break;
      }

      // Brief throttle delay between dynamic chunks
      await new Promise(r => setTimeout(r, 500));
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
        setResult(data.data); // data.data contains nm_pasien, nik, alamat, jk, etc.
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
    // Dynamic FHIR R4 mapping from SIMRS Khanza local table
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
      
      // If we searched by RM, result holds the local patient details
      if (activeTab === 'rm' && result) {
        localPatient = result;
      } else {
        // Search local database by NIK or NIK Ibu to fetch their rich details!
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
        // Basic fallback if patient is not in the local database at all
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
      // Change tab to NIK to show result directly from Satu Sehat structure
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
        <h1>Patient SatuSehat Portal</h1>
        <button className="logout-btn" onClick={handleLogout}>Logout</button>
      </div>

      <div className="glass search-section">
        <div className="tabs">
          <button className={`tab ${activeTab === 'rm' ? 'active' : ''}`} onClick={() => { setActiveTab('rm'); setResult(null); setError(''); }}>No Rekam Medis</button>
          <button className={`tab ${activeTab === 'nik' ? 'active' : ''}`} onClick={() => { setActiveTab('nik'); setResult(null); setError(''); }}>NIK</button>
          <button className={`tab ${activeTab === 'nik_ibu' ? 'active' : ''}`} onClick={() => { setActiveTab('nik_ibu'); setResult(null); setError(''); }}>NIK Ibu (Bayi)</button>
          {role === 'admin' && (
            <button className={`tab ${activeTab === 'sync' ? 'active' : ''}`} onClick={() => { setActiveTab('sync'); setResult(null); setError(''); fetchSyncStats(); }}>Batch Sync Manager</button>
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
        )}

        {activeTab === 'sync' && (
          <div className="sync-dashboard-container" style={{ textAlign: 'left' }}>
            <h2 style={{ marginTop: 0, color: 'var(--primary-color)' }}>🔄 Background Patient IHS Synchronization</h2>
            <p style={{ color: 'var(--text-muted)', fontSize: '0.9rem', marginBottom: '1.5rem' }}>
              Directly monitor mapping coverage and run visual throttled batches to complete missing SatuSehat IHS patient identifiers.
            </p>
            
            {syncStats && (
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: '1rem', marginBottom: '1.5rem' }}>
                <div style={{ padding: '1rem', background: 'rgba(255, 255, 255, 0.05)', borderRadius: '8px', border: '1px solid var(--border-color)' }}>
                  <div style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>TOTAL VALID PATIENTS</div>
                  <div style={{ fontSize: '1.8rem', fontWeight: 'bold', color: '#cdd6f4' }}>{syncStats.total_patients}</div>
                </div>
                <div style={{ padding: '1rem', background: 'rgba(74, 222, 128, 0.08)', borderRadius: '8px', border: '1px solid rgba(74, 222, 128, 0.2)' }}>
                  <div style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>MAPPED TO IHS</div>
                  <div style={{ fontSize: '1.8rem', fontWeight: 'bold', color: '#4ade80' }}>{syncStats.mapped_patients}</div>
                </div>
                <div style={{ padding: '1rem', background: 'rgba(248, 113, 113, 0.08)', borderRadius: '8px', border: '1px solid rgba(248, 113, 113, 0.2)' }}>
                  <div style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>UNMAPPED RECORDS</div>
                  <div style={{ fontSize: '1.8rem', fontWeight: 'bold', color: '#f87171' }}>{syncStats.unmapped_patients}</div>
                </div>
              </div>
            )}
            
            {syncStats && (
              <div style={{ background: 'rgba(255, 255, 255, 0.03)', padding: '1rem', borderRadius: '8px', border: '1px solid var(--border-color)', marginBottom: '1.5rem' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.85rem', marginBottom: '0.5rem', color: 'var(--text-muted)' }}>
                  <span>Overall Mapping Coverage</span>
                  <span style={{ fontWeight: 'bold', color: 'var(--primary-color)' }}>
                    {syncStats.total_patients > 0 ? Math.round((syncStats.mapped_patients / syncStats.total_patients) * 100) : 0}%
                  </span>
                </div>
                <div style={{ height: '8px', background: 'rgba(255, 255, 255, 0.1)', borderRadius: '4px', overflow: 'hidden' }}>
                  <div style={{ 
                    height: '100%', 
                    background: 'linear-gradient(90deg, var(--primary-color), #4ade80)', 
                    width: `${syncStats.total_patients > 0 ? (syncStats.mapped_patients / syncStats.total_patients) * 100 : 0}%`,
                    transition: 'width 0.5s ease'
                  }}></div>
                </div>
              </div>
            )}

            <div style={{ display: 'flex', gap: '1rem', marginBottom: '1.5rem', alignItems: 'center' }}>
              {!syncing ? (
                <button className="btn" style={{ width: 'auto' }} onClick={handleStartSync} disabled={loading || (syncStats && syncStats.unmapped_patients === 0)}>
                  Start Batch Sync
                </button>
              ) : (
                <button className="btn" style={{ width: 'auto', background: 'var(--danger)' }} onClick={handleCancelSync}>
                  {cancelRequested ? 'Cancelling...' : 'Cancel Sync'}
                </button>
              )}
              
              {syncing && (
                <div style={{ display: 'flex', gap: '1rem', fontSize: '0.9rem', color: 'var(--text-muted)' }}>
                  <span>Synced: <strong style={{ color: '#4ade80' }}>{syncedCount}</strong></span>
                  <span>Failed: <strong style={{ color: '#f87171' }}>{failedCount}</strong></span>
                </div>
              )}
            </div>

            {syncing && (
              <div style={{ marginBottom: '1.5rem' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.8rem', marginBottom: '0.3rem', color: 'var(--text-muted)' }}>
                  <span>Batch Syncing Progress...</span>
                  <span>{syncProgress}%</span>
                </div>
                <div style={{ height: '6px', background: 'rgba(255, 255, 255, 0.1)', borderRadius: '3px', overflow: 'hidden' }}>
                  <div style={{ height: '100%', background: 'var(--primary-color)', width: `${syncProgress}%`, transition: 'width 0.3s ease' }}></div>
                </div>
              </div>
            )}

            <div style={{ display: 'flex', flexDirection: 'column' }}>
              <div style={{ fontSize: '0.85rem', fontWeight: 'bold', marginBottom: '0.5rem', color: 'var(--text-muted)' }}>Activity Log Console</div>
              <div 
                style={{ 
                  height: '250px', 
                  background: '#1e1e2e', 
                  border: '1px solid var(--border-color)', 
                  borderRadius: '6px', 
                  padding: '1rem', 
                  fontFamily: 'monospace', 
                  fontSize: '0.8rem', 
                  overflowY: 'auto',
                  display: 'flex',
                  flexDirection: 'column',
                  gap: '0.3rem'
                }}
                ref={el => { if (el) el.scrollTop = el.scrollHeight; }}
              >
                {syncLogs.length === 0 ? (
                  <span style={{ color: '#585b70' }}>Console inactive. Click 'Start Batch Sync' to trigger.</span>
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
          <div className="glass modal-content">
            <h2 style={{ marginTop: 0, color: 'var(--primary-color)' }}>Confirm FHIR Payload</h2>
            <p style={{ fontSize: '0.9rem', color: 'var(--text-muted)' }}>
              Please review the auto-generated JSON payload mapped from your SIMRS database. You can manually edit it before submitting.
            </p>
            
            <textarea 
              className="form-control"
              style={{ height: '300px', fontFamily: 'monospace', fontSize: '0.85rem', marginBottom: '1rem' }}
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
