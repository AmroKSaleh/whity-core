<?php

declare(strict_types=1);

namespace Whity\Cli\Commands;

use Whity\Core\Update\LatestReleaseCheck;
use Whity\Core\Update\ReleaseCheckResult;

/**
 * `php public/index.php update:check` (WC-172)
 *
 * Renders {@see LatestReleaseCheck}'s verdict for a terminal and turns it
 * into an exit code. The comparison itself lives in the core service so the
 * HTTP surface (`GET /api/v1/platform/version/latest`, WHIT-587) gives the
 * same answer — an operator without shell access must not get a second
 * opinion. The APPLY step is deliberately manual — see
 * docs/wiki/Core-Update.md for the runbook.
 *
 * Exit codes (the machine contract, usable from cron/scripts):
 *   0 — up to date, running ahead of the latest release (dev checkouts), or
 *       no releases have been published yet (nothing to update to);
 *   1 — an update is available;
 *   2 — the check could not be performed (network, rate limit, bad payload).
 */
final class UpdateCheckCommand implements CliCommand
{
    private LatestReleaseCheck $check;

    /**
     * @param callable(string): ?string|null $fetcher HTTP GET implementation; null uses the service default.
     */
    public function __construct(?callable $fetcher = null)
    {
        $this->check = new LatestReleaseCheck($fetcher);
    }

    /**
     * Run the check.
     *
     * @param array<int, string> $argv Remaining CLI arguments (unused).
     * @return int Exit code (see class docblock).
     */
    public function execute(array $argv): int
    {
        $result = $this->check->run();
        $repo = $result->repository;

        if ($result->status === ReleaseCheckResult::STATUS_CHECK_FAILED) {
            $this->reportFailure($result, $repo);
            return 2;
        }

        if ($result->status === ReleaseCheckResult::STATUS_NO_RELEASES) {
            echo "\033[0;32m✓ No releases have been published yet for {$repo} — nothing to update to.\033[0m\n";
            return 0;
        }

        $latest = (string) $result->latestVersion;
        $publishedAt = $result->publishedAt ?? '';

        echo "Current version: {$result->currentVersion}\n";
        echo "Latest release:  {$latest}" . ($publishedAt !== '' ? " (published {$publishedAt})" : '') . "\n";

        if ($result->status === ReleaseCheckResult::STATUS_UPDATE_AVAILABLE) {
            echo "\n\033[1;33m⚠ An update is available: {$result->currentVersion} → {$latest}\033[0m\n";
            if ($result->releaseUrl !== null) {
                echo "  Release: {$result->releaseUrl}\n";
            }
            echo "  Apply it with the runbook: docs/wiki/Core-Update.md\n";
            return 1;
        }

        if ($result->status === ReleaseCheckResult::STATUS_AHEAD) {
            echo "\n\033[0;32m✓ Running ahead of the latest release ({$latest}) — nothing to do.\033[0m\n";
            return 0;
        }

        echo "\n\033[0;32m✓ The core is up to date.\033[0m\n";
        return 0;
    }

    /**
     * Print the failure the way the operator can act on it: each reason has a
     * different fix, so they are never collapsed into one generic error.
     */
    private function reportFailure(ReleaseCheckResult $result, string $repo): void
    {
        switch ($result->failureReason) {
            case ReleaseCheckResult::REASON_UNREACHABLE:
                echo "\033[0;31m✗ Could not reach the GitHub releases API for {$repo}.\033[0m\n";
                echo "  Check network access (or the WHITY_UPDATE_REPO setting) and retry.\n";
                return;

            case ReleaseCheckResult::REASON_UNPARSEABLE:
                echo "\033[0;31m✗ Could not parse the GitHub releases response for {$repo}.\033[0m\n";
                return;

            case ReleaseCheckResult::REASON_UNRECOGNIZED_TAG:
                echo "\033[0;31m✗ The latest release tag of {$repo} is not a recognizable version: '{$result->detail}'.\033[0m\n";
                echo "  Expected a vMAJOR.MINOR.PATCH tag — no comparison verdict is possible.\n";
                return;

            default:
                echo "\033[0;31m✗ The latest release of {$repo} carries no usable tag name.\033[0m\n";
                if ($result->detail !== null) {
                    echo "  GitHub said: {$result->detail}\n";
                }
        }
    }
}
