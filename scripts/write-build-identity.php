<?php

declare(strict_types=1);

/**
 * Freeze this build's IDENTITY into `build-identity.json`, which the running
 * backend reports on `GET /api/build` (#1049).
 *
 * WHY A BUILD STEP AND NOT A RUNTIME LOOKUP
 * -----------------------------------------
 * `/api/health` reports `CoreVersion::VERSION`, a constant in the source: it
 * says what the code claims to be, never what is running, and between releases
 * it is identical across every commit. The only honest answer to "which
 * checkout is this process running" has to be captured by whatever produced
 * the tree, because the published release image bakes the code in with
 * `COPY . /app` from a context that carries NO `.git` — so there is nothing at
 * runtime left to ask. Same trick, same reason, as `web/next.config.ts`
 * freezing the commit into the bundle and `render-service`'s
 * `write-build-info.js` writing `dist/build-info.json`.
 *
 * Usage (from the repository root):
 *
 *     WHITY_BUILD_COMMIT=$(git rev-parse HEAD) php scripts/write-build-identity.php
 *     php scripts/write-build-identity.php <target-root>
 *
 * `<target-root>` is the tree being described — where `build-identity.json` is
 * written and where the `.git` fallback looks. It defaults to this repository
 * and exists so a test can exercise the script against a scratch directory
 * without touching (or being rescued by) the real checkout.
 *
 * The commit comes from `WHITY_BUILD_COMMIT` — the build arg
 * `.github/workflows/release.yml` already passes to all three image legs — and
 * falls back to reading `.git` when the script is run in a real checkout (a
 * bare-metal deploy step, where there IS one).
 *
 * NO COMMIT MEANS NO FILE, and that is the point of the exercise. Writing
 * `{"commit": null}` would produce exactly the artifact `v0.2.2` shipped: an
 * endpoint that answers 200 while saying nothing, which reads as "working" to
 * everything checking it. Instead the file is not written, the runtime falls
 * through to its `checkout`/`unknown` sources, and `/api/build` says so out
 * loud. The gate that stops a PUBLISHED image reaching that state is in
 * release.yml's smoke job, which asserts the served `/api/build` names the
 * release commit — a check on what is SERVED rather than on what was written.
 *
 * Deliberately standalone: no Composer autoloader (the release stage runs this
 * before `composer install`) and no dependency beyond the one core file it
 * reuses rather than re-implements.
 */

const CORE_VERSION_RELATIVE = 'src/Core/CoreVersion.php';
const BUILD_IDENTITY_RELATIVE = 'build-identity.json';

// Where the code lives (CoreVersion.php, BuildIdentity.php) versus the tree
// being described. They are the same thing in every real invocation; splitting
// them is what lets a test drive the script against a scratch directory.
$sourceRoot = dirname(__DIR__);
$targetRoot = rtrim($argv[1] ?? $sourceRoot, "/\\");
$outputPath = $targetRoot . '/' . BUILD_IDENTITY_RELATIVE;

$commit = resolve_build_commit($sourceRoot, $targetRoot);

if ($commit === null) {
    fwrite(
        STDERR,
        "[build-identity] no commit could be established — WHITY_BUILD_COMMIT is unset and there is no readable .git.\n"
        . "[build-identity] NOT writing {$outputPath}: GET /api/build will report source=unknown rather than a commit it made up.\n"
        . "[build-identity] Pass it explicitly: docker build --build-arg WHITY_BUILD_COMMIT=\$(git rev-parse HEAD) ...\n"
    );
    exit(0);
}

$identity = [
    'commit' => $commit,
    // Written for whoever reads the file directly (`docker run <image> cat
    // build-identity.json`). The runtime does NOT read it back — `/api/build`
    // reports CoreVersion::VERSION as the class the worker loaded declares it,
    // which is the version actually in force. A second copy that could
    // disagree with the constant would just be one more thing to keep in step.
    'core_version' => read_core_version($sourceRoot),
    'built_at' => gmdate('Y-m-d\TH:i:s\Z'),
];

$encoded = json_encode($identity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

if (file_put_contents($outputPath, $encoded) === false) {
    fwrite(STDERR, "[build-identity] failed to write {$outputPath}\n");
    exit(1);
}

fwrite(STDOUT, "[build-identity] wrote {$outputPath}: " . json_encode($identity, JSON_THROW_ON_ERROR) . "\n");
exit(0);

/**
 * The commit this build is of: the build arg first, then the checkout.
 *
 * The checkout read reuses `BuildIdentity` rather than shelling out to `git` or
 * carrying a second parser — the class has no dependencies beyond the SPL, so
 * a bare `require` works with no autoloader.
 */
function resolve_build_commit(string $sourceRoot, string $targetRoot): ?string
{
    $fromEnv = getenv('WHITY_BUILD_COMMIT');

    if (is_string($fromEnv) && preg_match('/^[0-9a-fA-F]{7,64}$/', trim($fromEnv)) === 1) {
        return strtolower(trim($fromEnv));
    }

    $buildIdentity = $sourceRoot . '/src/Core/BuildIdentity.php';

    if (!is_file($buildIdentity)) {
        return null;
    }

    require_once $buildIdentity;

    /** @var callable(string): ?string $reader */
    $reader = ['Whity\\Core\\BuildIdentity', 'commitFromCheckout'];

    return $reader($targetRoot);
}

/**
 * `CoreVersion::VERSION`, parsed out of the file that declares it.
 *
 * Anchored on the constant, not on any `MAJOR.MINOR.PATCH` in the file: that
 * docblock cites version numbers in prose, and matching one of those would
 * produce a wrong answer that still looks like a version.
 */
function read_core_version(string $repoRoot): ?string
{
    $source = @file_get_contents($repoRoot . '/' . CORE_VERSION_RELATIVE);

    if ($source === false) {
        return null;
    }

    return preg_match("/VERSION\s*=\s*'([0-9]+\.[0-9]+\.[0-9]+)'/", $source, $matches) === 1
        ? $matches[1]
        : null;
}
