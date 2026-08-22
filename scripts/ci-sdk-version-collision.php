<?php

declare(strict_types=1);

/**
 * CI SDK version-collision guard (#873).
 *
 * scripts/ci-vendored-sdk-parity.php (#852) compares sdk/src against the
 * desktop template's vendored copy. That is a HORIZONTAL check: two trees, one
 * commit. A version collision is VERTICAL, and no horizontal check can see it.
 *
 * #871 and #872 were built in parallel and both set Sdk::VERSION to 1.30.0.
 * Both branches were internally consistent — source tree and vendored tree
 * agreed on both — so both passed parity, and both were wrong relative to
 * HISTORY. Whichever merged second silently overwrote the first's version
 * number with a different meaning, and nothing in CI said a word.
 *
 * The damage is not cosmetic. Sdk::VERSION is the value a plugin's
 * getSdkConstraint() is evaluated against ({@see PluginRequirementsGate}), so
 * two releases both called 1.30.0 leave a plugin declaring `^1.30` bound to
 * whichever meaning survived the race, with no way to tell which one it got.
 * templates/tauri-desktop/php-host/sdk then ships that ambiguity to devices,
 * where a plugin that cannot resolve its contract cannot even be DECLARED
 * (#849) — a failure that surfaces on hardware, offline, after the fact.
 *
 * Three checks, none of which subsumes the others:
 *
 *   1. THE THREE PINS AGREE. sdk/src/Sdk.php states its version in three
 *      places — the header docblock ("SDK identity (vX.Y)"), the last ledger
 *      entry, and the VERSION constant — and only the constant was ever gated.
 *      #872 merged with the constant at 1.30.0, a 1.30 ledger entry, and line 8
 *      still reading "SDK identity (v1.29)": the file contradicted itself in its
 *      own opening sentence. That is not carelessness, it is what partial
 *      enforcement produces. A red test teaches you to update the pin it
 *      watches; the unwatched pin rots, and it rots in the ledger a plugin
 *      author reads to decide which constraint to write.
 *
 *   2. THE TWO LEDGERS RUN IN OPPOSITE DIRECTIONS, and each must run its own
 *      way. sdk/src/Sdk.php is OLDEST-FIRST (newest entry last);
 *      tests/Sdk/SdkPackageContractTest.php is NEWEST-FIRST (newest entry
 *      first). Getting one backwards reads as though one PR's feature shipped
 *      in the other's release — which is precisely how a collision gets
 *      "resolved" into a wrong but plausible file. Each ledger is therefore
 *      checked for STRICT monotonicity in its own direction, which also makes a
 *      DUPLICATE entry an error: two 1.30 entries in one ledger is the exact
 *      residue left behind when two branches that both claimed 1.30 get merged
 *      by hand, and it is the only trace of the collision that survives an
 *      up-to-date branch (see check 3's note on why the vertical check alone
 *      cannot catch that case).
 *
 *   3. THE DECLARED VERSION MOVED FORWARD AND IS UNUSED. Against the merge base
 *      with develop, not against a sibling tree: if the version changed it must
 *      be strictly GREATER than the base's, and it must not already appear
 *      anywhere in develop's history of this file. The second half is the one
 *      that catches #873 directly — a branch cut before a rival landed sees an
 *      old base version, so "greater than base" passes happily while the number
 *      it picked is already spoken for on develop.
 *
 * WHY AN UNCHANGED Sdk.php PASSES SILENTLY. Most PRs do not touch the SDK, and
 * a guard that fires on them is a guard that gets deleted. So when the declared
 * version equals the merge base's, there is nothing vertical to compare and
 * check 3 is satisfied by construction — checks 1 and 2 still run, because they
 * read the file as it stands rather than as it changed, and a file that already
 * contradicts itself should not be allowed to stay that way just because this
 * PR was not the one that broke it.
 *
 * WHY IT FAILS CLOSED WHEN IT CANNOT SEE HISTORY. actions/checkout defaults to
 * depth 1. In a shallow clone `git log develop -- sdk/src/Sdk.php` answers with
 * whatever few commits came along, which is a SMALLER version set than the real
 * one, and a smaller set means a collision that exists in history is reported
 * as unused. That is the same shape of silent false green this whole issue is
 * about, so a guard that cannot prove it can see develop's history refuses to
 * pass rather than guessing. The workflow gives this job fetch-depth: 0; the
 * check below exists so that removing it fails loudly instead of quietly.
 *
 * NOT CHECKED HERE, deliberately:
 *   - the vendored copy's pins — the parity guard already asserts that tree is
 *     byte-identical to sdk/src, so its pins are this file's pins.
 *   - sdk/composer.json's `version` — SdkPackageContractTest already pins it to
 *     Sdk::VERSION, and a second copy of that assertion is a second thing to
 *     keep in step.
 *
 * NOT WIRED INTO scripts/ci-local.sh, unlike most of the guards beside it, and
 * deliberately: `ci-local.sh --clean` runs against a `git archive` export of
 * HEAD, which by design carries no .git. A guard whose entire subject is
 * history has nothing to read in that tree — it would fail closed there,
 * correctly and uselessly, on every local run. Run it in a real checkout.
 *
 * Usage:  php scripts/ci-sdk-version-collision.php
 */

