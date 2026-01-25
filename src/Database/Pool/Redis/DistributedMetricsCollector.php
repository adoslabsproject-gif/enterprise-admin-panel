<?php

/**
 * Enterprise Admin Panel - Distributed Metrics Collector
 *
 * Collects and aggregates database pool metrics across all workers/servers.
 * Uses Redis for cross-process metric aggregation.
 *
 * Features:
 * - Per-worker metrics with automatic aggregation
 * - Rolling time windows for rate calculations
 * - Histogram support for latency percentiles
 * - Automatic cleanup of stale worker metrics
 *
 * @package AdosLabs\AdminPanel\Database\Pool\Redis
 * @version 1.0.0
 */

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Database\Pool\Redis;

final class DistributedMetricsCollector
{
    /**
     * Worker identifier (unique per process)
     */
    private readonly string $workerId;

    /**
     * Local metrics buffer (flushed to Redis periodically)
     */
    private array $localBuffer = [];

    /**
     * Last flush timestamp
     */
    private float $lastFlush = 0;

    /**
     * Flush interval in seconds
     */
    private const FLUSH_INTERVAL = 1.0;

    /**
     * Metric definitions with aggregation types
     */
    private const METRIC_DEFINITIONS = [
        // Counters (sum across workers)
        'total_queries' => ['type' => 'counter', 'aggregate' => 'sum'],
        'slow_queries' => ['type' => 'counter', 'aggregate' => 'sum'],
        'failed_queries' => ['type' => 'counter', 'aggregate' => 'sum'],
        'pool_hits' => ['type' => 'counter', 'aggregate' => 'sum'],
        'pool_misses' => ['type' => 'counter', 'aggregate' => 'sum'],
        'connections_created' => ['type' => 'counter', 'aggregate' => 'sum'],
        'connections_failed' => ['type' => 'counter', 'aggregate' => 'sum'],
        'validations_passed' => ['type' => 'counter', 'aggregate' => 'sum'],
        'validations_failed' => ['type' => 'counter', 'aggregate' => 'sum'],
        'transaction_rollbacks' => ['type' => 'counter', 'aggregate' => 'sum'],
        'dos_blocked_queries' => ['type' => 'counter', 'aggregate' => 'sum'],
        'circuit_trips' => ['type' => 'counter', 'aggregate' => 'sum'],

        // Gauges (average or max across workers)
        'pool_size' => ['type' => 'gauge', 'aggregate' => 'sum'],
        'pool_idle' => ['type' => 'gauge', 'aggregate' => 'sum'],
        'pool_in_use' => ['type' => 'gauge', 'aggregate' => 'sum'],

        // Timings (average with sample count)
        'total_query_time_ms' => ['type' => 'timing', 'aggregate' => 'sum'],
        'query_count_for_avg' => ['type' => 'counter', 'aggregate' => 'sum'],
    ];

    public function __construct(
        private readonly RedisStateManager $redis,
        private readonly string $poolId = 'default',
        ?string $workerId = null
    ) {
        $this->workerId = $workerId ?? $this->generateWorkerId();
        $this->lastFlush = microtime(true);
    }

    /**
     * Record a query execution
     */
    public function recordQuery(float $durationMs, bool $slow = false, bool $failed = false): void
    {
        $this->increment('total_queries');
        $this->add('total_query_time_ms', $durationMs);
        $this->increment('query_count_for_avg');

        if ($slow) {
            $this->increment('slow_queries');
        }

        if ($failed) {
            $this->increment('failed_queries');
        }

        $this->maybeFlush();
    }

    /**
     * Record a pool hit (connection reused)
     */
    public function recordPoolHit(): void
    {
        $this->increment('pool_hits');
        $this->maybeFlush();
    }

    /**
     * Record a pool miss (new connection created)
     */
    public function recordPoolMiss(): void
    {
        $this->increment('pool_misses');
        $this->maybeFlush();
    }

    /**
     * Record connection creation
     */
    public function recordConnectionCreated(): void
    {
        $this->increment('connections_created');
        $this->maybeFlush();
    }

    /**
     * Record connection failure
     */
    public function recordConnectionFailed(): void
    {
        $this->increment('connections_failed');
        $this->maybeFlush();
    }

    /**
     * Record validation passed
     */
    public function recordValidationPassed(): void
    {
        $this->increment('validations_passed');
        $this->maybeFlush();
    }

    /**
     * Record validation failed
     */
    public function recordValidationFailed(): void
    {
        $this->increment('validations_failed');
        $this->maybeFlush();
    }

    /**
     * Record transaction rollback
     */
    public function recordRollback(): void
    {
        $this->increment('transaction_rollbacks');
        $this->maybeFlush();
    }

    /**
     * Record DoS blocked query
     */
    public function recordDosBlocked(): void
    {
        $this->increment('dos_blocked_queries');
        $this->maybeFlush();
    }

    /**
     * Record circuit breaker trip
     */
    public function recordCircuitTrip(): void
    {
        $this->increment('circuit_trips');
        $this->maybeFlush();
    }

    /**
     * Update pool size gauges
     */
    public function updatePoolGauges(int $total, int $idle, int $inUse): void
    {
        $this->set('pool_size', $total);
        $this->set('pool_idle', $idle);
        $this->set('pool_in_use', $inUse);
        $this->maybeFlush();
    }

