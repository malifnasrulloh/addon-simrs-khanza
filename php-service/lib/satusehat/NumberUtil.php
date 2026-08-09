<?php

/**
 * SatuSehatNumber — locale-safe numeric parsing for SIMRS Khanza values.
 *
 * Indonesian data entry commonly stores comma decimals ("36,5"), fractions
 * ("1/2"), and mixed forms ("1 1/2"). (float)"36,5" yields 36.0 — silently
 * corrupting vitals and doses — so every numeric conversion goes through
 * this helper.
 *
 * @author malifnasrulloh
 */

declare(strict_types=1);

class SatuSehatNumber
{
    /**
     * Parse a value into a float, or null when unparseable.
     *   "36,5" -> 36.5 ; "36.5" -> 36.5 ; "1/2" -> 0.5 ; "1 1/2" -> 1.5
     */
    public static function parse($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $s = trim((string) $value);
        if ($s === '' || $s === '-') {
            return null;
        }

        // Fraction forms first: "1/2", "1 1/2", "1½"
        if (preg_match('~^(\d+(?:[.,]\d+)?)\s*/\s*(\d+(?:[.,]\d+)?)$~u', $s, $m)) {
            $num = self::parse($m[1]);
            $den = self::parse($m[2]);
            if ($num === null || $den === null || $den == 0.0) {
                return null;
            }
            return $num / $den;
        }
        if (preg_match('~^(\d+)\s+(\d+)\s*/\s*(\d+)$~u', $s, $m)) {
            return (int) $m[1] + ((int) $m[2] / (int) $m[3]);
        }
        if (str_contains($s, '½')) {
            $s = str_replace(['½', '¼', '¾'], ['.5', '.25', '.75'], $s);
        }

        // Comma decimal: only when it looks like the Indonesian decimal
        // separator (a single comma with digits after it).
        if (preg_match('/^[+-]?\d+,\d+$/', $s) === 1) {
            $s = str_replace(',', '.', $s);
        }

        // Strip any stray unit/garbage suffix (e.g. "80 kg", "36,5 °C").
        if (preg_match('/^[+-]?(?:\d+(?:\.\d+)?|\.\d+)/', $s, $m2)) {
            $s = $m2[0];
        }

        if (!is_numeric($s)) {
            return null;
        }
        return (float) $s;
    }
}
