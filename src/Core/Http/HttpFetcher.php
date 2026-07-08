<?php

declare(strict_types=1);

namespace Whity\Core\Http;

/**
 * SSRF-guarded outbound HTTP client (WC-ae16 / OIDC engine).
 *
 * Built on the codebase's established `file_get_contents` + `stream_context`
 * fetch idiom (no HTTP-client dependency), hardened for the OIDC use case where
 * the target URL (a tenant admin's `issuer` / `discovery_url` / token endpoint)
 * is CONFIGURED, not fully trusted — so a malicious or mistaken config must not
 * turn the server into an SSRF proxy to cloud-metadata or internal services.
 *
 * Guard ({@see isPubliclyRoutableUrl()}), applied before every request:
 *   - scheme MUST be https (blocks http/file/gopher/…);
 *   - the host must resolve ONLY to publicly-routable IPs — any private, loopback,
 *     link-local (e.g. 169.254.169.254) or reserved address blocks the request.
 * Redirects are disabled (max_redirects 0) so a 30x cannot bounce a vetted host
 * to an internal one, TLS peer verification is on, and responses are size- and
 * time-bounded.
 *
 * LIMITATION: DNS is resolved for the guard and again by the fetch, so a
 * determined attacker controlling DNS could in theory race the two (TOCTOU).
 * Full protection needs a pinned-IP connect, which the file_get_contents
 * transport cannot express; given the URL is admin-configured (not arbitrary
 * user input) the range-block is the pragmatic, documented mitigation.
 */
final class HttpFetcher implements HttpClient
{
    public function __construct(
        private readonly int $timeoutSeconds = 5,
        private readonly int $maxBytes = 1048576,
    ) {
    }

    public function getJson(string $url): ?array
    {
        return $this->decode($this->fetch('GET', $url, null));
    }

    public function postForm(string $url, array $params): ?array
    {
        return $this->decode($this->fetch('POST', $url, http_build_query($params)));
    }

    /**
     * Whether $url is https AND its host resolves only to publicly-routable IPs.
     * Pure/deterministic for IP-literal hosts; performs DNS for hostnames.
     */
    public static function isPubliclyRoutableUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        $host = $parts['host'] ?? '';
        if ($host === '') {
            return false;
        }
        // Bracketed IPv6 literal → strip brackets.
        $host = trim($host, '[]');

        $ips = self::resolveIps($host);
        if ($ips === []) {
            // Unresolvable → cannot prove it is public → block (fail closed).
            return false;
        }

        foreach ($ips as $ip) {
            // FILTER_FLAG_NO_PRIV_RANGE | NO_RES_RANGE returns false for private
            // (10/8, 172.16/12, 192.168/16, fc00::/7) AND reserved/loopback/
            // link-local (127/8, 169.254/16, ::1, 0.0.0.0/8, 240/4, …).
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return list<string> Resolved IP literals for $host (or [$host] if it is
     *   already an IP literal), empty when a hostname cannot be resolved.
     */
    private static function resolveIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];
        // IPv4
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }
        // IPv6
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $rec) {
                if (isset($rec['ipv6']) && is_string($rec['ipv6'])) {
                    $ips[] = $rec['ipv6'];
                }
            }
        }
        return array_values(array_unique($ips));
    }

    private function fetch(string $method, string $url, ?string $body): ?string
    {
        if (!self::isPubliclyRoutableUrl($url)) {
            // A blocked URL is a configuration/security problem, not a transient
            // failure — surface it distinctly so the caller can log + 502 rather
            // than silently treating it like an unreachable host.
            throw new \RuntimeException('HttpFetcher: refused non-public or non-https URL');
        }

        $headers = "Accept: application/json\r\n";
        if ($body !== null) {
            $headers .= "Content-Type: application/x-www-form-urlencoded\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => $headers,
                'content'       => $body ?? '',
                'timeout'       => $this->timeoutSeconds,
                'ignore_errors' => true,   // read the body even on 4xx/5xx
                'max_redirects' => 0,      // never follow a redirect to a new host
                'follow_location' => 0,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context, 0, max(0, $this->maxBytes));
        return $raw === false ? null : $raw;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(?string $body): ?array
    {
        if ($body === null || trim($body) === '') {
            return null;
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return null;
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
