import os
import sys
import json
import hashlib
import datetime
import logging
import time
import base64
import urllib.request
import urllib.parse

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


# =========================================================================
# Automatic DICOM-to-SIMRS Radiology Image Sync Callback
# =========================================================================

def extract_instance_id(obj):
    """
    Safely extracts the string instance ID from an Orthanc ReceivedInstance/DicomInstance object or string.
    """
    if isinstance(obj, str) and not obj.startswith('<'):
        return obj
    for method_name in ['GetInstanceId', 'GetOrthancId', 'GetId', 'GetInstance', 'id']:
        if hasattr(obj, method_name):
            try:
                fn = getattr(obj, method_name)
                val = fn() if callable(fn) else fn
                if val and isinstance(val, str) and not val.startswith('<'):
                    return val
            except Exception:
                pass
    return None


def extract_tag_val(tags, tag_name, hex_code_1, hex_code_2):
    if not isinstance(tags, dict):
        return ""
    if tag_name in tags:
        v = tags[tag_name]
        if isinstance(v, str):
            return v.strip()
        if isinstance(v, list) and len(v) > 0:
            return str(v[0]).strip()
        if isinstance(v, dict):
            val_list = v.get('Value', [])
            if isinstance(val_list, list) and len(val_list) > 0:
                return str(val_list[0]).strip()

    for hex_key in [hex_code_1, hex_code_2, hex_code_1.upper(), hex_code_2.upper(), hex_code_1.lower(), hex_code_2.lower()]:
        if hex_key in tags:
            v = tags[hex_key]
            if isinstance(v, str):
                return v.strip()
            if isinstance(v, dict):
                val_list = v.get('Value', [])
                if isinstance(val_list, list) and len(val_list) > 0:
                    return str(val_list[0]).strip()
                if 'Value' in v and isinstance(v['Value'], str):
                    return v['Value'].strip()

    return ""


def get_all_tags_dicts(instance_obj, instance_id_str):
    dicts = []
    method_names = [
        'GetInstanceSimplifiedJson', 'GetSimplifiedJson', 'GetSimplifiedTags',
        'GetInstanceJson', 'GetJson', 'GetTags'
    ]
    for m in method_names:
        if hasattr(instance_obj, m):
            try:
                fn = getattr(instance_obj, m)
                res = fn() if callable(fn) else fn
                if isinstance(res, str):
                    res = json.loads(res)
                if isinstance(res, dict):
                    dicts.append((f"method:{m}", res))
            except Exception as e:
                logger.debug(f"[AutoSync] Error calling {m}: {e}")

    if instance_id_str:
        for path in [f'/instances/{instance_id_str}/simplified-tags', f'/instances/{instance_id_str}/tags']:
            try:
                res_str = orthanc.RestApiGet(path)
                res = json.loads(res_str)
                if isinstance(res, dict):
                    dicts.append((f"rest:{path}", res))
            except Exception as e:
                logger.debug(f"[AutoSync] REST call {path} failed: {e}")

    return dicts


def extract_dicom_metadata(instance_obj, instance_id_str):
    tag_dicts = get_all_tags_dicts(instance_obj, instance_id_str)
    
    patient_id = ""
    study_date = ""
    sop_uid = ""
    study_uid = ""

    for source_name, tdict in tag_dicts:
        if not patient_id:
            patient_id = extract_tag_val(tdict, "PatientID", "0010,0020", "00100020")
        if not study_date:
            study_date = extract_tag_val(tdict, "StudyDate", "0008,0020", "00080020")
        if not sop_uid:
            sop_uid = extract_tag_val(tdict, "SOPInstanceUID", "0008,0018", "00080018")
        if not study_uid:
            study_uid = extract_tag_val(tdict, "StudyInstanceUID", "0020,000D", "0020000D")
            
        if patient_id and study_date and sop_uid and study_uid:
            break

    logger.debug(f"[AutoSync] Extracted metadata across {len(tag_dicts)} sources: PatientID='{patient_id}', StudyDate='{study_date}', SOPInstanceUID='{sop_uid}', StudyInstanceUID='{study_uid}'")
    return patient_id, study_date, sop_uid, study_uid


