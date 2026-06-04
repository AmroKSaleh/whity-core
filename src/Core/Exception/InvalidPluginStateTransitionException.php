<?php

declare(strict_types=1);

namespace Whity\Core\Exception;

use LogicException;
use Whity\Core\PluginState;

/**
 * Raised when a plugin lifecycle is asked to perform an illegal state transition.
 *
 * The plugin state machine only permits a fixed set of transitions (see
 * {@see \Whity\Core\PluginLifecycle}). Attempting any other transition indicates
 * a programming error rather than a recoverable runtime condition, hence this is
 * a LogicException.
 */
class InvalidPluginStateTransitionException extends LogicException
{
    /**
     * Build an exception describing the rejected transition.
     *
     * @param PluginState $from The current state.
     * @param PluginState $to   The requested target state.
     * @return self
     */
    public static function between(PluginState $from, PluginState $to): self
    {
        return new self(sprintf(
            'Invalid plugin state transition from "%s" to "%s".',
            $from->value,
            $to->value
        ));
    }
}
