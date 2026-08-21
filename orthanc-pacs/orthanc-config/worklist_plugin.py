"""
Orthanc Python Plugin — SIMRS Khanza Radiology Integration
===========================================================
Two main features:
  1. Dynamic Modality Worklist (C-FIND) — serves MWL answers from SIMRS DB.
  2. Auto-Sync — aligns DICOM tags before storage and uploads JPEG previews
     to SIMRS webapps after storage.

Architecture:
  ReceivedInstanceCallback  →  modifies DICOM tags BEFORE Orthanc stores them
  OnStoredInstance           →  uploads JPEG preview AFTER Orthanc stores them

This two-callback design eliminates the infinite-loop problem that occurs when
using post-store /modify REST calls (which re-trigger OnStoredInstance).
"""

import os
import sys
import json
import hashlib
import datetime
import logging
import time
import base64
import threading
import urllib.request
import urllib.error
from io import BytesIO

import pymysql
from dbutils.pooled_db import PooledDB

import orthanc

# Optional: pydicom for pre-store tag modification
try:
    import pydicom
    from pydicom.uid import ExplicitVRLittleEndian
    HAS_PYDICOM = True
except ImportError:
    HAS_PYDICOM = False

# =========================================================================
#  Section 1: Shared Utilities
# =========================================================================

logger = logging.getLogger("orthanc-mwl")
logger.setLevel(logging.DEBUG)
_handler = logging.StreamHandler(sys.stdout)
_handler.setFormatter(logging.Formatter(
    "%(asctime)s - %(name)s - %(levelname)s - %(message)s"
))
logger.addHandler(_handler)




def _env(key, default=""):
    """Read an environment variable, stripping stray quotes from .env files."""
    raw = os.environ.get(key, default)
    return raw.strip().strip('"').strip("'")


# --- Database Pool -----------------------------------------------------------

DB_POOL = None


def get_db_pool():
    """Lazy-initialise a thread-safe MariaDB connection pool."""
    global DB_POOL
    if DB_POOL is None:
        host = _env("DB_HOST", "host.docker.internal")
        port = int(_env("DB_PORT", "3306"))
        user = _env("DB_USER", "root")
        password = _env("DB_PASS", "")
        db = _env("DB_NAME", "sik")
        logger.info(f"Initialising MariaDB pool -> {user}@{host}:{port}/{db}")
        DB_POOL = PooledDB(
            creator=pymysql,
            mincached=2, maxcached=5, maxconnections=10, blocking=True,
            host=host, port=port, user=user, password=password, database=db,
            charset="utf8mb4",
            cursorclass=pymysql.cursors.DictCursor,
        )
    return DB_POOL


# --- Formatters ---------------------------------------------------------------

def dicom_sanitize(s):
    """ASCII-only, uppercase, stripped — safe for DICOM VR."""
    if not s:
        return ""
    return "".join(c for c in str(s) if ord(c) < 128).strip().upper()


def map_sex(jk):
    """SIMRS gender code → DICOM PatientSex (M / F / O)."""
    if not jk:
        return "O"
    u = jk.strip().upper()
    if u in ("L", "MALE", "M"):
        return "M"
    if u in ("P", "FEMALE", "F"):
        return "F"
    return "O"


def build_acsn(noorder, kd_jenis_prw):
    """Build a deterministic AccessionNumber from SIMRS order+procedure codes."""
    raw = str(noorder) if noorder else ""
    stripped = raw[2:] if raw.startswith("PR") else raw
    kd = str(kd_jenis_prw) if kd_jenis_prw else ""
    return "".join(
        c for c in (stripped + kd) if c.isalnum() or c in ("_", "-")
    ).strip()


def generate_dicom_uid(patient_id, accession_number):
    """Deterministic Study Instance UID (2.25 root, MD5-based)."""
    source = f"PATIENT:{patient_id.strip()}|ACCESSION:{accession_number.strip()}"
    b = bytearray(hashlib.md5(source.encode("utf-8")).digest())
    b[6] = (b[6] & 0x0F) | 0x30  # version 3
    b[8] = (b[8] & 0x3F) | 0x80  # variant RFC 4122
    return f"2.25.{int.from_bytes(b, 'big', signed=False)}"


def format_study_time(jam_value):
    """Convert HH:MM:SS or timedelta to DICOM StudyTime HHMMSS."""
    if not jam_value:
        return "000000"
    parts = str(jam_value).split(":")
    if len(parts) >= 2:
        return f"{parts[0]:0>2}{parts[1]:0>2}00"
    return "000000"


def format_study_date(date_value):
    """Convert date/datetime/string to DICOM StudyDate YYYYMMDD."""
    if isinstance(date_value, (datetime.date, datetime.datetime)):
        return date_value.strftime("%Y%m%d")
    if date_value:
        return str(date_value).replace("-", "")
    return datetime.date.today().strftime("%Y%m%d")


