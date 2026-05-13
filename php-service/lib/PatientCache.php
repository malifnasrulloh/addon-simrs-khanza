<?php

/**
 * PatientCache - Daily file-based cache to skip already-approved patients.
 *
 * Cache file: logs/icare_cache_YYYY-MM-DD.json
 * Structure: { "NIK_OR_NOKARTU:KODEDOKTER": { "status": "success"|"failed", "ts": "..." } }
 *
 * @author  malifnasrulloh (by Antigravity)
 */

declare(strict_types=1);

class PatientCache
{
    private string $cacheFile;
    private array $data = [];

    public function __construct(string $cacheDir)
    {
        if (!str_starts_with($cacheDir, '/')) {
            $cacheDir = BASE_DIR . '/' . $cacheDir;
        }
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true)) {
            fwrite(STDERR, "[FATAL] Cannot create cache directory: {$cacheDir}\n");
            exit(1);
        }

        $this->cacheFile = $cacheDir . '/icare_cache_' . date('Y-m-d') . '.json';
        $this->load();
    }

    private function load(): void
    {
        if (file_exists($this->cacheFile)) {
            $raw = file_get_contents($this->cacheFile);
            $this->data = json_decode($raw, true) ?: [];
        }
    }

    private function save(): void
    {
        file_put_contents(
            $this->cacheFile,
            json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    /**
     * Build a unique cache key for this patient+doctor combination.
     */
    private function buildKey(string $param, string $kodeDokter): string
    {
        return $param . ':' . $kodeDokter;
    }

    /**
     * Check if this patient was already successfully processed today.
     */
    public function isApproved(string $param, string $kodeDokter): bool
    {
        $key = $this->buildKey($param, $kodeDokter);
        return isset($this->data[$key]) && $this->data[$key]['status'] === 'success';
    }

    /**
     * Mark a patient as processed (success or failed).
     */
    public function mark(string $param, string $kodeDokter, string $status, string $detail = ''): void
    {
        $key = $this->buildKey($param, $kodeDokter);
        $this->data[$key] = [
            'status' => $status,
            'ts'     => date('Y-m-d H:i:s'),
            'detail' => $detail,
        ];
        $this->save();
    }

    /**
     * Get summary counts.
     */
    public function getSummary(): array
    {
        $success = 0;
        $failed  = 0;
        foreach ($this->data as $entry) {
            if ($entry['status'] === 'success') $success++;
            else $failed++;
        }
        return ['total' => count($this->data), 'success' => $success, 'failed' => $failed];
    }

    /**
     * Clean cache files older than N days.
     */
    public function cleanOld(int $days): void
    {
        if ($days <= 0) return;
        $dir = dirname($this->cacheFile);
        $cutoff = time() - ($days * 86400);
        foreach (glob($dir . '/icare_cache_*.json') as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }
}
