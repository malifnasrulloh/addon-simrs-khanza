import os
import sys
import json
import hashlib
import datetime
import logging
import time

# Verify database drivers are present
try:
    import pymysql
    from dbutils.pooled_db import PooledDB
except ImportError:
    print("[Python MWL] CRITICAL ERROR: pymysql or dbutils driver not found! Please check your Docker build.")
    raise

import orthanc

# --- Global Objects ---
MAPPING_PATH = "/etc/orthanc/mapping_tindakan_radiologi.iyem"

# Initialize Logging (Point 8)
# We use a named logger to distinguish MWL activities from core Orthanc logs
logger = logging.getLogger("orthanc-mwl")
logger.setLevel(logging.DEBUG)
handler = logging.StreamHandler(sys.stdout)
formatter = logging.Formatter('%(asctime)s - %(name)s - %(levelname)s - %(message)s')
handler.setFormatter(formatter)
logger.addHandler(handler)

# Initialize Connection Pool (Point 4)
# Persistent pool to avoid TCP overhead on high-frequency C-FIND requests
DB_POOL = None

def get_db_pool():
    """
    Initializes or returns the existing database connection pool.
    
    Uses environment variables for secure database access. The pool is lazy-loaded
    on the first C-FIND request.
    
    Returns:
        PooledDB: A thread-safe connection pool for MariaDB.
    """
    global DB_POOL
    if DB_POOL is None:
        try:
            host = os.environ.get("DB_HOST", "host.docker.internal")
            port = int(os.environ.get("DB_PORT", "3306"))
            user = os.environ.get("DB_USER", "root")
            password = os.environ.get("DB_PASS", "")
            db = os.environ.get("DB_NAME", "sik")
            
            logger.info(f"Initializing MariaDB connection pool for {user}@{host}:{port}/{db}")
            DB_POOL = PooledDB(
                creator=pymysql,
                mincached=2,
                maxcached=5,
                maxconnections=10,
                blocking=True,
                host=host,
                port=port,
                user=user,
                password=password,
                database=db,
                charset='utf8mb4',
                cursorclass=pymysql.cursors.DictCursor
            )
        except Exception as e:
            logger.error(f"Failed to initialize database pool: {e}")
            raise
    return DB_POOL

def load_modality_mapping():
    """
    Loads procedure-to-modality mapping from the local JSON file.
    
    Returns:
        dict: A dictionary containing default AETs and specific procedure mappings.
    """
    try:
        if os.path.exists(MAPPING_PATH):
            with open(MAPPING_PATH, 'r') as f:
                return json.load(f)
    except Exception as e:
        logger.error(f"Error loading mapping: {e}")
    return {"default_aet": {}, "mapping": []}

def get_ae_title(kd_jenis_prw, modality, fallback_aet):
    """
    Resolves the Scheduled Station AE Title for a specific procedure.
    
    Resolution Order:
    1. Custom procedure mapping in JSON.
    2. Default modality mapping in JSON.
    3. Global fallback (usually the Called AET).
    
    Args:
        kd_jenis_prw (str): Procedure code from SIMRS.
        modality (str): DICOM modality code (e.g., 'CR', 'US').
        fallback_aet (str): Default AET if no mapping matches.
        
    Returns:
        str: The resolved AE Title.
    """
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
    """
    Generates a deterministic Study Instance UID (2.25 root).
    
    Uses MD5 hashing to ensure that the same PatientID + AccessionNumber 
    always results in the same UID, maintaining consistency across re-queries.
    
    Args:
        patient_id (str): Medreg number.
        accession_number (str): Unique order identifier.
        
    Returns:
        str: A valid DICOM Study Instance UID.
    """
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
    """Maps SIMRS gender codes to DICOM standards (M/F/O)."""
    if not jk:
        return "O"
    jk_upper = jk.strip().upper()
    if jk_upper in ("L", "MALE", "M"):
        return "M"
    elif jk_upper in ("P", "FEMALE", "F"):
        return "F"
    return "O"

