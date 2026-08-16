<?php

/**
 * ServiceTypeTerminology - Encounter.serviceType mapping per the official
 * SATUSEHAT terminology appendix:
 *   "Lampiran Terminologi Encounter.serviceType Code FHIR Terminology.xlsx"
 *   (RL 3.2 / RL 3.5 / RL 3.10 - Jenis Kegiatan → Encounter.serviceType)
 *
 * Three code systems are used by SATUSEHAT for serviceType:
 *   - http://terminology.hl7.org/CodeSystem/service-type
 *   - http://snomed.info/sct
 *   - http://terminology.kemkes.go.id   (codes TK0002xx)
 *
 * The SIMRS (Khanza) does not carry kd_jns_kegiatan in poliklinik, so the
 * resolver matches on kd_poli patterns and poliklinik name keywords, then
 * falls back to a status-based appendix code.
 *
 * @author malifnasrulloh
 */
declare(strict_types=1);

class ServiceTypeTerminology
{
    public const SYSTEM_HL7 = 'http://terminology.hl7.org/CodeSystem/service-type';
    public const SYSTEM_SNOMED = 'http://snomed.info/sct';
    public const SYSTEM_KEMKES = 'http://terminology.kemkes.go.id';

    /**
     * kd_poli / nm_poli keyword (normalized: uppercase, alnum only)
     * → [system, code, display] exactly as listed in the appendix.
     *
     * Order matters: first match wins; more specific keywords must precede
     * generic ones (e.g. 'BEDAH SARAF' before 'SARAF', 'NEUROBEDAH' too).
     */
    private const KEYWORD_MAP = [
        // Emergency & admissions
        'IGDK'            => [self::SYSTEM_HL7, '117.0', 'Emergency Medical'],
        'IGD'             => [self::SYSTEM_HL7, '117.0', 'Emergency Medical'],
        'DARURAT'         => [self::SYSTEM_HL7, '117.0', 'Emergency Medical'],
        'UMUM'            => [self::SYSTEM_HL7, '124.0', 'General Practice'],
        'MCU'             => [self::SYSTEM_KEMKES, 'TK000322', 'Medical Check Up Service'],
        'MEDICALCHECKUP'  => [self::SYSTEM_KEMKES, 'TK000322', 'Medical Check Up Service'],

        // Surgical
        'BEDAH SARAF'     => [self::SYSTEM_HL7, '216.0', 'Neurosurgery'],
        'NEUROBEDAH'      => [self::SYSTEM_HL7, '216.0', 'Neurosurgery'],
        'BEDAH ORTOPEDI'  => [self::SYSTEM_HL7, '218.0', 'Orthopaedic Surgery'],
        'ORTOPEDI'        => [self::SYSTEM_HL7, '218.0', 'Orthopaedic Surgery'],
        'LUKA BAKAR'      => [self::SYSTEM_SNOMED, '1255914002', 'Burns service'],
        'BEDAH'           => [self::SYSTEM_HL7, '221.0', 'Surgery - General'],
        'OPERASI'         => [self::SYSTEM_HL7, '221.0', 'Surgery - General'],
        'OK'              => [self::SYSTEM_HL7, '221.0', 'Surgery - General'],

        // Medicine subspecialties
        'PENYAKIT DALAM'  => [self::SYSTEM_SNOMED, '792848000', 'Internal medicine service'],
        'INTERNIS'        => [self::SYSTEM_SNOMED, '792848000', 'Internal medicine service'],
        'JANTUNG'         => [self::SYSTEM_HL7, '165.0', 'Cardiology'],
        'KARDIOLOGI'      => [self::SYSTEM_HL7, '165.0', 'Cardiology'],
        'PARU'            => [self::SYSTEM_SNOMED, '3601000175107', 'Pulmonary service'],
        'SARAF'           => [self::SYSTEM_HL7, '177.0', 'Neurology'],
        'STROKE'          => [self::SYSTEM_HL7, '454.0', 'Stroke'],
        'KANKER'          => [self::SYSTEM_HL7, '175.0', 'Medical Oncology'],
        'ONKOLOGI'        => [self::SYSTEM_HL7, '175.0', 'Medical Oncology'],
        'UROLOGI'         => [self::SYSTEM_HL7, '222.0', 'Urology'],
        'URONE'           => [self::SYSTEM_HL7, '222.0', 'Urology'],
        'GINJAL'          => [self::SYSTEM_HL7, '176.0', 'Nephrology'],
        'NEFROLOGI'       => [self::SYSTEM_HL7, '176.0', 'Nephrology'],
        'GERIATRI'        => [self::SYSTEM_HL7, '171.0', 'Geriatric Medicine'],
        'KULIT'           => [self::SYSTEM_HL7, '168.0', 'Dermatology'],
        'DERMATO'         => [self::SYSTEM_HL7, '168.0', 'Dermatology'],
        'KUSTA'           => [self::SYSTEM_KEMKES, 'TK000283', 'Leprosy service'],
        'ISOLASI'         => [self::SYSTEM_KEMKES, 'TK000287', 'Isolation/Quarantine Service'],

        // Children
        'ANAK'            => [self::SYSTEM_SNOMED, '310660004', 'Paediatric service'],
        'PEDIATRI'        => [self::SYSTEM_SNOMED, '310660004', 'Paediatric service'],
        'REMAJA'          => [self::SYSTEM_HL7, '283.0', 'Child And Adolescent'],

        // Woman & reproductive
        'OBSGYN'          => [self::SYSTEM_HL7, '186.0', 'Obstetrics & Gynaecology'],
        'KANDUNGAN'       => [self::SYSTEM_HL7, '482.0', 'Obstetrics'],
        'OBSTETRI'        => [self::SYSTEM_HL7, '482.0', 'Obstetrics'],
        'GINEKO'          => [self::SYSTEM_SNOMED, '310061009', 'Gynaecology service'],
        'KB'              => [self::SYSTEM_SNOMED, '310031001', 'Family planning service'],

        // Sensory
        'MATA'            => [self::SYSTEM_HL7, '217.0', 'Ophthalmology'],
        'THT'             => [self::SYSTEM_SNOMED, '310149003', 'Ear, nose and throat service'],
        'TELINGA'         => [self::SYSTEM_SNOMED, '310149003', 'Ear, nose and throat service'],
        'GIGI'            => [self::SYSTEM_HL7, '88.0', 'General Dental'],
        'DENTAL'          => [self::SYSTEM_HL7, '88.0', 'General Dental'],

        // Mental & therapy
        'JIWA'            => [self::SYSTEM_HL7, '141.0', 'Psychiatry (Requires Referral)'],
        'PSIKIATRI'       => [self::SYSTEM_HL7, '141.0', 'Psychiatry (Requires Referral)'],
        'PSIKOLOGI'       => [self::SYSTEM_HL7, '142.0', 'Psychology'],
        'NAPZA'           => [self::SYSTEM_SNOMED, '881250002', 'Addiction service'],
        'FISIOTERAPI'     => [self::SYSTEM_HL7, '583.0', 'Rehabilitation Service'],
        'REHABILITASI'    => [self::SYSTEM_HL7, '583.0', 'Rehabilitation Service'],
        'AKUPUNGTUR'      => [self::SYSTEM_HL7, '13.0', 'Acupuncture'],
        'GIZI'            => [self::SYSTEM_HL7, '60.0', 'Nutrition'],

        // Imaging & procedures
        'RADIOLOGI'       => [self::SYSTEM_HL7, '209.0', 'Diag. Radiology /Xray /CT /Fluoroscopy'],
        'RADIO'           => [self::SYSTEM_HL7, '209.0', 'Diag. Radiology /Xray /CT /Fluoroscopy'],
        'RADIOTERAPI'     => [self::SYSTEM_SNOMED, '310023006', 'Radiotherapy service'],
        'NUKLIR'          => [self::SYSTEM_HL7, '212.0', 'Nuclear Medicine'],
        'DAYCARE'         => [self::SYSTEM_HL7, '284.0', 'Child Care'],

        // Intensive & high-dependency units
        'RICU'            => [self::SYSTEM_KEMKES, 'TK000286', 'Respiratory Intensive Care Unit Service'],
        'ICCU'            => [self::SYSTEM_KEMKES, 'TK000285', 'Coronary Care Unit Service'],
        'ICVCU'           => [self::SYSTEM_KEMKES, 'TK000285', 'Coronary Care Unit Service'],
        'HCU'             => [self::SYSTEM_KEMKES, 'TK000284', 'High Dependency Care Service'],
        'NICU'            => [self::SYSTEM_SNOMED, '741073001', 'Neonatal intensive care service'],
        'PICU'            => [self::SYSTEM_SNOMED, '310034009', 'Pediatric intensive care service'],
        'PIICU'           => [self::SYSTEM_SNOMED, '310034009', 'Pediatric intensive care service'],
        'ICU'             => [self::SYSTEM_SNOMED, '310032008', 'Intensive care service'],
    ];

