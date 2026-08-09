<?php

declare(strict_types=1);

namespace SatusehatPanel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The B3 fixes: WIB wall-clock parsing on UTC servers, future dates allowed
 * (vaccine expirationDate etc.), zero-dates → null, and sanitizeDateTime
 * never inventing "now" timestamps.
 */
final class DateTimeUtilTest extends TestCase
{
    public function testFutureDateIsAccepted(): void
    {
        // The 10307 bug: a vaccine expiring next month must NOT collapse.
        $dt = \SatuSehatDateTime::parse('2027-03-01', '00:00:00');
        $this->assertNotNull($dt);
        $this->assertSame('2027-03-01T00:00:00+07:00', \SatuSehatPayloadBuilder::sanitizeDateTime('2027-03-01', '00:00:00', []));
    }

    public function testZeroDatesReturnNull(): void
    {
        $this->assertNull(\SatuSehatDateTime::parse('0000-00-00', '10:00:00'));
        $this->assertNull(\SatuSehatDateTime::parse(null, null));
        $this->assertNull(\SatuSehatDateTime::parse('', ''));
        $this->assertNull(\SatuSehatDateTime::parse('garbage', null));
    }

    public function testWallClockParsedAsWibInEveryServerTimezone(): void
    {
        $before = date_default_timezone_get();
        foreach (['UTC', 'Asia/Jakarta', 'America/New_York'] as $tz) {
            date_default_timezone_set($tz);
            $dt = \SatuSehatDateTime::parse('2026-08-08', '23:30:00');
            $this->assertNotNull($dt);
            $this->assertSame(
                '2026-08-08T23:30:00+07:00',
                \SatuSehatPayloadBuilder::sanitizeDateTime('2026-08-08', '23:30:00', []),
                "server tz {$tz} must not mangle WIB wall-clock"
            );
        }
        date_default_timezone_set($before);
    }

    public function testExplicitOffsetValuesAreHonored(): void
    {
        $dt = \SatuSehatDateTime::parse('2026-08-08T10:30:00+07:00');
        $this->assertNotNull($dt);
        $this->assertSame('2026-08-08T10:30:00+07:00', \SatuSehatDateTime::formatLocal($dt) . '+07:00');
    }

    public function testDateOnlyForms(): void
    {
        $this->assertSame('2026-08-08', \SatuSehatPayloadBuilder::sanitizeDateTime('2026-08-08', null, [], [], true));
    }

    public function testRegistrationFallbackInsteadOfNow(): void
    {
        // No date parts at all: falls back to the registration date, never
        // invents "now" (the A16 bug).
        $row = ['tgl_registrasi' => '2026-08-05', 'jam_reg' => '09:00:00'];
        $this->assertSame('2026-08-05T09:00:00+07:00', \SatuSehatPayloadBuilder::sanitizeDateTime('', '', $row));
        // Zero registrations too: empty string rather than a fabricated ts.
        $this->assertSame('', \SatuSehatPayloadBuilder::sanitizeDateTime('0000-00-00', '00:00:00', ['tgl_registrasi' => '0000-00-00']));
    }

    public function testFallbackPreferencesChain(): void
    {
        $row = ['tgl_perawatan' => '0000-00-00', 'jam_rawat' => '00:00:00', 'tgl_masuk' => '2026-08-06', 'jam_masuk' => '07:30:00'];
        $this->assertSame(
            '2026-08-06T07:30:00+07:00',
            \SatuSehatPayloadBuilder::sanitizeDateTime('0000-00-00', '00:00:00', $row, [['tgl_masuk', 'jam_masuk']])
        );
    }
}