<?php

declare(strict_types=1);

namespace Whity\Sdk\Http;

/**
 * HTTP response shape (SDK v1.0)
 *
 * Encapsulates HTTP response data with methods for setting status codes,
 * bodies, and headers. Provides convenience factories for creating JSON and
 * error responses. Plugin route handlers return an instance of this type.
 *
 * The static factories use late static binding (`new static`) so a host
 * subclass calling `HostResponse::json()` receives its own type back.
 * Subclasses that change the constructor signature (e.g. StreamedResponse)
 * must override withHeaders() so new static() is never called with a
 * mismatched signature.
 */
class Response
{
    private int $statusCode;
    private string $body;
    /** @var array<string, string> */
    private array $headers;

    /**
     * Constructor
     *
     * @param int $statusCode HTTP status code
     * @param string $body Response body
     * @param array<string, string> $headers Response headers (name => value pairs)
     */
    public function __construct(int $statusCode = 200, string $body = '', array $headers = [])
    {
        $this->statusCode = $statusCode;
        $this->body = $body;
        $this->headers = $this->normalizeHeaders($headers);
    }

    /**
     * Get HTTP status code
     *
     * @return int HTTP status code
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Get response body
     *
     * @return string Response body
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Get response headers
     *
     * @return array<string, string> Response headers
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Create a JSON response
     *
     * Automatically sets Content-Type header to application/json and
     * JSON-encodes the provided data.
     *
     * @param mixed $data Data to encode as JSON
     * @param int $statusCode HTTP status code (default: 200)
     * @return static JSON response instance
     * @throws \RuntimeException If JSON encoding fails
     */
    public static function json(mixed $data, int $statusCode = 200): static
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new \RuntimeException(
                'JSON encoding failed: ' . json_last_error_msg(),
                json_last_error()
            );
        }
        // @phpstan-ignore new.static (LSB factory; StreamedResponse overrides json() so this path is never reached with an incompatible constructor)
        return new static($statusCode, $body, [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * Create an error response
     *
     * Creates a JSON response with error message and optional details.
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status code (default: 500)
     * @param array<string, mixed> $details Optional error details
     * @return static Error response instance
     */
    public static function error(string $message, int $statusCode = 500, array $details = []): static
    {
        $data = ['error' => $message];
        if (!empty($details)) {
            $data['details'] = $details;
        }
        return static::json($data, $statusCode);
    }

    /**
     * Return a copy of this response with additional headers merged in.
     *
     * Headers in $extraHeaders override any same-named headers already on this
     * response (later values win in array_merge). Subclasses with a different
     * constructor shape (e.g. StreamedResponse) MUST override this method so
     * that new static() is never called with a mismatched signature.
     *
     * @param array<string, string> $extraHeaders
     * @return static
     */
    public function withHeaders(array $extraHeaders): static
    {
        // @phpstan-ignore new.static (StreamedResponse overrides withHeaders(); this path is never reached with an incompatible constructor)
        return new static(
            $this->statusCode,
            $this->body,
            array_merge($this->headers, $extraHeaders)
        );
    }

    /**
     * Send response with headers to client
     *
     * Outputs all response headers and the response body. This should typically
     * be called only once and after all other output is complete.
     *
     * @return void
     * @throws \RuntimeException If headers have already been sent
     */
    public function send(): void
    {
        // Check if headers have already been sent
        if (headers_sent()) {
            throw new \RuntimeException('Headers already sent');
        }

        // Set status code
        http_response_code($this->statusCode);

        // Send headers
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        // Send body
        echo $this->body;
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
}
