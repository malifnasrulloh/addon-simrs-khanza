import { useState } from 'react';

const API_BASE = window.PORTAL_CONFIG?.API_URL || '/php-service/api_satusehat_portal.php';

export default function Dashboard({ token, setToken }) {
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

  const prepareCreate = () => {
    // If they searched by RM, we have rich local data
    if (activeTab === 'rm' && result) {
      setCreatePayload(mapLocalToFHIR(result));
    } else {
      // Basic fallback if searching directly by NIK
      setCreatePayload({
        resourceType: "Patient",
        meta: { profile: ["https://fhir.kemkes.go.id/r4/StructureDefinition/Patient"] },
        identifier: [{ use: "official", system: "https://fhir.kemkes.go.id/id/nik", value: nik }],
        active: true,
        name: [{ use: "official", text: "" }],
        gender: "unknown",
        birthDate: birthdate || ""
      });
    }
    setShowModal(true);
  };

  const handleCreateConfirm = async () => {
    setLoading(true);
    setError('');
    
    try {
      const data = await fetchApi(`${API_BASE}?action=createPatient`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(createPayload)
      });
      setResult(data.data);
      setShowModal(false);
      setCreateMode(false);
      // Change tab to NIK to show result directly from Satu Sehat structure
      setActiveTab('nik');
    } catch (err) {
      setError(err.message);
      setShowModal(false);
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
        </div>

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

        {error && <div className="error-msg" style={{ marginTop: '1rem', textAlign: 'left' }}>{error}</div>}
        
        {createMode && (
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
              value={JSON.stringify(createPayload, null, 2)}
              onChange={(e) => {
                try {
                  setCreatePayload(JSON.parse(e.target.value));
                } catch(err) {
                  // allow temporary invalid json while typing
                }
              }}
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
