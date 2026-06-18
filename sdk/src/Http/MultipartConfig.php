<?php

declare(strict_types=1);

namespace Whity\Sdk\Http;

/**
 * Immutable configuration for {@see MultipartParser} (SDK v1.5).
 *
 * Carries the two size caps the parser enforces while consuming a body plus
 * the directory file parts are spilled to. Construct it explicitly in tests
 * for deterministic caps, or via {@see self::fromEnvironment()} to read the
 * host's `UPLOAD_MAX_BYTES` / `UPLOAD_MAX_FILE_BYTES` knobs with hard-coded
 * fallbacks. It holds no per-request state, so it is safe to reuse across
 * requests on a persistent worker.
 */
final class MultipartConfig
{
    /** Default global request-body cap (32 MiB) when the env is unset. */
    public const DEFAULT_MAX_REQUEST_BYTES = 33_554_432;

    /** Default per-file cap (32 MiB) when the env is unset. */
    public const DEFAULT_MAX_FILE_BYTES = 33_554_432;

    private readonly string $tempDir;

    /**
     * @param int $maxRequestBytes Hard cap on the total raw request-body size.
     * @param int $maxFileBytes Hard cap on any single file part's size.
     * @param string|null $tempDir Directory for spilled file parts; defaults to
     *                             the system temp dir when null.
     */
    public function __construct(
        private readonly int $maxRequestBytes,
        private readonly int $maxFileBytes,
        ?string $tempDir = null,
    ) {
        $this->tempDir = $tempDir ?? sys_get_temp_dir();
    }

    /**
     * Build a config from the host environment, with hard-coded fallbacks.
     *
     * Reads `UPLOAD_MAX_BYTES` and `UPLOAD_MAX_FILE_BYTES` from `$_ENV` /
     * `$_SERVER`. Non-numeric or non-positive values fall back to the
     * {@see self::DEFAULT_MAX_REQUEST_BYTES} / {@see self::DEFAULT_MAX_FILE_BYTES}
     * defaults so a misconfigured env can never disable the caps.
     *
     * @return self A config populated from the environment.
     */
    public static function fromEnvironment(): self
    {
        return new self(
            self::readPositiveInt('UPLOAD_MAX_BYTES', self::DEFAULT_MAX_REQUEST_BYTES),
            self::readPositiveInt('UPLOAD_MAX_FILE_BYTES', self::DEFAULT_MAX_FILE_BYTES),
        );
    }

    /**
     * The global request-body cap in bytes.
     *
     * @return int Maximum allowed raw request-body size.
     */
    public function getMaxRequestBytes(): int
    {
        return $this->maxRequestBytes;
    }

    /**
     * The per-file cap in bytes.
     *
     * @return int Maximum allowed size for any single file part.
     */
    public function getMaxFileBytes(): int
    {
        return $this->maxFileBytes;
    }

    /**
     * The directory spilled file parts are written to.
     *
     * @return string Absolute path to the temp directory.
     */
    public function getTempDir(): string
    {
        return $this->tempDir;
    }

    /**
     * Read a positive integer from $_ENV/$_SERVER, falling back when invalid.
     *
     * @param string $key Environment variable name.
     * @param int $fallback Value used when the env is unset or invalid.
     * @return int A positive integer.
     */
    private static function readPositiveInt(string $key, int $fallback): int
    {
        $raw = $_ENV[$key] ?? $_SERVER[$key] ?? null;

        if (is_string($raw) && ctype_digit($raw)) {
            $value = (int) $raw;
            if ($value > 0) {
                return $value;
            }
        }

        return $fallback;
    }
}
