<?php

declare(strict_types=1);

namespace SatusehatPanel\Util;

/**
 * EntryOutcomeClassifier — per-entry outcome of a FHIR transaction Bundle
 * response. A bundle can return HTTP 200 while individual entries fail with
 * per-entry OperationOutcome issues; the classifier extracts the truth per
 * entry and extracts the official SATUSEHAT RuleNumber from diagnostics.
 */
final class EntryOutcomeClassifier
{
    public const SENT = 'sent';
    public const FAILED_RULE = 'failed_rule';
    public const INVALID_CODE = 'invalid_code';
    public const PRIVACY_ERROR = 'privacy_error';
    public const FAILED = 'failed';
    public const NETWORK_UNKNOWN = 'network_unknown';

    /**
     * Classify one response entry (a FHIR Bundle.entry of a transaction
     * response) into a status + optional rule number + issue text.
     *
     * @param array $respEntry decoded FHIR response entry
     * @return array{status:string, rule_number:?int, issue_text:?string, satusehat_id:?string}
     */
    public static function classify(array $respEntry): array
    {
        $status = self::SENT;
        $ruleNumber = null;
        $issueText = null;
        $id = isset($respEntry['resource']['id']) ? (string) $respEntry['resource']['id'] : null;

        $httpStatus = (string) ($respEntry['response']['status'] ?? '');
        $outcome = $respEntry['response']['outcome'] ?? null;

        if ($httpStatus !== '' && ((int) $httpStatus) >= 400) {
            $status = self::FAILED;
        }

        if (is_array($outcome) && !empty($outcome['issue'])) {
            $issues = is_array($outcome['issue']) && isset($outcome['issue'][0])
                ? $outcome['issue']
                : [$outcome['issue']];

            $issue = $issues[0] ?? [];
            $severity = (string) ($issue['severity'] ?? '');
            $code = (string) ($issue['code'] ?? '');
            $diagnostics = (string) ($issue['diagnostics'] ?? '');
            $text = (string) ($issue['details']['text'] ?? '');
            $issueText = $text !== '' ? $text : $diagnostics;

            // RuleNumber from diagnostics: "RuleNumber 10403" or "10403"
            if (preg_match('/RuleNumber\s*(\d{3,6})/', $diagnostics, $m)) {
                $ruleNumber = (int) $m[1];
            } elseif (preg_match('/(?:^|\s)(10\d{3,5}|20\d{3,5}|100\d{3})(?:\s|$)/', $diagnostics, $m2)) {
                $ruleNumber = (int) $m2[1];
            }

            if ($severity === 'error' || $severity === 'fatal' || $code === 'processing' || $code === 'invalid') {
                $status = self::FAILED;
            }
            if ($ruleNumber !== null) {
                $status = self::FAILED_RULE;
            }
            if (stripos($code, 'code-invalid') !== false || stripos($diagnostics, 'invalid code') !== false) {
                $status = self::INVALID_CODE;
            }
            if (stripos($code, 'forbidden') !== false
                || stripos($diagnostics, 'privacy') !== false
                || stripos($diagnostics, 'permission') !== false) {
                $status = self::PRIVACY_ERROR;
            }
        }

        if ($issueText === null && $status !== self::SENT) {
            $issueText = "HTTP {$httpStatus}";
        }

        return [
            'status'        => $status,
            'rule_number'   => $ruleNumber,
            'issue_text'    => $issueText,
            'satusehat_id'  => $id,
        ];
    }

    /**
     * Summarize a full set of entry classifications.
     *
     * @param array<int,array> $classifications
     * @return array{all_sent:bool, any_failed:bool, status:'success'|'failed'|'partial'}
     */
    public static function summarize(array $classifications): array
    {
        $sent = 0;
        $failed = 0;
        foreach ($classifications as $c) {
            if ($c['status'] === self::SENT) {
                $sent++;
            } else {
                $failed++;
            }
        }
        $allSent = $failed === 0;
        return [
            'all_sent'  => $allSent,
            'any_failed' => $failed > 0,
            'status'    => $allSent ? 'success' : ($sent === 0 ? 'failed' : 'partial'),
        ];
    }
}