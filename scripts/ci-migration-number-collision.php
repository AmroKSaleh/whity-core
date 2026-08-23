<?php

declare(strict_types=1);

/**
 * CI migration-number collision guard (#971).
 *
 * The twin of {@see ci-sdk-version-collision.php} (#873/#929), for the other
 * number this repository allocates by hand across parallel branches.
 *
 * WHAT HAPPENED
 * -------------
 * #970 and #965 were built in parallel and each added migrations numbered 106
 * and 107:
 *
 *     #970   106_add_service_auth_method.php    107_seed_cli_service_principal.php
 *     #965   106_create_documents.php           107_grant_documents_read_all_to_admin.php
 *
 * Merging both would have put FOUR migrations on develop under two numbers.
 *
 * WHY NOTHING CAUGHT IT — three reasons, each sufficient on its own
 * -----------------------------------------------------------------
 *  1. GIT DOES NOT CONFLICT. The filenames differ, so both files land cleanly.
 *     There is nothing to resolve and therefore nothing to notice.
 *  2. THE RUNNER DOES NOT CARE. `MigrationsCommand::getMigrationFiles()` is
 *     `scandir()` + `sort()` over full paths, and each file is recorded in
 *     `core_schema_migrations` under its own name. Four files, two numbers, no
 *     error.
 *  3. THE SECOND PR'S CI IS HONESTLY GREEN, because it ran against a base that
 *     predates the first. Its verdict describes a tree that stops existing the
 *     moment it merges.
 *
 * WHY IT MATTERS EVEN THOUGH IT DOES NOT CRASH
 * --------------------------------------------
 * The prefix stops meaning APPLY ORDER and starts meaning "whatever the
 * filename sorts to". `106_add_service_auth_method` precedes
 * `106_create_documents` because `_a` < `_c` — an order neither author chose,
 * because neither knew the other existed.
 *
 * That is harmless while the two are independent and a data-loss bug the moment
 * they are not: a migration that assumes a column another 106 adds runs before
 * it on one deployment and after it on another, decided by nothing but how the
 * descriptions happen to sort. It also ends the ability to review a numbered
 * sequence — "what ran between 105 and 108" no longer has one answer.
 *
 * The deployments that care are the ones this platform sells into: long-lived
 * sovereign installs where migrations are applied incrementally over months
 * rather than recreated from scratch.
 *
 * WHAT IS COMPARED, AND AGAINST WHAT
 * ----------------------------------
 * The working tree against the TIP of develop, not against the merge base. The
 * question is what will exist after this branch merges, and that is the union
 * of the two trees; the merge base is only used to prove the two are related.
 *
 * A number the branch shares with develop under the SAME filename is the branch
 * simply carrying a migration it inherited, which is every branch cut after that
 * migration landed. Only a shared number under DIFFERENT filenames is two
 * authors having independently claimed it.
 *
 * WHY IT FAILS CLOSED WHEN IT CANNOT SEE HISTORY
 * ----------------------------------------------
 * Same reasoning as the SDK guard, and the same failure it prevents. A shallow
 * clone does not error — it answers with a smaller tree than the real one, so a
 * number that IS taken reads as free. That is the silent false green this guard
 * exists to remove, so it refuses to answer rather than guessing.
 *
 * NOT WIRED INTO scripts/ci-local.sh, deliberately, for the reason the SDK guard
 * gives: `ci-local.sh --clean` runs against a `git archive` export of HEAD,
 * which by design carries no `.git`. A guard whose whole subject is history
 * would fail closed there on every local run — correctly, and uselessly.
 *
 * NOT CHECKED HERE, deliberately:
 *   - GAPS in the sequence (105, 107, no 106). A gap is not ambiguous: it
 *     orders fine and reviews fine. Only a duplicate is.
 *   - PLUGIN migrations. Plugins ship their own directories and number within
 *     them, so two plugins are not competing for one sequence.
 *
 * Usage:  php scripts/ci-migration-number-collision.php
 */

$projectRoot = dirname(__DIR__);
$migrationDirRelative = 'database/migrations';
$migrationDir = $projectRoot . '/' . $migrationDirRelative;

