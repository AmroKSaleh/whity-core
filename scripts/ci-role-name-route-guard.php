<?php

declare(strict_types=1);

/**
 * CI role-name route guard (#1259, carved out of #990).
 *
 * ── What it stops ─────────────────────────────────────────────────────────
 *
 * A route gated on a ROLE NAME instead of a permission slug. `RbacMiddleware`
 * resolves `$requiredRole` through `hasRoleForProfile($profileId, 'admin',
 * $tenantId)`, so on any deployment whose administrative role is called
 * something else, that route answers 403 to the very administrator it was
 * written for. Being renameable is the point of a white-label platform.
 *
 * ── Why the existing guard does not cover this ────────────────────────────
 *
 * `ci-grant-by-role-name-guard.php` polices MIGRATIONS — a migration
 * resolving a role by name to decide who gets a permission. It says nothing
 * about a route. And `ci-permission-holder-guard.php` reports role-gated
 * routes, but only in `public/index.php`, and only as an informational tail
 * on a check about something else: it passes while they exist, by design.
 *
 * So #990 could be worked to zero and regrow the next week with nothing to
 * catch it. That already happened in miniature: twenty role-name gates sat in
 * `BaseCommand.php` for months (#1258) precisely because no guard read the
 * second entry point.
 *
 * ── Both entry points, deliberately ───────────────────────────────────────
 *
 * `public/index.php` and `src/Cli/Commands/BaseCommand.php` register the same
 * routes for HTTP and for the CLI. BaseCommand's own comment states the rule:
 * "a route whose gate depends on which entry point reached it is two rules
 * wearing one name". A guard reading only one of them enforces half a rule,
 * which is how the twenty got there.
 *
 * ── Two shapes ────────────────────────────────────────────────────────────
 *
 *   $router->register('GET', '/api/x', [$h, 'm'], 'admin');   <- route gate
 *   'requiredRole' => 'admin',                                <- nav item gate
 *
 * The nav shape is included because the navigation items are gated the same
 * way and drift for the same reason — and because the one item everybody
 * points at as a legitimate exception is a nav item, so a guard that cannot
 * see nav items cannot express that exception either.
 *
 * Usage:  php scripts/ci-role-name-route-guard.php
 */

/**
 * Gates that exist today, each with why it is still a role name.
 *
 * DEBT, NOT PERMISSION. Every entry is a route that breaks on a renamed
 * administrative role. The list is printed on every green build so it cannot
 * go quiet, and #990 is to empty it.
 *
 * Keyed by `METHOD /path` and `nav:<id>` rather than by line number, because
 * line numbers move whenever anything above them changes and a guard whose
 * allowlist rots on unrelated edits gets deleted.
 */
const GRANDFATHERED = [
    // BLOCKED ON #1283, not merely unslugged — do not mint a slug family here
    // without reading it first.
    //
    // DeploymentManager has ONE commit (2026-05-18), the feature has no UI and
    // no caller anywhere in the repo, and `rollbackMigration()` says in its own
    // comment that it only records the intent. The routes are nonetheless
    // published in public/openapi.json and the generated typed client, and are
    // reachable by anyone holding `admin`.
    //
    // Minting `deployments:*` would add catalogue rows, a grant migration and a
    // permanent RBAC surface for something that may be deleted instead. #1283
    // asks whether to finish it, unpublish the routes, or remove it.
    'POST /api/deployments/apply' => '#1283: feature unfinished and uncalled; decide before slugging',
    'POST /api/deployments/rollback' => '#1283: feature unfinished and uncalled; decide before slugging',
    'GET /api/deployments/status' => '#1283: feature unfinished and uncalled; decide before slugging',

    // #990/#1258: no `migrations:*` slug. Registered in BOTH entry points and
    // role-gated in both, so the two currently AGREE — re-gating one side
    // alone would break the mirror rather than complete it.
    'GET /api/migrations' => '#990: no migrations:* slug; role-gated in both entry points, so they agree',
    'POST /api/migrations/run' => '#990: no migrations:* slug; CLI-only route',
    'POST /api/migrations/rollback' => '#990: no migrations:* slug; CLI-only route',

    // The stats pair is GONE — the route and the `dashboard` nav item both moved
    // onto `stats:read` by migration 157, in one commit. This guard is what made
    // that atomic: re-gating either half alone left the other in the list and
    // failed the build, which is the coupling working rather than nagging.

    // The email_domains group is GONE — re-gated onto `email_domains:manage`
    // by migration 156. Recorded as a comment rather than deleted silently:
    // this list is the running record of what #990 still owes, and a group
    // leaving it is the only visible sign of progress between here and the
    // issue. The guard itself forced this edit — it refuses a grandfathered
    // entry that has been fixed, which is the half of a bidirectional check
    // that is easy to leave out and is the half that keeps the list honest.

    // LEGITIMATE, and the only one here that is not debt. The page has three
    // tabs behind three different permissions, and each enforces its own
    // server-side. A tenant admin holding ONLY password_resets:approve must
    // still see the entry to reach their tab, so gating the nav on any single
    // one of the three would hide a page they are entitled to use. The broad
    // role is the correct gate for VISIBILITY here; authority is enforced per
    // tab regardless of what the client renders.
    'nav:approval-gating' => 'DELIBERATE: three tabs, three permissions, each enforced server-side per tab',
];