$projectRoot = dirname(__DIR__);
$sdkFileRelative = 'sdk/src/Sdk.php';
$sdkFile = $projectRoot . '/' . $sdkFileRelative;
$testFileRelative = 'tests/Sdk/SdkPackageContractTest.php';
$testFile = $projectRoot . '/' . $testFileRelative;

/**
 * The branch every SDK version number is unique against. `develop` is the
 * integration branch: main only ever receives what develop already carried, so
 * a version that is free on develop is free everywhere.
 */
const BASE_BRANCH = 'develop';

/**
 * Run git and return [exit code, stdout lines]. stderr is folded into the
 * output so a failure explains itself in the message this script prints.
 *
 * @param  list<string> $args
 * @return array{0: int, 1: list<string>}
 */
function git(string $projectRoot, array $args): array
{
    $command = 'git -C ' . escapeshellarg($projectRoot);
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }

    $output = [];
    $status = 0;
    exec($command . ' 2>&1', $output, $status);

    return [$status, array_values($output)];
}

function fail(string $summary, string $detail, string $remediation): never
{
    fwrite(STDERR, 'FAIL: ' . $summary . "\n\n");
    if ($detail !== '') {
        fwrite(STDERR, $detail . "\n\n");
    }
    fwrite(STDERR, $remediation . "\n");
    exit(1);
}

/**
 * The MAJOR.MINOR a version string identifies. The ledgers and the header
 * docblock name minors ("1.34"); the constant carries a full semver
 * ("1.34.0", and historically a patch such as "1.1.1"). Comparing the three
 * pins on the minor is therefore comparing what they each actually claim.
 */
function minorOf(string $version): string
{
    $parts = explode('.', $version);

    return $parts[0] . '.' . ($parts[1] ?? '0');
}

/** The VERSION constant declared by a given Sdk.php source text, or null. */
function declaredVersion(string $source): ?string
{
    if (preg_match('/const\s+VERSION\s*=\s*\'(\d+\.\d+(?:\.\d+)?)\'/', $source, $m) !== 1) {
        return null;
    }

    return $m[1];
}

// ---------------------------------------------------------------------------
// 0. The file itself
// ---------------------------------------------------------------------------

if (!is_file($sdkFile)) {
    fail(
        "there is no {$sdkFileRelative} to check.",
        '',
        "This guard reads the canonical SDK identity file. If it moved, move this script's path with it."
    );
}

$source = (string) file_get_contents($sdkFile);
$version = declaredVersion($source);

if ($version === null) {
    fail(
        "could not read Sdk::VERSION out of {$sdkFileRelative}.",
        '',
        "The constant must read exactly:\n\n"
        . "    public const VERSION = '1.2.3';\n\n"
        . 'Every gate that evaluates a plugin\'s SDK constraint reads that constant, so an '
        . "unparseable one is not a formatting nit.\n"
    );
}

$minor = minorOf($version);
$failures = [];

// ---------------------------------------------------------------------------
// 1. The three pins in Sdk.php agree
// ---------------------------------------------------------------------------

// The header docblock's opening sentence: " * SDK identity (v1.34)."
if (preg_match('/SDK identity \(v(\d+\.\d+)\)/', $source, $m) !== 1) {
    $failures[] = sprintf(
        '  [header]    %s has no "SDK identity (vX.Y)" sentence to check. It is the version a plugin '
        . 'author reads first; it is a pin whether or not anything enforces it.',
        $sdkFileRelative
    );
} elseif ($m[1] !== $minor) {
    $failures[] = sprintf(
        '  [header]    the header docblock says "SDK identity (v%s)" while the constant says %s. '
        . 'The file contradicts itself in its own opening sentence (this is #872 exactly).',
        $m[1],
        $version
    );
}

