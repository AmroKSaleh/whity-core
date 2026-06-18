<?php

declare(strict_types=1);

namespace Whity\Sdk\Http;

/**
 * A single uploaded file part from a multipart/form-data request (SDK v1.5).
 *
 * Wraps one file part whose bytes have already been spilled to a temp file by
 * the host's multipart parser, so large uploads never live as request strings.
 * Plugins type-hint against this portable shape — not against PHP's $_FILES
 * array — which keeps the upload contract distributable and worker-safe.
 *
 * The metadata accessors follow PHP's uploaded-file semantics: the client
 * filename / media-type are CLIENT-supplied and therefore untrusted, and the
 * error code uses the {@see UPLOAD_ERR_OK} family of constants.
 */
final class UploadedFile
{
    private bool $moved = false;

    /**
     * @param string $streamPath Path to the temp file holding the part's bytes.
     * @param int $size Size of the part in bytes.
     * @param int $error A PHP UPLOAD_ERR_* code; {@see UPLOAD_ERR_OK} (0) on success.
     * @param string|null $clientFilename Client-supplied filename (untrusted), or null.
     * @param string|null $clientMediaType Client-supplied media type (untrusted), or null.
     */
    public function __construct(
        private readonly string $streamPath,
        private readonly int $size,
        private readonly int $error,
        private readonly ?string $clientFilename,
        private readonly ?string $clientMediaType,
    ) {
    }

    /**
     * The client-supplied filename, or null when the part declared none.
     *
     * Untrusted: never use directly as a filesystem path without sanitizing.
     *
     * @return string|null The client filename, or null.
     */
    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    /**
     * The client-supplied media type, or null when the part declared none.
     *
     * Untrusted: verify the real type from the bytes rather than trusting this.
     *
     * @return string|null The client media type, or null.
     */
    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }

    /**
     * The size of the uploaded part in bytes.
     *
     * @return int The byte length of the part.
     */
    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * The upload error code in PHP's UPLOAD_ERR_* family.
     *
     * @return int {@see UPLOAD_ERR_OK} (0) on success, otherwise an error code.
     */
    public function getError(): int
    {
        return $this->error;
    }

    /**
     * The path to the temp file holding this part's bytes.
     *
     * Valid until {@see self::moveTo()} relocates the file.
     *
     * @return string The temp file path.
     */
    public function getStreamPath(): string
    {
        return $this->streamPath;
    }

    /**
     * Relocate the temp file to a target path.
     *
     * May be called at most ONCE; a second call throws because the temp file
     * has already been consumed (mirroring PSR-7 / PHP's move_uploaded_file
     * single-move semantics).
     *
     * @param string $targetPath Destination path for the file.
     * @return void
     * @throws \RuntimeException If already moved, or the relocation fails.
     */
    public function moveTo(string $targetPath): void
    {
        if ($this->moved) {
            throw new \RuntimeException('Uploaded file has already been moved.');
        }

        if (!is_file($this->streamPath)) {
            throw new \RuntimeException(
                'Cannot move uploaded file: source stream is missing.'
            );
        }

        if (!@rename($this->streamPath, $targetPath)) {
            throw new \RuntimeException(
                sprintf('Failed to move uploaded file to "%s".', $targetPath)
            );
        }

        $this->moved = true;
    }
}
