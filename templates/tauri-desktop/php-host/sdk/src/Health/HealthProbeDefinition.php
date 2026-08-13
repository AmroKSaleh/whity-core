<?php

declare(strict_types=1);

namespace Whity\Sdk\Health;

use UnexpectedValueException;

/**
 * One health probe a plugin contributes to the host's status page (SDK 1.19).
 *
 * Three things: the BARE key the host namespaces under the plugin's name, the
 * human label the status page shows, and the callable that actually looks.
 *
 *     new HealthProbeDefinition(
 *         'ldap',
 *         'Directory service',
 *         fn (): ProbeResult => $this->pingDirectory(),
 *     );
 *
 * The callable runs inside the host's collector process, in the collector's
 * loop, with NO timeout the host can enforce — PHP cannot interrupt a blocking
 * call from outside it. A probe that hangs on a socket therefore stalls the
 * whole collection pass, so give every network call an explicit, short timeout
 * of your own. Throwing is safe (the host records the component as down and
 * keeps going); blocking forever is not.
 */
final class HealthProbeDefinition
{
    /** @var callable(): ProbeResult */
    private $probe;

    /**
     * @param string $key   Bare slug — lowercase, starts with a letter, then
     *                      letters/digits/underscores. The host prefixes it
     *                      with the plugin's own namespace.
     * @param string $label Display name for the status page ("Directory service").
     * @param callable(): ProbeResult $probe What to run each collection pass.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        callable $probe,
    ) {
        $this->probe = $probe;
    }

    /**
     * Run the probe.
     *
     * A return value that is not a {@see ProbeResult} is a programming error in
     * the plugin, and is raised as one: the host's per-probe boundary turns it
     * into "this component is down", which is the honest reading of a probe
     * that cannot say what it saw.
     */
    public function run(): ProbeResult
    {
        $result = ($this->probe)();

        if (!$result instanceof ProbeResult) {
            throw new UnexpectedValueException(
                "Health probe '{$this->key}' must return a " . ProbeResult::class
                . ', got ' . get_debug_type($result)
            );
        }

        return $result;
    }
}
