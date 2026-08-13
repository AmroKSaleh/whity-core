<?php

declare(strict_types=1);

namespace Whity\PluginHost;

use Composer\Semver\Semver;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginRequirementsInterface;
use Whity\Sdk\Sdk;

/**
 * Sized-down port of production's PluginLoader::gateAndOrder(): evaluates
 * each candidate's optional PluginRequirementsInterface declaration (SDK
 * constraint, host-core constraint, inter-plugin dependencies) and
 * topologically orders the survivors, exactly like production, but without
 * production's hot-reload/lifecycle bookkeeping — this host gates once at
 * boot, not on every reload.
 *
 * A plugin that fails any gate is QUARANTINED (excluded from the returned
 * ordered list, with a reason) rather than aborting the whole boot — one
 * incompatible plugin must never take hardware printing or every other
 * plugin down with it.
 */
final class PluginRequirementsGate
{
    /**
     * @param list<array{fqcn: class-string<PluginInterface>, plugin: PluginInterface}> $candidates
     * @return array{0: list<array{fqcn: string, plugin: PluginInterface}>, 1: list<array{fqcn: string, name: string, reason: string}>}
     */
    public static function gateAndOrder(array $candidates): array
    {
        $quarantined = [];

        /** @var array<string, array{fqcn: string, plugin: PluginInterface, sdkConstraint: string, coreConstraint: string, deps: array<string, string>}> $byName */
        $byName = [];
        foreach ($candidates as $candidate) {
            $name = $candidate['plugin']->getName();
            if (isset($byName[$name])) {
                $quarantined[] = self::quarantine(
                    $candidate,
                    "duplicate plugin name '{$name}' (already provided by {$byName[$name]['fqcn']})"
                );
                continue;
            }

            try {
                $candidate['sdkConstraint'] = self::sdkConstraintOf($candidate['plugin']);
                $candidate['coreConstraint'] = self::coreConstraintOf($candidate['plugin']);
                $candidate['deps'] = self::dependenciesOf($candidate['plugin']);
            } catch (\Throwable $e) {
                $quarantined[] = self::quarantine(
                    $candidate,
                    'requirements declaration threw ' . get_class($e) . ': ' . $e->getMessage()
                );
                continue;
            }

            $byName[$name] = $candidate;
        }

        // SDK-constraint gate.
        foreach ($byName as $name => $candidate) {
            $constraint = $candidate['sdkConstraint'];
            if ($constraint === '') {
                continue;
            }

            try {
                $satisfied = Semver::satisfies(Sdk::VERSION, $constraint);
            } catch (\UnexpectedValueException) {
                $quarantined[] = self::quarantine($candidate, "declares an unparseable SDK constraint '{$constraint}'");
                unset($byName[$name]);
                continue;
            }

            if (!$satisfied) {
                $quarantined[] = self::quarantine(
                    $candidate,
                    "requires plugin SDK '{$constraint}', but the host provides " . Sdk::VERSION
                );
                unset($byName[$name]);
            }
        }

        // Core-version gate.
        foreach ($byName as $name => $candidate) {
            $constraint = $candidate['coreConstraint'];
            if ($constraint === '') {
                continue;
            }

            try {
                $satisfied = Semver::satisfies(HostCoreVersion::VERSION, $constraint);
            } catch (\UnexpectedValueException) {
                $quarantined[] = self::quarantine($candidate, "declares an unparseable core constraint '{$constraint}'");
                unset($byName[$name]);
                continue;
            }

            if (!$satisfied) {
                $quarantined[] = self::quarantine(
                    $candidate,
                    "requires core '{$constraint}', but the host provides " . HostCoreVersion::VERSION
                );
                unset($byName[$name]);
            }
        }

        // Dependency gate, iterated to a fixpoint so removal cascades.
        do {
            $removed = false;
            foreach ($byName as $name => $candidate) {
                foreach ($candidate['deps'] as $depName => $depConstraint) {
                    if (!isset($byName[$depName])) {
                        $quarantined[] = self::quarantine(
                            $candidate,
                            "depends on plugin '{$depName}' ({$depConstraint}), which is missing or failed"
                        );
                        unset($byName[$name]);
                        $removed = true;
                        break;
                    }

                    $depVersion = $byName[$depName]['plugin']->getVersion();
                    try {
                        $satisfied = Semver::satisfies($depVersion, $depConstraint);
                    } catch (\UnexpectedValueException) {
                        $quarantined[] = self::quarantine(
                            $candidate,
                            "dependency on '{$depName}' is unevaluable (constraint '{$depConstraint}', found version '{$depVersion}')"
                        );
                        unset($byName[$name]);
                        $removed = true;
                        break;
                    }

                    if (!$satisfied) {
                        $quarantined[] = self::quarantine(
                            $candidate,
                            "requires plugin '{$depName}' {$depConstraint}, found {$depVersion}"
                        );
                        unset($byName[$name]);
                        $removed = true;
                        break;
                    }
                }
            }
        } while ($removed);

        // Topological sort (Kahn), stable by discovery order.
        $inDegree = [];
        $dependents = [];
        foreach ($byName as $name => $candidate) {
            $inDegree[$name] = 0;
        }
        foreach ($byName as $name => $candidate) {
            foreach (array_keys($candidate['deps']) as $depName) {
                $inDegree[$name]++;
                $dependents[$depName][] = $name;
            }
        }

        $queue = [];
        foreach ($inDegree as $name => $degree) {
            if ($degree === 0) {
                $queue[] = $name;
            }
        }

        $ordered = [];
        $orderedKeys = [];
        while ($queue !== []) {
            $name = array_shift($queue);
            $ordered[] = ['fqcn' => $byName[$name]['fqcn'], 'plugin' => $byName[$name]['plugin']];
            $orderedKeys[$name] = true;
            foreach ($dependents[$name] ?? [] as $dependent) {
                if (isset($inDegree[$dependent]) && --$inDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        if (count($ordered) < count($byName)) {
            foreach ($byName as $name => $candidate) {
                if (!isset($orderedKeys[$name])) {
                    $quarantined[] = self::quarantine($candidate, 'is part of a plugin dependency cycle and cannot be ordered');
                }
            }
        }

        return [$ordered, $quarantined];
    }

    /**
     * @param array{fqcn: string, plugin: PluginInterface} $candidate
     * @return array{fqcn: string, name: string, reason: string}
     */
    private static function quarantine(array $candidate, string $reason): array
    {
        return [
            'fqcn' => $candidate['fqcn'],
            'name' => $candidate['plugin']->getName(),
            'reason' => $reason,
        ];
    }

    private static function sdkConstraintOf(PluginInterface $plugin): string
    {
        return $plugin instanceof PluginRequirementsInterface ? $plugin->getSdkConstraint() : '';
    }

    private static function coreConstraintOf(PluginInterface $plugin): string
    {
        return $plugin instanceof PluginRequirementsInterface ? $plugin->getCoreConstraint() : '';
    }

    /**
     * @return array<string, string>
     */
    private static function dependenciesOf(PluginInterface $plugin): array
    {
        if (!$plugin instanceof PluginRequirementsInterface) {
            return [];
        }

        $valid = [];
        foreach ($plugin->getPluginDependencies() as $name => $constraint) {
            if (is_string($name) && is_string($constraint)) {
                $valid[$name] = $constraint;
            }
        }

        return $valid;
    }
}