/**
 * The branch every migration number is unique against. `develop` is the
 * integration branch: main only ever receives what develop already carried, so
 * a number that is free on develop is free everywhere.
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
 * The numeric prefix a migration filename declares, or null when it has none.
 *
 * Returned as a string so the padding survives the read: `sort()` orders the
 * FILENAMES, so `007_x` and `7_x` sit in different places even though they are
 * the same integer, and collapsing them here would report a collision between
 * two files that do not actually share a position.
 *
 * Note that the padding does NOT survive being used as an array key: PHP casts
 * an integer-like key, so `'106'` becomes `int 106` while `'006'` stays a
 * string. That is harmless because both sides of the comparison are indexed the
 * same way and therefore cast the same way — but it is why the helpers below
 * accept `int|string` rather than pretending the type is uniform.
 */
function prefixOf(string $filename): ?string
{
    return preg_match('/^(\d+)_/', $filename, $m) === 1 ? $m[1] : null;
}

/**
 * Migration filenames in a directory listing, indexed by numeric prefix.
 *
 * @param  list<string> $filenames Bare filenames, no directory part.
 * @return array<int|string, list<string>> prefix => filenames carrying it
 */
function byPrefix(array $filenames): array
{
    $indexed = [];
    foreach ($filenames as $filename) {
        if (!str_ends_with($filename, '.php')) {
            continue;
        }
        $prefix = prefixOf($filename);
        if ($prefix === null) {
            continue;
        }
        $indexed[$prefix][] = $filename;
    }

    ksort($indexed);

    return $indexed;
}

/**
 * The next free prefix: one above the highest either side carries.
 *
 * Takes `int|string` because PHP has already cast the unpadded keys to int by
 * the time they arrive from `array_keys()` (see {@see prefixOf()}). Typing this
 * `string` compiled and passed every same-branch test, then fataled the first
 * time a real cross-branch collision tried to build its remediation message —
 * a failure only reachable on the path that matters.
 *
 * @param int|string ...$prefixes Every prefix in use on either side.
 */
function suggestNext(int|string ...$prefixes): string
{
    $highest = 0;
    foreach ($prefixes as $prefix) {
        $highest = max($highest, (int) $prefix);
    }

    return str_pad((string) ($highest + 1), 3, '0', STR_PAD_LEFT);
}

// ---------------------------------------------------------------------------
// 1. This branch's migrations
// ---------------------------------------------------------------------------

if (!is_dir($migrationDir)) {
    fail(
        sprintf('%s does not exist.', $migrationDirRelative),
        '',
        "This guard reads the core migration directory; without it there is nothing to check and\n"
        . "passing silently would hide a move nobody meant to make. Run it from a repository root.\n"
    );
}

$branchFiles = scandir($migrationDir);
if ($branchFiles === false) {
    fail(
        sprintf('%s could not be read.', $migrationDirRelative),
        '',
        "Check permissions and run it again.\n"
    );
}

$branch = byPrefix($branchFiles);

// ---------------------------------------------------------------------------
// 2. A number claimed twice WITHIN this branch
// ---------------------------------------------------------------------------
//
// Cheap, needs no history, and catches one author making the mistake twice —
// which the cross-branch check below cannot see, because both files arrive
// together.

$internal = [];
foreach ($branch as $prefix => $filenames) {
    if (count($filenames) > 1) {
        $internal[$prefix] = $filenames;
    }
}

if ($internal !== []) {
    $detail = '';
    foreach ($internal as $prefix => $filenames) {
        sort($filenames);
        $detail .= sprintf("  %s is claimed by %d files:\n", $prefix, count($filenames));
        foreach ($filenames as $filename) {
            $detail .= '      ' . $migrationDirRelative . '/' . $filename . "\n";
        }
    }

    fail(
        'this branch gives two migrations the same number.',
        rtrim($detail),
        "The prefix is the apply order, so two files sharing one are ordered by whatever their\n"
        . "descriptions happen to sort to — which is not an order anybody chose. Renumber all but\n"
        . "one of them.\n"
    );
}

// ---------------------------------------------------------------------------
// 3. A number this branch and develop each claim, for different files
// ---------------------------------------------------------------------------

[$status, $output] = git($projectRoot, ['rev-parse', '--git-dir']);
if ($status !== 0) {
    fail(
        'this is not a git checkout, so migration numbers cannot be compared against develop.',
        implode("\n", $output),
        "This guard is vertical by definition — it compares the numbers on this branch against the\n"
        . "ones develop already carries. Without history there is nothing to compare, and passing\n"
        . "anyway would be the silent false green #971 is about. Run it from a git checkout.\n"
    );
}

