<?php

namespace Whity\Core;

/**
 * HTTP response object
 *
 * Encapsulates HTTP response data with methods for setting status codes,
 * bodies, and headers. Provides convenience methods for creating JSON and error responses.
 */
class Response
{
    private int $statusCode;
    private string $body;
    private array $headers;

    /**
     * Constructor
     *
     * @param int $statusCode HTTP status code
     * @param string $body Response body
     * @param array $headers Response headers (name => value pairs)
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
     * @return array Response headers
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
     * @return self JSON response instance
     */
    public static function json(mixed $data, int $statusCode = 200): self
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return new self($statusCode, $body, [
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
     * @param array $details Optional error details
     * @return self Error response instance
     */
    public static function error(string $message, int $statusCode = 500, array $details = []): self
    {
        $data = ['error' => $message];
        if (!empty($details)) {
            $data['details'] = $details;
        }
        return self::json($data, $statusCode);
    }

    /**
     * Send response with headers to client
     *
     * Outputs all response headers and the response body. This should typically
     * be called only once and after all other output is complete.
     *
     * @return void
     */
    public function send(): void
    {
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
     * @param array $headers Headers array
     * @return array Normalized headers
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
     * Normalize header names to a consistent format (capitalize words separated by hyphens)
     *
     * @param string $name Header name
     * @return string Normalized header name
     */
    private function normalizeHeaderName(string $name): string
    {
        return implode('-', array_map('ucfirst', explode('-', strtolower($name))));
    }
}
