#!/usr/bin/env python3
"""
DICOM Image Generator — Python/pydicom sidecar for PHP MWL.

Reads DICOM metadata from a JSON file (written by index.php) and produces
a valid .dcm file using pydicom. Replaces the fragile PHP binary pack()
generator with pydicom's well-tested encoder.

Usage:
    /app/venv/bin/python3 gen_dicom.py <meta_json> <output_dcm>

Exit codes:
    0 — success
    1 — error (message printed to stderr)
"""

import json
import sys
import os
import numpy as np
import pydicom
from pydicom.dataset import Dataset, FileMetaDataset
from pydicom.uid import ExplicitVRLittleEndian


# SOP Class UIDs per modality (matching PHP getSopClassUid)
SOP_CLASS_UIDS = {
    "CR": "1.2.840.10008.5.1.4.1.1.1",      # ComputedRadiographyImageStorage
    "CT": "1.2.840.10008.5.1.4.1.1.2",      # CTImageStorage
    "MR": "1.2.840.10008.5.1.4.1.1.4",      # MRImageStorage
    "US": "1.2.840.10008.5.1.4.1.1.6.1",    # UltrasoundImageStorage
    "MG": "1.2.840.10008.5.1.4.1.1.1.2",    # MammographyImageStorage
    "DX": "1.2.840.10008.5.1.4.1.1.1.1",    # DigitalRadiographyImageStorage
    "XR": "1.2.840.10008.5.1.4.1.1.7",      # Secondary Capture (fallback)
}

# Default pixel params per modality (same as PHP detectModality branches)
MODALITY_PIXEL_PARAMS = {
    "CT":  {"rows": 256, "cols": 256, "photo": "MONOCHROME2", "wc": 40,  "ww": 400,  "ps": "1.0", "ba": 16, "bs": 12, "hb": 11, "pr": 0},
    "MR":  {"rows": 256, "cols": 256, "photo": "MONOCHROME2", "wc": 40,  "ww": 400,  "ps": "1.0", "ba": 16, "bs": 12, "hb": 11, "pr": 0},
    "US":  {"rows": 480, "cols": 640, "photo": "MONOCHROME2", "wc": 128, "ww": 256,  "ps": "0.5", "ba": 8,  "bs": 8,  "hb": 7,  "pr": 0},
    "CR":  {"rows": 320, "cols": 240, "photo": "MONOCHROME1", "wc": 512, "ww": 1024, "ps": "0.2", "ba": 16, "bs": 10, "hb": 9,  "pr": 0},
    "DX":  {"rows": 320, "cols": 240, "photo": "MONOCHROME1", "wc": 512, "ww": 1024, "ps": "0.2", "ba": 16, "bs": 10, "hb": 9,  "pr": 0},
    "MG":  {"rows": 320, "cols": 240, "photo": "MONOCHROME1", "wc": 512, "ww": 1024, "ps": "0.2", "ba": 16, "bs": 10, "hb": 9,  "pr": 0},
    "RF":  {"rows": 320, "cols": 240, "photo": "MONOCHROME1", "wc": 512, "ww": 1024, "ps": "0.2", "ba": 16, "bs": 10, "hb": 9,  "pr": 0},
}


def generate_gradient_pixel_data(rows, cols, bits_alloc, bits_stored, photo_interp):
    """Generate synthetic gradient pixel data matching PHP's zero-filled pixels but with a gradient pattern."""
    if bits_alloc <= 8:
        arr = np.zeros((rows, cols), dtype=np.uint8)
        max_val = (1 << bits_stored) - 1
        for r in range(rows):
            val = int((r / rows) * max_val * 0.8 + 20)
            for c in range(cols):
                cv = int((c / cols) * max_val * 0.3)
                arr[r, c] = min(val + cv, max_val)
        if photo_interp == "MONOCHROME1":
            arr = max_val - arr
        return arr.tobytes()
    else:
        arr = np.zeros((rows, cols), dtype=np.uint16)
        max_val = (1 << bits_stored) - 1
        for r in range(rows):
            val = int((r / rows) * max_val * 0.8 + 100)
            for c in range(cols):
                cv = int((c / cols) * max_val * 0.3)
                arr[r, c] = min(val + cv, max_val)
        if photo_interp == "MONOCHROME1":
            arr = max_val - arr
        return arr.tobytes()


