<?php

declare(strict_types=1);

/**
 * CI date-display guard (#1068): fail the build when a date is formatted, or
 * rendered raw, outside the one sanctioned path.
 *
 * `ui.hide_dates` promises a tenant that no date or time appears anywhere in the
 * interface. That promise is falsifiable by a single screen, and the screen that
 * leaks is by definition the one nobody checked — so consolidating every date
 * onto `useDateDisplay()` made the promise true once, and this is what keeps it
 * true. Without it the feature decays: the next surface formats a date inline,
 * nothing notices, and a tenant who believes dates are hidden is wrong.
 *
 * Mirrors scripts/ci-tenant-predicate-guard.php: standalone, no HTTP, no
 * database, exits non-zero on any violation. Its escape hatch is the same shape
 * too — a reasoned inline annotation, where the reason is the point.
 *
 * Usage:  php scripts/ci-date-display-guard.php [path ...]
 *         (defaults to the frontend source roots below)
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Whity\Core\Ui\DateDisplayScanner;

$baseDir = dirname(__DIR__);

/**
 * The trees a tenant's reader actually sees.
 *
 * `templates/tauri-desktop` is deliberately absent. It is a SCAFFOLD for an
 * offline desktop app rather than a surface of this product: it mounts no
 * settings context, has no i18n at all (its strings are hard-coded English), and
 * a reader of this repository would be misled by a guard that implied
 * `ui.hide_dates` reached it. Wiring the preference through that template is a
 * separate piece of work, and adding its path here is the first line of it.
 */
$defaultRoots = [
    'web/app',
    'web/components',
    'web/hooks',
    'web/lib',
    'packages/features/src',
    'packages/ui/src',
];

$roots = array_slice($argv, 1);
if ($roots === []) {
    $roots = array_map(static fn (string $r): string => $baseDir . '/' . $r, $defaultRoots);
}

/**
 * A path shown relative to the repository root.
 *
 * Only the LEADING prefix is removed. A plain str_replace strips every
 * occurrence, and this repository's own layout contains a second one — the
 * container mounts the checkout at /app and the tree contains web/app, so
 * `/app/web/app/status/page.tsx` was being reported as `webstatus/page.tsx`.
 */
function relativeTo(string $baseDir, string $path): string
{
    $base = rtrim(str_replace('\\', '/', $baseDir), '/') . '/';
    $path = str_replace('\\', '/', $path);

    return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
}

$scanner = new DateDisplayScanner();
$violations = [];
foreach ($roots as $root) {
    if (!is_dir($root)) {
        fwrite(STDERR, "FAIL: not a directory: {$root}\n");
        exit(2);
    }
    foreach ($scanner->scanDirectory($root) as $violation) {
        $violations[] = $violation;
    }
}

if ($violations !== []) {
    fwrite(STDERR, 'FAIL: ' . count($violations) . " date(s) rendered outside the shared path.\n\n");
    fwrite(STDERR, "A tenant may set `ui.hide_dates` and be told that no date or time appears\n");
    fwrite(STDERR, "anywhere in the interface. That is only true if every surface goes through one\n");
    fwrite(STDERR, "path, so use it:\n\n");
    fwrite(STDERR, "    import { useDateDisplay } from '@amroksaleh/features/datetime'\n");
    fwrite(STDERR, "    const { hidden, date, dateTime, relative, dateColumns } = useDateDisplay()\n\n");
    fwrite(STDERR, "  LOCALE_METHOD / LOCALE_STRING / INTL\n");
    fwrite(STDERR, "      formatting a date yourself. Call `date()` or `dateTime()` instead; they\n");
    fwrite(STDERR, "      already carry the reader's resolved language.\n");
    fwrite(STDERR, "  RAW_FALLBACK\n");
    fwrite(STDERR, "      `dateTime(x) ?? x` prints the wire timestamp the formatter just declined\n");
    fwrite(STDERR, "      to print. Fall back to a string LITERAL, or read `hidden` and drop the\n");
    fwrite(STDERR, "      whole column, row or label.\n");
    fwrite(STDERR, "  RAW_RENDER / RAW_PROP\n");
    fwrite(STDERR, "      a timestamp rendered as it arrived — unformatted, in the browser's locale\n");
    fwrite(STDERR, "      rather than the reader's, and ungated.\n\n");
    fwrite(STDERR, "If the value genuinely is not a record timestamp — a time ZONE name, a\n");
    fwrite(STDERR, "duration, a date the SERVER already reduced for a public page — say so:\n\n");
    fwrite(STDERR, '    // ' . DateDisplayScanner::IGNORE_TAG . " <reason>\n\n");
    fwrite(STDERR, "An annotation with no reason does not suppress anything.\n\n");

    foreach ($violations as $v) {
        fwrite(STDERR, sprintf(
            "  %s:%d  [%s]\n    %s\n\n",
            relativeTo($baseDir, $v['file']),
            $v['line'],
            $v['code'],
            $v['snippet']
        ));
    }

    exit(1);
}

echo 'OK: every date reaches the UI through the shared path in: '
    . implode(', ', array_map(static fn (string $r): string => relativeTo($baseDir, $r), $roots))
    . ".\n";
exit(0);
