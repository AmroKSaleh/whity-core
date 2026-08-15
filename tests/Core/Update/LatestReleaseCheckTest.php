<?php

declare(strict_types=1);

namespace Tests\Core\Update;

use PHPUnit\Framework\TestCase;
use Whity\Core\CoreVersion;
use Whity\Core\Update\LatestReleaseCheck;
use Whity\Core\Update\ReleaseCheckResult;

/**
 * WHIT-587: the release comparison as a REUSABLE verdict.
 *
 * The comparison used to live inside `update:check`, interleaved with the
 * echo statements that render it — reachable only from a shell. These tests
 * pin the verdict itself (status, versions, failure reason) so both the CLI
 * and the HTTP surface can render the same answer without either of them
 * re-deriving it.
 *
 * The GitHub call is injected as a fetcher callable, so the suite never
 * touches the network.
 */
final class LatestReleaseCheckTest extends TestCase
{
    /**
     * A fetcher returning a canned GitHub /releases/latest payload.
     *
     * @param array<string, mixed>|null $release The decoded release, or null to simulate a network failure.
     */
    private static function fetcher(?array $release): callable
    {
        return static function (string $url) use ($release): ?string {
            return $release === null ? null : (string) json_encode($release);
        };
    }

    private static function raw(string $body): callable
    {
        return static fn (string $url): string => $body;
    }

    public function testReportsAnAvailableUpdate(): void
    {
        $result = (new LatestReleaseCheck(self::fetcher([
            'tag_name' => 'v99.0.0',
            'html_url' => 'https://github.com/AmroKSaleh/whity-core/releases/tag/v99.0.0',
            'published_at' => '2026-06-12T00:00:00Z',
        ])))->run();

        self::assertSame(ReleaseCheckResult::STATUS_UPDATE_AVAILABLE, $result->status);
        self::assertSame(CoreVersion::VERSION, $result->currentVersion);
        self::assertSame('99.0.0', $result->latestVersion);
        self::assertSame('https://github.com/AmroKSaleh/whity-core/releases/tag/v99.0.0', $result->releaseUrl);
        self::assertSame('2026-06-12T00:00:00Z', $result->publishedAt);
        self::assertTrue($result->updateAvailable());
    }

    public function testReportsUpToDateWhenVersionsMatch(): void
    {
        $result = (new LatestReleaseCheck(self::fetcher([
            'tag_name' => 'v' . CoreVersion::VERSION,
        ])))->run();

        self::assertSame(ReleaseCheckResult::STATUS_UP_TO_DATE, $result->status);
        self::assertFalse($result->updateAvailable());
    }

    public function testRunningAheadOfTheLatestReleaseIsNotAnUpdate(): void
    {
        $result = (new LatestReleaseCheck(self::fetcher([
            'tag_name' => 'v0.0.1',
        ])))->run();

        self::assertSame(ReleaseCheckResult::STATUS_AHEAD, $result->status);
        self::assertSame('0.0.1', $result->latestVersion);
        self::assertFalse($result->updateAvailable());
    }

    public function testRepositoryWithoutReleasesIsNothingToUpdateTo(): void
    {
        $result = (new LatestReleaseCheck(self::fetcher([
            'message' => 'Not Found',
            'status' => '404',
        ])))->run();

        self::assertSame(ReleaseCheckResult::STATUS_NO_RELEASES, $result->status);
        self::assertNull($result->latestVersion);
        self::assertFalse($result->updateAvailable());
    }

    public function testUnreachableApiIsAFailedCheckNotAVerdict(): void
    {
        $result = (new LatestReleaseCheck(self::fetcher(null)))->run();

        self::assertSame(ReleaseCheckResult::STATUS_CHECK_FAILED, $result->status);
        self::assertSame(ReleaseCheckResult::REASON_UNREACHABLE, $result->failureReason);
        self::assertNull($result->latestVersion);
    }

    public function testUnparseableBodyIsAFailedCheck(): void
    {
        $result = (new LatestReleaseCheck(self::raw('<html>502 Bad Gateway</html>')))->run();

        self::assertSame(ReleaseCheckResult::STATUS_CHECK_FAILED, $result->status);
        self::assertSame(ReleaseCheckResult::REASON_UNPARSEABLE, $result->failureReason);
    }

    public function testTaglessPayloadCarriesGitHubsOwnRefusalAsDetail(): void
    {
        $result = (new LatestReleaseCheck(self::fetcher([
            'message' => 'API rate limit exceeded',
        ])))->run();

        self::assertSame(ReleaseCheckResult::STATUS_CHECK_FAILED, $result->status);
        self::assertSame(ReleaseCheckResult::REASON_NO_TAG, $result->failureReason);
        self::assertSame('API rate limit exceeded', $result->detail);
    }

    public function testUnrecognizableTagIsAFailedCheckAndReportsTheTag(): void
    {
        $result = (new LatestReleaseCheck(self::fetcher([
            'tag_name' => 'nightly-2026-08-12',
        ])))->run();

        self::assertSame(ReleaseCheckResult::STATUS_CHECK_FAILED, $result->status);
        self::assertSame(ReleaseCheckResult::REASON_UNRECOGNIZED_TAG, $result->failureReason);
        self::assertSame('nightly-2026-08-12', $result->detail);
    }

    public function testForksCanRetargetTheReleaseStream(): void
    {
        $seen = null;
        $check = new LatestReleaseCheck(static function (string $url) use (&$seen): string {
            $seen = $url;
            return (string) json_encode(['tag_name' => 'v' . CoreVersion::VERSION]);
        }, 'acme/whity-fork');

        $result = $check->run();

        self::assertSame('https://api.github.com/repos/acme/whity-fork/releases/latest', $seen);
        self::assertSame('acme/whity-fork', $result->repository);
    }

    public function testAMalformedRepositoryOverrideFallsBackToTheCanonicalStream(): void
    {
        // A traversal-shaped override must never be pasted into the API URL.
        $check = new LatestReleaseCheck(
            self::fetcher(['tag_name' => 'v' . CoreVersion::VERSION]),
            '../../etc/passwd'
        );

        self::assertSame(LatestReleaseCheck::DEFAULT_REPO, $check->run()->repository);
    }

    public function testTheWireShapeCarriesTheWholeVerdict(): void
    {
        $wire = (new LatestReleaseCheck(self::fetcher([
            'tag_name' => 'v99.0.0',
            'html_url' => 'https://example.invalid/release',
            'published_at' => '2026-06-12T00:00:00Z',
        ])))->run()->toArray();

        self::assertSame([
            'status' => 'update_available',
            'update_available' => true,
            'repository' => LatestReleaseCheck::DEFAULT_REPO,
            'current_version' => CoreVersion::VERSION,
            'latest_version' => '99.0.0',
            'release_url' => 'https://example.invalid/release',
            'published_at' => '2026-06-12T00:00:00Z',
            'failure_reason' => null,
            'detail' => null,
        ], $wire);
    }
}
