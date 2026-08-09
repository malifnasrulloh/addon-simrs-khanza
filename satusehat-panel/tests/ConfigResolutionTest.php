<?php

declare(strict_types=1);

namespace SatusehatPanel\Tests;

use PHPUnit\Framework\TestCase;
use CredentialLocator;

/**
 * Credential resolution contract: .env wins when non-empty, otherwise the
 * Settings JSON; neither → controlled RuntimeException; NO temp env file is
 * ever written (the A4 dead-end + temp-file leak must stay fixed).
 */
final class ConfigResolutionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = PANEL_TEST_STORAGE . '/creds-' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        CredentialLocator::setPathsForTesting(null, null);
        @array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    private function writeEnv(array $lines): string
    {
        $path = $this->dir . '/.env';
        file_put_contents($path, implode("\n", $lines) . "\n");
        return $path;
    }

    private function writeJson(array $data): string
    {
        $path = $this->dir . '/satusehat_credential.json';
        file_put_contents($path, json_encode($data));
        return $path;
    }

    public function testEnvOnlySource(): void
    {
        $env = $this->writeEnv([
            'DB_HOST=localhost',
            'DB_NAME=sik',
            'DB_USER=root',
            'SATUSEHAT_ORG_ID=1000000001',
            'SATUSEHAT_CLIENT_ID=env-client',
            'SATUSEHAT_SECRET_KEY=env-secret',
            'SATUSEHAT_AUTH_URL=https://api-satusehat-stg.dto.kemkes.go.id/oauth2/v1',
            'SATUSEHAT_BASE_URL=https://api-satusehat-stg.dto.kemkes.go.id/fhir-r4/v1',
        ]);
        CredentialLocator::setPathsForTesting($env, null);

        $config = CredentialLocator::buildSatuSehatConfig();
        $this->assertSame('env-client', $config->clientId);
        $this->assertSame('1000000001', $config->orgId);
        $this->assertTrue($config->verifyTls, 'panel defaults to TLS verification');
    }

    public function testJsonOnlySource(): void
    {
        $json = $this->writeJson([
            'organization_id' => '2000000002',
            'client_id'       => 'json-client',
            'client_secret'   => 'json-secret',
            'environment'     => 'production',
        ]);
        CredentialLocator::setPathsForTesting(null, $json);

        $config = CredentialLocator::buildSatuSehatConfig();
        $this->assertSame('json-client', $config->clientId);
        $this->assertSame('json-secret', $config->secretKey);
        $this->assertStringStartsWith('https://api-satusehat.kemkes.go.id', $config->baseUrl);
        $this->assertTrue($config->verifyTls);
    }

    public function testEnvPresentButEmptyCredsUsesJson(): void
    {
        // The A4 dead-end: .env exists (DB creds) but SATUSEHAT keys are
        // empty — previously this threw an uncaught 500 on every send.
        $env = $this->writeEnv([
            'DB_HOST=localhost',
            'DB_NAME=sik',
            'DB_USER=root',
            'SATUSEHAT_ORG_ID=',
            'SATUSEHAT_CLIENT_ID=',
            'SATUSEHAT_SECRET_KEY=',
        ]);
        $json = $this->writeJson([
            'organization_id' => '3000000003',
            'client_id'       => 'json-client',
            'client_secret'   => 'json-secret',
            'environment'     => 'sandbox',
        ]);
        CredentialLocator::setPathsForTesting($env, $json);

        $config = CredentialLocator::buildSatuSehatConfig();
        $this->assertSame('json-client', $config->clientId);
        $this->assertSame('3000000003', $config->orgId);
        $this->assertStringStartsWith('https://api-satusehat-stg.dto.kemkes.go.id', $config->baseUrl);
    }

    public function testEnvFilledWinsOverJson(): void
    {
        $env = $this->writeEnv([
            'SATUSEHAT_ORG_ID=4000000004',
            'SATUSEHAT_CLIENT_ID=env-wins',
            'SATUSEHAT_SECRET_KEY=env-secret',
        ]);
        $json = $this->writeJson([
            'organization_id' => '9999999999',
            'client_id'       => 'json-should-lose',
            'client_secret'   => 'json-secret',
        ]);
        CredentialLocator::setPathsForTesting($env, $json);

        $config = CredentialLocator::buildSatuSehatConfig();
        $this->assertSame('env-wins', $config->clientId);
    }

    public function testNeitherSourceThrowsControlledException(): void
    {
        CredentialLocator::setPathsForTesting(null, null);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Kredensial SATUSEHAT/');
        CredentialLocator::buildSatuSehatConfig();
    }

    public function testBuildWritesNoTempEnvFile(): void
    {
        $json = $this->writeJson([
            'organization_id' => '1000000001',
            'client_id'       => 'c',
            'client_secret'   => 's',
            'environment'     => 'sandbox',
        ]);
        CredentialLocator::setPathsForTesting(null, $json);

        CredentialLocator::buildSatuSehatConfig();

        $this->assertFileDoesNotExist(PANEL_BASE . '/storage/.satusehat_env.tmp');
        $this->assertSame([], glob(PANEL_BASE . '/storage/.satusehat_env.tmp*') ?: []);
    }
}