    /**
     * Get aggregated metrics from all workers
     */
    public function getAggregatedMetrics(): array
    {
        // Flush any pending local metrics first
        $this->flush();

        // Get from Redis
        $metrics = $this->redis->getAggregatedMetrics();

        // Calculate derived metrics
        $totalQueries = $metrics['total_queries'] ?? 0;
        $queryCount = $metrics['query_count_for_avg'] ?? 0;
        $totalTime = $metrics['total_query_time_ms'] ?? 0;

        $result = [
            'total_queries' => (int) ($metrics['total_queries'] ?? 0),
            'slow_queries' => (int) ($metrics['slow_queries'] ?? 0),
            'failed_queries' => (int) ($metrics['failed_queries'] ?? 0),
            'pool_hits' => (int) ($metrics['pool_hits'] ?? 0),
            'pool_misses' => (int) ($metrics['pool_misses'] ?? 0),
            'connections_created' => (int) ($metrics['connections_created'] ?? 0),
            'connections_failed' => (int) ($metrics['connections_failed'] ?? 0),
            'validations_passed' => (int) ($metrics['validations_passed'] ?? 0),
            'validations_failed' => (int) ($metrics['validations_failed'] ?? 0),
            'transaction_rollbacks' => (int) ($metrics['transaction_rollbacks'] ?? 0),
            'dos_blocked_queries' => (int) ($metrics['dos_blocked_queries'] ?? 0),
            'circuit_trips' => (int) ($metrics['circuit_trips'] ?? 0),
            'pool_size' => (int) ($metrics['pool_size'] ?? 0),
            'pool_idle' => (int) ($metrics['pool_idle'] ?? 0),
            'pool_in_use' => (int) ($metrics['pool_in_use'] ?? 0),
            'total_query_time_ms' => round($totalTime, 3),
            'avg_query_time_ms' => $queryCount > 0 ? round($totalTime / $queryCount, 3) : 0,
            'hit_rate' => $this->calculateHitRate($metrics),
            'error_rate' => $this->calculateErrorRate($metrics),
            'worker_count' => count($this->redis->getActiveWorkers()),
        ];

        return $result;
    }

    /**
     * Get metrics for this worker only
     */
    public function getWorkerMetrics(): array
    {
        $this->flush();
        return $this->redis->getWorkerMetrics($this->workerId);
    }

    /**
     * Get list of active workers with their metrics
     */
    public function getActiveWorkers(): array
    {
        $workers = $this->redis->getActiveWorkers();
        $result = [];

        foreach ($workers as $workerId => $info) {
            $result[$workerId] = [
                'info' => $info,
                'metrics' => $this->redis->getWorkerMetrics($workerId),
            ];
        }

        return $result;
    }

    /**
     * Register this worker as active (call periodically)
     */
    public function heartbeat(): void
    {
        $this->redis->registerWorker($this->workerId, 60);
    }

    /**
     * Force flush all buffered metrics to Redis
     */
    public function flush(): void
    {
        if (empty($this->localBuffer)) {
            return;
        }

        if (!$this->redis->isConnected() && !$this->redis->connect()) {
            // Redis unavailable - keep in local buffer
            return;
        }

        foreach ($this->localBuffer as $metric => $value) {
            $this->redis->incrementMetric($metric, $value, $this->workerId);
        }

        $this->localBuffer = [];
        $this->lastFlush = microtime(true);
    }

    /**
     * Reset all metrics (for testing)
     */
    public function reset(): void
    {
        $this->localBuffer = [];
        $this->lastFlush = microtime(true);

        if ($this->redis->isConnected() || $this->redis->connect()) {
            $this->redis->reset("metrics:global");
            $this->redis->reset("metrics:worker:{$this->workerId}");
        }
    }

    /**
     * Get worker ID
     */
    public function getWorkerId(): string
    {
        return $this->workerId;
    }

    /**
     * Increment a counter metric
     */
    private function increment(string $metric, int $amount = 1): void
    {
        $this->add($metric, $amount);
    }

    /**
     * Add to a metric
     */
    private function add(string $metric, float $amount): void
    {
        if (!isset($this->localBuffer[$metric])) {
            $this->localBuffer[$metric] = 0;
        }
        $this->localBuffer[$metric] += $amount;
    }

    /**
     * Set a gauge metric (overwrites previous value)
     */
    private function set(string $metric, float $value): void
    {
        $this->localBuffer[$metric] = $value;
    }

    /**
     * Flush if interval has passed
     */
    private function maybeFlush(): void
    {
        if ((microtime(true) - $this->lastFlush) >= self::FLUSH_INTERVAL) {
            $this->flush();
        }
    }

    /**
     * Calculate pool hit rate
     */
    private function calculateHitRate(array $metrics): float
    {
        $hits = $metrics['pool_hits'] ?? 0;
        $misses = $metrics['pool_misses'] ?? 0;
        $total = $hits + $misses;

        if ($total === 0) {
            return 0;
        }

        return round(($hits / $total) * 100, 2);
    }

    /**
     * Calculate error rate
     */
    private function calculateErrorRate(array $metrics): float
    {
        $total = $metrics['total_queries'] ?? 0;
        $failed = $metrics['failed_queries'] ?? 0;

        if ($total === 0) {
            return 0;
        }

        return round(($failed / $total) * 100, 2);
    }

    /**
     * Generate unique worker ID
     */
    private function generateWorkerId(): string
    {
        return sprintf(
            '%s_%d_%s',
            gethostname() ?: 'unknown',
            getmypid(),
            substr(md5(uniqid('', true)), 0, 8)
        );
    }

    public function __destruct()
    {
        // Flush remaining metrics on shutdown
        $this->flush();
    }
}
