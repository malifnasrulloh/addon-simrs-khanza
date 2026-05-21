<?php
/**
 * PayloadBuilder — Builds JSON payloads for BPJS Mobile JKN API calls.
 *
 * Extracted from QueueProcessor for maintainability. Matches the Java robot's
 * exact payload structure for /antrean/add, /antrean/farmasi/add, etc.
 *
 * @author malifnasrulloh (ported from Java by Antigravity)
 */
declare(strict_types=1);

class PayloadBuilder
{
    /**
     * Build /antrean/add payload for a JKN patient from referensi_mobilejkn_bpjs.
     * Matches Java ANTROL-ROBOT.JAVA line 98–122 exactly.
     */
    public static function jknBooking(array $b): array
    {
        return [
            'kodebooking'      => $b['nobooking'],
            'jenispasien'      => 'JKN',
            'nomorkartu'       => $b['nomorkartu'],
            'nik'              => $b['nik'],
            'nohp'             => $b['nohp'],
            'kodepoli'         => $b['kodepoli'],
            'namapoli'         => $b['nm_poli'],
            'pasienbaru'       => (int) $b['pasienbaru'],
            'norm'             => $b['no_rkm_medis'],
            'tanggalperiksa'   => $b['tanggalperiksa'],
            'kodedokter'       => (int) $b['kodedokter'],
            'namadokter'       => $b['nm_dokter'],
            'jampraktek'       => $b['jampraktek'],
            'jeniskunjungan'   => (int) substr($b['jeniskunjungan'] ?? '3', 0, 1),
            'nomorreferensi'   => $b['nomorreferensi'],
            'nomorantrean'     => $b['nomorantrean'],
            'angkaantrean'     => (int) $b['angkaantrean'],
            'estimasidilayani' => (int) $b['estimasidilayani'],
            'sisakuotajkn'     => (int) $b['sisakuotajkn'],
            'kuotajkn'         => (int) $b['kuotajkn'],
            'sisakuotanonjkn'  => (int) $b['sisakuotanonjkn'],
            'kuotanonjkn'      => (int) $b['kuotanonjkn'],
            'keterangan'       => 'Peserta harap 30 menit lebih awal guna pencatatan administrasi.',
        ];
    }

    /**
     * Build /antrean/add payload for an on-site patient (JKN or Non-JKN).
     * Matches Java ANTROL-ROBOT.JAVA lines 810–834 / 1347–1371.
     *
     * @param array  $p       Patient data from fetchMissingOnSitePatients
     * @param bool   $isJkn   True for JKN, false for Non-JKN
     * @param string $nomorRef SEP reference number (empty for Non-JKN)
     */
    public static function onsitePatient(array $p, bool $isJkn, string $nomorRef = ''): array
    {
        $noReg      = (int) ($p['no_reg'] ?? 1);
        $jamMulai   = substr($p['jam_mulai'] ?? '08:00:00', 0, 5);
        $jamSelesai = substr($p['jam_selesai'] ?? '16:00:00', 0, 5);
        $kuota      = (int) ($p['kuota'] ?? 30);

        // Java: DATE_ADD(concat(tgl_registrasi, ' ', jam_mulai), INTERVAL no_reg*10 MINUTE)
        $baseTime   = strtotime($p['tgl_registrasi'] . ' ' . ($p['jam_mulai'] ?? '08:00:00'));
        $estimasi   = $baseTime + ($noReg * 10 * 60); // Java uses *10 min
        $estimasiMs = $estimasi * 1000;

        // Java: stts_daftar.replaceAll("Baru","1").replaceAll("Lama","0").replaceAll("-","0")
        $pasienbaru = match ($p['stts_daftar'] ?? '-') {
            'Baru' => 1,
            default => 0,
        };

        return [
            'kodebooking'      => $p['no_rawat'],
            'jenispasien'      => $isJkn ? 'JKN' : 'NON JKN',
            'nomorkartu'       => $isJkn ? ($p['no_peserta'] ?: '-') : '-',
            'nik'              => $isJkn ? ($p['no_ktp'] ?: '-') : '-',
            'nohp'             => $isJkn ? ($p['no_tlp'] ?: '080000000000') : '-',
            'kodepoli'         => $p['kd_poli_bpjs'],
            'namapoli'         => $p['nm_poli'],
            'pasienbaru'       => $pasienbaru,
            'norm'             => $p['no_rkm_medis'],
            'tanggalperiksa'   => $p['tgl_registrasi'],
            'kodedokter'       => (int) $p['kd_dokter_bpjs'],
            'namadokter'       => $p['nm_dokter'],
            'jampraktek'       => "{$jamMulai}-{$jamSelesai}",
            'jeniskunjungan'   => $isJkn ? (int) substr($p['jeniskunjungan'] ?? '3', 0, 1) : 3,
            'nomorreferensi'   => $isJkn ? ($nomorRef ?: '-') : '-',
            'nomorantrean'     => ($p['kd_poli'] ?? '') . '-' . ($p['no_reg'] ?? '001'),
            'angkaantrean'     => $noReg,
            'estimasidilayani' => $estimasiMs,
            'sisakuotajkn'     => max(0, $kuota - $noReg),
            'kuotajkn'         => $kuota,
            'sisakuotanonjkn'  => max(0, $kuota - $noReg),
            'kuotanonjkn'      => $kuota,
            'keterangan'       => 'Peserta harap 30 menit lebih awal guna pencatatan administrasi.',
        ];
    }

    /**
     * Build /antrean/farmasi/add payload.
     * Matches Java ANTROL-ROBOT.JAVA lines 503–508.
     */
    public static function farmasi(string $kodebooking, string $noResep, string $jenisResep): array
    {
        // Java: Integer.parseInt(StringUtils.right(noresep, 4))
        $nomorAntrean = (int) substr($noResep, -4);

        return [
            'kodebooking'  => $kodebooking,
            'jenisresep'   => $jenisResep,
            'nomorantrean' => $nomorAntrean,
            'keterangan'   => 'Resep dibuat secara elektronik di poli',
        ];
    }
}