// Shallow first, and fail on it, because a shallow clone does not error — it
// answers with a SMALLER tree than the real one, so a number that IS taken
// reads as free. actions/checkout defaults to depth 1; the job that runs this
// passes fetch-depth: 0.
[$status, $output] = git($projectRoot, ['rev-parse', '--is-shallow-repository']);
if ($status === 0 && ($output[0] ?? '') === 'true') {
    fail(
        'the checkout is SHALLOW, so develop\'s migration set is not visible.',
        '',
        "A shallow clone may not carry develop at all, and a develop it cannot read is a develop\n"
        . "whose numbers all look free. That is a false green of exactly the kind this guard exists\n"
        . "to remove, so it refuses to answer instead.\n\n"
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
            "%s is the branch migration numbers are unique against, so without it this guard has no\n"
            . "reference to check against and fails rather than passing blind. A CI checkout with\n"
            . "fetch-depth: 0 fetches every branch, so this normally means the fetch was narrowed\n"
            . "(fetch-depth, or a single-branch clone).\n\n"
            . "Locally:  git fetch origin %s\n",
            BASE_BRANCH,
            BASE_BRANCH
        )
    );
}

// The merge base is not what the numbers are compared against — develop's tip
// is. It is checked because two trees with no common ancestor are not a branch
// and its base, and comparing them would produce noise rather than a finding.
[$status, $output] = git($projectRoot, ['merge-base', 'HEAD', $baseRef]);
if ($status !== 0 || ($output[0] ?? '') === '') {
    fail(
        sprintf('HEAD and %s have no common ancestor this checkout can see.', $baseRef),
        implode("\n", $output),
        "Without a common ancestor there is no way to tell this branch's migrations from unrelated\n"
        . "ones. Fetch the full history (fetch-depth: 0 in CI, `git fetch --unshallow` locally) and\n"
        . "run it again.\n"
    );
}

[$status, $output] = git($projectRoot, ['ls-tree', '--name-only', $baseRef . ':' . $migrationDirRelative]);
if ($status !== 0) {
    fail(
        sprintf('%s could not be listed on %s.', $migrationDirRelative, $baseRef),
        implode("\n", $output),
        "The guard compares against the migrations develop carries; if that directory cannot be read\n"
        . "there is nothing to compare and a pass would mean nothing.\n"
    );
}

$base = byPrefix($output);

$collisions = [];
foreach ($branch as $prefix => $branchNames) {
    $baseNames = $base[$prefix] ?? [];
    if ($baseNames === []) {
        continue;
    }

    // Same number AND same filename is this branch carrying a migration it
    // inherited — every branch cut after that migration landed does this.
    $newNames = array_values(array_diff($branchNames, $baseNames));
    if ($newNames === []) {
        continue;
    }

    $collisions[$prefix] = ['branch' => $newNames, 'base' => $baseNames];
}

if ($collisions !== []) {
    $detail = '';
    foreach ($collisions as $prefix => $sides) {
        $detail .= sprintf("  %s is already used on %s:\n", $prefix, BASE_BRANCH);
        foreach ($sides['base'] as $filename) {
            $detail .= '      ' . BASE_BRANCH . ':  ' . $migrationDirRelative . '/' . $filename . "\n";
        }
        foreach ($sides['branch'] as $filename) {
            $detail .= '      this branch: ' . $migrationDirRelative . '/' . $filename . "\n";
        }
    }

    $next = suggestNext(
        ...array_merge(array_keys($branch), array_keys($base))
    );

    fail(
        'this branch claims a migration number develop already uses.',
        rtrim($detail),
        sprintf(
            "Both files would land — git does not conflict on different filenames, and the runner\n"
            . "records each under its own name — leaving one number with two meanings and an apply\n"
            . "order decided by how the descriptions sort.\n\n"
            . "Renumber this branch's migration(s), starting at %s, and rename the class to match:\n\n"
            . "    git mv %s/106_example.php %s/%s_example.php\n\n"
            . "The class name is derived from the filename with the prefix stripped, so a rename that\n"
            . "keeps the description keeps the class.\n",
            $next,
            $migrationDirRelative,
            $migrationDirRelative,
            $next
        )
    );
}

printf(
    "OK: %d migration number(s) on this branch, none colliding with %s (%d there).\n",
    count($branch),
    BASE_BRANCH,
    count($base)
);

exit(0);
