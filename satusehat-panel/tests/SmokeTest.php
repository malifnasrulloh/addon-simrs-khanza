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
