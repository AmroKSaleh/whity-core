<?php

declare(strict_types=1);

namespace Whity\Core\Document;

use PDO;
use Whity\Core\Form\FormUploadPolicy;
use Whity\Storage\StorageDriverInterface;
use Whity\Storage\StorageException;
use Whity\Storage\StorageKey;

/**
 * Images and files placed in an editor document (editor M3, E.13; migration
 * 161): writes the bytes to the tenant's storage and keeps the row that is the
 * only way back to them.
 *
 * It follows {@see \Whity\Core\Form\FormUploadStore}'s rules on purpose rather
 * than inventing a second storage story: keys come from {@see StorageKey::build()},
 * the file name is fresh randomness so no part of the address comes from the
 * client, an existing key is a loud refusal rather than an overwrite, the
 * content type is the SNIFFED one and is set at write time, and the row is
 * written after the bytes (an unreferenced object costs storage; a row with no
 * object is an image that silently never loads).
 *
 * What may be stored is {@see FormUploadPolicy}'s list (PDF, PNG, JPEG, sniffed
 * from the leading bytes, 10 MiB): one policy for every upload a person makes.
 * SVG is deliberately not on it, because an SVG served from our origin is a
 * script.
 */
final class DocumentSourceFileStore
{
    public const STORAGE_SEGMENT = 'document-sources';
    private const KEY_ENTROPY_BYTES = 16;

    public function __construct(
        private readonly StorageDriverInterface $storage,
        private readonly PDO $db,
    ) {
    }

    /**
     * Store one upload into a document and return its row.
     *
     * @param string $contentType The SNIFFED type from FormUploadPolicy::assertAcceptable().
     * @return array{id: int, content_type: string, byte_size: int, checksum_sha256: string, filename: ?string}
     * @throws StorageException When the address is taken or the write fails; no row is written.
     */
    public function put(int $tenantId, int $sourceId, string $bytes, string $contentType, ?string $clientFilename, ?int $uploaderProfileId): array
    {
        $suffix = preg_replace('/[^a-z0-9]/', '', strtolower(FormUploadPolicy::extensionFor($contentType))) ?? '';
        $name = bin2hex(random_bytes(self::KEY_ENTROPY_BYTES)) . ($suffix !== '' ? '.' . $suffix : '');
        $key = StorageKey::build($tenantId, self::STORAGE_SEGMENT . '/' . $sourceId, $name);

        if ($this->storage->exists($key)) {
            throw new StorageException('Refusing to overwrite an existing document file; a stored file is immutable.');
        }
        $this->storage->put($key, $bytes, ['ContentType' => $contentType]);

        $filename = self::safeFilename($clientFilename);
        $checksum = hash('sha256', $bytes);
        $stmt = $this->db->prepare(
            'INSERT INTO document_source_files
                 (tenant_id, source_id, storage_key, content_type, byte_size, checksum_sha256, filename, uploaded_by_profile_id, created_at)
             VALUES (:tenant_id, :source_id, :storage_key, :content_type, :byte_size, :checksum_sha256, :filename, :uploaded_by, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':source_id' => $sourceId,
            ':storage_key' => $key,
            ':content_type' => $contentType,
            ':byte_size' => strlen($bytes),
            ':checksum_sha256' => $checksum,
            ':filename' => $filename,
            ':uploaded_by' => $uploaderProfileId,
        ]);

        return [
            'id' => (int) $this->db->lastInsertId(),
            'content_type' => $contentType,
            'byte_size' => strlen($bytes),
            'checksum_sha256' => $checksum,
            'filename' => $filename,
        ];
    }

    /**
     * One file of one document of one tenant. A file id alone is never a
     * capability: it is bound to the document the caller was already allowed
     * to see.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $fileId, int $sourceId, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, storage_key, content_type, byte_size, checksum_sha256, filename
               FROM document_source_files
              WHERE id = :id AND source_id = :source_id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $fileId, ':source_id' => $sourceId, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @throws StorageException When the object is missing or unreadable.
     */
    public function bytes(string $storageKey): string
    {
        return $this->storage->get($storageKey);
    }

    /** The client's name reduced to a safe basename, or null; it never reaches the key. */
    private static function safeFilename(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $name = trim(preg_replace('/[\x00-\x1f\x7f]/', '', basename(str_replace('\\', '/', $raw))) ?? '');

        return $name === '' || $name === '.' || $name === '..' ? null : mb_substr($name, 0, 255);
    }
}
