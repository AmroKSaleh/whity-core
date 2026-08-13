<?php

declare(strict_types=1);

namespace Whity\Sdk\Http;

/**
 * HTTP request shape (SDK v1.0)
 *
 * Encapsulates HTTP request data with methods for accessing request properties.
 * Can be created from raw values or from PHP superglobals. Plugin route
 * handlers receive an instance of this type (the host may pass a subclass).
 *
 * @phpstan-consistent-constructor
 */
class Request
{
    /**
     * Well-known attribute name under which the host's first auth middleware
     * stashes the decoded JWT claims (array<string, mixed>|null) for the
     * request, so downstream consumers read them instead of re-decoding the
     * token (WC-159).
     */
    public const ATTR_JWT_CLAIMS = 'jwt.claims';

    private string $method;
    private string $path;
    /** @var array<string, string> */
    private array $headers;
    private string $body;
    public ?object $user = null;

    /**
     * Per-request attribute bag for values derived during middleware processing.
     *
     * Lives on the Request instance (never in statics) so persistent worker
     * runtimes cannot leak attributes across requests.
     *
     * @var array<string, mixed>
     */
    private array $attributes = [];

    /**
     * Per-instance memoized uploaded-files bag.
     *
     * Lives on the Request instance (never in a static) so persistent worker
     * runtimes cannot leak one request's parsed uploads into the next. Null
     * until the first {@see self::getUploadedFiles()} call lazily parses the body.
     *
     * @var array<string, UploadedFile>|null
     */
    private ?array $uploadedFiles = null;

    /**
     * The PHP `$_FILES` superglobal as captured by {@see self::fromGlobals()},
     * or null for a Request built from raw values (tests, plugins).
     *
     * Needed because, under the real runtime (FrankenPHP, mod_php, php-fpm) with
     * `enable_post_data_reading` ON — the default — PHP eagerly parses a
     * `multipart/form-data` body into `$_FILES` and leaves `php://input` EMPTY.
     * The body-based {@see MultipartParser} therefore has nothing to parse, so
     * {@see self::getUploadedFiles()} falls back to this captured `$_FILES` bag.
     * Stored on the instance (never a static) so a persistent worker cannot leak
     * one request's files into the next.
     *
     * @var array<string, mixed>|null
     */
    private ?array $phpFilesSuperglobal = null;

    /**
     * Constructor
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $path Request path
     * @param array<string, string> $headers Request headers (name => value pairs)
     * @param string $body Request body
     */
    public function __construct(string $method, string $path, array $headers = [], string $body = '')
    {
        $this->method = strtoupper($method);
        $this->path = $path;
        $this->headers = $this->normalizeHeaders($headers);
        $this->body = $body;
    }

    /**
     * Get HTTP method
     *
     * @return string HTTP method
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Get request path
     *
     * @return string Request path
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Get a specific header by name (case-insensitive)
     *
     * @param string $name Header name
     * @return string|null Header value or null if not found
     */
    public function getHeader(string $name): ?string
    {
        $normalized = $this->normalizeHeaderName($name);
        return $this->headers[$normalized] ?? null;
    }

    /**
     * Get all headers
     *
     * @return array<string, string> Headers array
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Get request body
     *
     * @return string Request body
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Get the uploaded files from a multipart/form-data request (SDK v1.5).
     *
     * Lazily parses the request body the first time it is called, keyed off the
     * `Content-Type` header + raw body (never PHP's $_FILES superglobal), so it
     * is worker-safe and unit-testable. Each file part's bytes are spilled to a
     * temp file by {@see MultipartParser}; size caps are read from the host
     * environment via {@see MultipartConfig::fromEnvironment()}.
     *
     * Returns an empty array for any non-multipart request (JSON, urlencoded,
     * GET, etc.); those requests' bodies are left entirely untouched.
     *
     * @return array<string, UploadedFile> Uploaded files keyed by multipart field name.
     * @throws \Whity\Sdk\Http\Exception\MultipartException On a malformed body or a cap violation.
     */
    public function getUploadedFiles(): array
    {
        if ($this->uploadedFiles !== null) {
            return $this->uploadedFiles;
        }

        $contentType = $this->getHeader('Content-Type');
        if (!MultipartParser::isMultipart($contentType)) {
            return $this->uploadedFiles = [];
        }

        // Real-runtime path (WC-221): with `enable_post_data_reading` ON (the
        // default for FrankenPHP / php-fpm / mod_php), PHP has ALREADY parsed the
        // multipart body into `$_FILES` and drained `php://input`, so the body
        // this Request was built from is empty. The body-based parser would then
        // find nothing and every upload would 400 even though the file arrived
        // intact. When `fromGlobals()` captured a populated `$_FILES`, adopt it.
        if ($this->body === '' && $this->phpFilesSuperglobal !== null && $this->phpFilesSuperglobal !== []) {
            return $this->uploadedFiles = $this->uploadedFilesFromPhpFiles(
                $this->phpFilesSuperglobal,
                MultipartConfig::fromEnvironment()
            );
        }

        // Body-based path: the raw multipart body is present (e.g. unit tests, or
        // a runtime with post-data-reading disabled). Parse it directly.
        if ($this->body === '') {
            return $this->uploadedFiles = [];
        }

        $parser = new MultipartParser(MultipartConfig::fromEnvironment());
        /** @var string $contentType Narrowed: isMultipart() returns false for null. */
        $result = $parser->parse($contentType, $this->body);

        return $this->uploadedFiles = $result->getUploadedFiles();
    }