/**
 * The version numbers of the ledger entries in the Sdk.php header docblock, in
 * the order the file states them.
 *
 * An entry opens with its version followed by a parenthesised description —
 * `1.34 (CONTEXT-AWARE ...)` — chained with arrows. A bare back-reference in
 * prose ("every tree that validated under 1.33 still validates") carries no
 * opening parenthesis and so is not an entry, which is what keeps this from
 * having to understand the prose.
 *
 * @return list<string>
 */
function sdkLedgerVersions(string $source): array
{
    if (preg_match('#/\*\*(.*?)\*/#s', $source, $m) !== 1) {
        return [];
    }

    $docblock = (string) preg_replace('/^\s*\*\s?/m', '', $m[1]);
    $flat = (string) preg_replace('/\s+/', ' ', $docblock);

    preg_match_all('/(?<![\d.])(\d+\.\d+) \(/', $flat, $matches);

    return $matches[1];
}

/**
 * The version numbers of the ledger entries in the assertion message that pins
 * Sdk::VERSION in the contract test, in the order the test states them.
 *
 * Located by the assertion itself rather than by method name: the ledger lives
 * in the one assertSame() whose expected value is a literal version and whose
 * actual value is Sdk::VERSION. Entries are `;`-separated and open with an
 * optional "SDK " and the version, so a semicolon inside an entry's prose
 * yields a chunk with no version and is ignored.
 *
 * @return list<string>
 */
function testLedgerVersions(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
    $start = null;
    foreach ($lines as $i => $line) {
        if (preg_match('/^\'\d+\.\d+(?:\.\d+)?\',$/', trim($line)) !== 1) {
            continue;
        }
        if (str_contains($lines[$i + 1] ?? '', 'Sdk::VERSION,')) {
            $start = $i + 2;
            break;
        }
    }

    if ($start === null) {
        return [];
    }

    $message = '';
    for ($i = $start, $count = count($lines); $i < $count; $i++) {
        if (trim($lines[$i]) === ');') {
            break;
        }
        // Every single-quoted chunk on the line, unescaped and concatenated —
        // the message is written as a `.`-chain across many lines.
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $lines[$i], $chunks);
        foreach ($chunks[1] as $chunk) {
            $message .= str_replace(["\\'", '\\\\'], ["'", '\\'], $chunk);
        }
    }

    $versions = [];
    foreach (explode(';', $message) as $segment) {
        if (preg_match('/^(?:SDK )?(\d+\.\d+)\b/', trim($segment), $m) === 1) {
            $versions[] = $m[1];
        }
    }

    return $versions;
}

// ---------------------------------------------------------------------------
// 2. Each ledger runs in its own direction, strictly
// ---------------------------------------------------------------------------

/**
 * @param  list<string> $versions
 * @return list<string> the ordering violations, as human sentences
 */
function monotonicityFailures(array $versions, string $direction, string $where): array
{
    $operator = $direction === 'ascending' ? '>' : '<';
    $problems = [];

    for ($i = 1, $count = count($versions); $i < $count; $i++) {
        $previous = $versions[$i - 1];
        $current = $versions[$i];

        if ($previous === $current) {
            $problems[] = sprintf(
                '  [ledger]    %s lists %s twice. Two entries under one version number is what a '
                . 'hand-merged collision leaves behind — the two features are not distinguishable '
                . 'by the constraint a plugin writes.',
                $where,
                $current
            );
            continue;
        }

        if (!version_compare($current, $previous, $operator)) {
            $problems[] = sprintf(
                '  [ledger]    %s is %s (oldest %s), but %s follows %s. An entry out of order reads '
                . 'as though one release shipped another\'s feature.',
                $where,
                $direction,
                $direction === 'ascending' ? 'first' : 'last',
                $current,
                $previous
            );
        }
    }

    return $problems;
}

$sdkLedger = sdkLedgerVersions($source);

