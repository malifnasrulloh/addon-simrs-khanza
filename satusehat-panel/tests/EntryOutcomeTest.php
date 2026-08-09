<?php

declare(strict_types=1);

namespace SatusehatPanel\Tests;

use PHPUnit\Framework\TestCase;
use SatusehatPanel\Util\EntryOutcomeClassifier;

/**
 * Per-entry outcome classification: HTTP 200 with failed entries must be
 * detected (the false-success bug), RuleNumbers must be extracted, and the
 * bundle-level status must become 'partial'.
 */
final class EntryOutcomeTest extends TestCase
{
    public function testAllSentBundleIsSuccess(): void
    {
        $classifications = [
            EntryOutcomeClassifier::classify([
                'response' => ['status' => '201'],
                'resource' => ['id' => 'id-1'],
            ]),
            EntryOutcomeClassifier::classify([
                'response' => ['status' => '200'],
                'resource' => ['id' => 'id-2'],
            ]),
        ];
        $summary = EntryOutcomeClassifier::summarize($classifications);
        $this->assertTrue($summary['all_sent']);
        $this->assertSame('success', $summary['status']);
        $this->assertNull($classifications[0]['rule_number']);
    }

    public function testHttp200WithFailedEntryIsPartial(): void
    {
        // The exact failure mode of the A1 bug: HTTP 200 overall, one entry
        // rejected with an OperationOutcome.
        $classifications = [
            EntryOutcomeClassifier::classify([
                'response' => ['status' => '201'],
                'resource' => ['id' => 'ok-1'],
            ]),
            EntryOutcomeClassifier::classify([
                'response' => [
                    'status' => '400',
                    'outcome' => [
                        'issue' => [[
                            'severity' => 'error',
                            'code' => 'processing',
                            'diagnostics' => 'RuleNumber 10403 SATUSEHAT Error',
                            'details' => ['text' => 'Data kondisi tidak ditemukan'],
                        ]],
                    ],
                ],
            ]),
        ];
        $summary = EntryOutcomeClassifier::summarize($classifications);
        $this->assertFalse($summary['all_sent']);
        $this->assertSame('partial', $summary['status']);

        $failed = $classifications[1];
        $this->assertSame('failed_rule', $failed['status']);
        $this->assertSame(10403, $failed['rule_number']);
        $this->assertSame('Data kondisi tidak ditemukan', $failed['issue_text']);
        $this->assertNull($failed['satusehat_id']);
    }

    public function testAllFailedBundleIsFailed(): void
    {
        $classifications = [
            EntryOutcomeClassifier::classify([
                'response' => ['status' => '400', 'outcome' => ['issue' => [['severity' => 'error', 'code' => 'processing']]]],
            ]),
            EntryOutcomeClassifier::classify([
                'response' => ['status' => '422', 'outcome' => ['issue' => [['severity' => 'error', 'code' => 'invalid']]]],
            ]),
        ];
        $this->assertSame('failed', EntryOutcomeClassifier::summarize($classifications)['status']);
    }

    public function testRuleNumberVariants(): void
    {
        $c = EntryOutcomeClassifier::classify([
            'response' => ['status' => '400', 'outcome' => ['issue' => [[
                'severity' => 'error',
                'code' => 'processing',
                'diagnostics' => 'Code: 10403',
            ]]]],
        ]);
        $this->assertSame(10403, $c['rule_number']);

        $c2 = EntryOutcomeClassifier::classify([
            'response' => ['status' => '400', 'outcome' => ['issue' => [[
                'severity' => 'error',
                'code' => 'processing',
                'diagnostics' => 'rule number: 20002',
            ]]]],
        ]);
        $this->assertSame(20002, $c2['rule_number']);
    }

    public function testInvalidCodeAndPrivacyHints(): void
    {
        $c = EntryOutcomeClassifier::classify([
            'response' => ['status' => '400', 'outcome' => ['issue' => [[
                'severity' => 'error',
                'code' => 'code-invalid',
                'diagnostics' => 'invalid code value',
            ]]]],
        ]);
        $this->assertSame('invalid_code', $c['status']);

        $c2 = EntryOutcomeClassifier::classify([
            'response' => ['status' => '403', 'outcome' => ['issue' => [[
                'severity' => 'error',
                'code' => 'forbidden',
                'diagnostics' => 'privacy: one or more codes not allowed',
            ]]]],
        ]);
        $this->assertSame('privacy_error', $c2['status']);
    }

    public function testMissingOutcomeWithErrorStatusClassifiesFailed(): void
    {
        $c = EntryOutcomeClassifier::classify(['response' => ['status' => '500']]);
        $this->assertSame('failed', $c['status']);
        $this->assertSame('HTTP 500', $c['issue_text']);
    }
}