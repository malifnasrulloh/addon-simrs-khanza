<?php

declare(strict_types=1);

namespace SatusehatPanel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Outcome classification (RuleNumber-first, replacing the keyword hacks):
 * the exact OperationOutcome shapes seen in the server logs must map to the
 * right terminal states — and clinical text like "Inform consent" must never
 * be treated as a privacy error.
 */
final class OutcomeClassificationTest extends TestCase
{
    private static function outcome(string $msg, int $code = 400): array
    {
        return [
            'success' => false,
            'code' => $code,
            'data' => ['issue' => [['severity' => 'error', 'details' => ['text' => $msg]]]],
            'message' => 'API Error',
        ];
    }

    public function testRuleNumberExtractionFromDetailsText(): void
    {
        // The server log showed the message lives in details.text while the
        // old processors only read diagnostics → "API Error" → no terminal
        // state → infinite retry (the 512-statement loop).
        $this->assertSame(
            'Invalid coding system:  (RuleNumber: 10480)',
            \SatuSehatClient::extractErrorMsg(self::outcome('Invalid coding system:  (RuleNumber: 10480)'))
        );
    }

    public function testTerminologyRulesAreInvalidCode(): void
    {
        $this->assertSame('invalid_code', \SatuSehatClient::classifyError(self::outcome('Invalid coding system:  (RuleNumber: 10480)')));
        $this->assertSame('invalid_code', \SatuSehatClient::classifyError(self::outcome("Code not found: 'Topical' in system: http://www.whocc.no/atc (RuleNumber: 10056)")));
    }

    public function testDuplicateIsDetectedBeforeRuleRange(): void
    {
        $this->assertSame('duplicate', \SatuSehatClient::classifyError(self::outcome('Found duplicate: Observation (RuleNumber: 20002)')));
        $this->assertSame('duplicate', \SatuSehatClient::classifyError(self::outcome('Conflict', 409)));
    }

    public function testPermissionRulesAreFailedRule(): void
    {
        $this->assertSame('failed_rule', \SatuSehatClient::classifyError(self::outcome('Failed to get permission for client for resource type (RuleNumber: 20003)')));
    }

    public function testClinicalConsentTextIsNotPrivacy(): void
    {
        // CarePlan descriptions contain "Inform consent" — must NOT classify
        // as privacy_error (the old keyword hack did).
        $this->assertSame('generic', \SatuSehatClient::classifyError(self::outcome('description: Acc pembiusan asa 2<br>Inform consent<br>Puasa 6 jam preop')));
    }

    public function testRealPrivacyDenialsStillDetected(): void
    {
        $this->assertSame('privacy_error', \SatuSehatClient::classifyError(self::outcome('privacy: one or more codes not allowed by access rules')));
    }

    public function testGenericErrorsStayRetryable(): void
    {
        $this->assertSame('generic', \SatuSehatClient::classifyError(self::outcome('server exploded')));
    }
}
