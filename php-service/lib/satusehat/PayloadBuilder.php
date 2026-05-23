<?php

/**
 * PayloadBuilder - Builds JSON payloads for Satu Sehat Encounter.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class SatuSehatPayloadBuilder
{
    /**
     * Build Encounter payload.
     *
     * @param string $orgId    SATUSEHAT_ORG_ID from config
     * @param array  $p        Patient data row
     * @param string $idPasien IHS Patient ID
     * @param string $idDokter IHS Practitioner ID
     * @param string $status   'arrived', 'in-progress', or 'finished'
     * @param array  $diagnoses Array of diagnoses (only used if status is finished)
     * @param string $idEncounter Existing Encounter ID (if updating)
     * @return array
     */
    public static function encounter(
        string $orgId,
        array $p,
        string $idPasien,
        string $idDokter,
        string $status,
        array $diagnoses = [],
        string $idEncounter = ''
    ): array {
        $isRalan = ($p['status_lanjut'] === 'Ralan');
        $classCode = $isRalan ? 'AMB' : 'IMP';
        $classDisplay = $isRalan ? 'ambulatory' : 'inpatient encounter';
        
        $startWaktu = $p['tgl_registrasi'] . 'T' . $p['jam_reg'] . '+07:00';
        $inProgressWaktu = $p['waktu_perawatan'] ?? $startWaktu; // fallback to reg time if missing
        $finishedWaktu = $p['waktu_pulang'] ?? null;

        // Build history array
        $statusHistory = [];
        
        // 1. Arrived state
        $statusHistory[] = [
            'status' => 'arrived',
            'period' => [
                'start' => $startWaktu,
                // if it goes past arrived, we set 'end' to the next state's start
                // For arrived only, no 'end' yet. If updating, java sets end = inProgressWaktu.
            ]
        ];

        if (in_array($status, ['in-progress', 'finished'])) {
            $statusHistory[0]['period']['end'] = $inProgressWaktu;
            
            $historyInProgress = [
                'status' => 'in-progress',
                'period' => [
                    'start' => $inProgressWaktu,
                ]
            ];
            
            if ($status === 'finished' && $finishedWaktu) {
                $historyInProgress['period']['end'] = $finishedWaktu;
            }
            $statusHistory[] = $historyInProgress;
        }

        if ($status === 'finished' && $finishedWaktu) {
            $statusHistory[] = [
                'status' => 'finished',
                'period' => [
                    'start' => $finishedWaktu,
                    'end'   => $finishedWaktu
                ]
            ];
        }

        $payload = [
            'resourceType' => 'Encounter',
            'status' => $status,
            'class' => [
                'system'  => 'http://terminology.hl7.org/CodeSystem/v3-ActCode',
                'code'    => $classCode,
                'display' => $classDisplay
            ],
            'subject' => [
                'reference' => 'Patient/' . $idPasien,
                'display'   => $p['nm_pasien']
            ],
            'participant' => [
                [
                    'type' => [
                        [
                            'coding' => [
                                [
                                    'system'  => 'http://terminology.hl7.org/CodeSystem/v3-ParticipationType',
                                    'code'    => 'ATND',
                                    'display' => 'attender'
                                ]
                            ]
                        ]
                    ],
                    'individual' => [
                        'reference' => 'Practitioner/' . $idDokter,
                        'display'   => $p['nama']
                    ]
                ]
            ],
            'period' => [
                'start' => $startWaktu,
            ],
            'location' => [
                [
                    'location' => [
                        'reference' => 'Location/' . $p['id_lokasi_satusehat'],
                        'display'   => $p['nm_poli']
                    ]
                ]
            ],
            'statusHistory' => $statusHistory,
            'serviceProvider' => [
                'reference' => 'Organization/' . $orgId
            ],
            'identifier' => [
                [
                    'system' => 'http://sys-ids.kemkes.go.id/encounter/' . $orgId,
                    'value'  => $p['no_rawat']
                ]
            ]
        ];

        if ($status === 'finished' && $finishedWaktu) {
            $payload['period']['end'] = $finishedWaktu;
        }

        if (!empty($idEncounter)) {
            $payload['id'] = $idEncounter;
        }

        // Add Diagnoses if status is finished
        if ($status === 'finished' && !empty($diagnoses)) {
            $diagnosisPayload = [];
            $rank = 1;
            foreach ($diagnoses as $diag) {
                $diagnosisPayload[] = [
                    'condition' => [
                        'reference' => 'Condition/' . $diag['id_condition'],
                        'display'   => $diag['nm_penyakit']
                    ],
                    'use' => [
                        'coding' => [
                            [
                                'system'  => 'http://terminology.hl7.org/CodeSystem/diagnosis-role',
                                'code'    => 'DD',
                                'display' => 'Discharge diagnosis'
                            ]
                        ]
                    ],
                    'rank' => $rank
                ];
                $rank++;
            }
            $payload['diagnosis'] = $diagnosisPayload;
        }

        return $payload;
    }

    /**
     * Build EpisodeOfCare payload.
     *
     * @param string $orgId    SATUSEHAT_ORG_ID
     * @param array  $p        Patient/Diagnosis data row
     * @param string $idPasien IHS Patient ID
     * @param string $idDokter IHS Practitioner ID
     * @param string $status   'active' or 'finished'
     * @param EpisodeOfCareType $type Type of episode (e.g., ANC, TB-SO)
     * @param string $idEpisode Existing EpisodeOfCare ID (if updating)
     * @return array
     */
    public static function episodeOfCare(
        string $orgId,
        array $p,
        string $idPasien,
        string $idDokter,
        string $status,
        EpisodeOfCareType $type,
        string $idEpisode = ''
    ): array {
        $startWaktu = $p['tgl_registrasi'] . 'T' . $p['jam_reg'] . '+07:00';
        $finishedWaktu = $p['waktu_pulang'] ?? null;

        $statusHistory = [
            [
                'status' => 'active',
                'period' => [
                    'start' => $startWaktu
                ]
            ]
        ];

        if ($status === 'finished' && $finishedWaktu) {
            $statusHistory[0]['period']['end'] = $finishedWaktu;
            $statusHistory[] = [
                'status' => 'finished',
                'period' => [
                    'start' => $finishedWaktu,
                    'end'   => $finishedWaktu
                ]
            ];
        }

        $payload = [
            'resourceType' => 'EpisodeOfCare',
            'identifier' => [
                [
                    'system' => 'http://sys-ids.kemkes.go.id/episode-of-care/' . $orgId,
                    'value'  => $p['no_rawat']
                ]
            ],
            'status' => $status,
            'statusHistory' => $statusHistory,
            'type' => [
                [
                    'coding' => [
                        [
                            'system'  => $type->system,
                            'code'    => $type->code,
                            'display' => $type->display
                        ]
                    ]
                ]
            ],
            'patient' => [
                'reference' => 'Patient/' . $idPasien,
                'display'   => $p['nm_pasien']
            ],
            'careManager' => [
                'reference' => 'Practitioner/' . $idDokter,
                'display'   => $p['nama']
            ],
            'managingOrganization' => [
                'reference' => 'Organization/' . $orgId
            ],
            'period' => [
                'start' => $startWaktu
            ]
        ];

        if ($status === 'finished' && $finishedWaktu) {
            $payload['period']['end'] = $finishedWaktu;
        }

        if (!empty($idEpisode)) {
            $payload['id'] = $idEpisode;
        }

        return $payload;
    }

    /**
     * Build Condition payload.
     *
     * @param array  $p        Patient/Diagnosis data row
     * @param string $idPasien IHS Patient ID
     * @param string $idCondition Existing Condition ID (if updating)
     * @return array
     */
    public static function condition(array $p, string $idPasien, string $idCondition = ''): array
    {
        $startWaktu = $p['tgl_registrasi'] . 'T' . $p['jam_reg'] . '+07:00';
        $waktuPulang = $p['pulang'] ?? '';

        $payload = [
            'resourceType' => 'Condition',
            'clinicalStatus' => [
                'coding' => [
                    [
                        'system'  => 'http://terminology.hl7.org/CodeSystem/condition-clinical',
                        'code'    => 'active',
                        'display' => 'Active'
                    ]
                ]
            ],
            'category' => [
                [
                    'coding' => [
                        [
                            'system'  => 'http://terminology.hl7.org/CodeSystem/condition-category',
                            'code'    => 'encounter-diagnosis',
                            'display' => 'Encounter Diagnosis'
                        ]
                    ]
                ]
            ],
            'code' => [
                'coding' => [
                    [
                        'system'  => 'http://hl7.org/fhir/sid/icd-10',
                        'code'    => $p['kd_penyakit'],
                        'display' => $p['nm_penyakit']
                    ]
                ]
            ],
            'subject' => [
                'reference' => 'Patient/' . $idPasien,
                'display'   => $p['nm_pasien']
            ],
            'encounter' => [
                'reference' => 'Encounter/' . $p['id_encounter'],
                'display'   => 'Diagnosa ' . $p['nm_pasien'] . ' selama kunjungan/dirawat dari tanggal ' . $startWaktu . ' sampai ' . $waktuPulang
            ]
        ];

        if (!empty($idCondition)) {
            $payload['id'] = $idCondition;
        }

        return $payload;
    }

    /**
     * Build Observation-TTV payload dynamically based on dictionary definition.
     */
    public static function observationTTV(array $p, string $idPasien, string $idDokter, array $def): array
    {
        $waktuObservasi = $p['tgl_observasi'] . 'T' . $p['jam_observasi'] . '+07:00';

        $payload = [
            'resourceType' => 'Observation',
            'status' => 'final',
            'category' => [
                [
                    'coding' => [
                        [
                            'system'  => 'http://terminology.hl7.org/CodeSystem/observation-category',
                            'code'    => 'vital-signs',
                            'display' => 'Vital Signs'
                        ]
                    ]
                ]
            ],
            'code' => [
                'coding' => [
                    [
                        'system'  => $def['system'],
                        'code'    => $def['code'],
                        'display' => $def['display']
                    ]
                ]
            ],
            'subject' => [
                'reference' => 'Patient/' . $idPasien,
                'display'   => $p['nm_pasien']
            ],
            'performer' => [
                [
                    'reference' => 'Practitioner/' . $idDokter,
                    'display'   => $p['nama']
                ]
            ],
            'encounter' => [
                'reference' => 'Encounter/' . $p['id_encounter'],
                'display'   => "Pemeriksaan Fisik " . $p['nm_pasien'] . " pada " . $waktuObservasi
            ],
            'effectiveDateTime' => $waktuObservasi,
            'issued' => $waktuObservasi
        ];

        // Format value based on type
        $val = trim((string)$p['value']);

        if ($def['type'] === 'quantity') {
            // standard numeric
            $payload['valueQuantity'] = [
                'value'  => (float) $val,
                'unit'   => $def['unit_display'],
                'system' => 'http://unitsofmeasure.org',
                'code'   => $def['unit']
            ];
        } elseif ($def['type'] === 'string') {
            // GCS
            $payload['valueString'] = $val;
        } elseif ($def['type'] === 'codeable_concept') {
            // Kesadaran -> needs mapping
            $map = ObservationTTVDictionary::mapKesadaran($val);
            $payload['valueCodeableConcept'] = [
                'coding' => [
                    [
                        'system'  => 'http://snomed.info/sct',
                        'code'    => $map['code'],
                        'display' => $map['display']
                    ]
                ]
            ];
        } elseif ($def['type'] === 'blood_pressure') {
            // Tensi component structure
            // DB format: "120/80"
            $parts = explode('/', $val);
            $systolic = (float) ($parts[0] ?? 0);
            $diastolic = (float) ($parts[1] ?? 0);

            $payload['component'] = [
                [
                    'code' => [
                        'coding' => [
                            [
                                'system'  => 'http://loinc.org',
                                'code'    => '8480-6',
                                'display' => 'Systolic blood pressure'
                            ]
                        ]
                    ],
                    'valueQuantity' => [
                        'value'  => $systolic,
                        'unit'   => 'mm[Hg]',
                        'system' => 'http://unitsofmeasure.org',
                        'code'   => 'mm[Hg]'
                    ]
                ],
                [
                    'code' => [
                        'coding' => [
                            [
                                'system'  => 'http://loinc.org',
                                'code'    => '8462-4',
                                'display' => 'Diastolic blood pressure'
                            ]
                        ]
                    ],
                    'valueQuantity' => [
                        'value'  => $diastolic,
                        'unit'   => 'mm[Hg]',
                        'system' => 'http://unitsofmeasure.org',
                        'code'   => 'mm[Hg]'
                    ]
                ]
            ];
        }

        return $payload;
    }

    /**
     * Build Procedure payload.
     *
     * @param array  $p        Patient/Procedure data row
     * @param string $idPasien IHS Patient ID
     * @param string $idProcedure Existing Procedure ID (if updating)
     * @return array
     */
    public static function procedure(array $p, string $idPasien, string $idProcedure = ''): array
    {
        $startWaktu = $p['waktu_registrasi'] ?? '';
        $waktuPulang = $p['waktu_pulang'] ?? $startWaktu;

        $payload = [
            'resourceType' => 'Procedure',
            'status' => 'completed',
            'category' => [
                'coding' => [
                    [
                        'system'  => 'http://snomed.info/sct',
                        'code'    => '103693007',
                        'display' => 'Diagnostic procedure'
                    ]
                ],
                'text' => 'Diagnostic procedure'
            ],
            'code' => [
                'coding' => [
                    [
                        'system'  => 'http://hl7.org/fhir/sid/icd-9-cm',
                        'code'    => $p['kode'],
                        'display' => $p['deskripsi_panjang']
                    ]
                ]
            ],
            'subject' => [
                'reference' => 'Patient/' . $idPasien,
                'display'   => $p['nm_pasien']
            ],
            'encounter' => [
                'reference' => 'Encounter/' . $p['id_encounter'],
                'display'   => 'Prosedur ' . $p['nm_pasien'] . ' selama kunjungan/dirawat dari tanggal ' . $startWaktu . ' sampai ' . $waktuPulang
            ],
            'performedPeriod' => [
                'start' => $startWaktu,
                'end'   => $waktuPulang
            ]
        ];

        if (!empty($idProcedure)) {
            $payload['id'] = $idProcedure;
        }

        return $payload;
    }

    /**
     * Build AllergyIntolerance payload.
     *
     * @param array  $a            Patient/Allergy data row
     * @param array  $allergyData  Dictionary lookup data for the allergy
     * @param string $idPasien     IHS Patient ID
     * @param string $idPraktisi   IHS Practitioner ID
     * @param string $idSatuSehat  SIMRS Satu Sehat ID (from config/DB)
     * @param string $idAllergy    Existing AllergyIntolerance ID (if updating)
     * @return array
     */
    public static function allergyIntolerance(array $a, array $allergyData, string $idPasien, string $idPraktisi, string $idSatuSehat, string $idAllergy = ''): array
    {
        $recordedDate = $a['tgl_perawatan'] . 'T' . $a['jam_rawat'] . '+07:00';

        $payload = [
            'resourceType' => 'AllergyIntolerance',
            'identifier' => [
                [
                    'system' => 'http://sys-ids.kemkes.go.id/allergy/' . $idSatuSehat,
                    'value'  => $a['no_rawat']
                ]
            ],
            'clinicalStatus' => [
                'coding' => [
                    [
                        'system'  => 'http://terminology.hl7.org/CodeSystem/allergyintolerance-clinical',
                        'code'    => 'active',
                        'display' => 'Active'
                    ]
                ]
            ],
            'verificationStatus' => [
                'coding' => [
                    [
                        'system'  => 'http://terminology.hl7.org/CodeSystem/allergyintolerance-verification',
                        'code'    => 'confirmed',
                        'display' => 'Confirmed'
                    ]
                ]
            ],
            'category' => [
                $allergyData['category']
            ],
            'code' => [
                'coding' => [
                    [
                        'system'  => $allergyData['coding_system'],
                        'code'    => $allergyData['coding_code'],
                        'display' => $allergyData['coding_display']
                    ]
                ],
                'text' => $allergyData['text']
            ],
            'patient' => [
                'reference' => 'Patient/' . $idPasien,
                'display'   => $a['nm_pasien']
            ],
            'encounter' => [
                'reference' => 'Encounter/' . $a['id_encounter'],
                'display'   => 'Kunjungan ' . $a['nm_pasien'] . ' pada tanggal ' . ($a['tgl_registrasi'] ?? '') . ' dengan nomor kunjungan ' . $a['no_rawat']
            ],
            'recordedDate' => $recordedDate,
            'recorder' => [
                'reference' => 'Practitioner/' . $idPraktisi,
                'display'   => $a['nama']
            ]
        ];

        if (!empty($idAllergy)) {
            $payload['id'] = $idAllergy;
        }

        return $payload;
    }

    /**
     * Build Immunization payload.
     *
     * @param array  $imm           Immunization/Vaccination data row
     * @param string $idPasien      IHS Patient ID
     * @param string $idDokter      IHS Practitioner ID
     * @param string $idImmunization Existing Immunization ID (if updating)
     * @return array
     */
    public static function immunization(
        array $imm,
        string $idPasien,
        string $idDokter,
        string $idImmunization = ''
    ): array {
        // Occurrence time
        $occurrenceDateTime = $imm['tgl_perawatan'] . 'T' . $imm['jam'] . '+07:00';
        
        // Expiration date (only if valid)
        $expirationDate = null;
        if (!empty($imm['tgl_kadaluarsa']) && $imm['tgl_kadaluarsa'] !== '0000-00-00' && strpos($imm['tgl_kadaluarsa'], '0000') === false) {
            $expirationDate = substr($imm['tgl_kadaluarsa'], 0, 10);
        }

        // Parse dose number from 'aturan' (e.g. "Dosis 1", "Dosis 2", etc.)
        $doseStr = strtolower($imm['aturan']);
        $doseStr = str_replace(['dosis', ' '], '', $doseStr);
        
        $validDose = false;
        if (is_numeric($doseStr)) {
            $d = intval($doseStr);
            if ($d > 0) {
                $validDose = true;
            }
        }
        
        if (!$validDose) {
            $doseStr = '1';
        }

        $payload = [
            'resourceType' => 'Immunization',
            'status' => 'completed',
            'vaccineCode' => [
                'coding' => [
                    [
                        'system' => $imm['vaksin_system'],
                        'code' => $imm['vaksin_code'],
                        'display' => $imm['vaksin_display']
                    ]
                ]
            ],
            'patient' => [
                'reference' => 'Patient/' . $idPasien
            ],
            'encounter' => [
                'reference' => 'Encounter/' . $imm['id_encounter']
            ],
            'occurrenceDateTime' => $occurrenceDateTime,
            'recorded' => $occurrenceDateTime,
            'primarySource' => true,
            'location' => [
                'reference' => 'Location/' . $imm['id_lokasi_satusehat'],
                'display' => $imm['nm_poli']
            ],
            'lotNumber' => $imm['no_batch'],
            'route' => [
                'coding' => [
                    [
                        'system' => $imm['route_system'],
                        'code' => $imm['route_code'],
                        'display' => $imm['route_display']
                    ]
                ]
            ],
            'doseQuantity' => [
                'value' => (float)$imm['jml'],
                'unit' => $imm['dose_quantity_unit'],
                'system' => $imm['dose_quantity_system'],
                'code' => $imm['dose_quantity_code']
            ],
            'performer' => [
                [
                    'function' => [
                        'coding' => [
                            [
                                'system' => 'http://terminology.hl7.org/CodeSystem/v2-0443',
                                'code' => 'AP',
                                'display' => 'Administering Provider'
                            ]
                        ]
                    ],
                    'actor' => [
                        'reference' => 'Practitioner/' . $idDokter
                    ]
                ]
            ],
            'reasonCode' => [
                [
                    'coding' => [
                        [
                            'system' => 'http://terminology.kemkes.go.id/CodeSystem/immunization-reason',
                            'code' => 'IM-Program',
                            'display' => 'Imunisasi Program'
                        ]
                    ]
                ]
            ],
            'protocolApplied' => [
                [
                    'doseNumberPositiveInt' => intval($doseStr)
                ]
            ]
        ];

        if ($expirationDate) {
            $payload['expirationDate'] = $expirationDate;
        }

        if (!empty($idImmunization)) {
            $payload['id'] = $idImmunization;
        }

        return $payload;
    }

    /**
     * Build Medication payload.
     *
     * @param string      $orgId         Satu Sehat Organization ID
     * @param array       $p             Medication data row
     * @param string|null $idMedication  Existing Medication ID (if updating)
     * @return array
     */
    public static function medication(string $orgId, array $p, ?string $idMedication = null): array
    {
        $payload = [
            'resourceType' => 'Medication',
            'meta' => [
                'profile' => ['https://fhir.kemkes.go.id/r4/StructureDefinition/Medication']
            ],
            'identifier' => [
                [
                    'system' => 'http://sys-ids.kemkes.go.id/medication/' . $orgId,
                    'use'    => 'official',
                    'value'  => $p['kode_brng']
                ]
            ],
            'code' => [
                'coding' => [
                    [
                        'system'  => $p['obat_system'],
                        'code'    => $p['obat_code'],
                        'display' => $p['obat_display']
                    ]
                ]
            ],
            'status' => $p['status'] === '0' ? 'inactive' : 'active',
            'form' => [
                'coding' => [
                    [
                        'system'  => $p['form_system'],
                        'code'    => $p['form_code'],
                        'display' => $p['form_display']
                    ]
                ]
            ],
            'extension' => [
                [
                    'url' => 'https://fhir.kemkes.go.id/r4/StructureDefinition/MedicationType',
                    'valueCodeableConcept' => [
                        'coding' => [
                            [
                                'system'  => 'http://terminology.kemkes.go.id/CodeSystem/medication-type',
                                'code'    => 'NC',
                                'display' => 'Non-compound'
                            ]
                        ]
                    ]
                ]
            ]
        ];

        if ($idMedication) {
            $payload['id'] = $idMedication;
        }

        return $payload;
    }

    /**
     * Build MedicationRequest payload.
     *
     * @param string      $orgId               Satu Sehat Organization ID
     * @param array       $p                   MedicationRequest data row
     * @param string      $idPasien            IHS Patient ID
     * @param string      $idDokter            IHS Practitioner ID
     * @param string|null $idMedicationRequest Existing MedicationRequest ID (if updating)
     * @return array
     */
    public static function medicationRequest(
        string $orgId,
        array $p,
        string $idPasien,
        string $idDokter,
        ?string $idMedicationRequest = null
    ): array {
        // Parse signa aturan pakai
        $signa1 = 1.0;
        $signa2 = 1.0;
        $aturan = $p['aturan_pakai'] ?? '';
        $parts = explode('x', strtolower($aturan));
        if (isset($parts[0])) {
            $val = preg_replace('/[^0-9.]/', '', $parts[0]);
            if (is_numeric($val)) {
                $signa1 = (float)$val;
            }
        }
        if (isset($parts[1])) {
            $val = preg_replace('/[^0-9.]/', '', $parts[1]);
            if (is_numeric($val)) {
                $signa2 = (float)$val;
            }
        }

        // Format dates: e.g. "2026-02-09 10:15:30" -> "2026-02-09T10:15:30+07:00"
        $authoredOn = str_replace(' ', 'T', $p['tgl_peresepan'] . ' ' . $p['jam_peresepan']) . '+07:00';

        // Identifiers
        $isRacikan = (bool)$p['is_racikan'];
        $noRacik = $p['no_racik'] ?? '';
        
        $prescVal = $p['no_resep'];
        if ($isRacikan && $noRacik !== '') {
            $prescVal = $p['no_resep'] . '-' . $noRacik;
        }

        $payload = [
            'resourceType' => 'MedicationRequest',
            'meta' => [
                'profile' => ['https://fhir.kemkes.go.id/r4/StructureDefinition/MedicationRequest']
            ],
            'identifier' => [
                [
                    'system' => 'http://sys-ids.kemkes.go.id/prescription/' . $orgId,
                    'use'    => 'official',
                    'value'  => $prescVal
                ],
                [
                    'system' => 'http://sys-ids.kemkes.go.id/prescription-item/' . $orgId,
                    'use'    => 'official',
                    'value'  => $p['kode_brng']
                ]
            ],
            'status' => 'completed',
            'intent' => 'order',
            'category' => [
                [
                    'coding' => [
                        [
                            'system'  => 'http://terminology.hl7.org/CodeSystem/medicationrequest-category',
                            'code'    => strtolower($p['status_lanjut']) === 'ranap' ? 'inpatient' : 'outpatient',
                            'display' => strtolower($p['status_lanjut']) === 'ranap' ? 'Inpatient' : 'Outpatient'
                        ]
                    ]
                ]
            ],
            'medicationReference' => [
                'reference' => 'Medication/' . $p['id_medication'],
                'display'   => $p['obat_display']
            ],
            'subject' => [
                'reference' => 'Patient/' . $idPasien,
                'display'   => $p['nm_pasien']
            ],
            'encounter' => [
                'reference' => 'Encounter/' . $p['id_encounter']
            ],
            'authoredOn' => $authoredOn,
            'requester' => [
                'reference' => 'Practitioner/' . $idDokter,
                'display'   => $p['nama']
            ],
            'dosageInstruction' => [
                [
                    'sequence' => 1,
                    'patientInstruction' => $aturan,
                    'timing' => [
                        'repeat' => [
                            'frequency'  => (int)$signa2,
                            'period'     => 1,
                            'periodUnit' => 'd'
                        ]
                    ],
                    'route' => [
                        'coding' => [
                            [
                                'system'  => $p['route_system'],
                                'code'    => $p['route_code'],
                                'display' => $p['route_display']
                            ]
                        ]
                    ],
                    'doseAndRate' => [
                        [
                            'doseQuantity' => [
                                'value'  => $signa1,
                                'unit'   => $p['denominator_code'],
                                'system' => $p['denominator_system'],
                                'code'   => $p['denominator_code']
                            ]
                        ]
                    ]
                ]
            ],
            'dispenseRequest' => [
                'quantity' => [
                    'value'  => (float)$p['jml'],
                    'unit'   => $p['denominator_code'],
                    'system' => $p['denominator_system'],
                    'code'   => $p['denominator_code']
                ]
            ]
        ];

        // Include Organization performer as in Java (only if not compound, but let's make it consistent)
        if (!$isRacikan) {
            $payload['dispenseRequest']['performer'] = [
                'reference' => 'Organization/' . $orgId
            ];
        }

        if ($idMedicationRequest) {
            $payload['id'] = $idMedicationRequest;
        }

        return $payload;
    }

    /**
     * Build MedicationDispense payload.
     *
     * @param string      $orgId                 Satu Sehat Organization ID
     * @param array       $p                     MedicationDispense data row
     * @param string      $idPasien              IHS Patient ID
     * @param string      $idDokter              IHS Practitioner ID
     * @param string|null $idMedicationRequest   Authorizing MedicationRequest ID (if synced)
     * @param string|null $idMedicationDispense  Existing MedicationDispense ID (if updating)
     * @return array
     */
    public static function medicationDispense(
        string $orgId,
        array $p,
        string $idPasien,
        string $idDokter,
        ?string $idMedicationRequest,
        ?string $idMedicationDispense = null
    ): array {
        // Parse signa aturan pakai
        $signa1 = 1.0;
        $signa2 = 1.0;
        $aturan = $p['aturan'] ?? '';
        $parts = explode('x', strtolower($aturan));
        if (isset($parts[0])) {
            $val = preg_replace('/[^0-9.]/', '', $parts[0]);
            if (is_numeric($val)) {
                $signa1 = (float)$val;
            }
        }
        if (isset($parts[1])) {
            $val = preg_replace('/[^0-9.]/', '', $parts[1]);
            if (is_numeric($val)) {
                $signa2 = (float)$val;
            }
        }

        // Format dates: e.g. "2026-02-09 10:15:30" -> "2026-02-09T10:15:30Z"
        $whenPrepared = str_replace(' ', 'T', $p['tgl_peresepan'] . ' ' . $p['jam_peresepan']) . 'Z';
        $whenHandedOver = str_replace(' ', 'T', $p['tgl_perawatan'] . ' ' . $p['jam']) . 'Z';

        // Identifiers: match Java's custom system conventions
        $sys1 = $idMedicationDispense ? 'medicationdispense' : 'prescription';
        $sys2Type = $idMedicationDispense ? 'medicationdispense-item' : 'prescription-item';

        $payload = [
            'resourceType' => 'MedicationDispense',
            'identifier' => [
                [
                    'system' => 'http://sys-ids.kemkes.go.id/' . $sys1 . '/' . $orgId,
                    'use'    => 'official',
                    'value'  => $p['no_resep']
                ],
                [
                    'system' => 'http://sys-ids.kemkes.go.id/' . $sys2Type . '/' . $orgId,
                    'use'    => 'official',
                    'value'  => $p['kode_brng']
                ]
            ],
            'status' => 'completed',
            'category' => [
                'coding' => [
                    [
                        'system'  => 'http://terminology.hl7.org/fhir/CodeSystem/medicationdispense-category',
                        'code'    => strtolower($p['status_pemberian']) === 'ranap' ? 'inpatient' : 'outpatient',
                        'display' => strtolower($p['status_pemberian']) === 'ranap' ? 'Inpatient' : 'Outpatient'
                    ]
                ]
            ],
            'medicationReference' => [
                'reference' => 'Medication/' . $p['id_medication'],
                'display'   => $p['obat_display']
            ],
            'subject' => [
                'reference' => 'Patient/' . $idPasien,
                'display'   => $p['nm_pasien']
            ],
            'context' => [
                'reference' => 'Encounter/' . $p['id_encounter']
            ],
            'performer' => [
                [
                    'actor' => [
                        'reference' => 'Practitioner/' . $idDokter,
                        'display'   => $p['nama']
                    ]
                ]
            ],
            'location' => [
                'reference' => 'Location/' . $p['id_lokasi_satusehat'],
                'display'   => $p['nm_bangsal']
            ],
            'quantity' => [
                'value'  => (float)$p['jml'],
                'system' => $p['denominator_system'],
                'code'   => $p['denominator_code']
            ],
            'whenPrepared'   => $whenPrepared,
            'whenHandedOver' => $whenHandedOver,
            'dosageInstruction' => [
                [
                    'sequence' => 1,
                    'text'     => $aturan,
                    'timing' => [
                        'repeat' => [
                            'frequency'  => (int)$signa2,
                            'period'     => 1,
                            'periodUnit' => 'd'
                        ]
                    ],
                    'route' => [
                        'coding' => [
                            [
                                'system'  => $p['route_system'],
                                'code'    => $p['route_code'],
                                'display' => $p['route_display']
                            ]
                        ]
                    ],
                    'doseAndRate' => [
                        [
                            'doseQuantity' => [
                                'value'  => $signa1,
                                'unit'   => $p['denominator_code'],
                                'system' => $p['denominator_system'],
                                'code'   => $p['denominator_code']
                            ]
                        ]
                    ]
                ]
            ]
        ];

        if (!empty($idMedicationRequest)) {
            $payload['authorizingPrescription'] = [
                [
                    'reference' => 'MedicationRequest/' . $idMedicationRequest
                ]
            ];
        }

        if ($idMedicationDispense) {
            $payload['id'] = $idMedicationDispense;
        }

        return $payload;
    }

    /**
     * Build MedicationStatement payload.
     *
     * @param string      $orgId                  Satu Sehat Organization ID
     * @param array       $p                      MedicationStatement data row
     * @param string      $idPasien               IHS Patient ID
     * @param string|null $idMedicationStatement  Existing MedicationStatement ID (if updating)
     * @return array
     */
    public static function medicationStatement(
        string $orgId,
        array $p,
        string $idPasien,
        ?string $idMedicationStatement = null
    ): array {
        // Parse signa aturan pakai
        $signa1 = 1.0;
        $signa2 = 1.0;
        $aturan = $p['aturan_pakai'] ?? '';
        $parts = explode('x', strtolower($aturan));
        if (isset($parts[0])) {
            $val = preg_replace('/[^0-9.]/', '', $parts[0]);
            if (is_numeric($val)) {
                $signa1 = (float)$val;
            }
        }
        if (isset($parts[1])) {
            $val = preg_replace('/[^0-9.]/', '', $parts[1]);
            if (is_numeric($val)) {
                $signa2 = (float)$val;
            }
        }

        // Format dates: e.g. "2026-02-09 10:15:30" -> "2026-02-09T10:15:30+07:00"
        $dateAsserted = str_replace(' ', 'T', $p['tgl_penyerahan'] . ' ' . $p['jam_penyerahan']) . '+07:00';

        // Identifiers:
        // System: http://sys-ids.kemkes.go.id/medicationstatement/{orgId}
        // Value non-racikan: {no_resep}-{kode_brng}
        // Value racikan: {no_resep}-{kode_brng}-{no_racik}
        $isRacikan = (bool)$p['is_racikan'];
        $noRacik = $p['no_racik'] ?? '';
        
        $valIdentifier = $p['no_resep'] . '-' . $p['kode_brng'];
        if ($isRacikan && $noRacik !== '') {
            $valIdentifier .= '-' . $noRacik;
        }

        $payload = [
            'resourceType' => 'MedicationStatement',
            'identifier' => [
                [
                    'system' => 'http://sys-ids.kemkes.go.id/medicationstatement/' . $orgId,
                    'use'    => 'official',
                    'value'  => $valIdentifier
                ]
            ],
            'status' => 'completed',
            'category' => [
                'coding' => [
                    [
                        'system'  => 'http://terminology.hl7.org/CodeSystem/medication-statement-category',
                        'code'    => strtolower($p['status_lanjut']) === 'ranap' ? 'inpatient' : 'outpatient',
                        'display' => strtolower($p['status_lanjut']) === 'ranap' ? 'Inpatient' : 'Outpatient'
                    ]
                ]
            ],
            'medicationReference' => [
                'reference' => 'Medication/' . $p['id_medication'],
                'display'   => $p['obat_display']
            ],
            'subject' => [
                'reference' => 'Patient/' . $idPasien,
                'display'   => $p['nm_pasien']
            ],
            'dosage' => [
                [
                    'text'   => $aturan,
                    'timing' => [
                        'repeat' => [
                            'frequency'  => (int)$signa2,
                            'period'     => 1,
                            'periodUnit' => 'd'
                        ]
                    ],
                    'route' => [
                        'coding' => [
                            [
                                'system'  => $p['route_system'],
                                'code'    => $p['route_code'],
                                'display' => $p['route_display']
                            ]
                        ]
                    ],
                    'doseAndRate' => [
                        [
                            'doseQuantity' => [
                                'value'  => $signa1,
                                'unit'   => $p['denominator_code'],
                                'system' => $p['denominator_system'],
                                'code'   => $p['denominator_code']
                            ]
                        ]
                    ]
                ]
            ],
            'dateAsserted' => $dateAsserted,
            'informationSource' => [
                'reference' => 'Patient/' . $idPasien,
                'display'   => $p['nm_pasien']
            ],
            'context' => [
                'reference' => 'Encounter/' . $p['id_encounter']
            ],
            'note' => [
                [
                    'text' => 'Pasien sudah memahami aturan pakai yang dijelaskan oleh petugas & Obat sudah diserahkan ke pasien'
                ]
            ]
        ];

        if ($idMedicationStatement) {
            $payload['id'] = $idMedicationStatement;
        }

        return $payload;
    }
}