// A one-entry ledger is not a short ledger, it is a parse that stopped working:
// monotonicity is vacuous on a single element, so an extraction that silently
// degraded would report OK forever. Both ledgers have carried tens of entries
// since 1.8 and neither can shrink — the policy is additive.
if (count($sdkLedger) < 2) {
    $failures[] = sprintf(
        '  [ledger]    found %d ledger entr(ies) in %s\'s header docblock. It is the record a plugin '
        . 'author reads to pick a constraint, and it only grows — so this is a ledger that was '
        . 'reformatted out from under the guard, not a short one.',
        count($sdkLedger),
        $sdkFileRelative
    );
} else {
    // OLDEST-FIRST: the newest entry is the LAST one.
    $failures = array_merge($failures, monotonicityFailures($sdkLedger, 'ascending', $sdkFileRelative));

    $newest = $sdkLedger[count($sdkLedger) - 1];
    if ($newest !== $minor) {
        $failures[] = sprintf(
            '  [ledger]    %s is OLDEST-FIRST, so its last entry is the release being shipped — but '
            . 'that entry is %s while the constant says %s. A bump with no ledger entry ships a '
            . 'version number nothing explains.',
            $sdkFileRelative,
            $newest,
            $version
        );
    }
}

$testLedger = testLedgerVersions($testFile);

if (count($testLedger) < 2) {
    $failures[] = sprintf(
        '  [ledger]    found %d ledger entr(ies) in %s\'s Sdk::VERSION assertion. That assertion is '
        . 'the pin that makes a bump a deliberate act rather than an edit; it does not shrink.',
        count($testLedger),
        $testFileRelative
    );
} else {
    // NEWEST-FIRST: the newest entry is the FIRST one. The opposite direction
    // from the file above, in the same repository, by design — which is exactly
    // why it is asserted rather than assumed.
    $failures = array_merge($failures, monotonicityFailures($testLedger, 'descending', $testFileRelative));

    if ($testLedger[0] !== $minor) {
        $failures[] = sprintf(
            '  [ledger]    %s is NEWEST-FIRST, so its first entry is the release being shipped — but '
            . 'that entry is %s while the constant says %s.',
            $testFileRelative,
            $testLedger[0],
            $version
        );
    }

    // A version the test narrates but the SDK's own ledger never declared.
    if (count($sdkLedger) >= 2) {
        foreach (array_unique($testLedger) as $narrated) {
            if (!in_array($narrated, $sdkLedger, true)) {
                $failures[] = sprintf(
                    '  [ledger]    %s narrates %s, which %s never declares. One of the two ledgers is '
                    . 'describing a release that does not exist.',
                    $testFileRelative,
                    $narrated,
                    $sdkFileRelative
                );
            }
        }
    }
}

if ($failures !== []) {
    fail(
        sprintf('the SDK version %s is not stated consistently.', $version),
        implode("\n", $failures),
        "Sdk.php pins its version in THREE places and they must agree:\n\n"
        . "    1. the header docblock  — \" * SDK identity (v{$minor}).\"\n"
        . "    2. the last ledger entry in that same docblock (OLDEST-FIRST)\n"
        . "    3. the constant         — \"public const VERSION = '{$version}';\"\n\n"
        . "and the narrative ledger in {$testFileRelative} runs the OTHER WAY — NEWEST-FIRST, so the\n"
        . "entry for this release goes at the TOP of that assertion message and at the BOTTOM of the\n"
        . "docblock. Getting the two directions backwards is how one PR's feature ends up documented\n"
        . "as another PR's release, so the directions are asserted rather than remembered.\n"
    );
}

// ---------------------------------------------------------------------------
// 3. The declared version moved forward, and nothing on develop used it
// ---------------------------------------------------------------------------

[$status, $output] = git($projectRoot, ['rev-parse', '--git-dir']);
if ($status !== 0) {
    fail(
        'this is not a git checkout, so the version cannot be compared against history.',
        implode("\n", $output),
        "This guard is vertical by definition — it compares the declared version against what develop\n"
        . "already shipped. Without history there is nothing to compare, and passing anyway would be\n"
        . "the silent false green #873 is about. Run it from a git checkout.\n"
    );
}

