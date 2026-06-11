<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * Optional compatibility declaration for plugins (SDK v1.1).
 *
 * A plugin MAY implement this interface — in addition to
 * {@see PluginInterface} — to declare what it needs from the host:
 *
 * - an SDK version constraint ({@see getSdkConstraint()}), evaluated against
 *   {@see Sdk::VERSION}; and
 * - inter-plugin dependencies with version ranges
 *   ({@see getPluginDependencies()}), evaluated against the other plugins'
 *   {@see PluginInterface::getVersion()}.
 *
 * Constraints use composer's version-constraint syntax (`^1.1`, `>=1.2 <2.0`,
 * `~1.3`, …). The host loads satisfied plugins in topological dependency
 * order and quarantines unsatisfied ones (PluginState::Failed with an
 * admin-visible reason) WITHOUT registering any of their capabilities.
 * Plugins that do not implement this interface load unconditionally, in
 * discovery order relative to each other (backward compatible).
 */
interface PluginRequirementsInterface
{
    /**
     * The composer-style constraint the host's SDK version must satisfy.
     *
     * Return an empty string to declare no SDK constraint.
     *
     * @return string e.g. '^1.1' — evaluated against {@see Sdk::VERSION}.
     */
    public function getSdkConstraint(): string;

    /**
     * Inter-plugin dependencies as plugin name => version constraint.
     *
     * Each named plugin must be present (by {@see PluginInterface::getName()})
     * and its {@see PluginInterface::getVersion()} must satisfy the constraint,
     * otherwise this plugin is quarantined. Dependencies define load order:
     * a plugin always registers AFTER everything it depends on.
     *
     * @return array<string, string> e.g. ['HelloWorld' => '^1.0']
     */
    public function getPluginDependencies(): array;
}
