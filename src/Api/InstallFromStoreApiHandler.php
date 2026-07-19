<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Audit\AuditLogger;
use Whity\Core\Exception\PluginAlreadyInstalled;
use Whity\Core\Exception\PluginExtractionUnsafe;
use Whity\Core\Exception\PluginIncompatible;
use Whity\Core\Exception\PluginNameUnsafe;
use Whity\Core\Exception\PluginPackageInvalid;
use Whity\Core\Http\HttpFetcher;
use Whity\Core\PluginInstaller;
use Whity\Core\PluginLoader;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * POST /api/plugins/install-from-store — fetch a plugin package from a TRUSTED
 * plugin store and install it (landing DISABLED) through the SAME hardened
 * {@see PluginInstaller} pipeline as a manual upload.
 *
 * SECURITY — this endpoint makes the SERVER fetch an operator-supplied URL, so
 * SSRF is the headline risk. Two layers:
 *   1. PRIMARY — an operator ALLOWLIST of trusted store hosts
 *      (`plugins.store_allowed_hosts`). Empty ⇒ the feature is OFF (403). The
 *      store URL's host must EXACTLY match an allowlisted host, so the server can
 *      only be aimed at hosts the operator vetted, never arbitrary internal ones.
 *   2. DEFENSE-IN-DEPTH — {@see HttpFetcher} enforces https + public-IP-only + no
 *      redirects + TLS verification on the resolved URL (its documented DNS-rebind
 *      TOCTOU is why the allowlist, not the IP guard, is the primary control).
 * The fetched bytes are NEVER trusted: they run the full PluginInstaller
 * validation (zip-slip/bomb, name allowlist, SDK gate, isolated introspection),
 * identical to an upload. The store token is sent as a bearer and never logged.
 * Internal errors never leak to the client (WC-186).
 */
final class InstallFromStoreApiHandler
{
    /** @var \Closure(string, array<string, string>): ?string */
    private \Closure $fetchPackage;

    /** @var \Closure(string): ?array<string, mixed> */
    private \Closure $fetchJson;

    /**
     * @param \Closure(string, array<string, string>): ?string|null $fetchPackage
     *   Injectable package fetcher (url, headers) => raw bytes|null. Defaults to a
     *   size-capped, SSRF-guarded HttpFetcher; stubbed in tests.
     * @param \Closure(string): ?array<string, mixed>|null $fetchJson
     *   Injectable JSON fetcher (url) => decoded array|null, used to read a store's
     *   public catalogue. Defaults to the same SSRF-guarded HttpFetcher; stubbed
     *   in tests.
     */
    public function __construct(
        private readonly string $pluginDir,
        private readonly SettingsService $settings,
        private readonly ?PluginLoader $pluginLoader = null,
        private readonly ?AuditLogger $auditLogger = null,
        ?\Closure $fetchPackage = null,
        ?\Closure $fetchJson = null,
    ) {
        $this->fetchPackage = $fetchPackage ?? static fn (string $url, array $headers): ?string
            => (new HttpFetcher(timeoutSeconds: 15, maxBytes: PluginInstaller::MAX_UPLOAD_BYTES))
                ->getBinary($url, $headers);
        $this->fetchJson = $fetchJson ?? static fn (string $url): ?array
            => (new HttpFetcher(timeoutSeconds: 15))->getJson($url);
    }

