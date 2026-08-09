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

    public function testTokenFetchWritesValidCacheOnce(): void
    {
        // Regression: the token-fetch path used its own curl block (not the
        // transport seam) and once wrote json_encode(null) into the cache —
        // "Undefined variable $cacheData" on line ~189. This test drives the
        // FULL auth+send path through the seam with NO pre-seeded cache.
        $authCalls = 0;
        $this->calls = 0;
        $this->logDir = PANEL_TEST_STORAGE . '/logs-tok-' . uniqid();
        mkdir($this->logDir, 0755, true);

        $client = $this->client();
        $client->transport = function (string $url, string $method, array $headers, ?string $body) use (&$authCalls): array {
            if (str_contains($url, '/accesstoken')) {
                $authCalls++;
                return ['{"access_token":"fresh-token-1","expires_in":3600}', 200, ''];
            }
            $this->calls++;
            return ['{"resourceType":"Bundle","type":"transaction-response","entry":[]}', 200, ''];
        };

        $result = $client->post('/', ['resourceType' => 'Bundle']);
        $this->assertTrue($result['success']);

        // Exactly one token fetch: the second send reuses the cache.
        $result2 = $client->post('/', ['resourceType' => 'Bundle']);
        $this->assertTrue($result2['success']);
        $this->assertSame(1, $authCalls, 'token must be fetched exactly once across two sends');

        // Cache file holds a real token + expiry and is 0600.
        $cache = json_decode((string) file_get_contents($this->logDir . '/satusehat_token.json'), true);
        $this->assertSame('fresh-token-1', $cache['token'] ?? null);
        $this->assertGreaterThan(time(), (int) ($cache['expires_at'] ?? 0));
        $perms = fileperms($this->logDir . '/satusehat_token.json') & 0777;
        $this->assertSame(0600, $perms);
    }
}