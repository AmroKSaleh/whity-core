<?php

namespace Whity\Core\Queue;

/**
 * Queue class for async job processing
 *
 * Handles queuing of async tasks for background processing.
 */
class Queue
{
    /**
     * Push a job onto the queue
     *
     * @param string $queue The queue name
     * @param array $payload The job payload
     * @return void
     */
    public function push(string $queue, array $payload): void
    {
        // Placeholder for queue implementation
        // Will be implemented when async job processing is added
    }
}
