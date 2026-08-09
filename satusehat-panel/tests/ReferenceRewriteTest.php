<?php

declare(strict_types=1);

namespace SatusehatPanel\Tests;

use PHPUnit\Framework\TestCase;
use SatusehatPanel\Util\ReferenceRegistry;

/**
 * Per-instance reference resolution: with 3 lab orders or 2 medications in
 * one bundle, every "Type/" reference must resolve to the entry matching the
 * referencing entry's business keys — never first-wins-per-type.
 */
final class ReferenceRewriteTest extends TestCase
{
    private static function registry(array $entries): ReferenceRegistry
    {
        $r = new ReferenceRegistry();
        foreach ($entries as $e) {
            $r->register($e['type'], $e['uuid'], $e['keys'] ?? []);
        }
        return $r;
    }

    public function testSingletonTypesResolveWithoutKeys(): void
    {
        $r = self::registry([
            ['type' => 'Encounter', 'uuid' => 'uuid-enc'],
            ['type' => 'Composition', 'uuid' => 'uuid-comp'],
        ]);
        $this->assertSame('uuid-enc', $r->resolve('Encounter', []));
        $this->assertSame('uuid-comp', $r->resolve('Composition', []));
    }

    public function testTwoMedicationsResolveByKodeBrng(): void
    {
        $r = self::registry([
            ['type' => 'Medication', 'uuid' => 'uuid-paracetamol', 'keys' => ['kode_brng' => 'P-001']],
            ['type' => 'Medication', 'uuid' => 'uuid-captopril', 'keys' => ['kode_brng' => 'C-002']],
        ]);
        $this->assertSame('uuid-paracetamol', $r->resolve('Medication', ['kode_brng' => 'P-001']));
        $this->assertSame('uuid-captopril', $r->resolve('Medication', ['kode_brng' => 'C-002']));
    }

    public function testTwoMedicationsResolveFromMedicationRequestContext(): void
    {
        // MedicationRequest carries {no_resep, kode_brng}; the medication
        // reference must resolve by the kode_brng part of the context.
        $r = self::registry([
            ['type' => 'Medication', 'uuid' => 'uuid-med-1', 'keys' => ['kode_brng' => 'P-001']],
            ['type' => 'Medication', 'uuid' => 'uuid-med-2', 'keys' => ['kode_brng' => 'C-002']],
        ]);
        $this->assertSame('uuid-med-2', $r->resolve('Medication', ['no_resep' => 'R-1', 'kode_brng' => 'C-002']));
    }

    public function testLabPipelineResolvesItsOwnInstances(): void
    {
        $r = self::registry([
            ['type' => 'ServiceRequest', 'uuid' => 'uuid-sr-1', 'keys' => ['noorder' => 'ORD-1', 'id_template' => 'T-10', 'kd_jenis_prw' => 'PK']],
            ['type' => 'ServiceRequest', 'uuid' => 'uuid-sr-2', 'keys' => ['noorder' => 'ORD-2', 'id_template' => 'T-20', 'kd_jenis_prw' => 'PK']],
            ['type' => 'Specimen', 'uuid' => 'uuid-sp-1', 'keys' => ['noorder' => 'ORD-1', 'id_template' => 'T-10', 'kd_jenis_prw' => 'PK']],
            ['type' => 'Specimen', 'uuid' => 'uuid-sp-2', 'keys' => ['noorder' => 'ORD-2', 'id_template' => 'T-20', 'kd_jenis_prw' => 'PK']],
            ['type' => 'Observation', 'uuid' => 'uuid-obs-1', 'keys' => ['noorder' => 'ORD-1', 'id_template' => 'T-10', 'kd_jenis_prw' => 'PK']],
            ['type' => 'Observation', 'uuid' => 'uuid-obs-2', 'keys' => ['noorder' => 'ORD-2', 'id_template' => 'T-20', 'kd_jenis_prw' => 'PK']],
            ['type' => 'DiagnosticReport', 'uuid' => 'uuid-dr-1', 'keys' => ['noorder' => 'ORD-1', 'id_template' => 'T-10', 'kd_jenis_prw' => 'PK']],
            ['type' => 'DiagnosticReport', 'uuid' => 'uuid-dr-2', 'keys' => ['noorder' => 'ORD-2', 'id_template' => 'T-20', 'kd_jenis_prw' => 'PK']],
        ]);

        $ctx1 = ['noorder' => 'ORD-1', 'id_template' => 'T-10', 'kd_jenis_prw' => 'PK'];
        $ctx2 = ['noorder' => 'ORD-2', 'id_template' => 'T-20', 'kd_jenis_prw' => 'PK'];

        $this->assertSame('uuid-obs-1', $r->resolve('Observation', $ctx1));
        $this->assertSame('uuid-obs-2', $r->resolve('Observation', $ctx2));
        $this->assertSame('uuid-sp-1', $r->resolve('Specimen', $ctx1));
        $this->assertSame('uuid-sr-2', $r->resolve('ServiceRequest', $ctx2));
        $this->assertSame('uuid-dr-1', $r->resolve('DiagnosticReport', $ctx1));
    }

    public function testAmbiguousResolutionIsNullAndNoted(): void
    {
        $r = self::registry([
            ['type' => 'Observation', 'uuid' => 'uuid-obs-1', 'keys' => ['noorder' => 'ORD-1', 'id_template' => 'T-10', 'kd_jenis_prw' => 'PK']],
            ['type' => 'Observation', 'uuid' => 'uuid-obs-2', 'keys' => ['noorder' => 'ORD-2', 'id_template' => 'T-20', 'kd_jenis_prw' => 'PK']],
        ]);
        $this->assertNull($r->resolve('Observation', ['noorder' => 'ORD-X']));
        $unresolved = $r->unresolved();
        $this->assertCount(1, $unresolved);
        $this->assertSame('Observation', $unresolved[0]['type']);
    }

    public function testKeyMatchRequiresEqualValues(): void
    {
        $r = self::registry([
            ['type' => 'Observation', 'uuid' => 'uuid-obs-1', 'keys' => ['noorder' => 'ORD-1', 'id_template' => 'T-10', 'kd_jenis_prw' => 'PK']],
            ['type' => 'Observation', 'uuid' => 'uuid-obs-2', 'keys' => ['noorder' => 'ORD-2', 'id_template' => 'T-20', 'kd_jenis_prw' => 'PK']],
        ]);
        $this->assertNull($r->resolve('Observation', ['noorder' => 'ORD-1', 'id_template' => 'T-99', 'kd_jenis_prw' => 'PK']));
    }

    public function testUnregisteredTypeResolvesNull(): void
    {
        $r = self::registry([
            ['type' => 'Encounter', 'uuid' => 'uuid-enc'],
        ]);
        $this->assertNull($r->resolve('Medication', ['kode_brng' => 'P-001']));
    }
}
