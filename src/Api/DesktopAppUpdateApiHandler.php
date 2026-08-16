<?php

declare(strict_types=1);

namespace Whity\Api;

use Composer\Semver\Comparator;
use PDO;
use Whity\Core\Request;
use Whity\Core\Response;

/**
 * Desktop App Self-Update API Handler (WC-app-self-update).
 *
 * Lets an already-enrolled desktop device (authenticated with the SAME
 * bearer access token it uses for every other authenticated call, issued by
 * POST /api/v1/devices/token) ask "is there a newer build of the app itself
 * for my platform?" — checked BEFORE any plugin sync, since a plugin package
 * assumes a compatible desktop runtime (see `plugins::reconcile` on the
 * client). Gated entirely by the `desktop-app-updates:read` permission via
 * the standard RBAC route pipeline; no new auth mechanism.
 *
 * Implements Tauri's "dynamic update check" contract: the SERVER decides
 * whether a newer release exists (not the client) — respond 204 No Content
 * when the caller is current/ahead, the target is unrecognised, or the
 * request itself is malformed (fail closed: an unparseable request can never
 * resolve to "yes, update"), or 200 with the exact
 * `{version, notes, pub_date, url, signature}` shape `tauri-plugin-updater`
 * expects when a newer release genuinely exists.
 *
 * `desktop_app_releases` is a GLOBAL catalog (no tenant scoping) in v1, same
 * posture as `desktop_plugin_releases` — every authenticated device on this
 * instance is offered the latest release for its platform; per-tenant staged
 * rollout is a deferred follow-up. Populating the table with real signed
 * builds is `bin/desktop-app-release`'s job (run by CI/an operator after a
 * tagged, signed build), not this class's.
 */
final class DesktopAppUpdateApiHandler
{
    /** Rust target-triple-derived key, e.g. `windows-x86_64`. */
    private const TARGET_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    /** Same semver shape `LatestReleaseCheck` already requires of a release tag. */
    private const VERSION_PATTERN = '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * GET /api/desktop-app-updates/latest?target=...&current_version=...
     *
     * @param Request $request The incoming request.
     * @param array<string, string> $params Route parameters (unused).
     * @return Response
     */
    public function latest(Request $request, array $params = []): Response
    {
        // Request::fromGlobals() strips the query from getPath() (path only)
        // at runtime, so $_GET is the reliable source live; in unit tests the
        // query is carried in the path, so parse that first — same fallback
        // InstallFromStoreApiHandler::browseCatalog() already uses.
        $query = [];
        $qs = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($qs) && $qs !== '') {
            parse_str($qs, $query);
        } else {
            $query = $_GET;
        }

        $targetRaw = $query['target'] ?? '';
        $currentVersionRaw = $query['current_version'] ?? '';
        $target = is_string($targetRaw) ? trim($targetRaw) : '';
        $currentVersion = is_string($currentVersionRaw) ? trim($currentVersionRaw) : '';

        if (preg_match(self::TARGET_PATTERN, $target) !== 1 || preg_match(self::VERSION_PATTERN, $currentVersion) !== 1) {
            return new Response(204);
        }

        try {
            $stmt = $this->pdo->prepare(
                'SELECT version, url, signature, notes, pub_date
                 FROM desktop_app_releases
                 WHERE target = :target
                 ORDER BY released_at DESC
                 LIMIT 1'
            );
            $stmt->execute([':target' => $target]);
            /** @var array<string, mixed>|false $row */
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('[DesktopAppUpdateApiHandler] latest lookup failed: ' . $e->getMessage());
            return new Response(204);
        }

        if ($row === false || !Comparator::greaterThan((string) $row['version'], $currentVersion)) {
            return new Response(204);
        }

        return Response::json([
            'version' => (string) $row['version'],
            'notes' => is_string($row['notes'] ?? null) ? $row['notes'] : '',
            'pub_date' => (string) $row['pub_date'],
            'url' => (string) $row['url'],
            'signature' => (string) $row['signature'],
        ], 200);
    }
}
