#!/usr/bin/env php
<?php
/**
 * payload_shape_test.php — standalone shape/unit tests for the SATUSEHAT
 * payload builders, canonical shapes per the official Postman collections:
 *   - Encounter.serviceType is a CodeableConcept ("coding" wrapper)
 *   - Encounter.location omitted when the location mapping is missing
 *   - Procedure.category is a single CodeableConcept object
 *   - MedicationStatement.category uses code 'community'
 *   - doseQuantity units: drug forms → v3-orderableDrugForm, UCUM canonical
 *     case ('ml' → 'mL'), unknown units stripped (never empty system/code)
 *   - bad administration routes (e.g. 'Topical' under ATC) are dropped
 *   - lab Observation: mapped unit → valueQuantity w/ system+code;
 *     unmapped unit → textual valueString instead of unitless quantity
 *   - validatePayload() pre-flight guard catches the server-rejected classes
 *
 * No database or network required. Usage: php tests/payload_shape_test.php
 */

declare(strict_types=1);

define('BASE_DIR', dirname(__DIR__));

require_once BASE_DIR . '/lib/Logger.php';
require_once BASE_DIR . '/lib/satusehat/DateTimeUtil.php';
require_once BASE_DIR . '/lib/satusehat/NumberUtil.php';
require_once BASE_DIR . '/lib/satusehat/ServiceTypeTerminology.php';
require_once BASE_DIR . '/lib/satusehat/PayloadBuilder.php';

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  PASS  {$label}\n";
    } else {
        $failed++;
        echo "  FAIL  {$label}\n";
    }
}

echo "── ServiceTypeTerminology ──────────────────────────────\n";

$st = ServiceTypeTerminology::coding('IGDK', 'Ralan', 'POLI IGD');
ok(is_array($st['coding'][0] ?? null), 'coding() returns CodeableConcept with coding array');
ok(($st['coding'][0]['code'] ?? '') === '117', 'IGDK → Emergency Medical 117');
ok(($st['coding'][0]['display'] ?? '') === 'Emergency Medical', 'IGDK display');

// Exactly 3 conditions decide the encounter (Java reference parity):
// IGDK = emergency, Ralan = outpatient, Ranap = inpatient. Poli names and
// keywords must NOT influence the outcome.
$st2 = ServiceTypeTerminology::coding('UNKNOWN-X', 'Ralan', 'POLIKLINIK JANTUNG');
ok(($st2['coding'][0]['code'] ?? '') === '124', 'Ralan (any poli) → General Practice 124');

$st3 = ServiceTypeTerminology::coding('UNKNOWN-Y', 'Ranap', '');
ok(($st3['coding'][0]['code'] ?? '') === '557', 'Ranap → Inpatients 557');

$st4 = ServiceTypeTerminology::coding('', 'Ralan', '');
ok(($st4['coding'][0]['code'] ?? '') === '124', 'Ralan (empty poli) → General Practice 124');

$st5 = ServiceTypeTerminology::resolve('BDS', 'Ralan', 'BEDAH SARAF');
ok($st5['code'] === '124', 'BEDAH SARAF with Ralan stays General Practice (3-condition rule)');

$st6 = ServiceTypeTerminology::resolve('BSY', 'Ranap', 'Bedah Syaraf');
ok($st6['code'] === '557', 'Bedah Syaraf with Ranap stays Inpatients (3-condition rule)');

$st7 = ServiceTypeTerminology::resolve('IGDK', 'Ranap', '');
ok($st7['code'] === '117', 'IGDK wins over Ranap → Emergency Medical (Java getClassCode parity)');


echo "── Encounter payload ───────────────────────────────────\n";

$p = [
    'status_lanjut' => 'Ralan', 'kd_poli' => 'JD', 'nm_poli' => 'POLIKLINIK JANTUNG',
    'tgl_registrasi' => '2024-01-01', 'jam_reg' => '08:00:00',
    'no_rawat' => '2024/01/01/000001', 'no_rkm_medis' => 'RM1',
    'nm_pasien' => 'Test Pasien', 'nama' => 'Dr Test', 'kd_dokter' => 'X',
    'nm_poli' => 'POLIKLINIK JANTUNG', 'id_lokasi_satusehat' => '',
];
$enc = SatuSehatPayloadBuilder::encounter('org-1', $p, 'P1', 'PR1', 'arrived');
ok(is_array($enc['serviceType']['coding'][0] ?? null), 'serviceType has coding wrapper');
ok(!isset($enc['location']), 'location omitted when id_lokasi_satusehat empty');

