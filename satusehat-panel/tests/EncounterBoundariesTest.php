<?php

declare(strict_types=1);

namespace SatusehatPanel\Tests;

use PHPUnit\Framework\TestCase;
use SatuSehatPayloadBuilder;

/**
 * Canonical Encounter timeline (T0 arrival / T1 care-begin / T2 care-end).
 *
 * The chain must be contiguous in every flow — ralan, IGD, ranap, and the
 * poli->ranap conversion — with one canonical source per boundary:
 *   T0 = reg_periksa registration
 *   T1 = first kamar admission (ranap) | exam time -> mutasi dikirim
 *   T2 = nota time -> kamar discharge (ranap) | mutasi kembali (ralan)
 * Ordering T0 <= T1 <= T2 is enforced; violating boundaries are dropped.
 */
final class EncounterBoundariesTest extends TestCase
{
    public function testRalanPrefersMutasiDikirimOverExam(): void
    {
        // mutasi_dikirim is primary for Ralan care start; waktu_perawatan is fallback.
        $b = SatuSehatPayloadBuilder::resolveEncounterBoundaries([
            'status_lanjut' => 'Ralan',
            'tgl_registrasi' => '2026-01-05', 'jam_reg' => '08:00:00',
            'mutasi_dikirim' => '2026-01-05 09:10:00',
            'waktu_perawatan' => '2026-01-05T09:45:00+07:00',
            'waktu_pulang' => '2026-01-05T11:00:00+07:00',
        ]);
        $this->assertSame('2026-01-05T08:00:00+07:00', $b['t0']);
        $this->assertSame('2026-01-05T09:10:00+07:00', $b['t1']);
        $this->assertSame('2026-01-05T11:00:00+07:00', $b['t2']);
    }

    public function testRalanWithoutMutasiFallsBackToExamTime(): void
    {
        $b = SatuSehatPayloadBuilder::resolveEncounterBoundaries([
            'status_lanjut' => 'Ralan',
            'tgl_registrasi' => '2026-01-05', 'jam_reg' => '08:00:00',
            'waktu_perawatan' => '2026-01-05T09:30:00+07:00',
            'waktu_pulang' => '2026-01-05T11:00:00+07:00',
        ]);
        $this->assertSame('2026-01-05T09:30:00+07:00', $b['t1']);
    }

    public function testRalanZeroDatetimeMutasiFallsBackToExamTime(): void
    {
        // 0000-00-00 00:00:00 in mutasi_dikirim must be ignored and fall back to exam.
        $b = SatuSehatPayloadBuilder::resolveEncounterBoundaries([
            'status_lanjut' => 'Ralan',
            'tgl_registrasi' => '2026-01-05', 'jam_reg' => '08:00:00',
            'mutasi_dikirim' => '0000-00-00 00:00:00',
            'waktu_perawatan' => '2026-01-05T09:30:00+07:00',
        ]);
        $this->assertSame('2026-01-05T09:30:00+07:00', $b['t1']);
    }

    public function testRalanWithoutAnyCareStartCollapsesToArrival(): void
    {
        $b = SatuSehatPayloadBuilder::resolveEncounterBoundaries([
            'status_lanjut' => 'Ralan',
            'tgl_registrasi' => '2026-01-05', 'jam_reg' => '08:00:00',
        ]);
        $this->assertNotNull($b['t0']);
        $this->assertNull($b['t1']);

        $h = SatuSehatPayloadBuilder::buildEncounterStatusHistory($b, 'in-progress');
        $this->assertCount(2, $h);
        $this->assertArrayNotHasKey('end', $h[0]['period'], 'arrived stays open-ended without T1');
        $this->assertSame($b['t0'], $h[1]['period']['start'], 'in-progress starts at arrival');
    }

    public function testRanapUsesFirstAdmissionNotExam(): void
    {
        $b = SatuSehatPayloadBuilder::resolveEncounterBoundaries([
            'status_lanjut' => 'Ranap',
            'tgl_registrasi' => '2026-01-05', 'jam_reg' => '08:00:00',
            'tgl_masuk' => '2026-01-05', 'jam_masuk' => '10:30:00',
            'waktu_perawatan' => '2026-01-06T07:00:00+07:00', // later ranap exam — ignored for timeline
        ]);
        $this->assertSame('2026-01-05T10:30:00+07:00', $b['t1']);
    }

