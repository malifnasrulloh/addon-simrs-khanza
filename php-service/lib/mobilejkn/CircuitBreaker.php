<?php

/**
 * CircuitBreaker - High-reliability, file-persistent circuit breaker pattern.
 *
 * Prevents system lock contention and API throttling when the BPJS API
 * is down or experiencing heavy connection timeouts.
 *
 * @author  malifnasrulloh (by Antigravity)
 */

declare(strict_types=1);

class CircuitBreaker
{
    private string $filePath;
    private int    $maxFailures;
    private int    $coolingPeriod;
    private Logger $log;

    public function __construct(string $logDir, Logger $log, int $maxFailures = 5, int $coolingPeriod = 300)
    {
        $this->filePath      = rtrim($logDir, '/') . '/circuit_breaker.json';
        $this->maxFailures   = $maxFailures;
        $this->coolingPeriod = $coolingPeriod;
        $this->log           = $log;
    }

    private function loadState(): array
    {
        if (!file_exists($this->filePath)) {
            return [
                'status'        => 'CLOSED',
                'failure_count' => 0,
                'tripped_time'  => 0,
            ];
        }
        $content = @file_get_contents($this->filePath);
        if ($content === false) {
            return [
                'status'        => 'CLOSED',
                'failure_count' => 0,
                'tripped_time'  => 0,
            ];
        }
        $data = json_decode($content, true);
        if (!is_array($data)) {
            return [
                'status'        => 'CLOSED',
                'failure_count' => 0,
                'tripped_time'  => 0,
            ];
        }
        return $data;
    }

    private function saveState(array $state): void
    {
        @file_put_contents($this->filePath, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Check if the circuit breaker is open (currently tripped).
     */
    public function isOpen(): bool
    {
        $state = $this->loadState();
        if ($state['status'] === 'OPEN') {
            $elapsed = time() - $state['tripped_time'];
            if ($elapsed >= $this->coolingPeriod) {
                $this->log->warning("[CIRCUIT-BREAKER] Cooling period completed. Transitioning to HALF-OPEN.");
                $state['status'] = 'HALF-OPEN';
                $this->saveState($state);
                return false;
            }
            return true;
        }
        return false;
    }

    /**
     * Get details of the circuit breaker state.
     */
    public function getStatusDetails(): array
    {
        $state = $this->loadState();
        if ($state['status'] === 'OPEN') {
            $state['remaining_cooling_time'] = max(0, $this->coolingPeriod - (time() - $state['tripped_time']));
        }
        return $state;
    }

    /**
     * Record a successful request using a Leaky Bucket algorithm.
     * Prevents "flapping" by gradually reducing failure counts 
     * on success, rather than resetting to zero immediately.
     */
    public function recordSuccess(): void
    {
        $state = $this->loadState();
        
        if ($state['status'] === 'HALF-OPEN') {
            // A success during the testing phase fully heals the circuit
            $this->log->info("[CIRCUIT-BREAKER] API request succeeded in HALF-OPEN state. Resetting to CLOSED.");
            $state['status']        = 'CLOSED';
            $state['failure_count'] = 0;
            $state['tripped_time']  = 0;
            $this->saveState($state);
            
        } elseif ($state['failure_count'] > 0) {
            // Leaky bucket: heal gradually during unstable periods
            $state['failure_count'] = max(0, $state['failure_count'] - 1);
            $this->log->debug("[CIRCUIT-BREAKER] Success recorded. Failure count decreased to {$state['failure_count']}");
            $this->saveState($state);
        }
    }

    /**
     * Record an API failure. Trips if consecutive failures cross limit.
     */
    public function recordFailure(): void
    {
        $state = $this->loadState();
        $state['failure_count']++;

        $this->log->warning("[CIRCUIT-BREAKER] Connection/API failure logged ({$state['failure_count']}/{$this->maxFailures})");

        if ($state['failure_count'] >= $this->maxFailures && $state['status'] !== 'OPEN') {
            $state['status']       = 'OPEN';
            $state['tripped_time'] = time();
            $this->log->error("[CIRCUIT-BREAKER] !!! CIRCUIT BREAKER TRIPPED !!! API requests paused for " . ($this->coolingPeriod / 60) . " mins.");
        }
        $this->saveState($state);
    }
}