def format_birth_date(tgl_lahir):
    """Convert birth-date to DICOM PatientBirthDate YYYYMMDD."""
    if isinstance(tgl_lahir, (datetime.date, datetime.datetime)):
        return tgl_lahir.strftime("%Y%m%d")
    if tgl_lahir:
        return str(tgl_lahir).replace("-", "")
    return ""


# --- Modality Mapping ---------------------------------------------------------

_modality_db_cache = {}

def resolve_modality(kd_jenis_prw):
    """Resolve DICOM Modality code for a given procedure code from database table satu_sehat_mapping_radiologi."""
    if not kd_jenis_prw:
        return "CR"
    
    if kd_jenis_prw in _modality_db_cache:
        return _modality_db_cache[kd_jenis_prw]

    try:
        pool = get_db_pool()
        conn = pool.connection()
        with conn.cursor() as cur:
            cur.execute(
                "SELECT modality FROM satu_sehat_mapping_radiologi WHERE kd_jenis_prw = %s AND modality IS NOT NULL AND modality != ''",
                (kd_jenis_prw,)
            )
            row = cur.fetchone()
            if row and row.get("modality"):
                mod = str(row["modality"]).strip().upper()
                _modality_db_cache[kd_jenis_prw] = mod
                conn.close()
                return mod
        conn.close()
    except Exception as e:
        logger.debug(f"DB modality resolve error for {kd_jenis_prw}: {e}")

    return "CR"


# AE Title defaults per modality — configurable via env if needed.
_DEFAULT_AET_MAP = {
    "CR": "CR_STATION",
    "DX": "DX_STATION",
    "CT": "CT_STATION",
    "MR": "MR_STATION",
    "US": "USG_STATION",
    "MG": "MG_STATION",
    "RF": "RF_STATION",
}


def get_ae_title(kd_jenis_prw, modality, fallback_aet):
    """
    Resolve Scheduled Station AE Title for a procedure.

    Resolution order:
      1. Modality-based default from _DEFAULT_AET_MAP
      2. Caller-provided fallback_aet
    """
    if modality:
        mod_upper = modality.strip().upper()
        if mod_upper in _DEFAULT_AET_MAP:
            return _DEFAULT_AET_MAP[mod_upper]
    return fallback_aet


# --- Orthanc HTTP Helper (loopback) ------------------------------------------

def call_orthanc_http(path, data=None, method="GET"):
    """
    HTTP request to Orthanc on 127.0.0.1:8042 (loopback).
    Used by background threads where orthanc.RestApi*() would deadlock.
    Tries configured credentials, then falls back to admin:changeme.
    """
    port = _env("ORTHANC_WEB_USER", "8042")  # intentional: read port below
    port = _env("ORTHANC_HTTP_PORT", "8042")
    user = _env("ORTHANC_WEB_USER", "admin")
    pwd = _env("ORTHANC_WEB_PASS", "changeme")
    url = f"http://127.0.0.1:{port}{path}"

    # Build deduplicated credential list
    creds = [(user, pwd)]
    if (user, pwd) != ("admin", "changeme"):
        creds.append(("admin", "changeme"))

    last_err = None
    for u, p in creds:
        b64 = base64.b64encode(f"{u}:{p}".encode()).decode()
        headers = {"Authorization": f"Basic {b64}"}
        body_bytes = None
        if data is not None:
            headers["Content-Type"] = "application/json"
            body_bytes = (json.dumps(data).encode() if isinstance(data, (dict, list))
                          else data)
        req = urllib.request.Request(url, data=body_bytes, headers=headers, method=method)
        try:
            with urllib.request.urlopen(req, timeout=30) as resp:
                return resp.status, resp.read()
        except urllib.error.HTTPError as e:
            if e.code == 401:
                last_err = e
                continue
            raise
    if last_err:
        raise last_err
    raise RuntimeError(f"Cannot reach Orthanc at {url}")


# --- SIMRS Exam Lookup --------------------------------------------------------

