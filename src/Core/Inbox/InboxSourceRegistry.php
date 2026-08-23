<?php

declare(strict_types=1);

namespace Whity\Core\Inbox;

use Whity\Core\Container\HostWiredService;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\Support\SourceSlug;

/**
 * The catalogue of registered INBOX SOURCES (#881), and the reason routing's
 * recipients are not a surface of their own.
 *
 * `document_route_recipients` IS an inbox — an open row is an item awaiting
 * somebody. Shipping it behind its own endpoint would have been the exact
 * mistake #881 exists to prevent, and #947 says so in one line: "two inbox
 * surfaces would be the same mistake as two audit trails." So routing registers
 * HERE, as core's first source, and the inbox surface belongs to this registry
 * rather than to routing.
 *
 * Same construction as {@see ResourceTypeRegistry}, {@see \Whity\Core\Ou\OuTypeRegistry},
 * {@see \Whity\Core\Queue\JobRegistry} and the ten others. #881 makes exactly
 * that argument — "nothing about an inbox is different in kind… a per-plugin
 * inbox is the odd one out, not the aggregate" — so the shape is borrowed rather
 * than invented.
 *
 * WHAT THIS DECIDES, AND WHAT IT POINTEDLY DOES NOT
 * ------------------------------------------------
 * #881 names three questions that only arise once sources are AGGREGATED, and
 * says each needs deciding before an aggregate ships:
 *
 *   1. ordering across heterogeneous sources ("most urgent" is not "most
 *      recent");
 *   2. per-source failure isolation (one source being down must degrade to
 *      "this source is unavailable", not blank the aggregate);
 *   3. pagination across sources (concatenating page 1 of each is #867 one
 *      level up).
 *
 * None of them is answered here, deliberately. The read surface is ONE SOURCE
 * AT A TIME (`GET /api/me/inbox?source=…`), and `source` is REQUIRED — an
 * unsourced request is a 422, not "core's only source", because answering it
 * would silently become wrong the day a second source registers, and the caller
 * would have no way to notice. That refusal is the honest placeholder for a
 * decision this PR is not entitled to make.
 *
 * What it does provide is the seam: when the aggregate lands it reads this
 * registry and this interface, and the sources themselves do not change.
 *
 * WHY THE PLUGIN-FACING HALF IS NOT HERE YET
 * ------------------------------------------
 * #881 names `PluginInboxSourcesInterface` as the other half, and it is
 * genuinely absent. {@see InboxSourceInterface} and {@see InboxItem} live in
 * `src/Core`, not in `sdk/`, so only core can contribute a source today.
 *
 * That is a deliberate ordering, not an oversight. A plugin contract has to be
 * published before plugins can implement it and cannot be quietly changed
 * afterwards — and question (1) above is precisely a question about this
 * interface: source-declared ranking is the likely answer, which means an extra
 * method. Publishing the contract now would mean publishing one #881 then has to
 * break, in an SDK that is vendored into every plugin and version-pinned
 * (scripts/ci-sdk-version-collision.php). Registering core's own source against
 * a core-only registry proves the shape without asking anybody to build on it.
 *
 * Promoting it later is a move, not a redesign: the two types go to
 * `Whity\Sdk\Inbox`, this class gains a namespacing {@see register()} beside
 * {@see registerCoreSource()}, and the plugin loader gains the same
 * optional-interface block every other contribution point already has.
 *
 * {@see HostWiredService}: an improvised, empty instance would report the
 * caller's inbox as EMPTY rather than as unavailable — and "no items" is the
 * most ordinary answer an inbox has, so nothing would look wrong. That is the
 * silent-failure shape the marker exists for.
 */
final class InboxSourceRegistry implements HostWiredService
{
    /** Source name for inbox sources shipped by core. Reserved. */
    public const CORE_SOURCE = 'core';

    /**
     * Separates a plugin's namespace from its slug, when the plugin-facing half
     * lands: `acme:approvals`.
     *
     * Borrowed from {@see ResourceTypeRegistry} rather than restated, so the
     * catalogues cannot drift into spelling the same plugin's keys two different
     * ways.
     */
    public const NAMESPACE_SEPARATOR = ResourceTypeRegistry::NAMESPACE_SEPARATOR;