    public function testRanapAdmissionAcceptsFullTimestampInDateColumn(): void
    {
        // Some deployments store a full timestamp in tgl_masuk — it must win
        // over the separate jam column rather than collide with it.
        $b = SatuSehatPayloadBuilder::resolveEncounterBoundaries([
            'status_lanjut' => 'Ranap',
            'tgl_registrasi' => '2026-01-05', 'jam_reg' => '08:00:00',
            'tgl_masuk' => '2026-01-05 10:45:00', 'jam_masuk' => '23:59:59',
        ]);
        $this->assertSame('2026-01-05T10:45:00+07:00', $b['t1']);
    }

    public function testRanapEndFallsBackToKamarDischarge(): void
    {
        $b = SatuSehatPayloadBuilder::resolveEncounterBoundaries([
            'status_lanjut' => 'Ranap',
            'tgl_registrasi' => '2026-01-05', 'jam_reg' => '08:00:00',
            'tgl_masuk' => '2026-01-05', 'jam_masuk' => '10:30:00',
            'kamar_tgl_keluar' => '2026-01-08', 'kamar_jam_keluar' => '14:00:00',
        ]);
        $this->assertSame('2026-01-08T14:00:00+07:00', $b['t2']);
    }

    public function testRalanEndFallsBackToMutasiKembali(): void
    {
        $b = SatuSehatPayloadBuilder::resolveEncounterBoundaries([
            'status_lanjut' => 'Ralan',
            'tgl_registrasi' => '2026-01-05', 'jam_reg' => '08:00:00',
            'mutasi_kembali' => '2026-01-05 12:15:00',
        ]);
        $this->assertSame('2026-01-05T12:15:00+07:00', $b['t2']);
    }

    public function testContradictoryCareStartCollapsesToEndDrops(): void
    {
        // Exam before registration — dirty data; T1 collapses to null.
        $b = SatuSehatPayloadBuilder::resolveEncounterBoundaries([
            'status_lanjut' => 'Ralan',
            'tgl_registrasi' => '2026-01-05', 'jam_reg' => '08:00:00',
            'waktu_perawatan' => '2026-01-04T07:00+07:00',
        ]);
        $this->assertNull($b['t1']);

        // Discharge before care start — dirty data; T2 is dropped.
        $c = SatuSehatPayloadBuilder::resolveEncounterBoundaries([
            'status_lanjut' => 'Ralan',
            'tgl_registrasi' => '2026-01-05', 'jam_reg' => '08:00:00',
            'waktu_perawatan' => '2026-01-05T09:30:00+07:00',
            'waktu_pulang' => '2026-01-05T08:00:00+07:00',
        ]);
        $this->assertNull($c['t2']);
    }

    public function testArrivedTargetEmitsSingleEntry(): void
    {
        $b = SatuSehatPayloadBuilder::resolveEncounterBoundaries([
            'status_lanjut' => 'Ralan',
            'tgl_registrasi' => '2026-01-05', 'jam_reg' => '08:00:00',
        ]);
        $h = SatuSehatPayloadBuilder::buildEncounterStatusHistory($b, 'arrived');
        $this->assertSame(['arrived'], array_column($h, 'status'));
    }

    public function testConvertedIgdRanapFollowsSameChainAsImp(): void
    {
        // kd_poli is irrelevant to the timeline — an IGDK visit converted to
        // ranap gets the exact same boundaries as a ward admission.
        $igd = SatuSehatPayloadBuilder::resolveEncounterBoundaries([
            'kd_poli' => 'IGDK', 'status_lanjut' => 'Ranap',
            'tgl_registrasi' => '2026-01-05', 'jam_reg' => '21:00:00',
            'tgl_masuk' => '2026-01-05 22:10:00', 'jam_masuk' => '22:15:00',
            'waktu_pulang' => '2026-01-07T09:00:00+07:00',
        ]);
        $ward = SatuSehatPayloadBuilder::resolveEncounterBoundaries([
            'kd_poli' => 'INT', 'status_lanjut' => 'Ranap',
            'tgl_registrasi' => '2026-01-05', 'jam_reg' => '21:00:00',
            'tgl_masuk' => '2026-01-05 22:10:00', 'jam_masuk' => '22:15:00',
            'waktu_pulang' => '2026-01-07T09:00:00+07:00',
        ]);
        $this->assertSame($ward, $igd);
    }
}