def resolve_orthanc_ids(instance_obj, sop_uid, study_uid):
    """
    Resolves clean (instance_id_str, parent_study_id) strings using UIDs or Orthanc REST queries.
    """
    instance_id_str = extract_instance_id(instance_obj)
    parent_study_id = None
    
    # 1. Resolve instance_id_str via /tools/find if not found directly
    if not instance_id_str and sop_uid:
        try:
            res = json.loads(orthanc.RestApiPost('/tools/find', json.dumps({
                "Level": "Instance",
                "Query": {"SOPInstanceUID": sop_uid}
            })))
            if res and len(res) > 0:
                instance_id_str = res[0]
        except Exception as err:
            logger.debug(f"[AutoSync] Could not resolve instance_id via SOPInstanceUID: {err}")
            
    # 2. Resolve parent_study_id
    if instance_id_str:
        try:
            inst_info = json.loads(orthanc.RestApiGet(f'/instances/{instance_id_str}'))
            parent_study_id = inst_info.get('ParentStudy')
        except Exception:
            pass
            
    if not parent_study_id and study_uid:
        try:
            res = json.loads(orthanc.RestApiPost('/tools/find', json.dumps({
                "Level": "Study",
                "Query": {"StudyInstanceUID": study_uid}
            })))
            if res and len(res) > 0:
                parent_study_id = res[0]
        except Exception as err:
            logger.debug(f"[AutoSync] Could not resolve study_id via StudyInstanceUID: {err}")
            
    return instance_id_str, parent_study_id


def build_acsn(noorder, kd_jenis_prw):
    raw = noorder if noorder else ""
    noorder_stripped = raw[2:] if raw.startswith('PR') else raw
    kd = kd_jenis_prw if kd_jenis_prw else ""
    return "".join(c for c in (noorder_stripped + kd) if c.isalnum() or c in ('_', '-')).strip()


def build_mwl_study_tags(matched_exam, inst_name="SIMRS KHANZA"):
    """
    Builds DICOM tags matching the exact Modality Worklist (MWL) C-FIND answer schema.
    Used for aligning Orthanc Study DICOM tags.
    """
    patient_id = matched_exam.get('no_rkm_medis', '').strip() if matched_exam.get('no_rkm_medis') else ""
    patient_name = dicom_sanitize(matched_exam.get('nm_pasien', ''))
    patient_sex = map_sex(matched_exam.get('jk', ''))
    
    tgl_l = matched_exam.get('tgl_lahir')
    patient_birth_date = tgl_l.strftime('%Y%m%d') if isinstance(tgl_l, (datetime.date, datetime.datetime)) else str(tgl_l).replace('-', '') if tgl_l else ""

    noorder = matched_exam.get('noorder', '')
    kd_jenis_prw = matched_exam.get('kd_jenis_prw', '')
    acsn = build_acsn(noorder, kd_jenis_prw) if (noorder or kd_jenis_prw) else noorder

    physician = dicom_sanitize(matched_exam.get('nm_dokter', ''))
    procedure_desc = dicom_sanitize(matched_exam.get('nm_perawatan', ''))
    clinical_diag = dicom_sanitize(matched_exam.get('diagnosa_klinis', ''))

    # Resolve Modality
    modality = "XR"
    mapping_data = load_modality_mapping()
    for item in mapping_data.get("mapping", []):
        if item.get("kd_jenis_prw") == kd_jenis_prw:
            modality = item.get("modality", "XR")
            break

    ae_title = get_ae_title(kd_jenis_prw, modality, "ORTHANC")

    tgl_p = matched_exam.get('tgl_periksa')
    study_date = tgl_p.strftime('%Y%m%d') if isinstance(tgl_p, (datetime.date, datetime.datetime)) else str(tgl_p).replace('-', '') if tgl_p else datetime.date.today().strftime('%Y%m%d')

    jam_p = matched_exam.get('jam')
    study_time = "000000"
    if jam_p:
        parts = str(jam_p).split(':')
        if len(parts) >= 2:
            study_time = f"{parts[0]:0>2}{parts[1]:0>2}00"

    replace_tags = {
        "SpecificCharacterSet": "ISO_IR 192",
        "InstitutionName": dicom_sanitize(inst_name),
        "ReferringPhysicianName": physician,
        "RequestingPhysician": physician,
        "StudyDescription": procedure_desc,
        "RequestedProcedureDescription": procedure_desc,
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
                "ScheduledStationName": "RADIOLOGI",
                "CommentsOnTheScheduledProcedureStep": clinical_diag
            }
        ]
    }

    if acsn:
        replace_tags["AccessionNumber"] = acsn
        replace_tags["RequestedProcedureID"] = acsn
        replace_tags["ScheduledProcedureStepSequence"][0]["ScheduledProcedureStepID"] = acsn

    return replace_tags


