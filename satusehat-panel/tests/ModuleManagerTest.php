<?php

declare(strict_types=1);

namespace SatusehatPanel\Tests;

use PHPUnit\Framework\TestCase;
use SatusehatPanel\Core\BaseModuleController;
use SatusehatPanel\Core\ModuleManager;
use SatusehatPanel\Core\Router;
use SatusehatPanel\Core\Routes;

/**
 * Test dynamic module discovery and modular architecture.
 */
final class ModuleManagerTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
        Routes::register($this->router);
    }

    public function testDiscoverFindsAllModularWorkspaces(): void
    {
        $manifests = ModuleManager::discover();
        $this->assertGreaterThanOrEqual(25, count($manifests));

        // Core required modules must exist
        $required = [
            'bundle',
            'encounter',
            'episode_of_care',
            'condition',
            'observation_ttv',
            'procedure',
            'allergy_intolerance',
            'care_plan',
            'clinical_impression',
            'medication',
            'medication_request',
            'medication_dispense',
            'medication_statement',
            'immunization',
            'service_request_lab',
            'specimen_lab',
            'observation_lab',
            'diagnostic_report_lab',
            'service_request_radiologi',
            'specimen_radiologi',
            'observation_radiologi',
            'diagnostic_report_radiologi',
            'imaging_study',
            'composition',
            'questionnaire_response',
        ];

        foreach ($required as $id) {
            $this->assertArrayHasKey($id, $manifests, "Module {$id} must be discovered");
            $m = $manifests[$id];
            $this->assertNotEmpty($m['title'], "Module {$id} must have a title");
            $this->assertNotEmpty($m['category'], "Module {$id} must have a category");
            $this->assertTrue($m['has_controller'], "Module {$id} must have Controller.php");
            $this->assertTrue($m['has_view'], "Module {$id} must have view.js");
        }
    }

    public function testModuleEndpointsRegisteredInRouter(): void
    {
        // /api/modules list endpoint
        $match = $this->router->matchRoute('GET', '/api/modules');
        $this->assertSame('/api/modules', $match['path']);

        // Module-specific endpoints
        $matchList = $this->router->matchRoute('GET', '/api/modules/condition/list');
        $this->assertSame('/api/modules/condition/list', $matchList['path']);

        $matchSend = $this->router->matchRoute('POST', '/api/modules/observation_ttv/send');
        $this->assertSame('/api/modules/observation_ttv/send', $matchSend['path']);

        $matchPrev = $this->router->matchRoute('GET', '/api/modules/encounter/preview/V-001');
        $this->assertSame('/api/modules/encounter/preview/{key:any}', $matchPrev['path']);
        $this->assertSame('V-001', $matchPrev['params']['key']);
    }

    public function testEvaluateStatusDetectsAllFourStates(): void
    {
        // 1. Sent
        $stSent = BaseModuleController::evaluateStatus([], 'SATUSEHAT-12345', null);
        $this->assertSame('sent', $stSent['status']);
        $this->assertSame('Sudah Kirim', $stSent['label']);
        $this->assertSame('SATUSEHAT-12345', $stSent['satusehat_id']);

        // 2. Failed (Rule error from SQLite state)
        $stFailed = BaseModuleController::evaluateStatus([], '', 'invalid_code');
        $this->assertSame('failed', $stFailed['status']);
        $this->assertStringContainsString('Ditolak', $stFailed['blocker_reason']);

        // 3. Blocked (Missing Encounter + Doctor IHS)
        $stBlocked = BaseModuleController::evaluateStatus([], '', null, ['encounter', 'ihs_dokter']);
        $this->assertSame('blocked', $stBlocked['status']);
        $this->assertSame('Terblokir', $stBlocked['label']);
        $this->assertStringContainsString('Encounter', $stBlocked['blocker_reason']);
        $this->assertStringContainsString('Dokter', $stBlocked['blocker_reason']);

        // 4. Ready
        $stReady = BaseModuleController::evaluateStatus([], '', null, []);
        $this->assertSame('ready', $stReady['status']);
        $this->assertSame('Siap Kirim', $stReady['label']);
        $this->assertNull($stReady['blocker_reason']);
    }

    public function testValidDateHelper(): void
    {
        $this->assertSame('2026-08-25', BaseModuleController::validDate('2026-08-25'));
        $this->assertSame('', BaseModuleController::validDate('2026-13-45'));
        $this->assertSame('', BaseModuleController::validDate('invalid-date'));
        $this->assertSame('', BaseModuleController::validDate(''));
    }
}
