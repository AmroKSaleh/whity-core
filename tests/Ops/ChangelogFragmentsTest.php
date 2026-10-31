<?php

declare(strict_types=1);

namespace Tests\Ops;

use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/scripts/lib/changelog-fragments.php';

/**
 * Changelog fragments fold into [Unreleased] at release (WHIT-791).
 *
 * The release notes are the dated [Unreleased] section, published verbatim,
 * so the assembler must never drop an entry, mix up sections, or touch an
 * earlier release.
 */
final class ChangelogFragmentsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/changelog-fragments-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->dir);
    }

    private const LOG = <<<'MD'
        # Changelog

        Intro.

        ## [Unreleased]

        ### Fixed

        - **An old fix.**

        ## [0.2.19] - 2026-10-29

        ### Added

        - **Released already.**

        MD;

    public function testFragmentsJoinTheirSectionsInKeepAChangelogOrder(): void
    {
        $out = changelog_assemble(self::LOG, [
            ['section' => 'fixed', 'body' => "- **A new fix.**\n"],
            ['section' => 'added', 'body' => "- **A feature.**\n"],
            ['section' => 'security', 'body' => '- **A patch.**'],
        ]);

        $unreleased = substr($out, (int) strpos($out, '## [Unreleased]'), (int) strpos($out, '## [0.2.19]') - (int) strpos($out, '## [Unreleased]'));
        self::assertSame(
            "## [Unreleased]\n\n### Added\n\n- **A feature.**\n\n### Fixed\n\n- **A new fix.**\n- **An old fix.**\n\n### Security\n\n- **A patch.**\n\n",
            $unreleased,
        );
    }

    public function testEarlierReleasesAndTheIntroAreUntouched(): void
    {
        $out = changelog_assemble(self::LOG, [['section' => 'added', 'body' => '- **New.**']]);

        self::assertStringStartsWith("# Changelog\n\nIntro.\n\n## [Unreleased]", $out);
        self::assertStringEndsWith("## [0.2.19] - 2026-10-29\n\n### Added\n\n- **Released already.**\n", $out);
    }

    public function testWithNoFragmentsTheSectionKeepsItsEntries(): void
    {
        $out = changelog_assemble(self::LOG, []);

        self::assertStringContainsString("## [Unreleased]\n\n### Fixed\n\n- **An old fix.**\n\n## [0.2.19]", $out);
    }

    public function testAnUnknownHeadingIsKeptAfterTheStandardOnes(): void
    {
        $log = "## [Unreleased]\n\n### Upgrade notes\n\n- Back up first.\n\n### Added\n\n- **A.**\n";

        $out = changelog_assemble($log, [['section' => 'removed', 'body' => '- **Gone.**']]);

        self::assertSame(
            "## [Unreleased]\n\n### Added\n\n- **A.**\n\n### Removed\n\n- **Gone.**\n\n### Upgrade notes\n\n- Back up first.\n\n",
            $out,
        );
    }

    public function testAChangelogWithoutOneUnreleasedHeadingIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        changelog_assemble("# Changelog\n\n## [0.2.19]\n", []);
    }

    public function testValidFragmentsHaveNoProblemsAndReadmeIsNotAFragment(): void
    {
        file_put_contents("{$this->dir}/README.md", "# Not a fragment\n");
        file_put_contents("{$this->dir}/WHIT-789.added.md", "- **Colour.**\n");
        file_put_contents("{$this->dir}/WHIT-789-2.fixed.md", "- **Fix.**\n");

        self::assertSame([], changelog_fragment_problems($this->dir));
        self::assertSame(['WHIT-789-2.fixed.md', 'WHIT-789.added.md'], changelog_fragment_files($this->dir));
        self::assertSame(
            [
                ['section' => 'fixed', 'body' => "- **Fix.**\n"],
                ['section' => 'added', 'body' => "- **Colour.**\n"],
            ],
            changelog_read_fragments($this->dir),
        );
    }

    public function testBadFragmentsAreReportedByName(): void
    {
        file_put_contents("{$this->dir}/WHIT-1.new.md", "- **Wrong section.**\n");
        file_put_contents("{$this->dir}/WHIT-2.added.md", "  \n");
        file_put_contents("{$this->dir}/WHIT-3.fixed.md", "Plain prose.\n");
        file_put_contents("{$this->dir}/WHIT-4.changed.md", "- **Item.**\n\n### Heading\n");

        $problems = changelog_fragment_problems($this->dir);

        self::assertSame(['WHIT-1.new.md', 'WHIT-2.added.md', 'WHIT-3.fixed.md', 'WHIT-4.changed.md'], array_keys($problems));
        self::assertStringContainsString('<id>.<section>.md', $problems['WHIT-1.new.md'][0]);
        self::assertSame(['file is empty'], $problems['WHIT-2.added.md']);
        self::assertStringContainsString('list item', $problems['WHIT-3.fixed.md'][0]);
        self::assertStringContainsString('headings', $problems['WHIT-4.changed.md'][0]);
    }

    public function testAMissingDirectoryHasNoFragments(): void
    {
        self::assertSame([], changelog_fragment_files($this->dir . '/missing'));
        self::assertSame([], changelog_fragment_problems($this->dir . '/missing'));
    }
}
