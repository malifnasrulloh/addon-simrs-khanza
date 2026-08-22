<?php

/**
 * PayloadBuilder - Builds JSON payloads for Satu Sehat Encounter.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

require_once __DIR__ . '/ServiceTypeTerminology.php';

class SatuSehatPayloadBuilder
{
    /**
     * Pre-flight payload shape validation (best-practice guard, Phase C).
     * Recursively flags the failure classes the platform rejects:
     *   - empty system / code values (rules 10012, 10010)
     *   - empty list references like "Location/" (rule 10120)
     *   - empty coding arrays (unparseable_resource class)
     * Returns a list of "<path>: <problem>" strings; empty list = OK.
     * Hooked into SatuSehatClient before every send — logged, never blocks.
     */
    public static function validatePayload(array $payload, string $label = ''): array
    {
        $issues = [];
        foreach (self::scanPayloadIssues($payload, '$') as $issue) {
            $issues[] = $label !== '' ? "{$label}: {$issue}" : $issue;
        }
        return $issues;
    }

    private static function scanPayloadIssues(array $node, string $path): array
    {
        $issues = [];
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                if ($key === 'coding' && $value === []) {
                    $issues[] = "{$path}.{$key}: empty coding array";
                } elseif ($key === 'reference' && is_string($value) && $value === '') {
                    $issues[] = "{$path}.{$key}: empty reference";
                } elseif ($key === 'reference' && is_string($value) && preg_match('/^[A-Za-z]+\/$/', $value)) {
                    $issues[] = "{$path}.{$key}: empty reference '{$value}'";
                } elseif (($key === 'system' || $key === 'code') && is_string($value) && trim($value) === '') {
                    $issues[] = "{$path}.{$key}: empty {$key}";
                }
                $issues = array_merge($issues, self::scanPayloadIssues($value, $path . '.' . $key));
            } elseif (is_string($value)) {
                if ($key === 'reference' && ($value === '' || preg_match('/^[A-Za-z]+\/$/', $value))) {
                    $issues[] = "{$path}.{$key}: empty reference '{$value}'";
                } elseif ($key === 'system' && trim($value) === '') {
                    $issues[] = "{$path}.{$key}: empty system";
                } elseif ($key === 'code' && trim($value) === '') {
                    $issues[] = "{$path}.{$key}: empty code";
                }
            }
        }
        return $issues;
    }


    // ── Unit classifier (Phase B) ───────────────────────────────────────────
    // SATUSEHAT medication quantities put drug FORMS in the
    // v3-orderableDrugForm code system and measurable units in UCUM
    // (unitsofmeasure.org) with canonical case ('mL' not 'ml'). Sending a
    // form under unitsofmeasure, or a lowercase unit, is rejected as
    // "Code not found" (rules 10348/10349/10050).
    private const FORM_SYSTEM = 'http://terminology.hl7.org/CodeSystem/v3-orderableDrugForm';

    /** Raw SIMRS unit (normalized uppercase, spaces removed) → canonical code. */
    private const DRUG_FORM_MAP = [
        'TAB' => 'TAB', 'TABLET' => 'TAB', 'TABL' => 'TAB', 'ENTAB' => 'TAB',
        'CAP' => 'CAP', 'CAPSUL' => 'CAP', 'CAPSULE' => 'CAP', 'KAPSUL' => 'CAP', 'KAPLET' => 'CAP',
        'SUPP' => 'SUPP', 'SUPPOS' => 'SUPP', 'SUPPOSITORIA' => 'SUPP',
        'SYR' => 'SYR', 'SIRUP' => 'SYR', 'SYRUP' => 'SYR',
        'INH' => 'INH', 'INHAL' => 'INH', 'INHALER' => 'INH',
        'DROP' => 'DROP', 'DROPS' => 'DROP', 'TETES' => 'DROP', 'DRP' => 'DROP',
        'OINT' => 'OINT', 'OINTMENT' => 'OINT', 'SALEP' => 'OINT',
        'CREAM' => 'CREAM', 'KRIM' => 'CREAM',
        'GEL' => 'GEL',
        'SPRAY' => 'SPRAY', 'SEMPROT' => 'SPRAY',
        'VIAL' => 'VIAL', 'AMP' => 'AMP', 'AMPUL' => 'AMP', 'AMPOULE' => 'AMP', 'INJ' => 'AMP',
        'SOL' => 'SOL', 'SOLUTION' => 'SOL',
        'SUSP' => 'SUSP', 'SUSPENSI' => 'SUSP', 'SUSPENSION' => 'SUSP',
        'PIL' => 'PILL', 'PILL' => 'PILL',
        'PULV' => 'PWDR', 'POWDER' => 'PWDR', 'SERBUK' => 'PWDR',
        'TROCHIS' => 'TROCH', 'LOZ' => 'TROCH',
        'PATCH' => 'PATCH', 'PLASTER' => 'PATCH',
    ];

    /**
     * Classify a SIMRS unit string into the FHIR Quantity triplet
     * (unit/system/code): drug forms → v3-orderableDrugForm, measurable
     * units → UCUM canonical case (mL, mg/dL, mm[Hg] …). Unknown units
     * return null → callers omit the coded unit instead of sending an empty
     * or wrong system (rule 10012).
     */
    private static function classifyUnit(string $unit): ?array
    {
        $raw = trim($unit);
        if ($raw === '') {
            return null;
        }
        $up = strtoupper(str_replace(' ', '', $raw));

        if (isset(self::DRUG_FORM_MAP[$up])) {
            $code = self::DRUG_FORM_MAP[$up];
            return ['unit' => $code, 'system' => self::FORM_SYSTEM, 'code' => $code];
        }

        $ucum = self::mapLabUnit($raw);
        if ($ucum !== null) {
            return ['unit' => $ucum, 'system' => 'http://unitsofmeasure.org', 'code' => $ucum];
        }

        return null;
    }

    /**
     * Administration-route coding guard. Route values that are not actual
     * route codes (e.g. 'Topical' stored under the ATC drug-class system)
     * are rejected by rule 10038 — return null (omit the coding) instead.
     */
    private static function sanitizeRoute(array $p): ?array
    {
        $system  = trim((string) ($p['route_system'] ?? ''));
        $code    = trim((string) ($p['route_code'] ?? ''));
        $display = trim((string) ($p['route_display'] ?? ''));
        if ($code === '') {
            return null;
        }
        if (stripos($system, 'whocc') !== false && !preg_match('/^[A-Z0-9]{3,7}$/', $code)) {
            return null;
        }
        if (preg_match('/^(topical|oral|iv|intravenous|inhalation|parenteral|peroral)$/i', $code)) {
            return null;
        }
        return [
            'coding' => [
                [
                    'system'  => $system !== '' ? $system : null,
                    'code'    => $code,
                    'display' => $display !== '' ? $display : null,
                ],
            ],
        ];
    }

    /**
     * Trim whitespace off mapping-provided columns before they enter a
     * payload (mapping tables carry trailing spaces — rule 10010 rejecting
     * LOINC codes like '20570-8 ').
     */
    private static function cleanMappingRow(array $p): array
    {
        $keys = [
            'obat_code', 'obat_system', 'obat_display',
            'form_code', 'form_system', 'form_display',
            'route_code', 'route_system', 'route_display',
            'denominator_code', 'denominator_system', 'denominator_display',
            'vaksin_code', 'vaksin_system', 'vaksin_display',
            'dose_quantity_code', 'dose_quantity_system', 'dose_quantity_unit',
            'sampel_code', 'sampel_system', 'sampel_display',
            'code', 'system', 'display',
            'id_lokasi_satusehat',
        ];
        foreach ($keys as $k) {
            if (isset($p[$k]) && is_string($p[$k])) {
                $p[$k] = trim($p[$k]);
            }
        }
        return $p;
    }

    /**
     * Reference for a composition section entry: bare SATUSEHAT ids become
     * "Type/{id}"; values that are already relative/absolute references
     * (e.g. in-bundle "urn:uuid:...") are passed through untouched.
     */
    private static function compositionRef(string $resourceType, string $id): string
    {
        if (str_starts_with($id, 'urn:') || str_contains($id, '/')) {
            return $id;
        }
        return $resourceType . '/' . $id;
    }

    private static function getLocationPeriodStart(array $p, string $status): ?string
    {
        // For Ranap, use admission time; for Ralan/IGD use registration time
        if (($p['status_lanjut'] ?? '') === 'Ranap' && !empty($p['tgl_masuk'])) {
            return self::sanitizeDateTime($p['tgl_masuk'] ?? null, $p['jam_masuk'] ?? null, $p);
        }
        return self::sanitizeDateTime($p['tgl_registrasi'] ?? null, $p['jam_reg'] ?? null, $p);
    }

    private static function buildServiceClassExtension(array $p): array
    {
        $isRalan = ($p['status_lanjut'] ?? '') === 'Ralan';
        $kdPoli = $p['kd_poli'] ?? '';

        if ($kdPoli === 'IGDK') {
            $serviceSystem = 'http://terminology.kemkes.go.id/CodeSystem/locationServiceClass-Outpatient';
            $serviceCode = 'reguler';
            $serviceDisplay = 'Kelas Reguler';
        } elseif (!$isRalan) {
            $serviceSystem = 'http://terminology.kemkes.go.id/CodeSystem/locationServiceClass-Inpatient';
            // Infer bed class from kamar code if available, default to 3
            $kdKamar = $p['kd_kamar'] ?? '';
            if (str_starts_with($kdKamar, 'VIP')) {
                $serviceCode = 'VIP';
                $serviceDisplay = 'Kelas VIP';
            } elseif (str_starts_with($kdKamar, 'K1')) {
                $serviceCode = '1';
                $serviceDisplay = 'Kelas 1';
            } elseif (str_starts_with($kdKamar, 'K2')) {
                $serviceCode = '2';
                $serviceDisplay = 'Kelas 2';
            } elseif (str_starts_with($kdKamar, 'K3') || str_starts_with($kdKamar, 'K3A')) {
                $serviceCode = '3';
                $serviceDisplay = 'Kelas 3';
            } elseif (str_starts_with($kdKamar, 'ICU') || str_starts_with($kdKamar, 'ICCU') || str_starts_with($kdKamar, 'NICU') || str_starts_with($kdKamar, 'PICU')) {
                $serviceCode = 'ICU';
                $serviceDisplay = 'Kelas ICU';
            } elseif (str_starts_with($kdKamar, 'ISO')) {
                $serviceCode = 'isolasi';
                $serviceDisplay = 'Kelas Isolasi';
            } else {
                $serviceCode = '3';
                $serviceDisplay = 'Kelas 3';
            }
        } else {
            $serviceSystem = 'http://terminology.kemkes.go.id/CodeSystem/locationServiceClass-Outpatient';
            $serviceCode = 'reguler';
            $serviceDisplay = 'Kelas Reguler';
        }

        return [
            'url' => 'https://fhir.kemkes.go.id/r4/StructureDefinition/ServiceClass',
            'extension' => [
                [
                    'url' => 'value',
                    'valueCodeableConcept' => [
                        'coding' => [
                            [
                                'system'  => $serviceSystem,
                                'code'    => $serviceCode,
                                'display' => $serviceDisplay
                            ]
                        ]
                    ]
                ],
                [
                    'url' => 'upgradeClassIndicator',
                    'valueCodeableConcept' => [
                        'coding' => [
                            [
                                'system' => 'http://terminology.kemkes.go.id/CodeSystem/locationUpgradeClass',
                                'code'   => 'kelas-tetap',
                                'display' => 'Kelas Tetap Perawatan'
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }

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
     * @param string|null $idEpisodeOfCare EpisodeOfCare ID to link (optional)
     * @return array
     */
    /**
     * Canonical Encounter timeline boundaries, shared by all three sync
     * phases (create/in-progress/finished) so every phase tells the same
     * story regardless of when a poli->ranap conversion happens.
     *
     *   T0 = patient arrival ......... reg_periksa.tgl_registrasi + jam_reg
     *   T1 = care begins ............. Ranap: FIRST kamar_inap admission
     *                                  Ralan: mutasi_berkas.dikirim -> exam time
     *                                  (missing/contradictory -> collapses to T0)
     *   T2 = care ends ............... nota_jalan/nota_inap (waktu_pulang)
     *                                  fallback Ranap: final kamar_inap discharge
     *                                  fallback Ralan: mutasi_berkas.kembali
     *
     * Returns ['t0'=>?string,'t1'=>?string,'t2'=>?string] as WIB timestamps;
     * ordering T0 <= T1 <= T2 is enforced — violating boundaries are dropped,
     * never sent.
     */
    public static function resolveEncounterBoundaries(array $p): array
    {
        $isRanap = ($p['status_lanjut'] ?? '') === 'Ranap';

        // Candidate parsing MUST NOT fall back to registration internally
        // (empty row = no fallback), otherwise garbage exam times silently
        // masquerade as real boundaries. Datetime-tolerant: full timestamp
        // in date column wins; zero datetimes ('0000-00-00', empty) return null.
        $parse = function (?string $d, ?string $t = null): ?string {
            if ($d === null) {
                return null;
            }
            $d = trim($d);
            if ($d === '' || $d === '0000-00-00' || $d === '0000-00-00 00:00:00' || str_starts_with($d, '0000-')) {
                return null;
            }
            if ($t !== null) {
                $t = trim($t);
                if ($t === '' || $t === '00:00:00') {
                    $t = null;
                }
            }
            if (preg_match('/[ T]\d{2}:\d{2}/', $d) === 1) {
                // Full timestamp already embedded — ignore separate jam.
                $value = self::sanitizeDateTime($d, null, []);
                return ($value === '' || $value === null) ? null : $value;
            }
            $value = self::sanitizeDateTime($d, $t, []);
            return ($value === '' || $value === null) ? null : $value;
        };

        // T0 — arrival.
        $t0 = $parse($p['tgl_registrasi'] ?? null, $p['jam_reg'] ?? null);

        // T1 — care begins.
        $t1 = null;
        if ($isRanap) {
            // Timeline uses the EARLIEST admission: room transfers must not
            // move the encounter start (Database overlays the first stay).
            $t1 = $parse($p['tgl_masuk'] ?? null, $p['jam_masuk'] ?? null);
        }
        if ($t1 === null) {
            foreach ([
                $parse($p['mutasi_dikirim'] ?? null),       // file dispatched to clinic (primary for Ralan)
                $parse($p['waktu_perawatan'] ?? null),      // examination time (fallback for Ralan)
            ] as $candidate) {
                if ($candidate !== null) {
                    $t1 = $candidate;
                    break;
                }
            }
        }
        if ($t1 !== null && $t0 !== null && strtotime($t1) < strtotime($t0)) {
            $t1 = null; // collapse: care cannot begin before arrival
        }

        // T2 — care ends.
        $t2 = $parse($p['waktu_pulang'] ?? null);
        if ($t2 === null) {
            $t2 = $isRanap
                ? $parse($p['kamar_tgl_keluar'] ?? null, $p['kamar_jam_keluar'] ?? null)
                : $parse($p['mutasi_kembali'] ?? null);
        }
        if ($t2 !== null) {
            $careStart = $t1 ?? $t0;
            if ($careStart === null || strtotime($t2) < strtotime($careStart)) {
                $t2 = null; // drop contradictory end rather than send it
            }
        }

        return ['t0' => $t0, 't1' => $t1, 't2' => $t2];
    }

    /**
     * Contiguous status history from resolved boundaries. Every entry hands
     * off exactly where the next begins:
     *   arrived(T0->T1) -> in-progress(T1->T2) -> finished(T2->T2)
     * A missing T1 leaves arrived open-ended and starts in-progress at T0.
     */
    public static function buildEncounterStatusHistory(array $boundaries, string $targetStatus): array
    {
        $t0 = $boundaries['t0'] ?? null;
        $t1 = $boundaries['t1'] ?? null;
        $t2 = $boundaries['t2'] ?? null;

        $history = [];
        $arrived = ['status' => 'arrived', 'period' => ['start' => $t0]];
        if ($t1 !== null) {
            $arrived['period']['end'] = $t1;
        }
        $history[] = $arrived;

        if (in_array($targetStatus, ['in-progress', 'finished'], true)) {
            $inProgress = ['status' => 'in-progress', 'period' => ['start' => $t1 ?? $t0]];
            if ($targetStatus === 'finished' && $t2 !== null) {
                $inProgress['period']['end'] = $t2;
            }
            $history[] = $inProgress;
        }

        if ($targetStatus === 'finished' && $t2 !== null) {
            $history[] = [
                'status' => 'finished',
                'period' => ['start' => $t2, 'end' => $t2],
            ];
        }

        return $history;
    }

    public static function encounter(
        string $orgId,
        array $p,
        string $idPasien,
        string $idDokter,
        string $status,
        array $diagnoses = [],
        string $idEncounter = '',
        ?string $idEpisodeOfCare = null
    ): array {
        $isRalan = ($p['status_lanjut'] === 'Ralan');
        $isRanap = ($p['status_lanjut'] === 'Ranap');
        if (($p['kd_poli'] ?? '') === 'IGDK') {
            $classCode = 'EMER';
            $classDisplay = 'emergency';
        } else {
            $classCode = $isRalan ? 'AMB' : 'IMP';
            $classDisplay = $isRalan ? 'ambulatory' : 'inpatient encounter';
        }

        // Unified contiguous timeline (identical across poli/IGD/Ranap and
        // conversions — see resolveEncounterBoundaries()).
        $boundaries = self::resolveEncounterBoundaries($p);
        $startWaktu = $boundaries['t0'];          // period.start = arrival
        $finishedWaktu = $boundaries['t2'];       // period.end = care end

        $statusHistory = self::buildEncounterStatusHistory($boundaries, $status);

        // Build location entries with period and ServiceClass extension.
        // Never emit an empty Location/ reference (rule 10120) — when the
        // polyclinic/room has no SATUSEHAT location mapping the element is
        // omitted entirely (canonical payloads do not require location).
        $locationEntries = [];
        if (!empty($p['id_lokasi_satusehat'])) {
            $locationEntry = [
                'location' => [
                    'reference' => 'Location/' . $p['id_lokasi_satusehat'],
                    'display'   => $p['nm_poli']
                ],
                'period' => [
                    'start' => self::getLocationPeriodStart($p, $status),
                ],
                'extension' => [
                    self::buildServiceClassExtension($p)
                ]
            ];
            if ($status === 'finished' && $finishedWaktu) {
                $locationEntry['period']['end'] = $finishedWaktu;
            }
            $locationEntries[] = $locationEntry;
        }

        $payload = [
            'resourceType' => 'Encounter',
            'status' => $status,
            'class' => [
                'system'  => 'http://terminology.hl7.org/CodeSystem/v3-ActCode',
                'code'    => $classCode,
                'display' => $classDisplay
            ],
            'serviceType' => \ServiceTypeTerminology::coding($p['kd_poli'] ?? '', $p['status_lanjut'] ?? '', $p['nm_poli'] ?? ''),
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
            'location' => $locationEntries,
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

        if ($locationEntries === []) {
            unset($payload['location']);
        }

        if ($status === 'finished' && $finishedWaktu) {
            $payload['period']['end'] = $finishedWaktu;
        }

        // Add episodeOfCare link if present
        if ($idEpisodeOfCare !== null) {
            $payload['episodeOfCare'] = [
                ['reference' => 'EpisodeOfCare/' . $idEpisodeOfCare]
            ];
        }

        if (!empty($idEncounter)) {
            $payload['id'] = $idEncounter;
        }

        // Add length (duration) for finished encounters
        if ($status === 'finished' && $finishedWaktu) {
            $durationSeconds = strtotime($finishedWaktu) - strtotime($startWaktu);
            if ($durationSeconds > 0) {
                $unit = $isRalan ? 'min' : 'd';
                $durationValue = $isRalan ? round($durationSeconds / 60) : round($durationSeconds / 86400, 1);
                if ($durationValue < 1) {
                    $durationValue = 1;
                    $unit = 'min';
                }
                $payload['length'] = [
                    'value'  => $durationValue,
                    'unit'   => $unit,
                    'system' => 'http://unitsofmeasure.org',
                    'code'   => $unit
                ];
            }
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
     * @param array  $diagnoses Array of diagnoses (optional, with id_condition, nm_penyakit)
     * @return array
     */
    public static function episodeOfCare(
        string $orgId,
        array $p,
        string $idPasien,
        string $idDokter,
        string $status,
        EpisodeOfCareType $type,
        string $idEpisode = '',
        array $diagnoses = []
    ): array {
        $startWaktu = self::sanitizeDateTime($p['tgl_registrasi'] ?? null, $p['jam_reg'] ?? null, $p);
        $finishedWaktu = !empty($p['waktu_pulang']) ? self::sanitizeDateTime($p['waktu_pulang'], null, $p) : null;

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

        // Add diagnosis array with Condition references
        if (!empty($diagnoses)) {
            $diagnosisArray = [];
            $rank = 1;
            foreach ($diagnoses as $diag) {
                $idCond = $diag['id_condition'] ?? ($diag['id'] ?? null);
                $nmPenyakit = $diag['nm_penyakit'] ?? ($diag['display'] ?? '');
                if (empty($idCond)) {
                    continue;
                }
                $diagnosisArray[] = [
                    'condition' => [
                        'reference' => 'Condition/' . $idCond,
                        'display'   => $nmPenyakit
                    ],
                    'role' => [
                        'coding' => [
                            [
                                'system'  => 'http://terminology.hl7.org/CodeSystem/diagnosis-role',
                                'code'    => 'DD',
                                'display' => 'Discharged Diagnosis'
                            ]
                        ]
                    ],
                    'rank' => $rank
                ];
                $rank++;
            }
            if (!empty($diagnosisArray)) {
                $payload['diagnosis'] = $diagnosisArray;
            }
        }

        return $payload;
    }

    /**
     * ICD-10 code mapping: maps codes not recognized by SATUSEHAT (ICD-10 2010 edition)
     * to their nearest accepted equivalent.
     *
     * Structure: 'REJECTED_CODE' => 'ACCEPTED_2010_CODE'
     */
    private static function mapIcd10(string $code): string
    {
        // Env-driven overrides win: SATUSEHAT_ICD10_OVERRIDES = JSON object
        // {"REJECTED_CODE": "ACCEPTED_2010_CODE", ...} — the maintenance
        // point for codes the running SATUSEHAT dictionary keeps rejecting.
        static $overrides = null;
        if ($overrides === null) {
            $raw = getenv('SATUSEHAT_ICD10_OVERRIDES') ?: '';
            $decoded = $raw !== '' ? json_decode($raw, true) : null;
            $overrides = is_array($decoded) ? $decoded : [];
        }
        if (isset($overrides[$code]) && is_string($overrides[$code]) && $overrides[$code] !== '') {
            return $overrides[$code];
        }

        $map = [
            // Cardiovascular
            'I96'   => 'I95.9',  // Gangrene (not in 2010) -> Hypotension, unspecified
            'I69.9' => 'I69.8',  // Sequelae of other/unspecified CVD (not 2010) -> Sequelae of other/unspecified
            'I50.2' => 'I50.9',  // Systolic heart failure (2012+) -> Heart failure, unspecified
            'I16.1' => 'I10',    // Hypertensive emergency (2018+) -> Essential hypertension
            'I48.9' => 'I48',    // AFib, unspecified (4-digit, not in 2010) -> AFib (3-digit 2010)
            // Hemorrhoid
            'K64.9' => 'I84.9',  // Haemorrhoid unspecified (not in ICD-10 2010 K-series) -> Haemorrhoid unspecified
            'K64.3' => 'I84.3',  // Haemorrhoid grade 3 -> Internal haemorrhoid grade 3
            // Respiratory
            'J96.0' => 'J96',    // Acute respiratory failure (ICD-10 2010 uses 3-digit)
            'J96.1' => 'J96',    // Chronic respiratory failure
            'J96.9' => 'J96',    // Respiratory failure, unspecified
            // Fever
            'R50.0' => 'R50',    // Fever with chills (ICD-10 2010 uses 3-digit R50)
            'R50.9' => 'R50',    // Fever, unspecified
            // Other preventive/screening
            'Z00.11' => 'Z00.1', // Health examination for newborns (not in 2010) -> Health examination
            'Z00.12' => 'Z00.1',
            // Atherosclerosis (I70.xx) — WHO ICD-10 uses I70.9 not I70.90 (5-char is US CM extension)
            'I70.90' => 'I70.9',  // Unspecified atherosclerosis -> Atherosclerosis, unspecified
            // Benign neoplasm of mandible (D16.5x)
            'D16.50' => 'D16.5',  // Benign neoplasm of mandible (unspecified part) -> D16.5 4-char WHO form
        ];

        return $map[$code] ?? $code; // Return mapped code, or original if not in map
    }

    /**
     * Build Condition payload.
     *
     * @param array  $p        Patient/Diagnosis data row
     * @param string $idPasien IHS Patient ID
     * @param string $idCondition Existing Condition ID (if updating)
     * @return array|null Returns null if the ICD-10 code is invalid/empty (should be skipped).
     */
    public static function condition(array $p, string $idPasien, string $idCondition = '', ?string $idDokter = null, ?string $namaDokter = null): ?array
    {
        $startWaktu = self::sanitizeDateTime($p['tgl_registrasi'] ?? null, $p['jam_reg'] ?? null, $p);
        $waktuPulang = $p['pulang'] ?? '';

        // Validate and map ICD-10 code
        $rawCode = strtoupper(trim($p['kd_penyakit'] ?? ''));
        if (empty($rawCode) || $rawCode === '-' || $rawCode === '.') {
            return null; // Signal to caller to skip this record
        }
        $kdPenyakit = self::mapIcd10($rawCode);

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
                        // Explicit mapping fields if present; default to ICD-10
                        // coding from diagnosa_pasien (kd_penyakit / nm_penyakit).
                        'system'  => !empty(trim((string) ($p['system'] ?? '')))
                            ? trim((string) $p['system'])
                            : 'http://hl7.org/fhir/sid/icd-10',
                        'code'    => !empty(trim((string) ($p['code'] ?? '')))
                            ? trim((string) $p['code'])
                            : $kdPenyakit,
                        'display' => !empty(trim((string) ($p['display'] ?? '')))
                            ? trim((string) $p['display'])
                            : trim((string) ($p['nm_penyakit'] ?? ''))
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
            ],
            'recordedDate' => $startWaktu,
        ];

        if ($idDokter !== null) {
            $payload['recorder'] = [
                'reference' => 'Practitioner/' . $idDokter,
                'display'   => $namaDokter ?? ''
            ];
        }

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
        $waktuObservasi = self::sanitizeDateTime($p['tgl_observasi'] ?? null, $p['jam_observasi'] ?? null, $p);

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
                'value'  => \SatuSehatNumber::parse($val) ?? 0.0,
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
            $systolic = \SatuSehatNumber::parse($parts[0] ?? '') ?? 0.0;
            $diastolic = \SatuSehatNumber::parse($parts[1] ?? '') ?? 0.0;

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
    public static function procedure(array $p, string $idPasien, string $idProcedure = '', ?string $idDokter = null, ?string $namaDokter = null): array
    {
        $startWaktu = self::sanitizeDateTime($p['waktu_registrasi'] ?? null, null, $p);
        $endWaktu = self::sanitizeDateTime($p['waktu_pulang'] ?? null, null, $p);

        // Ensure start <= end (FHIRPath constraint)
        if (strtotime($endWaktu) < strtotime($startWaktu)) {
            $endWaktu = $startWaktu;
        }

        $payload = [
            'resourceType' => 'Procedure',
            'status' => 'completed',
            // category is 0..1 CodeableConcept → OBJECT form; the legacy
            // array-of-one shape was rejected as unparseable_resource.
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
                'display'   => 'Prosedur ' . $p['nm_pasien'] . ' selama kunjungan/dirawat dari tanggal ' . $startWaktu . ' sampai ' . $endWaktu
            ],
            'performedPeriod' => [
                'start' => $startWaktu,
                'end'   => $endWaktu
            ]
        ];

        // Add performer (critical — 98% of Postman examples include it)
        if ($idDokter !== null) {
            $payload['performer'] = [
                [
                    'actor' => [
                        'reference' => 'Practitioner/' . $idDokter,
                        'display'   => $namaDokter ?? ''
                    ]
                ]
            ];
        }

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
     * @param string|null $title   CarePlan title (defaults to 'Instruksi Medik dan Keperawatan Pasien')
     * @param array  $goalRefs     Array of Goal references (optional)
     * @return array
     */
    public static function carePlan(
        string $orgId,
        array $p,
        string $idPasien,
        string $idDokter,
        string $idCarePlan = '',
        ?string $title = null,
        array $goalRefs = []
    ): array {
        $isRalan = ($p['status_lanjut'] === 'Ralan');
        $createdTime = self::sanitizeDateTime($p['tgl_perawatan'] ?? null, $p['jam_rawat'] ?? null, $p);
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
            'title' => $title ?? 'Instruksi Medik dan Keperawatan Pasien',
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

        // Add period if available
        $startWaktu = self::sanitizeDateTime($p['tgl_registrasi'] ?? null, $p['jam_reg'] ?? null, $p);
        $endWaktu = !empty($p['waktu_pulang']) ? self::sanitizeDateTime($p['waktu_pulang'], null, $p) : null;
        if ($endWaktu !== null && strtotime($endWaktu) < strtotime($startWaktu)) {
            $endWaktu = null; // Drop contradictory end date (Rule: start <= end)
        }
        $period = ['start' => $startWaktu];
        if ($endWaktu !== null) {
            $period['end'] = $endWaktu;
        }
        $payload['period'] = $period;

        // Add goal references if available
        if (!empty($goalRefs)) {
            $goals = [];
            foreach ($goalRefs as $g) {
                $goals[] = ['reference' => 'Goal/' . $g];
            }
            $payload['goal'] = $goals;
        }

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
        $recordedDate = self::sanitizeDateTime($a['tgl_perawatan'] ?? null, $a['jam_rawat'] ?? null, $a);

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
        // Copy-paste leftover from medication(): `$p` was never defined here
        // and is never used below — the builder reads `$imm` directly.
        // Removing it fixes a TypeError that crashed every Immunization sync.
        // Occurrence time
        $occurrenceDateTime = self::sanitizeDateTime($imm['tgl_perawatan'] ?? null, $imm['jam'] ?? null, $imm);
        
        // Expiration date (only if valid)
        $expirationDate = null;
        if (!empty($imm['tgl_kadaluarsa']) && $imm['tgl_kadaluarsa'] !== '0000-00-00' && strpos($imm['tgl_kadaluarsa'], '0000') === false) {
            $expirationDate = self::sanitizeDateTime($imm['tgl_kadaluarsa'], null, [], [], true);
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
            'doseQuantity' => self::sanitizeUcum([
                'value' => \SatuSehatNumber::parse($imm['jml']) ?? 0.0,
                'unit' => $imm['dose_quantity_unit'],
                'system' => $imm['dose_quantity_system'],
                'code' => $imm['dose_quantity_code']
            ]),
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
        $p = self::cleanMappingRow($p);
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
                        'system'  => str_replace(' ', '', trim($p['obat_system'])),
                        'code'    => trim($p['obat_code']),
                        'display' => trim($p['obat_display'])
                    ]
                ]
            ],
            'status' => $p['status'] === '0' ? 'inactive' : 'active',
            'form' => [
                'coding' => [
                    [
                        'system'  => str_replace(' ', '', trim($p['form_system'])),
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
        $p = self::cleanMappingRow($p);
        // Parse signa aturan pakai (e.g. "3x1", "1x0.5") — ensure positive values
        $signa1 = 1.0;
        $signa2 = 1.0;
        $aturan = $p['aturan_pakai'] ?? $p['aturan'] ?? '';
        $parts = explode('x', strtolower($aturan));
        if (isset($parts[0])) {
            $signa1 = \SatuSehatNumber::parse($parts[0]) ?? 1.0;
        }
        if (isset($parts[1])) {
            $signa2 = \SatuSehatNumber::parse($parts[1]) ?? 1.0;
        }

        // Format dates: e.g. "2026-02-09 10:15:30" -> "2026-02-09T10:15:30+07:00"
        $authoredOn = self::sanitizeDateTime($p['tgl_peresepan'] ?? null, $p['jam_peresepan'] ?? null, $p);

        // Identifiers
        $isRacikan = (bool)($p['is_racikan'] ?? false);
        $noRacik = $p['no_racik'] ?? '';
        
        $prescVal = $p['no_resep'];
        if ($isRacikan && $noRacik !== '') {
            $prescVal = $p['no_resep'] . '-' . $noRacik;
        }

        // Official semantics: the prescription-item identifier is
        // "{no_resep}-{item sequence}" (e.g. A00000111222-1), NOT the local
        // drug code. The adapter assigns prescription_item_seq per
        // prescription; legacy rows without it fall back to kode_brng.
        $itemVal = trim((string) ($p['prescription_item_seq'] ?? ''));
        if ($itemVal === '') {
            $itemVal = $p['kode_brng'];
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
                    'value'  => $itemVal
                ]
            ],
            'status' => 'completed',
            'intent' => 'order',
            'category' => [
                [
                    'coding' => [
                        [
                            'system'  => 'http://terminology.hl7.org/CodeSystem/medicationrequest-category',
                            'code'    => strtolower((string)($p['status_lanjut'] ?? '')) === 'ranap' ? 'inpatient' : 'outpatient',
                            'display' => strtolower((string)($p['status_lanjut'] ?? '')) === 'ranap' ? 'Inpatient' : 'Outpatient'
                        ]
                    ]
                ]
            ],
            'priority' => 'routine',
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
            'courseOfTherapyType' => [
                'coding' => [
                    [
                        'system'  => 'http://terminology.hl7.org/CodeSystem/medicationrequest-course-of-therapy',
                        'code'    => 'continuous',
                        'display' => 'Continuing long term therapy'
                    ]
                ]
            ],
            'dosageInstruction' => [
                [
                    'sequence' => 1,
                    'patientInstruction' => $aturan,
                    'timing' => [
                        'repeat' => [
                            'frequency'  => max(1, (int) round($signa2)),
                            'period'     => 1,
                            'periodUnit' => 'd'
                        ]
                    ],
                    'route' => self::sanitizeRoute($p) ?: [],
                    'doseAndRate' => [
                        [
                            'doseQuantity' => self::sanitizeUcum([
                                'value'  => $signa1,
                                'unit'   => isset($p['denominator_code']) ? trim($p['denominator_code']) : null,
                                'system' => isset($p['denominator_system']) ? trim($p['denominator_system']) : null,
                                'code'   => isset($p['denominator_code']) ? trim($p['denominator_code']) : null
                            ])
                        ]
                    ]
                ]
            ],
            'dispenseRequest' => [
                'quantity' => self::sanitizeUcum([
                    'value'  => $p['jml'],
                    'unit'   => isset($p['denominator_code']) ? trim($p['denominator_code']) : null,
                    'system' => isset($p['denominator_system']) ? trim($p['denominator_system']) : null,
                    'code'   => isset($p['denominator_code']) ? trim($p['denominator_code']) : null
                ])
            ]
        ];

        // Include Organization performer as in Java (only if not compound, but let's make it consistent)
        if (!$isRacikan) {
            $payload['dispenseRequest']['performer'] = [
                'reference' => 'Organization/' . $orgId
            ];
        }

        self::stripEmptyRoutes($payload);

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
        $p = self::cleanMappingRow($p);
        // Parse signa aturan pakai (e.g. "3x1", "1x0.5") — ensure positive values
        $signa1 = 1.0;
        $signa2 = 1.0;
        $aturan = $p['aturan'] ?? $p['aturan_pakai'] ?? '';
        $parts = explode('x', strtolower($aturan));
        if (isset($parts[0])) {
            $parsed1 = \SatuSehatNumber::parse($parts[0]);
            if ($parsed1 !== null && $parsed1 > 0) {
                $signa1 = $parsed1;
            }
        }
        if (isset($parts[1])) {
            $parsed2 = \SatuSehatNumber::parse($parts[1]);
            if ($parsed2 !== null && $parsed2 > 0) {
                $signa2 = $parsed2;
            }
        }

        // Format dates: e.g. "2026-02-09 10:15:30" -> "2026-02-09T10:15:30+07:00"
        $whenPrepared = self::sanitizeDateTime($p['tgl_peresepan'] ?? null, $p['jam_peresepan'] ?? null, $p);
        $whenHandedOver = self::sanitizeDateTime($p['tgl_perawatan'] ?? null, $p['jam'] ?? null, $p);

        // Enforce constraint: whenHandedOver >= whenPrepared
        if (strtotime($whenHandedOver) < strtotime($whenPrepared)) {
            $whenHandedOver = $whenPrepared;
        }

        // Identifiers: must be from the allowed systems in SATUSEHAT for MedicationDispense
        $payload = [
            'resourceType' => 'MedicationDispense',
            'identifier' => [
                [
                    'system' => 'http://sys-ids.kemkes.go.id/prescription/' . $orgId,
                    'use'    => 'official',
                    'value'  => $p['no_resep']
                ],
                [
                    'system' => 'http://sys-ids.kemkes.go.id/prescription-item/' . $orgId,
                    'use'    => 'official',
                    'value'  => $p['kode_brng']
                ]
            ],
            'status' => 'completed',
            // category is 0..1 CodeableConcept → OBJECT form (official
            // fixture; list form was rejected with "expected a CodeableConcept
            // object" → rule 10480-class 400s on every dispense).
            'category' => [
                'coding' => [
                    [
                        'system'  => 'http://terminology.hl7.org/fhir/CodeSystem/medicationdispense-category',
                        'code'    => strtolower((string)($p['status_pemberian'] ?? '')) === 'ranap' ? 'inpatient' : 'outpatient',
                        'display' => strtolower((string)($p['status_pemberian'] ?? '')) === 'ranap' ? 'Inpatient' : 'Outpatient'
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
            'quantity' => self::sanitizeUcum([
                'value'  => $p['jml'],
                'unit'   => isset($p['denominator_code']) ? trim($p['denominator_code']) : null,
                'system' => isset($p['denominator_system']) ? trim($p['denominator_system']) : null,
                'code'   => isset($p['denominator_code']) ? trim($p['denominator_code']) : null
            ]),
            'whenPrepared'   => $whenPrepared,
            'whenHandedOver' => $whenHandedOver,
            'dosageInstruction' => [
                [
                    'sequence' => 1,
                    'text'     => $aturan,
                    'timing' => [
                        'repeat' => [
                            'frequency'  => max(1, (int) round($signa2)),
                            'period'     => 1,
                            'periodUnit' => 'd'
                        ]
                    ],
                    'route' => self::sanitizeRoute($p) ?: [],
                    'doseAndRate' => [
                        [
                            'doseQuantity' => self::sanitizeUcum([
                                'value'  => $signa1,
                                'unit'   => isset($p['denominator_code']) ? trim($p['denominator_code']) : null,
                                'system' => isset($p['denominator_system']) ? trim($p['denominator_system']) : null,
                                'code'   => isset($p['denominator_code']) ? trim($p['denominator_code']) : null
                            ])
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

        // Rule 10393: location is only emitted when the depo mapping exists —
        // a bare "Location/" reference is always rejected.
        $locId = trim((string) ($p['id_lokasi_satusehat'] ?? ''));
        if ($locId !== '') {
            $payload['location'] = [
                'reference' => 'Location/' . $locId,
                'display'   => (string) ($p['nm_bangsal'] ?? ''),
            ];
        }

        // Add daysSupply if available (from prescription or calculated)
        $jml = \SatuSehatNumber::parse($p['jml'] ?? '') ?? 0.0;
        $supplyDays = 0;
        if ($jml > 0 && $signa1 > 0) {
            // Calculate days supply: total qty / dose per day
            $dosePerDay = $signa1 * max((int)$signa2, 1);
            if ($dosePerDay > 0) {
                $supplyDays = (int)ceil($jml / $dosePerDay);
            }
        }
        if ($supplyDays > 0) {
            $payload['daysSupply'] = [
                'value'  => $supplyDays,
                'unit'   => 'Day',
                'system' => 'http://unitsofmeasure.org',
                'code'   => 'd'
            ];
        }

        self::stripEmptyRoutes($payload);

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
        $p = self::cleanMappingRow($p);
        // Parse signa aturan pakai (e.g. "3x1", "1x0.5") — ensure positive values
        $signa1 = 1.0;
        $signa2 = 1.0;
        $aturan = $p['aturan_pakai'] ?? $p['aturan'] ?? '';
        $parts = explode('x', strtolower($aturan));
        if (isset($parts[0])) {
            $parsed1 = \SatuSehatNumber::parse($parts[0]);
            if ($parsed1 !== null && $parsed1 > 0) {
                $signa1 = $parsed1;
            }
        }
        if (isset($parts[1])) {
            $parsed2 = \SatuSehatNumber::parse($parts[1]);
            if ($parsed2 !== null && $parsed2 > 0) {
                $signa2 = $parsed2;
            }
        }

        // Format dates: e.g. "2026-02-09 10:15:30" -> "2026-02-09T10:15:30+07:00"
        $dateAsserted = self::sanitizeDateTime($p['tgl_penyerahan'] ?? null, $p['jam_penyerahan'] ?? null, $p);

        // Identifiers:
        // System: http://sys-ids.kemkes.go.id/medicationstatement/{orgId}
        // Value non-racikan: {no_resep}-{kode_brng}
        // Value racikan: {no_resep}-{kode_brng}-{no_racik}
        $isRacikan = (bool)($p['is_racikan'] ?? false);
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
            // category is 0..1 CodeableConcept → OBJECT form (mirrors
            // MedicationDispense; list form was rejected by the server).
            // 'community' is the only reliably accepted code for this system —
            // rule 10436 rejects inpatient/outpatient (those belong to
            // MedicationRequest.category; canonical statements use community).
            'category' => [
                'coding' => [
                    [
                        'system'  => 'http://terminology.hl7.org/CodeSystem/medication-statement-category',
                        'code'    => 'community',
                        'display' => 'Community'
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
                            'frequency'  => max(1, (int) round($signa2)),
                            'period'     => 1,
                            'periodUnit' => 'd'
                        ]
                    ],
                    'route' => self::sanitizeRoute($p) ?: [],
                    'doseAndRate' => [
                        [
                            'doseQuantity' => self::sanitizeUcum([
                                'value'  => $signa1,
                                'unit'   => isset($p['denominator_code']) ? trim($p['denominator_code']) : null,
                                // system must be present when code is present
                                // (RuleNumber 10480 rejects a missing/empty
                                // system — the earlier "intentionally null"
                                // workaround was the actual cause of the
                                // 512-record infinite retry loop).
                                'system' => isset($p['denominator_system']) && trim((string) $p['denominator_system']) !== ''
                                    ? trim((string) $p['denominator_system'])
                                    : 'http://unitsofmeasure.org',
                                'code'   => isset($p['denominator_code']) ? trim($p['denominator_code']) : null
                            ])
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

        self::stripEmptyRoutes($payload);

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

        $effectiveDateTime = self::sanitizeDateTime($p['tgl_perawatan'] ?? null, $p['jam_rawat'] ?? null, $p);

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
                                // mapIcd10() remaps codes missing from the
                                // platform's ICD-10 2010 dictionary (e.g. I96,
                                // rule 10082) — legacy map + env overrides.
                                'system'  => 'http://hl7.org/fhir/sid/icd-10',
                                'code'    => self::mapIcd10(strtoupper(trim($p['kd_penyakit']))),
                                'display' => $p['nm_penyakit'],
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
        $authored = self::sanitizeDateTime($p['tgl_peresepan'] ?? null, $p['jam_peresepan'] ?? null, $p);

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
        $p = self::cleanMappingRow($p);
        $acsn = self::buildAcsn($p['noorder'], $p['kd_jenis_prw']);
        
        $authoredOn = self::sanitizeDateTime($p['tgl_permintaan'] ?? null, $p['jam_permintaan'] ?? null, $p);
        $tglJam = date('Y-m-d H:i:s', strtotime($authoredOn));

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
        $p = self::cleanMappingRow($p);
        $dateTimeStr = self::sanitizeDateTime(
            $p['tgl_hasil'] ?? null,
            $p['jam_hasil'] ?? null,
            $p,
            [
                ['tgl_permintaan', 'jam_permintaan']
            ]
        );

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
        $p = self::cleanMappingRow($p);
        $receivedTime = self::sanitizeDateTime(
            $p['tgl_sampel'] ?? null,
            $p['jam_sampel'] ?? null,
            $p,
            [
                ['tgl_permintaan', 'jam_permintaan']
            ]
        );

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
        $p = self::cleanMappingRow($p);
        $dateTimeStr = self::sanitizeDateTime(
            $p['tgl_hasil'] ?? null,
            $p['jam_hasil'] ?? null,
            $p,
            [
                ['tgl_permintaan', 'jam_permintaan']
            ]
        );

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
                'display'   => 'Hasil Pemeriksaan Radiologi ' . $p['nm_perawatan'] . ' No.Rawat ' . $p['no_rawat'] . ', Atas Nama Pasien ' . $p['nm_pasien'] . ', Pada Tanggal ' . $p['tgl_hasil'] . ' ' . ($p['jam_hasil'] ?? '')
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
        $p = self::cleanMappingRow($p);
        $dateTimeStr = self::sanitizeDateTime($p['tgl_permintaan'] ?? null, $p['jam_permintaan'] ?? null, $p);

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
                'display'   => 'Permintaan ' . $p['Pemeriksaan'] . ' atas nama pasien ' . $p['nm_pasien'] . ' No.RM ' . $p['no_rkm_medis'] . ' No.Rawat ' . $p['no_rawat'] . ', pada tanggal ' . $p['tgl_permintaan']
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
        $p = self::cleanMappingRow($p);
        $receivedTime = self::sanitizeDateTime(
            $p['tgl_sampel'] ?? null,
            $p['jam_sampel'] ?? null,
            $p,
            [
                ['tgl_permintaan', 'jam_permintaan']
            ]
        );

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
        $p = self::cleanMappingRow($p);
        $dateTimeStr = self::sanitizeDateTime(
            $p['tgl_hasil'] ?? null,
            $p['jam_hasil'] ?? null,
            $p,
            [
                ['tgl_permintaan', 'jam_permintaan']
            ]
        );

        $valueString = 'Hasil Lab : ' . $p['nilai'] . ' ' . $p['satuan'] . ', Nilai Rujukan : ' . $p['nilai_rujukan'];
        if (!empty($p['keterangan'])) {
            $valueString .= ', Keterangan : ' . $p['keterangan'];
        }
        $valueString = str_replace(["\r\n", "\r", "\n"], '<br>', $valueString);
        $valueString = str_replace("\t", ' ', $valueString);

        // Numeric results become valueQuantity (official SATUSEHAT lab
        // pattern) instead of narrative text; text results (culture/growth,
        // qualitative) keep valueString.
        $numeric = \SatuSehatNumber::parse($p['nilai'] ?? '');
        $ucumCode = self::mapLabUnit((string) ($p['satuan'] ?? ''));

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
                'display'   => 'Hasil Pemeriksaan Lab ' . $p['Pemeriksaan'] . ' No.Rawat ' . $p['no_rawat'] . ', Atas Nama Pasien ' . $p['nm_pasien'] . ', No.RM ' . $p['no_rkm_medis'] . ', Pada Tanggal ' . $p['tgl_hasil']
            ],
            'specimen' => [
                'reference' => 'Specimen/' . $p['id_specimen']
            ],
            'effectiveDateTime' => $dateTimeStr,
        ];

        if ($numeric !== null && $ucumCode !== null) {
            // Coded unit known → valueQuantity with system+code (SATUSEHAT
            // requires the coded form; a bare value is rejected as
            // "Invalid coding system" — rule 10012).
            $payload['valueQuantity'] = [
                'value'  => $numeric,
                'unit'   => (string) ($p['satuan'] ?? ''),
                'system' => 'http://unitsofmeasure.org',
                'code'   => $ucumCode,
            ];
        } elseif ($numeric !== null) {
            // Unit not mappable to UCUM → emit the number textually instead
            // of a unitless valueQuantity (keeps the result accepted).
            $satuan = trim((string) ($p['satuan'] ?? ''));
            $payload['valueString'] = $satuan !== '' ? $numeric . ' ' . $satuan : (string) $numeric;
        } else {
            $payload['valueString'] = $valueString;
        }

        if (!empty($idObservation) && $idObservation !== '-') {
            $payload['id'] = $idObservation;
        }

        return $payload;
    }

    /**
     * Map a SIMRS lab unit string to its UCUM representation for
     * valueQuantity.system (http://unitsofmeasure.org). Unknown units return
     * null and the quantity is emitted without system/code.
     */
    private static function mapLabUnit(string $unit): ?string
    {
        $unit = strtolower(preg_replace('/\s+/', '', $unit) ?: '');
        $map = [
            'mg/dl' => 'mg/dL', 'g/dl' => 'g/dL', 'g/l' => 'g/L', 'mg/l' => 'mg/L',
            'ng/dl' => 'ng/dL', 'ng/ml' => 'ng/mL', 'pg/ml' => 'pg/mL', 'µg/ml' => 'ug/mL',
            'mmol/l' => 'mmol/L', 'µmol/l' => 'umol/L', 'mcmol/l' => 'umol/L',
            'u/l' => 'U/L', 'iu/l' => 'IU/L', 'iu/ml' => 'IU/mL', 'miu/ml' => 'mIU/mL',
            'meq/l' => 'meq/L', 'mmhg' => 'mm[Hg]', 'mm/hg' => 'mm[Hg]',
            '%' => '%', 'µl' => 'uL', 'ul' => 'uL', 'ml' => 'mL', 'l' => 'L',
            'fl' => 'fL', 'pg' => 'pg', 'mg' => 'mg', 'g' => 'g', 'µg' => 'ug',
            '10^3/µl' => '10*3/uL', '10^3/ul' => '10*3/uL', 'sel/µl' => '10*3/uL',
            'ratio' => '{ratio}', 'index' => '{index}',
        ];
        return $map[$unit] ?? null;
    }

    public static function diagnosticReportLab(
        array $p,
        string $idPasien,
        string $idDokter,
        string $orgId,
        string $idDiagnosticReport = ''
    ): array {
        $p = self::cleanMappingRow($p);
        $dateTimeStr = self::sanitizeDateTime(
            $p['tgl_hasil'] ?? null,
            $p['jam_hasil'] ?? null,
            $p,
            [
                ['tgl_permintaan', 'jam_permintaan']
            ]
        );

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
            ]
        ];

        if ($conclusion !== '') {
            $payload['conclusion'] = $conclusion;
        }

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
        string $idComposition = '',
        string $status = 'final'
    ): array {
        $finishedWaktu = self::sanitizeDateTime($p['waktu_pulang'] ?? null, null, $p);

        $sections = [];

        // 1. Anamnesis Section (LOINC TK000003)
        $anamnesisEntries = [];
        if (!empty($refs['AllergyIntolerance'])) {
            foreach ($refs['AllergyIntolerance'] as $id) {
                $anamnesisEntries[] = ['reference' => self::compositionRef('AllergyIntolerance', $id)];
            }
        }
        if (!empty($anamnesisEntries)) {
            $sections[] = [
                'title' => 'Anamnesis',
                'code' => [
                    'coding' => [
                        [
                            'system' => 'http://terminology.kemkes.go.id',
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
                $obsEntries[] = ['reference' => self::compositionRef('Observation', $id)];
            }
            $sections[] = [
                'title' => 'Pemeriksaan Fisik',
                'code' => [
                    'coding' => [
                        [
                            'system' => 'http://terminology.kemkes.go.id',
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
                $condEntries[] = ['reference' => self::compositionRef('Condition', $id)];
            }
            $sections[] = [
                'title' => 'Diagnosis',
                'code' => [
                    'coding' => [
                        [
                            'system' => 'http://terminology.kemkes.go.id',
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
                $procEntries[] = ['reference' => self::compositionRef('Procedure', $id)];
            }
            $sections[] = [
                'title' => 'Tindakan/Prosedur Medis',
                'code' => [
                    'coding' => [
                        [
                            'system' => 'http://terminology.kemkes.go.id',
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
                $pharmacyEntries[] = ['reference' => self::compositionRef('MedicationRequest', $id)];
            }
        }
        if (!empty($refs['MedicationDispense'])) {
            foreach ($refs['MedicationDispense'] as $id) {
                $pharmacyEntries[] = ['reference' => self::compositionRef('MedicationDispense', $id)];
            }
        }
        if (!empty($pharmacyEntries)) {
            $sections[] = [
                'title' => 'Farmasi',
                'code' => [
                    'coding' => [
                        [
                            'system' => 'http://terminology.kemkes.go.id',
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
                $planEntries[] = ['reference' => self::compositionRef('ClinicalImpression', $id)];
            }
        }
        if (!empty($refs['CarePlan'])) {
            foreach ($refs['CarePlan'] as $id) {
                $planEntries[] = ['reference' => self::compositionRef('CarePlan', $id)];
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
                $supportEntries[] = ['reference' => self::compositionRef('DiagnosticReport', $id)];
            }
        }
        if (!empty($refs['Specimen'])) {
            foreach ($refs['Specimen'] as $id) {
                $supportEntries[] = ['reference' => self::compositionRef('Specimen', $id)];
            }
        }
        if (!empty($supportEntries)) {
            $sections[] = [
                'title' => 'Pemeriksaan Penunjang',
                'code' => [
                    'coding' => [
                        [
                            'system' => 'http://terminology.kemkes.go.id',
                            'code' => 'TK000009',
                            'display' => 'Pemeriksaan Penunjang'
                        ]
                    ]
                ],
                'entry' => $supportEntries
            ];
        }

        $isRalan = (($p['status_lanjut'] ?? '') === 'Ralan');
        $kdPoli = $p['kd_poli'] ?? '';

        if ($kdPoli === 'IGDK') {
            $typeCode = '97663-9';
            $typeDisplay = 'Emergency medicine Emergency department Discharge summary';
            $title = 'Resume Medis Gawat Darurat';
        } elseif ($isRalan) {
            $typeCode = '88645-7';
            $typeDisplay = 'Outpatient hospital Discharge summary';
            $title = 'Resume Medis Rawat Jalan';
        } else {
            $typeCode = '18842-5';
            $typeDisplay = 'Discharge Summary';
            $title = 'Resume Medis Rawat Inap';
        }

        $payload = [
            'resourceType' => 'Composition',
            'status' => $status,
            // Rule 10464: the composition identifier is required by SATUSEHAT
            // (the official example: sys-ids.kemkes.go.id/composition/{org}).
            'identifier' => [
                'system' => 'http://sys-ids.kemkes.go.id/composition/' . $orgId,
                'value'  => (string) ($p['no_rawat'] ?? '')
            ],
            'type' => [
                'coding' => [
                    [
                        'system' => 'http://loinc.org',
                        'code' => $typeCode,
                        'display' => $typeDisplay
                    ]
                ]
            ],
            'category' => [
                [
                    'coding' => [
                        [
                            'system' => 'http://loinc.org',
                            'code' => 'LP173421-1',
                            'display' => 'Report'
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
            'title' => $title,
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

    /**
     * Convert a full FHIR payload array into JSON Patch replace operations.
     * Skips server-managed fields (resourceType, id, meta) that cannot be PATCHed.
     * Each field becomes a separate {op: replace, path: /key, value: ...} operation.
     *
     * @param array $payload Full FHIR resource payload from a ::build*() method
     * @return array Array of JSON Patch operations
     */
    public static function payloadToPatchOps(array $payload): array
    {
        $ops = [];
        // Fields that are immutable after resource creation — PATCHing them triggers
        // SATUSEHAT's "You don't have permission to edit resource" because they
        // create an organization-binding scope.
        $skipKeys = [
            'resourceType', 'id', 'meta',
            'identifier',             // org-scoped: cannot be changed after creation
            'subject',                // patient reference: immutable
            'encounter',              // visit reference: immutable
            'context',                // encounter alias (MedicationDispense, MedicationStatement)
            'requester',              // who ordered the service: immutable
            'author',                 // who authored (Composition, CarePlan): immutable
            'recorder',               // who recorded (Condition): immutable
            'assessor',               // who assessed (ClinicalImpression): immutable
            'informationSource',      // who provided info (MedicationStatement): immutable
            'authoredOn',             // creation timestamp: immutable
            'dateAsserted',           // assertion timestamp: immutable
            'recordedDate',           // recording timestamp: immutable
            'serviceProvider',        // organization reference: immutable
            'managingOrganization',   // managing org: immutable
        ];

        foreach ($payload as $key => $value) {
            if (in_array($key, $skipKeys, true)) {
                continue;
            }
            $ops[] = [
                'op'    => 'replace',
                'path'  => '/' . $key,
                'value' => $value
            ];
        }

        return $ops;
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

    /**
     * Remove empty 'route' codings from dosage instructions (FHIR route is
     * 0..1 — an empty object is rejected; rule 10038 class).
     */
    private static function stripEmptyRoutes(array &$payload): void
    {
        foreach (['dosage', 'dosageInstruction'] as $key) {
            if (!isset($payload[$key]) || !is_array($payload[$key])) {
                continue;
            }
            foreach ($payload[$key] as &$entry) {
                if (is_array($entry) && empty($entry['route'])) {
                    unset($entry['route']);
                }
            }
            unset($entry);
        }
    }

    public static function sanitizeUcum(array $qty): array
    {
        $value = isset($qty['value']) ? $qty['value'] : null;
        $unit = isset($qty['unit']) ? trim((string)$qty['unit']) : '';
        $system = isset($qty['system']) ? trim((string)$qty['system']) : '';
        $code = isset($qty['code']) ? trim((string)$qty['code']) : '';

        // The unit classifier decides system+code: forms → orderableDrugForm,
        // measurable units → canonical UCUM case ('ml' → 'mL'), unknown →
        // coded-unit fields stripped (rule 10012: never send empty strings).
        $classified = self::classifyUnit($unit !== '' ? $unit : $code);
        if ($classified !== null) {
            $unit = $classified['unit'];
            $system = $classified['system'];
            $code = $classified['code'];
        } elseif ($code === '') {
            $system = '';
        } elseif ($system === '') {
            // Coded unit with an unknown unit label: keep the code but give
            // it the UCUM default system (rule 10480 rejects a missing system).
            $system = 'http://unitsofmeasure.org';
        }

        $res = [];
        if ($value !== null && $value !== '') {
            $res['value'] = \SatuSehatNumber::parse($value) ?? 0.0;
        }
        if ($unit !== '') {
            $res['unit'] = $unit;
        }
        if ($system !== '') {
            $res['system'] = $system;
        }
        if ($code !== '') {
            $res['code'] = $code;
        }

        return $res;
    }

    /**
     * Timezone-correct, semantic date formatting.
     *
     * Delegates to SatuSehatDateTime: parses SIMRS wall-clock values as
     * Asia/Jakarta, accepts future dates (vaccine expirationDate,
     * pre-registered visits), and NEVER invents timestamps — when nothing
     * valid is available (zero-dates etc.) the registration date is the last
     * fallback, otherwise '' is returned (callers emit no timestamp instead
     * of a wrong one).
     */
    public static function sanitizeDateTime(
        ?string $datePart,
        ?string $timePart = null,
        array $row = [],
        array $fallbackPreferences = [],
        bool $dateOnly = false
    ): string {
        $dt = \SatuSehatDateTime::parse($datePart, $timePart);

        if ($dt === null) {
            foreach ($fallbackPreferences as $pref) {
                $dt = \SatuSehatDateTime::parse($row[$pref[0]] ?? null, $row[$pref[1]] ?? null);
                if ($dt !== null) {
                    break;
                }
            }
        }

        if ($dt === null) {
            $dt = \SatuSehatDateTime::parse($row['tgl_registrasi'] ?? null, $row['jam_reg'] ?? null);
        }

        if ($dt === null) {
            return '';
        }

        return \SatuSehatDateTime::formatLocal($dt, $dateOnly) . ($dateOnly ? '' : '+07:00');
    }
}





