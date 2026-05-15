<?php

/**
 * Logger - Structured file + console logger for CLI scripts.
 *
 * Features:
 *  - Per-service log subdirectories (e.g. logs/mobilejkn/, logs/aplicare/)
 *  - Date-rotated log files (service_YYYY-MM-DD.log)
 *  - Configurable retention with auto-cleanup
 *  - Level-filtered output (DEBUG, INFO, WARNING, ERROR)
 *
 * @author  malifnasrulloh (by Antigravity)
 */

declare(strict_types=1);

class Logger
{
    private string $logFile;
    private string $logDir;
    private string $prefix;
    private int $minLevel;
    private bool $verbose;

    private const LEVELS = ['DEBUG' => 0, 'INFO' => 1, 'WARNING' => 2, 'ERROR' => 3];

    /**
     * @param string $baseLogDir  Root log directory (e.g. "logs")
     * @param string $serviceName Service name used as subfolder AND file prefix (e.g. "mobilejkn")
     * @param string $level       Minimum log level
     * @param bool   $verbose     If true, always output DEBUG to console
     */
    public function __construct(string $baseLogDir, string $serviceName, string $level = 'INFO', bool $verbose = false)
    {
        // Resolve to absolute path
        if (!str_starts_with($baseLogDir, '/')) {
            $baseLogDir = (defined('BASE_DIR') ? BASE_DIR : __DIR__) . '/' . $baseLogDir;
        }

        // Create service-specific subdirectory: logs/mobilejkn/
        $this->logDir  = rtrim($baseLogDir, '/') . '/' . $serviceName;
        $this->prefix  = $serviceName;

        if (!is_dir($this->logDir) && !mkdir($this->logDir, 0755, true)) {
            fwrite(STDERR, "[FATAL] Cannot create log directory: {$this->logDir}\n");
            exit(1);
        }

        $this->logFile  = $this->logDir . '/' . $serviceName . '_' . date('Y-m-d') . '.log';
        $this->minLevel = self::LEVELS[strtoupper($level)] ?? 1;
        $this->verbose  = $verbose;
    }

    /**
     * Get the resolved log directory for this service.
     */
    public function getLogDir(): string
    {
        return $this->logDir;
    }

    public function write(string $level, string $message): void
    {
        $levelNum = self::LEVELS[$level] ?? 1;
        if ($levelNum < $this->minLevel && !$this->verbose) return;

        $ts = date('Y-m-d H:i:s');
        $line = "[{$ts}] [{$level}] {$message}";

        file_put_contents($this->logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);

        $stream = ($level === 'ERROR' || $level === 'WARNING') ? STDERR : STDOUT;
        fwrite($stream, $line . PHP_EOL);
    }

    public function debug(string $msg): void
    {
        $this->write('DEBUG', $msg);
    }
    public function info(string $msg): void
    {
        $this->write('INFO', $msg);
    }
    public function warning(string $msg): void
    {
        $this->write('WARNING', $msg);
    }
    public function error(string $msg): void
    {
        $this->write('ERROR', $msg);
    }

    /**
     * Delete log files older than $days days for this service.
     * Searches within this service's log subdirectory.
     */
    public function cleanOldLogs(int $retentionDays): void
    {
        if ($retentionDays <= 0) return;

        $cutoff = time() - ($retentionDays * 86400);
        $pattern = $this->logDir . '/' . $this->prefix . '_*.log';

        foreach (glob($pattern) as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    /**
     * Clean arbitrary directory of files matching a glob pattern older than $days.
     * Useful for cleaning cache directories, tmp files, etc.
     *
     * @param string $dir     Directory to clean
     * @param string $pattern Glob pattern (e.g. "cache_*.json")
     * @param int    $days    Max age in days
     */
    public static function cleanDirectory(string $dir, string $pattern, int $days): void
    {
        if ($days <= 0) return;
        if (!is_dir($dir)) return;

        $cutoff = time() - ($days * 86400);
        foreach (glob(rtrim($dir, '/') . '/' . $pattern) as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }
}
