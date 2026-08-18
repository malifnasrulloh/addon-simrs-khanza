<?php

declare(strict_types=1);

namespace SatusehatPanel\Tests;

use PHPUnit\Framework\TestCase;
use SatusehatPanel\Controller\PatientController;

/**
 * Structural tests for the server-side rail stats (T48). The ready-count SQL
 * runs against the hospital MySQL (reg_periksa + real mapping tables), which
 * is not available in CI — so the SQL text itself is the contract: parity
 * with buildResourceManifest()'s availability/coverage rules, the strict
 * sent-sentinels, and correct outer-alias scoping.
 */
final class PatientStatsSqlTest extends TestCase
{
    private array $predicates;

    protected function setUp(): void
    {
        $this->predicates = PatientController::pendingResourcePredicates();
    }

    public function testCoversEveryManifestResourceType(): void
    {
        $expected = [
            'Encounter',
            'Condition',
            'Procedure',
            'AllergyIntolerance',
            'MedicationRequest',
            'MedicationDispense',
            'MedicationStatement',
            'Medication',
            'CarePlan',
            'ClinicalImpression',
            'Immunization',
            'Composition',
            'QuestionnaireResponse',
            'EpisodeOfCare',
            'ObservationTTV',
            'ServiceRequest',
            'Specimen',
            'Observation',
            'DiagnosticReport',
        ];
        sort($expected);
        $actual = array_keys($this->predicates);
        sort($actual);
        $this->assertSame($expected, $actual, 'every manifest resource type must have a ready predicate');
    }

    public function testEveryPredicateScopesToOuterAlias(): void
    {
        foreach ($this->predicates as $type => $sql) {
            $this->assertStringContainsString('rp.no_rawat', $sql, "{$type} must reference the outer rp alias");
            $this->assertStringNotContainsString('ORDER BY', $sql, "{$type} must be a plain boolean expression");
            $this->assertStringNotContainsString(';', $sql, "{$type} must not allow statement injection via the predicate list");
        }
    }

    public function testMappingIdsUseRealSentinelGuard(): void
    {
        // Existence-form types: the manifest's sent check for these is a bare
        // "mapping row exists" (no id column sentinel), so the predicate
        // mirrors that — an empty/- mapping row counts as sent.
        $existenceForm = ['Encounter', 'ClinicalImpression', 'Composition'];
        foreach ($this->predicates as $type => $sql) {
            if (in_array($type, $existenceForm, true)) {
                $this->assertStringContainsString('NOT EXISTS (SELECT 1 FROM satu_sehat_', $sql, "{$type} must use the existence form");
                continue;
            }
            $this->assertStringContainsString("NOT IN ('', '-')", $sql, "{$type} must treat empty/'-' mapping ids as not-sent");
        }
    }

    public function testMedicationDispenseMatchesStrictManifestSemantics(): void
    {
        // Parity with the manifest's strict allCovered: detail_pemberian_obat
        // rows keyed on the full CLI key must exceed the mapped real rows.
        $sql = $this->predicates['MedicationDispense'];
        $this->assertStringContainsString('detail_pemberian_obat dpo', $sql);
        $this->assertStringContainsString('satu_sehat_medicationdispense ssmd', $sql);
        foreach (['tgl_perawatan', 'jam', 'kode_brng', 'no_batch', 'no_faktur'] as $col) {
            $this->assertStringContainsString("ssmd.{$col} = dpo.{$col}", $sql, "strict key column {$col} must be joined");
        }
        // Strict form: source count > mapped count (NOT the old any-real form).
        $this->assertStringContainsString('> (SELECT COUNT(*)', $sql);
        $this->assertStringNotContainsString('NOT EXISTS (SELECT 1 FROM satu_sehat_medicationdispense', $sql);
    }

    public function testMedicationFamilyUsesPairCounts(): void
    {
        // MedicationRequest/Statement strictness counts resep_obat ×
        // resep_dokter pairs — the CLI's per-prescription-per-drug sync unit.
        foreach (['MedicationRequest', 'MedicationStatement'] as $type) {
            $sql = $this->predicates[$type];
            $this->assertStringContainsString('INNER JOIN resep_dokter rd ON rd.no_resep = ro.no_resep', $sql);
            $this->assertStringContainsString('> (SELECT COUNT(*) FROM satu_sehat_' . strtolower($type), $sql);
        }
    }

    public function testEveryPredicateIsAValidSqlBooleanForInjectionIntoStatsQuery(): void
    {
        // The stats() ready query ORs all predicates inside the WHERE of the
        // outer count query. Each predicate must start with an open paren or
        // a NOT/SELECT keyword so the OR chain stays unambiguous.
        foreach ($this->predicates as $type => $sql) {
            $this->assertMatchesRegularExpression(
                '/^\s*(\(|NOT EXISTS|SELECT)/',
                $sql,
                "{$type} must be a self-contained boolean expression for the OR chain"
            );
        }
    }

    public function testMedicationRequestStatementResolveVisitViaJoin(): void
    {
        // Regression (audit): these tables are keyed (no_resep, kode_brng)
        // and have NO no_rawat column — the old predicates referenced
        // `no_rawat = rp.no_rawat` on them, so the whole ready count threw.
        foreach (['MedicationRequest', 'MedicationStatement'] as $type) {
            $sql = $this->predicates[$type];
            $this->assertStringContainsString('INNER JOIN resep_obat ro ON ro.no_resep = ssm.no_resep', $sql, "{$type} must reach the visit through resep_obat");
            $this->assertStringNotContainsString('FROM satu_sehat_' . strtolower($type) . ' WHERE no_rawat', $sql, "{$type} must never filter by no_rawat on its own table");
        }
    }

    public function testLabPredicatesReachOrdersThroughNoorderJoins(): void
    {
        // Regression (audit): lab mapping tables are keyed by noorder; the
        // predicates must resolve the visit via the permintaan_* order header.
        foreach (['ServiceRequest', 'Specimen', 'Observation', 'DiagnosticReport'] as $type) {
            $sql = $this->predicates[$type];
            $this->assertStringContainsString('ss.noorder', $sql, "{$type} must join its mapping table on noorder");
            $this->assertStringNotContainsString('FROM satu_sehat_' . strtolower($type), $sql, "{$type} must never scan a mapping table without its order join");
        }
    }
}