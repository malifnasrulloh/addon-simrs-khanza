<?php

/**
 * SatuSehatDateTime — timezone-correct date/time parsing for SIMRS Khanza
 * data.
 *
 * Replaces the legacy sanitizeDateTime behavior:
 *   - parses wall-clock SIMRS values AS Asia/Jakarta (WIB) regardless of the
 *     server's timezone (a UTC server no longer mangles WIB evenings);
 *   - ACCEPTS future dates (vaccine expirationDate, pre-registered visits)
 *     instead of silently collapsing them to "now";
 *   - never invents timestamps: invalid/zero input yields null, and callers
 *     decide the fallback.
 *
 * @author malifnasrulloh
 */

declare(strict_types=1);

class SatuSehatDateTime
{
    public const DEFAULT_TZ = 'Asia/Jakarta';

    /**
     * Parse a SIMRS date(+time) pair into a DateTimeImmutable in $tz.
     * Values may already carry an explicit offset ("2026-08-08T10:30:00+07:00"
     * or "2026-08-08 10:30:00 +07:00"), which is honored.
     */
    public static function parse(?string $datePart, ?string $timePart = null, string $tz = self::DEFAULT_TZ): ?\DateTimeImmutable
    {
        $datePart = $datePart !== null ? trim($datePart) : '';
        $timePart = $timePart !== null ? trim($timePart) : '';

        // Explicit offset embedded in the value itself (checked BEFORE the
        // space-split so "2026-01-01 10:30:00 +07:00" parses directly).
        if (preg_match('/[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?\s*[+\-]\d{2}:\d{2}$/', $datePart) === 1) {
            try {
                return new \DateTimeImmutable($datePart);
            } catch (\Throwable $e) {
                return null;
            }
        }

        if ($timePart === '' && str_contains($datePart, ' ')) {
            $parts = explode(' ', $datePart, 2);
            $datePart = trim($parts[0]);
            $timePart = trim($parts[1]);
        }

        if ($datePart === ''
            || $datePart === '0000-00-00'
            || $datePart === '0000-00-00 00:00:00'
            || preg_match('/^0{4}-/', $datePart) === 1) {
            return null;
        }

        $zone = new \DateTimeZone($tz);

        $timeStr = ($timePart !== '' && $timePart !== '00:00:00') ? $timePart : '00:00:00';

        $candidates = [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $datePart) === 1) {
            $candidates[] = $datePart . ' ' . $timeStr; // Y-m-d H:i:s
            if ($timePart !== '' && $timePart !== '00:00:00') {
                $candidates[] = $datePart . 'T' . $timePart; // Y-m-d\TH:i:s
            }
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/', $datePart) === 1) {
            $candidates[] = $datePart;
        } else {
            return null; // unparseable garbage
        }

        foreach ($candidates as $candidate) {
            $parsed = \DateTimeImmutable::createFromFormat(
                str_contains($candidate, 'T') ? '!Y-m-d\TH:i:s' : '!Y-m-d H:i:s',
                $candidate,
                $zone
            );
            if ($parsed !== false) {
                return $parsed;
            }
        }
        return null;
    }

    /** Local WIB wall-clock formatting (FHIR instant without offset). */
    public static function formatLocal(\DateTimeImmutable $dt, bool $dateOnly = false): string
    {
        return $dateOnly ? $dt->format('Y-m-d') : $dt->format('Y-m-d\TH:i:s');
    }
}
