<?php

declare(strict_types=1);

namespace Whity\Core\Document\Organizer;

use Whity\Core\Container\HostWiredService;

/**
 * The fact sources the document organizer knows about, and which of them this
 * installation actually has (#978).
 *
 * Unremarkable beside {@see \Whity\Core\RBAC\ResourceTypeRegistry},
 * {@see \Whity\Core\Ou\OuTypeRegistry} and
 * {@see \Whity\Core\DataType\DataTypeRegistry}: core registers its own, and a
 * later core feature or a plugin registers more without editing this file.
 *
 * THE DIFFERENCE FROM THOSE REGISTRIES, AND WHY IT MATTERS
 * --------------------------------------------------------
 * Those hold DECLARATIONS and take them at face value. This one resolves every
 * declaration against the live schema through {@see SchemaPresence} before
 * calling it available. A registry that believed its own declarations would let
 * a substrate be "registered" while the migration that backs it has not run —
 * which is not hypothetical, it is what a rolling deploy looks like from the
 * inside for a few minutes — and a folder computed from a table that is not
 * there is the one outcome #978 exists to prevent.
 *
 * ABSENT SUBSTRATES ARE KEPT, NOT DROPPED
 * ---------------------------------------
 * {@see unavailable()} lists what is registered but unresolvable, with each
 * substrate's own description and provenance. That is deliberate and it is the
 * #951 principle applied to the registry rather than to a button: an absence is
 * EXPLAINED — "this installation does not record X; feature Y adds it" —
 * instead of being silent. On a fully migrated installation the list is empty,
 * which is the honest answer rather than a missing feature.
 *
 * Note what is NOT done with that list: it never becomes a folder. Naming a
 * missing fact source in a diagnostic report is honest; rendering "Awaiting me"
 * next to it and letting it return an empty page is not, and the two are one
 * short step apart. Views requiring an absent substrate are absent, and core
 * deliberately registers no such view — see {@see CoreDocumentViews}.
 *
 * HOST-WIRED, AND THE REASON IS THIS FILE'S OWN ARGUMENT
 * ------------------------------------------------------
 * {@see HostWiredService} because an improvised, empty instance of this is
 * indistinguishable from a correct one. That is exactly the property #978 is
 * about, one layer up: this class exists so that an ABSENT fact source never
 * renders as an EMPTY folder, and an unregistered registry answering "nothing
 * is available" would make the whole organizer render no folders at all —
 * which looks like a tenant with nothing filed rather than a boot that did not
 * happen. The marker makes {@see \Whity\app()} throw instead of improvising.
 *
 * The marker governs IMPROVISATION, not lifetime. Both entry points still build
 * this per request — see public/index.php for why a process-level cache would
 * be per FrankenPHP worker and therefore frozen at whatever the schema looked
 * like when that worker first answered (#701).
 *
 * Availability is resolved ONCE per instance, and instances are per request.
 */
final class DocumentSubstrateRegistry implements HostWiredService
{
    /** @var array<string, DocumentSubstrate> */
    private array $substrates = [];

    /**
     * key => resolved availability, memoised per instance.
     *
     * @var array<string, bool>
     */
    private array $resolved = [];

    public function __construct(private readonly SchemaPresence $schema)
    {
    }

    /**
     * Register a fact source. Re-registering a key REPLACES it rather than
     * throwing: a plugin narrowing core's declaration for its own deployment is
     * a supported thing to want, and a registry that fataled on a duplicate
     * would turn that into a boot failure. The last registration wins and the
     * memoised answer for that key is discarded so the new requirements are the
     * ones measured.
     */
    public function register(DocumentSubstrate $substrate): void
    {
        $this->substrates[$substrate->key] = $substrate;
        unset($this->resolved[$substrate->key]);
    }

    /**
     * Whether this installation actually has the named fact source.
     *
     * An UNREGISTERED key is false, not an error. A view naming a substrate
     * nobody registered is a view nothing can compute, which is precisely the
     * state this returns false for — failing closed is the whole posture.
     */
    public function isAvailable(string $key): bool
    {
        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        $substrate = $this->substrates[$key] ?? null;

        return $this->resolved[$key] = $substrate !== null && $substrate->isSatisfiedBy($this->schema);
    }

    /**
     * Whether EVERY named substrate is available. An empty list is available —
     * a view that needs nothing beyond the documents table itself declares no
     * substrate and is always present.
     *
     * @param list<string> $keys
     */
    public function allAvailable(array $keys): bool
    {
        foreach ($keys as $key) {
            if (!$this->isAvailable($key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Registered substrates this installation does NOT have, for the diagnostic
     * half of the organizer's views response.
     *
     * @return list<DocumentSubstrate>
     */
    public function unavailable(): array
    {
        $missing = [];
        foreach ($this->substrates as $key => $substrate) {
            if (!$this->isAvailable($key)) {
                $missing[] = $substrate;
            }
        }

        return $missing;
    }

    /**
     * Every registered key, available or not. Exposed for tests and for the
     * plugin-facing introspection that will want it once a plugin registers a
     * substrate of its own.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->substrates);
    }
}
