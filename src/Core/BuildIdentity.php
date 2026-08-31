<?php

declare(strict_types=1);

namespace Whity\Core;

use DateTimeImmutable;
use Throwable;

/**
 * WHICH CHECKOUT IS THIS BACKEND ACTUALLY RUNNING (#1049).
 *
 * `GET /api/health` reports {@see CoreVersion::VERSION}, a CONSTANT IN THE
 * SOURCE. It says what the code claims to be, never what is running: between
 * releases it is byte-identical across every commit, so a worker pool holding
 * a three-week-old checkout and one started five minutes ago report the same
 * string. A version constant cannot detect deployment drift, because it moves
 * with the source whether or not the source was deployed. This instance's
 * backend was found four days behind with a green health probe, and a
 * documented v0.1.0 -> v0.2.0 upgrade passed its success criterion
 * (`/api/health` said `0.2.0`) while the served frontend was 268 commits
 * stale. The web tier answered its half of that in WHIT-587 with `/web-build`;
 * this is the backend's half, and it is deliberately built the same way.
 *
 * THE MECHANISM, AND WHY IT IS NOT `git`
 * --------------------------------------
 * The published release image bakes the tree in with `COPY . /app` and the
 * build context carries NO `.git` (see .dockerignore), so `git rev-parse` at
 * runtime is not merely slow, it is unavailable — and a mechanism that is
 * unavailable in production is not a mechanism. So the identity is CAPTURED AT
 * BUILD TIME and READ AT RUNTIME, exactly the trick `web/next.config.ts` uses:
 * `scripts/write-build-identity.php` freezes the commit into
 * `build-identity.json` inside the image, and {@see self::resolve()} reads it.
 *
 * THREE SOURCES, AND THE FIELD THAT SAYS WHICH ONE ANSWERED
 * ---------------------------------------------------------
 * `source` is not decoration. It is the difference between a number an
 * operator may act on and one they may not, so it is reported beside the
 * commit rather than inferred from its presence:
 *
 *  - `build`    — read from the baked `build-identity.json`. The strong case:
 *                 the file was written by the build that produced this tree and
 *                 cannot be changed by anything the container does afterwards.
 *  - `checkout` — read out of `.git` AT WORKER BOOT (files, never a subprocess)
 *                 because no baked file existed. This is the bind-mount
 *                 deployment — `docker-compose.staging.yml` mounts `.:/app` —
 *                 which is precisely the topology the drift was found in, so
 *                 answering `unknown` there would have made this whole feature
 *                 inert on the instance that motivated it. It is weaker than
 *                 `build` in one specific way, stated so nobody has to guess:
 *                 it names the commit HEAD pointed at when the worker booted,
 *                 and says nothing about uncommitted edits on top of it.
 *  - `unknown`  — nothing could be established, and `commit` is null.
 *
 * `unknown`/null is a first-class answer, not a failure. A plausible-looking
 * wrong commit is worse than no commit: this issue exists because a check
 * reported success without looking, and a monitor comparing strings cannot
 * tell a confident lie from the truth. So a malformed baked file, an
 * unreadable ref and an empty string all collapse to null rather than to
 * something that parses.
 *
 * FROZEN AT BOOT, ON PURPOSE
 * --------------------------
 * FrankenPHP runs workers that never recycle (the Caddyfile sets no
 * `max_requests`), so a worker holds the classes it loaded at boot
 * indefinitely and moving the checkout underneath it changes nothing about
 * what it serves. {@see self::resolve()} is therefore called ONCE during the
 * worker bootstrap and the result held for the process's life. Re-resolving
 * per request would report the checkout on DISK — which is the thing that
 * moved — and would have been confidently wrong at exactly the moment it
 * mattered. {@see self::commitFromCheckout()} stays public precisely so the
 * endpoint can read the disk SEPARATELY and report both numbers: two fields
 * that disagree are a restart nobody performed.
 */
final class BuildIdentity
{
    /** Read from the build-time file baked into the image. */
    public const SOURCE_BUILD = 'build';

    /** Read from `.git` at worker boot — a bind-mounted checkout. */
    public const SOURCE_CHECKOUT = 'checkout';

    /** Nothing could be established; `commit` is null. */
    public const SOURCE_UNKNOWN = 'unknown';

