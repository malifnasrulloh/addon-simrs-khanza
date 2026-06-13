<?php

/**
 * SatuSehatImagingStudyProcessor - Orchestrator for background PACS and Satu Sehat ImagingStudy synchronization.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

require_once __DIR__ . '/RadiologyModalityMapper.php';

class SatuSehatImagingStudyProcessor
{
    private SatuSehatDatabase $db;
    private OrthancClient $orthanc;
    private SatuSehatConfig $config;
    private Logger $log;

    private int $successCount = 0;
    private int $failCount    = 0;
    private int $skipCount    = 0;

    public function __construct(SatuSehatDatabase $db, OrthancClient $orthanc, SatuSehatConfig $config, Logger $log)
    {
        $this->db      = $db;
        $this->orthanc = $orthanc;
        $this->config  = $config;
        $this->log     = $log;
    }

    public function run(?array $records = null): array
    {
        $this->successCount = 0;
        $this->failCount    = 0;
        $this->skipCount    = 0;

        if ($this->config->lookbackDays > 0) {
            $dateTo = date('Y-m-d', strtotime('-1 day'));
            $dateFrom = date('Y-m-d', strtotime('-' . $this->config->lookbackDays . ' days', strtotime(date('Y-m-d'))));
            $this->log->info("  Date Range: {$dateFrom} to {$dateTo} (Lookback: {$this->config->lookbackDays} days)");
        } else {
            $dateFrom = $this->config->dateFrom;
            $dateTo = $this->config->dateTo;
            $this->log->info("  Date Range: {$dateFrom} to {$dateTo} (Configured)");
        }

        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[SYNC] Phase 1: Processing Radiology ImagingStudies");
        $this->processImagingStudies($dateFrom, $dateTo, $records);

        return [
            'success' => $this->successCount,
            'fail'    => $this->failCount,
            'skip'    => $this->skipCount,
        ];
    }

    private function processImagingStudies(string $dateFrom, string $dateTo, ?array $records = null): void
    {
        $pending = $records !== null ? $records : $this->db->fetchPendingImagingStudies($dateFrom, $dateTo);
        $total = count($pending);
        $this->log->info("[SYNC] Found {$total} pending/failed radiology exams to process.");

        if ($total === 0) {
            return;
        }

        // Get institution name
        $instName = 'SIMRS KHANZA';
        try {
            if (isset($this->db->mysql)) {
                $inst = $this->db->mysql->query("SELECT nama_instansi FROM setting LIMIT 1")->fetchColumn();
                if ($inst) {
                    $instName = trim($inst);
                }
            }
        } catch (\Throwable $e) {
            $this->log->warning("[SYNC] Failed to fetch institution name: " . $e->getMessage());
        }

        $mapper = RadiologyModalityMapper::getInstance();

        foreach ($pending as $item) {
            $no_rawat = $item['no_rawat'];
            $kd_jenis_prw = $item['kd_jenis_prw'];
            $noorder = $item['noorder'];
            $id_servicerequest = $item['id_servicerequest'];
            $no_rm = $item['no_rm'];
            $tgl_periksa = $item['tgl_periksa'];
            $jam_periksa = $item['jam_periksa'];
            $nm_perawatan = $item['nm_perawatan'];
            $tgl_permintaan = $item['tgl_permintaan'];
            $jam_permintaan = $item['jam_permintaan'];
            $nm_dokter = $item['nm_dokter'] ?? '';
            $nm_dokter_perujuk = $item['nm_dokter_perujuk'] ?? '';
            $nm_pasien = $item['nm_pasien'];
            $tgl_lahir = $item['tgl_lahir'];
            $jk = $item['jk'];

            $acsn = SatuSehatPayloadBuilder::buildAcsn($noorder, $kd_jenis_prw);

            $this->log->info("--------------------------------------------------");
            $this->log->info("[SYNC] Processing order: {$noorder} | Procedure: {$nm_perawatan} | Patient: {$nm_pasien} ({$no_rm})");

            // 1. Initial State Save
            $this->db->saveImagingStudyInitial($noorder, $kd_jenis_prw, $acsn, $id_servicerequest);

            // 2. Query PACS (Orthanc) by deterministic AccessionNumber
            $studyId = $this->orthanc->findStudyByAccession($acsn);

            if ($studyId === null) {
                $this->log->info("[SYNC] Study not found by AccessionNumber '{$acsn}'. Starting High-Confidence Matching Engine...");

                // High-Confidence Matching Engine
                $modality = $mapper->getModality($kd_jenis_prw) ?? 'OT';
                $patientIdClean = str_replace('=', '', $no_rm);
                $dateDicom = str_replace('-', '', substr($tgl_periksa, 0, 10));

                $studies = $this->orthanc->findStudyByModality($patientIdClean, $dateDicom, $modality);
                $this->log->debug("[MATCH] Found " . count($studies) . " studies in PACS matching Patient ID '{$patientIdClean}', Date '{$dateDicom}', Modality '{$modality}'");

                $matches = [];
                foreach ($studies as $s) {
                    $sId = $s['ID'] ?? null;
                    if ($sId === null) {
                        continue;
                    }

                    // Check if study already belongs to another accession number
                    $studyAcsn = trim($s['MainDicomTags']['AccessionNumber'] ?? '');
                    if ($studyAcsn !== '' && $studyAcsn !== '-' && $studyAcsn !== $acsn) {
                        $this->log->debug("[MATCH] Skipping Study '{$sId}' because it has a non-matching AccessionNumber '{$studyAcsn}'");
                        continue;
                    }

                    $score = 0;

                    // Match 1: Time Difference (max 1 hour / 3600 seconds)
                    $studyTime = trim($s['MainDicomTags']['StudyTime'] ?? '');
                    $periksaDigits = preg_replace('/[^0-9]/', '', $jam_periksa);
                    $studyDigits = preg_replace('/[^0-9]/', '', $studyTime);

                    if (strlen($periksaDigits) >= 4 && strlen($studyDigits) >= 4) {
                        $periksaSecs = (int)substr($periksaDigits, 0, 2) * 3600 + (int)substr($periksaDigits, 2, 2) * 60;
                        if (strlen($periksaDigits) >= 6) {
                            $periksaSecs += (int)substr($periksaDigits, 4, 2);
                        }

                        $studySecs = (int)substr($studyDigits, 0, 2) * 3600 + (int)substr($studyDigits, 2, 2) * 60;
                        if (strlen($studyDigits) >= 6) {
                            $studySecs += (int)substr($studyDigits, 4, 2);
                        }

                        $timeDiff = abs($studySecs - $periksaSecs);
                        if ($timeDiff <= 3600) {
                            $score += 50;
                            $this->log->debug("[MATCH] Study '{$sId}' time '{$studyTime}' matches exam time '{$jam_periksa}' (diff: {$timeDiff}s) (+50 pts)");
                        } else {
                            $this->log->debug("[MATCH] Study '{$sId}' time '{$studyTime}' exceeds 1h difference from '{$jam_periksa}' (diff: {$timeDiff}s)");
                        }
                    }

                    // Match 2: Procedure Description Substring / Word Match
                    $studyDesc = trim($s['MainDicomTags']['StudyDescription'] ?? '');
                    if ($studyDesc !== '' && $nm_perawatan !== '') {
                        if (stripos($studyDesc, $nm_perawatan) !== false || stripos($nm_perawatan, $studyDesc) !== false) {
                            $score += 50;
                            $this->log->debug("[MATCH] Study '{$sId}' description '{$studyDesc}' matches procedure '{$nm_perawatan}' (+50 pts)");
                        }
                    }

                    if ($score >= 50) {
                        $matches[] = [
                            'id' => $sId,
                            'score' => $score
                        ];
                    }
                }

                // Sort matches by score descending
                usort($matches, function ($a, $b) {
                    return $b['score'] <=> $a['score'];
                });

                $bestMatchId = null;
                if (count($matches) === 1) {
                    $bestMatchId = $matches[0]['id'];
                } elseif (count($matches) > 1) {
                    if ($matches[0]['score'] > $matches[1]['score']) {
                        $bestMatchId = $matches[0]['id'];
                    } else {
                        $this->log->warning("[MATCH] Ambiguous top matches found with identical score. Fallback to image upload.");
                    }
                }

                if ($bestMatchId !== null) {
                    $this->log->info("[MATCH] High-confidence match found! Modifying PACS Study '{$bestMatchId}' with AccessionNumber '{$acsn}'...");
                    $modifiedStudyId = $this->orthanc->modifyStudyAccession($bestMatchId, $acsn);
                    if ($modifiedStudyId) {
                        $studyId = $modifiedStudyId;
                    }
                } else {
                    $this->log->info("[SYNC] No high-confidence match found in PACS.");
                }
            }

            // 3. Fallback: Image conversion and upload via go-dcm
            if ($studyId === null) {
                $this->log->info("[SYNC] Study not found/matched in PACS. Querying local images for conversion...");
                $images = $this->db->fetchRadiologyImages($no_rawat, $kd_jenis_prw, $noorder);
                $imageCount = count($images);
                $this->log->info("[SYNC] Found {$imageCount} images in database for this exam.");

                if ($imageCount === 0) {
                    $this->log->warning("[SYNC] No images found for order {$noorder}. Cannot convert.");
                    $this->db->updateImagingStudyLocalState($noorder, $kd_jenis_prw, 'FAILED');
                    $this->db->updateImagingStudyMySQLState($noorder, $kd_jenis_prw, 'FAILED', 'Belum ada gambar radiologi untuk dikonversi');
                    $this->failCount++;
                    continue;
                }

                // Build full URLs for all images
                $urls = [];
                foreach ($images as $img) {
                    $urls[] = $this->config->simrsWebappsUrl . '/radiologi/' . ltrim($img, '/');
                }

                // Formulate tags
                $modality = $mapper->getModality($kd_jenis_prw) ?? 'OT';
                $aeTitle = $mapper->getAeTitle($kd_jenis_prw, $modality, $this->config->dicomRouterAe);
                $scheduledDate = str_replace('-', '', substr($tgl_permintaan, 0, 10));
                $scheduledTime = str_replace(':', '', $jam_permintaan);
                if (strlen($scheduledTime) < 6) {
                    $scheduledTime = str_pad($scheduledTime, 6, '0');
                }

                $dobDicom = str_replace('-', '', substr($tgl_lahir, 0, 10));
                $sexDicom = ($jk === 'L') ? 'M' : (($jk === 'P') ? 'F' : 'O');
                $patientNameDicom = $this->sanitizeDicomPersonName($nm_pasien);

                // Deterministic OID generation for StudyInstanceUID
                $studyUid = $this->generateDicomStudyUid($no_rm, $acsn);

                $parameters = [
                    'PatientID' => $no_rm,
                    'PatientName' => $patientNameDicom,
                    'PatientBirthDate' => $dobDicom,
                    'PatientSex' => $sexDicom,
                    'StudyDate' => $scheduledDate,
                    'StudyTime' => $scheduledTime,
                    'Modality' => $modality,
                    'AccessionNumber' => $acsn
                ];

                $modify = [
                    'StudyInstanceUID' => $studyUid,
                    'AccessionNumber' => $acsn,
                    'PatientName' => $patientNameDicom,
                    'PatientID' => $no_rm,
                    'PatientBirthDate' => $dobDicom,
                    'PatientSex' => $sexDicom,
                    'RequestedProcedureDescription' => $nm_perawatan,
                    'RequestedProcedureID' => $noorder,
                    'ReferringPhysicianName' => $this->sanitizeDicomPersonName($nm_dokter_perujuk),
                    'RequestingPhysician' => $this->sanitizeDicomPersonName($nm_dokter_perujuk),
                    'StudyDate' => $scheduledDate,
                    'StudyTime' => $scheduledTime,
                    'InstitutionName' => $instName,
                    'Modality' => $modality,
                    'ScheduledStationAETitle' => $aeTitle,
                    'ScheduledProcedureStepStartDate' => $scheduledDate,
                    'ScheduledProcedureStepStartTime' => $scheduledTime,
                    'ScheduledPerformingPhysicianName' => $nm_dokter,
                    'ScheduledProcedureStepDescription' => $nm_perawatan,
                    'ScheduledProcedureStepID' => $noorder,
                    'ScheduledStationName' => $aeTitle,
                    'ScheduledProcedureStepSequence' => [
                        [
                            'ScheduledProcedureStepStartDate' => $scheduledDate,
                            'ScheduledProcedureStepStartTime' => $scheduledTime,
                            'Modality' => $modality,
                            'ScheduledPerformingPhysicianName' => $nm_dokter,
                            'ScheduledProcedureStepDescription' => $nm_perawatan,
                            'ScheduledProcedureStepID' => $noorder,
                            'ScheduledStationAETitle' => $aeTitle,
                            'ScheduledStationName' => $aeTitle
                        ]
                    ]
                ];

                $this->log->info("[SYNC] Submitting batch of {$imageCount} images to go-dcm converter...");
                $convertedStudyId = $this->orthanc->sendToDicomConverterFromUrls($urls, $parameters, $modify);

                if ($convertedStudyId) {
                    $studyId = $convertedStudyId;
                } else {
                    $this->log->error("[SYNC] DICOM conversion/upload failed.");
                    $this->db->updateImagingStudyLocalState($noorder, $kd_jenis_prw, 'FAILED');
                    $this->db->updateImagingStudyMySQLState($noorder, $kd_jenis_prw, 'FAILED', 'Konversi DICOM gagal');
                    $this->failCount++;
                    continue;
                }
            }

            // 4. Route from PACS to Kemenkes DICOM Router
            if ($studyId !== null) {
                $this->log->info("[SYNC] Routing Study '{$studyId}' to DICOM Router AE '{$this->config->dicomRouterAe}'...");
                $routed = $this->orthanc->sendToModality($studyId, $this->config->dicomRouterAe);

                if ($routed) {
                    $this->db->updateImagingStudyLocalState($noorder, $kd_jenis_prw, 'SUCCESS');
                    $this->db->updateImagingStudyMySQLState($noorder, $kd_jenis_prw, 'PENDING', 'DICOM berhasil dikirim, menunggu webhook', $studyId);
                    $this->successCount++;
                } else {
                    $this->db->updateImagingStudyLocalState($noorder, $kd_jenis_prw, 'FAILED');
                    $this->db->updateImagingStudyMySQLState($noorder, $kd_jenis_prw, 'FAILED', 'Gagal mengirim DICOM ke router');
                    $this->failCount++;
                }
            }
        }
    }

    private function sanitizeDicomPersonName(string $nm): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $nm));
    }

    private function generateDicomStudyUid(string $patientId, string $acsn): string
    {
        $source = "PATIENT:" . trim($patientId) . "|ACCESSION:" . trim($acsn);
        
        // Calculate MD5 hash
        $hash = md5($source, true); // raw 16 bytes
        
        // Set UUID version to 3 (name-based MD5)
        $hash[6] = chr((ord($hash[6]) & 0x0f) | 0x30);
        // Set variant to RFC 4122
        $hash[8] = chr((ord($hash[8]) & 0x3f) | 0x80);
        
        $hex = bin2hex($hash);
        
        // Convert 128-bit hex representation to decimal string
        $dec = $this->hexToDec($hex);
        return "2.25." . $dec;
    }

    private function hexToDec(string $hex): string
    {
        $hex = ltrim(strtolower($hex), '0');
        if ($hex === '') {
            return '0';
        }
        $dec = '0';
        for ($i = 0; $i < strlen($hex); $i++) {
            $val = hexdec($hex[$i]);
            $dec = $this->addDec($this->mulDec($dec, 16), (string)$val);
        }
        return $dec;
    }

    private function addDec(string $a, string $b): string
    {
        $res = '';
        $carry = 0;
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        while ($i >= 0 || $j >= 0 || $carry > 0) {
            $sum = $carry;
            if ($i >= 0) $sum += (int)$a[$i--];
            if ($j >= 0) $sum += (int)$b[$j--];
            $res = ($sum % 10) . $res;
            $carry = (int)($sum / 10);
        }
        return $res;
    }

    private function mulDec(string $num, int $multiplier): string
    {
        $res = '';
        $carry = 0;
        for ($i = strlen($num) - 1; $i >= 0; $i--) {
            $prod = (int)$num[$i] * $multiplier + $carry;
            $res = ($prod % 10) . $res;
            $carry = (int)($prod / 10);
        }
        if ($carry > 0) {
            $res = $carry . $res;
        }
        return $res;
    }
}