// Shallow first, and fail on it, because a shallow clone does not error — it
// answers with a SMALLER history than the real one, so a collision reads as
// unused. actions/checkout defaults to depth 1; the job that runs this passes
// fetch-depth: 0.
[$status, $output] = git($projectRoot, ['rev-parse', '--is-shallow-repository']);
if ($status === 0 && ($output[0] ?? '') === 'true') {
    fail(
        'the checkout is SHALLOW, so develop\'s version history is not visible.',
        '',
        "A shallow clone answers `git log develop -- {$sdkFileRelative}` with only the commits it\n"
        . "happens to carry, which is a smaller set of used versions than the real one — so a version\n"
        . "that IS already taken would be reported as free. That is a false green of exactly the kind\n"
        . "this guard exists to remove, so it refuses to answer instead.\n\n"
        . "In CI, give the job:\n\n"
        . "    - uses: actions/checkout@v7\n"
        . "      with:\n"
        . "        fetch-depth: 0\n\n"
        . "Locally:  git fetch --unshallow\n"
    );
}

// origin/develop when there is a remote (CI, and any normal clone), the local
// branch when there is not.
$baseRef = null;
foreach (['refs/remotes/origin/' . BASE_BRANCH, 'refs/heads/' . BASE_BRANCH] as $candidate) {
    [$status] = git($projectRoot, ['rev-parse', '--verify', '--quiet', $candidate]);
    if ($status === 0) {
        $baseRef = $candidate;
        break;
    }
}

if ($baseRef === null) {
    fail(
        sprintf('neither origin/%s nor %s is present in this checkout.', BASE_BRANCH, BASE_BRANCH),
        '',
        sprintf(
            "%s is the branch SDK version numbers are unique against, so without it this guard has no\n"
            . "reference to check against and fails rather than passing blind. A CI checkout with\n"
            . "fetch-depth: 0 fetches every branch, so this normally means the fetch was narrowed\n"
            . "(fetch-depth, or a single-branch clone).\n\n"
            . "Locally:  git fetch origin %s\n",
            BASE_BRANCH,
            BASE_BRANCH
        )
    );
}

[$status, $output] = git($projectRoot, ['merge-base', 'HEAD', $baseRef]);
if ($status !== 0 || ($output[0] ?? '') === '') {
    fail(
        sprintf('HEAD and %s have no common ancestor this checkout can see.', $baseRef),
        implode("\n", $output),
        "The merge base is what says whether this branch CHANGED the version or merely carries it, so\n"
        . "without one there is no question to answer. Fetch the full history (fetch-depth: 0 in CI,\n"
        . "`git fetch --unshallow` locally) and run it again.\n"
    );
}
$mergeBase = $output[0];

[$status, $output] = git($projectRoot, ['show', $mergeBase . ':' . $sdkFileRelative]);
$baseVersion = $status === 0 ? declaredVersion(implode("\n", $output)) : null;

if ($status === 0 && $baseVersion === null) {
    fail(
        sprintf('%s at the merge base %s declares no readable VERSION.', $sdkFileRelative, substr($mergeBase, 0, 8)),
        '',
        "The base version is the whole basis of the forward-only comparison. This is decidable and it\n"
        . "did not decide, so it fails rather than skipping the check.\n"
    );
}

// The file did not exist at the merge base at all (a branch cut from before the
// SDK was extracted, or the file's introducing commit). Nothing to compare
// backwards to, and every version is therefore "new" — the collision check
// below still applies, which is the half that matters.
$baseKnown = $baseVersion !== null;

if ($baseKnown && $baseVersion === $version) {
    printf(
        "OK: SDK %s is stated consistently in all three pins; %d ledger entries oldest-first in %s and "
        . "%d newest-first in %s; version unchanged from the merge base %s, so there is nothing to "
        . "compare against %s's history.\n",
        $version,
        count($sdkLedger),
        $sdkFileRelative,
        count($testLedger),
        $testFileRelative,
        substr($mergeBase, 0, 8),
        BASE_BRANCH
    );
    exit(0);
}

if ($baseKnown && !version_compare($version, $baseVersion, '>')) {
    fail(
        sprintf('the SDK version went BACKWARDS: %s at the merge base, %s here.', $baseVersion, $version),
        '',
        "The SDK's versioning policy is additive and forward-only: a plugin resolves its constraint\n"
        . "against whatever the host reports, so a version that moves backwards makes an already-\n"
        . "shipped constraint unsatisfiable on a host that is strictly newer.\n\n"
        . sprintf("    merge base with %s:  %s (%s)\n", BASE_BRANCH, $baseVersion, substr($mergeBase, 0, 8))
        . sprintf("    declared here:       %s\n", $version)
    );
}

