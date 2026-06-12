<?php

declare(strict_types=1);

namespace Tests\Cli;

use PHPUnit\Framework\TestCase;
use Whity\Cli\Commands\UpdateCheckCommand;
use Whity\Core\CoreVersion;

/**
 * WC-172: `update:check` — compare the running core version against the
 * latest GitHub release so operators know when (and to what) to update.
 *
 * The GitHub API call is injected as a fetcher callable, so the suite never
 * touches the network: each behavior (update available, up to date, ahead of
 * the latest release, network failure, malformed payload) is pinned against
 * a deterministic response. Exit codes are the machine contract:
 * 0 = up to date (or ahead, or no releases published yet — nothing to
 * update to is not a failure), 1 = update available, 2 = check failed.
 */
final class UpdateCheckCommandTest extends TestCase
{
    /**
     * A fetcher returning a canned GitHub /releases/latest payload.
     *
     * @param array<string, mixed>|null $release The decoded release, or null to simulate failure.
     */
    private static function fetcher(?array $release): callable
    {
        return static function (string $url) use ($release): ?string {
            return $release === null ? null : (string) json_encode($release);
        };
    }

    /**
     * @param array<int, string> $argv
     * @return array{0: int, 1: string} Exit code and captured output.
     */
    private function runCommand(UpdateCheckCommand $command, array $argv = []): array
    {
        ob_start();
        $exitCode = $command->execute($argv);
        $output = (string) ob_get_clean();

        return [$exitCode, $output];
    }