    /**
     * Resolve an Encounter.serviceType tuple for a SIMRS visit.
     *
     * @param string $kdPoli      poliklinik.kd_poli
     * @param string $statusLanjut 'Ralan' | 'Ranap'
     * @param string $nmPoli      poliklinik.nm_poli (used for keyword matching)
     * @return array{system: string, code: string, display: string}
     */
    public static function resolve(string $kdPoli, string $statusLanjut, string $nmPoli = ''): array
    {
        $kd = strtoupper(preg_replace('/\s+/', '', $kdPoli) ?: '');

        foreach ([$kd, self::normalizeKeyword($nmPoli)] as $subject) {
            if ($subject === '') {
                continue;
            }
            foreach (self::KEYWORD_MAP as $keyword => $tuple) {
                if (str_contains($subject, $keyword)) {
                    return [
                        'system'  => $tuple[0],
                        'code'    => $tuple[1],
                        'display' => $tuple[2],
                    ];
                }
            }
        }

        // Status-based fallbacks (codes present in the appendix).
        return $statusLanjut === 'Ranap'
            ? ['system' => self::SYSTEM_HL7, 'code' => '557.0', 'display' => 'Inpatients']
            : ['system' => self::SYSTEM_HL7, 'code' => '124.0', 'display' => 'General Practice'];
    }

    /**
     * Full CodeableConcept for Encounter.serviceType (coding wrapper required
     * by the SATUSEHAT parser — the bare system/code object was rejected as
     * unparseable_resource).
     */
    public static function coding(string $kdPoli, string $statusLanjut, string $nmPoli = ''): array
    {
        $t = self::resolve($kdPoli, $statusLanjut, $nmPoli);
        return [
            'coding' => [
                [
                    'system'  => $t['system'],
                    'code'    => $t['code'],
                    'display' => $t['display'],
                ],
            ],
        ];
    }

    private static function normalizeKeyword(string $name): string
    {
        $name = strtoupper($name);
        $name = preg_replace('/[^A-Z0-9]+/', '', $name) ?? '';
        return $name;
    }
}