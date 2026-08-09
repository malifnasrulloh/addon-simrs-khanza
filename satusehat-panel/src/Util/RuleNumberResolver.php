<?php

declare(strict_types=1);

namespace SatusehatPanel\Util;

/**
 * RuleNumberResolver — resolves SATUSEHAT rule numbers to the official
 * Indonesian descriptions (config/rule_numbers.php, generated from the
 * published error dictionary by scripts/import-rule-numbers.php).
 */
final class RuleNumberResolver
{
    /** @var array<int,string>|null */
    private static ?array $rules = null;

    /** @return array<int,string> */
    public static function all(): array
    {
        if (self::$rules === null) {
            $path = __DIR__ . '/../../config/rule_numbers.php';
            self::$rules = is_file($path) ? (require $path) : [];
        }
        return self::$rules;
    }

    public static function message(int $ruleNumber): ?string
    {
        $rules = self::all();
        return $rules[$ruleNumber] ?? null;
    }

    /** Human-readable line for logs/UI: "10403 — Data harus menggunakan ..." */
    public static function describe(int $ruleNumber): string
    {
        $msg = self::message($ruleNumber);
        return $msg !== null ? "RuleNumber {$ruleNumber}: {$msg}" : "RuleNumber {$ruleNumber}";
    }
}