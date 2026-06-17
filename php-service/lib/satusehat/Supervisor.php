<?php

/**
 * SatuSehatSupervisor - Orchestrates and monitors background workers for parallel execution.
 * Tracks exit status of forked workers. Optional Time-To-Live (TTL) can be set
 * via the constructor to send SIGKILL to workers that exceed the budget; by
 * default TTL is disabled so workers run to natural completion.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class SatuSehatSupervisor
{
    private Logger $log;
    /**
     * Per-worker TTL in seconds. 0 (or negative) disables the kill entirely:
     * workers are monitored for exit status only and are allowed to run to
     * natural completion. Set >0 only if you need a hard ceiling.
     */
    private int $timeoutSeconds;

    /**
     * Constructor.
     *
     * @param Logger $log             Logger instance
     * @param int    $timeoutSeconds  Per-worker TTL in seconds.
     *                                0 (default) disables TTL: workers run to
     *                                natural completion, no SIGKILL is ever sent.
     */
    public function __construct(Logger $log, int $timeoutSeconds = 0)
    {
        $this->log = $log;
        $this->timeoutSeconds = $timeoutSeconds;
    }

    /**
     * Monitors active workers, checking exit codes and enforcing execution TTL.
     *
     * @param array $workers Array of active worker PIDs
     * @return bool True if all workers exited successfully with code 0, false otherwise
     */
    public function monitor(array $workers): bool
    {
        if (empty($workers)) {
            return true;
        }

        $ttlMsg = $this->timeoutSeconds > 0
            ? "{$this->timeoutSeconds}s"
            : 'disabled (run to natural completion)';
        $this->log->info("[SUPERVISOR] Starting active monitoring for " . count($workers) . " background workers (TTL: {$ttlMsg})...");

        // Map containing PID => start timestamp
        $activeWorkers = [];
        foreach ($workers as $pid) {
            $activeWorkers[$pid] = time();
        }

        $allSuccess = true;

        while (count($activeWorkers) > 0) {
            foreach ($activeWorkers as $pid => $startTime) {
                // Non-blocking status check on PID
                $res = pcntl_waitpid($pid, $status, WNOHANG);

                if ($res === -1 || $res > 0) {
                    // Child process has terminated
                    unset($activeWorkers[$pid]);

                    if (pcntl_wifexited($status)) {
                        $exitCode = pcntl_wexitstatus($status);
                        if ($exitCode === 0) {
                            $this->log->info("[SUPERVISOR] Worker [PID {$pid}] completed successfully.");
                        } else {
                            $this->log->error("[SUPERVISOR] Worker [PID {$pid}] exited with non-zero status code: {$exitCode}");
                            $allSuccess = false;
                        }
                    } else {
                        $this->log->error("[SUPERVISOR] Worker [PID {$pid}] terminated abnormally (e.g. signal or crash).");
                        $allSuccess = false;
                    }
                } else {
                    // Process is still running. TTL is opt-in: only kill if a
                    // positive timeout was configured.
                    if ($this->timeoutSeconds > 0) {
                        $runningTime = time() - $startTime;
                        if ($runningTime > $this->timeoutSeconds) {
                            $this->log->warning("[SUPERVISOR] Worker [PID {$pid}] has been running for {$runningTime}s, exceeding TTL limit of {$this->timeoutSeconds}s! Sending SIGKILL...");

                            // Terminate the hung process forcefully
                            posix_kill($pid, SIGKILL);
                            pcntl_waitpid($pid, $status); // clean up zombie process reference

                            unset($activeWorkers[$pid]);
                            $this->log->error("[SUPERVISOR] Worker [PID {$pid}] terminated due to timeout.");
                            $allSuccess = false;
                        }
                    }
                }
            }

            // Sleep 100ms before next status check iteration to avoid CPU throttling
            if (count($activeWorkers) > 0) {
                usleep(100000);
            }
        }

        $this->log->info("[SUPERVISOR] All monitored worker processes have exited.");
        return $allSuccess;
    }
}
