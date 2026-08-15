<?php

declare(strict_types=1);

namespace Whity\Core\Update;

use Composer\Semver\Comparator;
use Whity\Core\CoreVersion;

/**
 * Compares the running core version against the latest published GitHub
 * release (WC-172, extracted for reuse in WHIT-587).
 *
 * This used to live inside `update:check`, wound through the echo statements
 * that rendered it, which made the answer reachable only from a shell. On a
 * white-label platform the operator is the CUSTOMER, and quite possibly has
 * no shell on their own deployment — so the comparison lives here, and both
 * the CLI and `GET /api/v1/platform/version/latest` render the one verdict.
 *
 * The repository defaults to the canonical core repo and can be overridden
 * with the WHITY_UPDATE_REPO environment variable (owner/name), so forks and
 * private mirrors point the check at their own release stream. The HTTP fetch
 * is injectable for tests; the default uses PHP streams with a short timeout
 * and the User-Agent header the GitHub API requires.
 *
 * Failures are values, never exceptions: a deployment offline by policy must
 * still be able to ask this question and get a usable "could not tell".
 */
final class LatestReleaseCheck
{
    /**
     * Canonical release stream for the platform core.
     */
    public const DEFAULT_REPO = 'AmroKSaleh/whity-core';

    /**
     * Seconds before the release lookup gives up.
     */
    private const TIMEOUT_SECONDS = 10;

    /**
     * @var callable(string): ?string Returns the response body, or null on failure.
     */
    private $fetcher;

    private ?string $repositoryOverride;

    /**
     * @param callable(string): ?string|null $fetcher    HTTP GET implementation; null uses the stream default.
     * @param string|null                    $repository Release stream override (owner/name); null reads
     *                                                   WHITY_UPDATE_REPO, then falls back to the canonical repo.
     */
    public function __construct(?callable $fetcher = null, ?string $repository = null)
    {
        $this->fetcher = $fetcher ?? self::streamFetcher();
        $this->repositoryOverride = $repository;
    }

    /**
     * The release stream this check consults (owner/name).
     *
     * Resolved per call, not cached at construction: the FrankenPHP worker
     * that holds this service outlives any one request, and an operator who
     * retargets WHITY_UPDATE_REPO expects the next check to honour it.
     *
     * An override is accepted only when it is shaped like owner/name — the
     * value is pasted straight into an api.github.com URL, so a traversal or
     * a stray path segment would silently retarget the check.
     */
    public function repository(): string
    {
        $override = $this->repositoryOverride ?? ($_ENV['WHITY_UPDATE_REPO'] ?? null);

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
     * Perform the comparison.
     */
    public function run(): ReleaseCheckResult
    {
        $repository = $this->repository();

        $body = ($this->fetcher)("https://api.github.com/repos/{$repository}/releases/latest");
        if ($body === null) {
            return $this->failed($repository, ReleaseCheckResult::REASON_UNREACHABLE);
        }

        $release = json_decode($body, true);
        if (!is_array($release)) {
            return $this->failed($repository, ReleaseCheckResult::REASON_UNPARSEABLE);
        }

        $tag = $release['tag_name'] ?? null;
        if (!is_string($tag) || $tag === '') {
            if (self::isGitHubNotFound($release)) {
                return $this->verdict($repository, ReleaseCheckResult::STATUS_NO_RELEASES);
            }

            // A tagless payload with an error `message` is GitHub describing
            // its own refusal (rate limit, auth, ...) — carry it through so
            // the operator can tell that apart from a broken release.
            $message = $release['message'] ?? null;

            return $this->failed(
                $repository,
                ReleaseCheckResult::REASON_NO_TAG,
                is_string($message) && $message !== '' ? $message : null
            );
        }

        $latest = ltrim($tag, 'vV');
        if (preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $latest) !== 1) {
            return $this->failed($repository, ReleaseCheckResult::REASON_UNRECOGNIZED_TAG, $tag);
        }

        $current = CoreVersion::VERSION;
        $status = match (true) {
            Comparator::greaterThan($latest, $current) => ReleaseCheckResult::STATUS_UPDATE_AVAILABLE,
            Comparator::greaterThan($current, $latest) => ReleaseCheckResult::STATUS_AHEAD,
            default => ReleaseCheckResult::STATUS_UP_TO_DATE,
        };

        return $this->verdict(
            $repository,
            $status,
            $latest,
            is_string($release['html_url'] ?? null) && $release['html_url'] !== '' ? $release['html_url'] : null,
            is_string($release['published_at'] ?? null) && $release['published_at'] !== '' ? $release['published_at'] : null
        );
    }

    /**
     * The repository is threaded through rather than re-read, so the verdict
     * can only ever name the stream that was actually consulted.
     */
    private function verdict(
        string $repository,
        string $status,
        ?string $latest = null,
        ?string $releaseUrl = null,
        ?string $publishedAt = null
    ): ReleaseCheckResult {
        return new ReleaseCheckResult(
            $status,
            $repository,
            CoreVersion::VERSION,
            $latest,
            $releaseUrl,
            $publishedAt
        );
    }

    private function failed(string $repository, string $reason, ?string $detail = null): ReleaseCheckResult
    {
        return new ReleaseCheckResult(
            ReleaseCheckResult::STATUS_CHECK_FAILED,
            $repository,
            CoreVersion::VERSION,
            null,
            null,
            null,
            $reason,
            $detail
        );
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
     * turns that into a graceful "could not tell", never a stack trace. HTTP
     * error statuses still return their body (ignore_errors), so the caller
     * can tell "no releases published yet" (a JSON 404) from "unreachable".
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