def find_simrs_exam(patient_id, study_date):
    """
    Query SIMRS DB for matching radiology exam.
    Returns (matched_exam dict, inst_name str) or (None, inst_name).
    Tier 1: periksa_radiologi.  Tier 2: permintaan_radiologi.
    """
    pool = get_db_pool()
    conn = pool.connection()
    inst_name = "SIMRS KHANZA"
    matched = None
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT nama_instansi FROM setting LIMIT 1")
            row = cur.fetchone()
            if row and row.get("nama_instansi"):
                inst_name = row["nama_instansi"]

            # Tier 1
            cur.execute("""
                SELECT p.no_rawat, p.tgl_periksa, p.jam,
                       r.no_rkm_medis, ps.nm_pasien, ps.tgl_lahir, ps.jk,
                       p.kd_jenis_prw, jpr.nm_perawatan, d.nm_dokter,
                       pr.noorder, pr.diagnosa_klinis, pl.nm_poli
                FROM periksa_radiologi p
                JOIN reg_periksa r     ON p.no_rawat = r.no_rawat
                JOIN pasien ps         ON r.no_rkm_medis = ps.no_rkm_medis
                LEFT JOIN jns_perawatan_radiologi jpr ON p.kd_jenis_prw = jpr.kd_jenis_prw
                LEFT JOIN dokter d     ON p.kd_dokter = d.kd_dokter
                LEFT JOIN poliklinik pl ON r.kd_poli = pl.kd_poli
                LEFT JOIN permintaan_radiologi pr
                          ON p.no_rawat = pr.no_rawat AND p.tgl_periksa = pr.tgl_hasil
                WHERE r.no_rkm_medis = %s AND p.tgl_periksa = %s
                ORDER BY p.jam DESC LIMIT 1
            """, (patient_id, study_date))
            matched = cur.fetchone()

            # Tier 2
            if not matched:
                cur.execute("""
                    SELECT pr.no_rawat,
                           pr.tgl_permintaan AS tgl_periksa,
                           pr.jam_permintaan AS jam,
                           r.no_rkm_medis, ps.nm_pasien, ps.tgl_lahir, ps.jk,
                           ppr.kd_jenis_prw, jpr.nm_perawatan, d.nm_dokter,
                           pr.noorder, pr.diagnosa_klinis, pl.nm_poli
                    FROM permintaan_radiologi pr
                    JOIN reg_periksa r     ON pr.no_rawat = r.no_rawat
                    JOIN pasien ps         ON r.no_rkm_medis = ps.no_rkm_medis
                    LEFT JOIN permintaan_pemeriksaan_radiologi ppr ON pr.noorder = ppr.noorder
                    LEFT JOIN jns_perawatan_radiologi jpr ON ppr.kd_jenis_prw = jpr.kd_jenis_prw
                    LEFT JOIN dokter d     ON pr.dokter_perujuk = d.kd_dokter
                    LEFT JOIN poliklinik pl ON r.kd_poli = pl.kd_poli
                    WHERE r.no_rkm_medis = %s AND pr.tgl_permintaan = %s
                    ORDER BY pr.jam_permintaan DESC LIMIT 1
                """, (patient_id, study_date))
                matched = cur.fetchone()
    finally:
        conn.close()
    return matched, inst_name


def build_mwl_tags(exam, inst_name):
    """
    Build the full set of DICOM tags matching the MWL C-FIND answer schema.
    Used both for C-FIND answers and for pre-store tag alignment.
    Returns a dict of DICOM keyword → value.
    """
    noorder = exam.get("noorder", "")
    kd = exam.get("kd_jenis_prw", "")
    acsn = build_acsn(noorder, kd)
    physician = dicom_sanitize(exam.get("nm_dokter", ""))
    proc_desc = dicom_sanitize(exam.get("nm_perawatan", ""))
    clinical = dicom_sanitize(exam.get("diagnosa_klinis", ""))
    modality = resolve_modality(kd)
    ae_title = get_ae_title(kd, modality, "ORTHANC")
    study_date = format_study_date(exam.get("tgl_periksa"))
    study_time = format_study_time(exam.get("jam"))

    tags = {
        "SpecificCharacterSet": "ISO_IR 192",
        "InstitutionName": dicom_sanitize(inst_name),
        "ReferringPhysicianName": physician,
        "RequestingPhysician": physician,
        "StudyDescription": proc_desc,
        "RequestedProcedureDescription": proc_desc,
        "ReasonForTheRequestedProcedure": clinical,
        "RequestedProcedurePriority": "ROUTINE",
        "ScheduledProcedureStepSequence": [{
            "Modality": modality,
            "ScheduledStationAETitle": ae_title,
            "ScheduledProcedureStepStartDate": study_date,
            "ScheduledProcedureStepStartTime": study_time,
            "ScheduledPerformingPhysicianName": physician,
            "ScheduledProcedureStepDescription": proc_desc,
            "ScheduledStationName": "RADIOLOGI",
            "CommentsOnTheScheduledProcedureStep": clinical,
        }],
    }
    if acsn:
        tags["AccessionNumber"] = acsn
        tags["RequestedProcedureID"] = acsn
        tags["ScheduledProcedureStepSequence"][0]["ScheduledProcedureStepID"] = acsn
    return tags


# =========================================================================
#  Section 2: Modality Worklist (C-FIND)
# =========================================================================

