<?php

declare(strict_types=1);

/**
 * Lightweight test runner for satusehat-panel test suite.
 * Runs PHPUnit TestCase tests via Reflection, without requiring
 * ext-dom or ext-xmlwriter on developer/CI environments where
 * only core PHP CLI is installed.
 */

require_once __DIR__ . '/../tests/bootstrap.php';

$testFiles = glob(__DIR__ . '/../tests/*Test.php');
sort($testFiles);

$totalPass = 0;
$totalFail = 0;
$totalSkipped = 0;
$failures = [];

echo "========================================================\n";
echo "SATUSEHAT Panel Test Suite\n";
echo "========================================================\n\n";

foreach ($testFiles as $file) {
    require_once $file;
    $classes = get_declared_classes();
    $testClass = null;
    foreach ($classes as $c) {
        if (str_starts_with($c, 'SatusehatPanel\\Tests\\') && is_subclass_of($c, 'PHPUnit\\Framework\\TestCase')) {
            $ref = new ReflectionClass($c);
            if (!$ref->isAbstract() && $ref->getFileName() === realpath($file)) {
                $testClass = $c;
                break;
            }
        }
    }
    if (!$testClass) {
        continue;
    }

    $ref = new ReflectionClass($testClass);
    $methods = array_filter(
        $ref->getMethods(ReflectionMethod::IS_PUBLIC),
        fn(ReflectionMethod $m) => str_starts_with($m->getName(), 'test')
    );

    if (empty($methods)) {
        continue;
    }

    echo "▶ " . basename($file) . "\n";

    $setUp = $ref->hasMethod('setUp') ? $ref->getMethod('setUp') : null;
    $tearDown = $ref->hasMethod('tearDown') ? $ref->getMethod('tearDown') : null;

    foreach ($methods as $m) {
        $methodName = $m->getName();
        try {
            $t = new $testClass($methodName);
            if ($setUp) {
                $setUp->setAccessible(true);
                $setUp->invoke($t);
            }
            $m->invoke($t);
            if ($tearDown) {
                $tearDown->setAccessible(true);
                $tearDown->invoke($t);
            }
            // If test expected an exception and none was thrown, fail
            if (isset($t) && method_exists($t, 'getExpectedException') && $t->getExpectedException() !== null) {
                throw new \AssertionError("Failed asserting that exception of type \"{$t->getExpectedException()}\" is thrown.");
            }
            echo "    ✓ {$methodName}\n";
            $totalPass++;
        } catch (\Throwable $e) {
            // Check if this exception was expected by PHPUnit
            if (isset($t) && method_exists($t, 'getExpectedException') && $t->getExpectedException() !== null) {
                $expected = $t->getExpectedException();
                if ($e instanceof $expected) {
                    echo "    ✓ {$methodName} (expected exception caught)\n";
                    $totalPass++;
                    continue;
                }
            }

            // Check if sqlite driver is missing (environment limitation)
            if (str_contains($e->getMessage(), 'could not find driver')) {
                echo "    ⊘ {$methodName} (skipped: SQLite PDO driver not loaded in CLI)\n";
                $totalSkipped++;
            } else {
                echo "    ✗ {$methodName}: {$e->getMessage()}\n";
                $totalFail++;
                $failures[] = [
                    'class'   => $testClass,
                    'method'  => $methodName,
                    'message' => $e->getMessage(),
                ];
            }
        }
    }
    echo "\n";
}

echo "========================================================\n";
echo sprintf("Summary: %d passed, %d failed, %d skipped\n", $totalPass, $totalFail, $totalSkipped);
echo "========================================================\n";

if ($totalFail > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - {$f['class']}::{$f['method']}: {$f['message']}\n";
    }
    exit(1);
}

exit(0);
