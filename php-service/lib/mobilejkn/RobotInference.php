<?php
/**
 * RobotInference — Exact port of Java ANTROL-ROBOT.JAVA time inference logic.
 *
 * Generates synthetic timestamps for missing task IDs using random offsets
 * from the previous task. Matches the Java robot's exact random ranges:
 *   Task 4 ← task 3: rand(35–58 min) + rand(1–60 sec)
 *   Task 5 ← task 4: rand(3–10 min)  + rand(1–60 sec)
 *   Task 6 ← task 5: rand(6–15 min)  + rand(1–60 sec)
 *   Task 7 ← task 6: rand(8–15 min)  / rand(11–30 min) racikan + rand(1–60 sec)
 *
 * Two safety gates (matching Java exactly):
 *   1. newTime must be BEFORE now (past-time gate)
 *   2. newTime must be AFTER prevTime (chronological gate)
 *
 * @author malifnasrulloh (ported from Java by Antigravity)
 */
declare(strict_types=1);

class RobotInference
{
    /**
     * Infer a waktu timestamp for a missing task using the Java robot's exact logic.
     *
     * @param string $taskId     Task to infer ('4', '5', '6', '7')
     * @param string $prevWaktu  Previous task's waktu (Y-m-d H:i:s format)
     * @param bool   $isRacikan  Whether the prescription is racikan (only affects task 7)
     * @return string  Inferred waktu in 'Y-m-d H:i:s' format, or '' if gates not satisfied
     */
    public static function infer(string $taskId, string $prevWaktu, bool $isRacikan = false): string
    {
        if (empty($prevWaktu) || str_starts_with($prevWaktu, '0000')) {
            return '';
        }

        $prevTs = strtotime($prevWaktu);
        if ($prevTs === false || $prevTs <= 0) {
            return '';
        }

        // Get random offset range matching Java robot exactly
        [$minMinutes, $maxMinutes] = self::getRange($taskId, $isRacikan);
        if ($minMinutes === 0 && $maxMinutes === 0) {
            return ''; // Task 3 or unknown — cannot infer
        }

        // Java: int randomMin = rand.nextInt((max + 1) - min) + min;
        $randomMin = mt_rand($minMinutes, $maxMinutes);
        // Java: int randomSec = rand.nextInt((60 + 1) - 1) + 1;
        $randomSec = mt_rand(1, 60);

        $newTs = $prevTs + ($randomMin * 60) + $randomSec;
        $nowTs = time();

        // Gate 1: newTime must be in the past (Java: newTime.isBefore(sekarang))
        if ($newTs >= $nowTs) {
            return '';
        }

        // Gate 2: newTime must be after prevTime (Java: newTime.isAfter(dateTime))
        if ($newTs <= $prevTs) {
            return '';
        }

        return date('Y-m-d H:i:s', $newTs);
    }

    /**
     * Get the random minute range for each task transition.
     * Exact values from Java ANTROL-ROBOT.JAVA.
     *
     * @return int[] [minMinutes, maxMinutes]
     */
    private static function getRange(string $taskId, bool $isRacikan): array
    {
        return match ($taskId) {
            '4' => [35, 58],           // Java line 373: rand.nextInt((58+1)-35)+35
            '5' => [3, 10],            // Java line 435: rand.nextInt((10+1)-3)+3
            '6' => [6, 15],            // Java line 525: rand.nextInt((15+1)-6)+6
            '7' => $isRacikan
                ? [11, 30]             // Java line 590: rand.nextInt((30+1)-11)+11
                : [8, 15],             // Java line 587: rand.nextInt((15+1)-8)+8
            default => [0, 0],
        };
    }

    /**
     * Convert Y-m-d H:i:s to epoch milliseconds (Java's Date.getTime()).
     * Returns null on parse failure.
     */
    public static function toEpochMs(string $datetime): ?int
    {
        if (empty($datetime) || str_starts_with($datetime, '0000')) {
            return null;
        }

        // Handle millisecond timestamps (e.g. 2023-10-01 12:00:00.123)
        $clean = preg_replace('/\.\d+$/', '', $datetime);
        $ts = strtotime($clean);
        if ($ts === false || $ts <= 0) {
            return null;
        }
        return $ts * 1000;
    }
}
