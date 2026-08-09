<?php

declare(strict_types=1);

/**
 * extract-fixtures.php — pull golden FHIR examples out of the official
 * SATUSEHAT Postman collections (source of truth: satu-sehat-postman KB) into
 * tests/fixtures/ so payload tests can assert parity with the official
 * examples.
 *
 * Usage:
 *   php scripts/extract-fixtures.php
 *   php scripts/extract-fixtures.php --source=/path/to/satu-sehat-postman
 *
 * Each pick walks the collection by item NAME (first match at each level),
 * extracts request.body.raw (JSON), validates it, and writes:
 *   tests/fixtures/<name>.json        the raw official example
 *   tests/fixtures/manifest.json      provenance (source collection + path)
 */

$root = dirname(__DIR__);
$sourceDir = '/home/malifnasrulloh/Downloads/satu-sehat-postman';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--source=')) {
        $sourceDir = rtrim(substr($arg, strlen('--source=')), '/');
    }
}
if (!is_dir($sourceDir)) {
    fwrite(STDERR, "Postman KB directory not found: {$sourceDir}\n");
    exit(2);
}

/**
 * Pick list: [collectionFile, [section names...], fixtureName]
 */
$picks = [
    // collection 00 — one canonical example per core resource
    ['00. FHIR Resource - Contoh Penggunaan.json', ['Resource', 'Patient', 'POST Create Patient', 'Patient - Create by NIK'], 'patient-create'],
    ['00. FHIR Resource - Contoh Penggunaan.json', ['Resource', 'Encounter', 'Encounter - Create'], 'encounter-create'],
    ['00. FHIR Resource - Contoh Penggunaan.json', ['Resource', 'Encounter', 'Encounter - Update Finished'], 'encounter-finished'],
    ['00. FHIR Resource - Contoh Penggunaan.json', ['Resource', 'Condition', 'Condition - Diagnosis'], 'condition-diagnosis'],
    ['00. FHIR Resource - Contoh Penggunaan.json', ['Resource', 'Observation - TTV', 'Observation - Create'], 'observation-ttv-create'],
    ['00. FHIR Resource - Contoh Penggunaan.json', ['Resource', 'Composition', 'Composition - Edukasi Diet'], 'composition-edukasi-diet'],
    ['00. FHIR Resource - Contoh Penggunaan.json', ['Resource', 'Medication', 'Medication - Create'], 'medication-create'],
    ['00. FHIR Resource - Contoh Penggunaan.json', ['Resource', 'MedicationRequest', 'MedicationRequest - Create'], 'medicationrequest-create'],
    ['00. FHIR Resource - Contoh Penggunaan.json', ['Resource', 'MedicationDispense', 'MedicationDispense - Create'], 'medicationdispense-create'],
    ['00. FHIR Resource - Contoh Penggunaan.json', ['Resource', 'AllergyIntolerance', 'AllergyIntolerance - Create'], 'allergyintolerance-create'],
    ['00. FHIR Resource - Contoh Penggunaan.json', ['Resource', 'EpisodeOfCare', 'EpisodeOfCare - Create'], 'episodeofcare-create'],
    // use-case 01 — rawat jalan flow
    ['01. Pelayanan - Rawat Jalan.json', ['02. Pendaftaran Kunjungan Rawat Jalan', 'Pembuatan Kunjungan Baru', 'Encounter - Kunjungan Baru'], 'rajal-encounter-create'],
    ['01. Pelayanan - Rawat Jalan.json', ['03. Anamnesis', 'Keluhan Utama', 'Condition  - Keluhan Utama'], 'rajal-keluhan-utama'],
    ['01. Pelayanan - Rawat Jalan.json', ['12. Diagnosis', 'Condition - Primary Dengue'], 'rajal-condition-dengue'],
    // use case 04 — pharmacy flow (official Paracetamol example)
    ['04. Pelayanan - Farmasi.json', ['02. Peresepan Obat oleh Fasyankes', '03. Peresepan Obat', 'MedicationRequest - Resep Obat Non Racik Generik - Paracetamol'], 'farmasi-peresepan-paracetamol'],
];

/**
 * Walk item tree by name; return the leaf item.
 */
function findItem(array $items, array $path): ?array
{
    $name = array_shift($path);
    foreach ($items as $item) {
        if (($item['name'] ?? '') === $name) {
            if (empty($path)) {
                return $item;
            }
            return findItem($item['item'] ?? [], $path);
        }
    }
    return null;
}

$manifest = [];
$extracted = 0;
$errors = [];

$fixtureDir = $root . '/tests/fixtures';
if (!is_dir($fixtureDir)) {
    mkdir($fixtureDir, 0755, true);
}

foreach ($picks as [$file, $path, $fixtureName]) {
    $collectionPath = $sourceDir . '/' . $file;
    if (!is_file($collectionPath)) {
        $errors[] = "collection missing: {$file}";
        continue;
    }
    $collection = json_decode((string) file_get_contents($collectionPath), true);
    if (!is_array($collection)) {
        $errors[] = "collection unparseable: {$file}";
        continue;
    }

    $item = findItem($collection['item'] ?? [], $path);
    if ($item === null) {
        $errors[] = "item not found: " . implode(' → ', $path);
        continue;
    }

    $raw = (string) ($item['request']['body']['raw'] ?? '');
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['resourceType'])) {
        $errors[] = "no FHIR body for: " . implode(' → ', $path);
        continue;
    }

    $target = $root . '/tests/fixtures/' . $fixtureName . '.json';
    file_put_contents($target, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    $manifest[$fixtureName] = [
        'source'   => $file,
        'path'     => $path,
        'resourceType' => $decoded['resourceType'],
        'sha256'   => hash_file('sha256', $target),
    ];
    $extracted++;
}

if (!is_dir($root . '/tests/fixtures')) {
    mkdir($root . '/tests/fixtures', 0755, true);
}
file_put_contents(
    $root . '/tests/fixtures/manifest.json',
    json_encode(['extracted_at' => date('c'), 'fixtures' => $manifest], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
);

fwrite(STDOUT, "Extracted {$extracted} fixtures from {$sourceDir}\n");
foreach ($errors as $e) {
    fwrite(STDERR, "  WARN: {$e}\n");
}
exit(empty($errors) ? 0 : 1);