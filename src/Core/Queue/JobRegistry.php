<?php

declare(strict_types=1);

namespace Whity\Core\Queue;

use Whity\Sdk\JobInterface;

/**
 * Maps a job NAME to the handler that runs it. Core services and plugins
 * register their handlers at boot; {@see JobRunner} resolves a reserved job's
 * `name` to its handler. A job whose name has no registered handler is
 * dead-lettered (it can never run).
 */
final class JobRegistry
{
    /** @var array<string, JobInterface> */
    private array $handlers = [];

    /**
     * Job names that may be enqueued through the public POST /api/jobs API.
     * FAIL-CLOSED: a handler is NOT API-submittable unless it explicitly opts in
     * (register(..., submittable: true)). This stops an authenticated caller
     * from triggering arbitrary INTERNAL handlers (notification, webhook, GC,
     * …) via the generic submission endpoint — only handlers deliberately
     * exposed as tenant-invokable are accepted.
     *
     * @var array<string, true>
     */
    private array $submittable = [];

    public function register(string $name, JobInterface $handler, bool $submittable = false): void
    {
        $this->handlers[$name] = $handler;
        if ($submittable) {
            $this->submittable[$name] = true;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->handlers[$name]);
    }

    /**
     * Whether `$name` may be enqueued via the public job-submission API.
     */
    public function isSubmittable(string $name): bool
    {
        return isset($this->submittable[$name]);
    }

    /**
     * The job names exposed to the public submission API (fail-closed allowlist).
     *
     * @return list<string>
     */
    public function submittableNames(): array
    {
        return array_keys($this->submittable);
    }

    public function get(string $name): ?JobInterface
    {
        return $this->handlers[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->handlers);
    }
}
