<?php

namespace Whity\Core\Queue;

use Psr\Log\LoggerInterface;

/**
 * Queue class for async job processing
 *
 * Handles queuing of async tasks for background processing.
 * MVP implementation logs to file. Full Redis/Celery integration happens in Phase 2.
 */
class Queue
{
    /**
     * Logger instance
     */
    private static ?LoggerInterface $logger = null;

    /**
     * Set the logger instance for queue logging
     *
     * @param LoggerInterface $logger The PSR-3 logger instance
     * @return void
     */
    public static function setLogger(LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    /**
     * Push a job onto the queue
     *
     * @param string $queueName The queue name
     * @param array $payload The job payload
     * @return void
     */
    public static function push(string $queueName, array $payload): void
    {
        if (self::$logger !== null) {
            self::$logger->info(
                'Async Job Queued on [' . $queueName . ']',
                [
                    'queue' => $queueName,
                    'payload' => $payload,
                    'timestamp' => time(),
                ]
            );
        }
    }
}
