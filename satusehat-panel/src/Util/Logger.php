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
    private bool $echoWeb;
    /** Lazily-opened persistent file handle (perf: one open per file, not per line). */
    private $fh = null;
    /** File the open handle belongs to (rotation detection). */
    private string $fhFile = '';

    private const LEVELS = ['DEBUG' => 0, 'INFO' => 1, 'WARNING' => 2, 'ERROR' => 3];

    /**
     * @param string $baseLogDir  Root log directory (e.g. "logs")
     * @param string $serviceName Service name used as subfolder AND file prefix (e.g. "mobilejkn")
     * @param string $level       Minimum log level
     * @param bool   $verbose     If true, always output DEBUG to console
     * @param bool   $echoWeb     If true, echo log output to web page when run in web context
     */
    public function __construct(string $baseLogDir, string $serviceName, string $level = 'INFO', bool $verbose = false, bool $echoWeb = false)
    {
        // Resolve to absolute path
        if (!str_starts_with($baseLogDir, '/')) {
            $baseLogDir = (defined('BASE_DIR') ? BASE_DIR : __DIR__) . '/' . $baseLogDir;
        }

        // Create service-specific subdirectory: logs/mobilejkn/
        $this->logDir  = rtrim($baseLogDir, '/') . '/' . $serviceName;
        $this->prefix  = $serviceName;
        $this->echoWeb = $echoWeb;

        if (!is_dir($this->logDir) && !mkdir($this->logDir, 0755, true)) {
            if (defined('STDERR')) {
                fwrite(STDERR, "[FATAL] Cannot create log directory: {$this->logDir}\n");
            } else {
                error_log("[FATAL] Cannot create log directory: {$this->logDir}");
            }
            exit(1);
        }

        $suffix = (php_sapi_name() === 'cli') ? '' : '_web';
        $this->logFile  = $this->logDir . '/' . $serviceName . '_' . date('Y-m-d') . $suffix . '.log';
        $this->minLevel = self::LEVELS[strtoupper($level)] ?? 1;
        $this->verbose  = $verbose;

        // Close the persistent handle at process shutdown.
        register_shutdown_function(function () {
            if (is_resource($this->fh)) {
                fflush($this->fh);
                fclose($this->fh);
                $this->fh = null;
            }
        });
    }

    /**
     * Get the resolved log directory for this service.
     */
    public function getLogDir(): string
    {
        return $this->logDir;
    }

    /**
     * Scrub sensitive patient credentials (Data Minimization)
     */
    public static function scrubSensitiveData(string $message): string
    {
        $keys = 'nik|nohp|nomorkartu|nomorpeserta|no_peserta|no_ktp|no_tlp';
        $pattern = '/(["\']?(?:' . $keys . ')(?:_bpjs)?["\']?\s*[:=]\s*["\']?)([^"\'\s,;\}]+)(["\']?)/i';

        $message = preg_replace_callback($pattern, function($matches) {
            $prefix = $matches[1];
            $val    = $matches[2];
            $suffix = $matches[3];

            $len = strlen($val);
            if ($len > 4) {
                $masked = substr($val, 0, 2) . str_repeat('*', max(2, $len - 4)) . substr($val, -2);
            } else {
                $masked = str_repeat('*', $len);
            }
            return $prefix . $masked . $suffix;
        }, $message);

        // URL-encoded IHS lookup form: ...id/nik|3333061211890001... — the
        // NIK sits after a pipe (the "key: value" regex above cannot see it).
        $message = preg_replace_callback(
            '/(?:\bnik\|)(\d{12,16})/i',
            function ($m) {
                return 'nik|' . substr($m[1], 0, 4) . str_repeat('*', max(4, strlen($m[1]) - 8)) . substr($m[1], -4);
            },
            $message
        );

        // Standalone 16-digit IDs (raw NIKs in payloads/URLs).
        return preg_replace('/\b\d{16}\b/', '****************', $message);
    }

    public function write(string $level, string $message): void
    {
        $levelNum = self::LEVELS[$level] ?? 1;
        if ($levelNum < $this->minLevel && !$this->verbose) return;

        // Apply automatic log scrubbing (Data Minimization)
        $message = self::scrubSensitiveData($message);

        $ts = date('Y-m-d H:i:s');
        $line = "[{$ts}] [{$level}] {$message}";

        // Persistent handle: open once per log file, keep it across writes.
        // Reopen only when the (date-rotated) filename changes.
        if (!is_resource($this->fh) || $this->fhFile !== $this->logFile) {
            if (is_resource($this->fh)) {
                fclose($this->fh);
            }
            $this->fh = @fopen($this->logFile, 'ab');
            $this->fhFile = $this->logFile;
        }

        if (is_resource($this->fh)) {
            flock($this->fh, LOCK_EX);
            fwrite($this->fh, $line . PHP_EOL);
            fflush($this->fh);
            flock($this->fh, LOCK_UN);
        } else {
            error_log("SIMRS-MobileJKN Log write failed: " . $line);
        }

        if (defined('STDERR') && defined('STDOUT')) {
            $stream = ($level === 'ERROR' || $level === 'WARNING') ? STDERR : STDOUT;
            fwrite($stream, $line . PHP_EOL);
        } elseif (php_sapi_name() !== 'cli' && $this->echoWeb) {
            echo $line . PHP_EOL;
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }
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
