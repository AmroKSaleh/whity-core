<?php

declare(strict_types=1);

namespace Whity\Mcp\Notifications;

use Whity\Mcp\Prompts\Prompt;
use Whity\Mcp\Prompts\PromptArgument;
use Whity\Mcp\Prompts\PromptRegistry;
use Whity\Mcp\Resources\ResourceDeriver;
use Whity\Mcp\Tools\ToolDeriver;

/**
 * Content fingerprint of the three MCP discovery lists (#952).
 *
 * The `list_changed` notifications are driven by what a worker WOULD SERVE, not
 * by an event fired at the moment something reloaded. That is deliberate, and it
 * is what makes the signal survive the FrankenPHP worker pool: the eight workers
 * reload independently, so "a reload happened" is only ever one worker's opinion,
 * whereas "this is the catalogue I am serving" is a fact each worker can state
 * for itself. A worker that has not picked up a plugin change yet keeps computing
 * the old signature and therefore stays silent — it never announces a change it
 * cannot then serve, and it never has to be told what the other seven did.
 *
 * Signatures cover the UNFILTERED catalogue. `tools/list` is additionally
 * RBAC-filtered per caller, so a change here is a superset of the changes any
 * one caller can observe; a caller whose *permissions* changed sees a different
 * list without the catalogue moving, which is a grant change rather than a
 * registry change and is out of this class's scope.
 *
 * Deriving and hashing the whole catalogue on every MCP call would be wasteful,
 * so the result is memoized per worker. {@see invalidate()} drops the memo and is
 * wired to the plugin loader's registry-change seam, which is what keeps the memo
 * exactly as old as the catalogue it describes.
 */
final class CatalogSignature
{
    public const TOOLS     = 'tools';
    public const RESOURCES = 'resources';
    public const PROMPTS   = 'prompts';

    /**
     * How many past signatures per list this worker keeps a record of.
     *
     * Only used to retire a client's stale bookkeeping (see {@see recent()}), so
     * a small window is enough: it needs to span the catalogues a still-connected
     * client might be holding, not the deployment's whole history.
     */
    private const RECENT_LIMIT = 8;

    /**
     * Per-worker memo of the last computed signatures.
     *
     * @var array<string, string>|null
     */
    private ?array $memo = null;

    /**
     * Signatures this worker has observed per list, most recent first.
     *
     * @var array<string, list<string>>
     */
    private array $recent = [];

    public function __construct(
        private readonly ToolDeriver $toolDeriver,
        private readonly ResourceDeriver $resourceDeriver,
        private readonly PromptRegistry $promptRegistry,
    ) {}

    /**
     * Drop the memo so the next {@see current()} re-reads the live catalogue.
     *
     * Wire this to every path that mutates the plugin registry. Calling it more
     * often than necessary only costs one re-hash; calling it too rarely is what
     * makes a worker announce (or fail to announce) the wrong thing.
     */
    public function invalidate(): void
    {
        $this->memo = null;
    }

    /**
     * Signature per discovery list, keyed by the self::TOOLS/RESOURCES/PROMPTS
     * constants.
     *
     * @return array<string, string>
     */
    public function current(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $signatures = [
            // The access map rides along with the tool definitions: a route whose
            // requiredPermission changed serves a different tool list to the same
            // caller, which is a catalogue change even though no tool moved.
            self::TOOLS => $this->hash([
                $this->toolDeriver->deriveTools(),
                $this->toolDeriver->buildAccessMap(),
            ]),
            self::RESOURCES => $this->hash($this->resourceDeriver->deriveResources()),
            self::PROMPTS   => $this->hash($this->promptShapes()),
        ];

        foreach ($signatures as $list => $signature) {
            $this->remember($list, $signature);
        }

        return $this->memo = $signatures;
    }

    /**
     * Signatures this worker has seen for a list, most recent first, current
     * one included.
     *
     * This exists so a client's bookkeeping for catalogues it no longer holds
     * can be retired. Without it, a catalogue that goes A → B → A — an install
     * followed by an uninstall, which is not an exotic sequence — would announce
     * only the first move: the client's record of having been told about A would
     * still be sitting there when the server came back to A, and the client would
     * be left holding B's tool definitions against a server serving A. That is
     * the same shape of staleness as #952 itself.
     *
     * @return list<string>
     */
    public function recent(string $list): array
    {
        return $this->recent[$list] ?? [];
    }

    private function remember(string $list, string $signature): void
    {
        $ring = $this->recent[$list] ?? [];
        // Re-seen signature moves back to the front rather than being duplicated,
        // so a catalogue that flaps between two states cannot push the other one
        // out of the window.
        $ring = array_values(array_filter($ring, static fn (string $s): bool => $s !== $signature));
        array_unshift($ring, $signature);

        $this->recent[$list] = array_slice($ring, 0, self::RECENT_LIMIT);
    }

    /**
     * Project prompts down to exactly what `prompts/list` serves.
     *
     * Prompt objects also carry their message templates, which `prompts/get`
     * returns but `prompts/list` does not. Hashing those too would fire a
     * `prompts/list_changed` for an edit no listing client can see.
     *
     * @return list<array<string, mixed>>
     */
    private function promptShapes(): array
    {
        return array_map(
            static fn (Prompt $p): array => [
                'name'               => $p->name,
                'description'        => $p->description,
                'requiredRole'       => $p->requiredRole,
                'requiredPermission' => $p->requiredPermission,
                'arguments'          => array_map(
                    static fn (PromptArgument $a): array => [
                        'name'        => $a->name,
                        'description' => $a->description,
                        'required'    => $a->required,
                    ],
                    $p->arguments,
                ),
            ],
            $this->promptRegistry->all(),
        );
    }

    /**
     * Hash any JSON-encodable catalogue slice into a short, comparable token.
     *
     * Truncated to 16 hex characters: the value is only ever compared for
     * equality against another signature of the same catalogue, and it becomes
     * part of a shared-store key whose column is VARCHAR(255).
     */
    private function hash(mixed $value): string
    {
        return substr(hash('sha256', (string) json_encode($value, JSON_THROW_ON_ERROR)), 0, 16);
    }
}
