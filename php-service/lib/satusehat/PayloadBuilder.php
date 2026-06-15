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
        if (($p['kd_poli'] ?? '') === 'IGDK') {
            $classCode = 'EMER';
            $classDisplay = 'emergency';
        } else {
            $classCode = $isRalan ? 'AMB' : 'IMP';
            $classDisplay = $isRalan ? 'ambulatory' : 'inpatient encounter';
        }
        
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

        // Add hospitalization discharge disposition mapping if status is finished
        if ($status === 'finished') {
            $dischargeDisposition = null;
            if ($isRalan) {
                // Outpatient
                $stts = $p['stts'] ?? '';
                if ($stts === 'Dirujuk') {
                    $dischargeDisposition = [
                        'system' => 'http://terminology.hl7.org/CodeSystem/discharge-disposition',
                        'code' => 'other-hcf',
                        'display' => 'Other healthcare facility'
                    ];
                } elseif ($stts === 'Meninggal') {
                    $dischargeDisposition = [
                        'system' => 'http://terminology.hl7.org/CodeSystem/discharge-disposition',
                        'code' => 'oth',
                        'display' => 'Other'
                    ];
                } elseif ($stts === 'Pulang Paksa') {
                    $dischargeDisposition = [
                        'system' => 'http://terminology.hl7.org/CodeSystem/discharge-disposition',
                        'code' => 'aadvice',
                        'display' => 'Left against advice'
                    ];
                } else {
                    // Fallback to home/Home
                    $dischargeDisposition = [
                        'system' => 'http://terminology.hl7.org/CodeSystem/discharge-disposition',
                        'code' => 'home',
                        'display' => 'Home'
                    ];
                }
            } else {
                // Inpatient (Ranap)
                $sttsPulang = $p['stts_pulang'] ?? '';
                $lama = intval($p['lama'] ?? 0);
                if (in_array($sttsPulang, ['Sehat', 'Sembuh', 'Membaik', 'Atas Persetujuan Dokter'])) {
                    $dischargeDisposition = [
                        'system' => 'http://terminology.hl7.org/CodeSystem/discharge-disposition',
                        'code' => 'home',
                        'display' => 'Home'
                    ];
                } elseif (in_array($sttsPulang, ['Atas Permintaan Sendiri', 'APS', 'Isoman'])) {
                    $dischargeDisposition = [
                        'system' => 'http://terminology.hl7.org/CodeSystem/discharge-disposition',
                        'code' => 'aadvice',
                        'display' => 'Left against advice'
                    ];
                } elseif ($sttsPulang === 'Pulang Paksa') {
                    $dischargeDisposition = [
                        'system' => 'http://terminology.hl7.org/CodeSystem/discharge-disposition',
                        'code' => 'aadvice',
                        'display' => 'Left against advice'
                    ];
                } elseif ($sttsPulang === 'Rujuk') {
                    $dischargeDisposition = [
                        'system' => 'http://terminology.hl7.org/CodeSystem/discharge-disposition',
                        'code' => 'other-hcf',
                        'display' => 'Other healthcare facility'
                    ];
                } elseif (in_array($sttsPulang, ['+', 'Meninggal'])) {
                    // Check length of stay
                    if ($lama <= 2) {
                        $dischargeDisposition = [
                            'system' => 'http://terminology.kemkes.go.id/CodeSystem/discharge-disposition',
                            'code' => 'exp-lt48h',
                            'display' => 'Meninggal < 48 jam'
                        ];
                    } else {
                        $dischargeDisposition = [
                            'system' => 'http://terminology.kemkes.go.id/CodeSystem/discharge-disposition',
                            'code' => 'exp-gt48h',
                            'display' => 'Meninggal > 48 jam'
                        ];
                    }
                } else {
                    // Fallback to home/Home
                    $dischargeDisposition = [
                        'system' => 'http://terminology.hl7.org/CodeSystem/discharge-disposition',
                        'code' => 'home',
                        'display' => 'Home'
                    ];
                }
            }

            if ($dischargeDisposition !== null) {
                $payload['hospitalization'] = [
                    'dischargeDisposition' => [
                        'coding' => [
                            [
                                'system' => $dischargeDisposition['system'],
                                'code' => $dischargeDisposition['code'],
                                'display' => $dischargeDisposition['display']
                            ]
                        ]
                    ]
                ];
            }
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
                        'code'    => strtoupper(trim($p['kd_penyakit'])),
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

        $categoryCode = $def['category_code'] ?? 'vital-signs';
        $categoryDisplay = $def['category_display'] ?? 'Vital Signs';

        $payload = [
            'resourceType' => 'Observation',
            'status' => 'final',
            'category' => [
                [
                    'coding' => [
                        [
                            'system'  => 'http://terminology.hl7.org/CodeSystem/observation-category',
                            'code'    => $categoryCode,
                            'display' => $categoryDisplay
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
                'display'   => "Pemeriksaan Fisik " . str_replace("Ralan", "Rawat Jalan/IGD", str_replace("Ranap", "Rawat Inap", $p['nm_poli'] ?? '')) . ", Pasien " . $p['nm_pasien'] . " Pada Tanggal " . $p['tgl_observasi'] . " Jam " . $p['jam_observasi']
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
            // Unused currently but kept for legacy
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
        } elseif ($def['type'] === 'kesadaran_text') {
            // Kesadaran strictly matched to Java output
            $textVal = str_replace(
                ['Compos Mentis', 'Somnolence', 'Sopor', 'Coma'],
                ['Alert', 'Voice', 'Pain', 'Unresponsive'],
                $val
            );
            $payload['valueCodeableConcept'] = [
                'text' => $textVal
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
     * Build CarePlan payload.
     *
     * @param string $orgId        SATUSEHAT_ORG_ID from config
     * @param array  $p            CarePlan data row
     * @param string $idPasien     IHS Patient ID
     * @param string $idDokter     IHS Practitioner ID
     * @param string $idCarePlan   Existing CarePlan ID (if updating)
     * @return array
     */
    public static function carePlan(
        string $orgId,
        array $p,
        string $idPasien,
        string $idDokter,
        string $idCarePlan = ''
    ): array {
        $isRalan = ($p['status_lanjut'] === 'Ralan');
        $createdTime = str_replace(' ', 'T', $p['tgl_perawatan'] . ' ' . $p['jam_rawat']) . '+07:00';
        $waktuRegistrasi = $p['tgl_registrasi'] . ' ' . $p['jam_reg'];

        // Clean description: replacing newlines with <br>, tab characters with space
        $description = str_replace(["\r\n", "\r", "\n", "\n\r"], '<br>', $p['rtl']);
        $description = str_replace("\t", ' ', $description);

        if (($p['kd_poli'] ?? '') === 'IGDK') {
            $categoryCoding = [
                'system'  => 'http://terminology.kemkes.go.id',
                'code'    => 'TK000068',
                'display' => 'Emergency care plan'
            ];
        } else {
            $categoryCoding = [
                'system'  => 'http://snomed.info/sct',
                'code'    => $isRalan ? '736271009' : '736353004',
                'display' => $isRalan ? 'Outpatient care plan' : 'Inpatient care plan'
            ];
        }

        $payload = [
            'resourceType' => 'CarePlan',
            'identifier' => [
                [
                    'system' => 'http://sys-ids.kemkes.go.id/careplan/' . $orgId,
                    'value'  => $p['no_rawat']
                ]
            ],
            'title' => 'Instruksi Medik dan Keperawatan Pasien',
            'status' => 'active',
            'intent' => 'plan',
            'category' => [
                [
                    'coding' => [
                        $categoryCoding
                    ]
                ]
            ],
            'description' => $description,
            'subject' => [
                'reference' => 'Patient/' . $idPasien,
                'display'   => $p['nm_pasien']
            ],
            'encounter' => [
                'reference' => 'Encounter/' . $p['id_encounter'],
                'display'   => 'Kunjungan ' . $p['nm_pasien'] . ' pada tanggal ' . $waktuRegistrasi . ' dengan nomor kunjungan ' . $p['no_rawat']
            ],
            'created' => $createdTime,
            'author' => [
                'reference' => 'Practitioner/' . $idDokter,
                'display'   => $p['nama']
            ]
        ];

        if (!empty($idCarePlan)) {
            $payload['id'] = $idCarePlan;
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
                    'value'  => trim($p['kode_brng'])
                ]
            ],
            'code' => [
                'coding' => [
                    [
                        'system'  => trim($p['obat_system']),
                        'code'    => trim($p['obat_code']),
                        'display' => trim($p['obat_display'])
                    ]
                ]
            ],
            'status' => $p['status'] === '0' ? 'inactive' : 'active',
            'form' => [
                'coding' => [
                    [
                        'system'  => trim($p['form_system']),
                        'code'    => trim($p['form_code']),
                        'display' => trim($p['form_display'])
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
                                'system'  => isset($p['route_system']) ? trim($p['route_system']) : null,
                                'code'    => isset($p['route_code']) ? trim($p['route_code']) : null,
                                'display' => isset($p['route_display']) ? trim($p['route_display']) : null
                            ]
                        ]
                    ],
                    'doseAndRate' => [
                        [
                            'doseQuantity' => [
                                'value'  => $signa1,
                                'unit'   => isset($p['denominator_code']) ? trim($p['denominator_code']) : null,
                                'system' => isset($p['denominator_system']) ? trim($p['denominator_system']) : null,
                                'code'   => isset($p['denominator_code']) ? trim($p['denominator_code']) : null
                            ]
                        ]
                    ]
                ]
            ],
            'dispenseRequest' => [
                'quantity' => [
                    'value'  => (float)$p['jml'],
                    'unit'   => isset($p['denominator_code']) ? trim($p['denominator_code']) : null,
                    'system' => isset($p['denominator_system']) ? trim($p['denominator_system']) : null,
                    'code'   => isset($p['denominator_code']) ? trim($p['denominator_code']) : null
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

        // Format dates: e.g. "2026-02-09 10:15:30" -> "2026-02-09T10:15:30+07:00"
        $whenPrepared = str_replace(' ', 'T', $p['tgl_peresepan'] . ' ' . $p['jam_peresepan']) . '+07:00';
        $whenHandedOver = str_replace(' ', 'T', $p['tgl_perawatan'] . ' ' . $p['jam']) . '+07:00';

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
                'system' => isset($p['denominator_system']) ? trim($p['denominator_system']) : null,
                'code'   => isset($p['denominator_code']) ? trim($p['denominator_code']) : null
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
                                'system'  => isset($p['route_system']) ? trim($p['route_system']) : null,
                                'code'    => isset($p['route_code']) ? trim($p['route_code']) : null,
                                'display' => isset($p['route_display']) ? trim($p['route_display']) : null
                            ]
                        ]
                    ],
                    'doseAndRate' => [
                        [
                            'doseQuantity' => [
                                'value'  => $signa1,
                                'unit'   => isset($p['denominator_code']) ? trim($p['denominator_code']) : null,
                                'system' => isset($p['denominator_system']) ? trim($p['denominator_system']) : null,
                                'code'   => isset($p['denominator_code']) ? trim($p['denominator_code']) : null
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
                                'system'  => isset($p['route_system']) ? trim($p['route_system']) : null,
                                'code'    => isset($p['route_code']) ? trim($p['route_code']) : null,
                                'display' => isset($p['route_display']) ? trim($p['route_display']) : null
                            ]
                        ]
                    ],
                    'doseAndRate' => [
                        [
                            'doseQuantity' => [
                                'value'  => $signa1,
                                'unit'   => isset($p['denominator_code']) ? trim($p['denominator_code']) : null,
                                'system' => isset($p['denominator_system']) ? trim($p['denominator_system']) : null,
                                'code'   => isset($p['denominator_code']) ? trim($p['denominator_code']) : null
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

    public static function clinicalImpression(
        array $p,
        string $idPasien,
        string $idDokter,
        string $idClinicalImpression = ''
    ): array {
        // Replace newlines with <br> and clean tabs
        $description = str_replace(["\r\n", "\r", "\n", "\n\r"], "<br>", $p['keluhan_pemeriksaan']);
        $description = str_replace("\t", " ", $description);

        $summary = str_replace(["\r\n", "\r", "\n", "\n\r"], "<br>", $p['penilaian']);
        $summary = str_replace("\t", " ", $summary);

        $effectiveDateTime = $p['tgl_perawatan'] . 'T' . $p['jam_rawat'] . '+07:00';

        $payload = [
            'resourceType' => 'ClinicalImpression',
            'status' => 'completed',
            'description' => $description,
            'subject' => [
                'reference' => 'Patient/' . $idPasien,
                'display'   => $p['nm_pasien']
            ],
            'encounter' => [
                'reference' => 'Encounter/' . $p['id_encounter'],
                'display'   => 'Kunjungan ' . $p['nm_pasien'] . ' pada tanggal ' . $p['tgl_registrasi'] . ' dengan nomor kunjungan ' . $p['no_rawat']
            ],
            'effectiveDateTime' => $effectiveDateTime,
            'date' => $effectiveDateTime,
            'assessor' => [
                'reference' => 'Practitioner/' . $idDokter
            ],
            'summary' => $summary,
            'finding' => [
                [
                    'itemCodeableConcept' => [
                        'coding' => [
                            [
                                'system'  => 'http://hl7.org/fhir/sid/icd-10',
                                'code'    => strtoupper(trim($p['kd_penyakit'])),
                                'display' => $p['nm_penyakit']
                            ]
                        ]
                    ],
                    'itemReference' => [
                        'reference' => 'Condition/' . $p['id_condition']
                    ]
                ]
            ],
            'prognosisCodeableConcept' => [
                [
                    'coding' => [
                        [
                            'system'  => 'http://terminology.kemkes.go.id/CodeSystem/clinical-term',
                            'code'    => 'PR000001',
                            'display' => 'Prognosis'
                        ]
                    ]
                ]
            ]
        ];

        if (!empty($idClinicalImpression)) {
            $payload['id'] = $idClinicalImpression;
        }

        return $payload;
    }

    /**
     * Builds QuestionnaireResponse payload for Telaah Farmasi
     *
     * @param array       $p          QuestionnaireResponse data row
     * @param string      $idPasien   IHS Patient ID
     * @param string      $idPraktisi IHS Practitioner ID
     * @param string|null $idQR       Existing QuestionnaireResponse ID (if updating)
     * @return array
     */
    public static function questionnaireResponse(
        array $p,
        string $idPasien,
        string $idPraktisi,
        ?string $idQR = null
    ): array {
        $authored = str_replace(' ', 'T', $p['tgl_peresepan'] . ' ' . $p['jam_peresepan']) . '+07:00';

        $payload = [
            'resourceType' => 'QuestionnaireResponse',
            'status' => 'completed',
            'authored' => $authored,
            'subject' => [
                'reference' => 'Patient/' . $idPasien,
                'display' => $p['nm_pasien']
            ],
            'source' => [
                'reference' => 'Patient/' . $idPasien
            ],
            'encounter' => [
                'reference' => 'Encounter/' . $p['id_encounter']
            ],
            'author' => [
                'reference' => 'Practitioner/' . $idPraktisi,
                'display' => $p['nama']
            ],
            'item' => [
                [
                    'linkId' => 'identitas',
                    'text' => 'Identitas',
                    'item' => [
                        [
                            'linkId' => 'no-rawat',
                            'text' => 'No. Rawat',
                            'answer' => [['valueString' => $p['no_rawat']]]
                        ],
                        [
                            'linkId' => 'no-rm',
                            'text' => 'No. RM',
                            'answer' => [['valueString' => $p['no_rkm_medis']]]
                        ],
                        [
                            'linkId' => 'no-resep',
                            'text' => 'No. Resep',
                            'answer' => [['valueString' => $p['no_resep']]]
                        ]
                    ]
                ],
                [
                    'linkId' => 'telaah-resep',
                    'text' => 'Telaah Resep',
                    'item' => [
                        [
                            'linkId' => 'tr-1-tepat-identifikasi-pasien',
                            'text' => '1. Tepat Identifikasi Pasien',
                            'answer' => [['valueString' => $p['resep_identifikasi_pasien']]]
                        ],
                        [
                            'linkId' => 'tr-1-tepat-identifikasi-pasien-ket',
                            'text' => 'Keterangan',
                            'answer' => [['valueString' => $p['resep_ket_identifikasi_pasien']]]
                        ],
                        [
                            'linkId' => 'tr-2-tepat-obat',
                            'text' => '2. Tepat Obat',
                            'answer' => [['valueString' => $p['resep_tepat_obat']]]
                        ],
                        [
                            'linkId' => 'tr-2-tepat-obat-ket',
                            'text' => 'Keterangan',
                            'answer' => [['valueString' => $p['resep_ket_tepat_obat']]]
                        ],
                        [
                            'linkId' => 'tr-3-tepat-dosis',
                            'text' => '3. Tepat Dosis',
                            'answer' => [['valueString' => $p['resep_tepat_dosis']]]
                        ],
                        [
                            'linkId' => 'tr-3-tepat-dosis-ket',
                            'text' => 'Keterangan',
                            'answer' => [['valueString' => $p['resep_ket_tepat_dosis']]]
                        ],
                        [
                            'linkId' => 'tr-4-tepat-cara-pemberian',
                            'text' => '4. Tepat Cara Pemberian',
                            'answer' => [['valueString' => $p['resep_tepat_cara_pemberian']]]
                        ],
                        [
                            'linkId' => 'tr-4-tepat-cara-pemberian-ket',
                            'text' => 'Keterangan',
                            'answer' => [['valueString' => $p['resep_ket_tepat_cara_pemberian']]]
                        ],
                        [
                            'linkId' => 'tr-5-tepat-waktu-pemberian',
                            'text' => '5. Tepat Waktu Pemberian',
                            'answer' => [['valueString' => $p['resep_tepat_waktu_pemberian']]]
                        ],
                        [
                            'linkId' => 'tr-5-tepat-waktu-pemberian-ket',
                            'text' => 'Keterangan',
                            'answer' => [['valueString' => $p['resep_ket_tepat_waktu_pemberian']]]
                        ],
                        [
                            'linkId' => 'tr-6-duplikasi-obat',
                            'text' => '6. Ada Tidak Duplikasi Obat',
                            'answer' => [['valueString' => $p['resep_ada_tidak_duplikasi_obat']]]
                        ],
                        [
                            'linkId' => 'tr-6-duplikasi-obat-ket',
                            'text' => 'Keterangan',
                            'answer' => [['valueString' => $p['resep_ket_ada_tidak_duplikasi_obat']]]
                        ],
                        [
                            'linkId' => 'tr-7-interaksi-obat',
                            'text' => '7. Interaksi Obat',
                            'answer' => [['valueString' => $p['resep_interaksi_obat']]]
                        ],
                        [
                            'linkId' => 'tr-7-interaksi-obat-ket',
                            'text' => 'Keterangan',
                            'answer' => [['valueString' => $p['resep_ket_interaksi_obat']]]
                        ],
                        [
                            'linkId' => 'tr-8-kontra-indikasi-obat',
                            'text' => '8. Kontra Indikasi Obat',
                            'answer' => [['valueString' => $p['resep_kontra_indikasi_obat']]]
                        ],
                        [
                            'linkId' => 'tr-8-kontra-indikasi-obat-ket',
                            'text' => 'Keterangan',
                            'answer' => [['valueString' => $p['resep_ket_kontra_indikasi_obat']]]
                        ]
                    ]
                ],
                [
                    'linkId' => 'telaah-obat',
                    'text' => 'Telaah Obat',
                    'item' => [
                        [
                            'linkId' => 'to-1-tepat-pasien',
                            'text' => '1. Tepat Pasien',
                            'answer' => [['valueString' => $p['obat_tepat_pasien']]]
                        ],
                        [
                            'linkId' => 'to-2-tepat-obat',
                            'text' => '2. Tepat Obat',
                            'answer' => [['valueString' => $p['obat_tepat_obat']]]
                        ],
                        [
                            'linkId' => 'to-3-tepat-dosis',
                            'text' => '3. Tepat Dosis',
                            'answer' => [['valueString' => $p['obat_tepat_dosis']]]
                        ],
                        [
                            'linkId' => 'to-4-tepat-cara-pemberian',
                            'text' => '4. Tepat Cara Pemberian',
                            'answer' => [['valueString' => $p['obat_tepat_cara_pemberian']]]
                        ],
                        [
                            'linkId' => 'to-5-tepat-waktu-pemberian',
                            'text' => '5. Tepat Waktu Pemberian',
                            'answer' => [['valueString' => $p['obat_tepat_waktu_pemberian']]]
                        ]
                    ]
                ]
            ]
        ];

        if (!empty($idQR)) {
            $payload['id'] = $idQR;
        }

        return $payload;
    }

    public static function buildAcsn(string $noorder, string $kdJenisPrw): string
    {
        $base = str_replace('PR', '', $noorder) . $kdJenisPrw;
        return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $base);
    }

    public static function serviceRequestRadiologi(
        array $p,
        string $idPasien,
        string $idDokter,
        string $orgId,
        string $idServiceRequest = ''
    ): array {
        $acsn = self::buildAcsn($p['noorder'], $p['kd_jenis_prw']);
        
        $time = !empty($p['jam_permintaan']) && $p['jam_permintaan'] !== '00:00:00' 
            ? $p['jam_permintaan'] 
            : '00:00:00';
        $authoredOn = $p['tgl_permintaan'] . 'T' . $time . '+07:00';
        $tglJam = $p['tgl_permintaan'] . ' ' . $time;

        $payload = [
            'resourceType' => 'ServiceRequest',
            'identifier' => [
                [
                    'system' => 'http://sys-ids.kemkes.go.id/acsn/' . $orgId,
                    'value'  => $acsn
                ]
            ],
            'status' => 'active',
            'intent' => 'order',
            'category' => [
                [
                    'coding' => [
                        [
                            'system'  => 'http://snomed.info/sct',
                            'code'    => '363679005',
                            'display' => 'Imaging'
                        ]
                    ]
                ]
            ],
            'code' => [
                'coding' => [
                    [
                        'system'  => !empty($p['system']) ? $p['system'] : 'http://snomed.info/sct',
                        'code'    => !empty($p['code']) ? $p['code'] : '',
                        'display' => !empty($p['display']) ? $p['display'] : $p['nm_perawatan']
                    ]
                ],
                'text' => $p['nm_perawatan']
            ],
            'subject' => [
                'reference' => 'Patient/' . $idPasien
            ],
            'encounter' => [
                'reference' => 'Encounter/' . $p['id_encounter'],
                'display'   => 'Permintaan ' . $p['nm_perawatan'] . ' atas nama pasien ' . $p['nm_pasien'] .
                               ' No.RM ' . $p['no_rkm_medis'] . ' No.Rawat ' . $p['no_rawat'] .
                               ', pada tanggal ' . $tglJam
            ],
            'authoredOn' => $authoredOn,
            'requester' => [
                'reference' => 'Practitioner/' . $idDokter,
                'display'   => $p['nama']
            ],
            'performer' => [
                [
                    'reference' => 'Organization/' . $orgId,
                    'display'   => 'Ruang Radiologi/Petugas Radiologi'
                ]
            ],
            'reasonCode' => [
                [
                    'text' => !empty($p['diagnosa_klinis']) ? $p['diagnosa_klinis'] : '-'
                ]
            ]
        ];

        if (!empty($idServiceRequest) && $idServiceRequest !== '-') {
            $payload['id'] = $idServiceRequest;
        }

        return $payload;
    }

    public static function diagnosticReportRadiologi(
        array $p,
        string $idPasien,
        string $idDokter,
        string $orgId,
        string $idDiagnosticReport = ''
    ): array {
        $time = !empty($p['jam_hasil']) && $p['jam_hasil'] !== '00:00:00' 
            ? $p['jam_hasil'] 
            : '00:00:00';
        $dateTimeStr = $p['tgl_hasil'] . 'T' . $time . '+07:00';

        $conclusion = !empty($p['hasil']) ? $p['hasil'] : '';
        $conclusion = str_replace(["\r\n", "\r", "\n", "\n\r"], '<br>', $conclusion);
        $conclusion = str_replace("\t", ' ', $conclusion);

        $payload = [
            'resourceType' => 'DiagnosticReport',
            'identifier' => [
                [
                    'system' => 'http://sys-ids.kemkes.go.id/diagnostic/' . $orgId . '/rad',
                    'use'    => 'official',
                    'value'  => $p['noorder'] . '.' . $p['kd_jenis_prw']
                ]
            ],
            'status' => 'final',
            'category' => [
                [
                    'coding' => [
                        [
                            'system'  => 'http://terminology.hl7.org/CodeSystem/v2-0074',
                            'code'    => 'RAD',
                            'display' => 'Radiology'
                        ]
                    ]
                ]
            ],
            'code' => [
                'coding' => [
                    [
                        'system'  => !empty($p['system']) ? $p['system'] : 'http://snomed.info/sct',
                        'code'    => !empty($p['code']) ? $p['code'] : '',
                        'display' => !empty($p['display']) ? $p['display'] : $p['nm_perawatan']
                    ]
                ]
            ],
            'subject' => [
                'reference' => 'Patient/' . $idPasien
            ],
            'encounter' => [
                'reference' => 'Encounter/' . $p['id_encounter']
            ],
            'effectiveDateTime' => $dateTimeStr,
            'issued'            => $dateTimeStr,
            'performer' => [
                [
                    'reference' => 'Practitioner/' . $idDokter
                ],
                [
                    'reference' => 'Organization/' . $orgId
                ]
            ],
            'imagingStudy' => [
                [
                    'reference' => 'ImagingStudy/' . $p['id_imaging']
                ]
            ],
            'result' => [
                [
                    'reference' => 'Observation/' . $p['id_observation']
                ]
            ],
            'basedOn' => [
                [
                    'reference' => 'ServiceRequest/' . $p['id_servicerequest']
                ]
            ],
            'conclusion' => $conclusion
        ];

        if (!empty($idDiagnosticReport) && $idDiagnosticReport !== '-') {
            $payload['id'] = $idDiagnosticReport;
        }

        return $payload;
    }

    public static function specimenRadiologi(
        array $p,
        string $idPasien,
        string $orgId,
        string $idSpecimen = ''
    ): array {
        $time = !empty($p['jam_sampel']) && $p['jam_sampel'] !== '00:00:00' 
            ? $p['jam_sampel'] 
            : '00:00:00';
        $receivedTime = $p['tgl_sampel'] . 'T' . $time . '+07:00';

        $payload = [
            'resourceType' => 'Specimen',
            'identifier' => [
                [
                    'system' => 'http://sys-ids.kemkes.go.id/specimen/' . $orgId,
                    'value'  => $p['noorder'] . '.' . $p['kd_jenis_prw']
                ]
            ],
            'status' => 'available',
            'type' => [
                'coding' => [
                    [
                        'system'  => !empty($p['sampel_system']) ? $p['sampel_system'] : '',
                        'code'    => !empty($p['sampel_code']) ? $p['sampel_code'] : '',
                        'display' => !empty($p['sampel_display']) ? $p['sampel_display'] : ''
                    ]
                ]
            ],
            'subject' => [
                'reference' => 'Patient/' . $idPasien,
                'display'   => $p['nm_pasien']
            ],
            'request' => [
                [
                    'reference' => 'ServiceRequest/' . $p['id_servicerequest']
                ]
            ],
            'receivedTime' => $receivedTime
        ];

        if (!empty($idSpecimen) && $idSpecimen !== '-') {
            $payload['id'] = $idSpecimen;
        }

        return $payload;
    }

    public static function observationRadiologi(
        array $p,
        string $idPasien,
        string $idDokter,
        string $orgId,
        string $idObservation = ''
    ): array {
        $time = !empty($p['jam_hasil']) && $p['jam_hasil'] !== '00:00:00' 
            ? $p['jam_hasil'] 
            : '00:00:00';
        $dateTimeStr = $p['tgl_hasil'] . 'T' . $time . '+07:00';

        // Sanitizing valueString
        $conclusion = str_replace(["\r\n", "\r", "\n"], '<br>', $p['hasil']);
        $conclusion = str_replace("\t", ' ', $conclusion);

        $payload = [
            'resourceType' => 'Observation',
            'identifier' => [
                [
                    'system' => 'http://sys-ids.kemkes.go.id/observation/' . $orgId,
                    'value'  => $p['noorder'] . '.' . $p['kd_jenis_prw']
                ]
            ],
            'status' => 'final',
            'category' => [
                [
                    'coding' => [
                        [
                            'system'  => 'http://terminology.hl7.org/CodeSystem/observation-category',
                            'code'    => 'imaging',
                            'display' => 'Imaging'
                        ]
                    ]
                ]
            ],
            'code' => [
                'coding' => [
                    [
                        'system'  => !empty($p['system']) ? $p['system'] : '',
                        'code'    => !empty($p['code']) ? $p['code'] : '',
                        'display' => !empty($p['display']) ? $p['display'] : ''
                    ]
                ]
            ],
            'subject' => [
                'reference' => 'Patient/' . $idPasien
            ],
            'encounter' => [
                'reference' => 'Encounter/' . $p['id_encounter'],
                'display'   => 'Hasil Pemeriksaan Radiologi ' . $p['nm_perawatan'] . ' No.Rawat ' . $p['no_rawat'] . ', Atas Nama Pasien ' . $p['nm_pasien'] . ', Pada Tanggal ' . $p['tgl_hasil'] . ' ' . $time
            ],
            'effectiveDateTime' => $dateTimeStr,
            'issued'            => $dateTimeStr,
            'performer' => [
                [
                    'reference' => 'Practitioner/' . $idDokter
                ],
                [
                    'reference' => 'Organization/' . $orgId
                ]
            ],
            'basedOn' => [
                [
                    'reference' => 'ServiceRequest/' . $p['id_servicerequest']
                ]
            ],
            'bodySite' => [
                'coding' => [
                    [
                        'system'  => !empty($p['sampel_system']) ? $p['sampel_system'] : '',
                        'code'    => !empty($p['sampel_code']) ? $p['sampel_code'] : '',
                        'display' => !empty($p['sampel_display']) ? $p['sampel_display'] : ''
                    ]
                ]
            ],
            'derivedFrom' => [
                [
                    'reference' => 'ImagingStudy/' . $p['id_imaging']
                ]
            ],
            'valueString' => $conclusion
        ];

        if (!empty($idObservation) && $idObservation !== '-') {
            $payload['id'] = $idObservation;
        }

        return $payload;
    }

    public static function serviceRequestLab(
        array $p,
        string $idPasien,
        string $idDokter,
        string $orgId,
        string $idServiceRequest = ''
    ): array {
        $tgl = $p['tgl_permintaan'];
        $time = !empty($p['jam_permintaan']) && $p['jam_permintaan'] !== '00:00:00' 
            ? $p['jam_permintaan'] 
            : '00:00:00';

        $year = (int)substr($tgl, 0, 4);
        if ($year < 2014) {
            if (!empty($p['tgl_registrasi'])) {
                $tgl = $p['tgl_registrasi'];
                $time = !empty($p['jam_reg']) && $p['jam_reg'] !== '00:00:00' ? $p['jam_reg'] : '00:00:00';
            } else {
                $tgl = date('Y-m-d');
                $time = date('H:i:s');
            }
        }
        $dateTimeStr = $tgl . 'T' . $time . '+07:00';

        $payload = [
            'resourceType' => 'ServiceRequest',
            'identifier' => [
                [
                    'system' => 'http://sys-ids.kemkes.go.id/servicerequest/' . $orgId,
                    'value'  => $p['noorder'] . '.' . $p['id_template']
                ]
            ],
            'status' => 'active',
            'intent' => 'order',
            'category' => [
                [
                    'coding' => [
                        [
                            'system'  => 'http://snomed.info/sct',
                            'code'    => '108252007',
                            'display' => 'Laboratory procedure'
                        ]
                    ]
                ]
            ],
            'code' => [
                'coding' => [
                    [
                        'system'  => !empty($p['system']) ? trim($p['system']) : '',
                        'code'    => !empty($p['code']) ? trim($p['code']) : '',
                        'display' => !empty($p['display']) ? trim($p['display']) : ''
                    ]
                ],
                'text' => !empty($p['Pemeriksaan']) ? $p['Pemeriksaan'] : ''
            ],
            'subject' => [
                'reference' => 'Patient/' . $idPasien
            ],
            'encounter' => [
                'reference' => 'Encounter/' . $p['id_encounter'],
                'display'   => 'Permintaan ' . $p['Pemeriksaan'] . ' atas nama pasien ' . $p['nm_pasien'] . ' No.RM ' . $p['no_rkm_medis'] . ' No.Rawat ' . $p['no_rawat'] . ', pada tanggal ' . $p['tgl_permintaan'] . ' ' . $time
            ],
            'authoredOn' => $dateTimeStr,
            'requester' => [
                'reference' => 'Practitioner/' . $idDokter,
                'display'   => $p['nm_dokter']
            ],
            'performer' => [
                [
                    'reference' => 'Organization/' . $orgId,
                    'display'   => 'Ruang Laborat/Petugas Laborat'
                ]
            ],
            'reasonCode' => [
                [
                    'text' => !empty($p['diagnosa_klinis']) ? $p['diagnosa_klinis'] : '-'
                ]
            ]
        ];

        if (!empty($idServiceRequest) && $idServiceRequest !== '-') {
            $payload['id'] = $idServiceRequest;
        }

        return $payload;
    }

    public static function specimenLab(
        array $p,
        string $idPasien,
        string $orgId,
        string $idSpecimen = ''
    ): array {
        $tgl = $p['tgl_sampel'];
        $time = !empty($p['jam_sampel']) && $p['jam_sampel'] !== '00:00:00' 
            ? $p['jam_sampel'] 
            : '00:00:00';

        $year = (int)substr($tgl, 0, 4);
        if ($year < 2014) {
            if (!empty($p['tgl_registrasi'])) {
                $tgl = $p['tgl_registrasi'];
                $time = !empty($p['jam_reg']) && $p['jam_reg'] !== '00:00:00' ? $p['jam_reg'] : '00:00:00';
            } else {
                $tgl = date('Y-m-d');
                $time = date('H:i:s');
            }
        }
        $receivedTime = $tgl . 'T' . $time . '+07:00';

        $sampelSystem = !empty($p['sampel_system']) ? trim($p['sampel_system']) : '';
        if (strpos($sampelSystem, 'snomed.info') !== false) {
            $sampelSystem = 'http://snomed.info/sct';
        }
        $sampelCode = !empty($p['sampel_code']) ? trim($p['sampel_code']) : '';
        $sampelDisplay = !empty($p['sampel_display']) ? trim($p['sampel_display']) : '';

        $payload = [
            'resourceType' => 'Specimen',
            'identifier' => [
                [
                    'system' => 'http://sys-ids.kemkes.go.id/specimen/' . $orgId,
                    'value'  => $p['noorder'] . '.' . $p['id_template']
                ]
            ],
            'status' => 'available',
            'type' => [
                'coding' => [
                    [
                        'system'  => $sampelSystem,
                        'code'    => $sampelCode,
                        'display' => $sampelDisplay
                    ]
                ]
            ],
            'subject' => [
                'reference' => 'Patient/' . $idPasien,
                'display'   => $p['nm_pasien']
            ],
            'request' => [
                [
                    'reference' => 'ServiceRequest/' . $p['id_servicerequest']
                ]
            ],
            'receivedTime' => $receivedTime
        ];

        if (!empty($idSpecimen) && $idSpecimen !== '-') {
            $payload['id'] = $idSpecimen;
        }

        return $payload;
    }

    public static function observationLab(
        array $p,
        string $idPasien,
        string $idDokter,
        string $orgId,
        string $idObservation = ''
    ): array {
        $time = !empty($p['jam_hasil']) && $p['jam_hasil'] !== '00:00:00' 
            ? $p['jam_hasil'] 
            : '00:00:00';
        $dateTimeStr = $p['tgl_hasil'] . 'T' . $time . '+07:00';

        $valueString = 'Hasil Lab : ' . $p['nilai'] . ' ' . $p['satuan'] . ', Nilai Rujukan : ' . $p['nilai_rujukan'];
        if (!empty($p['keterangan'])) {
            $valueString .= ', Keterangan : ' . $p['keterangan'];
        }
        $valueString = str_replace(["\r\n", "\r", "\n"], '<br>', $valueString);
        $valueString = str_replace("\t", ' ', $valueString);

        $payload = [
            'resourceType' => 'Observation',
            'identifier' => [
                [
                    'system' => 'http://sys-ids.kemkes.go.id/observation/' . $orgId,
                    'value'  => $p['noorder'] . '.' . $p['id_template']
                ]
            ],
            'status' => 'final',
            'category' => [
                [
                    'coding' => [
                        [
                            'system'  => 'http://terminology.hl7.org/CodeSystem/observation-category',
                            'code'    => 'laboratory',
                            'display' => 'Laboratory'
                        ]
                    ]
                ]
            ],
            'code' => [
                'coding' => [
                    [
                        'system'  => !empty($p['system']) ? $p['system'] : '',
                        'code'    => !empty($p['code']) ? $p['code'] : '',
                        'display' => !empty($p['display']) ? $p['display'] : ''
                    ]
                ]
            ],
            'subject' => [
                'reference' => 'Patient/' . $idPasien
            ],
            'performer' => [
                [
                    'reference' => 'Practitioner/' . $idDokter
                ]
            ],
            'encounter' => [
                'reference' => 'Encounter/' . $p['id_encounter'],
                'display'   => 'Hasil Pemeriksaan Lab ' . $p['Pemeriksaan'] . ' No.Rawat ' . $p['no_rawat'] . ', Atas Nama Pasien ' . $p['nm_pasien'] . ', No.RM ' . $p['no_rkm_medis'] . ', Pada Tanggal ' . $p['tgl_hasil'] . ' ' . $time
            ],
            'specimen' => [
                'reference' => 'Specimen/' . $p['id_specimen']
            ],
            'effectiveDateTime' => $dateTimeStr,
            'valueString'       => $valueString
        ];

        if (!empty($idObservation) && $idObservation !== '-') {
            $payload['id'] = $idObservation;
        }

        return $payload;
    }

    public static function diagnosticReportLab(
        array $p,
        string $idPasien,
        string $idDokter,
        string $orgId,
        string $idDiagnosticReport = ''
    ): array {
        $time = !empty($p['jam_hasil']) && $p['jam_hasil'] !== '00:00:00' 
            ? $p['jam_hasil'] 
            : '00:00:00';
        $dateTimeStr = $p['tgl_hasil'] . 'T' . $time . '+07:00';

        $conclusion = !empty($p['kesan']) ? $p['kesan'] : '';
        $conclusion = str_replace(["\r\n", "\r", "\n", "\n\r"], '<br>', $conclusion);
        $conclusion = str_replace("\t", ' ', $conclusion);

        $payload = [
            'resourceType' => 'DiagnosticReport',
            'identifier' => [
                [
                    'system' => 'http://sys-ids.kemkes.go.id/diagnostic/' . $orgId . '/lab',
                    'use'    => 'official',
                    'value'  => $p['noorder'] . '.' . $p['id_template']
                ]
            ],
            'status' => 'final',
            'category' => [
                [
                    'coding' => [
                        [
                            'system'  => 'http://terminology.hl7.org/CodeSystem/v2-0074',
                            'code'    => 'LAB',
                            'display' => 'Laboratory'
                        ]
                    ]
                ]
            ],
            'code' => [
                'coding' => [
                    [
                        'system'  => !empty($p['system']) ? $p['system'] : '',
                        'code'    => !empty($p['code']) ? $p['code'] : '',
                        'display' => !empty($p['display']) ? $p['display'] : ''
                    ]
                ]
            ],
            'subject' => [
                'reference' => 'Patient/' . $idPasien
            ],
            'encounter' => [
                'reference' => 'Encounter/' . $p['id_encounter']
            ],
            'effectiveDateTime' => $dateTimeStr,
            'issued'            => $dateTimeStr,
            'performer' => [
                [
                    'reference' => 'Practitioner/' . $idDokter
                ]
            ],
            'specimen' => [
                [
                    'reference' => 'Specimen/' . $p['id_specimen']
                ]
            ],
            'result' => [
                [
                    'reference' => 'Observation/' . $p['id_observation']
                ]
            ],
            'basedOn' => [
                [
                    'reference' => 'ServiceRequest/' . $p['id_servicerequest']
                ]
            ],
            'conclusion' => $conclusion
        ];

        if (!empty($idDiagnosticReport) && $idDiagnosticReport !== '-') {
            $payload['id'] = $idDiagnosticReport;
        }

        return $payload;
    }

    public static function composition(
        string $orgId,
        array $p,
        string $idPasien,
        string $idDokter,
        string $idEncounter,
        array $refs,
        string $idComposition = ''
    ): array {
        $finishedWaktu = $p['waktu_pulang'] ?? date('Y-m-d\TH:i:s+07:00');

        $sections = [];

        // 1. Anamnesis Section (LOINC TK000003)
        $anamnesisEntries = [];
        if (!empty($refs['AllergyIntolerance'])) {
            foreach ($refs['AllergyIntolerance'] as $id) {
                $anamnesisEntries[] = ['reference' => 'AllergyIntolerance/' . $id];
            }
        }
        if (!empty($anamnesisEntries)) {
            $sections[] = [
                'title' => 'Anamnesis',
                'code' => [
                    'coding' => [
                        [
                            'system' => 'http://terminology.kemkes.go.id/CodeSystem/composition-section',
                            'code' => 'TK000003',
                            'display' => 'Anamnesis'
                        ]
                    ]
                ],
                'entry' => $anamnesisEntries
            ];
        }

        // 2. Pemeriksaan Fisik Section (LOINC TK000007)
        if (!empty($refs['Observation'])) {
            $obsEntries = [];
            foreach ($refs['Observation'] as $id) {
                $obsEntries[] = ['reference' => 'Observation/' . $id];
            }
            $sections[] = [
                'title' => 'Pemeriksaan Fisik',
                'code' => [
                    'coding' => [
                        [
                            'system' => 'http://terminology.kemkes.go.id/CodeSystem/composition-section',
                            'code' => 'TK000007',
                            'display' => 'Pemeriksaan Fisik'
                        ]
                    ]
                ],
                'entry' => $obsEntries
            ];
        }

        // 3. Diagnosis Section (LOINC TK000004)
        if (!empty($refs['Condition'])) {
            $condEntries = [];
            foreach ($refs['Condition'] as $id) {
                $condEntries[] = ['reference' => 'Condition/' . $id];
            }
            $sections[] = [
                'title' => 'Diagnosis',
                'code' => [
                    'coding' => [
                        [
                            'system' => 'http://terminology.kemkes.go.id/CodeSystem/composition-section',
                            'code' => 'TK000004',
                            'display' => 'Diagnosis'
                        ]
                    ]
                ],
                'entry' => $condEntries
            ];
        }

        // 4. Tindakan/Prosedur Medis Section (LOINC TK000005)
        if (!empty($refs['Procedure'])) {
            $procEntries = [];
            foreach ($refs['Procedure'] as $id) {
                $procEntries[] = ['reference' => 'Procedure/' . $id];
            }
            $sections[] = [
                'title' => 'Tindakan/Prosedur Medis',
                'code' => [
                    'coding' => [
                        [
                            'system' => 'http://terminology.kemkes.go.id/CodeSystem/composition-section',
                            'code' => 'TK000005',
                            'display' => 'Tindakan/Prosedur Medis'
                        ]
                    ]
                ],
                'entry' => $procEntries
            ];
        }

        // 5. Farmasi Section (LOINC TK000013)
        $pharmacyEntries = [];
        if (!empty($refs['MedicationRequest'])) {
            foreach ($refs['MedicationRequest'] as $id) {
                $pharmacyEntries[] = ['reference' => 'MedicationRequest/' . $id];
            }
        }
        if (!empty($refs['MedicationDispense'])) {
            foreach ($refs['MedicationDispense'] as $id) {
                $pharmacyEntries[] = ['reference' => 'MedicationDispense/' . $id];
            }
        }
        if (!empty($pharmacyEntries)) {
            $sections[] = [
                'title' => 'Farmasi',
                'code' => [
                    'coding' => [
                        [
                            'system' => 'http://terminology.kemkes.go.id/CodeSystem/composition-section',
                            'code' => 'TK000013',
                            'display' => 'Farmasi'
                        ]
                    ]
                ],
                'entry' => $pharmacyEntries
            ];
        }

        // 6. Perencanaan Perawatan Section (LOINC 18776-5)
        $planEntries = [];
        if (!empty($refs['ClinicalImpression'])) {
            foreach ($refs['ClinicalImpression'] as $id) {
                $planEntries[] = ['reference' => 'ClinicalImpression/' . $id];
            }
        }
        if (!empty($refs['CarePlan'])) {
            foreach ($refs['CarePlan'] as $id) {
                $planEntries[] = ['reference' => 'CarePlan/' . $id];
            }
        }
        if (!empty($planEntries)) {
            $sections[] = [
                'title' => 'Perencanaan Perawatan',
                'code' => [
                    'coding' => [
                        [
                            'system' => 'http://loinc.org',
                            'code' => '18776-5',
                            'display' => 'Plan of care note'
                        ]
                    ]
                ],
                'entry' => $planEntries
            ];
        }

        // 7. Pemeriksaan Penunjang Section (LOINC TK000009)
        $supportEntries = [];
        if (!empty($refs['DiagnosticReport'])) {
            foreach ($refs['DiagnosticReport'] as $id) {
                $supportEntries[] = ['reference' => 'DiagnosticReport/' . $id];
            }
        }
        if (!empty($refs['Specimen'])) {
            foreach ($refs['Specimen'] as $id) {
                $supportEntries[] = ['reference' => 'Specimen/' . $id];
            }
        }
        if (!empty($supportEntries)) {
            $sections[] = [
                'title' => 'Pemeriksaan Penunjang',
                'code' => [
                    'coding' => [
                        [
                            'system' => 'http://terminology.kemkes.go.id/CodeSystem/composition-section',
                            'code' => 'TK000009',
                            'display' => 'Pemeriksaan Penunjang'
                        ]
                    ]
                ],
                'entry' => $supportEntries
            ];
        }

        $payload = [
            'resourceType' => 'Composition',
            'status' => 'final',
            'type' => [
                'coding' => [
                    [
                        'system' => 'http://loinc.org',
                        'code' => '88645-7',
                        'display' => 'Outpatient hospital Discharge summary'
                    ]
                ]
            ],
            'category' => [
                [
                    'coding' => [
                        [
                            'system' => 'http://terminology.kemkes.go.id/CodeSystem/definition-category',
                            'code' => 'resume-medis',
                            'display' => 'Resume Medis'
                        ]
                    ]
                ]
            ],
            'subject' => [
                'reference' => 'Patient/' . $idPasien,
                'display' => $p['nm_pasien']
            ],
            'encounter' => [
                'reference' => 'Encounter/' . $idEncounter
            ],
            'date' => $finishedWaktu,
            'author' => [
                [
                    'reference' => 'Practitioner/' . $idDokter,
                    'display' => $p['nama']
                ]
            ],
            'title' => 'Resume Medis - ' . $p['nm_pasien'],
            'custodian' => [
                'reference' => 'Organization/' . $orgId
            ],
            'section' => $sections
        ];

        if (!empty($idComposition)) {
            $payload['id'] = $idComposition;
        }

        return $payload;
    }

    public static function convertLocalToUtc(string $localDateTime): string
    {
        try {
            $dt = new \DateTime($localDateTime, new \DateTimeZone('Asia/Jakarta'));
            $dt->setTimezone(new \DateTimeZone('UTC'));
            return $dt->format('Y-m-d\TH:i:s\+00:00');
        } catch (\Throwable $e) {
            return str_replace(' ', 'T', $localDateTime) . '+00:00';
        }
    }
}





