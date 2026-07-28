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

    public function register(string $name, JobInterface $handler): void
    {
        $this->handlers[$name] = $handler;
    }

    public function has(string $name): bool
    {
        return isset($this->handlers[$name]);
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
