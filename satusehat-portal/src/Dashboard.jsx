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
  { id: 'clinicalimpression', name: 'Clinical Impression' },
  { id: 'servicerequest_rad', name: 'Radiology Service Request' },
  { id: 'specimen_rad', name: 'Radiology Specimen' },
  { id: 'observation_rad', name: 'Radiology Observation' },
  { id: 'diagnosticreport_rad', name: 'Radiology Diagnostic Report' },
  { id: 'servicerequest_lab_pk', name: 'Lab PK Service Request' },
  { id: 'specimen_lab_pk', name: 'Lab PK Specimen' },
  { id: 'observation_lab_pk', name: 'Lab PK Observation' },
  { id: 'diagnosticreport_lab_pk', name: 'Lab PK Diagnostic Report' },
  { id: 'servicerequest_lab_mb', name: 'Lab MB Service Request' },
  { id: 'specimen_lab_mb', name: 'Lab MB Specimen' },
  { id: 'observation_lab_mb', name: 'Lab MB Observation' },
  { id: 'diagnosticreport_lab_mb', name: 'Lab MB Diagnostic Report' },
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
  const [searchPatient, setSearchPatient] = useState('');
  const [syncingRecordId, setSyncingRecordId] = useState(null);

  // Phase 3 Troubleshoot & Mapping Center States
  const [unmappedType, setUnmappedType] = useState('location');
  const [unmappedSearch, setUnmappedSearch] = useState('');
  const [unmappedRecords, setUnmappedRecords] = useState([]);
  const [unmappedTotal, setUnmappedTotal] = useState(0);
  const [unmappedPage, setUnmappedPage] = useState(1);
  const [unmappedLoading, setUnmappedLoading] = useState(false);
  const [mappingInputs, setMappingInputs] = useState({});
  const [savingMapping, setSavingMapping] = useState({});

  // Section Selector (Phase 5)
  const [currentSection, setCurrentSection] = useState('analytics');

  // Analytics states (Phase 5)
  const [analyticsData, setAnalyticsData] = useState(null);
  const [analyticsLoading, setAnalyticsLoading] = useState(false);
  const [analyticsError, setAnalyticsError] = useState('');

  // Logs states (Phase 5)
  const [logs, setLogs] = useState([]);
  const [logsDate, setLogsDate] = useState(new Date().toISOString().split('T')[0]);
  const [logsLevel, setLogsLevel] = useState('all');
  const [logsSearch, setLogsSearch] = useState('');
  const [logsPage, setLogsPage] = useState(1);
  const [logsTotal, setLogsTotal] = useState(0);
  const [logsLoading, setLogsLoading] = useState(false);
  const [logsError, setLogsError] = useState('');
  const [expandedLogIndex, setExpandedLogIndex] = useState(null);

  const cancelRef = useRef(false);

  const fetchAnalyticsData = async () => {
    setAnalyticsLoading(true);
    setAnalyticsError('');
    try {
      const data = await fetchApi(`${API_BASE}?action=getAnalyticsStats`);
      if (data.success) {
        setAnalyticsData(data);
      } else {
        setAnalyticsError(data.message || 'Failed to fetch analytics stats');
      }
    } catch (err) {
      setAnalyticsError(err.message || 'Error fetching analytics stats');
    } finally {
      setAnalyticsLoading(false);
    }
  };

  const fetchLogs = async (pageVal = 1) => {
    setLogsLoading(true);
    setLogsError('');
    try {
      const url = `${API_BASE}?action=getLogs&date=${logsDate}&level=${logsLevel}&search=${encodeURIComponent(logsSearch)}&page=${pageVal}&limit=50`;
      const data = await fetchApi(url);
      if (data.success) {
        setLogs(data.logs);
        setLogsTotal(data.total_count);
        setLogsPage(pageVal);
      } else {
        setLogsError(data.message || 'Failed to fetch log entries');
      }
    } catch (err) {
      setLogsError(err.message || 'Error fetching log entries');
    } finally {
      setLogsLoading(false);
    }
  };

  useEffect(() => {
    if (currentSection === 'analytics' && role === 'admin') {
      fetchAnalyticsData();
    }
    if (currentSection === 'logs' && role === 'admin') {
      fetchLogs(1);
    }
  }, [currentSection, logsDate, logsLevel]);

  useEffect(() => {
    if (currentSection === 'sync' && role === 'admin') {
      fetchSyncStats();
      fetchRecords(1);
    }
  }, [selectedResource, currentSection, statusFilter, dateFrom, dateTo]);

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
      if (searchPatient) url += `&search_patient=${encodeURIComponent(searchPatient)}`;

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

  const fetchUnmapped = async (targetPage = unmappedPage) => {
    setUnmappedLoading(true);
    setError('');
    try {
      let url = `${API_BASE}?action=getUnmappedEntities&type=${unmappedType}&page=${targetPage}&limit=10`;
      if (unmappedSearch) {
        url += `&search=${encodeURIComponent(unmappedSearch)}`;
      }
      const data = await fetchApi(url);
      if (data.success) {
        setUnmappedRecords(data.records || []);
        setUnmappedTotal(data.total_count || 0);
        setUnmappedPage(data.page || 1);
        setMappingInputs({});
      }
    } catch (err) {
      setError(err.message);
    } finally {
      setUnmappedLoading(false);
    }
  };

  const handleSaveMapping = async (key, value) => {
    if (!value || !value.trim()) {
      setError('SatuSehat ID/Code is required.');
      return;
    }
    setSavingMapping(prev => ({ ...prev, [key]: true }));
    setError('');
    try {
      const response = await fetchApi(`${API_BASE}?action=saveMapping`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ type: unmappedType, key, value })
      });
      if (response.success) {
        const timeStr = new Date().toLocaleTimeString();
        setSyncLogs(prev => [...prev, { time: timeStr, text: `Successfully mapped [${unmappedType}] key: ${key} to value: ${value}`, type: 'success' }]);
        await fetchUnmapped(unmappedPage);
      } else {
        setError(response.message || 'Failed to save mapping');
      }
    } catch (err) {
      setError(err.message);
    } finally {
      setSavingMapping(prev => ({ ...prev, [key]: false }));
    }
  };

  useEffect(() => {
    if (currentSection === 'troubleshoot' && role === 'admin') {
      fetchUnmapped(1);
    }
  }, [unmappedType, currentSection]);

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
      <div className="header glass" style={{ padding: '1.5rem 2rem', marginBottom: '1rem', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <h1 style={{ margin: 0 }}>SatuSehat Integration Portal</h1>
        <div style={{ display: 'flex', gap: '1rem', alignItems: 'center' }}>
          <span style={{ fontSize: '0.85rem', color: 'var(--text-muted)' }}>Logged in as: <strong>{username}</strong> ({role})</span>
          <button className="logout-btn" onClick={handleLogout}>Logout</button>
        </div>
      </div>

      {/* Top Navigation Bar - Phase 5 */}
      <div className="glass" style={{ display: 'flex', gap: '0.5rem', padding: '0.75rem', marginBottom: '2rem', overflowX: 'auto' }}>
        {role === 'admin' ? (
          <>
            <button
              className={`tab ${currentSection === 'analytics' ? 'active' : ''}`}
              onClick={() => { setCurrentSection('analytics'); setResult(null); setError(''); }}
              style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}
            >
              📈 Analytics
            </button>
            <button
              className={`tab ${currentSection === 'patient_search' ? 'active' : ''}`}
              onClick={() => { setCurrentSection('patient_search'); setResult(null); setError(''); }}
              style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}
            >
              🔍 Patient Search
            </button>
            <button
              className={`tab ${currentSection === 'sync' ? 'active' : ''}`}
              onClick={() => { setCurrentSection('sync'); setResult(null); setError(''); }}
              style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}
            >
              🔄 Sync Center
            </button>
            <button
              className={`tab ${currentSection === 'troubleshoot' ? 'active' : ''}`}
              onClick={() => { setCurrentSection('troubleshoot'); setResult(null); setError(''); }}
              style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}
            >
              🗺️ Mapping Center
            </button>
            <button
              className={`tab ${currentSection === 'logs' ? 'active' : ''}`}
              onClick={() => { setCurrentSection('logs'); setResult(null); setError(''); }}
              style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}
            >
              📋 Log Viewer
            </button>
          </>
        ) : (
          <button
            className={`tab active`}
            style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}
          >
            🔍 Patient Search
          </button>
        )}
      </div>

      {/* Analytics Dashboard (Phase 5) */}
      {currentSection === 'analytics' && role === 'admin' && (
        <div className="glass" style={{ padding: '2rem', textAlign: 'left', marginBottom: '2rem' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1.5rem' }}>
            <h2 style={{ margin: 0, color: 'var(--primary-color)' }}>📈 Analytics & Coverage Dashboard</h2>
            <button className="btn" style={{ width: 'auto', padding: '0.5rem 1rem', fontSize: '0.85rem' }} onClick={fetchAnalyticsData} disabled={analyticsLoading}>
              {analyticsLoading ? 'Refreshing...' : '🔄 Refresh Stats'}
            </button>
          </div>

          {analyticsError && <div className="error-msg" style={{ marginBottom: '1.5rem' }}>{analyticsError}</div>}

          {analyticsLoading && !analyticsData ? (
            <div style={{ textAlign: 'center', padding: '3rem' }}>
              <div className="spinner" style={{ margin: '0 auto 1rem auto' }}></div>
              <p style={{ color: 'var(--text-muted)' }}>Compiling synchronization analytics from databases and logs...</p>
            </div>
          ) : analyticsData ? (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '2rem' }}>
              <div className="glass" style={{ padding: '1.5rem', background: 'rgba(255,255,255,0.02)' }}>
                <h3 style={{ marginTop: 0, marginBottom: '1.5rem', color: '#cdd6f4' }}>Daily Visit Encounter Sync Trends (Last 7 Days)</h3>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', gap: '1rem', height: '200px', alignItems: 'end', borderBottom: '1px solid var(--border-color)', paddingBottom: '0.5rem' }}>
                  {analyticsData.trends.map((t, idx) => {
                    const maxVal = Math.max(...analyticsData.trends.map(x => x.total), 1);
                    const syncHeight = (t.synced / maxVal) * 100;
                    const pendingHeight = (t.pending / maxVal) * 100;
                    return (
                      <div key={idx} style={{ display: 'flex', flexDirection: 'column', height: '100%', justifyContent: 'flex-end', position: 'relative' }}>
                        <div style={{ display: 'flex', height: '100%', alignItems: 'end', gap: '4px' }}>
                          <div
                            style={{
                              height: `${syncHeight}%`,
                              backgroundColor: 'rgba(74, 222, 128, 0.85)',
                              width: '100%',
                              borderRadius: '4px 4px 0 0',
                              transition: 'height 0.3s ease'
                            }}
                            title={`Synced: ${t.synced}`}
                          />
                          <div
                            style={{
                              height: `${pendingHeight}%`,
                              backgroundColor: 'rgba(248, 113, 113, 0.85)',
                              width: '100%',
                              borderRadius: '4px 4px 0 0',
                              transition: 'height 0.3s ease'
                            }}
                            title={`Pending/Failed: ${t.pending}`}
                          />
                        </div>
                        <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)', textAlign: 'center', marginTop: '0.5rem', whiteSpace: 'nowrap' }}>
                          {t.date.substring(5)}
                        </div>
                        <div style={{ position: 'absolute', top: '-25px', left: 0, right: 0, textAlign: 'center', fontSize: '0.7rem', color: '#a6adc8' }}>
                          {t.total}
                        </div>
                      </div>
                    );
                  })}
                </div>
                <div style={{ display: 'flex', gap: '1.5rem', marginTop: '1rem', justifyContent: 'center', fontSize: '0.8rem' }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                    <div style={{ width: '12px', height: '12px', backgroundColor: 'rgba(74, 222, 128, 0.85)', borderRadius: '3px' }} />
                    <span style={{ color: '#cdd6f4' }}>Synced Visits</span>
                  </div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                    <div style={{ width: '12px', height: '12px', backgroundColor: 'rgba(248, 113, 113, 0.85)', borderRadius: '3px' }} />
                    <span style={{ color: '#cdd6f4' }}>Pending / Failed Visits</span>
                  </div>
                </div>
              </div>

              <div style={{ display: 'grid', gridTemplateColumns: '1.2fr 1fr', gap: '1.5rem' }}>
                <div className="glass" style={{ padding: '1.5rem', background: 'rgba(255,255,255,0.02)' }}>
                  <h3 style={{ marginTop: 0, marginBottom: '1rem', color: '#cdd6f4' }}>Top Common API Sync Errors (Last 3 Days)</h3>
                  {analyticsData.top_errors.length === 0 ? (
                    <div style={{ padding: '2rem', textAlign: 'center', color: 'var(--text-muted)' }}>
                      🎉 No errors logged in the last 3 days!
                    </div>
                  ) : (
                    <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
                      {analyticsData.top_errors.map((err, idx) => (
                        <div key={idx} style={{ borderBottom: '1px solid rgba(255,255,255,0.05)', paddingBottom: '0.75rem' }}>
                          <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '0.25rem' }}>
                            <span style={{ fontSize: '0.85rem', color: '#f38ba8', fontWeight: 500, fontFamily: 'monospace', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', maxWidth: '80%' }}>
                              {err.reason}
                            </span>
                            <span className="badge" style={{ backgroundColor: 'rgba(243, 139, 168, 0.15)', color: '#f38ba8' }}>
                              {err.count} times
                            </span>
                          </div>
                        </div>
                      ))}
                    </div>
                  )}
                </div>

                <div className="glass" style={{ padding: '1.5rem', background: 'rgba(255,255,255,0.02)' }}>
                  <h3 style={{ marginTop: 0, marginBottom: '1rem', color: '#cdd6f4' }}>Resource Coverage Rates (Last 7 Days)</h3>
                  <div style={{ display: 'flex', flexDirection: 'column', gap: '0.85rem', maxHeight: '300px', overflowY: 'auto', paddingRight: '0.5rem' }}>
                    {Object.entries(analyticsData.coverage).map(([resName, stats]) => (
                      <div key={resName}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.8rem', marginBottom: '0.25rem' }}>
                          <span style={{ textTransform: 'capitalize', color: '#cdd6f4' }}>{resName.replace('_', ' ')}</span>
                          <span style={{ color: 'var(--text-muted)' }}>{stats.synced}/{stats.total} ({stats.percent}%)</span>
                        </div>
                        <div style={{ height: '6px', backgroundColor: 'rgba(255,255,255,0.05)', borderRadius: '3px', overflow: 'hidden' }}>
                          <div
                            style={{
                              height: '100%',
                              width: `${stats.percent}%`,
                              backgroundColor: stats.percent === 100 ? '#4ade80' : stats.percent > 50 ? '#f9e2af' : '#f38ba8',
                              transition: 'width 0.3s ease'
                            }}
                          />
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              </div>
            </div>
          ) : (
            <div style={{ textAlign: 'center', padding: '2rem', color: 'var(--text-muted)' }}>No data available. Click Refresh.</div>
          )}
        </div>
      )}

      {currentSection === 'patient_search' && (
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
      )}
      {currentSection === 'sync' && role === 'admin' && (
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
                  <optgroup label="🏥 Core Visit & Demographics" style={{ background: '#110e1a', color: '#ffb3c1', fontWeight: 'bold' }}>
                    <option value="patient">{'Patient (NIK -> IHS)'}</option>
                    <option value="encounter">Encounter (Visit Registration)</option>
                    <option value="episodeofcare">Episode Of Care</option>
                    <option value="condition">Condition (Diagnosis)</option>
                    <option value="observationttv">Observation TTV (Vitals)</option>
                    <option value="procedure">Procedure</option>
                    <option value="allergyintolerance">Allergy Intolerance</option>
                    <option value="immunization">Immunization</option>
                  </optgroup>
                  <optgroup label="💊 Medication Services" style={{ background: '#110e1a', color: '#ffb3c1', fontWeight: 'bold' }}>
                    <option value="medication">Medication (Drug Catalog)</option>
                    <option value="medicationrequest">Medication Request (Prescription)</option>
                    <option value="medicationdispense">Medication Dispense</option>
                    <option value="medicationstatement">Medication Statement</option>
                  </optgroup>
                  <optgroup label="🔬 Laboratory Services (PK & MB)" style={{ background: '#110e1a', color: '#ffb3c1', fontWeight: 'bold' }}>
                    <option value="servicerequest_lab_pk">Lab PK Service Request</option>
                    <option value="specimen_lab_pk">Lab PK Specimen</option>
                    <option value="observation_lab_pk">Lab PK Observation</option>
                    <option value="diagnosticreport_lab_pk">Lab PK Diagnostic Report</option>
                    <option value="servicerequest_lab_mb">Lab MB Service Request</option>
                    <option value="specimen_lab_mb">Lab MB Specimen</option>
                    <option value="observation_lab_mb">Lab MB Observation</option>
                    <option value="diagnosticreport_lab_mb">Lab MB Diagnostic Report</option>
                  </optgroup>
                  <optgroup label="🩻 Radiology & Clinical Impression" style={{ background: '#110e1a', color: '#ffb3c1', fontWeight: 'bold' }}>
                    <option value="clinicalimpression">Clinical Impression</option>
                    <option value="servicerequest_rad">Radiology Service Request</option>
                    <option value="specimen_rad">Radiology Specimen</option>
                    <option value="observation_rad">Radiology Observation</option>
                    <option value="diagnosticreport_rad">Radiology Diagnostic Report</option>
                  </optgroup>
                  <optgroup label="🔥 Advanced Workflow" style={{ background: '#110e1a', color: '#ffb3c1', fontWeight: 'bold' }}>
                    <option value="workflow">Sequential Workflow Sync (Encounter Sequence)</option>
                  </optgroup>
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
                  <div style={{ flex: '1 1 200px' }}>
                    <input
                      type="text"
                      className="form-control"
                      placeholder="Patient Name, RM or NIK..."
                      value={searchPatient}
                      onChange={e => setSearchPatient(e.target.value)}
                      onKeyDown={e => e.key === 'Enter' && fetchRecords(1)}
                      style={{ background: 'rgba(22, 18, 38, 0.9)', color: '#fff', border: '1px solid var(--border-color)' }}
                    />
                  </div>
                  <div style={{ flex: '1 1 200px' }}>
                    <input
                      type="text"
                      className="form-control"
                      placeholder="General keyword/code..."
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
                              {(record.noorder || record.kd_jenis_prw || record.id_template) && (
                                <div style={{ display: 'flex', gap: '0.4rem', marginTop: '0.25rem', flexWrap: 'wrap' }}>
                                  {record.noorder && (
                                    <span style={{ fontSize: '0.7rem', padding: '0.1rem 0.3rem', borderRadius: '4px', background: 'rgba(137, 180, 250, 0.1)', color: '#89b4fa', border: '1px solid rgba(137, 180, 250, 0.2)' }}>
                                      Order: {record.noorder}
                                    </span>
                                  )}
                                  {record.kd_jenis_prw && (
                                    <span style={{ fontSize: '0.7rem', padding: '0.1rem 0.3rem', borderRadius: '4px', background: 'rgba(203, 166, 247, 0.1)', color: '#cba6f7', border: '1px solid rgba(203, 166, 247, 0.2)' }}>
                                      Type Code: {record.kd_jenis_prw}
                                    </span>
                                  )}
                                  {record.id_template && (
                                    <span style={{ fontSize: '0.7rem', padding: '0.1rem 0.3rem', borderRadius: '4px', background: 'rgba(242, 205, 205, 0.1)', color: '#f2cdcd', border: '1px solid rgba(242, 205, 205, 0.2)' }}>
                                      Template: {record.id_template}
                                    </span>
                                  )}
                                </div>
                              )}
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
                                <div style={{ display: 'flex', flexDirection: 'column', gap: '0.25rem' }}>
                                  <span style={{ display: 'inline-block', width: 'fit-content', padding: '0.2rem 0.6rem', borderRadius: '20px', fontSize: '0.75rem', background: 'rgba(248, 113, 113, 0.15)', color: '#f87171', border: '1px solid rgba(248, 113, 113, 0.3)', textShadow: '0 0 10px rgba(248, 113, 113, 0.4)' }}>
                                    Blocked
                                  </span>
                                  {record.blocked_reason && (
                                    <span
                                      onClick={() => {
                                        if (record.blocked_reason.includes('Location')) {
                                          setUnmappedType('location');
                                          setActiveTab('troubleshoot');
                                        } else if (record.blocked_reason.includes('Practitioner') || record.blocked_reason.includes('Doctor')) {
                                          setUnmappedType('practitioner');
                                          setActiveTab('troubleshoot');
                                        } else if (record.blocked_reason.includes('Medication')) {
                                          setUnmappedType('medication');
                                          setActiveTab('troubleshoot');
                                        } else if (record.blocked_reason.includes('Vaccine')) {
                                          setUnmappedType('vaccine');
                                          setActiveTab('troubleshoot');
                                        }
                                      }}
                                      style={{
                                        fontSize: '0.7rem',
                                        color: '#f87171',
                                        textDecoration: 'underline',
                                        cursor: record.blocked_reason.includes('Unmapped') ? 'pointer' : 'default',
                                        opacity: 0.8
                                      }}
                                      title={record.blocked_reason.includes('Unmapped') ? 'Click to resolve in Mapping Center' : ''}
                                    >
                                      {record.blocked_reason}
                                    </span>
                                  )}
                                </div>
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
        {currentSection === 'troubleshoot' && role === 'admin' && (
          <div className="sync-dashboard-container" style={{ textAlign: 'left' }}>
            <h2 style={{ marginTop: 0, color: 'var(--primary-color)' }}>Troubleshooting & Mapping Center</h2>
            <p style={{ color: 'var(--text-muted)', fontSize: '0.9rem', marginBottom: '1.5rem' }}>
              Identify missing master data mappings that block synchronization pipelines. Update matching IDs directly to auto-heal failed sync runs.
            </p>

            <div style={{ display: 'grid', gridTemplateColumns: '1fr 3fr', gap: '1.5rem', minHeight: '400px' }}>
              {/* Left sidebar selectors */}
              <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
                {[
                  { id: 'location', label: 'Clinics / Locations' },
                  { id: 'practitioner', label: 'Practitioners / Doctors' },
                  { id: 'medication', label: 'Medications / Drugs' },
                  { id: 'vaccine', label: 'Vaccines / Immunizations' }
                ].map(item => (
                  <button
                    key={item.id}
                    className={`tab ${unmappedType === item.id ? 'active' : ''}`}
                    onClick={() => { setUnmappedType(item.id); setUnmappedPage(1); setUnmappedSearch(''); }}
                    style={{
                      width: '100%',
                      textAlign: 'left',
                      padding: '0.75rem 1rem',
                      borderRadius: '8px',
                      background: unmappedType === item.id ? 'rgba(168, 85, 247, 0.2)' : 'rgba(255,255,255,0.05)',
                      border: '1px solid ' + (unmappedType === item.id ? 'var(--primary-color)' : 'rgba(255,255,255,0.1)'),
                      color: unmappedType === item.id ? '#fff' : 'var(--text-muted)',
                      cursor: 'pointer',
                      transition: 'all 0.2s'
                    }}
                  >
                    {item.label}
                  </button>
                ))}
              </div>

              {/* Right content panel */}
              <div className="glass" style={{ padding: '1.25rem', display: 'flex', flexDirection: 'column', gap: '1rem', background: 'rgba(255, 255, 255, 0.02)', border: '1px solid rgba(255,255,255,0.05)' }}>
                {/* Top search/filter */}
                <div style={{ display: 'flex', gap: '1rem' }}>
                  <input
                    type="text"
                    className="form-control"
                    placeholder={`Search unmapped ${unmappedType}s...`}
                    value={unmappedSearch}
                    onChange={e => setUnmappedSearch(e.target.value)}
                    style={{ background: 'rgba(0,0,0,0.4)', color: '#fff', border: '1px solid rgba(255,255,255,0.1)' }}
                  />
                  <button
                    className="btn"
                    onClick={() => fetchUnmapped(1)}
                    disabled={unmappedLoading}
                    style={{ width: 'auto', whiteSpace: 'nowrap' }}
                  >
                    {unmappedLoading ? 'Searching...' : 'Search'}
                  </button>
                </div>

                {/* Table or loading state */}
                {unmappedLoading ? (
                  <div style={{ textAlign: 'center', padding: '3rem', color: 'var(--text-muted)' }}>
                    Loading unmapped records...
                  </div>
                ) : unmappedRecords.length === 0 ? (
                  <div style={{ textAlign: 'center', padding: '3rem', color: 'var(--text-muted)', border: '1px dashed rgba(255,255,255,0.1)', borderRadius: '8px' }}>
                    No unmapped {unmappedType}s found matching filters. Everything is set up correctly!
                  </div>
                ) : (
                  <div style={{ overflowX: 'auto', flexGrow: 1 }}>
                    <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '0.9rem' }}>
                      <thead>
                        <tr style={{ borderBottom: '1px solid rgba(255,255,255,0.1)', textAlign: 'left' }}>
                          <th style={{ padding: '0.75rem', color: 'var(--text-muted)' }}>Local Key</th>
                          <th style={{ padding: '0.75rem', color: 'var(--text-muted)' }}>Name / Details</th>
                          <th style={{ padding: '0.75rem', color: 'var(--text-muted)' }}>SatuSehat Identifier / Code</th>
                        </tr>
                      </thead>
                      <tbody>
                        {unmappedRecords.map(rec => (
                          <tr key={rec.key} style={{ borderBottom: '1px solid rgba(255,255,255,0.05)' }}>
                            <td style={{ padding: '0.75rem', fontFamily: 'monospace', color: 'var(--primary-color)' }}>{rec.key}</td>
                            <td style={{ padding: '0.75rem' }}>
                              <div style={{ fontWeight: '500' }}>{rec.name}</div>
                              {rec.extra && <div style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>{rec.extra}</div>}
                            </td>
                            <td style={{ padding: '0.75rem', minWidth: '320px' }}>
                              <div style={{ display: 'flex', gap: '0.5rem' }}>
                                <input
                                  type="text"
                                  className="form-control"
                                  placeholder={
                                    unmappedType === 'location' ? 'Location UUID (e.g. 10002891)' :
                                    unmappedType === 'practitioner' ? 'Practitioner IHS ID' :
                                    unmappedType === 'medication' ? 'KFA Drug Code' : 'KFA Vaccine Code'
                                  }
                                  value={mappingInputs[rec.key] ?? ''}
                                  onChange={e => setMappingInputs(prev => ({ ...prev, [rec.key]: e.target.value }))}
                                  style={{
                                    height: '38px',
                                    padding: '0 0.75rem',
                                    fontSize: '0.85rem',
                                    background: 'rgba(0,0,0,0.3)',
                                    color: '#fff',
                                    border: '1px solid rgba(255,255,255,0.15)'
                                  }}
                                />
                                <button
                                  className="btn"
                                  onClick={() => handleSaveMapping(rec.key, mappingInputs[rec.key] ?? '')}
                                  disabled={savingMapping[rec.key]}
                                  style={{
                                    width: 'auto',
                                    height: '38px',
                                    padding: '0 1rem',
                                    fontSize: '0.85rem',
                                    background: 'var(--primary-color)',
                                    color: '#fff',
                                    border: 'none',
                                    borderRadius: '8px',
                                    cursor: 'pointer',
                                    whiteSpace: 'nowrap'
                                  }}
                                >
                                  {savingMapping[rec.key] ? 'Saving...' : 'Save'}
                                </button>
                              </div>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}

                {/* Pagination */}
                {unmappedTotal > 10 && (
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 'auto', borderTop: '1px solid rgba(255,255,255,0.05)', paddingTop: '1rem' }}>
                    <div style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>
                      Showing {(unmappedPage - 1) * 10 + 1} - {Math.min(unmappedPage * 10, unmappedTotal)} of {unmappedTotal} entries
                    </div>
                    <div style={{ display: 'flex', gap: '0.5rem' }}>
                      <button
                        className="btn btn-secondary"
                        disabled={unmappedPage <= 1 || unmappedLoading}
                        onClick={() => fetchUnmapped(unmappedPage - 1)}
                        style={{ width: 'auto', padding: '0.4rem 0.8rem', fontSize: '0.8rem' }}
                      >
                        Prev
                      </button>
                      <span style={{ alignSelf: 'center', fontSize: '0.85rem', color: '#fff' }}>Page {unmappedPage} of {Math.ceil(unmappedTotal / 10)}</span>
                      <button
                        className="btn btn-secondary"
                        disabled={unmappedPage >= Math.ceil(unmappedTotal / 10) || unmappedLoading}
                        onClick={() => fetchUnmapped(unmappedPage + 1)}
                        style={{ width: 'auto', padding: '0.4rem 0.8rem', fontSize: '0.8rem' }}
                      >
                        Next
                      </button>
                    </div>
                  </div>
                )}
              </div>
            </div>
          </div>
        )}

      {currentSection === 'logs' && role === 'admin' && (
        <div className="glass" style={{ padding: '2rem', textAlign: 'left', marginBottom: '2rem' }}>
          <h2 style={{ marginTop: 0, color: 'var(--primary-color)' }}>📋 SatuSehat Sync Log Viewer</h2>
          <p style={{ color: 'var(--text-muted)', fontSize: '0.9rem', marginBottom: '1.5rem' }}>
            Monitor live HTTP transaction logs, network payloads, and outbound FHIR responses directly from the rotating system log files.
          </p>

          {/* Filter Bar */}
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 2fr 1fr', gap: '1rem', marginBottom: '1.5rem' }}>
            <div className="form-group" style={{ marginBottom: 0 }}>
              <label style={{ fontSize: '0.75rem', marginBottom: '0.25rem', color: '#cdd6f4' }}>Select Log Date</label>
              <input 
                type="date" 
                className="form-control" 
                value={logsDate} 
                onChange={e => setLogsDate(e.target.value)} 
                style={{ height: '38px', background: 'rgba(22, 18, 38, 0.9)', color: '#fff', border: '1px solid var(--border-color)' }} 
              />
            </div>

            <div className="form-group" style={{ marginBottom: 0 }}>
              <label style={{ fontSize: '0.75rem', marginBottom: '0.25rem', color: '#cdd6f4' }}>Filter Level</label>
              <select 
                className="form-control" 
                value={logsLevel} 
                onChange={e => setLogsLevel(e.target.value)} 
                style={{ height: '38px', background: 'rgba(22, 18, 38, 0.9)', color: '#fff', border: '1px solid var(--border-color)' }}
              >
                <option value="all">All Levels</option>
                <option value="DEBUG">DEBUG</option>
                <option value="INFO">INFO</option>
                <option value="WARNING">WARNING</option>
                <option value="ERROR">ERROR</option>
              </select>
            </div>

            <div className="form-group" style={{ marginBottom: 0 }}>
              <label style={{ fontSize: '0.75rem', marginBottom: '0.25rem', color: '#cdd6f4' }}>Search Keywords</label>
              <input
                type="text"
                className="form-control"
                placeholder="Search message, visit key, or NIK..."
                value={logsSearch}
                onChange={e => setLogsSearch(e.target.value)}
                onKeyDown={e => e.key === 'Enter' && fetchLogs(1)}
                style={{ height: '38px', background: 'rgba(22, 18, 38, 0.9)', color: '#fff', border: '1px solid var(--border-color)' }}
              />
            </div>

            <div style={{ display: 'flex', alignItems: 'flex-end' }}>
              <button className="btn" onClick={() => fetchLogs(1)} disabled={logsLoading} style={{ height: '38px' }}>
                {logsLoading ? 'Filtering...' : 'Apply Filter'}
              </button>
            </div>
          </div>

          {logsError && <div className="error-msg" style={{ marginBottom: '1.5rem' }}>{logsError}</div>}

          {logsLoading ? (
            <div style={{ textAlign: 'center', padding: '3rem' }}>
              <div className="spinner" style={{ margin: '0 auto 1rem auto' }}></div>
              <p style={{ color: 'var(--text-muted)' }}>Retrieving transactions log from disk...</p>
            </div>
          ) : logs.length === 0 ? (
            <div style={{ textAlign: 'center', padding: '3rem', color: 'var(--text-muted)', border: '1px dashed var(--border-color)', borderRadius: '8px' }}>
              No log entries match the criteria for {logsDate}.
            </div>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
              <div style={{ maxHeight: '500px', overflowY: 'auto', border: '1px solid var(--border-color)', borderRadius: '8px' }}>
                {logs.map((logItem, idx) => {
                  const isExpanded = expandedLogIndex === idx;
                  const levelColors = {
                    'ERROR': '#f38ba8',
                    'WARNING': '#f9e2af',
                    'INFO': '#89b4fa',
                    'DEBUG': '#a6adc8'
                  };
                  const levelBgColors = {
                    'ERROR': 'rgba(243, 139, 168, 0.1)',
                    'WARNING': 'rgba(249, 226, 175, 0.1)',
                    'INFO': 'rgba(137, 180, 250, 0.1)',
                    'DEBUG': 'rgba(166, 173, 200, 0.1)'
                  };

                  return (
                    <div
                      key={idx}
                      style={{
                        borderBottom: '1px solid var(--border-color)',
                        background: isExpanded ? 'rgba(255,255,255,0.02)' : 'transparent',
                        transition: 'background 0.2s'
                      }}
                    >
                      <div
                        onClick={() => setExpandedLogIndex(isExpanded ? null : idx)}
                        style={{
                          display: 'grid',
                          gridTemplateColumns: '180px 100px 1fr',
                          padding: '0.75rem 1rem',
                          cursor: 'pointer',
                          alignItems: 'center',
                          fontSize: '0.85rem'
                        }}
                      >
                        <div style={{ color: 'var(--text-muted)', fontFamily: 'monospace' }}>{logItem.timestamp}</div>
                        <div>
                          <span
                            style={{
                              backgroundColor: levelBgColors[logItem.level] || 'rgba(255,255,255,0.05)',
                              color: levelColors[logItem.level] || '#cdd6f4',
                              padding: '0.15rem 0.5rem',
                              borderRadius: '4px',
                              fontSize: '0.7rem',
                              fontWeight: 'bold',
                              display: 'inline-block',
                              textAlign: 'center',
                              width: '70px'
                            }}
                          >
                            {logItem.level}
                          </span>
                        </div>
                        <div style={{
                          color: '#cdd6f4',
                          overflow: 'hidden',
                          textOverflow: 'ellipsis',
                          whiteSpace: 'nowrap',
                          paddingRight: '1rem',
                          fontFamily: 'monospace'
                        }}>
                          {logItem.message}
                          {logItem.payload && <span style={{ color: 'var(--primary-color)', marginLeft: '0.5rem', fontSize: '0.75rem' }}>[inspect payload]</span>}
                        </div>
                      </div>

                      {isExpanded && (
                        <div style={{ padding: '0 1rem 1rem 1rem', fontSize: '0.8rem', borderTop: '1px dashed rgba(255,255,255,0.05)' }}>
                          <div style={{ marginTop: '0.75rem', color: '#cdd6f4', lineHeight: 1.5 }}>
                            <strong>Message: </strong>
                            <span style={{ fontFamily: 'monospace' }}>{logItem.message}</span>
                          </div>
                          {logItem.payload && (
                            <div style={{ marginTop: '0.75rem' }}>
                              <strong style={{ color: 'var(--primary-color)' }}>FHIR / API Transaction Payload:</strong>
                              <pre style={{
                                marginTop: '0.5rem',
                                padding: '1rem',
                                background: '#1e1e2e',
                                color: '#cdd6f4',
                                borderRadius: '6px',
                                overflowX: 'auto',
                                maxHeight: '300px',
                                fontSize: '0.75rem',
                                fontFamily: 'monospace'
                              }}>
                                {JSON.stringify(logItem.payload, null, 2)}
                              </pre>
                            </div>
                          )}
                        </div>
                      )}
                    </div>
                  );
                })}
              </div>

              {/* Pagination controls */}
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: '1rem' }}>
                <div style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>
                  Found {logsTotal} log entries.
                </div>
                <div style={{ display: 'flex', gap: '0.5rem' }}>
                  <button
                    className="btn btn-secondary"
                    disabled={logsPage <= 1 || logsLoading}
                    onClick={() => fetchLogs(logsPage - 1)}
                    style={{ padding: '0.2rem 0.8rem', fontSize: '0.8rem', height: '32px', width: 'auto' }}
                  >
                    Previous
                  </button>
                  <span style={{ display: 'flex', alignItems: 'center', padding: '0 0.5rem', fontSize: '0.85rem', color: '#cdd6f4' }}>
                    Page {logsPage}
                  </span>
                  <button
                    className="btn btn-secondary"
                    disabled={logs.length < 50 || logsLoading}
                    onClick={() => fetchLogs(logsPage + 1)}
                    style={{ padding: '0.2rem 0.8rem', fontSize: '0.8rem', height: '32px', width: 'auto' }}
                  >
                    Next
                  </button>
                </div>
              </div>
            </div>
          )}
        </div>
      )}

      {currentSection === 'patient_search' && result && !showModal && (
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