// Every version this file has ever declared on develop, mapped to the commit
// that first declared it. `git log -- path` on a merge-commit history reports
// the branch commits themselves, so both sides of a parallel pair are in here.
[$status, $commits] = git($projectRoot, ['log', '--format=%H', '--reverse', $baseRef, '--', $sdkFileRelative]);
if ($status !== 0) {
    fail(
        sprintf('could not read %s\'s history of %s.', BASE_BRANCH, $sdkFileRelative),
        implode("\n", $commits),
        "Without the history there is no collision check, and passing anyway is the failure mode this\n"
        . "guard exists for.\n"
    );
}

/** @var array<string, string> version => "sha subject" of the commit that first declared it */
$usedVersions = [];
$unreadable = 0;

foreach ($commits as $sha) {
    if ($sha === '') {
        continue;
    }

    [$showStatus, $showOutput] = git($projectRoot, ['show', $sha . ':' . $sdkFileRelative]);
    if ($showStatus !== 0) {
        continue;
    }

    $seen = declaredVersion(implode("\n", $showOutput));
    if ($seen === null) {
        // A commit from before the constant existed. Counted and reported
        // rather than ignored, but not fatal: it is not decidable and not
        // fixable, and refusing to run over ancient history would just mean
        // the guard never runs.
        $unreadable++;
        continue;
    }

    if (isset($usedVersions[$seen])) {
        continue;
    }

    [, $subject] = git($projectRoot, ['log', '-1', '--format=%h %s', $sha]);
    $usedVersions[$seen] = $subject[0] ?? substr($sha, 0, 8);
}

if (isset($usedVersions[$version])) {
    fail(
        sprintf('SDK version %s is ALREADY USED on %s.', $version, BASE_BRANCH),
        sprintf(
            "  [collision] %s was first declared by:\n\n      %s\n\n"
            . "  This branch declares it again with a different meaning. Whichever of the two merges\n"
            . "  second wins, and a plugin declaring ^%s then resolves against a contract nobody can\n"
            . "  identify from the constraint — on a device, offline, with no way to ask (#849).",
            $version,
            $usedVersions[$version],
            $minor
        ),
        sprintf(
            "Renumber this branch to the next unused minor and move BOTH ledger entries with it:\n\n"
            . "    1. sdk/src/Sdk.php — the header \"SDK identity (vX.Y)\", the ledger entry (which goes\n"
            . "       LAST: that docblock is OLDEST-FIRST), and the VERSION constant\n"
            . "    2. sdk/composer.json — the `version` field\n"
            . "    3. %s — the ledger entry, which goes FIRST: that\n"
            . "       one is NEWEST-FIRST, the opposite direction from the docblock\n"
            . "    4. re-vendor templates/tauri-desktop/php-host/sdk (see ci-vendored-sdk-parity.php)\n\n"
            . "Highest version on %s: %s. The lowest free minor above it is %s.\n",
            $testFileRelative,
            BASE_BRANCH,
            (static function (array $used): string {
                $versions = array_keys($used);
                usort($versions, 'version_compare');

                return $versions === [] ? '(none)' : (string) end($versions);
            })($usedVersions),
            (static function (array $used): string {
                $versions = array_keys($used);
                usort($versions, 'version_compare');
                $highest = $versions === [] ? '0.0' : (string) end($versions);
                $parts = explode('.', $highest);

                return $parts[0] . '.' . ((int) ($parts[1] ?? 0) + 1) . '.0';
            })($usedVersions)
        )
    );
}

printf(
    "OK: SDK %s is stated consistently in all three pins; %d ledger entries oldest-first, %d "
    . "newest-first; and it is a forward, unused version — %s at the merge base %s, and %d version(s) "
    . "across %d commit(s) of %s history, none of them %s.%s\n",
    $version,
    count($sdkLedger),
    count($testLedger),
    $baseKnown ? $baseVersion : '(the file did not exist)',
    substr($mergeBase, 0, 8),
    count($usedVersions),
    count(array_filter($commits, static fn (string $sha): bool => $sha !== '')),
    BASE_BRANCH,
    $version,
    $unreadable > 0 ? sprintf(' (%d older commit(s) declared no readable VERSION)', $unreadable) : ''
);
exit(0);
