<?php

declare(strict_types=1);

namespace Whity\Core\Container;

/**
 * Marker: the host MUST wire this collaborator; {@see \Whity\app()} may never
 * improvise one.
 *
 * WC-712 bounded the container's auto-instantiation fallback to "concrete class
 * with no REQUIRED constructor arguments" so it "could never improvise a
 * security service". That bound is expressed in constructor shape, and
 * constructor shape turned out to be the wrong proxy for the property that
 * actually matters.
 *
 * {@see \Whity\Core\RBAC\PermissionRegistry} slipped straight through it: its
 * only constructor argument (a HookManager) is OPTIONAL and the class is
 * concrete, so a plugin asking the container for the permission catalogue got a
 * FRESH, EMPTY registry instead of the one the plugin loader had just filled.
 * Nothing threw. Nothing was logged. `exists('some_plugin:manage')` simply
 * answered `false` for a permission the plugin had declared and the loader had
 * accepted, and the plugin failed closed with nothing to diagnose from.
 *
 * The distinguishing property is not "does the constructor take arguments" but
 * **is an empty instance distinguishable from a legitimately empty one**. For a
 * registry it is not: "no permissions registered", "no probes contributed", "no
 * job handler for this name" and "no transport for this channel" are all
 * perfectly ordinary states, so an improvised instance is indistinguishable
 * from a correct one right up to the moment it silently answers the wrong
 * security question. That property cannot be reflected over — a class has to
 * declare it. This interface is that declaration.
 *
 * A class implementing it is NEVER auto-instantiated. An unregistered lookup
 * raises the documented, catchable \RuntimeException naming the class, so the
 * failure is loud, immediate and attributable instead of silent and closed.
 *
 * Implement it on anything whose usefulness comes from state accumulated at
 * boot — registries, catalogues, caches filled by the plugin loader — and
 * register the populated instance in BOTH entry points (`public/index.php` and
 * {@see \Whity\Cli\Commands\BaseCommand::setupKernel()}); a registry wired in
 * only one of them is the divergence bug class this repo has already paid for
 * in #717 and #724.
 *
 * Purely declarative: no methods, no behaviour, nothing to implement.
 */
interface HostWiredService
{
}
