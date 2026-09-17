<?php

declare(strict_types=1);

namespace SatusehatPanel\Tests;

use PHPUnit\Framework\TestCase;
use SatusehatPanel\Core\BaseModuleController;

/**
 * Verifies that BaseModuleController routes resource updates according
 * to the SATUSEHAT PUT vs PATCH update architecture:
 * - EpisodeOfCare with ID: direct targeted PATCH (bypassing consent rules)
 * - Encounter / Condition / AllergyIntolerance with ID: putWithPatchFallback
 * - All other resources with ID: standard PUT
 * - New resources (no ID): standard POST
 */
final class PanelUpdateStrategyTest extends TestCase
{
    protected function tearDown(): void
    {
        BaseModuleController::setClientForTesting(null);
    }

    private function createMockClient(): object
    {
        return new class extends \SatuSehatClient {
            public array $calls = [];

            public function __construct()
            {
                $config = new \SatuSehatConfig('', [
                    'DB_HOST' => 'localhost',
                    'DB_NAME' => 'sik',
                    'DB_USER' => 'root',
                    'SATUSEHAT_ORG_ID' => '1000',
                    'SATUSEHAT_CLIENT_ID' => 'test-client',
                    'SATUSEHAT_SECRET_KEY' => 'test-secret',
                    'SATUSEHAT_AUTH_URL' => 'https://example.com/oauth2',
                    'SATUSEHAT_BASE_URL' => 'https://example.com/fhir',
                    'LOG_DIR' => sys_get_temp_dir(),
                ]);
                parent::__construct($config, new \Logger(sys_get_temp_dir(), 'test'));
            }

            public function post(string $path, array $payload): array
            {
                $this->calls[] = ['method' => 'POST', 'path' => $path, 'payload' => $payload];
                return ['success' => true, 'code' => 201, 'data' => ['resourceType' => 'Resource', 'id' => 'NEW-1']];
            }

            public function put(string $path, array $payload): array
            {
                $this->calls[] = ['method' => 'PUT', 'path' => $path, 'payload' => $payload];
                return ['success' => true, 'code' => 200, 'data' => ['resourceType' => 'Resource', 'id' => 'UPD-1']];
            }

            public function patch(string $path, array $ops, ?array $putPayload = null): array
            {
                $this->calls[] = ['method' => 'PATCH', 'path' => $path, 'ops' => $ops];
                return ['success' => true, 'code' => 200, 'data' => ['resourceType' => 'EpisodeOfCare', 'id' => 'EOC-1']];
            }

            public function putWithPatchFallback(string $endpoint, array $putPayload, array $patchOps): array
            {
                $this->calls[] = [
                    'method' => 'PUT_FALLBACK',
                    'path' => $endpoint,
                    'putPayload' => $putPayload,
                    'patchOps' => $patchOps
                ];
                return ['success' => true, 'code' => 200, 'data' => ['resourceType' => 'Resource', 'id' => 'UPD-FB-1']];
            }
        };
    }

    private function invokeSend(string $endpoint, array $payload, object $client): array
    {
        BaseModuleController::setClientForTesting($client);
        $input = ['items' => ['2026/09/12/000001']];

        return BaseModuleController::executeSend(
            $endpoint,
            fn($k) => ['payload' => $payload, 'meta' => []],
            fn($k, $id, $out) => null,
            $input
        );
    }

    public function testEpisodeOfCareWithIdRoutesToDirectPatch(): void
    {
        $mock = $this->createMockClient();
        $payload = [
            'resourceType' => 'EpisodeOfCare',
            'id' => 'eoc-100',
            'status' => 'finished',
            'patient' => [
                'reference' => 'Patient/P1000',
                'display' => 'PASIEN TEST',
            ],
            'period' => ['end' => '2026-09-12T12:00:00+07:00'],
            'diagnosis' => [['condition' => ['reference' => 'Condition/c1']]],
        ];

        $res = $this->invokeSend('/EpisodeOfCare', $payload, $mock);
        $this->assertTrue($res['success']);
        $this->assertCount(1, $mock->calls);
        $call = $mock->calls[0];

        $this->assertSame('PATCH', $call['method']);
        $this->assertSame('/EpisodeOfCare/eoc-100', $call['path']);
        $this->assertCount(4, $call['ops']);
        $this->assertSame('/patient', $call['ops'][0]['path']);
        $this->assertSame('Patient/P1000', $call['ops'][0]['value']['reference']);
        $this->assertSame('PASIEN TEST', $call['ops'][0]['value']['display']);
        $this->assertSame('/status', $call['ops'][1]['path']);
        $this->assertSame('/period/end', $call['ops'][2]['path']);
        $this->assertSame('/diagnosis', $call['ops'][3]['path']);
    }

