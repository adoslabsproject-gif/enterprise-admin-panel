<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Logging;

/**
 * Log Worker - Async Database Writer
 *
 * Processes logs from Redis queue and writes to database.
 * Run as background process or cron job.
 *
 * USAGE:
 * ```php
 * // As CLI script
 * $worker = new LogWorker($redis, $pdo);
 * $worker->run(); // Runs until stopped
 *
 * // As cron (process batch and exit)
 * $worker = new LogWorker($redis, $pdo);
 * $worker->processBatch(1000);
 * ```
 *
 * PERFORMANCE: Batch INSERT for efficiency (~10ms per 100 logs)
 */
final class LogWorker
{
    /**
     * Batch size for database INSERT
     */
    private const DB_BATCH_SIZE = 100;

    /**
     * Sleep time between empty queue checks (microseconds)
     */
    private const SLEEP_EMPTY_QUEUE = 100000; // 100ms

    /**
     * Maximum runtime in seconds (0 = unlimited)
     */
    private int $maxRuntime = 0;

    private \Redis $redis;
    private \PDO $pdo;
    private string $tableName;
    private bool $running = true;

    /**
     * Create worker instance
     *
     * @param \Redis $redis Redis instance
     * @param \PDO $pdo Database connection
     * @param string $tableName Log table name
     */
    public function __construct(\Redis $redis, \PDO $pdo, string $tableName = 'logs')
    {
        $this->redis = $redis;
        $this->pdo = $pdo;
        $this->tableName = $tableName;
    }

    /**
     * Run worker loop
     *
     * @param int $maxRuntime Maximum runtime in seconds (0 = unlimited)
     */
    public function run(int $maxRuntime = 0): void
    {
        $this->maxRuntime = $maxRuntime;
        $startTime = time();

        // Handle signals for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, fn() => $this->stop());
            pcntl_signal(SIGINT, fn() => $this->stop());
        }

        while ($this->running) {
            // Check max runtime
            if ($this->maxRuntime > 0 && (time() - $startTime) >= $this->maxRuntime) {
                break;
            }

            // Process batch
            $processed = $this->processBatch(self::DB_BATCH_SIZE);

            // Sleep if queue is empty
            if ($processed === 0) {
                usleep(self::SLEEP_EMPTY_QUEUE);
            }

            // Handle signals
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }

    /**
     * Process a batch of logs from queue
     *
     * @param int $limit Maximum entries to process
     * @return int Number of entries processed
     */
    public function processBatch(int $limit = 100): int
    {
        $queueKey = LogFlusher::getRedisQueueKey();
        $entries = [];

        // Pop entries from queue
        for ($i = 0; $i < $limit; $i++) {
            $json = $this->redis->rPop($queueKey);

            if ($json === false) {
                break; // Queue empty
            }

            $entry = json_decode($json, true);

            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        if (empty($entries)) {
            return 0;
        }

        // Write to database
        $this->writeToDB($entries);

        return count($entries);
    }

    /**
     * Write entries to database (batch INSERT)
     *
     * @param array<int, array{channel: string, level: string, message: string, context: array, timestamp: float}> $entries
     */
    private function writeToDB(array $entries): void
    {
        if (empty($entries)) {
            return;
        }

        // Build batch INSERT
        $placeholders = [];
        $values = [];

        foreach ($entries as $entry) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?)';

            $values[] = $entry['channel'];
            $values[] = $entry['level'];
            $values[] = $entry['message'];
            $values[] = json_encode($entry['context'] ?? [], JSON_UNESCAPED_UNICODE);
            $values[] = date('Y-m-d H:i:s', (int) $entry['timestamp']);
            $values[] = date('Y-m-d H:i:s'); // created_at
        }

        $sql = sprintf(
            'INSERT INTO %s (channel, level, message, context, logged_at, created_at) VALUES %s',
            $this->tableName,
            implode(', ', $placeholders)
        );

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($values);
        } catch (\Throwable $e) {
            // Log to stderr (don't lose logs!)
            error_log("[LogWorker] DB write error: " . $e->getMessage());

            // Re-queue failed entries
            $this->requeue($entries);
        }
    }

    /**
     * Re-queue failed entries
     *
     * @param array<int, array> $entries
     */
    private function requeue(array $entries): void
    {
        $queueKey = LogFlusher::getRedisQueueKey() . ':failed';

        foreach ($entries as $entry) {
            $this->redis->lPush($queueKey, json_encode($entry));
        }
    }

    /**
     * Stop worker gracefully
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Get queue length
     */
    public function getQueueLength(): int
    {
        return (int) $this->redis->lLen(LogFlusher::getRedisQueueKey());
    }

    /**
     * Get failed queue length
     */
    public function getFailedQueueLength(): int
    {
        return (int) $this->redis->lLen(LogFlusher::getRedisQueueKey() . ':failed');
    }
}
