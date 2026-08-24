<?php

declare(strict_types=1);

namespace SatusehatPanel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The B8 fix: comma decimals, fractions and mixed quantities parse
 * correctly; unparseable values become null (never silent 36.0-style
 * truncation).
 */
final class NumberUtilTest extends TestCase
{
    public function testCommaDecimal(): void
    {
        $this->assertSame(36.5, \SatuSehatNumber::parse('36,5'));
        $this->assertSame(36.5, \SatuSehatNumber::parse('36.5'));
    }

    public function testFractions(): void
    {
        $this->assertSame(0.5, \SatuSehatNumber::parse('1/2'));
        $this->assertSame(1.5, \SatuSehatNumber::parse('1 1/2'));
        $this->assertSame(0.25, \SatuSehatNumber::parse('1/4'));
    }

    public function testPlainIntegersAndFloats(): void
    {
        $this->assertSame(80.0, \SatuSehatNumber::parse('80'));
        $this->assertSame(2.0, \SatuSehatNumber::parse(2));
        $this->assertSame(0.75, \SatuSehatNumber::parse('.75'));
    }

    public function testUnparseableValuesAreNull(): void
    {
        $this->assertNull(\SatuSehatNumber::parse(''));
        $this->assertNull(\SatuSehatNumber::parse('-'));
        $this->assertNull(\SatuSehatNumber::parse(null));
        $this->assertNull(\SatuSehatNumber::parse('abc'));
        $this->assertNull(\SatuSehatNumber::parse('1/0'));
    }

    public function testBuilderUsesLocaleParsingForTtvAndSigna(): void
    {
        // TTV suhu "36,5" must produce 36.5 (previously 36.0).
        $def = [
            'type' => 'quantity',
            'unit' => 'Cel',
            'unit_display' => 'degree Celsius',
            'db_column' => 'suhu_tubuh',
            'system' => 'http://unitsofmeasure.org',
            'code' => 'Cel',
            'display' => 'degree Celsius',
        ];
        $p = \SatuSehatPayloadBuilder::observationTTV(
            ['value' => '36,5', 'nm_pasien' => 'NAPSA', 'nama' => 'Dr. X', 'id_encounter' => 'E-1', 'tgl_observasi' => '2026-08-08', 'jam_observasi' => '09:00:00'],
            'ihs-1', 'ihs-dok', $def
        );
        $this->assertSame(36.5, $p['valueQuantity']['value']);

        // Dispense with "1/2 x 3" signa: dose quantity 0.5, not 1.0.
        $row = [
            'no_resep' => 'R-1', 'kode_brng' => 'P-001', 'no_rawat' => 'V-1',
            'id_medication' => 'med-1', 'obat_display' => 'Paracetamol',
            'tgl_peresepan' => '2026-08-08', 'jam_peresepan' => '10:00:00',
            'tgl_perawatan' => '2026-08-08', 'jam' => '11:00:00',
            'status_pemberian' => 'Ralan', 'id_lokasi_satusehat' => 'loc-1', 'nm_bangsal' => 'A',
            'jml' => 2, 'nm_pasien' => 'NAPSA', 'id_encounter' => 'E-1', 'nama' => 'Dr. X',
            'aturan' => '1/2 x 3',
        ];
        $d = \SatuSehatPayloadBuilder::medicationDispense('1000000001', $row, 'ihs-1', 'ihs-dok', 'MR-1');
        $dose = $d['dosageInstruction'][0]['doseAndRate'][0]['doseQuantity']['value'] ?? null;
        $this->assertSame(0.5, $dose);

        // Zero value in doseQuantity clamped to 1.0 (Rule 10343 / 10356)
        $ucum = \SatuSehatPayloadBuilder::sanitizeUcum(['value' => 0, 'unit' => 'TAB']);
        $this->assertSame(1.0, $ucum['value']);
    }

    public function testIcd10CodeNormalizationAndMapping(): void
    {
        $this->assertSame('E87.0', \SatuSehatPayloadBuilder::mapIcd10('E870'));
        $this->assertSame('P00.0', \SatuSehatPayloadBuilder::mapIcd10('P000'));
        $this->assertSame('E88.7', \SatuSehatPayloadBuilder::mapIcd10('E887'));
        $this->assertSame('O00', \SatuSehatPayloadBuilder::mapIcd10('O00.'));
        $this->assertSame('O00.8', \SatuSehatPayloadBuilder::mapIcd10('O00.81'));
        $this->assertSame('J96', \SatuSehatPayloadBuilder::mapIcd10('J96.90'));
        $this->assertSame('I84.9', \SatuSehatPayloadBuilder::mapIcd10('K64'));
    }
}