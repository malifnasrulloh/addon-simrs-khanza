<?php

declare(strict_types=1);

namespace SatusehatPanel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * PHI scrubbing: NIKs in key:value form, pipe-form (IHS lookup URLs) and
 * standalone 16-digit sequences must never reach logs/audit in plaintext.
 */
final class PhiiScrubbingTest extends TestCase
{
    public function testKeyValueFormIsMasked(): void
    {
        $out = \Logger::scrubSensitiveData('{"nik": "3333061211890001"}');
        $this->assertStringNotContainsString('3333061211890001', $out);
        $this->assertStringContainsString('nik', $out);
    }

    public function testIhsLookupUrlPipeFormIsMasked(): void
    {
        $out = \Logger::scrubSensitiveData('GET https://api-satusehat.kemkes.go.id/fhir-r4/v1/Patient?identifier=https://fhir.kemkes.go.id/id/nik|3333061211890001');
        $this->assertStringNotContainsString('3333061211890001', $out);
        $this->assertStringContainsString('3333', $out, 'first 4 digits remain for traceability');
    }

    public function testStandaloneSixteenDigitNikIsMasked(): void
    {
        $out = \Logger::scrubSensitiveData('no_ktp 3333061211890001 end');
        $this->assertStringNotContainsString('3333061211890001', $out);
    }

    public function testAuditBundlePayloadRedaction(): void
    {
        $bundle = [
            'resourceType' => 'Bundle',
            'entry' => [
                ['resource' => ['resourceType' => 'Patient', 'identifier' => ['value' => '3333061211890001']]],
            ],
        ];
        $out = \Logger::scrubSensitiveData(json_encode($bundle));
        $this->assertStringNotContainsString('3333061211890001', $out);
    }
}