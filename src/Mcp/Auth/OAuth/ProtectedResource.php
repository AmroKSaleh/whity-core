<?php

declare(strict_types=1);

namespace Whity\Mcp\Auth\OAuth;

use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Mcp\Auth\McpScope;

/**
 * This server as an OAuth 2.1 protected resource (MCP authorization, RFC 9728;
 * WHIT-709, ADR-0015).
 *
 * Clients such as Claude and ChatGPT connect by "paste the URL, then sign in":
 * they call /mcp, get a 401 whose WWW-Authenticate names this server's
 * Protected Resource Metadata, read the authorization server from it, and sign
 * the user in there. whity-core only says WHERE to sign in; the authorization
 * server is configuration (`MCP_OAUTH_ISSUER`), so a deployment can use
 * Whity-Auth or any OAuth 2.1 server and core holds no identity machinery.
 *
 * Off unless configured: with no issuer the metadata documents answer 404 and
 * the 401 challenge names no metadata, exactly as before.
 */
final class ProtectedResource
{
    public const WELL_KNOWN = '/.well-known/oauth-protected-resource';

    /**
     * @param string|null $issuer   The authorization server's issuer URL, or null when OAuth is off.
     * @param string|null $resource This server's MCP endpoint, the resource tokens must be for.
     */
    public function __construct(
        private readonly ?string $issuer,
        private readonly ?string $resource,
    ) {}

    /** From MCP_OAUTH_ISSUER and the MCP endpoint (APP_URL + /mcp). */
    public static function fromEnv(?string $resource): self
    {
        $issuer = $_ENV['MCP_OAUTH_ISSUER'] ?? getenv('MCP_OAUTH_ISSUER');
        $issuer = is_string($issuer) ? rtrim(trim($issuer), '/') : '';
        return new self($issuer === '' ? null : $issuer, $resource);
    }

    /**
     * Whether clients are told where to sign in. Both halves are needed: the
     * metadata's `resource` must be the exact URL clients connect to, and an
     * unknown APP_URL would make it a guess.
     */
    public function enabled(): bool
    {
        return $this->issuer !== null && $this->resource !== null;
    }

    /**
     * Where the metadata for this resource lives: the well-known path with the
     * resource's own path appended (RFC 9728 §3.1), which clients try first.
     */
    public function metadataUrl(): ?string
    {
        if (!$this->enabled()) {
            return null;
        }
        $parts = parse_url((string) $this->resource);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '')
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
        return $origin . self::WELL_KNOWN . rtrim((string) ($parts['path'] ?? ''), '/');
    }

    /** The WWW-Authenticate value for a 401 from /mcp. */
    public function challenge(): string
    {
        $url = $this->metadataUrl();
        if ($url === null) {
            return 'Bearer';
        }
        return sprintf(
            'Bearer resource_metadata="%s", scope="%s"',
            $url,
            implode(' ', McpScope::CAPABILITIES),
        );
    }

    /**
     * GET /.well-known/oauth-protected-resource[/mcp]
     *
     * @param array<string, mixed> $params
     */
    public function metadata(Request $request, array $params = []): Response
    {
        if (!$this->enabled()) {
            return Response::error('Not found', 404);
        }
        return new Response(200, (string) json_encode([
            'resource'                 => $this->resource,
            'authorization_servers'    => [$this->issuer],
            'scopes_supported'         => McpScope::CAPABILITIES,
            'bearer_methods_supported' => ['header'],
            'resource_name'            => 'Whity',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), [
            'Content-Type'  => 'application/json',
            // Public, and the same for everyone: the whole point is that a
            // client with no credentials can read it.
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