def OnWorklist(answers, query, issuerAet, calledAet):
    """
    C-FIND callback.  Queries SIMRS DB for pending radiology orders
    and returns matching DICOM worklist answers to the modality.
    """
    start = time.time()
    logger.info(f"C-FIND request from {issuerAet}")

    # Parse query tags
    query_json = {}
    try:
        if hasattr(query, "WorklistGetDicomQuery"):
            query_json = json.loads(query.WorklistGetDicomQuery())
        elif hasattr(query, "GetDicomAsJson"):
            query_json = json.loads(query.GetDicomAsJson())
        logger.debug(f"Query tags: {json.dumps(query_json)}")
    except Exception as e:
        logger.error(f"Error parsing query: {e}")

    # Build dynamic SQL
    params = []
    sql = """
        SELECT p.noorder, p.no_rawat, r.no_rkm_medis, ps.nm_pasien,
               ps.tgl_lahir, ps.jk,
               j.kd_jenis_prw, j.nm_perawatan,
               COALESCE(px.tgl_periksa, p.tgl_permintaan) AS tgl_periksa,
               IF(COALESCE(px.jam, p.jam_permintaan)='00:00:00', '', COALESCE(px.jam, p.jam_permintaan)) AS jam_periksa,
               p.dokter_perujuk, d.nm_dokter,
               pl.nm_poli, p.diagnosa_klinis
        FROM permintaan_radiologi p
        INNER JOIN reg_periksa r ON p.no_rawat = r.no_rawat
        INNER JOIN pasien ps ON r.no_rkm_medis = ps.no_rkm_medis
        INNER JOIN permintaan_pemeriksaan_radiologi pr ON p.noorder = pr.noorder
        INNER JOIN jns_perawatan_radiologi j ON j.kd_jenis_prw = pr.kd_jenis_prw
        INNER JOIN dokter d ON p.dokter_perujuk = d.kd_dokter
        INNER JOIN poliklinik pl ON r.kd_poli = pl.kd_poli
        LEFT JOIN periksa_radiologi px ON p.no_rawat = px.no_rawat AND j.kd_jenis_prw = px.kd_jenis_prw AND p.tgl_hasil = px.tgl_periksa
        WHERE p.tgl_permintaan >= CURDATE() - INTERVAL 1 DAY
    """

    if query_json.get("PatientID"):
        q_pid = query_json["PatientID"].replace("*", "").strip()
        if q_pid:
            sql += " AND r.no_rkm_medis LIKE %s"
            params.append(f"%{q_pid}%")

    if query_json.get("AccessionNumber"):
        q_acsn = query_json["AccessionNumber"].replace("*", "").strip()
        if q_acsn:
            sql += " AND p.noorder LIKE %s"
            params.append(f"%{q_acsn}%")

    # Extract modality filter for Python-side check
    q_modality = ""
    sps_seq = query_json.get("ScheduledProcedureStepSequence")
    if isinstance(sps_seq, list) and sps_seq:
        q_modality = sps_seq[0].get("Modality", "").replace("*", "").strip()

    sql += " ORDER BY p.tgl_permintaan DESC, p.jam_permintaan DESC LIMIT 100"

    conn = None
    try:
        pool = get_db_pool()
        conn = pool.connection()
        with conn.cursor() as cursor:
            cursor.execute("SELECT nama_instansi FROM setting LIMIT 1")
            setting_row = cursor.fetchone()
            inst_name = dicom_sanitize(setting_row["nama_instansi"]) if setting_row else "SIMRS PACS"

            cursor.execute(sql, params)
            rows = cursor.fetchall()
            logger.info(f"Fetched {len(rows)} rows in {time.time() - start:.3f}s")

            for row in rows:
                kd_jenis_prw = row["kd_jenis_prw"]
                modality = resolve_modality(kd_jenis_prw)

                # Modality filter (Python-side)
                if q_modality and q_modality != modality:
                    continue

                patient_id = row["no_rkm_medis"].strip()
                noorder_stripped = row["noorder"][2:] if row["noorder"].startswith("PR") else row["noorder"]
                acsn = "".join(
                    c for c in (noorder_stripped + kd_jenis_prw)
                    if c.isalnum() or c in ("_", "-")
                ).strip()
                ae_title = get_ae_title(kd_jenis_prw, modality, calledAet)
                study_uid = generate_dicom_uid(patient_id, acsn)
                study_date = format_study_date(row["tgl_periksa"])
                study_time = format_study_time(row["jam_periksa"])
                physician = dicom_sanitize(row["nm_dokter"])
                procedure_desc = dicom_sanitize(row["nm_perawatan"])
                clinical_diag = dicom_sanitize(row["diagnosa_klinis"])

                dicom_tags = {
                    "SpecificCharacterSet": "ISO_IR 192",
                    "AccessionNumber": acsn,
                    "InstitutionName": inst_name,
                    "ReferringPhysicianName": physician,
                    "RequestingPhysician": physician,
                    "PatientName": dicom_sanitize(row["nm_pasien"]),
                    "PatientID": patient_id,
                    "PatientBirthDate": format_birth_date(row["tgl_lahir"]),
                    "PatientSex": map_sex(row["jk"]),
                    "StudyInstanceUID": study_uid,
                    "StudyDate": study_date,
                    "StudyTime": study_time,
                    "RequestedProcedureDescription": procedure_desc,
                    "RequestedProcedureID": acsn,
                    "ReasonForTheRequestedProcedure": clinical_diag,
                    "RequestedProcedurePriority": "ROUTINE",
                    "ScheduledProcedureStepSequence": [{
                        "Modality": modality,
                        "ScheduledStationAETitle": ae_title,
                        "ScheduledProcedureStepStartDate": study_date,
                        "ScheduledProcedureStepStartTime": study_time,
                        "ScheduledPerformingPhysicianName": physician,
                        "ScheduledProcedureStepDescription": procedure_desc,
                        "ScheduledProcedureStepID": acsn,
                        "ScheduledStationName": "RADIOLOGI",
                        "CommentsOnTheScheduledProcedureStep": clinical_diag,
                    }],
                }

                try:
                    dicom_bytes = orthanc.CreateDicom(json.dumps(dicom_tags), None, 0)
                    answers.WorklistAddAnswer(query, dicom_bytes)
                except Exception as ex:
                    logger.error(f"Error emitting worklist answer for {acsn}: {ex}")

    except Exception as e:
        logger.error(f"Exception in worklist query: {e}")
    finally:
        if conn:
            conn.close()

    logger.debug(f"C-FIND processed in {time.time() - start:.3f}s")