def dicom_sanitize(s):
    """Cleans strings for DICOM compliance (ASCII only, Uppercase)."""
    if not s:
        return ""
    return "".join(c for c in s if ord(c) < 128).strip().upper()

def OnWorklist(answers, query, issuerAet, calledAet):
    """
    Callback triggered by Orthanc on every C-FIND (MWL) request.
    
    This function performs the following:
    1. Parses incoming DICOM query tags.
    2. Constructs a dynamic SQL query to push filters (PatientID, Accession) to the DB.
    3. Fetches data from SIMRS using a connection pool.
    4. Formats results into DICOM datasets and returns them to the modality.
    """
    start_time = time.time()
    logger.info(f"C-FIND request from {issuerAet}")
    
    # Parse Query (Point 2)
    query_json = {}
    try:
        if hasattr(query, "WorklistGetDicomQuery"):
            query_json = json.loads(query.WorklistGetDicomQuery())
        elif hasattr(query, "GetDicomAsJson"):
            query_json = json.loads(query.GetDicomAsJson())
        logger.debug(f"Query tags: {json.dumps(query_json)}")
    except Exception as e:
        logger.error(f"Error parsing query: {e}")

    # Build Dynamic SQL (Point 2)
    params = []
    # Base Query
    sql = """
        SELECT p.noorder, p.no_rawat, r.no_rkm_medis, ps.nm_pasien,
               ps.tgl_lahir, ps.jk,
               j.kd_jenis_prw, j.nm_perawatan,
               p.tgl_permintaan,
               IF(p.jam_permintaan='00:00:00', '', p.jam_permintaan) AS jam_permintaan,
               p.dokter_perujuk, d.nm_dokter,
               pl.nm_poli, p.diagnosa_klinis
        FROM permintaan_radiologi p
        INNER JOIN reg_periksa r ON p.no_rawat = r.no_rawat
        INNER JOIN pasien ps ON r.no_rkm_medis = ps.no_rkm_medis
        INNER JOIN permintaan_pemeriksaan_radiologi pr ON p.noorder = pr.noorder
        INNER JOIN jns_perawatan_radiologi j ON j.kd_jenis_prw = pr.kd_jenis_prw
        INNER JOIN dokter d ON p.dokter_perujuk = d.kd_dokter
        INNER JOIN poliklinik pl ON r.kd_poli = pl.kd_poli
        WHERE p.tgl_permintaan >= CURDATE() - INTERVAL 1 DAY
    """

    # Filter by PatientID
    if "PatientID" in query_json and query_json["PatientID"]:
        q_pid = query_json["PatientID"].replace("*", "").strip()
        if q_pid:
            sql += " AND r.no_rkm_medis LIKE %s"
            params.append(f"%{q_pid}%")
            
    # Filter by AccessionNumber
    if "AccessionNumber" in query_json and query_json["AccessionNumber"]:
        q_acsn = query_json["AccessionNumber"].replace("*", "").strip()
        if q_acsn:
            # Note: The DB stores noorder, but our MWL logic creates a synthetic accession
            # We filter by base noorder here
            sql += " AND p.noorder LIKE %s"
            params.append(f"%{q_acsn}%")

    # Filter by Modality
    if "ScheduledProcedureStepSequence" in query_json:
        sps_seq = query_json["ScheduledProcedureStepSequence"]
        if isinstance(sps_seq, list) and len(sps_seq) > 0:
            sps_query = sps_seq[0]
            if "Modality" in sps_query and sps_query["Modality"]:
                # Modality filtering requires joining with mapping logic
                # For simplicity in SQL, we filter by procedure name if it contains the modality code
                # but better to do it in Python if the mapping is complex
                pass

    sql += " ORDER BY p.tgl_permintaan DESC, p.jam_permintaan DESC LIMIT 100"

    conn = None
    try:
        pool = get_db_pool()
        conn = pool.connection()
        with conn.cursor() as cursor:
            # Fetch Instansi Name once per request
            cursor.execute("SELECT nama_instansi FROM setting LIMIT 1")
            setting_row = cursor.fetchone()
            inst_name = dicom_sanitize(setting_row['nama_instansi']) if setting_row else "SIMRS PACS"
            
            # Execute Main Query
            cursor.execute(sql, params)
            rows = cursor.fetchall()
            
            query_time = time.time() - start_time
            logger.info(f"Fetched {len(rows)} rows in {query_time:.3f}s")
            
            for row in rows:
                patient_id = row['no_rkm_medis'].strip()
                patient_name = dicom_sanitize(row['nm_pasien'])
                patient_sex = map_sex(row['jk'])
                patient_birth_date = row['tgl_lahir'].strftime('%Y%m%d') if row['tgl_lahir'] else ""
                
                # Accession number logic
                raw_noorder = row['noorder']
                kd_jenis_prw = row['kd_jenis_prw']
                noorder_stripped = raw_noorder[2:] if raw_noorder.startswith('PR') else raw_noorder
                acsn = "".join(c for c in (noorder_stripped + kd_jenis_prw) if c.isalnum() or c in ('_', '-')).strip()
                
                # Modality mapping
                mapping_data = load_modality_mapping()
                modality = "XR"
                for item in mapping_data.get("mapping", []):
                    if item.get("kd_jenis_prw") == kd_jenis_prw:
                        modality = item.get("modality", "XR")
                        break
                
                # Verify modality filter if provided in query (Python side fallback)
                if "ScheduledProcedureStepSequence" in query_json:
                    q_mod = query_json["ScheduledProcedureStepSequence"][0].get("Modality", "").replace("*", "").strip()
                    if q_mod and q_mod != modality:
                        continue

                ae_title = get_ae_title(kd_jenis_prw, modality, calledAet)
                study_uid = generate_dicom_uid(patient_id, acsn)
                study_date = row['tgl_permintaan'].strftime('%Y%m%d') if row['tgl_permintaan'] else datetime.date.today().strftime('%Y%m%d')
                
                study_time = "000000"
                if row['jam_permintaan']:
                    time_parts = str(row['jam_permintaan']).split(':')
                    if len(time_parts) >= 2:
                        study_time = f"{time_parts[0]:0>2}{time_parts[1]:0>2}00"
                        
                physician = dicom_sanitize(row['nm_dokter'])
                procedure_desc = dicom_sanitize(row['nm_perawatan'])
                clinical_diag = dicom_sanitize(row['diagnosa_klinis'])
                
                dicom_tags = {
                    "SpecificCharacterSet": "ISO_IR 192",
                    "AccessionNumber": acsn,
                    "InstitutionName": inst_name,
                    "ReferringPhysicianName": physician,
                    "RequestingPhysician": physician,
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
                            "ScheduledPerformingPhysicianName": physician,
                            "ScheduledProcedureStepDescription": procedure_desc,
                            "ScheduledProcedureStepID": acsn,
                            "ScheduledStationName": "RADIOLOGI",
                            "CommentsOnTheScheduledProcedureStep": clinical_diag
                        }
                    ]
                }
                
                try:
                    dicom_content = orthanc.CreateDicom(json.dumps(dicom_tags), None, 0)
                    answers.WorklistAddAnswer(query, dicom_content)
                except Exception as ex:
                    logger.error(f"Error emitting worklist answer for {acsn}: {ex}")
                    
    except Exception as e:
        logger.error(f"Exception in worklist query: {e}")
    finally:
        if conn:
            conn.close()
    
    total_time = time.time() - start_time
    logger.debug(f"C-FIND request processed in {total_time:.3f}s")

# Register Callback
orthanc.RegisterWorklistCallback(OnWorklist)
logger.info("Dynamic C-FIND Modality Worklist Plugin successfully loaded.")
