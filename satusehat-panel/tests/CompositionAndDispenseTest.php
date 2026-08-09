<?php

declare(strict_types=1);

namespace SatusehatPanel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Pins the Composition fixes (arg order, identifier 10464, sections from
 * refs, status final) and the MedicationDispense gating fields.
 */
final class CompositionAndDispenseTest extends TestCase
{
    public function testCompositionCarriesIdentifierSectionsAndFinalStatus(): void
    {
        $row = [
            'no_rawat' => '2026/08/08/0001', 'nm_pasien' => 'NAMA PAS', 'nama' => 'Dr. X',
            'status_lanjut' => 'Ranap', 'kd_poli' => 'PENY',
            'tgl_registrasi' => '2026-08-08', 'jam_reg' => '09:00:00',
            'waktu_pulang' => null,
        ];
        $p = \SatuSehatPayloadBuilder::composition(
            '1000000001', $row, 'ihs-pas', 'ihs-dok', 'enc-uuid',
            [
                'Condition' => ['urn:uuid:cond-1'],
                'Observation' => ['urn:uuid:obs-1'],
            ],
            '',
            'final'
        );

        $this->assertSame('final', $p['status']);
        $this->assertSame('http://sys-ids.kemkes.go.id/composition/1000000001', $p['identifier']['system']);
        $this->assertSame('2026/08/08/0001', $p['identifier']['value']);
        $this->assertSame('Encounter/enc-uuid', $p['encounter']['reference']);

        $sectionRefs = [];
        foreach ($p['section'] as $section) {
            foreach ($section['entry'] ?? [] as $entry) {
                $sectionRefs[] = $entry['reference'];
            }
        }
        $this->assertContains('urn:uuid:cond-1', $sectionRefs);
        $this->assertContains('urn:uuid:obs-1', $sectionRefs);
    }

    public function testCompositionSectionEmptyWithoutRefs(): void
    {
        $row = ['no_rawat' => 'V-1', 'nm_pasien' => 'NAPSA', 'nama' => 'Dr. X', 'status_lanjut' => 'Ralan', 'kd_poli' => 'UMU'];
        $p = \SatuSehatPayloadBuilder::composition('1000000001', $row, 'ihs-1', 'ihs-dok', 'enc-uuid', [], '', 'final');
        $this->assertSame([], $p['section']);
    }

    public function testDispenseUsesStatusLocationAndAuthorizingRequest(): void
    {
        $row = [
            'no_resep' => 'R-1', 'kode_brng' => 'P-001', 'no_rawat' => 'V-1',
            'id_medication' => 'med-1', 'obat_display' => 'Paracetamol',
            'tgl_peresepan' => '2026-08-08', 'jam_peresepan' => '10:00:00',
            'tgl_perawatan' => '2026-08-08', 'jam' => '11:00:00',
            'status_pemberian' => 'Ranap', 'id_lokasi_satusehat' => 'loc-1', 'nm_bangsal' => 'Melati',
            'jml' => 2, 'denominator_code' => 'mg', 'denominator_system' => 'http://unitsofmeasure.org',
            'nm_pasien' => 'NAPSA', 'id_encounter' => 'enc-uuid', 'nama' => 'Dr. X',
            'aturan' => '3 x 1 tablet',
        ];
        $p = \SatuSehatPayloadBuilder::medicationDispense('1000000001', $row, 'ihs-1', 'ihs-dok', 'MR-1');

        $category = $p['category'][0]['coding'][0]['code'] ?? null;
        $this->assertSame('inpatient', $category);
        $this->assertSame('Location/loc-1', $p['location']['reference'] ?? '');
        $this->assertSame('Melati', $p['location']['display'] ?? '');
        $this->assertSame('MedicationRequest/MR-1', $p['authorizingPrescription'][0]['reference'] ?? '');
    }

    public function testDispenseOutpatientCategoryAndNoLocationWhenMissing(): void
    {
        $row = [
            'no_resep' => 'R-2', 'kode_brng' => 'C-002', 'no_rawat' => 'V-1',
            'id_medication' => 'MD-2', 'obat_display' => 'Captopril',
            'tgl_peresepan' => '2026-08-08', 'jam_peresepan' => '10:00:00',
            'tgl_perawatan' => '2026-08-08', 'jam' => '11:00:00',
            'status_pemberian' => 'Ralan', 'id_lokasi_satusehat' => '',
            'nm_bangsal' => '', 'jml' => 1,
            'nm_pasien' => 'NAPSA', 'id_encounter' => 'enc-uuid', 'nama' => 'Dr. X',
        ];
        $p = \SatuSehatPayloadBuilder::medicationDispense('1000000001', $row, 'ihs-1', 'ihs-dok', 'MR-2');
        $this->assertSame('outpatient', $p['category'][0]['coding'][0]['code'] ?? null);
        $this->assertArrayNotHasKey('location', $p);
    }
}