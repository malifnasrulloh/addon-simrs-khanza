<?php

/**
 * BatchCursor - Iterates over large MySQL result sets in configurable batches,
 * preventing OOM by never loading all rows into PHP memory at once.
 *
 * Usage (single process):
 *   $cursor = new SatuSehatBatchCursor($db, 'fetchPendingArrived', [$dateFrom, $dateTo], 500, $log, 'arrived');
 *   foreach ($cursor->batches() as $batch) {
 *       foreach ($batch as $row) { /* process * / }
 *       $cursor->tick();
 *   }
 *
 * Usage (parallel worker):
 *   $windows = SatuSehatBatchCursor::splitDateRange($dateFrom, $dateTo, $numWorkers);
 *   [$wFrom, $wTo] = $windows[$workerIndex];
 *   $cursor = new SatuSehatBatchCursor($db, 'fetchPendingX', [$wFrom, $wTo], 500, $log, 'x');
 *   foreach ($cursor->batches() as $batch) { ... }
 *
 * @author malifnasrulloh
 */
declare(strict_types=1);

class SatuSehatBatchCursor
{
    private const ALLOWED_METHOD_PREFIX = 'fetchPending';
    private const MAX_ITERATIONS = 100000;

    private object $db;
    private string $method;
    private array $params;
    private array $extraParams;
    private int $batchSize;
    private int $currentOffset = 0;
    private int $totalProcessed = 0;
    private int $batchCount = 0;
    private bool $dryRun = false;
    private ?Logger $log = null;
    private string $label;

    /**
     * @param object  $db          SatuSehatDatabase instance
     * @param string  $method      Method name on $db to call, e.g. 'fetchPendingArrived'
     * @param array   $params      Positional params for $method (excluding limit/offset)
     * @param int     $batchSize   Rows per batch (default 500)
     * @param ?Logger $log         Logger for progress output
     * @param string  $label       Human label for log messages
     * @param array   $extraParams Extra positional params after the standard ones (e.g. TTV definitions)
     */
    public function __construct(
        object $db,
        string $method,
        array $params,
        int $batchSize = 500,
        ?Logger $log = null,
        string $label = '',
        array $extraParams = []
    ) {
        // Validate method starts with fetchPending to prevent arbitrary method invocation
        if (!str_starts_with($method, self::ALLOWED_METHOD_PREFIX)) {
            throw new \InvalidArgumentException(
                "Method must start with '" . self::ALLOWED_METHOD_PREFIX . "', got: {$method}"
            );
        }

        $this->db = $db;
        $this->method = $method;
        $this->params = $params;
        $this->extraParams = $extraParams;
        $this->batchSize = max(1, $batchSize);
        $this->log = $log;
        $this->label = $label ?: $method;
    }

    /**
     * Enable dry-run mode (API calls skipped downstream).
     */
    public function setDryRun(bool $dryRun): void
    {
        $this->dryRun = $dryRun;
    }

    /**
     * Override batch size after construction.
     */
    public function setBatchSize(int $size): void
    {
        $this->batchSize = max(1, $size);
    }

    /**
     * Generator that yields arrays of rows — one batch per iteration.
     * Each batch is fetched via $db->{$method}(..., $batchSize, $offset).
     *
     * @yields array
     */
    public function batches(): \Generator
    {
        $this->currentOffset = 0;
        $this->totalProcessed = 0;
        $this->batchCount = 0;

        // Set memory limit once before iteration (not on every batch)
        $this->maybeSetMemoryLimit('512M');

        $iteration = 0;

        while (true) {
            // Guard against infinite loop if concurrent writes keep batches full
            if (++$iteration > self::MAX_ITERATIONS) {
                throw new \RuntimeException(
                    "BatchCursor exceeded " . self::MAX_ITERATIONS . " iterations for method '{$this->method}' — possible infinite loop. "
                    . "Total processed: {$this->totalProcessed}"
                );
            }

            // Build argument list: params + (limit, offset) + extraParams
            $args = $this->params;
            $args[] = $this->batchSize;
            $args[] = $this->currentOffset;
            foreach ($this->extraParams as $ep) {
                $args[] = $ep;
            }

            $rows = $this->db->{$this->method}(...$args);

            if (!is_array($rows) || count($rows) === 0) {
                break;
            }

            $batchCount = count($rows);
            $this->batchCount++;

            if ($this->log) {
                $this->log->debug(sprintf(
                    '[BATCH] %s batch #%d: %d rows (offset %d)',
                    $this->label,
                    $this->batchCount,
                    $batchCount,
                    $this->currentOffset
                ));
            }

            yield $rows;

            $this->totalProcessed += $batchCount;
            $this->currentOffset += $this->batchSize;

            // If this batch returned fewer rows than requested, we're done
            if ($batchCount < $this->batchSize) {
                break;
            }
        }
    }

