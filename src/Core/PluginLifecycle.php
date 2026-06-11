<?php

declare(strict_types=1);

namespace Whity\Core;

use Throwable;
use Whity\Core\Exception\InvalidPluginStateTransitionException;

/**
 * Per-plugin lifecycle state machine with error tracking.
 *
 * One instance is held per loaded plugin (keyed by the plugin's original FQCN)
 * for the lifetime of a worker process. It tracks the plugin's current state,
 * a counter of consecutive errors, and details of the most recent error so the
 * admin surface can report failures.
 *
 * When a plugin accumulates {@see self::MAX_CONSECUTIVE_ERRORS} consecutive
 * errors it is automatically transitioned to the {@see PluginState::Failed}
 * state, which the error boundary uses to short-circuit further invocations. A
 * successful invocation resets the consecutive-error counter.
 *
 * This is worker-level (not request-level) state: it is acceptable to persist it
 * across requests within a single FrankenPHP worker, and it composes with
 * hot-reload by being reset when a plugin's source changes (see
 * {@see PluginLoader::reload()}).
 */
class PluginLifecycle
{
    /**
     * Number of consecutive errors that trips a plugin into the failed state.
     */
    public const int MAX_CONSECUTIVE_ERRORS = 3;

    /**
     * @var PluginState Current lifecycle state.
     */
    private PluginState $state = PluginState::Discovered;

    /**
     * @var int Count of consecutive errors since the last success/reset.
     */
    private int $consecutiveErrors = 0;

    /**
     * Details of the most recent error, or null if none has occurred.
     *
     * @var array{message: string, type: string, trace: string, at: int}|null
     */
    private ?array $lastError = null;

    /**
     * Allowed forward transitions between lifecycle states.
     *
     * @var array<string, array<int, PluginState>>
     */
    private const TRANSITIONS = [
        'discovered' => [PluginState::Loaded],
        'loaded' => [PluginState::Active, PluginState::Failed, PluginState::Disabled],
        'active' => [PluginState::Failed, PluginState::Disabled],
        'failed' => [PluginState::Active, PluginState::Disabled],
        'disabled' => [PluginState::Active],
    ];

    /**
     * @param string $id The stable plugin identity (original FQCN).
     * @param string|null $name Optional human-readable plugin name.
     */
    public function __construct(
        private readonly string $id,
        private readonly ?string $name = null
    ) {
    }

    /**
     * Get the stable plugin identity.
     *
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get the current lifecycle state.
     *
     * @return PluginState
     */
    public function getState(): PluginState
    {
        return $this->state;
    }

    /**
     * Get the count of consecutive errors since the last success or reset.
     *
     * @return int
     */
    public function getConsecutiveErrors(): int
    {
        return $this->consecutiveErrors;
    }

    /**
     * Get details of the most recent error, or null if none recorded.
     *
     * @return array{message: string, type: string, trace: string, at: int}|null
     */
    public function getLastError(): ?array
    {
        return $this->lastError;
    }

    /**
     * Whether the plugin is currently in the failed state.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->state === PluginState::Failed;
    }

    /**
     * Whether the plugin is currently serving (active) and may be invoked.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->state === PluginState::Active;
    }

    /**
     * Transition the plugin from discovered to loaded.
     *
     * @return void
     * @throws InvalidPluginStateTransitionException If not currently discovered.
     */
    public function markLoaded(): void
    {
        $this->transitionTo(PluginState::Loaded);
    }

    /**
     * Transition the plugin from loaded to active (ready to serve).
     *
     * @return void
     * @throws InvalidPluginStateTransitionException If not currently loaded.
     */
    public function markActive(): void
    {
        $this->transitionTo(PluginState::Active);
    }

    /**
     * Record a successful invocation, resetting the consecutive-error counter.
     *
     * Has no effect on a plugin that is failed or disabled; such a plugin must be
     * explicitly re-enabled before it is considered healthy again.
     *
     * @return void
     */
    public function recordSuccess(): void
    {
        if ($this->state === PluginState::Failed || $this->state === PluginState::Disabled) {
            return;
        }

        $this->consecutiveErrors = 0;
    }

    /**
     * Record an error from a plugin invocation.
     *
     * Increments the consecutive-error counter and captures the error details.
     * Once the counter reaches {@see self::MAX_CONSECUTIVE_ERRORS} the plugin is
     * transitioned to the failed state. Errors recorded against an already
     * failed or disabled plugin are ignored (it is no longer being invoked).
     *
     * @param Throwable $error The throwable raised by the plugin.
     * @return void
     */
    public function recordError(Throwable $error): void
    {
        if ($this->state === PluginState::Failed || $this->state === PluginState::Disabled) {
            return;
        }

        $this->consecutiveErrors++;
        $this->lastError = [
            'message' => $error->getMessage(),
            'type' => $error::class,
            'trace' => $error->getTraceAsString(),
            'at' => time(),
        ];

        if ($this->consecutiveErrors >= self::MAX_CONSECUTIVE_ERRORS) {
            $this->transitionTo(PluginState::Failed);
        }
    }

    /**
     * Quarantine the plugin: transition straight to Failed with a reason.
     *
     * Used by the WC-165 SDK/version compatibility gate when a plugin's
     * declared requirements (SDK constraint, inter-plugin dependencies) are
     * unsatisfied — the plugin is never registered, and the reason is exposed
     * through {@see toArray()} so the admin API can show WHY it failed.
     *
     * @param string $reason Admin-visible explanation of the quarantine.
     * @return void
     */
    public function quarantine(string $reason): void
    {
        if ($this->state === PluginState::Failed || $this->state === PluginState::Disabled) {
            return;
        }

        $this->lastError = [
            'message' => $reason,
            'type' => 'quarantine',
            'trace' => '',
            'at' => time(),
        ];

        $this->transitionTo(PluginState::Failed);
    }

    /**
     * Administratively disable the plugin.
     *
     * @return void
     */
    public function disable(): void
    {
        if ($this->state === PluginState::Disabled) {
            return;
        }

        $this->transitionTo(PluginState::Disabled);
    }

    /**
     * Re-enable a failed or disabled plugin, clearing its error history.
     *
     * Returns the plugin to the active state with a clean error counter so it can
     * serve requests again.
     *
     * @return void
     */
    public function reEnable(): void
    {
        if ($this->state !== PluginState::Failed && $this->state !== PluginState::Disabled) {
            return;
        }

        $this->consecutiveErrors = 0;
        $this->lastError = null;
        $this->transitionTo(PluginState::Active);
    }

    /**
     * Export the lifecycle as a serialisable status array for the admin API.
     *
     * @return array{id: string, name: string, state: string, consecutive_errors: int, last_error: array{message: string, type: string, trace: string, at: int}|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name ?? $this->id,
            'state' => $this->state->value,
            'consecutive_errors' => $this->consecutiveErrors,
            'last_error' => $this->lastError,
        ];
    }

    /**
     * Perform a guarded state transition.
     *
     * @param PluginState $target The desired target state.
     * @return void
     * @throws InvalidPluginStateTransitionException If the transition is not allowed.
     */
    private function transitionTo(PluginState $target): void
    {
        if ($this->state === $target) {
            return;
        }

        $allowed = self::TRANSITIONS[$this->state->value] ?? [];
        if (!in_array($target, $allowed, true)) {
            throw InvalidPluginStateTransitionException::between($this->state, $target);
        }

        $this->state = $target;
    }
}