orthanc.RegisterWorklistCallback(OnWorklist)
logger.info("Modality Worklist (C-FIND) plugin loaded.")


# =========================================================================
#  Section 3: Auto-Sync (Pre-Store Tag Alignment + Post-Store JPEG Upload)
# =========================================================================

def _apply_tags_pydicom(ds, exam, inst_name):
    """Apply SIMRS-aligned DICOM tags to a pydicom Dataset in-place."""
    noorder = exam.get("noorder", "")
    kd = exam.get("kd_jenis_prw", "")
    acsn = build_acsn(noorder, kd)
    physician = dicom_sanitize(exam.get("nm_dokter", ""))
    proc_desc = dicom_sanitize(exam.get("nm_perawatan", ""))
    clinical = dicom_sanitize(exam.get("diagnosa_klinis", ""))
    modality = resolve_modality(kd)
    ae_title = get_ae_title(kd, modality, "ORTHANC")
    study_date = format_study_date(exam.get("tgl_periksa"))
    study_time = format_study_time(exam.get("jam"))

    ds.SpecificCharacterSet = "ISO_IR 192"
    ds.InstitutionName = dicom_sanitize(inst_name)
    ds.ReferringPhysicianName = physician
    ds.RequestingPhysician = physician
    ds.StudyDescription = proc_desc
    ds.RequestedProcedureDescription = proc_desc
    ds.ReasonForTheRequestedProcedure = clinical
    ds.RequestedProcedurePriority = "ROUTINE"

    if study_date:
        ds.StudyDate = study_date
    if study_time:
        ds.StudyTime = study_time

    if acsn:
        ds.AccessionNumber = acsn
        ds.RequestedProcedureID = acsn

    # Patient demographics (ensure consistency)
    patient_name = dicom_sanitize(exam.get("nm_pasien", ""))
    if patient_name:
        ds.PatientName = patient_name
    patient_sex = map_sex(exam.get("jk", ""))
    if patient_sex:
        ds.PatientSex = patient_sex
    birth_date = format_birth_date(exam.get("tgl_lahir"))
    if birth_date:
        ds.PatientBirthDate = birth_date

    # Build ScheduledProcedureStepSequence
    sps = pydicom.Dataset()
    sps.Modality = modality
    sps.ScheduledStationAETitle = ae_title
    sps.ScheduledProcedureStepStartDate = study_date
    sps.ScheduledProcedureStepStartTime = study_time
    sps.ScheduledPerformingPhysicianName = physician
    sps.ScheduledProcedureStepDescription = proc_desc
    sps.ScheduledStationName = "RADIOLOGI"
    sps.CommentsOnTheScheduledProcedureStep = clinical
    if acsn:
        sps.ScheduledProcedureStepID = acsn
    ds.ScheduledProcedureStepSequence = [sps]

    return acsn


def _dataset_to_bytes(ds):
    """Serialise a pydicom Dataset back to DICOM bytes."""
    buf = BytesIO()
    ds.save_as(buf, write_like_original=True)
    return buf.getvalue()


