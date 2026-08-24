<?php

declare(strict_types=1);

namespace Whity\Mcp\Notifications;

use Whity\Core\Store\SharedStoreInterface;

/**
 * Decides which `notifications/*\/list_changed` a client is owed (#952).
 *
 * MCP clients cache the discovery lists at connection time. Before this, core
 * emitted no signal when the plugin registry reloaded, so a client that
 * connected before a plugin rebuild kept its stale tool definitions for the
 * lifetime of the connection — which is how thirteen records were written
 * double-encoded against a server that had been serving the corrected schema
 * all along.
 *
 * The rule is: a client is owed a notification for a list when the catalogue it
 * would be served does not match the catalogue it was last told about. Because
 * {@see CatalogSignature} is content-derived, that comparison holds no matter
 * which of the eight FrankenPHP workers answers the call — a worker that has not
 * reloaded yet computes the old signature and owes nothing, so no client is ever
 * told about a catalogue the answering worker cannot serve.
 *
 * The "last told about" marker lives in the shared store rather than in worker
 * memory, and that is the whole point: eight workers each holding their own
 * marker would each announce the same change, so one plugin reload would reach a
 * client as up to eight notifications. One shared marker per (client, list,
 * catalogue) makes it exactly one.
 *
 * Nothing here may throw. A notification is an optimisation on top of a correct
 * server; losing one must never cost the caller its response, and the shared
 * store is a database table that can be slow, locked, or missing.
 */
final class ListChangedNotifier
{
    /**
     * How long a client's "already told about this catalogue" marker lives.
     *
     * Long enough that a continuously-connected client is not re-told about a
     * catalogue it already holds; short enough that a client which disappears
     * for a day re-syncs on its own when it comes back. The failure mode at
     * expiry is one redundant `tools/list` round-trip, which is the direction a
     * correctness fix should fail in.
     */
    public const SEEN_TTL_SECONDS = 86400;

    private const METHODS = [
        CatalogSignature::TOOLS     => 'notifications/tools/list_changed',
        CatalogSignature::RESOURCES => 'notifications/resources/list_changed',
        CatalogSignature::PROMPTS   => 'notifications/prompts/list_changed',
    ];

    /** @var \Closure(string): void */
    private readonly \Closure $warn;

    /**
     * @param \Closure(string): void|null $warn Diagnostic sink; defaults to error_log().
     *                                          Inject a capturing closure in tests.
     */
    public function __construct(
        private readonly CatalogSignature $signature,
        private readonly SharedStoreInterface $store,
        private readonly int $seenTtlSeconds = self::SEEN_TTL_SECONDS,
        ?\Closure $warn = null,
    ) {
        $this->warn = $warn ?? static function (string $msg): void { error_log($msg); };
    }

    /**
     * Encoded JSON-RPC notification frames this client is owed, claiming them so
     * no other worker sends the same ones.
     *
     * Returns an empty list when the client is already current, when there is no
     * catalogue to compare, or when the bookkeeping failed — all of which are
     * the same thing to the caller: send nothing extra.
     *
     * @param string $clientKey Stable per-client identity (the token's jti).
     * @return list<string>
     */
    public function drainFor(string $clientKey): array
    {
        return $this->reconcile($clientKey, emit: true);
    }

    /**
     * Record that this client is current WITHOUT emitting anything.
     *
     * Used on `initialize`: a client that just handshook is about to fetch the
     * lists, so telling it they changed would be noise sent before the client has
     * even finished connecting.
     */
    public function markSeen(string $clientKey): void
    {
        $this->reconcile($clientKey, emit: false);
    }

    /** @return list<string> */
    private function reconcile(string $clientKey, bool $emit): array
    {
        try {
            $frames = [];
            foreach ($this->signature->current() as $list => $signature) {
                if (!isset(self::METHODS[$list])) {
                    continue;
                }
                if (!$this->claim($clientKey, $list, $signature)) {
                    continue;
                }

                // The client has just moved to this catalogue, so its markers for
                // the ones it used to hold are dead — and leaving them behind is
                // what would hide a REVERT. A → B → A must announce twice; it can
                // only do that if the marker saying "told about A" does not
                // outlive the move to B.
                $this->retireStaleMarkers($clientKey, $list, $signature);

                if ($emit) {
                    $frames[] = (string) json_encode(
                        ['jsonrpc' => '2.0', 'method' => self::METHODS[$list]],
                        JSON_THROW_ON_ERROR,
                    );
                }
            }

            return $frames;
        } catch (\Throwable $e) {
            // Swallowed on purpose: the caller's JSON-RPC response is already
            // built and correct, and a bookkeeping failure must not turn a good
            // response into an error. Logged so a store outage is visible.
            ($this->warn)('[MCP] list_changed bookkeeping failed: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Claim the (client, list, catalogue) marker, returning true exactly once.
     *
     * The read comes first because the steady state is "already told", and that
     * keeps the hot path off the shared-store write path entirely. The write is
     * still the authority: `increment()` is atomic across workers, so two workers
     * racing on the same client both call it and only the one that gets 1 sends.
     */
    private function claim(string $clientKey, string $list, string $signature): bool
    {
        $key = $this->key($clientKey, $list, $signature);

        if ($this->store->count($key) > 0) {
            return false;
        }

        return $this->store->increment($key, $this->seenTtlSeconds) === 1;
    }

    /**
     * Drop this client's markers for every other catalogue this worker knows of.
     *
     * Scoped to what THIS worker has observed, which is the honest bound: a
     * marker written on behalf of a catalogue no worker in this process ever saw
     * cannot be found by name, and expires with its TTL instead.
     */
    private function retireStaleMarkers(string $clientKey, string $list, string $current): void
    {
        foreach ($this->signature->recent($list) as $stale) {
            if ($stale === $current) {
                continue;
            }
            $this->store->delete($this->key($clientKey, $list, $stale));
        }
    }

    /**
     * The client identity is hashed rather than stored raw: `shared_store` is a
     * global table, and a token jti sitting in it would be one tenant's
     * revocation handle readable from another tenant's row dump. Hashing also
     * fixes the key length, which the VARCHAR(255) primary key cares about.
     */
    private function key(string $clientKey, string $list, string $signature): string
    {
        return 'mcp:listchanged:'
            . substr(hash('sha256', $clientKey), 0, 32)
            . ':' . $list
            . ':' . $signature;
    }
}