    public function testEncounterWithIdRoutesToPutWithPatchFallback(): void
    {
        $mock = $this->createMockClient();
        $payload = [
            'resourceType' => 'Encounter',
            'id' => 'enc-200',
            'status' => 'finished',
            'period' => ['end' => '2026-09-12T13:00:00+07:00'],
            'statusHistory' => [['status' => 'finished']],
        ];

        $res = $this->invokeSend('/Encounter', $payload, $mock);
        $this->assertTrue($res['success']);
        $this->assertCount(1, $mock->calls);
        $call = $mock->calls[0];

        $this->assertSame('PUT_FALLBACK', $call['method']);
        $this->assertSame('/Encounter/enc-200', $call['path']);
        $this->assertSame($payload, $call['putPayload']);
        $this->assertCount(3, $call['patchOps']);
        $this->assertSame('/status', $call['patchOps'][0]['path']);
        $this->assertSame('/period/end', $call['patchOps'][1]['path']);
        $this->assertSame('/statusHistory', $call['patchOps'][2]['path']);
    }

    public function testConditionWithIdRoutesToPutWithPatchFallback(): void
    {
        $mock = $this->createMockClient();
        $payload = [
            'resourceType' => 'Condition',
            'id' => 'cond-300',
            'clinicalStatus' => ['coding' => [['code' => 'resolved']]],
        ];

        $res = $this->invokeSend('/Condition', $payload, $mock);
        $this->assertTrue($res['success']);
        $this->assertCount(1, $mock->calls);
        $call = $mock->calls[0];

        $this->assertSame('PUT_FALLBACK', $call['method']);
        $this->assertSame('/Condition/cond-300', $call['path']);
        $this->assertCount(1, $call['patchOps']);
        $this->assertSame('/clinicalStatus', $call['patchOps'][0]['path']);
    }

    public function testAllergyIntoleranceWithIdRoutesToPutWithPatchFallback(): void
    {
        $mock = $this->createMockClient();
        $payload = [
            'resourceType' => 'AllergyIntolerance',
            'id' => 'allergy-400',
            'clinicalStatus' => ['coding' => [['code' => 'resolved']]],
            'verificationStatus' => ['coding' => [['code' => 'confirmed']]],
        ];

        $res = $this->invokeSend('/AllergyIntolerance', $payload, $mock);
        $this->assertTrue($res['success']);
        $this->assertCount(1, $mock->calls);
        $call = $mock->calls[0];

        $this->assertSame('PUT_FALLBACK', $call['method']);
        $this->assertSame('/AllergyIntolerance/allergy-400', $call['path']);
        $this->assertCount(2, $call['patchOps']);
        $this->assertSame('/clinicalStatus', $call['patchOps'][0]['path']);
        $this->assertSame('/verificationStatus', $call['patchOps'][1]['path']);
    }

    public function testProcedureWithIdRoutesToStandardPut(): void
    {
        $mock = $this->createMockClient();
        $payload = [
            'resourceType' => 'Procedure',
            'id' => 'proc-500',
            'status' => 'completed',
        ];

        $res = $this->invokeSend('/Procedure', $payload, $mock);
        $this->assertTrue($res['success']);
        $this->assertCount(1, $mock->calls);
        $call = $mock->calls[0];

        $this->assertSame('PUT', $call['method']);
        $this->assertSame('/Procedure/proc-500', $call['path']);
    }

    public function testResourceWithoutIdRoutesToStandardPost(): void
    {
        $mock = $this->createMockClient();
        $payload = [
            'resourceType' => 'Procedure',
            'status' => 'completed',
        ];

        $res = $this->invokeSend('/Procedure', $payload, $mock);
        $this->assertTrue($res['success']);
        $this->assertCount(1, $mock->calls);
        $call = $mock->calls[0];

        $this->assertSame('POST', $call['method']);
        $this->assertSame('/Procedure', $call['path']);
    }

    public function testPermissionSkipDoesNotCountAsSuccess(): void
    {
        $mock = $this->createMockClient();
        $mock = new class extends \SatuSehatClient {
            public function __construct()
            {
                $config = new \SatuSehatConfig('', [
                    'DB_HOST' => 'localhost',
                    'DB_NAME' => 'sik',
                    'DB_USER' => 'root',
                    'SATUSEHAT_ORG_ID' => '1000',
                    'SATUSEHAT_CLIENT_ID' => 'test-client',
                    'SATUSEHAT_SECRET_KEY' => 'test-secret',
                    'SATUSEHAT_AUTH_URL' => 'https://example.com/oauth2',
                    'SATUSEHAT_BASE_URL' => 'https://example.com/fhir',
                    'LOG_DIR' => sys_get_temp_dir(),
                ]);
                parent::__construct($config, new \Logger(sys_get_temp_dir(), 'test'));
            }
            public function putWithPatchFallback(string $endpoint, array $putPayload, array $patchOps): array
            {
                return [
                    'success' => true,
                    'code' => 200,
                    'message' => 'Permission denied (cached)',
                    'data' => [],
                    'permission_skip' => true,
                ];
            }
        };

        $payload = [
            'resourceType' => 'Encounter',
            'id' => 'enc-denied',
            'status' => 'finished',
        ];

        $res = $this->invokeSend('/Encounter', $payload, $mock);
        $this->assertFalse($res['success'], 'Permission skip must not evaluate to overall success');
        $this->assertSame(0, $res['success_count']);
        $this->assertSame(1, $res['fail_count']);
        $this->assertSame('permission_denied', $res['results']['2026/09/12/000001']['status']);
    }