$p2 = $p;
$p2['id_lokasi_satusehat'] = 'abc-123';
$enc2 = SatuSehatPayloadBuilder::encounter('org-1', $p2, 'P1', 'PR1', 'arrived');
ok(($enc2['location'][0]['location']['reference'] ?? '') === 'Location/abc-123', 'location included with mapping');

echo "── Procedure payload ───────────────────────────────────\n";

$procP = [
    'waktu_registrasi' => '2024-01-01T08:00:00+07:00', 'waktu_pulang' => '2024-01-01T09:00:00+07:00',
    'kode' => '87.44', 'deskripsi_panjang' => 'Routine chest x-ray',
    'nm_pasien' => 'Test Pasien', 'id_encounter' => 'E1', 'no_rawat' => '2024/01/01/000001',
];
$proc = SatuSehatPayloadBuilder::procedure($procP, 'P1', '', 'PR1', 'Dr Test');
ok(isset($proc['category']['coding'][0]) && !isset($proc['category'][0]), 'Procedure.category is an object (not array)');
ok(($proc['category']['coding'][0]['code'] ?? '') === '103693007', 'Procedure.category SNOMED 103693007');

echo "── MedicationStatement ─────────────────────────────────\n";

$medP = [
    'status_lanjut' => 'Ranap', 'no_resep' => 'R1', 'kode_brng' => 'B1', 'is_racikan' => 0,
    'id_medication' => 'M1', 'obat_display' => 'Paracetamol', 'nm_pasien' => 'Test Pasien',
    'aturan_pakai' => '1x1', 'tgl_penyerahan' => '2024-01-01', 'jam_penyerahan' => '10:00:00',
    'denominator_code' => 'ml', 'denominator_system' => 'http://unitsofmeasure.org',
    'route_system' => 'http://terminology.hl7.org/CodeSystem/route-codes', 'route_code' => 'PO',
    'id_encounter' => 'E1',
];
$stmt = SatuSehatPayloadBuilder::medicationStatement('org-1', $medP, 'P1');
ok(($stmt['category']['coding'][0]['code'] ?? '') === 'community', 'MedicationStatement.category = community');
$dq = $stmt['dosage'][0]['doseAndRate'][0]['doseQuantity'] ?? [];
ok(($dq['code'] ?? '') === 'mL', "doseQuantity 'ml' → UCUM 'mL' (got: " . ($dq['code'] ?? 'null') . ')');
ok(($dq['system'] ?? '') === 'http://unitsofmeasure.org', 'doseQuantity system = unitsofmeasure');

$medPForm = $medP;
$medPForm['denominator_code'] = 'TAB';
$medPForm['denominator_system'] = 'http://unitsofmeasure.org';
$stmtF = SatuSehatPayloadBuilder::medicationStatement('org-1', $medPForm, 'P1');
$dqF = $stmtF['dosage'][0]['doseAndRate'][0]['doseQuantity'] ?? [];
ok(($dqF['system'] ?? '') === 'http://terminology.hl7.org/CodeSystem/v3-orderableDrugForm', "form TAB → v3-orderableDrugForm (got: " . ($dqF['system'] ?? 'null') . ')');
ok(($dqF['code'] ?? '') === 'TAB', 'form TAB → code TAB');

$medPRoute = $medP;
$medPRoute['route_system'] = 'http://www.whocc.no/atc';
$medPRoute['route_code'] = 'Topical';
$stmtR = SatuSehatPayloadBuilder::medicationStatement('org-1', $medPRoute, 'P1');
ok(!isset($stmtR['dosage'][0]['route']), "invalid route (Topical/ATC) omitted (got: " . json_encode($stmtR['dosage'][0]['route'] ?? null) . ')');

echo "── MedicationRequest ───────────────────────────────────\n";