    /**
     * Core's routing inbox: the open `document_route_recipients` rows.
     *
     * Underscored rather than hyphenated because the slug grammar every other
     * registry in the platform enforces is `[a-z][a-z0-9_]*`, and a key that
     * only this catalogue could accept would be the start of a second grammar.
     */
    public const CORE_DOCUMENT_ROUTING = 'document_routing';

    /**
     * Registered sources, keyed by canonical key.
     *
     * @var array<string, InboxSourceInterface>
     */
    private array $sources = [];

    /**
     * Which SOURCE registered each key, kept so the catalogue endpoint can say
     * where a source came from — an operator deciding what is safe to uninstall
     * needs to tell core's from a plugin's.
     *
     * @var array<string, string>
     */
    private array $originByKey = [];

    /**
     * Register a source core owns, under its bare key.
     *
     * Bare, because the unprefixed namespace belongs to core — the same
     * reservation {@see ResourceTypeRegistry} makes, and what will stop a
     * plugin shadowing `document_routing` once plugins can register at all.
     *
     * @throws InvalidInboxSourceException On a malformed or duplicate key.
     */
    public function registerCoreSource(string $key, InboxSourceInterface $source): void
    {
        if (!self::isValidSlug($key)) {
            throw InvalidInboxSourceException::forSlug($key);
        }
        if (array_key_exists($key, $this->sources)) {
            throw InvalidInboxSourceException::forDuplicateKey($key);
        }

        $this->sources[$key] = $source;
        $this->originByKey[$key] = self::CORE_SOURCE;
    }

    /**
     * A registered source, or null when nothing registered the key.
     *
     * Null is what turns an unknown `?source=` into a 422 naming the registered
     * keys, rather than an empty list that reads as "you have no items".
     */
    public function get(string $key): ?InboxSourceInterface
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
     * Carries the FIELD MAPPING as well as the key, so a screen wiring an
     * `inbox` block does not have to hardcode the field names
     * {@see InboxItem::toArray()} emits — and so a future source that shapes its
     * items differently can say so rather than silently breaking every block
     * pointed at it. The mapping is identical for every source today, which is
     * the point: it is the contract, published rather than assumed.
     *
     * @return list<array{key: string, label: string, origin: string, item_fields: array<string, string>}>
     */
    public function catalogue(): array
    {
        return array_map(
            fn (string $key): array => [
                'key' => $key,
                'label' => $this->sources[$key]->label(),
                'origin' => $this->originByKey[$key] ?? self::CORE_SOURCE,
                'item_fields' => self::itemFields(),
            ],
            $this->keys()
        );
    }

    /**
     * The `inbox` block prop => item field mapping every source emits.
     *
     * Exactly the props the block type declares (#868): a screen can copy this
     * into the block's configuration and the two cannot disagree, which is the
     * "conforms by construction" property #881 relies on.
     *
     * @return array<string, string>
     */
    public static function itemFields(): array
    {
        return [
            'idField' => 'id',
            'titleField' => 'title',
            'subtitleField' => 'subtitle',
            'timestampField' => 'timestamp',
            'statusField' => 'status',
        ];
    }

    /**
     * A valid bare key: lowercase, starts with a letter, then letters, digits
     * and underscores. No colon — that is the namespace separator the host
     * applies.
     */
    public static function isValidSlug(string $key): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*$/', $key) === 1 && strlen($key) <= 128;
    }

    /**
     * The canonical key a source's bare slug resolves to.
     *
     * Present now, unused until the plugin-facing half lands, so that the
     * namespacing rule is stated in the same place as every other registry's
     * rather than being invented under time pressure later.
     */
    public static function canonicalKey(string $origin, string $slug): string
    {
        if ($origin === self::CORE_SOURCE) {
            return $slug;
        }

        $prefix = SourceSlug::from($origin);

        return $prefix === null ? $slug : $prefix . self::NAMESPACE_SEPARATOR . $slug;
    }
}
