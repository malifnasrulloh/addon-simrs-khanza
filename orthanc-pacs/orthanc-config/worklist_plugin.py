import os
import sys
import json
import hashlib
import datetime
import subprocess

# Verify database driver is present
try:
    import pymysql
except ImportError:
    print("[Python MWL] CRITICAL ERROR: pymysql driver not found! Please check your Docker build.")
    raise

import orthanc

MAPPING_PATH = "/etc/orthanc/mapping_tindakan_radiologi.iyem"

def load_modality_mapping():
    try:
        if os.path.exists(MAPPING_PATH):
            with open(MAPPING_PATH, 'r') as f:
                return json.load(f)
    except Exception as e:
        print(f"[Python MWL] Error loading mapping: {e}")
    return {"default_aet": {}, "mapping": []}

def get_ae_title(kd_jenis_prw, modality, fallback_aet):
    mapping_data = load_modality_mapping()
    
    # 1. Check custom procedure AET mapping
    for item in mapping_data.get("mapping", []):
        if item.get("kd_jenis_prw") == kd_jenis_prw:
            if "aet" in item and item["aet"].strip():
                return item["aet"].strip()
            
    # 2. Check default modality AET mapping
    default_aet = mapping_data.get("default_aet", {})
    if modality in default_aet and default_aet[modality].strip():
        return default_aet[modality].strip()
        
    # 3. Fallback
    return fallback_aet

def generate_dicom_uid(patient_id, accession_number):
    source = f"PATIENT:{patient_id.strip()}|ACCESSION:{accession_number.strip()}"
    source_bytes = source.encode('utf-8')
    md5 = hashlib.md5(source_bytes).digest()
    b = bytearray(md5)
    
    # Set version 3 and variant RFC 4122 matching Java
    b[6] = (b[6] & 0x0f) | 0x30
    b[8] = (b[8] & 0x3f) | 0x80
    
    val = int.from_bytes(b, byteorder='big', signed=False)
    return f"2.25.{val}"

def map_sex(jk):
    if not jk:
        return "O"
    jk_upper = jk.strip().upper()
    if jk_upper in ("L", "MALE", "M"):
        return "M"
    elif jk_upper in ("P", "FEMALE", "F"):
        return "F"
    return "O"

def dicom_sanitize(s):
    if not s:
        return ""
    # Remove non-ascii and convert to uppercase for standard compliance
    return "".join(c for c in s if ord(c) < 128).strip().upper()

def get_db_connection():
    # Read environment variables injected via docker-compose
    host = os.environ.get("DB_HOST", "host.docker.internal")
    port = int(os.environ.get("DB_PORT", "3306"))
    user = os.environ.get("DB_USER", "root")
    password = os.environ.get("DB_PASS", "")
    db = os.environ.get("DB_NAME", "sik")
    
    return pymysql.connect(
        host=host,
        port=port,
        user=user,
        password=password,
        db=db,
        charset='utf8mb4',
        cursorclass=pymysql.cursors.DictCursor,
        connect_timeout=5
    )

