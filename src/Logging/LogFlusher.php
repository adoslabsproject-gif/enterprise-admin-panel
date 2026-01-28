<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Logging;

/**
 * Log Flusher - Multi-Layer Persistence
 *
 * Handles flushing buffered logs to persistent storage with automatic fallback:
 *
 * L1: Redis Queue (if available) → Worker writes to DB async
 * L2: File fallback (always works)
 *
 * ZERO BLOCKING: Logs never slow down requests
 */
final class LogFlusher
{
    /**
     * Redis queue key
     */
    private const REDIS_QUEUE_KEY = 'eap:logs:queue';

    /**
     * Maximum entries per Redis LPUSH
     */
    private const REDIS_BATCH_SIZE = 100;

    /**
     * Redis instance (null if unavailable)
     */
    private ?\Redis $redis;

    /**
     * Log file path
     */
    private string $logPath;

    /**
     * Whether Redis is available
     */
    private bool $redisAvailable;

    /**
     * Create flusher instance
     *
     * @param \Redis|null $redis Redis instance (optional)
     * @param string $logPath Base path for log files
     */
    public function __construct(?\Redis $redis = null, string $logPath = '/tmp/logs')
    {
        $this->redis = $redis;
        $this->logPath = rtrim($logPath, '/');
        $this->redisAvailable = $redis !== null && $this->testRedisConnection();

        // Ensure log directory exists
        if (!is_dir($this->logPath)) {
            @mkdir($this->logPath, 0755, true);
        }
    }

    /**
     * Flush log entries to storage
     *
     * @param array<int, array{channel: string, level: string, message: string, context: array, timestamp: float}> $entries
     * @return bool Success
     */
    public function flush(array $entries): bool
    {
        if (empty($entries)) {
            return true; // Nothing to flush is still success
        }

        // Try Redis first (non-blocking queue for async DB write)
        if ($this->redisAvailable) {
            $success = $this->flushToRedis($entries);
            if ($success) {
                return true; // Redis handled it, worker will write to DB
            }
        }

        // Fallback to file
        return $this->flushToFile($entries);
    }

    /**
     * Flush to Redis queue
     *
     * @param array<int, array{channel: string, level: string, message: string, context: array, timestamp: float}> $entries
     * @return bool Success
     */
    private function flushToRedis(array $entries): bool
    {
        try {
            // Batch entries for efficiency
            $batches = array_chunk($entries, self::REDIS_BATCH_SIZE);

            foreach ($batches as $batch) {
                $jsonEntries = array_map(
                    fn($entry) => json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $batch
                );

                // LPUSH all entries in single command
                $this->redis->lPush(self::REDIS_QUEUE_KEY, ...$jsonEntries);
            }

            return true;
        } catch (\Throwable $e) {
            // Redis failed, mark as unavailable for this request
            $this->redisAvailable = false;
            error_log("[LogFlusher] Redis error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Flush to file (fallback)
     *
     * @param array<int, array{channel: string, level: string, message: string, context: array, timestamp: float}> $entries
     * @return bool Success
     */
    private function flushToFile(array $entries): bool
    {
        // Group entries by date for separate files
        $byDate = [];

        foreach ($entries as $entry) {
            $date = date('Y-m-d', (int) $entry['timestamp']);
            $byDate[$date][] = $entry;
        }

        $success = true;

        foreach ($byDate as $date => $dateEntries) {
            $filePath = $this->logPath . "/app-{$date}.log";

            $lines = array_map(
                fn($entry) => $this->formatLogLine($entry),
                $dateEntries
            );

            $content = implode("\n", $lines) . "\n";

            // Atomic write with lock
            $written = @file_put_contents($filePath, $content, FILE_APPEND | LOCK_EX);

            if ($written === false) {
                error_log("[LogFlusher] Failed to write to {$filePath}");
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Format single log line
     *
     * @param array{channel: string, level: string, message: string, context: array, timestamp: float} $entry
     * @return string Formatted line
     */
    private function formatLogLine(array $entry): string
    {
        $timestamp = date('Y-m-d H:i:s', (int) $entry['timestamp']);
        $microseconds = sprintf('%06d', ($entry['timestamp'] - floor($entry['timestamp'])) * 1000000);

        $contextStr = '';
        if (!empty($entry['context'])) {
            $contextStr = ' ' . json_encode($entry['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return sprintf(
            "[%s.%s] %s.%s: %s%s",
            $timestamp,
            $microseconds,
            $entry['channel'],
            strtoupper($entry['level']),
            $entry['message'],
            $contextStr
        );
    }

    /**
     * Test Redis connection
     */
    private function testRedisConnection(): bool
    {
        if ($this->redis === null) {
            return false;
        }

        try {
            $this->redis->ping();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get Redis queue key (for worker)
     */
    public static function getRedisQueueKey(): string
    {
        return self::REDIS_QUEUE_KEY;
    }

    /**
     * Check if Redis is being used
     */
    public function isUsingRedis(): bool
    {
        return $this->redisAvailable;
    }
}