    /**
     * The build-time artifact, at the application root.
     *
     * Written by `scripts/write-build-identity.php` (run by the release stage
     * of the Dockerfile), gitignored, and never committed — a tracked copy
     * would be a constant again, which is the bug.
     */
    public const FILE_NAME = 'build-identity.json';

    private function __construct(
        /** The commit this process is running, or null when it cannot be established. */
        public readonly ?string $commit,
        /** ISO-8601 instant the identity was captured, or null (only `build` carries one). */
        public readonly ?string $builtAt,
        /** One of the SOURCE_* constants — WHERE the commit above came from. */
        public readonly string $source,
    ) {
    }

    /**
     * The identity of the process reading this, resolved once at worker boot.
     *
     * Baked file first (it is the only source a release image has, and the only
     * one nothing at runtime can move), then the checkout, then honest silence.
     *
     * @param string $repoRoot Application root — the directory holding `build-identity.json` and `.git`.
     */
    public static function resolve(string $repoRoot): self
    {
        return self::fromBakedFile($repoRoot)
            ?? self::fromCheckout($repoRoot)
            ?? self::unknown();
    }

    /**
     * Nothing established. `commit` is null so a monitor comparing it against
     * `/web-build` gets no answer instead of a wrong one.
     */
    public static function unknown(): self
    {
        return new self(null, null, self::SOURCE_UNKNOWN);
    }

    /**
     * A known build identity — the shape `scripts/write-build-identity.php`
     * produces. Exposed so tests can construct one without a filesystem.
     */
    public static function fromBuild(string $commit, ?string $builtAt = null): self
    {
        return new self(
            self::normalizeCommit($commit),
            self::normalizeInstant($builtAt),
            self::SOURCE_BUILD
        );
    }