# --- Callback A: Pre-Store Tag Alignment (ReceivedInstanceCallback) ----------

if HAS_PYDICOM:
    def ReceivedInstanceCallback(receivedDicom, origin):
        """
        Intercepts DICOM instances BEFORE Orthanc stores them.
        Only processes instances from DICOM protocol (modality C-STORE).
        Modifies tags in-place using pydicom, then returns MODIFY action.
        """
        # Only align instances arriving from the DICOM protocol (modalities).
        # Skip REST API uploads, Lua scripts, internal modifications, etc.
        if origin != orthanc.InstanceOrigin.DICOM_PROTOCOL:
            return orthanc.ReceivedInstanceAction.KEEP_AS_IS, receivedDicom

        try:
            ds = pydicom.dcmread(BytesIO(receivedDicom))

            # If AccessionNumber is already present and valid, DO NOT overwrite!
            existing_acsn = str(getattr(ds, "AccessionNumber", "")).strip()
            if existing_acsn and existing_acsn not in ("-", "None", ""):
                logger.info(
                    f"[AutoSync] Pre-store: Instance already has AccessionNumber='{existing_acsn}'. Keeping tags as-is."
                )
                return orthanc.ReceivedInstanceAction.KEEP_AS_IS, receivedDicom

            # Extract PatientID and StudyDate
            patient_id = str(getattr(ds, "PatientID", "")).strip()
            study_date_raw = str(getattr(ds, "StudyDate", "")).strip()

            if not patient_id or not study_date_raw:
                logger.debug(f"[AutoSync] Pre-store: Missing PatientID or StudyDate. Keeping as-is.")
                return orthanc.ReceivedInstanceAction.KEEP_AS_IS, receivedDicom

            # Convert YYYYMMDD → YYYY-MM-DD for SQL
            if len(study_date_raw) == 8:
                study_date_sql = f"{study_date_raw[:4]}-{study_date_raw[4:6]}-{study_date_raw[6:]}"
            else:
                study_date_sql = study_date_raw

            # Lookup SIMRS exam
            exam, inst_name = find_simrs_exam(patient_id, study_date_sql)
            if not exam:
                logger.debug(f"[AutoSync] Pre-store: No SIMRS exam for PID={patient_id}, date={study_date_sql}. Keeping as-is.")
                return orthanc.ReceivedInstanceAction.KEEP_AS_IS, receivedDicom

            # Apply aligned tags
            acsn = _apply_tags_pydicom(ds, exam, inst_name)
            modified_bytes = _dataset_to_bytes(ds)

            logger.info(
                f"[AutoSync] Pre-store: Aligned instance for PID={patient_id}, "
                f"AccessionNumber={acsn}, no_rawat={exam['no_rawat']}"
            )
            return orthanc.ReceivedInstanceAction.MODIFY, modified_bytes

        except Exception as e:
            logger.error(f"[AutoSync] Pre-store exception: {e}", exc_info=True)
            return orthanc.ReceivedInstanceAction.KEEP_AS_IS, receivedDicom

    orthanc.RegisterReceivedInstanceCallback(ReceivedInstanceCallback)
    logger.info("Pre-store tag alignment callback (ReceivedInstanceCallback) registered.")
else:
    logger.warning(
        "pydicom not installed -- pre-store tag alignment disabled. "
        "Install pydicom in the Docker image for best results."
    )


# --- Callback B: Post-Store JPEG Upload (OnStoredInstance) -------------------

