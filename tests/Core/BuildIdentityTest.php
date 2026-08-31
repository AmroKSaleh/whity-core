<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use Whity\Core\BuildIdentity;
use Whity\Core\CoreVersion;

/**
 * #1049: the resolver behind `GET /api/build`.
 *
 * The thing under test is not "does it read a file". It is the pair of
 * properties the endpoint's whole value rests on:
 *
 *  1. THE COMMIT IS NOT A CONSTANT. Two different builds must produce two
 *     different answers from the same code — that is the difference between
 *     this and `CoreVersion::VERSION`, which is identical across every commit
 *     between releases and so cannot detect drift at all.
 *  2. NOT KNOWING IS AN ANSWER. A value that is not a commit — an empty
 *     string, `unknown`, a branch name, a truncated substitution — is rejected
 *     rather than passed through, because a monitor comparing strings cannot
 *     tell a confident lie from the truth, and this whole issue exists because
 *     a check reported success without looking.
 */
final class BuildIdentityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/whity_build_identity_' . uniqid('', true);
        mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    /**
     * The baked file is the strong source: a released image reports the commit
     * its build was given, tagged `build`.
     */
    public function testBakedFileIsReadAndTaggedAsABuild(): void
    {
        $this->writeBakedFile([
            'commit' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0',
            'core_version' => '9.9.9',
            'built_at' => '2026-08-31T09:10:11Z',
        ]);

        $identity = BuildIdentity::resolve($this->root);

        self::assertSame('a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0', $identity->commit);
        self::assertSame(BuildIdentity::SOURCE_BUILD, $identity->source);
        self::assertTrue($identity->isKnown());
        self::assertSame('2026-08-31T09:10:11+00:00', $identity->builtAt);
    }

    /**
     * THE POINT OF THE FEATURE, stated as a test: the same code returns
     * different commits for different builds. A constant cannot pass this, and
     * a reversion to one — reporting `CoreVersion::VERSION`, or any other fixed
     * value — fails it.
     */
    public function testTwoBuildsOfTheSameCodeReportDifferentCommits(): void
    {
        $this->writeBakedFile(['commit' => str_repeat('a', 40)]);
        $first = BuildIdentity::resolve($this->root);

        $this->writeBakedFile(['commit' => str_repeat('b', 40)]);
        $second = BuildIdentity::resolve($this->root);

        self::assertSame(str_repeat('a', 40), $first->commit);
        self::assertSame(str_repeat('b', 40), $second->commit);
        self::assertNotSame($first->commit, $second->commit);
        self::assertNotSame(CoreVersion::VERSION, $first->commit);
        self::assertNotSame(CoreVersion::VERSION, $second->commit);
    }

    /**
     * Nothing to read: null, `unknown`, and NOT a value that parses as an answer.
     */
    public function testNothingToReadIsReportedAsUnknownRatherThanGuessedAt(): void
    {
        $identity = BuildIdentity::resolve($this->root);

        self::assertNull($identity->commit);
        self::assertNull($identity->builtAt);
        self::assertSame(BuildIdentity::SOURCE_UNKNOWN, $identity->source);
        self::assertFalse($identity->isKnown());
        // The failure mode being guarded: a field that LOOKS answered.
        self::assertNotSame('', $identity->commit);
        self::assertNotSame(CoreVersion::VERSION, $identity->commit);
    }

    /**
     * A file that exists but names no usable commit is worth exactly as much as
     * no file — so the checkout still gets its turn rather than the endpoint
     * answering `source: build, commit: null`, which is the shape v0.2.2
     * shipped and which reads as "working".
     *
     * @param mixed $commit
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unusableCommitValues')]
    public function testABakedFileThatNamesNoCommitFallsThroughToTheCheckout(mixed $commit): void
    {
        $this->writeBakedFile(['commit' => $commit]);
        $this->writeCheckout(str_repeat('c', 40));

        $identity = BuildIdentity::resolve($this->root);

        self::assertSame(str_repeat('c', 40), $identity->commit);
        self::assertSame(BuildIdentity::SOURCE_CHECKOUT, $identity->source);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableCommitValues(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'the literal word' => ['unknown'],
            'a branch name' => ['refs/heads/develop'],
            'too short to be an object id' => ['a1b2c3'],
            'not hex' => ['zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz'],
            'not a string' => [12345],
        ];
    }

    public function testMalformedJsonInTheBakedFileIsIgnored(): void
    {
        file_put_contents($this->root . '/' . BuildIdentity::FILE_NAME, '{ this is not json');
        $this->writeCheckout(str_repeat('d', 40));

        $identity = BuildIdentity::resolve($this->root);

        self::assertSame(str_repeat('d', 40), $identity->commit);
        self::assertSame(BuildIdentity::SOURCE_CHECKOUT, $identity->source);
    }

    /**
     * A `built_at` that is not a time is not reported as one.
     */
    public function testUnparseableBuiltAtBecomesNull(): void
    {
        $this->writeBakedFile([
            'commit' => str_repeat('e', 40),
            'built_at' => 'sometime last tuesday-ish',
        ]);

        $identity = BuildIdentity::resolve($this->root);

        self::assertSame(str_repeat('e', 40), $identity->commit);
        self::assertNull($identity->builtAt);
    }

    /**
     * Baked beats checkout. The image's identity is the one nothing at runtime
     * can move, so a `.git` that happens to be mounted alongside must not
     * override it.
     */
    public function testTheBakedFileWinsOverACheckout(): void
    {
        $this->writeBakedFile(['commit' => str_repeat('1', 40)]);
        $this->writeCheckout(str_repeat('2', 40));

        $identity = BuildIdentity::resolve($this->root);

        self::assertSame(str_repeat('1', 40), $identity->commit);
        self::assertSame(BuildIdentity::SOURCE_BUILD, $identity->source);
    }

    /**
     * The bind-mount deployment — `docker-compose.staging.yml` mounts `.:/app`,
     * which is the topology the reported drift was found in. A detached HEAD
     * (what `git checkout v0.2.6` leaves behind, i.e. every tag-based deploy)
     * puts the commit straight in the HEAD file.
     */
    public function testDetachedHeadIsReadDirectly(): void
    {
        mkdir($this->root . '/.git', 0o755, true);
        file_put_contents($this->root . '/.git/HEAD', str_repeat('3', 40) . "\n");

        $identity = BuildIdentity::resolve($this->root);

        self::assertSame(str_repeat('3', 40), $identity->commit);
        self::assertSame(BuildIdentity::SOURCE_CHECKOUT, $identity->source);
        // A checkout was pulled, not built — there is no build time to report.
        self::assertNull($identity->builtAt);
    }

    public function testSymbolicHeadResolvesThroughTheLooseRef(): void
    {
        $this->writeCheckout(str_repeat('4', 40), 'refs/heads/develop');

        self::assertSame(str_repeat('4', 40), BuildIdentity::commitFromCheckout($this->root));
    }

    /**
     * `git gc` moves refs out of individual files into `packed-refs`, so a
     * long-lived deployment checkout routinely has no loose ref to read.
     */
    public function testSymbolicHeadResolvesThroughPackedRefs(): void
    {
        mkdir($this->root . '/.git', 0o755, true);
        file_put_contents($this->root . '/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents(
            $this->root . '/.git/packed-refs',
            "# pack-refs with: peeled fully-peeled sorted\n"
            . str_repeat('5', 40) . " refs/heads/main\n"
            . str_repeat('6', 40) . " refs/tags/v0.2.7\n"
            . '^' . str_repeat('7', 40) . "\n"
        );

        self::assertSame(str_repeat('5', 40), BuildIdentity::commitFromCheckout($this->root));
    }

    /**
     * A loose ref is written on every commit and only packed later, so a stale
     * packed entry must never win over it.
     */
    public function testALooseRefWinsOverAStalePackedEntry(): void
    {
        $this->writeCheckout(str_repeat('8', 40), 'refs/heads/develop');
        file_put_contents(
            $this->root . '/.git/packed-refs',
            str_repeat('9', 40) . " refs/heads/develop\n"
        );

        self::assertSame(str_repeat('8', 40), BuildIdentity::commitFromCheckout($this->root));
    }

    /**
     * A linked worktree (and a submodule) has `.git` as a FILE pointing at the
     * real gitdir, with branch refs left in the shared `commondir`.
     */
    public function testGitdirPointerFileAndCommondirAreFollowed(): void
    {
        $common = $this->root . '/real-git';
        $linked = $this->root . '/worktree-git';
        mkdir($common . '/refs/heads', 0o755, true);
        mkdir($linked, 0o755, true);

        file_put_contents($this->root . '/.git', "gitdir: worktree-git\n");
        file_put_contents($linked . '/HEAD', "ref: refs/heads/feature\n");
        file_put_contents($linked . '/commondir', "../real-git\n");
        file_put_contents($common . '/refs/heads/feature', str_repeat('f', 40) . "\n");

        self::assertSame(str_repeat('f', 40), BuildIdentity::commitFromCheckout($this->root));
    }

    /**
     * The release image: `COPY . /app` from a context with no `.git`. Nothing to
     * read, and nothing invented.
     */
    public function testNoCheckoutReturnsNull(): void
    {
        self::assertNull(BuildIdentity::commitFromCheckout($this->root));
    }

    /**
     * A HEAD pointing at a ref that does not exist anywhere — a checkout mid
     * surgery — is unknown, not a guess.
     */
    public function testUnresolvableRefIsNull(): void
    {
        mkdir($this->root . '/.git', 0o755, true);
        file_put_contents($this->root . '/.git/HEAD', "ref: refs/heads/nothing-here\n");

        self::assertNull(BuildIdentity::commitFromCheckout($this->root));
    }

    /**
     * The ref name comes off disk and is concatenated into a path, so a HEAD
     * that tries to walk out of the gitdir resolves to nothing.
     */
    public function testATraversingRefNameIsRefused(): void
    {
        mkdir($this->root . '/.git', 0o755, true);
        file_put_contents($this->root . '/.git/HEAD', "ref: refs/../../../../etc/passwd\n");

        self::assertNull(BuildIdentity::commitFromCheckout($this->root));
    }

    /**
     * `fromBuild()` normalizes the same way the file path does, so a test double
     * cannot assert behaviour the real resolver does not have.
     */
    public function testFromBuildNormalizesAndRejectsTheSameWay(): void
    {
        self::assertSame(str_repeat('a', 40), BuildIdentity::fromBuild(strtoupper(str_repeat('a', 40)))->commit);
        self::assertNull(BuildIdentity::fromBuild('not-a-commit')->commit);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeBakedFile(array $data): void
    {
        file_put_contents(
            $this->root . '/' . BuildIdentity::FILE_NAME,
            (string) json_encode($data, JSON_THROW_ON_ERROR)
        );
    }

    private function writeCheckout(string $commit, string $ref = 'refs/heads/develop'): void
    {
        $gitDir = $this->root . '/.git';
        mkdir($gitDir . '/' . dirname($ref), 0o755, true);
        file_put_contents($gitDir . '/HEAD', "ref: {$ref}\n");
        file_put_contents($gitDir . '/' . $ref, $commit . "\n");
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
