<?php

declare(strict_types=1);

namespace Whity\Cli\Commands;

use Composer\Semver\Comparator;
use Whity\Core\CoreVersion;

/**
 * `php public/index.php update:check` (WC-172)
 *
 * Compares the running core version ({@see CoreVersion::VERSION}) against
 * the latest published GitHub release and tells the operator whether an
 * update is available. The APPLY step is deliberately manual — see
 * docs/wiki/Core-Update.md for the runbook.
 *
 * Exit codes (the machine contract, usable from cron/scripts):
 *   0 — up to date, running ahead of the latest release (dev checkouts), or
 *       no releases have been published yet (nothing to update to);
 *   1 — an update is available;
 *   2 — the check could not be performed (network, rate limit, bad payload).
 *
 * The repository defaults to the canonical core repo and can be overridden
 * with the WHITY_UPDATE_REPO environment variable (owner/name), so forks and
 * private mirrors can point the check at their own release stream. The HTTP
 * fetch is injectable for tests; the default uses PHP streams with a short
 * timeout and the User-Agent header the GitHub API requires.
 */
final class UpdateCheckCommand
{
    /**
     * Canonical release stream for the platform core.
     */
    private const DEFAULT_REPO = 'AmroKSaleh/whity-core';

    /**
     * Seconds before the release lookup gives up.
     */
    private const TIMEOUT_SECONDS = 10;

    /**
     * @var callable(string): ?string Returns the response body, or null on failure.
     */
    private $fetcher;

    /**
     * @param callable(string): ?string|null $fetcher HTTP GET implementation; null uses the stream default.
     */
    public function __construct(?callable $fetcher = null)
    {
        $this->fetcher = $fetcher ?? self::streamFetcher();
    }

    /**
     * Run the check.
     *
     * @param array<int, string> $argv Remaining CLI arguments (unused).
     * @return int Exit code (see class docblock).
     */
    public function execute(array $argv): int
    {
        $repo = $this->repository();
        $url = "https://api.github.com/repos/{$repo}/releases/latest";

        $body = ($this->fetcher)($url);
        if ($body === null) {
            echo "\033[0;31m✗ Could not reach the GitHub releases API for {$repo}.\033[0m\n";
            echo "  Check network access (or the WHITY_UPDATE_REPO setting) and retry.\n";
            return 2;
        }

        $release = json_decode($body, true);
        if (!is_array($release)) {
            echo "\033[0;31m✗ Could not parse the GitHub releases response for {$repo}.\033[0m\n";
            return 2;
        }

        $tag = $release['tag_name'] ?? null;
        if (!is_string($tag) || $tag === '') {
            if (self::isGitHubNotFound($release)) {
                echo "\033[0;32m✓ No releases have been published yet for {$repo} — nothing to update to.\033[0m\n";
                return 0;
            }

            echo "\033[0;31m✗ The latest release of {$repo} carries no usable tag name.\033[0m\n";

            // A tagless payload with an error `message` is GitHub describing
            // its own refusal (rate limit, auth, ...) — surface it so the
            // operator can tell that apart from a broken release.
            $message = $release['message'] ?? null;
            if (is_string($message) && $message !== '') {
                echo "  GitHub said: {$message}\n";
            }

            return 2;
        }

        $latest = ltrim($tag, 'vV');
        if (preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $latest) !== 1) {
            echo "\033[0;31m✗ The latest release tag of {$repo} is not a recognizable version: '{$tag}'.\033[0m\n";
            echo "  Expected a vMAJOR.MINOR.PATCH tag — no comparison verdict is possible.\n";
            return 2;
        }
        $current = CoreVersion::VERSION;
        $releaseUrl = is_string($release['html_url'] ?? null) ? $release['html_url'] : '';
        $publishedAt = is_string($release['published_at'] ?? null) ? $release['published_at'] : '';

        echo "Current version: {$current}\n";
        echo "Latest release:  {$latest}" . ($publishedAt !== '' ? " (published {$publishedAt})" : '') . "\n";

        if (Comparator::greaterThan($latest, $current)) {
            echo "\n\033[1;33m⚠ An update is available: {$current} → {$latest}\033[0m\n";
            if ($releaseUrl !== '') {
                echo "  Release: {$releaseUrl}\n";
            }
            echo "  Apply it with the runbook: docs/wiki/Core-Update.md\n";
            return 1;
        }

        if (Comparator::greaterThan($current, $latest)) {
            echo "\n\033[0;32m✓ Running ahead of the latest release ({$latest}) — nothing to do.\033[0m\n";
            return 0;
        }

        echo "\n\033[0;32m✓ The core is up to date.\033[0m\n";
        return 0;
    }

    /**
     * Resolve the release repository (owner/name).
     */
    private function repository(): string
    {
        $override = $_ENV['WHITY_UPDATE_REPO'] ?? null;
        if (
            is_string($override)
            && preg_match('#^[\w.-]+/[\w.-]+$#', $override) === 1
            && !str_contains($override, '..')
        ) {
            return $override;
        }

        return self::DEFAULT_REPO;
    }

    /**
     * Whether a decoded payload is GitHub's "Not Found" error shape — the
     * answer /releases/latest gives when the repository has no releases at
     * all. That is "nothing to update to", not a failed check.
     *
     * @param array<mixed> $release The decoded JSON payload.
     */
    private static function isGitHubNotFound(array $release): bool
    {
        return ($release['message'] ?? null) === 'Not Found'
            || ($release['status'] ?? null) === '404';
    }

    /**
     * The default HTTP GET: PHP streams, short timeout, the headers the
     * GitHub API requires. Returns null on NETWORK failure — the caller
     * turns that into a graceful exit-2, never a stack trace. HTTP error
     * statuses still return their body (ignore_errors), so the caller can
     * tell "no releases published yet" (a JSON 404) from "unreachable".
     *
     * @return callable(string): ?string
     */
    private static function streamFetcher(): callable
    {
        return static function (string $url): ?string {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => self::TIMEOUT_SECONDS,
                    'header' => implode("\r\n", [
                        'User-Agent: whity-core-update-check',
                        'Accept: application/vnd.github+json',
                    ]),
                    'ignore_errors' => true,
                ],
            ]);

            $body = @file_get_contents($url, false, $context);

            return $body === false ? null : $body;
        };
    }
}