def _upload_jpeg_background(instance_id, exam):
    """
    Background thread: fetch JPEG preview from Orthanc, POST to SIMRS webapps.
    Runs after Orthanc has committed the instance.
    """
    try:
        time.sleep(0.5)  # Let Orthanc commit the C-STORE transaction

        # Fetch rendered preview image (Orthanc returns PNG for most DICOM images)
        try:
            _, img_bytes = call_orthanc_http(
                f"/instances/{instance_id}/rendered", method="GET"
            )
            logger.info(f"[AutoSync] Rendered preview for instance {instance_id} ({len(img_bytes)} bytes)")
        except Exception as e:
            logger.error(f"[AutoSync] Failed to render preview for {instance_id}: {e}")
            return

        if not img_bytes:
            logger.error(f"[AutoSync] Empty preview for {instance_id}. Aborting upload.")
            return

        # Detect format from magic bytes: JPEG (ff d8 ff) or PNG (89 50 4e 47)
        magic = img_bytes[:4].hex()
        ext = ".jpg" if magic[:6] == "ffd8ff" else ".png"

        b64_img = base64.b64encode(img_bytes).decode()
        no_rawat = exam["no_rawat"]
        tgl = str(exam["tgl_periksa"])
        jam = str(exam["jam"])
        sop_uid = exam.get("_sop_uid", instance_id[:12])
        filename = f"CR_{sop_uid.replace('.', '_')}{ext}"
        rel_path = f"pages/upload/{filename}"

        # Pre-check: Skip if this specific image file already exists in SIMRS gambar_radiologi
        try:
            pool = get_db_pool()
            conn = pool.connection()
            with conn.cursor() as cur:
                cur.execute(
                    "SELECT COUNT(*) AS cnt FROM gambar_radiologi WHERE no_rawat = %s AND tgl_periksa = %s AND jam = %s AND (lokasi_gambar = %s OR lokasi_gambar LIKE %s)",
                    (no_rawat, tgl, jam, rel_path, f"%{filename}%")
                )
                chk = cur.fetchone()
                if chk and chk.get("cnt", 0) > 0:
                    logger.info(
                        f"[AutoSync] Image '{filename}' for no_rawat={no_rawat}, tgl={tgl}, jam={jam} already exists in SIMRS. "
                        f"Skipping duplicate upload."
                    )
                    return
            conn.close()
        except Exception as chk_err:
            logger.debug(f"[AutoSync] Pre-check query error (proceeding with post): {chk_err}")

        # POST to webapps service.php
        webapps_url = _env("SIMRS_WEBAPPS_URL",
                           "http://host.docker.internal/webapps/radiologi/pages/upload/service.php")
        web_user = _env("SIMRS_WEBAPPS_USER", "yanghack")
        web_pass = _env("SIMRS_WEBAPPS_PASS", "sialselamanya")

        payload = json.dumps({
            "norawat": no_rawat,
            "tanggal": tgl,
            "jam": jam,
            "namafile": filename,
            "file": b64_img,
        }).encode()

        logger.info(
            f"[AutoSync] Posting image -> {webapps_url} | "
            f"norawat={no_rawat}, tgl={tgl}, jam={jam}, filename={filename}, b64_len={len(b64_img)}"
        )

        req = urllib.request.Request(
            webapps_url, data=payload,
            headers={
                "Content-Type": "application/json",
                "Username": web_user,
                "Password": web_pass,
            },
            method="POST",
        )
        try:
            with urllib.request.urlopen(req, timeout=15) as resp:
                body = resp.read().decode("utf-8", errors="replace")
                logger.info(f"[AutoSync] Webapps response (HTTP {resp.status}): {body}")
                return  # success
        except urllib.error.HTTPError as e:
            err_body = e.read().decode("utf-8", errors="replace") if e.fp else ""
            logger.warning(
                f"[AutoSync] Webapps HTTP {e.code}: {err_body}. Falling back to direct DB/disk."
            )
        except Exception as e:
            logger.warning(f"[AutoSync] Webapps network error: {e}. Falling back to direct DB/disk.")

        # Fallback: direct disk write + DB insert
        webapps_dir = _env("SIMRS_WEBAPPS_DIR",
                           "/var/www/html/webapps/radiologi/pages/upload")
        if not os.path.exists(webapps_dir):
            logger.error(f"[AutoSync] Fallback dir '{webapps_dir}' not found. Upload failed.")
            return

        target = os.path.join(webapps_dir, filename)
        with open(target, "wb") as f:
            f.write(img_bytes)
        logger.info(f"[AutoSync] Wrote JPEG to disk: {target}")

        rel_path = f"pages/upload/{filename}"
        pool = get_db_pool()
        conn = pool.connection()
        try:
            with conn.cursor() as cur:
                cur.execute("""
                    INSERT INTO gambar_radiologi (no_rawat, tgl_periksa, jam, lokasi_gambar)
                    VALUES (%s, %s, %s, %s)
                    ON DUPLICATE KEY UPDATE lokasi_gambar = VALUES(lokasi_gambar)
                """, (no_rawat, tgl, jam, rel_path))
                conn.commit()
                logger.info(f"[AutoSync] Fallback: registered {rel_path} in gambar_radiologi")
        except Exception as db_err:
            logger.error(f"[AutoSync] Fallback DB insert failed: {db_err}")
        finally:
            conn.close()

    except Exception as e:
        logger.error(f"[AutoSync] Background upload exception: {e}", exc_info=True)


