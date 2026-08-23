<?php

declare(strict_types=1);

namespace Whity\Core\Document;

/**
 * The wire shape of a document and its artifacts (#947 item 1).
 *
 * Shared by {@see \Whity\Api\DocumentRenderApiHandler} (which returns a
 * freshly-issued document) and {@see \Whity\Api\DocumentsApiHandler} (which
 * lists, shows and re-renders them), so a document serialises identically
 * whichever route produced it. Two hand-rolled copies of the same envelope
 * drift, and the one the OpenAPI schema describes is only ever one of them.
 *
 * WHAT IS DELIBERATELY NOT ON THE WIRE
 * ------------------------------------
 * `storage_key` never leaves the server. {@see \Whity\Storage\StorageDriverInterface}
 * states the invariant — callers never receive backend paths or bucket
 * addresses — and it is not a formality here: the key is the only thing an
 * attacker would need to attempt a direct fetch against a misconfigured public
 * bucket, and it tells a client nothing it can use. `content_url` is the
 * durable reference instead: an ordinary API path, RBAC-checked on every hit,
 * identical whether the tenant is on local disk, on an S3-compatible store, or
 * on its own bucket through the storage entitlement. A signed URL was the
 * alternative and is not available uniformly — {@see \Whity\Storage\LocalStorageDriver}
 * throws on temporaryUrl(), so half the deployments would have got a reference
 * the other half could not produce.
 *
 * `checksum_sha256` IS on the wire: it is what lets a consumer prove the bytes
 * it downloaded are the bytes that were issued, which is the entire claim this
 * subsystem makes.
 */
final class DocumentPresenter
{
    private function __construct()
    {
    }

    /**
     * One document, with its artifacts newest-first.
     *
     * THE ORGANIZER FIELDS ARE ABSENT RATHER THAN FALSE (#978)
     * --------------------------------------------------------
     * `collection_ids` and `starred` appear only when the caller supplied a
     * filing to render — which the organizer's list and show routes do and the
     * RENDER route does not, because it has just issued a document and has not
     * asked anyone whether they filed it.
     *
     * The tempting alternative is to default them to `[]` and `false`, and it is
     * the one thing this codebase keeps refusing to do: `starred: false` is a
     * claim ("you have not starred this") that the route did not compute, and a
     * client cannot tell it apart from one that did. #756 is the same mistake
     * with a fabricated changelog and #951 with a control hidden for three
     * different reasons. An absent key means "not computed here"; a present
     * `false` means "asked, and no".
     *
     * @param array<string, mixed>       $document            A normalized `documents` row.
     * @param list<array<string, mixed>> $artifacts           Normalized `document_artifacts` rows, newest first.
     * @param list<int>|null             $collectionIds       The CALLER's collections holding this document,
     *                                                        or null when filing was not computed.
     * @param int|null                   $starredCollectionId The caller's starred collection id, used to
     *                                                        derive `starred` from the same list rather
     *                                                        than from a second query.
     * @return array<string, mixed>
     */
    public static function document(
        array $document,
        array $artifacts,
        ?array $collectionIds = null,
        ?int $starredCollectionId = null,
    ): array {
        $id = (int) $document['id'];

        $filing = $collectionIds === null ? [] : [
            'collection_ids' => array_values($collectionIds),
            'starred' => $starredCollectionId !== null && in_array($starredCollectionId, $collectionIds, true),
        ];

        return [
            'id'                   => $id,
            'tenant_id'            => (int) $document['tenant_id'],
            'document_template_id' => $document['document_template_id'],
            'template_name'        => $document['template_name'],
            'title'                => $document['title'],
            'origin_ou_id'         => $document['origin_ou_id'],
            'created_by'           => $document['created_by'],
            'created_at'           => $document['created_at'],
            // The current artifact's bytes. Stable across corrections — it
            // always resolves to the newest — which is what a link pasted into
            // a message should do.
            'content_url'          => $artifacts === [] ? null : self::documentContentUrl($id),
            'artifacts'            => array_map(
                static fn (array $a): array => self::artifact($a),
                $artifacts
            ),
        ] + $filing;
    }

    /**
     * One of the caller's collections.
     *
     * `profile_id` is on the wire even though it can only ever be the caller's:
     * a client caching a rail across an account switch has one field to check
     * rather than an assumption to hold. `item_count` is how many documents are
     * FILED, which is not always how many the owner may still read — see
     * {@see DocumentCollectionRepository::listOwned()}.
     *
     * @param array<string, mixed> $collection
     * @return array<string, mixed>
     */
    public static function collection(array $collection): array
    {
        $presented = [
            'id' => (int) $collection['id'],
            'tenant_id' => (int) $collection['tenant_id'],
            'profile_id' => (int) $collection['profile_id'],
            'name' => (string) $collection['name'],
            // Null for an ordinary collection somebody made; `starred` for the
            // one the star control addresses. It is the collection's IDENTITY,
            // which is why the API refuses to rename or delete a keyed one and
            // why the name beside it is free to change.
            'system_key' => $collection['system_key'] ?? null,
            'created_at' => (string) $collection['created_at'],
        ];

        if (array_key_exists('item_count', $collection)) {
            $presented['item_count'] = (int) $collection['item_count'];
        }

        return $presented;
    }

    /**
     * One artifact.
     *
     * Its `content_url` addresses THIS artifact specifically and keeps working
     * after a later render supersedes it — that permanence is the observable
     * form of the immutability guarantee.
     *
     * @param array<string, mixed> $artifact
     * @return array<string, mixed>
     */
    public static function artifact(array $artifact): array
    {
        return [
            'id'              => (int) $artifact['id'],
            'document_id'     => (int) $artifact['document_id'],
            'content_type'    => $artifact['content_type'],
            'byte_size'       => $artifact['byte_size'],
            'checksum_sha256' => $artifact['checksum_sha256'],
            'rendered_by'     => $artifact['rendered_by'],
            'rendered_at'     => $artifact['rendered_at'],
            'content_url'     => self::artifactContentUrl((int) $artifact['document_id'], (int) $artifact['id']),
        ];
    }

    /** The durable reference to a document's CURRENT artifact. */
    public static function documentContentUrl(int $documentId): string
    {
        return "/api/documents/{$documentId}/content";
    }

    /** The durable reference to ONE artifact, superseded or not. */
    public static function artifactContentUrl(int $documentId, int $artifactId): string
    {
        return "/api/documents/{$documentId}/artifacts/{$artifactId}/content";
    }

    /**
     * A filename-safe slug of a document/template name.
     *
     * Lifted from the render handler unchanged so the download name a persisted
     * document offers matches the one an ephemeral render already offered for
     * the same template.
     */
    public static function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'document';
    }
}