    /**
     * @param array<string, string> $params Route params (unused; none in the path).
     */
    public function install(Request $request, array $params = []): Response
    {
        $body = json_decode($request->getBody(), true);
        if (!is_array($body)) {
            return Response::error('A JSON body is required.', 400);
        }

        $storeUrl = trim((string) ($body['store_url'] ?? ''));
        $slug = trim((string) ($body['slug'] ?? ''));
        $version = trim((string) ($body['version'] ?? ''));
        $token = (string) ($body['token'] ?? '');

        if ($storeUrl === '' || $slug === '' || $version === '') {
            return Response::error('store_url, slug and version are required.', 422);
        }
        // Safe path segments — never let slug/version inject into the download path.
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/', $slug) !== 1) {
            return Response::error('Invalid slug.', 422);
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9.+_-]{0,63}$/', $version) !== 1) {
            return Response::error('Invalid version.', 422);
        }

        // ── SSRF allowlist (primary control) ─────────────────────────────────
        [$origin, $originError] = $this->resolveStoreOrigin($storeUrl);
        if ($originError !== null) {
            return $originError;
        }

        // ── Fetch (SSRF-guarded, token-authenticated) ────────────────────────
        $downloadUrl = $origin
            . '/api/v1/plugin-store/plugins/' . rawurlencode($slug)
            . '/versions/' . rawurlencode($version) . '/download';

        $headers = [];
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        try {
            $bytes = ($this->fetchPackage)($downloadUrl, $headers);
        } catch (\Throwable $e) {
            // HttpFetcher guard refusal / transport error — logged, never leaked.
            error_log('[InstallFromStore] fetch refused/failed: ' . $e->getMessage());
            return Response::error('The store package could not be fetched.', 502);
        }
        if ($bytes === null || $bytes === '') {
            return Response::error('The store rejected the request or the package was not found.', 502);
        }

        // ── Install via the hardened pipeline (bytes never trusted) ───────────
        $installer = new PluginInstaller($this->pluginDir, $this->pluginLoader, $this->auditLogger);
        try {
            $entry = $installer->installFromBytes($bytes);
        } catch (PluginPackageInvalid | PluginNameUnsafe | PluginExtractionUnsafe $e) {
            return Response::error($e->clientMessage(), 400, $e->clientDetails());
        } catch (PluginIncompatible $e) {
            return Response::error($e->clientMessage(), 422, $e->clientDetails());
        } catch (PluginAlreadyInstalled $e) {
            return Response::error($e->clientMessage(), 409, $e->clientDetails());
        } catch (\Throwable $e) {
            error_log('[InstallFromStore] install failed: ' . $e->getMessage());
            return Response::error('Failed to install the plugin.', 500);
        }

        return Response::json(['data' => $entry], 201);
    }

    /**
     * GET /api/plugins/store/allowed — the trusted store hosts the operator has
     * configured, so the admin UI can offer a store picker and know whether the
     * feature is on. Read-only, no outbound request.
     *
     * @param array<string, string> $params
     */
    public function allowedStores(Request $request, array $params = []): Response
    {
        $hosts = $this->allowedHosts();

        return Response::json([
            'data' => [
                'enabled' => $hosts !== [],
                'hosts' => $hosts,
            ],
        ]);
    }

    /**
     * GET /api/plugins/store/catalog?store_url=…&q=… — browse (and search) a
     * trusted store's PUBLIC catalogue on the caller's behalf. The host must be
     * on the allowlist (same SSRF control as install); the browser never talks to
     * the store directly. `q` filters by slug/name/description/tags (substring,
     * case-insensitive). Never leaks internal errors.
     *
     * @param array<string, string> $params
     */
    public function browseCatalog(Request $request, array $params = []): Response
    {
        // Query params: at runtime Request::fromGlobals() strips the query from
        // getPath() (path only), so the reliable source is PHP's native $_GET.
        // In unit tests the query is carried in the path, so parse that first and
        // fall back to $_GET for the live request.
        $query = [];
        $qs = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($qs) && $qs !== '') {
            parse_str($qs, $query);
        } else {
            $query = $_GET;
        }
        // parse_str/$_GET can yield arrays (e.g. `?q[]=x`); accept only scalar strings.
        $storeUrlRaw = $query['store_url'] ?? '';
        $searchRaw = $query['q'] ?? '';
        $storeUrl = is_string($storeUrlRaw) ? trim($storeUrlRaw) : '';
        $search = is_string($searchRaw) ? strtolower(trim($searchRaw)) : '';

        if ($storeUrl === '') {
            return Response::error('store_url is required.', 422);
        }

        [$origin, $originError] = $this->resolveStoreOrigin($storeUrl);
        if ($originError !== null) {
            return $originError;
        }

        try {
            $payload = ($this->fetchJson)($origin . '/api/v1/plugin-store/plugins');
        } catch (\Throwable $e) {
            error_log('[StoreBrowse] catalogue fetch refused/failed: ' . $e->getMessage());
            return Response::error('The store catalogue could not be fetched.', 502);
        }
        if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
            return Response::error('The store returned an unexpected catalogue response.', 502);
        }

        /** @var list<array<string, mixed>> $plugins */
        $plugins = array_values(array_filter($payload['data'], 'is_array'));

        if ($search !== '') {
            $plugins = array_values(array_filter(
                $plugins,
                static fn (array $p): bool => self::matchesSearch($p, $search)
            ));
        }

        return Response::json([
            'data' => $plugins,
            'store_url' => $origin,
            'count' => count($plugins),
        ]);
    }

    /**
     * Whether a catalogue entry matches a lower-cased search term across its
     * slug, name, description and tags.
     *
     * @param array<string, mixed> $plugin
     */
    private static function matchesSearch(array $plugin, string $needle): bool
    {
        $haystacks = [
            (string) ($plugin['slug'] ?? ''),
            (string) ($plugin['name'] ?? ''),
            (string) ($plugin['description'] ?? ''),
            (string) ($plugin['author'] ?? ''),
        ];
        if (isset($plugin['tags']) && is_array($plugin['tags'])) {
            foreach ($plugin['tags'] as $tag) {
                $haystacks[] = (string) $tag;
            }
        }
        foreach ($haystacks as $h) {
            if ($h !== '' && str_contains(strtolower($h), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate that $storeUrl is a BARE https origin whose host is on the operator
     * allowlist — the single SSRF gate shared by install + browse. Rejecting any
     * path/query/fragment/userinfo and any non-443 port keeps the fetch on the
     * vetted host AND blocks aiming the server at other TCP ports of that host
     * (e.g. a co-located Redis).
     *
     * @return array{0: ?string, 1: ?Response} [normalized `https://host` origin, error-to-return]
     */
    private function resolveStoreOrigin(string $storeUrl): array
    {
        $allowed = $this->allowedHosts();
        if ($allowed === []) {
            return [null, Response::error(
                'Installing from a store is disabled (no trusted store hosts are configured).',
                403
            )];
        }

        $parts = parse_url($storeUrl);
        if (!is_array($parts)) {
            return [null, Response::error('The store URL is malformed.', 422)];
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = $parts['port'] ?? null;
        $path = (string) ($parts['path'] ?? '');
        if ($scheme !== 'https' || $host === '' || !in_array($host, $allowed, true)) {
            return [null, Response::error('The store host is not in the trusted allowlist.', 403)];
        }
        if (($port !== null && $port !== 443)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || ($path !== '' && $path !== '/')
        ) {
            return [null, Response::error('The store URL must be a bare https origin (no path, query, port or credentials).', 422)];
        }

        return ['https://' . $host, null];
    }

    /**
     * @return list<string> Lower-cased trusted store hosts (empty ⇒ feature off).
     */
    private function allowedHosts(): array
    {
        $raw = (string) ($this->settings->getGlobal()[SettingsRegistry::PLUGINS_STORE_ALLOWED_HOSTS] ?? '');
        $hosts = array_map(
            static fn (string $h): string => strtolower(trim($h)),
            explode(',', $raw),
        );

        return array_values(array_filter($hosts, static fn (string $h): bool => $h !== ''));
    }
}
