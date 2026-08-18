<?php

declare(strict_types=1);

namespace SatusehatPanel\Tests;

use PHPUnit\Framework\TestCase;
use SatusehatPanel\Core\Routes;
use SatusehatPanel\Core\Router;

/**
 * Route-order regression (T35): literal routes must win over catch-alls.
 * /api/audit/stats and /api/audit/export are shadowed if a catch-all like
 * /api/audit/{id} is registered first — first-match-wins makes ORDER the
 * contract. Uses the production registration (Routes::register), not a copy.
 */
final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
        Routes::register($this->router);
    }

    private function resolve(string $method, string $uri): ?string
    {
        $match = $this->router->matchRoute($method, $uri);
        return $match['path'] ?? null;
    }

    public function testAuditStatsNotShadowedByCatchAllId(): void
    {
        $this->assertSame('/api/audit/stats', $this->resolve('GET', '/api/audit/stats'));
    }

    public function testAuditExportNotShadowedByCatchAllId(): void
    {
        $this->assertSame('/api/audit/export', $this->resolve('GET', '/api/audit/export'));
    }

    public function testAuditIdStillMatchesNumericId(): void
    {
        $this->assertSame('/api/audit/{id}', $this->resolve('GET', '/api/audit/42'));
    }

    public function testAuditListMatchesBarePath(): void
    {
        $this->assertSame('/api/audit', $this->resolve('GET', '/api/audit'));
    }

    public function testPatientSubroutesMoreSpecificThanNoRawatCatchAll(): void
    {
        $this->assertSame(
            '/api/patients/{noRawat:any}/resources/{resource}',
            $this->resolve('GET', '/api/patients/V-1/resources/Encounter')
        );
        $this->assertSame(
            '/api/patients/{noRawat:any}/send',
            $this->resolve('POST', '/api/patients/V-1/send')
        );
        $this->assertSame(
            '/api/patients/{noRawat:any}',
            $this->resolve('GET', '/api/patients/V-1')
        );
        $this->assertSame('/api/patients', $this->resolve('GET', '/api/patients'));
    }

    public function testUnknownRouteIsNull(): void
    {
        // /api/auth/login is POST-only and no GET catch-all shadows it.
        $this->assertNull($this->resolve('GET', '/api/auth/login'));
        $this->assertNull($this->resolve('DELETE', '/api/audit/42'));
        $this->assertNull($this->resolve('GET', '/api/nonexistent'));
        $this->assertNull($this->resolve('GET', '/'));
    }

    public function testMultiSegmentNoRawatWithSlash(): void
    {
        $match = $this->router->matchRoute('GET', '/api/patients/V-1/2026/resources/Observation');
        $this->assertSame('/api/patients/{noRawat:any}/resources/{resource}', $match['path']);
        $this->assertSame('V-1/2026', $match['params']['noRawat']);
        $this->assertSame('Observation', $match['params']['resource']);
    }

    public function testDispatchSmokeBindsStatsToRealHandler(): void
    {
        // Dispatch-level proof: /api/audit/stats must reach AuditController::
        // stats (SQLite-backed, no MySQL) and emit its JSON shape. Runs in a
        // child process — dispatch() sets HTTP headers, which would trip
        // PHPUnit's already-sent-output check in-process.
        $dbPath = PANEL_TEST_STORAGE . '/router-' . uniqid() . '.db';
        $script = PANEL_TEST_STORAGE . '/router-dispatch-' . uniqid() . '.php';
        $panelBase = var_export(PANEL_BASE, true);
        file_put_contents($script, sprintf(<<<'PHP'
<?php
define('PANEL_BASE', %s);
define('PANEL_SRC', PANEL_BASE . '/src');
define('BASE_DIR', PANEL_BASE);
require PANEL_BASE . '/vendor/autoload.php';
\SatusehatPanel\Core\Database::setSqlitePathForTesting($argv[1]);
$_SERVER['REQUEST_METHOD'] = 'GET';
$router = new \SatusehatPanel\Core\Router();
\SatusehatPanel\Core\Routes::register($router);
$router->setRequestUri('/api/audit/stats');
$router->dispatch();
PHP, $panelBase));

        $output = shell_exec(PHP_BINARY . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($dbPath) . ' 2>&1');
        @unlink($script);
        @unlink($dbPath);

        $this->assertIsString($output);
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'dispatch must emit JSON, got: ' . var_export($output, true));
        $this->assertTrue($decoded['success'] ?? false);
        $this->assertArrayHasKey('top_rules', $decoded['data'] ?? []);
    }
}