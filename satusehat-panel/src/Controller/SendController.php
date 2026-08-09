<?php

namespace SatusehatPanel\Controller;

use SatusehatPanel\Core\Database;
use SatusehatPanel\Core\Config;
use SatusehatPanel\Util\PayloadAdapter;
use SatusehatPanel\Util\ReferenceRegistry;
use SatusehatPanel\Util\EntryOutcomeClassifier;
use SatusehatPanel\Util\IdempotencyStore;

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
        // Per-instance reference registry: resolves "Type/" empty references
        // to the correct bundle entry via source-row keys — never first-wins.
        $registry = new ReferenceRegistry();

        $customPayloads = $input['custom_payloads'] ?? [];

        foreach ($resources as $resource) {
            $payloadList = [];

            if (isset($customPayloads[$resource]) && is_array($customPayloads[$resource])) {
                $rawCustom = $customPayloads[$resource];
                $payloadList = isset($rawCustom['resourceType']) ? [$rawCustom] : $rawCustom;
            } else {
                try {
                    // In-bundle references for composition sections (earlier
                    // resources only — encounter/condition/obs etc.).
                    $payloadList = PayloadAdapter::build($resource, $noRawat, $patient, $registry->uuidsByType());
                    foreach (PayloadAdapterWarnings::take() as $w) {
                        $buildErrors[] = $w;
                    }
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

                // Register the entry for per-instance reference resolution
                $registry->register(
                    $realResourceType,
                    $uuid,
                    isset($entryMeta['persist_keys']['keys']) && is_array($entryMeta['persist_keys']['keys'])
                        ? $entryMeta['persist_keys']['keys']
                        : []
                );

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
        $refWarnings = self::rewriteBundleReferences($bundle['entry'], $registry);
        $buildErrors = array_merge($buildErrors, $refWarnings);

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

        // ── Idempotency gate: claim every entry BEFORE POST ─────────────
        // Timeouts/5xx leave outcomes uncertain (the server may have
        // committed) — a subsequent send is refused until human review,
        // instead of silently duplicating (rule 20002). Fully-sent key sets
        // are replayed from the store without a new POST.
        IdempotencyStore::sweep();
        $entryKeyHashes = [];
        $idemKeys = [];
        foreach ($bundle['entry'] as $i => $ent) {
            $meta = $ent['_panel_meta'] ?? [];
            $pk = $meta['persist_keys']['keys'] ?? [];
            $keys = is_array($pk) ? $pk : [];
            if (empty($keys)) {
                continue; // hand-edited payloads without keys get no key
            }
            $key = IdempotencyStore::canonicalKey($noRawat, (string) ($ent['request']['url'] ?? ''), $keys);
            $entryKeyHashes[$i] = $key;
            $idemKeys[$key] = (string) ($ent['request']['url'] ?? '');
        }

        $conflicts = IdempotencyStore::attemptsConflicted($idemKeys);
        if (!empty($conflicts)) {
            $types = implode(', ', array_unique(array_map(static fn($c) => $c['type'], $conflicts)));
            return [
                'success' => false,
                'error' => "Kiriman sebelumnya untuk: {$types} — hasilnya belum tentu selesai (timeout/error jaringan). Periksa Riwayat Kirim, verifikasi manual, lalu coba lagi.",
                'needs_manual_verify' => true,
                'build_errors' => $buildErrors,
                'ref_warnings' => $refWarnings,
                'sent_count' => 0,
            ];
        }
        foreach ($entryKeyHashes as $i => $key) {
            IdempotencyStore::claim($key, $noRawat, (string) ($bundle['entry'][$i]['request']['url'] ?? ''));
        }

        // ── Send the transaction Bundle to SATUSEHAT ──────────────────
        $client = self::getClient();
        $replayed = false;
        if (!empty($idemKeys) && self::allKeysSettledSent($idemKeys)) {
            // Full replay: every entry was already committed in a previous
            // attempt — rebuild the response from stored ids, no new POST.
            $responseEntries = [];
            foreach ($bundle['entry'] as $i => $ent) {
                $stored = isset($entryKeyHashes[$i]) ? IdempotencyStore::lookup($entryKeyHashes[$i]) : null;
                $responseEntries[] = [
                    'fullUrl' => $ent['fullUrl'] ?? '',
                    'resource' => ['id' => $stored['resource_id'] ?? null],
                ];
            }
            $result = [
                'success' => true,
                'code' => 200,
                'message' => 'Replayed from idempotency store (no new POST)',
                'data' => ['entry' => $responseEntries],
                'response' => '{}',
            ];
            $replayed = true;
        } else {
            $result = $client->post('/', $bundle);
        }

        // ── Per-entry outcome state machine ───────────────────────────
        // A transaction returns HTTP 200 even when individual entries fail.
        // Classify every response entry; network-level failures mark all
        // entries unknown. Bundle success now means: HTTP 2xx AND every
        // entry actually sent.
        if (!empty($result['success'])) {
            $outcomes = self::constructEntryOutcomes($bundle['entry'], $result['data']['entry'] ?? []);
        } else {
            $outcomes = [];
            foreach ($bundle['entry'] as $ent) {
                $meta = $ent['_panel_meta'] ?? [];
                $keys = $meta['persist_keys']['keys'] ?? [];
                $outcomes[] = [
                    'status'        => EntryOutcomeClassifier::NETWORK_UNKNOWN,
                    'rule_number'   => null,
                    'issue_text'    => (string) ($result['message'] ?? 'API Error'),
                    'satusehat_id'  => null,
                    'key_hash'      => is_array($keys) ? ReferenceRegistry::hashKeys(array_map('strval', $keys)) : null,
                ];
            }
        }
        $summary = EntryOutcomeClassifier::summarize($outcomes);

        // ── Settle idempotency keys from per-entry outcomes ───────────
        foreach ($entryKeyHashes as $i => $key) {
            $o = $outcomes[$i] ?? null;
            if ($o === null) {
                continue;
            }
            if ($o['status'] === EntryOutcomeClassifier::SENT) {
                IdempotencyStore::settle($key, IdempotencyStore::STATUS_SENT, $o['satusehat_id']);
            } elseif ($o['status'] === EntryOutcomeClassifier::NETWORK_UNKNOWN
                || ($result['code'] ?? 0) >= 500) {
                // Uncertain: the server may have committed this entry.
                IdempotencyStore::settle($key, IdempotencyStore::STATUS_UNKNOWN);
            } else {
                // Definitive rejection — safe to retry later.
                IdempotencyStore::settle($key, IdempotencyStore::STATUS_FAILED);
            }
        }

        // ── Persist created IHS ids back to mapping tables ────────────
        // A successful transaction Bundle returns entry[].resource.id for
        // each created resource. Save them so the UI marks them "sent" and
        // future bundles reference the real ids instead of re-creating.
        // Failed / unknown entries carry no resource.id and are skipped —
        // they remain sendable.
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
        $auditId = self::audit($noRawat, $resources, $bundle, $summary['status'], $result);
        self::persistEntryOutcomes($auditId, $noRawat, $bundle['entry'], $outcomes);

        $realSuccess = ($result['success'] ?? false) && $summary['all_sent'];

        return [
            'success' => $realSuccess,
            'partial_failure' => $partialFailure || !$summary['all_sent'],
            'build_errors' => $buildErrors,
            'ref_warnings' => $refWarnings,
            'sent_count' => count(array_filter($outcomes, fn($o) => $o['status'] === EntryOutcomeClassifier::SENT)),
            'failed_count' => count(array_filter($outcomes, fn($o) => $o['status'] !== EntryOutcomeClassifier::SENT)),
            'entry_status' => $summary['status'],
            'entries' => $outcomes,
            'created' => $created,
            'bundle' => $bundle,
            'response' => $result['data'] ?? [],
            'message' => $realSuccess
                ? 'Bundle sent successfully'
                : ($summary['status'] === 'partial' ? 'Sebagian resource gagal dikirim — cek detail entri' : 'Bundle send failed'),
        ];
    }

    /**
     * True when every provided key exists in the store with status 'sent'
     * (the whole bundle was previously committed — safe to replay).
     *
     * @param array<string,string> $keys key_hash => resource_type
     */
    private static function allKeysSettledSent(array $keys): bool
    {
        foreach ($keys as $key => $type) {
            $row = IdempotencyStore::lookup($key);
            if ($row === null || $row['status'] !== IdempotencyStore::STATUS_SENT) {
                return false;
            }
        }
        return true;
    }

    /**
     * Match response entries back to request entries and classify each one.
     *
     * @return array<int,array{status:string,rule_number:?int,issue_text:?string,satusehat_id:?string,key_hash:?string}>
     */
    private static function constructEntryOutcomes(array $requestEntries, array $responseEntries): array
    {
        $reqByUrl = [];
        $reqByPos = [];
        foreach ($requestEntries as $i => $entry) {
            if (isset($entry['fullUrl'])) {
                $reqByUrl[$entry['fullUrl']] = $i;
            }
            $reqByPos[] = $i;
        }

        $outcomes = [];
        foreach ($responseEntries as $ri => $respEntry) {
            $reqIndex = null;
            $fullUrl = $respEntry['fullUrl'] ?? '';
            if ($fullUrl !== '' && isset($reqByUrl[$fullUrl])) {
                $reqIndex = $reqByUrl[$fullUrl];
            } elseif (isset($reqByPos[$ri])) {
                $reqIndex = $reqByPos[$ri];
            }

            $meta = [];
            $keys = [];
            if ($reqIndex !== null) {
                $meta = $requestEntries[$reqIndex]['_panel_meta'] ?? [];
                $pk = $meta['persist_keys']['keys'] ?? [];
                $keys = is_array($pk) ? $pk : [];
            }

            $classified = EntryOutcomeClassifier::classify($respEntry);

            $outcomes[] = [
                'status'       => $classified['status'],
                'rule_number'  => $classified['rule_number'],
                'issue_text'   => $classified['issue_text'],
                'satusehat_id' => $classified['satusehat_id'],
                'key_hash'     => empty($keys) ? null : ReferenceRegistry::hashKeys(array_map('strval', $keys)),
            ];
        }
        return $outcomes;
    }

    /**
     * Persist per-entry outcomes into SQLite (send_entries) so the manifest
     * can compute truthful per-instance "sent" state.
     */
    private static function persistEntryOutcomes(?int $auditId, string $noRawat, array $requestEntries, array $outcomes): void
    {
        try {
            $db = Database::getSqlite();
            $stmt = $db->prepare("
                INSERT INTO send_entries
                    (audit_id, patient_id, resource_type, key_hash, status, rule_number, issue_text, satusehat_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($outcomes as $i => $o) {
                $type = '';
                if (isset($requestEntries[$i]['request']['url'])) {
                    $type = (string) $requestEntries[$i]['request']['url'];
                }
                $stmt->execute([
                    $auditId,
                    $noRawat,
                    $type,
                    $o['key_hash'] ?? null,
                    $o['status'],
                    $o['rule_number'] ?? null,
                    $o['issue_text'] ?? null,
                    $o['satusehat_id'] ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            error_log('[PANEL] persistSendOutcomes failed: ' . $e->getMessage());
        }
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

                // ── Strategy 4: Medication family (legacy fallback) ──
                // Adapter-built payloads always carry _panel_persist_keys and
                // are handled by Strategy 1. This path only sees hand-edited
                // payloads WITHOUT keys; deriving keys from identifier values
                // previously corrupted no_resep (A2) — persist nothing rather
                // than write garbage (the entry will be re-sendable).
                if (isset($medTables[$type])) {
                    if (empty($meta['persist_keys'])) {
                        error_log("[PANEL] persist skipped for {$type}: payload tanpa _panel_persist_keys");
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
     * Resolution is per-instance: the referencing entry's own persist keys
     * (e.g. {noorder, id_template, kd_jenis_prw}) select the matching target
     * entry when several entries of a type exist. References to
     * already-persisted resources (real SATUSEHAT ids) are left untouched so
     * external refs keep working. Unresolvable empty references are returned
     * as warnings — they are never shipped silently.
     *
     * @param array $entries  Bundle entries (by-ref, mutated)
     * @return array<int,string> pre-send warnings for unresolved references
     */
    private static function rewriteBundleReferences(array &$entries, ReferenceRegistry $registry): array
    {
        $warnings = [];
        foreach ($entries as &$entry) {
            $meta = $entry['_panel_meta'] ?? [];
            $contextKeys = $meta['persist_keys']['keys'] ?? [];
            if (!is_array($contextKeys)) {
                $contextKeys = [];
            }
            if (isset($entry['resource'])) {
                self::rewriteRefs($entry['resource'], $registry, $contextKeys, $warnings);
            }
        }
        unset($entry);
        return $warnings;
    }

    /**
     * Recursively walk a FHIR resource and rewrite 'Type/{id}' reference
     * strings when Type is in the bundle's registry and the id is empty.
     * Writes an entry warning for references that cannot be resolved.
     */
    private static function rewriteRefs(array &$node, ReferenceRegistry $registry, array $contextKeys, array &$warnings): void
    {
        foreach ($node as $key => &$value) {
            if (is_array($value)) {
                self::rewriteRefs($value, $registry, $contextKeys, $warnings);
            } elseif (is_string($value) && $key === 'reference') {
                // e.g. "Encounter/", "Patient/xyz", "Encounter/abc-123"
                if (preg_match('#^([A-Za-z]+)/(.*)$#', $value, $m)) {
                    $type = $m[1];
                    $id = $m[2];
                    // Only rewrite if the type is being created in this bundle
                    // and has no real SATUSEHAT id yet.
                    if (($id === '' || $id === '-') && $registry->count($type) > 0) {
                        $uuid = $registry->resolve($type, $contextKeys);
                        if ($uuid !== null) {
                            $value = 'urn:uuid:' . $uuid;
                        } else {
                            $ctx = $contextKeys;
                            $ctxLabel = $ctx === [] ? '' : ' (keys: ' . implode(', ', array_map(
                                static fn($k, $v) => $k . '=' . $v,
                                array_keys($ctx),
                                $ctx
                            )) . ')';
                            $warnings[] = "{$type}/ tidak dapat dicocokkan ke entri bundle{$ctxLabel}";
                        }
                    }
                }
            }
        }
        unset($value);
    }

    /**
     * Record an audit row and return its id (for send_entries linkage).
     * Status is the truthful per-entry summary: 'success' | 'failed' |
     * 'partial' (never derived from HTTP code alone).
     */
    private static function audit(string $noRawat, array $resources, array $bundle, string $status, array $apiResult = []): ?int
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
        if (!in_array($status, ['success', 'partial'], true)) {
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
            $status,
            \Logger::scrubSensitiveData(json_encode($bundle)),
            $responseJson,
            $errorMsg,
            $_SERVER['REMOTE_ADDR'] ?? 'cli',
        ]);

        return (int) $db->lastInsertId();
    }
}