    public function testCoreVersionIsValidSemver(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            CoreVersion::VERSION,
            'CoreVersion::VERSION must be plain MAJOR.MINOR.PATCH (the release workflow pins the tag to it)'
        );
    }

    public function testReportsAnAvailableUpdate(): void
    {
        $command = new UpdateCheckCommand(self::fetcher([
            'tag_name' => 'v99.0.0',
            'name' => 'v99.0.0',
            'html_url' => 'https://github.com/AmroKSaleh/whity-core/releases/tag/v99.0.0',
            'published_at' => '2026-06-12T00:00:00Z',
        ]));

        [$exitCode, $output] = $this->runCommand($command);

        $this->assertSame(1, $exitCode, 'Update available must exit 1 so scripts can branch on it');
        $this->assertStringContainsString(CoreVersion::VERSION, $output, 'The current version is reported');
        $this->assertStringContainsString('99.0.0', $output, 'The latest version is reported');
        $this->assertStringContainsString('releases/tag/v99.0.0', $output, 'The release URL is reported');
    }

    public function testReportsUpToDateWhenVersionsMatch(): void
    {
        $command = new UpdateCheckCommand(self::fetcher([
            'tag_name' => 'v' . CoreVersion::VERSION,
            'name' => 'v' . CoreVersion::VERSION,
            'html_url' => 'https://example.invalid/release',
            'published_at' => '2026-06-12T00:00:00Z',
        ]));

        [$exitCode, $output] = $this->runCommand($command);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('up to date', strtolower($output));
    }

    public function testRunningAheadOfTheLatestReleaseIsNotAnUpdate(): void
    {
        // A dev checkout past the last cut release must not nag to "update"
        // to an older version.
        $command = new UpdateCheckCommand(self::fetcher([
            'tag_name' => 'v0.0.1',
            'name' => 'v0.0.1',
            'html_url' => 'https://example.invalid/release',
            'published_at' => '2020-01-01T00:00:00Z',
        ]));

        [$exitCode, $output] = $this->runCommand($command);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('ahead', strtolower($output));
    }

    public function testNetworkFailureFailsGracefullyWithExitTwo(): void
    {
        $command = new UpdateCheckCommand(self::fetcher(null));

        [$exitCode, $output] = $this->runCommand($command);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('could not', strtolower($output));
        $this->assertStringNotContainsString('Exception', $output, 'No raw exceptions/stack traces to the operator');
    }

    public function testMalformedPayloadFailsGracefully(): void
    {
        $command = new UpdateCheckCommand(static fn (string $url): ?string => 'not json at all');

        [$exitCode] = $this->runCommand($command);

        $this->assertSame(2, $exitCode);
    }

    public function testPayloadWithoutAUsableTagFailsGracefully(): void
    {
        $command = new UpdateCheckCommand(self::fetcher([
            'name' => 'weird release without tag_name',
            'html_url' => 'https://example.invalid/release',
        ]));

        [$exitCode] = $this->runCommand($command);

        $this->assertSame(2, $exitCode);
    }

    public function testNonSemverLatestTagFailsGracefully(): void
    {
        // A repo whose latest release is tagged outside the vX.Y.Z scheme
        // (e.g. 'release-2026') must not produce a confident verdict — there
        // is no version to compare. Exit 2 and report the raw tag.
        $command = new UpdateCheckCommand(self::fetcher([
            'tag_name' => 'release-2026',
            'name' => 'release-2026',
            'html_url' => 'https://example.invalid/release',
            'published_at' => '2026-01-01T00:00:00Z',
        ]));

        [$exitCode, $output] = $this->runCommand($command);

        $this->assertSame(2, $exitCode, 'A non-semver latest tag means the check cannot be performed');
        $this->assertStringContainsString('release-2026', $output, 'The raw tag is reported so the operator can see what GitHub returned');
        $this->assertStringNotContainsString('ahead', strtolower($output), 'No confident wrong verdict');
        $this->assertStringNotContainsString('up to date', strtolower($output), 'No confident wrong verdict');
    }

    public function testRateLimitErrorMessageIsSurfaced(): void
    {
        // GitHub answers rate-limited requests with a JSON error body carrying
        // a `message`. That is not "no releases" (Not Found) — surface the
        // message so the operator can tell a rate limit from a broken payload.
        $command = new UpdateCheckCommand(self::fetcher([
            'message' => 'API rate limit exceeded for 1.2.3.4',
            'documentation_url' => 'https://docs.github.com/rest/overview/rate-limits-for-the-rest-api',
        ]));

        [$exitCode, $output] = $this->runCommand($command);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('rate limit', $output, 'The GitHub error message is included in the diagnostic output');
    }

    public function testNoPublishedReleasesYetIsNotAFailure(): void
    {
        // GitHub answers /releases/latest with a JSON 404 body when the repo
        // has no releases at all. That means "nothing to update to", which is
        // a healthy state — not a check failure.
        $command = new UpdateCheckCommand(self::fetcher([
            'message' => 'Not Found',
            'documentation_url' => 'https://docs.github.com/rest/releases/releases#get-the-latest-release',
            'status' => '404',
        ]));

        [$exitCode, $output] = $this->runCommand($command);

        $this->assertSame(0, $exitCode, 'No published releases means nothing to update to — exit 0, not 2');
        $this->assertStringContainsString('no releases', strtolower($output));
        $this->assertStringContainsString('AmroKSaleh/whity-core', $output, 'The repo coordinates are reported');
    }

    public function testRepositoryOverrideViaEnvironmentIsUsedInTheRequest(): void
    {
        $seenUrl = null;
        $command = new UpdateCheckCommand(static function (string $url) use (&$seenUrl): ?string {
            $seenUrl = $url;
            return (string) json_encode([
                'tag_name' => 'v0.0.1',
                'html_url' => 'https://example.invalid/release',
            ]);
        });

        $_ENV['WHITY_UPDATE_REPO'] = 'SomeFork/whity-core-fork';
        try {
            $this->runCommand($command);
        } finally {
            unset($_ENV['WHITY_UPDATE_REPO']);
        }

        $this->assertIsString($seenUrl);
        $this->assertStringContainsString('repos/SomeFork/whity-core-fork/releases/latest', $seenUrl);
    }

    public function testRepositoryOverrideWithPathTraversalFallsBackToDefault(): void
    {
        // 'a/..' passes a naive owner/name shape check ('..' is dots), but it
        // would rewrite the request path. Traversal-shaped overrides are
        // rejected and the default repository is used instead.
        $seenUrl = null;
        $command = new UpdateCheckCommand(static function (string $url) use (&$seenUrl): ?string {
            $seenUrl = $url;
            return (string) json_encode([
                'tag_name' => 'v0.0.1',
                'html_url' => 'https://example.invalid/release',
            ]);
        });

        $_ENV['WHITY_UPDATE_REPO'] = 'a/..';
        try {
            $this->runCommand($command);
        } finally {
            unset($_ENV['WHITY_UPDATE_REPO']);
        }

        $this->assertIsString($seenUrl);
        $this->assertStringContainsString('repos/AmroKSaleh/whity-core/releases/latest', $seenUrl);
        $this->assertStringNotContainsString('..', $seenUrl);
    }
}
