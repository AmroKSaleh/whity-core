<?php

declare(strict_types=1);

namespace Whity\Core\Update;

/**
 * The verdict of one latest-release comparison (WHIT-587).
 *
 * A plain value object rather than a formatted string, because the SAME
 * verdict is rendered two ways: `update:check` prints it to a terminal and
 * turns it into an exit code, and `GET /api/v1/platform/version/latest`
 * serialises it for an operator who has no shell on their own deployment.
 * Anything that re-derived "is there an update?" from a rendered form would
 * eventually disagree with the other renderer.
 *
 * {@see self::STATUS_CHECK_FAILED} is deliberately distinct from every real
 * verdict: "I could not tell" must never be shown (or scripted against) as
 * "up to date". {@see self::$failureReason} says WHICH way it failed so the
 * CLI can keep printing its specific remediation line.
 */
final class ReleaseCheckResult
{
    /** The running version equals the latest published release. */
    public const STATUS_UP_TO_DATE = 'up_to_date';

    /** A newer release than the running version exists. */
    public const STATUS_UPDATE_AVAILABLE = 'update_available';

    /** The running version is newer than any published release (dev checkouts). */
    public const STATUS_AHEAD = 'ahead';

    /** The release stream exists but has published nothing yet. */
    public const STATUS_NO_RELEASES = 'no_releases';

    /** The comparison could not be performed at all. */
    public const STATUS_CHECK_FAILED = 'check_failed';

    /** The releases API could not be reached (network, DNS, timeout). */
    public const REASON_UNREACHABLE = 'unreachable';

    /** A response arrived but was not JSON (proxy error page, captive portal). */
    public const REASON_UNPARSEABLE = 'unparseable';

    /** JSON arrived with no tag name — usually GitHub refusing (rate limit, auth). */
    public const REASON_NO_TAG = 'no_tag';

    /** The latest tag is not a vMAJOR.MINOR.PATCH version, so nothing is comparable. */
    public const REASON_UNRECOGNIZED_TAG = 'unrecognized_tag';

    /**
     * @param string      $status         One of the STATUS_* constants.
     * @param string      $repository     The release stream that was consulted (owner/name).
     * @param string      $currentVersion The running core version.
     * @param string|null $latestVersion  The latest published version, when one could be read.
     * @param string|null $releaseUrl     Human-facing release page, when the payload carried one.
     * @param string|null $publishedAt    Release publication timestamp, when the payload carried one.
     * @param string|null $failureReason  One of the REASON_* constants; null unless the check failed.
     * @param string|null $detail         Free-text context for a failure (GitHub's own message, or the
     *                                    unusable tag) — never a URL, credential or stack trace.
     */
    public function __construct(
        public readonly string $status,
        public readonly string $repository,
        public readonly string $currentVersion,
        public readonly ?string $latestVersion = null,
        public readonly ?string $releaseUrl = null,
        public readonly ?string $publishedAt = null,
        public readonly ?string $failureReason = null,
        public readonly ?string $detail = null,
    ) {
    }

    /**
     * Whether the operator should act. False for every status except
     * {@see self::STATUS_UPDATE_AVAILABLE} — notably for a failed check, which
     * is unknown, not clear.
     */
    public function updateAvailable(): bool
    {
        return $this->status === self::STATUS_UPDATE_AVAILABLE;
    }

    /**
     * The wire shape shared by the HTTP endpoint and any other serialiser.
     *
     * `update_available` is included alongside `status` so a caller that only
     * wants the yes/no does not have to hard-code the status vocabulary.
     *
     * @return array{status: string, update_available: bool, repository: string, current_version: string, latest_version: ?string, release_url: ?string, published_at: ?string, failure_reason: ?string, detail: ?string}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'update_available' => $this->updateAvailable(),
            'repository' => $this->repository,
            'current_version' => $this->currentVersion,
            'latest_version' => $this->latestVersion,
            'release_url' => $this->releaseUrl,
            'published_at' => $this->publishedAt,
            'failure_reason' => $this->failureReason,
            'detail' => $this->detail,
        ];
    }
}