    /**
     * Call after processing each batch to free memory and log progress.
     * Explicitly unsets the last yielded batch and runs GC.
     */
    public function tick(): void
    {
        // Force garbage collection to free processed batch memory
        if (gc_enabled()) {
            gc_collect_cycles();
        }

        // Log memory usage periodically (every 10th batch or at milestones)
        $milestone = $this->totalProcessed > 0 && ($this->totalProcessed % ($this->batchSize * 10) === 0);
        if ($this->log && ($this->batchCount % 10 === 0 || $milestone)) {
            $memUsage = round(memory_get_usage(true) / 1024 / 1024, 1);
            $memPeak = round(memory_get_peak_usage(true) / 1024 / 1024, 1);
            $this->log->debug(sprintf(
                '[MEM] %s: %d rows processed | current: %sMB | peak: %sMB',
                $this->label,
                $this->totalProcessed,
                $memUsage,
                $memPeak
            ));
        }
    }

    /**
     * Get total rows processed so far.
     */
    public function getProcessedCount(): int
    {
        return $this->totalProcessed;
    }

    /**
     * Get batch count so far.
     */
    public function getBatchCount(): int
    {
        return $this->batchCount;
    }

    /**
     * Reset cursor state so batches() can be re-iterated.
     */
    public function reset(): void
    {
        $this->currentOffset = 0;
        $this->totalProcessed = 0;
        $this->batchCount = 0;
    }

    /**
     * Split a date range [$from, $to] into $parts contiguous day windows.
     * Each window is a [from, to] pair. Returned array always has exactly $parts entries.
     *
     * Example: splitDateRange('2026-07-01', '2026-07-10', 3)
     *   → [['2026-07-01', '2026-07-03'], ['2026-07-04', '2026-07-06'], ['2026-07-07', '2026-07-10']]
     */
    public static function splitDateRange(string $from, string $to, int $parts): array
    {
        $parts = max(1, $parts);
        $startTs = strtotime($from);
        $endTs = strtotime($to);
        $totalDays = (int) ceil(($endTs - $startTs) / 86400) + 1;

        if ($totalDays <= $parts) {
            // Fewer days than workers: each worker gets at most 1 day
            $windows = [];
            for ($i = 0; $i < $parts; $i++) {
                $dayTs = $startTs + ($i * 86400);
                if ($dayTs > $endTs) {
                    $windows[] = [$to, $to]; // no data day
                } else {
                    $day = date('Y-m-d', $dayTs);
                    $windows[] = [$day, $day];
                }
            }
            return $windows;
        }

        $daysPerPart = (int) ceil($totalDays / $parts);
        $windows = [];
        for ($i = 0; $i < $parts; $i++) {
            $wFrom = date('Y-m-d', $startTs + ($i * $daysPerPart * 86400));
            $wEndTs = $startTs + (($i + 1) * $daysPerPart * 86400) - 86400;
            if ($wEndTs > $endTs) {
                $wEndTs = $endTs;
            }
            $wTo = date('Y-m-d', $wEndTs);
            $windows[] = [$wFrom, $wTo];
        }

        return $windows;
    }

    /**
     * Set a sane memory limit if not already configured higher.
     */
    private function maybeSetMemoryLimit(string $limit): void
    {
        $current = ini_get('memory_limit');
        if ($current === '-1') {
            return; // unlimited, leave it
        }

        // Convert both to bytes for comparison
        $currentBytes = $this->shorthandToBytes($current);
        $desiredBytes = $this->shorthandToBytes($limit);

        if ($desiredBytes > $currentBytes) {
            @ini_set('memory_limit', $limit);
        }
    }

    /**
     * Convert PHP shorthand (128M, 1G, 512K) to bytes.
     */
    private static function shorthandToBytes(string $shorthand): int
    {
        $shorthand = trim(strtoupper($shorthand));
        $unit = substr($shorthand, -1);
        $value = (int) $shorthand;

        return match ($unit) {
            'G' => $value * 1073741824,
            'M' => $value * 1048576,
            'K' => $value * 1024,
            default => (int) $shorthand,
        };
    }
}