    /**
     * The baked `build-identity.json`, or null when there is none to read.
     *
     * A file that exists but cannot name a commit returns NULL rather than a
     * `build`-sourced identity with a null commit: the honest reading of
     * "the build wrote a file it could not fill in" is that the build told us
     * nothing, so the checkout still gets its turn. `v0.2.2` shipped a
     * `/web-build` that answered 200 with every field null and read as
     * "working" to everything checking it; the same shape must not be
     * reachable here.
     */
    public static function fromBakedFile(string $repoRoot): ?self
    {
        $raw = self::readFile(self::join($repoRoot, self::FILE_NAME));

        if ($raw === null) {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        /** @var mixed $commitValue */
        $commitValue = $decoded['commit'] ?? null;
        $commit = is_string($commitValue) ? self::normalizeCommit($commitValue) : null;

        if ($commit === null) {
            return null;
        }

        /** @var mixed $builtAtValue */
        $builtAtValue = $decoded['built_at'] ?? null;

        return new self(
            $commit,
            is_string($builtAtValue) ? self::normalizeInstant($builtAtValue) : null,
            self::SOURCE_BUILD
        );
    }

    /**
     * The checkout's HEAD commit, or null when this is not a readable checkout.
     *
     * Reads `.git` as FILES — HEAD, then the loose ref, then `packed-refs`.
     * Never a subprocess: the release image has neither `.git` nor a reason to
     * be able to spawn one, and a mechanism that only works on a developer's
     * machine is how a check comes to report success without looking.
     *
     * Public because the endpoint reports the on-disk commit SEPARATELY from
     * the frozen one it booted with — see the class docblock.
     */
    public static function commitFromCheckout(string $repoRoot): ?string
    {
        $gitDir = self::resolveGitDir($repoRoot);

        if ($gitDir === null) {
            return null;
        }

        $head = self::readFile(self::join($gitDir, 'HEAD'));

        if ($head === null) {
            return null;
        }

        $head = trim($head);

        // Detached HEAD: the file IS the commit.
        $detached = self::normalizeCommit($head);
        if ($detached !== null) {
            return $detached;
        }

        if (preg_match('/^ref:\s*(\S+)$/', $head, $matches) !== 1) {
            return null;
        }

        $ref = $matches[1];

        // The ref name comes off disk and is concatenated into a path below, so
        // it is constrained to the shape git actually writes.
        if (preg_match('#^refs/[A-Za-z0-9._\-/]+$#', $ref) !== 1 || str_contains($ref, '..')) {
            return null;
        }

        // A linked worktree keeps HEAD in its own gitdir but the branch refs in
        // the shared commondir, so both are searched — loose refs first (git
        // writes the loose file on every commit and only packs later, so a
        // stale packed entry must never win over it).
        $searchDirs = self::refSearchDirs($gitDir);

        foreach ($searchDirs as $dir) {
            $loose = self::readFile(self::join($dir, $ref));
            if ($loose !== null) {
                $commit = self::normalizeCommit(trim($loose));
                if ($commit !== null) {
                    return $commit;
                }
            }
        }

        foreach ($searchDirs as $dir) {
            $packed = self::packedRef(self::join($dir, 'packed-refs'), $ref);
            if ($packed !== null) {
                return $packed;
            }
        }

        return null;
    }

    /**
     * Whether a commit could be established at all. `false` means every
     * comparison a monitor would make against this instance is unanswerable —
     * which is itself worth alerting on.
     */
    public function isKnown(): bool
    {
        return $this->commit !== null;
    }

    private static function fromCheckout(string $repoRoot): ?self
    {
        $commit = self::commitFromCheckout($repoRoot);

        return $commit === null
            ? null
            // No `built_at`: a checkout was not built, it was pulled. Reporting
            // the worker's boot time here would dress a guess as a fact.
            : new self($commit, null, self::SOURCE_CHECKOUT);
    }

    /**
     * `.git` as a directory, or as the `gitdir:` pointer file a linked worktree
     * and a submodule use. Null when neither is present (the release image).
     */
    private static function resolveGitDir(string $repoRoot): ?string
    {
        $candidate = self::join($repoRoot, '.git');

        if (is_dir($candidate)) {
            return $candidate;
        }

        $pointer = self::readFile($candidate);

        if ($pointer === null || preg_match('/^gitdir:\s*(.+)$/m', trim($pointer), $matches) !== 1) {
            return null;
        }

        $target = trim($matches[1]);

        if (!self::isAbsolute($target)) {
            $target = self::join($repoRoot, $target);
        }

        return is_dir($target) ? $target : null;
    }

    /**
     * Directories a ref may live under: this gitdir, plus the shared `commondir`
     * when this is a linked worktree.
     *
     * @return list<string>
     */
    private static function refSearchDirs(string $gitDir): array
    {
        $dirs = [$gitDir];

        $common = self::readFile(self::join($gitDir, 'commondir'));

        if ($common !== null) {
            $common = trim($common);

            if ($common !== '') {
                $path = self::isAbsolute($common) ? $common : self::join($gitDir, $common);

                if (is_dir($path)) {
                    $dirs[] = rtrim($path, "/\\");
                }
            }
        }

        return $dirs;
    }

    /**
     * Look one ref up in a `packed-refs` file. Null when the file or the ref is
     * absent.
     */
    private static function packedRef(string $path, string $ref): ?string
    {
        $contents = self::readFile($path);

        if ($contents === null) {
            return null;
        }

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            // `^<sha> <ref>` — the `^` peeled-tag lines carry no ref name and
            // never match, which is correct: an annotated tag's target is not
            // what HEAD points at.
            if (preg_match('/^([0-9a-fA-F]{7,64})\s+(\S+)$/', trim($line), $matches) !== 1) {
                continue;
            }

            if ($matches[2] === $ref) {
                return self::normalizeCommit($matches[1]);
            }
        }

        return null;
    }

    /**
     * A commit hash, or null.
     *
     * Anything that is not a hex object id is REJECTED rather than passed
     * through: an empty string, `unknown`, a branch name or a truncated
     * substitution would all reach a monitor looking exactly like an answer.
     */
    private static function normalizeCommit(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = strtolower(trim($value));

        return preg_match('/^[0-9a-f]{7,64}$/', $trimmed) === 1 ? $trimmed : null;
    }

    /**
     * An instant normalized to ISO-8601 UTC, or null when it does not parse.
     * Same rule as the commit: a value that is not a time is not reported as one.
     */
    private static function normalizeInstant(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->format(DATE_ATOM);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * File contents, or null when the path is not a readable file. Never throws:
     * every caller here treats "cannot read" and "not there" identically.
     */
    private static function readFile(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    private static function join(string $base, string $relative): string
    {
        return rtrim($base, "/\\") . '/' . ltrim($relative, "/\\");
    }

    private static function isAbsolute(string $path): bool
    {
        return preg_match('#^(/|[A-Za-z]:[\\\\/])#', $path) === 1;
    }
}