    /**
     * Build the uploaded-files bag from a PHP `$_FILES`-shaped array (WC-221).
     *
     * Used only on the real-runtime path where PHP itself parsed the multipart
     * body. Each scalar (single-file) entry whose temp file is within the
     * per-file cap becomes an {@see UploadedFile} pointing at PHP's tmp_name;
     * the installer reads it via `copy()`, so PHP's `move_uploaded_file()`
     * single-move semantics are not required. A part PHP flagged with an error
     * (e.g. {@see UPLOAD_ERR_INI_SIZE}) is preserved with that error code so the
     * caller can surface it. Array-of-files fields are not used by core upload
     * endpoints and are skipped. Kept side-effect-free and array-driven so it is
     * unit-testable without a live `$_FILES`.
     *
     * @param array<string, mixed> $phpFiles A `$_FILES`-shaped array.
     * @param MultipartConfig $config Provides the per-file size cap.
     * @return array<string, UploadedFile> File parts keyed by field name.
     */
    private function uploadedFilesFromPhpFiles(array $phpFiles, MultipartConfig $config): array
    {
        $files = [];
        foreach ($phpFiles as $field => $spec) {
            // Only the scalar single-file shape is supported (the shape core
            // upload endpoints use); skip multi-file array specs.
            if (!is_array($spec) || !isset($spec['tmp_name']) || !is_string($spec['tmp_name'])) {
                continue;
            }

            $error = isset($spec['error']) && is_int($spec['error']) ? $spec['error'] : UPLOAD_ERR_OK;
            $size = isset($spec['size']) && is_int($spec['size']) ? $spec['size'] : 0;

            // A part PHP rejected for size (or any other reason) keeps its error
            // code; a successful part exceeding the SDK per-file cap is downgraded
            // to UPLOAD_ERR_INI_SIZE so the contract matches the body-parse path.
            if ($error === UPLOAD_ERR_OK && $size > $config->getMaxFileBytes()) {
                $error = UPLOAD_ERR_INI_SIZE;
            }

            $files[(string) $field] = new UploadedFile(
                $spec['tmp_name'],
                $size,
                $error,
                isset($spec['name']) && is_string($spec['name']) ? $spec['name'] : null,
                isset($spec['type']) && is_string($spec['type']) ? $spec['type'] : null,
            );
        }

        return $files;
    }

    /**
     * Stash a derived value on the request for downstream consumers.
     *
     * @param string $name Attribute name (e.g. {@see self::ATTR_JWT_CLAIMS}).
     * @param mixed $value Attribute value; null is a valid, distinct value.
     * @return void
     */
    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    /**
     * Read a previously stashed attribute.
     *
     * A stashed null is returned as null (not as $default); use
     * {@see self::hasAttribute()} to distinguish "absent" from "stashed null".
     *
     * @param string $name Attribute name.
     * @param mixed $default Value returned when the attribute is absent.
     * @return mixed The attribute value, or $default when absent.
     */
    public function getAttribute(string $name, mixed $default = null): mixed
    {
        if (!array_key_exists($name, $this->attributes)) {
            return $default;
        }

        return $this->attributes[$name];
    }

    /**
     * Whether an attribute has been stashed (a stashed null counts as present).
     *
     * @param string $name Attribute name.
     * @return bool True when the attribute exists.
     */
    public function hasAttribute(string $name): bool
    {
        return array_key_exists($name, $this->attributes);
    }

    /**
     * Create a Request from PHP superglobals
     *
     * Extracts request data from $_SERVER, $_GET, and php://input.
     *
     * WARNING: php://input can only be read once per request. Subsequent reads
     * will return an empty string. This is a PHP limitation that affects all code
     * using this method. Consider buffering the input if you need to read it
     * multiple times.
     *
     * @return static Request instance populated from superglobals
     */
    public static function fromGlobals(): static
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // Extract path from REQUEST_URI, removing query string
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';

        // Extract headers from $_SERVER
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                // Convert HTTP_HEADER_NAME to Header-Name
                $headerName = substr($key, 5);
                $headerName = str_replace('_', '-', $headerName);
                $headers[$headerName] = $value;
            }
        }

        // Get request body
        $body = file_get_contents('php://input') ?: '';

        $request = new static($method, $path, $headers, $body);

        // Capture $_FILES so getUploadedFiles() can fall back to it when PHP has
        // already drained php://input for a multipart body (WC-221). Copied by
        // value onto the instance; never read from the superglobal afterwards.
        if ($_FILES !== []) {
            $request->phpFilesSuperglobal = $_FILES;
        }

        return $request;
    }

    /**
     * Normalize header names to a consistent format (lowercase with hyphens)
     *
     * @param string $name Header name
     * @return string Normalized header name
     */
    private function normalizeHeaderName(string $name): string
    {
        return HeaderUtil::normalize($name);
    }

    /**
     * Normalize headers array to use consistent key format
     *
     * @param array<string, string> $headers Headers array
     * @return array<string, string> Normalized headers
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[$this->normalizeHeaderName($name)] = $value;
        }
        return $normalized;
    }
}