$reqP = [
    'status_lanjut' => 'Ralan', 'no_resep' => 'R2', 'kode_brng' => 'B2', 'is_racikan' => 0,
    'id_medication' => 'M2', 'obat_display' => 'Amox', 'nm_pasien' => 'Test Pasien',
    'aturan_pakai' => '2x1', 'tgl_peresepan' => '2024-01-01', 'jam_peresepan' => '09:00:00',
    'denominator_code' => 'ml', 'denominator_system' => 'http://unitsofmeasure.org',
    'route_system' => 'http://terminology.hl7.org/CodeSystem/route-codes', 'route_code' => 'PO',
    'jml' => '10', 'id_encounter' => 'E1', 'nama' => 'Dr Test',
    'kd_dokter' => 'X', 'id_lokasi_satusehat' => '',
];
$req = SatuSehatPayloadBuilder::medicationRequest('org-1', $reqP, 'P1', 'PR1');
$rdq = $req['dosageInstruction'][0]['doseAndRate'][0]['doseQuantity'] ?? [];
ok(($rdq['code'] ?? '') === 'mL', "request doseQuantity 'ml' → 'mL' (got: " . ($rdq['code'] ?? 'null') . ')');
$rq = $req['dispenseRequest']['quantity'] ?? [];
ok(($rq['code'] ?? '') === 'mL', "dispenseRequest.quantity 'ml' → 'mL'");
ok(!isset($req['location']) || empty($req['location']), 'request location omitted when mapping empty');

echo "── Lab Observation valueQuantity ───────────────────────\n";

$labP = [
    'noorder' => 'L1', 'id_template' => '1', 'nilai' => '133', 'satuan' => 'mg/dL',
    'Pemeriksaan' => 'Glukosa', 'nm_pasien' => 'Test', 'no_rawat' => '2024/01/01/000001',
    'no_rkm_medis' => 'RM1', 'id_encounter' => 'E1', 'id_specimen' => 'S1',
    'tgl_hasil' => '2024-01-01', 'jam_hasil' => '10:00:00', 'system' => 'http://loinc.org',
    'code' => '20570-8 ', 'display' => 'Glukosa', 'nilai_rujukan' => '',
];
$lab = SatuSehatPayloadBuilder::observationLab($labP, 'P1', 'PR1', 'org-1');
ok(($lab['valueQuantity']['code'] ?? '') === 'mg/dL', 'lab mapped unit mg/dL → UCUM code');
ok(($lab['code']['coding'][0]['code'] ?? '') === '20570-8', "lab LOINC code trimmed ('20570-8 ' → '20570-8')");

$labP['satuan'] = 'pes'; // unmappable unit
$lab2 = SatuSehatPayloadBuilder::observationLab($labP, 'P1', 'PR1', 'org-1');
ok(!isset($lab2['valueQuantity']), 'unmapped unit → no valueQuantity');
ok(isset($lab2['valueString']) && str_contains($lab2['valueString'], '133'), 'unmapped unit → valueString fallback');

echo "── Pre-flight validator ────────────────────────────────\n";

$good = ['resourceType' => 'X', 'code' => ['coding' => [['system' => 'http://loinc.org', 'code' => '20570-8', 'display' => 'T']]]];
ok(SatuSehatPayloadBuilder::validatePayload($good) === [], 'clean payload → 0 issues');

$bad = ['location' => [['location' => ['reference' => 'Location/']]], 'code' => ['coding' => [['system' => '', 'code' => '']]]];
$issues = SatuSehatPayloadBuilder::validatePayload($bad);
ok(count($issues) >= 3, 'bad payload → ≥3 issues (empty reference, system, code)');
ok($issues !== [] && str_contains($issues[0], 'Location/'), 'issue mentions the empty Location/ reference');

echo "── ICD-10 map ──────────────────────────────────────────\n";

$ciP = [
    'keluhan_pemeriksaan' => 'LU', 'nm_pasien' => 'T', 'id_encounter' => 'E1',
    'tgl_registrasi' => '2024-01-01', 'no_rawat' => '2024/01/01/000001',
    'kd_penyakit' => 'I96', 'nm_penyakit' => 'Gangrene', 'tgl_perawatan' => '2024-01-01',
    'jam_rawat' => '10:00:00', 'id_condition' => 'C1', 'penilaian' => 'ok',
];
putenv('SATUSEHAT_ICD10_OVERRIDES={"ZZ99":"Z99.9"}');
$ciP['kd_penyakit'] = 'ZZ99';
$ci0 = SatuSehatPayloadBuilder::clinicalImpression($ciP, 'P1', 'PR1');
ok(($ci0['finding'][0]['itemCodeableConcept']['coding'][0]['code'] ?? '') === 'Z99.9', 'env override applied');
putenv('SATUSEHAT_ICD10_OVERRIDES');

$ciP['kd_penyakit'] = 'I96';
$ci = SatuSehatPayloadBuilder::clinicalImpression($ciP, 'P1', 'PR1');
ok(($ci['finding'][0]['itemCodeableConcept']['coding'][0]['code'] ?? '') === 'I95.9', "I96 → mapped to I95.9 (legacy map)");

echo "────────────────────────────────────────────────────────\n";
echo "{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);