def build_dicom(meta):
    """Build a complete DICOM dataset from metadata dict (from PHP JSON)."""

    modality = meta.get("modality", "CR")
    pixel = MODALITY_PIXEL_PARAMS.get(modality, MODALITY_PIXEL_PARAMS["CR"])

    sop_class_uid = meta.get("sopClassUid", SOP_CLASS_UIDS.get(modality, SOP_CLASS_UIDS["XR"]))
    sop_instance_uid = meta.get("sopInstanceUid", "1.2.3.4.5.6.7.8")
    study_uid = meta.get("studyUid", "")
    series_uid = meta.get("seriesUid", "")

    tgl = meta.get("tglDicom", "00000000")
    jam = meta.get("jamDicom", "000000")
    jam_dot = jam + ".000" if jam else "000000.000"

    nm_pasien = meta.get("nmPasien", "")
    dokter = meta.get("nmDokter", "")
    perawatan = meta.get("nmPerawatan", "")
    acsn = meta.get("acsn", "")
    inst_name = meta.get("instName", "")
    station_aet = meta.get("stationAet", "")
    no_rkm_medis = meta.get("noRkmMedis", "")
    birth_date = meta.get("birthDate", "")
    patient_sex = meta.get("patientSex", "O")
    diagnosa = meta.get("diagnosa", "")

    # --- File Meta Information ---
    file_meta = FileMetaDataset()
    file_meta.MediaStorageSOPClassUID = sop_class_uid
    file_meta.MediaStorageSOPInstanceUID = sop_instance_uid
    file_meta.TransferSyntaxUID = ExplicitVRLittleEndian
    file_meta.ImplementationClassUID = "1.2.392.200036.9125.5154.1"
    file_meta.ImplementationVersionName = "V2.0B"
    file_meta.SourceApplicationEntityTitle = "SIMRS_KHANZA"

    # --- Dataset ---
    ds = Dataset()
    ds.file_meta = file_meta

    # Specific Character Set
    ds.SpecificCharacterSet = "ISO_IR 100"

    # ImageType — 9-value format matching CR modality
    ds.ImageType = ["DERIVED", "PRIMARY", "POST_PROCESSED", "", "", "", "", "", "100000"]

    # SOP
    ds.SOPClassUID = sop_class_uid
    ds.SOPInstanceUID = sop_instance_uid

    # Dates/Times
    ds.StudyDate = tgl
    ds.SeriesDate = tgl
    ds.AcquisitionDate = tgl
    ds.ContentDate = tgl
    ds.StudyTime = jam_dot
    ds.SeriesTime = jam_dot
    ds.AcquisitionTime = jam_dot
    ds.ContentTime = jam_dot

    # Identifiers
    ds.AccessionNumber = acsn
    ds.Modality = modality
    ds.Manufacturer = "PYDICOM"
    if inst_name:
        ds.InstitutionName = inst_name
    ds.ReferringPhysicianName = dokter
    ds.PerformingPhysicianName = dokter
    ds.OperatorsName = dokter
    ds.StationName = station_aet
    ds.ManufacturerModelName = "SIMRS-KHANZA/1.0"
    ds.StudyDescription = perawatan
    ds.SeriesDescription = perawatan

    # Patient Module
    ds.PatientName = nm_pasien
    ds.PatientID = no_rkm_medis
    ds.PatientBirthDate = birth_date
    ds.PatientSex = patient_sex
    if diagnosa:
        ds.PatientComments = diagnosa

    # Body Part Examined
    ds.BodyPartExamined = perawatan[:16] if perawatan else "BODY"

    # Study/Series/Instance UIDs
    ds.StudyInstanceUID = study_uid
    ds.SeriesInstanceUID = series_uid
    ds.StudyID = acsn
    ds.SeriesNumber = 1
    ds.AcquisitionNumber = 1
    ds.InstanceNumber = 1

    # Image Pixel Module
    ds.SamplesPerPixel = 1
    ds.PhotometricInterpretation = pixel["photo"]
    ds.Rows = pixel["rows"]
    ds.Columns = pixel["cols"]
    ds.PixelSpacing = [float(pixel["ps"]), float(pixel["ps"])]
    ds.BitsAllocated = pixel["ba"]
    ds.BitsStored = pixel["bs"]
    ds.HighBit = pixel["hb"]
    ds.PixelRepresentation = pixel["pr"]
    ds.WindowCenter = pixel["wc"]
    ds.WindowWidth = pixel["ww"]
    ds.RescaleIntercept = "0"
    ds.RescaleSlope = "1"
    ds.RescaleType = "US"
    ds.LossyImageCompression = "00"

    # Requested Procedure info (matching PHP MWL output)
    ds.RequestedProcedureDescription = perawatan
    ds.RequestedProcedureID = acsn
    ds.add_new((0x0032, 0x1032), "PN", dokter)

    # Pixel Data
    pixel_data = generate_gradient_pixel_data(
        pixel["rows"], pixel["cols"], pixel["ba"], pixel["bs"], pixel["photo"]
    )
    ds.PixelData = pixel_data
    ds.NumberOfFrames = 1

    return ds


def main():
    if len(sys.argv) != 3:
        print("Usage: gen_dicom.py <meta_json> <output_dcm>", file=sys.stderr)
        sys.exit(1)

    meta_path = sys.argv[1]
    output_path = sys.argv[2]

    try:
        with open(meta_path, "r") as f:
            meta = json.load(f)
    except (json.JSONDecodeError, IOError) as e:
        print(f"Error reading metadata file: {e}", file=sys.stderr)
        sys.exit(1)

    try:
        ds = build_dicom(meta)
        ds.save_as(output_path, write_like_original=False)
    except Exception as e:
        print(f"Error generating DICOM file: {e}", file=sys.stderr)
        sys.exit(1)

    sys.exit(0)


if __name__ == "__main__":
    main()
