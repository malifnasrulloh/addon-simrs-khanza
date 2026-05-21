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
}
