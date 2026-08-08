<?php

namespace SatusehatPanel\Controller;

use SatusehatPanel\Core\Database;
use SatusehatPanel\Core\Config;
use SatusehatPanel\Util\PayloadAdapter;

class SendController
{
    /**
     * Build a FHIR transaction Bundle from checked resources and POST it.
     *
     * Request body: { no_rawat, resources: ['Encounter', 'Condition', ...] }
     * Each entry has a urn:uuid fullUrl + request.method=POST + resource.
     * Mirrors the SATUSEHAT Postman Bundle transaction structure.
     */
    public static function sendBundle(string $noRawat): array
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            return ['success' => false, 'error' => 'Invalid JSON body'];
        }

        $resources = $input['resources'] ?? [];
        if (empty($resources)) {
            return ['success' => false, 'error' => 'No resources selected'];
        }

        // Fetch patient
        $db = Database::getMysql();
        $stmt = $db->prepare("
            SELECT rp.*, pj.nm_pasien, pj.no_ktp, pj.no_rkm_medis
            FROM reg_periksa rp
            JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            WHERE rp.no_rawat = ?
        ");
        $stmt->execute([$noRawat]);
        $patient = $stmt->fetch();
        if (!$patient) {
            return ['success' => false, 'error' => 'Patient not found'];
        }

        // Billing gate — the CLI only POSTs clinical resources for paid
        // visits (status_bayar = 'Sudah Bayar'). The panel list color-codes
        // billing status precisely to surface this; block sends otherwise.
        if (($patient['status_bayar'] ?? '') !== 'Sudah Bayar') {
            return [
                'success' => false,
                'error' => 'Pasien belum lunas (status_bayar != Sudah Bayar). Sesuai kebijakan CLI, kirim hanya untuk kunjungan yang sudah dibayar.',
                'build_errors' => [],
            ];
        }

        // ── Resolve IHS IDs before building payloads ──────────────────
        // During preview, PayloadAdapter uses placeholder IDs. During send,
        // we must resolve them via SATUSEHAT API to get real IDs.
        $nik = $patient['no_ktp'] ?? '';
        $ihsPasien = PayloadAdapter::resolvePatientIhs($db, $nik);

        // Get doctor NIK for IHS resolution
        $stmtDoc = $db->prepare("SELECT pg.no_ktp FROM reg_periksa rp JOIN pegawai pg ON pg.nik = rp.kd_dokter WHERE rp.no_rawat = ? LIMIT 1");
        $stmtDoc->execute([$noRawat]);
        $nikDokter = (string) ($stmtDoc->fetchColumn() ?: '');
        $ihsDokter = PayloadAdapter::resolveDokterIhs($db, $nikDokter);

        // Validate IHS IDs are real (not placeholders)
        if (str_contains($ihsPasien, 'PLACEHOLDER')) {
            return [
                'success' => false,
                'error' => 'IHS ID Pasien tidak ditemukan. Pastikan NIK pasien (' . $nik . ') terdaftar di SATUSEHAT.',
                'build_errors' => [],
            ];
        }
        if (str_contains($ihsDokter, 'PLACEHOLDER')) {
            return [
                'success' => false,
                'error' => 'IHS ID Dokter tidak ditemukan. Pastikan NIK dokter (' . $nikDokter . ') terdaftar di SATUSEHAT.',
                'build_errors' => [],
            ];
        }

        // Build each resource payload using the adopted PayloadBuilder logic
        $bundle = [
            'resourceType' => 'Bundle',
            'type' => 'transaction',
            'entry' => [],
        ];

        $buildErrors = [];
        $uuidMap = []; // resourceType => first UUID (first-wins, no overwrite)

        $customPayloads = $input['custom_payloads'] ?? [];

        foreach ($resources as $resource) {
            $payloadList = [];

            if (isset($customPayloads[$resource]) && is_array($customPayloads[$resource])) {
                $rawCustom = $customPayloads[$resource];
                $payloadList = isset($rawCustom['resourceType']) ? [$rawCustom] : $rawCustom;
            } else {
                try {
                    $payloadList = PayloadAdapter::build($resource, $noRawat, $patient);
                } catch (\Throwable $e) {
                    error_log("[PANEL] build failed for {$resource}: " . $e->getMessage());
                    $buildErrors[] = "{$resource}: gagal dibangun (" . $e->getMessage() . ")";
                    continue;
                }
            }

            if (empty($payloadList)) {
                $buildErrors[] = "{$resource}: no data on this visit";
                continue;
            }

            foreach ($payloadList as $payload) {
                if (!is_array($payload) || !isset($payload['resourceType'])) {
                    continue;
                }

                $realResourceType = $payload['resourceType'];
                $uuid = self::genUuid();

                // First-wins: only first UUID per type used for cross-references
                if (!isset($uuidMap[$resource])) {
                    $uuidMap[$resource] = $uuid;
                }
                if (!isset($uuidMap[$realResourceType])) {
                    $uuidMap[$realResourceType] = $uuid;
                }

                // Capture ALL persist-relevant metadata on the entry
                $entryMeta = [];

                // Medication family metadata
                if (in_array($realResourceType, ['MedicationRequest', 'MedicationStatement', 'MedicationDispense'], true)) {
                    $entryMeta['is_racikan'] = !empty($payload['_panel_is_racikan']);
                    $entryMeta['no_racik'] = (string) ($payload['_panel_no_racik'] ?? '');
                }

                // Composite persist keys (attached by PayloadAdapter for all resources)
                if (isset($payload['_panel_persist_keys'])) {
                    $entryMeta['persist_keys'] = $payload['_panel_persist_keys'];
                }

                // TTV type metadata
                if (isset($payload['_panel_ttv_type'])) {
                    $entryMeta['ttv_type'] = $payload['_panel_ttv_type'];
                }

                // Strip all internal _panel_* keys before wire
                foreach (array_keys($payload) as $k) {
                    if (str_starts_with($k, '_panel_')) {
                        unset($payload[$k]);
                    }
                }

                $entry = [
                    'fullUrl' => 'urn:uuid:' . $uuid,
                    'resource' => $payload,
                    'request' => [
                        'method' => 'POST',
                        'url' => $realResourceType,
                    ],
                ];
                if (!empty($entryMeta)) {
                    $entry['_panel_meta'] = $entryMeta;
                }
                $bundle['entry'][] = $entry;
            }
        }

        // ── Rewrite cross-resource references to use urn:uuid ──────────
        // Within a transaction Bundle, SATUSEHAT expects references between
        // entries to use fullUrl urn:uuid (Postman pattern), NOT the
        // Resource/{id} form — because resources created in THIS bundle have
        // no id yet. Child resources (Condition.encounter, MedicationRequest
        // .subject, etc.) built by PayloadBuilder use 'Encounter/{id}' /
        // 'Patient/{id}' from the mapping tables, which would be incomplete
        // ('Encounter/') when the referenced resource is also in this bundle.
        self::rewriteBundleReferences($bundle['entry'], $uuidMap);

        if (empty($bundle['entry'])) {
            return [
                'success' => false,
                'error' => 'No resources could be built',
                'build_errors' => $buildErrors,
            ];
        }

        // Check if there were partial build failures
        $partialFailure = count($buildErrors) > 0;

        // ── Strip internal _panel_meta from entries before wire ───────
        // _panel_meta carries persist-routing info (is_racikan, no_racik)
        // needed AFTER the response, but must NOT be sent to SATUSEHAT.
        // Keep a copy for persistCreatedIds, then strip from the bundle.
        $entryMetas = [];
        foreach ($bundle['entry'] as $i => $ent) {
            if (isset($ent['_panel_meta'])) {
                $entryMetas[$i] = $ent['_panel_meta'];
                unset($bundle['entry'][$i]['_panel_meta']);
            }
        }
        // Re-index so JSON encodes as array (not object)
        $bundle['entry'] = array_values($bundle['entry']);

        // ── Send the transaction Bundle to SATUSEHAT ──────────────────
        $client = self::getClient();
        $result = $client->post('/', $bundle);

        // ── Persist created IHS ids back to mapping tables ────────────
        // A successful transaction Bundle returns entry[].resource.id for
        // each created resource. Save them so the UI marks them "sent" and
        // future bundles reference the real ids instead of re-creating.
        $created = [];
        if (!empty($result['success']) && isset($result['data']['entry'])) {
            // Re-attach _panel_meta for persist routing
            foreach ($entryMetas as $i => $meta) {
                if (isset($bundle['entry'][$i])) {
                    $bundle['entry'][$i]['_panel_meta'] = $meta;
                }
            }
            $created = self::persistCreatedIds($noRawat, $bundle['entry'], $result['data']['entry']);
        }

        // ── Record audit log (with full API response) ─────────────────
        self::audit($noRawat, $resources, $bundle, $result['success'] ?? false, $result);

        return [
            'success' => $result['success'] ?? false,
            'partial_failure' => $partialFailure,
            'build_errors' => $buildErrors,
            'sent_count' => count($bundle['entry']),
            'created' => $created,
            'bundle' => $bundle,
            'response' => $result['data'] ?? [],
            'message' => ($result['success'] ?? false) ? 'Bundle sent successfully' : 'Bundle send failed',
        ];
    }

    /**
     * After a successful transaction, map response entry ids back to the
     * sent bundle entries and INSERT/UPDATE the satu_sehat_* mapping tables.
     *
     * V3: Uses _panel_persist_keys for composite key tables (Condition,
     * Procedure, CarePlan, AllergyIntolerance, ClinicalImpression, TTV, etc.)
     * and handles ALL resource types including lab pipeline and Immunization.
     *
     * @return array resourceType => SATUSEHAT id (persisted)
     */
    private static function persistCreatedIds(string $noRawat, array $requestEntries, array $responseEntries): array
    {
        $db = Database::getMysql();
        $created = [];

        // Index request entries by urn:uuid for response matching
        $reqByUrl = [];
        $reqByPos = [];
        foreach ($requestEntries as $i => $entry) {
            $reqByUrl[$entry['fullUrl'] ?? ''] = $i;
            $reqByPos[] = $i;
        }

        // Resolve response -> request index
        $respToReq = [];
        foreach ($responseEntries as $ri => $respEntry) {
            $fullUrl = $respEntry['fullUrl'] ?? '';
            if ($fullUrl !== '' && isset($reqByUrl[$fullUrl])) {
                $respToReq[$ri] = $reqByUrl[$fullUrl];
            } elseif (isset($reqByPos[$ri])) {
                $respToReq[$ri] = $reqByPos[$ri]; // positional fallback
            }
        }

        // Simple one-row tables (no_rawat + id_col) — fallback for resources
        // that DON'T have _panel_persist_keys (e.g. Encounter, EpisodeOfCare,
        // Composition which have simple no_rawat primary keys).
        $simpleTables = [
            'Encounter'     => ['table' => 'satu_sehat_encounter', 'id_col' => 'id_encounter'],
            'EpisodeOfCare' => ['table' => 'satu_sehat_episode_of_care', 'id_col' => 'id_episode_of_care'],
            'Composition'   => ['table' => 'satu_sehat_composition', 'id_col' => 'id_composition'],
        ];

        // Medication family tables keyed by no_resep+kode_brng
        $medTables = [
            'MedicationRequest' => [
                'base' => ['table' => 'satu_sehat_medicationrequest', 'id_col' => 'id_medicationrequest'],
                'racikan' => ['table' => 'satu_sehat_medicationrequest_racikan', 'id_col' => 'id_medicationrequest'],
            ],
            'MedicationDispense' => [
                'base' => ['table' => 'satu_sehat_medicationdispense', 'id_col' => 'id_medicationdispanse'], // CLI typo
                'racikan' => ['table' => 'satu_sehat_medicationdispense_racikan', 'id_col' => 'id_medicationdispanse'],
            ],
            'MedicationStatement' => [
                'base' => ['table' => 'satu_sehat_medicationstatement', 'id_col' => 'id_medicationstatement'],
                'racikan' => ['table' => 'satu_sehat_medicationstatement_racikan', 'id_col' => 'id_medicationstatement'],
            ],
        ];

        $medicationTable = ['table' => 'satu_sehat_medication', 'id_col' => 'id_medication'];

        foreach ($responseEntries as $ri => $respEntry) {
            if (!isset($respToReq[$ri])) {
                continue;
            }
            $reqEntry = $requestEntries[$respToReq[$ri]];

            $type = $reqEntry['request']['url'] ?? '';
            $respId = $respEntry['resource']['id'] ?? null;
            if ($type === '' || !$respId) {
                continue;
            }

            $resource = $reqEntry['resource'] ?? [];
            $meta = $reqEntry['_panel_meta'] ?? [];
            $persistKeys = $meta['persist_keys'] ?? null;

            try {
                // ── Strategy 1: Use _panel_persist_keys (composite key tables) ──
                if ($persistKeys !== null && isset($persistKeys['table'], $persistKeys['id_col'], $persistKeys['keys'])) {
                    $table = $persistKeys['table'];
                    $idCol = $persistKeys['id_col'];
                    $keys = $persistKeys['keys'];

                    $cols = array_keys($keys);
                    $cols[] = $idCol;
                    $placeholders = array_map(fn($c) => ":$c", $cols);
                    $colList = implode(', ', $cols);
                    $phList = implode(', ', $placeholders);

                    $sql = "INSERT INTO {$table} ({$colList}) VALUES ({$phList}) ON DUPLICATE KEY UPDATE {$idCol} = :id_upd";
                    $stmt = $db->prepare($sql);
                    $params = $keys;
                    $params[$idCol] = $respId;
                    $params['id_upd'] = $respId;
                    $stmt->execute($params);
                    $created[$type . ($meta['ttv_type'] ?? '')] = $respId;
                    continue;
                }

                // ── Strategy 2: Simple no_rawat tables ──
                if (isset($simpleTables[$type])) {
                    $t = $simpleTables[$type];
                    $stmt = $db->prepare(
                        "INSERT INTO {$t['table']} (no_rawat, {$t['id_col']})
                         VALUES (:nr, :id)
                         ON DUPLICATE KEY UPDATE {$t['id_col']} = :id2"
                    );
                    $stmt->execute(['nr' => $noRawat, 'id' => $respId, 'id2' => $respId]);
                    $created[$type] = $respId;
                    continue;
                }

                // ── Strategy 3: Medication (keyed by kode_brng) ──
                if ($type === 'Medication' && isset($resource['code']['coding'][0]['code'])) {
                    $t = $medicationTable;
                    $kodeBrng = $resource['code']['coding'][0]['code'];
                    $stmt = $db->prepare(
                        "INSERT INTO {$t['table']} (kode_brng, {$t['id_col']})
                         VALUES (:kb, :id)
                         ON DUPLICATE KEY UPDATE {$t['id_col']} = :id2"
                    );
                    $stmt->execute(['kb' => $kodeBrng, 'id' => $respId, 'id2' => $respId]);
                    $created[$type] = $respId;
                    continue;
                }

                // ── Strategy 4: Medication family (Request/Dispense/Statement) ──
                if (isset($medTables[$type])) {
                    $t = $medTables[$type];
                    $noResep = $resource['identifier'][0]['value'] ?? null;
                    $kodeBrng = $resource['identifier'][1]['value'] ?? null;
                    $isRacikan = !empty($meta['is_racikan']);

                    if ($isRacikan && isset($t['racikan'])) {
                        $r = $t['racikan'];
                        $noRacik = (string) ($meta['no_racik'] ?? '');
                        if ($noResep && $kodeBrng && $noRacik !== '') {
                            $stmt = $db->prepare(
                                "INSERT INTO {$r['table']} (no_resep, kode_brng, no_racik, {$r['id_col']})
                                 VALUES (:nr2, :kb, :nrc, :id)
                                 ON DUPLICATE KEY UPDATE {$r['id_col']} = :id2"
                            );
                            $stmt->execute(['nr2' => $noResep, 'kb' => $kodeBrng, 'nrc' => $noRacik, 'id' => $respId, 'id2' => $respId]);
                            $created[$type] = $respId;
                        }
                    } elseif ($noResep && $kodeBrng) {
                        $stmt = $db->prepare(
                            "INSERT INTO {$t['base']['table']} (no_resep, kode_brng, {$t['base']['id_col']})
                             VALUES (:nr2, :kb, :id)
                             ON DUPLICATE KEY UPDATE {$t['base']['id_col']} = :id2"
                        );
                        $stmt->execute(['nr2' => $noResep, 'kb' => $kodeBrng, 'id' => $respId, 'id2' => $respId]);
                        $created[$type] = $respId;
                    }
                    continue;
                }

                // ── Strategy 5: QuestionnaireResponse (keyed by no_resep) ──
                if ($type === 'QuestionnaireResponse') {
                    $noResep = $resource['identifier'][0]['value'] ?? null;
                    if ($noResep) {
                        $stmt = $db->prepare(
                            "INSERT INTO satu_sehat_questionresponse_telaah_farmasi (no_resep, id_questionresponse)
                             VALUES (:nr, :id)
                             ON DUPLICATE KEY UPDATE id_questionresponse = :id2"
                        );
                        $stmt->execute(['nr' => $noResep, 'id' => $respId, 'id2' => $respId]);
                        $created[$type] = $respId;
                    }
                    continue;
                }

            } catch (\Throwable $e) {
                // Table may not exist for this hospital — log and continue
                error_log("[PANEL] persistCreatedIds failed for {$type}: " . $e->getMessage());
            }
        }

        return $created;
    }

    /**
     * Instantiate the SatuSehatClient with panel credentials.
     *
     * Prefers the panel's own .env; falls back to
     * config/satusehat_credential.json (plug-and-play, editable via the
     * in-panel Settings page).
     */
    private static function getClient(): \SatuSehatClient
    {
        $config = \CredentialLocator::buildSatuSehatConfig();

        $log = new \Logger($config->logDir, 'panel_send');
        return new \SatuSehatClient($config, $log);
    }

    private static function genUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Rewrite 'Resource/{id}' style references inside a Bundle so that
     * references to resources also present in the same Bundle point at the
     * entry's urn:uuid instead.
     *
     * Only rewrites when the referenced resource type is IN the bundle AND
     * the id is empty or mismatched (i.e. the target is being created here).
     * References to already-persisted resources (real SATUSEHAT ids) are left
     * untouched so external refs keep working.
     *
     * @param array $entries Bundle entries (by-ref, mutated)
     * @param array $uuidMap resourceType => urn:uuid (without prefix)
     */
    private static function rewriteBundleReferences(array &$entries, array $uuidMap): void
    {
        foreach ($entries as &$entry) {
            if (isset($entry['resource'])) {
                self::rewriteRefs($entry['resource'], $uuidMap);
            }
        }
        unset($entry);
    }

    /**
     * Recursively walk a FHIR resource and rewrite 'Type/{id}' reference
     * strings when Type is in the bundle's uuidMap and the id is empty.
     */
    private static function rewriteRefs(array &$node, array $uuidMap): void
    {
        foreach ($node as $key => &$value) {
            if (is_array($value)) {
                self::rewriteRefs($value, $uuidMap);
            } elseif (is_string($value) && $key === 'reference') {
                // e.g. "Encounter/", "Patient/xyz", "Encounter/abc-123"
                if (preg_match('#^([A-Za-z]+)/(.*)$#', $value, $m)) {
                    $type = $m[1];
                    $id = $m[2];
                    // Only rewrite if the type is being created in this bundle
                    // and has no real SATUSEHAT id yet.
                    if (isset($uuidMap[$type]) && ($id === '' || $id === '-')) {
                        $value = 'urn:uuid:' . $uuidMap[$type];
                    }
                }
            }
        }
        unset($value);
    }

    private static function audit(string $noRawat, array $resources, array $bundle, bool $success, array $apiResult = []): void
    {
        $db = Database::getSqlite();

        // Store actual SATUSEHAT response for debugging (truncate to 64KB
        // to avoid SQLite bloat on huge OperationOutcome responses).
        $responseJson = json_encode($apiResult['data'] ?? []);
        if (strlen($responseJson) > 65536) {
            $responseJson = substr($responseJson, 0, 65536) . '...(truncated)';
        }

        // Extract error message from FHIR OperationOutcome if present
        $errorMsg = null;
        if (!$success) {
            $errorMsg = $apiResult['data']['issue'][0]['details']['text']
                ?? $apiResult['data']['issue'][0]['diagnostics']
                ?? $apiResult['message']
                ?? 'Unknown error';
        }

        $stmt = $db->prepare("
            INSERT INTO audit_logs
                (patient_id, resource_type, action, status, request_payload, response_payload, error_message, user_identifier)
            VALUES (?, ?, 'send', ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $noRawat,
            implode(',', $resources),
            $success ? 'success' : 'failed',
            json_encode($bundle),
            $responseJson,
            $errorMsg,
            $_SERVER['REMOTE_ADDR'] ?? 'cli',
        ]);
    }
}