$root = dirname(__DIR__);
$files = [
    $root . '/public/index.php',
    $root . '/src/Cli/Commands/BaseCommand.php',
];

$found = [];      // key => list of "file:line"
$patternHits = 0;

foreach ($files as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Entry point not found: {$path}\n");
        exit(2);
    }

    $lines = explode("\n", (string) file_get_contents($path));
    $short = basename(dirname($path)) . '/' . basename($path);
    $lastNavId = null;

    foreach ($lines as $i => $line) {
        $lineNo = $i + 1;

        if (preg_match("/'id'\s*=>\s*'([^']+)'/", $line, $m) === 1) {
            $lastNavId = $m[1];
        }

        // Route gate: the role is the 4th positional argument, so anchor past
        // the handler array — otherwise the handler's own method name matches.
        if (preg_match("/register\(\s*'([A-Z]+)'\s*,\s*'([^']+)'\s*,\s*\[[^\]]*\]\s*,\s*'([a-z][a-z0-9_-]*)'/", $line, $m) === 1) {
            $patternHits++;
            $found[$m[1] . ' ' . $m[2]][] = "{$short}:{$lineNo}";
            continue;
        }

        if (preg_match("/'requiredRole'\s*=>\s*'([a-z][a-z0-9_-]*)'/", $line) === 1) {
            $patternHits++;
            $key = 'nav:' . ($lastNavId ?? "unknown@{$lineNo}");
            $found[$key][] = "{$short}:{$lineNo}";
        }
    }
}

// A parser that suddenly matches nothing is not a clean bill of health. The
// registration style could have changed, or these regexes could have broken;
// both look identical to "no role-name gates left" and only one of them is
// good news. #990 emptying the list legitimately is handled below, by the
// allowlist being empty too.
if ($patternHits === 0 && GRANDFATHERED !== []) {
    fwrite(STDERR, "\nNo role-name gates found at all, but the allowlist is not empty.\n");
    fwrite(
        STDERR,
        "Either every entry below was fixed without updating this script, or the\n"
        . "scanner stopped matching the registration style. Fix whichever it is:\n"
        . "a guard that matches nothing reads exactly like a guard that passed.\n\n"
    );
    foreach (array_keys(GRANDFATHERED) as $k) {
        fwrite(STDERR, "  - {$k}\n");
    }
    exit(2);
}

$new = array_diff(array_keys($found), array_keys(GRANDFATHERED));
$gone = array_diff(array_keys(GRANDFATHERED), array_keys($found));

$exit = 0;

if ($new !== []) {
    fwrite(STDERR, "\nNew route(s) gated on a ROLE NAME rather than a permission slug:\n\n");
    foreach ($new as $key) {
        fwrite(STDERR, sprintf("  - %s\n      at %s\n", $key, implode(', ', $found[$key])));
    }
    fwrite(
        STDERR,
        "\nRbacMiddleware resolves a role gate by NAME, so this route answers 403 on any\n"
        . "deployment whose administrative role is called something else — including to\n"
        . "the administrator it was written for. Gate it on a permission slug instead.\n"
        . "If the slug does not exist yet, it needs a grant migration before the re-gate\n"
        . "lands; scripts/ci-permission-holder-guard.php is what checks that.\n\n"
    );
    $exit = 1;
}

if ($gone !== []) {
    fwrite(STDERR, "\nThese are no longer role-gated and must leave the allowlist:\n\n");
    foreach ($gone as $key) {
        fwrite(STDERR, "  - {$key}\n");
    }
    fwrite(
        STDERR,
        "\nRemove them from GRANDFATHERED in " . basename(__FILE__) . ". A list that keeps\n"
        . "entries after they are fixed is how the next one hides inside it.\n\n"
    );
    $exit = 1;
}

if ($exit !== 0) {
    exit($exit);
}

printf(
    "OK: %d role-name gate(s) across both entry points, all grandfathered (#990 empties this list).\n",
    count($found)
);
foreach ($found as $key => $where) {
    printf("    %-44s %s\n", $key, implode(', ', $where));
}
exit(0);
