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

        // category is 0..1 CodeableConcept → OBJECT (official fixture shape;
        // the list form was rejected with "expected a CodeableConcept object").
        $this->assertSame(
            'inpatient',
            $p['category']['coding'][0]['code'] ?? null,
            'category must be an OBJECT {"coding":[...]} matching the official example'
        );
        $this->assertArrayNotHasKey(0, $p['category'], 'list form must not be used for a 0..1 field');
        $this->assertSame('Location/loc-1', $p['location']['reference'] ?? '');
        $this->assertSame('Melati', $p['location']['display'] ?? '');
        $this->assertSame('MedicationRequest/MR-1', $p['authorizingPrescription'][0]['reference'] ?? '');
    }

    public function testStatementCategoryIsObjectShape(): void
    {
        // MedicationStatement.category is also 0..1 → object, mirroring the
        // dispense fixture; regression for the same server rejection.
        $row = [
            'no_resep' => 'R-2', 'kode_brng' => 'C-002', 'no_rawat' => 'V-1',
            'id_medication' => 'med-2', 'obat_display' => 'Captopril',
            'tgl_peresepan' => '2026-08-08', 'jam_peresepan' => '10:00:00',
            'tgl_perawatan' => '2026-08-08', 'jam' => '11:00:00',
            'status_lanjut' => 'Ralan', 'jml' => 1,
            'nm_pasien' => 'NAPSA', 'id_encounter' => 'enc-uuid', 'nama' => 'Dr. X',
        ];
        $p = \SatuSehatPayloadBuilder::medicationStatement('1000000001', $row, 'ihs-1', null);
        $this->assertSame(
            'outpatient',
            $p['category']['coding'][0]['code'] ?? null,
            'statement category must be an OBJECT {"coding":[...]}'
        );
    }

    public function testMedicationRequestCategoryStaysListShape(): void
    {
        // MedicationRequest.category is 0..* → LIST (official fixture).
        $row = [
            'no_resep' => 'R-3', 'kode_brng' => 'P-003', 'no_rawat' => 'V-1',
            'id_medication' => 'med-3', 'obat_display' => 'Paracetamol',
            'tgl_peresepan' => '2026-08-08', 'jam_peresepan' => '10:00:00',
            'jml' => 2, 'is_racikan' => false, 'aturan_pakai' => '3 x 1 tablet',
            'status_lanjut' => 'Ralan', 'nm_pasien' => 'NAPSA', 'id_encounter' => 'enc-uuid',
            'nama' => 'Dr. X', 'no_racik' => '',
        ];
        $p = \SatuSehatPayloadBuilder::medicationRequest('1000000001', $row, 'ihs-1', 'ihs-dok', null);
        $this->assertSame('outpatient', $p['category'][0]['coding'][0]['code'] ?? null);
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
        $this->assertSame('outpatient', $p['category']['coding'][0]['code'] ?? null);
        $this->assertArrayNotHasKey('location', $p);
    }
}