def OnWorklist(answers, query, issuerAet, calledAet):
    print(f"[Python MWL] C-FIND request received from modality {issuerAet}")
    
    # Parse the query JSON safely
    query_json = {}
    try:
        # Try getting query tags if the method exists
        if hasattr(query, "GetDicomAsJson"):
            query_json = json.loads(query.GetDicomAsJson())
        elif hasattr(query, "GetQueryDicom"):
            # If we have raw DICOM buffer, convert to JSON
            dicom_bytes = query.GetQueryDicom()
            query_json = json.loads(orthanc.DicomBufferToJson(dicom_bytes))
        elif hasattr(query, "WorklistGetDicomQuery"):
            # New Orthanc SDK method for WorklistQuery
            query_json = json.loads(query.WorklistGetDicomQuery())
        print(f"[Python MWL] Query JSON: {json.dumps(query_json, indent=2)}")
    except Exception as e:
        print(f"[Python MWL] Error parsing query: {e}")
        
    # Connect and query SIMRS
    conn = None
    try:
        conn = get_db_connection()
        with conn.cursor() as cursor:
            # 1. Fetch Instansi Name
            cursor.execute("SELECT nama_instansi FROM setting LIMIT 1")
            setting_row = cursor.fetchone()
            inst_name = dicom_sanitize(setting_row['nama_instansi']) if setting_row else "SIMRS PACS"
            
            # 2. Fetch Radiology Orders (last 2 days)
            sql = """
                SELECT p.noorder, p.no_rawat, r.no_rkm_medis, ps.nm_pasien,
                       ps.tgl_lahir, ps.jk,
                       j.kd_jenis_prw, j.nm_perawatan,
                       p.tgl_permintaan,
                       IF(p.jam_permintaan='00:00:00', '', p.jam_permintaan) AS jam_permintaan,
                       p.dokter_perujuk, d.nm_dokter,
                       pl.nm_poli, p.diagnosa_klinis,
                       r.kd_pj, pj.png_jawab
                FROM permintaan_radiologi p
                INNER JOIN reg_periksa r ON p.no_rawat = r.no_rawat
                INNER JOIN pasien ps ON r.no_rkm_medis = ps.no_rkm_medis
                INNER JOIN permintaan_pemeriksaan_radiologi pr ON p.noorder = pr.noorder
                INNER JOIN jns_perawatan_radiologi j ON j.kd_jenis_prw = pr.kd_jenis_prw
                INNER JOIN dokter d ON p.dokter_perujuk = d.kd_dokter
                INNER JOIN poliklinik pl ON r.kd_poli = pl.kd_poli
                INNER JOIN penjab pj ON r.kd_pj = pj.kd_pj
                WHERE p.tgl_permintaan >= CURDATE() - INTERVAL 1 DAY
                ORDER BY p.tgl_permintaan DESC, p.jam_permintaan DESC
            """
            cursor.execute(sql)
            rows = cursor.fetchall()
            print(f"[Python MWL] Fetched {len(rows)} candidate orders from SIMRS")
            
            # 3. Match and emit answers
            for row in rows:
                # Format variables
                patient_id = row['no_rkm_medis'].strip()
                patient_name = dicom_sanitize(row['nm_pasien'])
                patient_sex = map_sex(row['jk'])
                
                patient_birth_date = ""
                if row['tgl_lahir']:
                    patient_birth_date = row['tgl_lahir'].strftime('%Y%m%d')
                    
                # Clean accession noorder format
                raw_noorder = row['noorder']
                kd_jenis_prw = row['kd_jenis_prw']
                
                # strip PR prefix
                noorder_stripped = raw_noorder
                if noorder_stripped.startswith('PR'):
                    noorder_stripped = noorder_stripped[2:]
                acsn_raw = noorder_stripped + kd_jenis_prw
                acsn = "".join(c for c in acsn_raw if c.isalnum() or c in ('_', '-')).strip()
                
                # Check for modality mapping from mapping file
                mapping_data = load_modality_mapping()
                modality = "XR" # Default fallback
                for item in mapping_data.get("mapping", []):
                    if item.get("kd_jenis_prw") == kd_jenis_prw:
                        modality = item.get("modality", "XR")
                        break
                        
                ae_title = get_ae_title(kd_jenis_prw, modality, calledAet)
                station_name = "BEDAH UMUM" if modality == "CR" or modality == "XR" else "RADIOLOGI"
                
                # Dynamic study UID using matching deterministic ISO 2.25 generator
                study_uid = generate_dicom_uid(patient_id, acsn)
                
                study_date = row['tgl_permintaan'].strftime('%Y%m%d') if row['tgl_permintaan'] else datetime.date.today().strftime('%Y%m%d')
                
                study_time = "000000"
                if row['jam_permintaan']:
                    # format time as HHMMSS
                    time_parts = str(row['jam_permintaan']).split(':')
                    if len(time_parts) >= 2:
                        study_time = f"{time_parts[0]:0>2}{time_parts[1]:0>2}00"
                        
                referring_physician = dicom_sanitize(row['nm_dokter'])
                requesting_physician = referring_physician
                procedure_desc = dicom_sanitize(row['nm_perawatan'])
                clinical_diag = dicom_sanitize(row['diagnosa_klinis'])
                
                # Build DICOM tags dictionary
                dicom_tags = {
                    "SpecificCharacterSet": "ISO_IR 192",  # UTF-8
                    "AccessionNumber": acsn,
                    "InstitutionName": inst_name,
                    "ReferringPhysicianName": referring_physician,
                    "RequestingPhysician": requesting_physician,
                    "PatientName": patient_name,
                    "PatientID": patient_id,
                    "PatientBirthDate": patient_birth_date,
                    "PatientSex": patient_sex,
                    "StudyInstanceUID": study_uid,
                    "StudyDate": study_date,
                    "StudyTime": study_time,
                    "RequestedProcedureDescription": procedure_desc,
                    "RequestedProcedureID": acsn,
                    "ReasonForTheRequestedProcedure": clinical_diag,
                    "RequestedProcedurePriority": "ROUTINE",
                    "ScheduledProcedureStepSequence": [
                        {
                            "Modality": modality,
                            "ScheduledStationAETitle": ae_title,
                            "ScheduledProcedureStepStartDate": study_date,
                            "ScheduledProcedureStepStartTime": study_time,
                            "ScheduledPerformingPhysicianName": requesting_physician,
                            "ScheduledProcedureStepDescription": procedure_desc,
                            "ScheduledProcedureStepID": acsn,
                            "ScheduledStationName": station_name,
                            "CommentsOnTheScheduledProcedureStep": clinical_diag
                        }
                    ]
                }
                
                # Apply C-FIND Query Filters (if requested by modality)
                matched = True
                
                # Filter by PatientID if provided
                if "PatientID" in query_json and query_json["PatientID"]:
                    q_pid = query_json["PatientID"].replace("*", "").strip()
                    if q_pid and q_pid not in patient_id:
                        matched = False
                        
                # Filter by AccessionNumber if provided
                if "AccessionNumber" in query_json and query_json["AccessionNumber"]:
                    q_acsn = query_json["AccessionNumber"].replace("*", "").strip()
                    if q_acsn and q_acsn not in acsn:
                        matched = False
                        
                # Filter by Modality in ScheduledProcedureStepSequence if provided
                if "ScheduledProcedureStepSequence" in query_json:
                    sps_seq = query_json["ScheduledProcedureStepSequence"]
                    if isinstance(sps_seq, list) and len(sps_seq) > 0:
                        sps_query = sps_seq[0]
                        if "Modality" in sps_query and sps_query["Modality"]:
                            q_mod = sps_query["Modality"].replace("*", "").strip()
                            if q_mod and q_mod != modality:
                                matched = False
                                
                if matched:
                    print(f"[Python MWL] Emitting match: Patient={patient_name}, Accession={acsn}, UID={study_uid}")
                    try:
                        # Generate DICOM bytes from JSON tags
                        dicom_content = orthanc.CreateDicom(json.dumps(dicom_tags), None, 0)
                        # Add result to Answers collection
                        answers.WorklistAddAnswer(query, dicom_content)
                    except Exception as ex:
                        print(f"[Python MWL] Error generating/adding worklist answer: {ex}")
                    
    except Exception as e:
        print(f"[Python MWL] Exception in worklist query: {e}")
    finally:
        if conn:
            conn.close()

# Register the dynamic worklist callback
orthanc.RegisterWorklistCallback(OnWorklist)
print("[Python MWL] Dynamic C-FIND Modality Worklist Plugin successfully loaded.")