    public function testOwnershipSkipDoesNotCountAsSuccess(): void
    {
        $mock = $this->createMockClient();
        $mock = new class extends \SatuSehatClient {
            public function __construct()
            {
                $config = new \SatuSehatConfig('', [
                    'DB_HOST' => 'localhost',
                    'DB_NAME' => 'sik',
                    'DB_USER' => 'root',
                    'SATUSEHAT_ORG_ID' => '1000',
                    'SATUSEHAT_CLIENT_ID' => 'test-client',
                    'SATUSEHAT_SECRET_KEY' => 'test-secret',
                    'SATUSEHAT_AUTH_URL' => 'https://example.com/oauth2',
                    'SATUSEHAT_BASE_URL' => 'https://example.com/fhir',
                    'LOG_DIR' => sys_get_temp_dir(),
                ]);
                parent::__construct($config, new \Logger(sys_get_temp_dir(), 'test'));
            }
            public function putWithPatchFallback(string $endpoint, array $putPayload, array $patchOps): array
            {
                return [
                    'success' => true,
                    'code' => 200,
                    'message' => 'Resource owned by another organization',
                    'data' => [],
                    'ownership_skip' => true,
                    'owner_org' => '9999999999',
                ];
            }
        };

        $payload = [
            'resourceType' => 'Encounter',
            'id' => 'enc-foreign',
            'status' => 'finished',
        ];

        $res = $this->invokeSend('/Encounter', $payload, $mock);
        $this->assertFalse($res['success'], 'Ownership skip must not evaluate to overall success');
        $this->assertSame(0, $res['success_count']);
        $this->assertSame(1, $res['fail_count']);
        $this->assertSame('ownership_skip', $res['results']['2026/09/12/000001']['status']);
    }

    public function testEvaluateStatusUpdateNeeded(): void
    {
        $st = BaseModuleController::evaluateStatus(
            [],
            'ENC-123',
            'in-progress',
            [],
            ['needs_update' => true, 'update_label' => 'Perlu Update (Selesai)', 'update_reason' => 'Discharged']
        );

        $this->assertSame('update_needed', $st['status']);
        $this->assertSame('Perlu Update (Selesai)', $st['label']);
        $this->assertSame('badge-warning', $st['badge']);
        $this->assertTrue($st['can_send']);
        $this->assertSame('Discharged', $st['blocker_reason']);
    }

    public function testEncounterEmbedsEpisodeOfCare(): void
    {
        $row = [
            'no_rawat' => '2026/09/12/000001',
            'tgl_registrasi' => '2026-09-12',
            'jam_reg' => '08:00:00',
            'status_lanjut' => 'Ralan',
            'id_lokasi_satusehat' => 'LOC-1',
            'id_encounter' => 'ENC-1',
            'id_episode_of_care' => 'EOC-999',
            'nm_poli' => 'Poli Umum',
            'nm_pasien' => 'Pasien Test',
            'nama' => 'Dokter Test',
        ];

        $payload = \SatuSehatPayloadBuilder::encounter(
            '1000',
            $row,
            'P-1',
            'D-1',
            'finished',
            [],
            'ENC-1',
            'EOC-999'
        );

        $this->assertArrayHasKey('episodeOfCare', $payload);
        $this->assertCount(1, $payload['episodeOfCare']);
        $this->assertSame('EpisodeOfCare/EOC-999', $payload['episodeOfCare'][0]['reference']);
    }

    public function testEpisodeOfCareHasNoEncounterReference(): void
    {
        $row = [
            'no_rawat' => '2026/09/12/000001',
            'tgl_registrasi' => '2026-09-12',
            'jam_reg' => '08:00:00',
            'nm_pasien' => 'Pasien Test',
            'nama' => 'Dokter Test',
        ];
        $type = \EpisodeOfCareType::fromIcdCode('A15.0');
        $this->assertNotNull($type);

        $payload = \SatuSehatPayloadBuilder::episodeOfCare(
            '1000',
            $row,
            'P-1',
            'D-1',
            'active',
            $type,
            'EOC-1'
        );

        // Official HL7 FHIR R4 EpisodeOfCare has NO encounter element
        $this->assertArrayNotHasKey('encounter', $payload);
    }
}
