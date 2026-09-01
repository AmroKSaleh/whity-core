<?php

declare(strict_types=1);

namespace Whity\Core\Report;

use Whity\Core\Container\HostWiredService;
use Whity\Core\RBAC\ResourceTypeRegistry;

/**
 * What a report may be run over (#947 item 6).
 *
 * Deliberately the same shape as {@see \Whity\Core\Inbox\InboxSourceRegistry},
 * because it is the same problem: a surface that must span first-party and
 * plugin data without either side handing the other a query. That registry's
 * reasoning is not restated here, but two of its decisions are inherited
 * wholesale — core sources register under BARE keys, reserving the unprefixed
 * namespace, and the plugin-facing half is withheld until the contract has
 * survived contact.
 *
 * {@see HostWiredService}, and here the marker earns its keep more than usual.
 * An improvised, empty instance would answer "that source does not exist" for
 * every key — which is indistinguishable from an installation that legitimately
 * has no reports configured, so nothing would look broken. A caller would be
 * told their report is unavailable, would go looking at their own permissions,
 * and would find nothing wrong with them.
 */
final class ReportSourceRegistry implements HostWiredService
{
    /** Origin recorded for sources shipped by core. Reserved. */
    public const CORE_SOURCE = 'core';

    /**
     * Separates a plugin's namespace from its slug when the plugin-facing half
     * lands: `acme:assessments`. Borrowed rather than restated so the
     * catalogues cannot drift into two spellings of one plugin's keys.
     */
    public const NAMESPACE_SEPARATOR = ResourceTypeRegistry::NAMESPACE_SEPARATOR;

    /** Core's first source: the issued documents this tenant can see. */
    public const CORE_DOCUMENTS = 'documents';

    /** @var array<string, ReportSourceInterface> */
    private array $sources = [];

    /** @var array<string, string> */
    private array $originByKey = [];

    /**
     * Register a source core owns, under its bare key.
     *
     * @throws InvalidReportSourceException On a malformed or duplicate key.
     */
    public function registerCoreSource(string $key, ReportSourceInterface $source): void
    {
        if (!self::isValidSlug($key)) {
            throw InvalidReportSourceException::forSlug($key);
        }
        if (array_key_exists($key, $this->sources)) {
            throw InvalidReportSourceException::forDuplicateKey($key);
        }
        if ($source->requiredPermission() === '') {
            // A source with no permission would be readable by anyone holding
            // the route's own gate, which is the whole population. Refused at
            // REGISTRATION rather than at request time: a source that cannot
            // say what it protects should never reach a catalogue, let alone a
            // caller.
            throw InvalidReportSourceException::forMissingPermission($key);
        }

        $this->sources[$key] = $source;
        $this->originByKey[$key] = self::CORE_SOURCE;
    }

    /**
     * A registered source, or null.
     *
     * Null is what turns an unknown key into a 404 naming what IS registered,
     * rather than an empty report that reads as "nothing matched".
     */
    public function get(string $key): ?ReportSourceInterface
    {
        return $this->sources[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->sources);
    }

    /**
     * Every registered key, sorted.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = array_keys($this->sources);
        sort($keys);

        return $keys;
    }

    /**
     * The catalogue a client reads to know what it may ask for.
     *
     * Carries each source's REQUIRED PERMISSION, so a screen can hide a report
     * the reader could not run instead of offering it and collecting a 403 —
     * and so an operator can see, in one place, what each report exposes.
     *
     * @return list<array{key: string, label: string, origin: string, required_permission: string}>
     */
    public function catalogue(string $language): array
    {
        return array_map(
            fn (string $key): array => [
                'key' => $key,
                'label' => $this->sources[$key]->label($language),
                'origin' => $this->originByKey[$key] ?? self::CORE_SOURCE,
                'required_permission' => $this->sources[$key]->requiredPermission(),
            ],
            $this->keys()
        );
    }

    /**
     * The slug grammar every other registry in the platform enforces.
     *
     * A key only this catalogue would accept is the start of a second grammar.
     */
    private static function isValidSlug(string $key): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*$/', $key) === 1;
    }
}
