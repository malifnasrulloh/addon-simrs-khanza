<?php

declare(strict_types=1);

namespace SatusehatPanel\Util;

/**
 * Queue for build-time warnings surfaced from PayloadAdapter (e.g. a
 * radiology Observation skipped because its ImagingStudy id is missing).
 * SendController drains these into the send response's `warnings` list —
 * advisory only; they do NOT mark the send as failed.
 */
final class PayloadAdapterWarnings
{
    private static array $warnings = [];

    public static function add(string $message): void
    {
        self::$warnings[] = $message;
    }

    /** Drain and reset. */
    public static function take(): array
    {
        $w = self::$warnings;
        self::$warnings = [];
        return $w;
    }
}