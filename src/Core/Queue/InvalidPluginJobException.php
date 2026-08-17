<?php

declare(strict_types=1);

namespace Whity\Core\Queue;

use InvalidArgumentException;

/**
 * Raised when a plugin's declared job does not conform to what
 * {@see JobRegistry::registerFromSource()} accepts.
 *
 * A valid declared job name is lowercase, starts with a letter, and continues
 * with letters, digits, underscores or dots — `sync`, `catalog.sync`, the shape
 * core's own `core.notifications.deliver` already uses. It carries NO colon: the
 * colon is the namespace separator the host applies, so allowing one in a
 * declared name would let a plugin write its own prefix and claim another
 * plugin's namespace.
 */
class InvalidPluginJobException extends InvalidArgumentException
{
    /** A name that failed format validation. */
    public static function forJobName(string $name): self
    {
        return new self(
            "Invalid plugin job name '{$name}': expected lowercase 'job_name' or "
            . "'job.name' format (letters, digits, underscores, dots; no colon)"
        );
    }

    /**
     * A caller other than core tried to register under the reserved `core`
     * source, which would let it mint UNPREFIXED names and shadow a core job.
     */
    public static function forReservedSource(string $source): self
    {
        return new self(
            "Source '{$source}' is reserved for core job handlers; "
            . 'plugins are namespaced under their own plugin name'
        );
    }

    /**
     * A source name from which no usable namespace prefix could be derived, so
     * its handlers would be stored unprefixed and could collide with core.
     */
    public static function forSource(string $source): self
    {
        return new self(
            "Source '{$source}' yields no usable namespace prefix; a plugin job "
            . 'source must start with a letter'
        );
    }

    /** Something other than a JobInterface appeared in the declaration. */
    public static function forHandler(string $name, mixed $handler): self
    {
        return new self(
            "Plugin job '{$name}' must be declared as a "
            . \Whity\Sdk\JobInterface::class
            . ', got ' . get_debug_type($handler)
        );
    }

    /**
     * The namespaced name is wider than `jobs.name`, so the handler could be
     * registered but never enqueued — a job that exists and can never run.
     */
    public static function forOversizedName(string $name): self
    {
        return new self(
            "Plugin job name '{$name}' is longer than "
            . JobRegistry::MAX_NAME_LENGTH
            . ' characters once namespaced, so it could never be enqueued'
        );
    }

    /**
     * The canonical name is already owned by a different source.
     *
     * Two plugin names can reduce to the same slug (`Acme\Plugin` and
     * `Globex\Plugin` are both `plugin`), so namespacing makes collisions rare
     * rather than impossible. FIRST REGISTRATION WINS — the same rule MCP prompt
     * names and frontend feature ids already use — and the loser is refused
     * loudly instead of silently taking over work that is not its own.
     */
    public static function forTakenName(string $name, string $source): self
    {
        return new self(
            "Plugin job name '{$name}' is already registered by another source, so "
            . "'{$source}' cannot claim it (first registration wins)"
        );
    }

    /**
     * A submittable declaration naming a job the plugin does not ship.
     *
     * Refused rather than ignored: it reads as an API-exposed job and is not
     * one, so the plugin would ship believing a surface exists that does not.
     */
    public static function forUnknownSubmittable(string $name): self
    {
        return new self(
            "Plugin job '{$name}' is declared API-submittable but is not one of the "
            . 'jobs this plugin declares; submittable names are the BARE names from getJobs()'
        );
    }
}
