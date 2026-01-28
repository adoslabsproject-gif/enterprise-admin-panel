<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Logging;

/**
 * In-Memory Log Buffer (L0 Cache)
 *
 * PERFORMANCE: ~0.001ms per log entry
 *
 * Accumulates log entries in memory during request lifecycle.
 * Flushes to persistent storage at request end or when buffer is full.
 *
 * FEATURES:
 * - Zero I/O during request (all in memory)
 * - Automatic flush on shutdown
 * - Configurable buffer size
 * - Memory-safe (auto-flush when limit reached)
 */
final class LogBuffer
{
    /**
     * Maximum buffer entries before auto-flush
     */
    private const MAX_BUFFER_SIZE = 1000;

    /**
     * Maximum memory usage (bytes) before auto-flush
     */
    private const MAX_MEMORY_BYTES = 5 * 1024 * 1024; // 5MB

    /**
     * Singleton instance
     */
    private static ?self $instance = null;

    /**
     * Log entries buffer
     * @var array<int, array{channel: string, level: string, message: string, context: array, timestamp: float}>
     */
    private array $buffer = [];

    /**
     * Flush handlers (called when buffer is flushed)
     * @var array<int, callable>
     */
    private array $flushHandlers = [];

    /**
     * Whether shutdown handler is registered
     */
    private bool $shutdownRegistered = false;

    /**
     * Estimated memory usage of buffer
     */
    private int $estimatedMemory = 0;

    private function __construct()
    {
    }

    /**
     * Get singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Add log entry to buffer
     *
     * PERFORMANCE: ~0.001ms (memory only)
     *
     * @param string $channel Log channel
     * @param string $level PSR-3 log level
     * @param string $message Log message
     * @param array<string, mixed> $context Additional context
     */
    public function add(string $channel, string $level, string $message, array $context = []): void
    {
        // Register shutdown handler on first log
        if (!$this->shutdownRegistered) {
            $this->registerShutdownHandler();
        }

        $entry = [
            'channel' => $channel,
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'timestamp' => microtime(true),
        ];

        $this->buffer[] = $entry;

        // Estimate memory (rough calculation)
        $this->estimatedMemory += strlen($message) + strlen($channel) + 200;

        // Auto-flush if limits reached
        if (count($this->buffer) >= self::MAX_BUFFER_SIZE || $this->estimatedMemory >= self::MAX_MEMORY_BYTES) {
            $this->flush();
        }
    }

    /**
     * Register flush handler
     *
     * Handler signature: function(array $entries): void
     *
     * @param callable $handler Flush handler
     */
    public function onFlush(callable $handler): void
    {
        $this->flushHandlers[] = $handler;
    }

    /**
     * Flush buffer to handlers
     *
     * Called automatically on:
     * - Request shutdown
     * - Buffer size limit reached
     * - Memory limit reached
     * - Manual call
     */
    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $entries = $this->buffer;
        $this->buffer = [];
        $this->estimatedMemory = 0;

        // Call all flush handlers
        foreach ($this->flushHandlers as $handler) {
            try {
                $handler($entries);
            } catch (\Throwable $e) {
                // Never let logging crash the application
                error_log("[LogBuffer] Flush handler error: " . $e->getMessage());
            }
        }
    }

    /**
     * Get current buffer size
     */
    public function getBufferSize(): int
    {
        return count($this->buffer);
    }

    /**
     * Get estimated memory usage
     */
    public function getEstimatedMemory(): int
    {
        return $this->estimatedMemory;
    }

    /**
     * Clear buffer without flushing
     */
    public function clear(): void
    {
        $this->buffer = [];
        $this->estimatedMemory = 0;
    }

    /**
     * Get buffer contents (for testing/debugging)
     *
     * @return array<int, array{channel: string, level: string, message: string, context: array, timestamp: float}>
     */
    public function getBuffer(): array
    {
        return $this->buffer;
    }

    /**
     * Register shutdown handler for automatic flush
     */
    private function registerShutdownHandler(): void
    {
        $this->shutdownRegistered = true;

        register_shutdown_function(function (): void {
            $this->flush();
        });
    }

    /**
     * Reset instance (for testing)
     */
    public static function resetInstance(): void
    {
        if (self::$instance !== null) {
            self::$instance->clear();
            self::$instance->flushHandlers = [];
        }
        self::$instance = null;
    }
}
