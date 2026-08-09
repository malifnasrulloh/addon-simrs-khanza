<?php

declare(strict_types=1);

namespace SatusehatPanel\Tests;

use PHPUnit\Framework\TestCase;
use SatusehatPanel\Tests\Support\Fixtures;

/**
 * Validates the golden fixtures extracted from the official SATUSEHAT
 * Postman collections: manifest integrity + FHIR shape of each example.
 */
final class FixturesTest extends TestCase
{
    public function testManifestReferencedFixturesAllExist(): void
    {
        foreach (Fixtures::manifest() as $name => $meta) {
            $this->assertFileExists(
                PANEL_BASE . '/tests/fixtures/' . $name . '.json',
                "fixture missing for manifest entry: {$name}"
            );
            $this->assertFileExists(PANEL_BASE . '/tests/fixtures/manifest.json');
        }
    }

    public function testEveryFixtureIsParseableFhir(): void
    {
        foreach (Fixtures::names() as $name) {
            $data = Fixtures::load($name);
            $this->assertArrayHasKey('resourceType', $data, $name);
            $this->assertIsString($data['resourceType'], $name);
            $this->assertNotEmpty($data['resourceType'], $name);
        }
    }

    public function testEncounterCreateShape(): void
    {
        $e = Fixtures::load('encounter-create');
        $this->assertSame('Encounter', $e['resourceType']);
        $this->assertContains($e['status'], ['arrived', 'planned', 'in-progress', 'finished'], 'encounter-create status');
        $this->assertArrayHasKey('class', $e);
        $this->assertArrayHasKey('subject', $e);
        $this->assertArrayHasKey('participant', $e);
        $this->assertArrayHasKey('period', $e);
        $this->assertArrayHasKey('location', $e);
    }

    public function testEncounterFinishedHasStatusHistory(): void
    {
        $e = Fixtures::load('encounter-finished');
        $this->assertSame('Encounter', $e['resourceType']);
        $this->assertSame('finished', $e['status']);
        $this->assertArrayHasKey('statusHistory', $e);
        $this->assertNotEmpty($e['statusHistory']);
        $this->assertArrayHasKey('period', $e);
    }

    public function testConditionDiagnosisShape(): void
    {
        $c = Fixtures::load('condition-diagnosis');
        $this->assertSame('Condition', $c['resourceType']);
        $this->assertSame('active', $c['clinicalStatus']['coding'][0]['code'] ?? null);
        $codes = array_column($c['code']['coding'] ?? [], 'system');
        $this->assertContains('http://hl7.org/fhir/sid/icd-10', $codes);
    }

    public function testObservationTtvQuantityShape(): void
    {
        $o = Fixtures::load('observation-ttv-create');
        $this->assertSame('Observation', $o['resourceType']);
        $this->assertArrayHasKey('valueQuantity', $o);
        $vq = $o['valueQuantity'];
        $this->assertArrayHasKey('value', $vq);
        $this->assertArrayHasKey('unit', $vq);
        $this->assertArrayHasKey('system', $vq);
        $this->assertStringStartsWith('http://unitsofmeasure.org', $vq['system']);
    }

    public function testCompositionOfficialHasIdentifierFinalStatusAndSections(): void
    {
        $c = Fixtures::load('composition-edukasi-diet');
        $this->assertSame('Composition', $c['resourceType']);
        $this->assertSame('final', $c['status']);
        $this->assertArrayHasKey('identifier', $c);
        $this->assertNotEmpty($c['identifier']);
        $this->assertArrayHasKey('section', $c);
        $this->assertNotEmpty($c['section']);
        $identifiers = array_is_list($c['identifier']) ? $c['identifier'] : [$c['identifier']];
        $found = false;
        foreach ($identifiers as $id) {
            if (str_contains((string) ($id['system'] ?? ''), 'http://sys-ids.kemkes.go.id/composition/')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'composition identifier must use the sys-ids composition system');
    }

    public function testFarmasiParacetamolIdentifierSemantics(): void
    {
        $m = Fixtures::load('farmasi-peresepan-paracetamol');
        $this->assertSame('MedicationRequest', $m['resourceType']);
        $identifiers = $m['identifier'] ?? [];
        $this->assertCount(2, $identifiers);
        $prescription = null;
        $item = null;
        foreach ($identifiers as $id) {
            if (str_contains($id['system'] ?? '', 'prescription-item')) {
                $item = $id['value'];
            } elseif (str_contains($id['system'] ?? '', 'prescription/')) {
                $prescription = $id['value'];
            }
        }
        $this->assertNotNull($prescription);
        $this->assertNotNull($item);
        $this->assertStringStartsWith($prescription, $item, 'official prescription-item id extends the prescription id (-1 suffix)');
        $this->assertMatchesRegularExpression('/-\d+$/', $item ?? '');
    }

    public function testPatientCreateHasProfileAndNikIdentifier(): void
    {
        $p = Fixtures::load('patient-create');
        $this->assertSame('Patient', $p['resourceType']);
        $this->assertArrayHasKey('meta', $p);
        $this->assertArrayHasKey('profile', $p['meta']);
        $systems = [];
        foreach ($p['identifier'] ?? [] as $id) {
            $systems[] = $id['system'] ?? '';
        }
        $this->assertContains('https://fhir.kemkes.go.id/id/nik', $systems);
        $this->assertNotEmpty($p['name'] ?? [], 'patient must carry a name');
        $this->assertArrayHasKey('text', $p['name'][0], 'official example uses name.text');
        $this->assertNotEmpty($p['name'][0]['text']);
    }

    public function testNormalizeIsDeterministic(): void
    {
        $a = ['fullUrl' => 'urn:uuid:3a1b2c3d-4e5f-4a6b-8c9d-0e1f2a3b4c5d', 'period' => ['start' => '2026-08-08T10:30:00+07:00']];
        $b = ['fullUrl' => 'urn:uuid:ffffffff-1111-2222-3333-444455556666', 'period' => ['start' => '2025-01-02T03:04:05Z']];
        $this->assertSame(Fixtures::normalize($a), Fixtures::normalize($b));
    }
}