<?php

/**
 * Logger - Simple file + console logger for CLI scripts.
 *
 * @author  malifnasrulloh (by Antigravity)
 */

declare(strict_types=1);

class Logger
{
    private string $logFile;
    private int $minLevel;
    private bool $verbose;

    private const LEVELS = ['DEBUG' => 0, 'INFO' => 1, 'WARNING' => 2, 'ERROR' => 3];

    public function __construct(string $logDir, string $prefix, string $level = 'INFO', bool $verbose = false)
    {
        if (!str_starts_with($logDir, '/')) {
            $logDir = BASE_DIR . '/' . $logDir;
        }
        if (!is_dir($logDir) && !mkdir($logDir, 0755, true)) {
            fwrite(STDERR, "[FATAL] Cannot create log directory: {$logDir}\n");
            exit(1);
        }

        $this->logFile  = $logDir . '/' . $prefix . '_' . date('Y-m-d') . '.log';
        $this->minLevel = self::LEVELS[strtoupper($level)] ?? 1;
        $this->verbose  = $verbose;
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
     * Delete log files older than $days days.
     */
    public function cleanOldLogs(string $dir, string $prefix, int $days): void
    {
        if ($days <= 0) return;
        if (!str_starts_with($dir, '/')) {
            $dir = BASE_DIR . '/' . $dir;
        }
        $cutoff = time() - ($days * 86400);
        foreach (glob($dir . '/' . $prefix . '_*.log') as $file) {
            if (filemtime($file) < $cutoff) {
                // @unlink($file);
            }
        }
    }
}
