<?php

declare(strict_types=1);

namespace SatusehatPanel\Tests;

use PHPUnit\Framework\TestCase;
use SatusehatPanel\Util\PayloadAdapter;
use SatusehatPanel\Util\ReferenceRegistry;

/**
 * Guards the persist-key contract between PayloadAdapter and the mapping
 * tables the CLI actually writes to (php-service/lib/satusehat/Database.php).
 * Wrong key shapes here = ids never persisted = duplicate resources (20002).
 */
final class PersistMappingTest extends TestCase
{
    private static function withKeys(array $payload, string $table, string $idCol, array $row, array $wanted): array
    {
        $m = new \ReflectionMethod(PayloadAdapter::class, 'withPersistKeys');
        $m->setAccessible(true);
        return $m->invoke(null, $payload, $table, $idCol, $row, $wanted);
    }

    public function testMedicationKeysMatchCliSchema(): void
    {
        $p = self::withKeys(['resourceType' => 'Medication'], 'satu_sehat_medication', 'id_medication', ['kode_brng' => 'P-001'], ['kode_brng']);
        $this->assertSame(['kode_brng' => 'P-001'], $p['_panel_persist_keys']['keys']);
    }

    public function testMedicationRequestKeysMatchCliSchema(): void
    {
        $p = self::withKeys([], 'satu_sehat_medicationrequest', 'id_medicationrequest', ['no_resep' => 'R-9', 'kode_brng' => 'P-001'], ['no_resep', 'kode_brng']);
        $this->assertSame(['no_resep' => 'R-9', 'kode_brng' => 'P-001'], $p['_panel_persist_keys']['keys']);
    }

    public function testMedicationRequestRacikanKeysIncludeNoRacik(): void
    {
        $p = self::withKeys([], 'satu_sehat_medicationrequest_racikan', 'id_medicationrequest', ['no_resep' => 'R-9', 'kode_brng' => 'P-001', 'no_racik' => 'RC-1'], ['no_resep', 'kode_brng', 'no_racik']);
        $this->assertSame(['no_resep' => 'R-9', 'kode_brng' => 'P-001', 'no_racik' => 'RC-1'], $p['_panel_persist_keys']['keys']);
    }

    public function testDispenseKeysFollowCliNoResepSchema(): void
    {
        // CLI: satu_sehat_medicationdispense (no_rawat, tgl_perawatan, jam,
        // kode_brng, no_batch, no_faktur) — NO no_resep column.
        $row = ['no_rawat' => 'V-1', 'tgl_perawatan' => '2026-08-08', 'jam' => '10:00:00', 'kode_brng' => 'P-001', 'no_batch' => 'B-1', 'no_faktur' => 'F-1'];
        $p = self::withKeys([], 'satu_sehat_medicationdispense', 'id_medicationdispanse', $row, ['no_rawat', 'tgl_perawatan', 'jam', 'kode_brng', 'no_batch', 'no_faktur']);
        $keys = $p['_panel_persist_keys']['keys'];
        $this->assertSame('V-1', $keys['no_rawat']);
        $this->assertArrayNotHasKey('no_resep', $keys);
        $this->assertSame('id_medicationdispanse', $p['_panel_persist_keys']['id_col']);
    }

    public function testLabPipelineKeysMatchCliSchema(): void
    {
        $row = ['noorder' => 'ORD-1', 'kd_jenis_prw' => 'PK', 'id_template' => 'T-10'];
        $obs = self::withKeys([], 'satu_sehat_observation_lab', 'id_observation', $row, ['noorder', 'kd_jenis_prw', 'id_template']);
        $this->assertSame(['noorder' => 'ORD-1', 'kd_jenis_prw' => 'PK', 'id_template' => 'T-10'], $obs['_panel_persist_keys']['keys']);

        $radRow = ['noorder' => 'ORD-2', 'kd_jenis_prw' => 'RA'];
        $sr = self::withKeys([], 'satu_sehat_servicerequest_radiologi', 'id_servicerequest', $radRow, ['noorder', 'kd_jenis_prw']);
        $this->assertSame(['noorder' => 'ORD-2', 'kd_jenis_prw' => 'RA'], $sr['_panel_persist_keys']['keys']);
        $this->assertArrayNotHasKey('id_template', $sr['_panel_persist_keys']['keys']);
    }

    public function testMissingRowKeysAreOmitted(): void
    {
        $p = self::withKeys([], 'satu_sehat_immunization', 'id_immunization', ['no_rawat' => 'V-1', 'kode_brng' => 'VX-1'], ['no_rawat', 'tgl_perawatan', 'jam', 'kode_brng', 'no_batch', 'no_faktur']);
        $this->assertSame(['no_rawat' => 'V-1', 'kode_brng' => 'VX-1'], $p['_panel_persist_keys']['keys']);
    }

    public function testPersistKeysFeedReferenceRegistryResolution(): void
    {
        $registry = new ReferenceRegistry();
        $p1 = self::withKeys([], 'satu_sehat_observation_lab', 'id_observation', ['noorder' => 'ORD-1', 'kd_jenis_prw' => 'PK', 'id_template' => 'T-10'], ['noorder', 'kd_jenis_prw', 'id_template']);
        $registry->register('Observation', 'uuid-1', $p1['_panel_persist_keys']['keys']);
        $p2 = self::withKeys([], 'satu_sehat_observation_lab', 'id_observation', ['noorder' => 'ORD-2', 'kd_jenis_prw' => 'PK', 'id_template' => 'T-20'], ['noorder', 'kd_jenis_prw', 'id_template']);
        $registry->register('Observation', 'uuid-2', $p2['_panel_persist_keys']['keys']);

        $drRow = ['noorder' => 'ORD-2', 'kd_jenis_prw' => 'PK', 'id_template' => 'T-20'];
        $drKeys = self::withKeys([], 'satu_sehat_diagnosticreport_lab', 'id_diagnosticreport', $drRow, ['noorder', 'kd_jenis_prw', 'id_template']);
        $this->assertSame('uuid-2', $registry->resolve('Observation', $drKeys['_panel_persist_keys']['keys']));
        $this->assertSame('uuid-1', $registry->resolve('Observation', ['noorder' => 'ORD-1', 'kd_jenis_prw' => 'PK', 'id_template' => 'T-10']));
    }
}