def align_orthanc_study_tags(parent_study_id, matched_exam, inst_name="SIMRS KHANZA"):
    """
    Aligns/normalizes DICOM tags on the parent Study in Orthanc using SIMRS metadata,
    matching the exact schema of Modality Worklist (MWL) answers.
    """
    try:
        if not parent_study_id:
            return
            
        replace_study_tags = build_mwl_study_tags(matched_exam, inst_name)
        if not replace_study_tags:
            return

        modify_payload = {
            "Replace": replace_study_tags,
            "KeepSource": True,
            "Force": True
        }
        
        logger.info(f"[AutoSync] Aligning DICOM study tags for StudyID={parent_study_id} matching MWL schema: {replace_study_tags}")
        orthanc.RestApiPost(f'/studies/{parent_study_id}/modify', json.dumps(modify_payload))
    except Exception as err:
        logger.error(f"[AutoSync] Failed to align DICOM study tags: {err}")


def OnStoredInstance(instanceId, instance=None):
    """
    Triggered whenever Orthanc receives a DICOM instance (e.g. from Fuji CR machine).
    1. Extracts PatientID (no_rkm_medis) and StudyDate.
    2. Queries SIMRS MariaDB for matching no_rawat, tgl_periksa, jam, and patient demographics.
    3. Aligns/enriches DICOM tags in Orthanc PACS matching MWL schema.
    4. Renders full-resolution JPEG preview via Orthanc API.
    5. Posts to SIMRS webapps service.php to insert into `gambar_radiologi` and save JPEG file (with direct disk/DB fallback).
    """
    try:
        # Resolve clean instance_id_str if possible
        instance_id_str = extract_instance_id(instanceId)
        
        # Extract metadata from multi-source tag inspection
        patient_id, study_date_raw, sop_instance_uid, study_instance_uid = extract_dicom_metadata(instanceId, instance_id_str)
        
        # Resolve clean string IDs for instance and study
        instance_id_str, parent_study_id = resolve_orthanc_ids(instanceId, sop_instance_uid, study_instance_uid)
        log_id = instance_id_str if instance_id_str else (sop_instance_uid if sop_instance_uid else "instance")
        
        if not patient_id or not study_date_raw:
            logger.debug(f"[AutoSync] Missing PatientID ('{patient_id}') or StudyDate ('{study_date_raw}') for instance {log_id}. Skipping auto-sync.")
            return
            
        # Convert DICOM StudyDate (YYYYMMDD) to YYYY-MM-DD format
        if len(study_date_raw) == 8:
            study_date = f"{study_date_raw[0:4]}-{study_date_raw[4:6]}-{study_date_raw[6:8]}"
        else:
            study_date = study_date_raw

        logger.info(f"[AutoSync] Received DICOM instance {log_id} for PatientID={patient_id}, StudyDate={study_date}")
        
        # Connect to SIMRS database via pool
        pool = get_db_pool()
        conn = pool.connection()
        matched_exam = None
        inst_name = "SIMRS KHANZA"
        
        try:
            with conn.cursor() as cursor:
                # Fetch Institution Name from setting table
                cursor.execute("SELECT nama_instansi FROM setting LIMIT 1")
                setting_row = cursor.fetchone()
                if setting_row and setting_row.get('nama_instansi'):
                    inst_name = setting_row['nama_instansi']

                # Tier 1: Search in periksa_radiologi with full patient demographics & exam info
                sql_periksa = """
                    SELECT 
                        p.no_rawat, 
                        p.tgl_periksa, 
                        p.jam,
                        r.no_rkm_medis,
                        ps.nm_pasien,
                        ps.tgl_lahir,
                        ps.jk,
                        p.kd_jenis_prw,
                        jpr.nm_perawatan,
                        d.nm_dokter,
                        pr.noorder,
                        pr.diagnosa_klinis,
                        pl.nm_poli
                    FROM periksa_radiologi p
                    JOIN reg_periksa r ON p.no_rawat = r.no_rawat
                    JOIN pasien ps ON r.no_rkm_medis = ps.no_rkm_medis
                    LEFT JOIN jns_perawatan_radiologi jpr ON p.kd_jenis_prw = jpr.kd_jenis_prw
                    LEFT JOIN dokter d ON p.kd_dokter = d.kd_dokter
                    LEFT JOIN poliklinik pl ON r.kd_poli = pl.kd_poli
                    LEFT JOIN permintaan_radiologi pr ON p.no_rawat = pr.no_rawat AND p.tgl_periksa = pr.tgl_hasil
                    WHERE r.no_rkm_medis = %s AND p.tgl_periksa = %s
                    ORDER BY p.jam DESC LIMIT 1
                """
                cursor.execute(sql_periksa, (patient_id, study_date))
                matched_exam = cursor.fetchone()
                
                # Tier 2: Fallback to permintaan_radiologi if periksa_radiologi not filled yet
                if not matched_exam:
                    sql_permintaan = """
                        SELECT 
                            pr.no_rawat, 
                            pr.tgl_permintaan AS tgl_periksa, 
                            pr.jam_permintaan AS jam,
                            r.no_rkm_medis,
                            ps.nm_pasien,
                            ps.tgl_lahir,
                            ps.jk,
                            ppr.kd_jenis_prw,
                            jpr.nm_perawatan,
                            d.nm_dokter,
                            pr.noorder,
                            pr.diagnosa_klinis,
                            pl.nm_poli
                        FROM permintaan_radiologi pr
                        JOIN reg_periksa r ON pr.no_rawat = r.no_rawat
                        JOIN pasien ps ON r.no_rkm_medis = ps.no_rkm_medis
                        LEFT JOIN permintaan_pemeriksaan_radiologi ppr ON pr.noorder = ppr.noorder
                        LEFT JOIN jns_perawatan_radiologi jpr ON ppr.kd_jenis_prw = jpr.kd_jenis_prw
                        LEFT JOIN dokter d ON pr.dokter_perujuk = d.kd_dokter
                        LEFT JOIN poliklinik pl ON r.kd_poli = pl.kd_poli
                        WHERE r.no_rkm_medis = %s AND pr.tgl_permintaan = %s
                        ORDER BY pr.jam_permintaan DESC LIMIT 1
                    """
                    cursor.execute(sql_permintaan, (patient_id, study_date))
                    matched_exam = cursor.fetchone()
        finally:
            conn.close()
            
        if not matched_exam:
            logger.info(f"[AutoSync] No active periksa/permintaan_radiologi found for PatientID={patient_id} on {study_date}. Skipping.")
            return
            
        no_rawat = matched_exam['no_rawat']
        tgl_periksa = str(matched_exam['tgl_periksa'])
        jam_periksa = str(matched_exam['jam'])
        
        logger.info(f"[AutoSync] Matched SIMRS Exam: no_rawat={no_rawat}, tgl={tgl_periksa}, jam={jam_periksa}")
        
        # Step A: Align DICOM tags on parent Study in Orthanc (matching MWL schema)
        if parent_study_id:
            align_orthanc_study_tags(parent_study_id, matched_exam, inst_name)
        
        # Step B: Get rendered JPEG preview bytes from Orthanc REST API
        jpeg_bytes = None
        if instance_id_str:
            jpeg_bytes = orthanc.RestApiGet(f'/instances/{instance_id_str}/preview')
            
        if not jpeg_bytes:
            logger.error(f"[AutoSync] Failed to render JPEG preview for instance {log_id}")
            return
            
        # Encode JPEG bytes to base64 string
        base64_jpg = base64.b64encode(jpeg_bytes).decode('utf-8')
        
        # Unique filename using SOPInstanceUID or instance_id_str
        clean_uid = sop_instance_uid.replace('.', '_') if sop_instance_uid else (instance_id_str[:12] if instance_id_str else "img")
        filename = f"CR_{clean_uid}.jpg"
        
        # Webapps upload config from env or defaults (strip quotes & whitespace)
        raw_webapps_url = os.environ.get("SIMRS_WEBAPPS_URL", "http://host.docker.internal/webapps/radiologi/pages/upload/service.php")
        raw_user = os.environ.get("SIMRS_WEBAPPS_USER", "yanghack")
        raw_pass = os.environ.get("SIMRS_WEBAPPS_PASS", "sialselamanya")

        webapps_url = raw_webapps_url.strip().strip('"').strip("'")
        web_user = raw_user.strip().strip('"').strip("'")
        web_pass = raw_pass.strip().strip('"').strip("'")
        
        payload = {
            "norawat": no_rawat,
            "tanggal": tgl_periksa,
            "jam": jam_periksa,
            "namafile": filename,
            "file": base64_jpg
        }
        
        webapps_success = False
        logger.info(f"[AutoSync] Posting image to webapps URL: '{webapps_url}' (User: '{web_user}')")

        req = urllib.request.Request(
            webapps_url,
            data=json.dumps(payload).encode('utf-8'),
            headers={
                "Content-Type": "application/json",
                "Username": web_user,
                "Password": web_pass
            },
            method="POST"
        )
        
        try:
            with urllib.request.urlopen(req, timeout=10) as resp:
                resp_data = json.loads(resp.read().decode('utf-8'))
                logger.info(f"[AutoSync] Webapps Upload Response: {resp_data}")
                if resp.status == 200:
                    webapps_success = True
        except urllib.error.HTTPError as http_err:
            logger.warn(f"[AutoSync] HTTP post to '{webapps_url}' returned HTTP {http_err.code}: {http_err.reason}. Attempting direct DB/disk fallback...")
        except Exception as http_err:
            logger.warn(f"[AutoSync] HTTP post to '{webapps_url}' failed ({http_err}). Attempting direct DB/disk fallback...")
            
        # Direct DB/disk fallback if HTTP POST fails
        if not webapps_success:
            webapps_dir = os.environ.get("SIMRS_WEBAPPS_DIR", "/var/www/html/webapps/radiologi/pages/upload").strip().strip('"').strip("'")
            if not os.path.exists(webapps_dir):
                alt_dir = "/home/malifnasrulloh/Downloads/SIMRS-Khanza-fork/webapps/radiologi/pages/upload"
                if os.path.exists(alt_dir):
                    webapps_dir = alt_dir

            if os.path.exists(webapps_dir):
                target_path = os.path.join(webapps_dir, filename)
                with open(target_path, 'wb') as f:
                    f.write(jpeg_bytes)
                logger.info(f"[AutoSync] Direct Fallback: Wrote JPEG image to {target_path}")

                rel_lokasi = f"pages/upload/{filename}"
                pool = get_db_pool()
                conn = pool.connection()
                try:
                    with conn.cursor() as cursor:
                        sql_insert = """
                            INSERT INTO gambar_radiologi (no_rawat, tgl_periksa, jam, lokasi_gambar)
                            VALUES (%s, %s, %s, %s)
                            ON DUPLICATE KEY UPDATE lokasi_gambar = VALUES(lokasi_gambar)
                        """
                        cursor.execute(sql_insert, (no_rawat, tgl_periksa, jam_periksa, rel_lokasi))
                        conn.commit()
                        logger.info(f"[AutoSync] Direct Fallback: Successfully registered {rel_lokasi} in gambar_radiologi")
                except Exception as db_err:
                    logger.error(f"[AutoSync] Direct Fallback DB insert failed: {db_err}")
                finally:
                    conn.close()
            else:
                logger.error(f"[AutoSync] Direct Fallback failed: upload directory {webapps_dir} does not exist.")

    except Exception as e:
        logger.error(f"[AutoSync] Exception in OnStoredInstance callback: {e}")

orthanc.RegisterOnStoredInstanceCallback(OnStoredInstance)
logger.info("Automatic DICOM-to-SIMRS Radiology Image Sync plugin callback registered.")
