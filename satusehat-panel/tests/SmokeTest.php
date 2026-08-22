<?php

declare(strict_types=1);

namespace SatusehatPanel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Baseline smoke tests: assert every class the app depends on autoloads and
 * that the adopted global-namespace lib files are present in src/Util.
 */
final class SmokeTest extends TestCase
{
    public function testNamespacedPanelClassesAutoload(): void
    {
        $this->assertTrue(class_exists(\SatusehatPanel\Core\Router::class));
        $this->assertTrue(class_exists(\SatusehatPanel\Core\Auth::class));
        $this->assertTrue(class_exists(\SatusehatPanel\Core\Config::class));
        $this->assertTrue(class_exists(\SatusehatPanel\Core\Database::class));
        $this->assertTrue(class_exists(\SatusehatPanel\Util\PayloadAdapter::class));
        $this->assertTrue(class_exists(\SatusehatPanel\Controller\SendController::class));
        $this->assertTrue(class_exists(\SatusehatPanel\Controller\PatientController::class));
        $this->assertTrue(class_exists(\SatusehatPanel\Controller\AuditController::class));
        $this->assertTrue(class_exists(\SatusehatPanel\Controller\SettingsController::class));
        $this->assertTrue(class_exists(\SatusehatPanel\Controller\ResourceController::class));
        $this->assertTrue(class_exists(\SatusehatPanel\Controller\AuthController::class));
    }

    public function testAdoptedGlobalLibClassesLoad(): void
    {
        $this->assertTrue(class_exists(\SatuSehatClient::class));
        $this->assertTrue(class_exists(\SatuSehatConfig::class));
        $this->assertTrue(class_exists(\SatuSehatPayloadBuilder::class));
        $this->assertTrue(class_exists(\SatuSehatAllergyDictionary::class));
        $this->assertTrue(class_exists(\ObservationTTVDictionary::class));
        $this->assertTrue(class_exists(\EpisodeOfCareType::class));
        $this->assertTrue(class_exists(\Logger::class));
        $this->assertTrue(class_exists(\CredentialLocator::class));
    }

    public function testSendControllerImportsPayloadAdapterWarnings(): void
    {
        // Regression (audit): the class was referenced unqualified in the
        // SatusehatPanel\Controller namespace with no use import — every
        // adapter-built send threw and only custom payloads worked.
        $src = file_get_contents(__DIR__ . '/../src/Controller/SendController.php');
        $this->assertStringContainsString('use SatusehatPanel\Util\PayloadAdapterWarnings;', $src);
    }

    public function testDiagnosticReportBuildersExistForAdapterCalls(): void
    {
        // Regression (audit): the adapter called SatuSehatPayloadBuilder::
        // diagnosticReport() which does not exist — DiagnosticReport never
        // sent. The panel branches on the rad/lab builders the CLI uses.
        $this->assertTrue(method_exists(\SatuSehatPayloadBuilder::class, 'diagnosticReportRadiologi'));
        $this->assertTrue(method_exists(\SatuSehatPayloadBuilder::class, 'diagnosticReportLab'));
    }

    public function testConditionPayloadBuilderDefaultsToIcd10(): void
    {
        $payload = \SatuSehatPayloadBuilder::condition([
            'kd_penyakit' => 'A09.0',
            'nm_penyakit' => 'Other and unspecified gastroenteritis and colitis of infectious origin',
            'status' => 'Ralan',
            'tgl_registrasi' => '2026-08-22',
            'jam_reg' => '10:00:00',
            'nm_pasien' => 'Test Patient',
            'id_encounter' => 'enc-123',
        ], 'P123', '', 'D123', 'Dr. Test');

        $this->assertNotNull($payload);
        $this->assertSame('http://hl7.org/fhir/sid/icd-10', $payload['code']['coding'][0]['system']);
        $this->assertSame('A09.0', $payload['code']['coding'][0]['code']);
        $this->assertSame('Other and unspecified gastroenteritis and colitis of infectious origin', $payload['code']['coding'][0]['display']);
    }

    public function testDiagnosticReportLabOmitsEmptyConclusion(): void
    {
        $payload = \SatuSehatPayloadBuilder::diagnosticReportLab([
            'noorder' => 'LAB-001',
            'id_template' => '1',
            'Pemeriksaan' => 'Darah Lengkap',
            'tgl_hasil' => '2026-08-22',
            'jam_hasil' => '11:00:00',
            'id_encounter' => 'enc-123',
            'id_servicerequest' => 'sr-123',
            'id_specimen' => 'sp-123',
            'id_observation' => 'obs-123',
            'kesan' => '', // empty conclusion
        ], 'P123', 'D123', 'org-123');

        $this->assertArrayNotHasKey('conclusion', $payload);
    }

    public function testPayloadBuilderPublicApiSurface(): void
    {
        $this->assertTrue(method_exists(\SatuSehatPayloadBuilder::class, 'encounter'));
        $this->assertTrue(method_exists(\SatuSehatPayloadBuilder::class, 'condition'));
        $this->assertTrue(method_exists(\SatuSehatPayloadBuilder::class, 'medicationDispense'));
        $this->assertTrue(method_exists(\SatuSehatPayloadBuilder::class, 'composition'));
        $this->assertTrue(method_exists(\SatuSehatPayloadBuilder::class, 'sanitizeDateTime'));
        $this->assertTrue(method_exists(\SatuSehatPayloadBuilder::class, 'observationTTV'));
    }

    public function testDictionariesLoad(): void
    {
        $this->assertTrue(method_exists(\ObservationTTVDictionary::class, 'getDefinitions'));
        $this->assertTrue(method_exists(\ObservationTTVDictionary::class, 'mapKesadaran'));
        $this->assertTrue(method_exists(\EpisodeOfCareType::class, 'fromIcdCode'));
        $this->assertTrue(method_exists(\SatuSehatAllergyDictionary::class, 'lookup'));
    }

    public function testEntryPointsReferenceOnlyExistingFiles(): void
    {
        foreach (['index.php', 'public/index.php'] as $entry) {
            $this->assertFileExists(PANEL_BASE . '/' . $entry);
        }
        $this->assertFileExists(PANEL_BASE . '/public/shell.php');
    }
}
