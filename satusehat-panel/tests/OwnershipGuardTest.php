<?php

declare(strict_types=1);

namespace SatusehatPanel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Edit-ownership guard (the "You don't have permission to edit resource"
 * diagnosis): resources demonstrably owned by ANOTHER organization are
 * skipped locally with a clear Indonesian message instead of a generic 403
 * + permanent-denial cache. Medication.manufacturer must NOT trigger a skip
 * (it is the drug company, always foreign).
 */
final class OwnershipGuardTest extends TestCase
{
    private string $logDir;
    private array $calls = [];
    private array $responses = [];

    protected function setUp(): void
    {
        $this->logDir = PANEL_TEST_STORAGE . '/logs-' . uniqid();
        mkdir($this->logDir, 0755, true);
        file_put_contents(
            $this->logDir . '/satusehat_token.json',
            json_encode(['token' => 'test-token', 'expires_at' => time() + 3600])
        );
        $this->calls = [];
    }

    protected function tearDown(): void
    {
        @array_map('unlink', glob($this->logDir . '/*') ?: []);
        @rmdir($this->logDir);
    }

    private function client(): \SatuSehatClient
    {
        $config = new \SatuSehatConfig('', [
            'DB_HOST' => 'localhost',
            'DB_NAME' => 'sik',
            'DB_USER' => 'root',
            'SATUSEHAT_ORG_ID' => '1000000001', // OUR org
            'SATUSEHAT_CLIENT_ID' => 'test-client',
            'SATUSEHAT_SECRET_KEY' => 'test-secret',
            'SATUSEHAT_AUTH_URL' => 'https://api-satusehat-stg.dto.kemkes.go.id/oauth2/v1',
            'SATUSEHAT_BASE_URL' => 'https://api-satusehat-stg.dto.kemkes.go.id/fhir-r4/v1',
            'LOG_DIR' => $this->logDir,
            'SATUSEHAT_VERIFY_TLS' => 'true',
            'SATUSEHAT_DELAY_MS' => '0',
        ]);
        $log = new \Logger($this->logDir, 'test');
        $client = new \SatuSehatClient($config, $log);
        $client->transport = function (string $url, string $method, array $headers, ?string $body): array {
            $this->calls[] = $method;
            if ($method === 'GET' && isset($this->responses['get'])) {
                return $this->responses['get'];
            }
            return $this->responses['put'] ?? ['{}', 200, ''];
        };
        return $client;
    }

    public function testForeignEncounterIsSkippedWithoutPut(): void
    {
        $this->responses['get'] = [
            json_encode(['resourceType' => 'Encounter', 'serviceProvider' => ['reference' => 'Organization/9999999999']]),
            200,
            '',
        ];
        $result = $this->client()->patch('/Encounter/enc-foreign', [], ['resourceType' => 'Encounter']);

        $this->assertTrue($result['success']);
        $this->assertTrue(!empty($result['ownership_skip']));
        $this->assertSame('9999999999', $result['owner_org']);
        $this->assertStringContainsString('fasyankes lain', $result['message']);
        $this->assertSame(['GET'], $this->calls, 'PUT must never fire for a foreign resource');
    }

    public function testOwnEncounterProceedsToPut(): void
    {
        $this->responses['get'] = [
            json_encode(['resourceType' => 'Encounter', 'serviceProvider' => ['reference' => 'Organization/1000000001']]),
            200,
            '',
        ];
        $this->responses['put'] = [json_encode(['resourceType' => 'Encounter', 'id' => 'enc-1']), 200, ''];

        $result = $this->client()->patch('/Encounter/enc-1', [], ['resourceType' => 'Encounter', 'status' => 'finished']);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['ownership_skip'] ?? null);
        $this->assertSame(['GET', 'PUT'], $this->calls);
    }

    public function testMultipleOrgRefsIncludingOursIsOurs(): void
    {
        // A DiagnosticReport whose performer lists BOTH the hospital and an
        // external lab belongs to the hospital — must NOT be skipped.
        $this->responses['get'] = [
            json_encode([
                'resourceType' => 'DiagnosticReport',
                'performer' => [
                    ['reference' => 'Practitioner/N10000001'],
                    ['reference' => 'Organization/1000000001'],
                    ['reference' => 'Organization/5555555555'],
                ],
            ]),
            200,
            '',
        ];
        $this->responses['put'] = [json_encode(['resourceType' => 'DiagnosticReport']), 200, ''];

        $result = $this->client()->patch('/DiagnosticReport/dr-1', [], ['resourceType' => 'DiagnosticReport']);
        $this->assertEmpty($result['ownership_skip'] ?? null);
        $this->assertSame(['GET', 'PUT'], $this->calls);
    }

    public function testMedicationManufacturerNeverTriggersSkip(): void
    {
        // Medication has no reliable ownership field (manufacturer is the
        // drug company, always foreign) — the pre-check is skipped entirely,
        // no GET fires, and the PUT proceeds.
        $this->responses['put'] = [json_encode(['resourceType' => 'Medication']), 200, ''];

        $result = $this->client()->patch('/Medication/med-1', [], ['resourceType' => 'Medication']);
        $this->assertEmpty($result['ownership_skip'] ?? null);
        $this->assertSame(['PUT'], $this->calls);
    }

    public function testMissingReadScopeProceedsToPut(): void
    {
        $this->responses['get'] = ['{"resourceType":"OperationOutcome"}', 403, ''];
        $this->responses['put'] = [json_encode(['resourceType' => 'Encounter']), 200, ''];

        $result = $this->client()->patch('/Encounter/enc-2', [], ['resourceType' => 'Encounter']);
        $this->assertEmpty($result['ownership_skip'] ?? null);
        $this->assertSame(['GET', 'PUT'], $this->calls, 'cannot verify → the server decides');
    }

    public function testResourceGoneOnServerIsSkippedWithMessage(): void
    {
        $this->responses['get'] = ['{"resourceType":"OperationOutcome"}', 404, ''];
        $result = $this->client()->patch('/Encounter/enc-gone', [], ['resourceType' => 'Encounter']);
        $this->assertTrue(!empty($result['ownership_skip']));
        $this->assertStringContainsString('tidak ditemukan', $result['message']);
        $this->assertSame(['GET'], $this->calls);
    }
}
