<?php

declare(strict_types=1);

namespace SatusehatPanel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Client transport rules (the A3 fix): only HTTP 429 is retried; timeouts
 * and empty responses are surfaced as `network_unknown`-class failures with
 * NO automatic re-POST — re-sending after a timeout would duplicate the
 * bundle (rule 20002). Uses the new transport seam, no network.
 */
final class ClientTransportTest extends TestCase
{
    private string $logDir;
    private int $calls = 0;
    private array $responses = [];

    protected function setUp(): void
    {
        $this->logDir = PANEL_TEST_STORAGE . '/logs-' . uniqid();
        mkdir($this->logDir, 0755, true);
        // Seed a valid token cache so getToken() never hits the network.
        file_put_contents(
            $this->logDir . '/satusehat_token.json',
            json_encode(['token' => 'test-token', 'expires_at' => time() + 3600])
        );
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
            'SATUSEHAT_ORG_ID' => '1000000001',
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
            $this->calls++;
            return $this->responses;
        };
        return $client;
    }

    public function testTimeoutIsNotRetried(): void
    {
        $this->calls = 0;
        $this->responses = ['', 0, 'Operation timed out after 30001 milliseconds'];
        $result = $this->client()->post('/', ['resourceType' => 'Bundle']);
        $this->assertSame(1, $this->calls, 'a timeout must NOT trigger an automatic re-POST');
        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('cURL error', $result['message']);
    }

    public function testHttp429IsRetriedWithBackoffThenSurfaces(): void
    {
        $this->calls = 0;
        $this->responses = ['{"resourceType":"OperationOutcome","issue":[{"severity":"error"}]}', 429, ''];
        $result = $this->client()->post('/', ['resourceType' => 'Bundle']);
        $this->assertSame(3, $this->calls);
        $this->assertFalse($result['success']);
        $this->assertSame(429, $result['code']);
    }

    public function testHttp500IsNotRetried(): void
    {
        $this->calls = 0;
        $this->responses = ['{"resourceType":"OperationOutcome"}', 503, ''];
        $result = $this->client()->post('/', ['resourceType' => 'Bundle']);
        $this->assertSame(1, $this->calls, '5xx is uncertain, never auto-retried');
        $this->assertFalse($result['success']);
        $this->assertSame(503, $result['code']);
    }

    public function testHttp400IsNotRetried(): void
    {
        $this->calls = 0;
        $this->responses = ['{"resourceType":"OperationOutcome","issue":[{"severity":"error","code":"processing"}]}', 400, ''];
        $result = $this->client()->post('/', ['resourceType' => 'Bundle']);
        $this->assertSame(1, $this->calls);
        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['code']);
    }

    public function testSuccessPassesThrough(): void
    {
        $this->calls = 0;
        $this->responses = ['{"resourceType":"Bundle","type":"transaction-response","entry":[{"fullUrl":"urn:uuid:abc","resource":{"id":"enc-1"}}]}', 200, ''];
        $result = $this->client()->post('/', ['resourceType' => 'Bundle']);
        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['code']);
        $this->assertSame('enc-1', $result['data']['entry'][0]['resource']['id']);
    }
}