def is_from_webapps_converter(dicom, tags):
    """
    Determines if the DICOM instance originated from the SIMRS webapps converter
    rather than a real physical modality (CR/DX/CT/MRI/US).
    Returns True if converted from webapps (should skip re-uploading to webapps),
    Returns False if from a physical machine (should upload preview to webapps).
    """
    try:
        # Layer 1: Network origin check
        origin = dicom.GetInstanceOrigin()
        if origin != orthanc.InstanceOrigin.DICOM_PROTOCOL:
            return True  # Uploaded via REST API / Lua / Plugin -> Webapps
        
        # Check calling AE Title
        calling_aet = ""
        if hasattr(dicom, "GetOriginCallingAet"):
            calling_aet = str(dicom.GetOriginCallingAet() or "").strip().upper()
        elif hasattr(dicom, "GetOriginAet"):
            calling_aet = str(dicom.GetOriginAet() or "").strip().upper()
            
        if calling_aet in ("SIMRS_CONVERTER", "CONVERTER", "DCMCONVERTER", "PYTHON_SCU", "PYNETDICOM"):
            return True
    except Exception:
        pass

    # Layer 2: Explicit DICOM Tag Markers
    image_comments = str(tags.get("ImageComments", "")).strip().upper()
    conversion_type = str(tags.get("ConversionType", "")).strip().upper()
    sec_mfg = str(tags.get("SecondaryCaptureDeviceManufacturer", "")).strip().upper()
    
    if "SOURCE_WEBAPPS" in image_comments or conversion_type == "WSD" or "SIMRS" in sec_mfg or "CONVERTER" in sec_mfg:
        return True

    # Layer 3: Secondary Capture Signature from DCMTK
    sop_class = str(tags.get("SOPClassUID", "")).strip()
    manufacturer = str(tags.get("Manufacturer", "")).strip().upper()
    # 1.2.840.10008.5.1.4.1.1.7 is Secondary Capture Image Storage
    if sop_class == "1.2.840.10008.5.1.4.1.1.7" and ("OFFIS" in manufacturer or "DCMTK" in manufacturer or not manufacturer):
        return True

    return False


def OnStoredInstance(dicom, instanceId):
    """
    Post-store callback. Runs AFTER Orthanc has stored the instance.
    If pre-store alignment applied tags (AccessionNumber matches SIMRS pattern),
    spawns a background thread to render and upload JPEG preview.

    Parameters (Orthanc SDK order):
        dicom:      orthanc.DicomInstance — in-memory DICOM object
        instanceId: str — Orthanc instance ID (stable, no /modify was called)
    """
    try:
        # Extract tags from the DicomInstance C++ object (no REST calls!)
        tags = {}
        try:
            tags = json.loads(dicom.GetInstanceSimplifiedJson())
        except Exception:
            pass

        patient_id = tags.get("PatientID", "").strip()
        study_date_raw = tags.get("StudyDate", "").strip()
        acsn = tags.get("AccessionNumber", "").strip()
        sop_uid = tags.get("SOPInstanceUID", "")

        if not patient_id or not study_date_raw:
            return  # Unidentifiable instance — nothing to do

        # 3-Layer Check: If instance originated from webapps converter, skip preview upload!
        if is_from_webapps_converter(dicom, tags):
            logger.info(
                f"[AutoSync] Instance {instanceId} originated from webapps converter. "
                f"Skipping duplicate upload to webapps."
            )
            return

        # Convert YYYYMMDD → YYYY-MM-DD for SQL
        if len(study_date_raw) == 8:
            study_date_sql = f"{study_date_raw[:4]}-{study_date_raw[4:6]}-{study_date_raw[6:]}"
        else:
            study_date_sql = study_date_raw

        # Look up matching SIMRS exam
        exam, _ = find_simrs_exam(patient_id, study_date_sql)
        if not exam:
            return  # No matching exam — nothing to upload

        # Check if AccessionNumber matches expected (i.e. tags were aligned)
        expected_acsn = build_acsn(
            exam.get("noorder", ""), exam.get("kd_jenis_prw", "")
        )

        if HAS_PYDICOM:
            # With pydicom, pre-store alignment should have set AccessionNumber.
            # Only upload if alignment was applied.
            if not (expected_acsn and acsn and acsn.upper() == expected_acsn.upper()):
                logger.debug(
                    f"[AutoSync] Instance {instanceId} AccessionNumber '{acsn}' "
                    f"!= expected '{expected_acsn}'. Not aligned; skipping upload."
                )
                return
        # Without pydicom, we still try to upload for any matching exam

        # Stash SOPInstanceUID for filename generation
        exam["_sop_uid"] = sop_uid

        logger.info(
            f"[AutoSync] Instance {instanceId} matched exam no_rawat={exam['no_rawat']}. "
            f"Spawning JPEG upload thread."
        )
        t = threading.Thread(
            target=_upload_jpeg_background, args=(instanceId, exam), daemon=True
        )
        t.start()

    except Exception as e:
        logger.error(f"[AutoSync] OnStoredInstance exception: {e}", exc_info=True)


orthanc.RegisterOnStoredInstanceCallback(OnStoredInstance)
logger.info("Post-store JPEG upload callback (OnStoredInstance) registered.")
