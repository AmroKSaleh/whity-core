<?php

declare(strict_types=1);

/**
 * i18n catalogue drift guard — fail CI when the committed English catalogue no
 * longer matches the `t()` calls that produce it.
 *
 * THE REGRESSION THIS PREVENTS
 * ----------------------------
 * A screen is converted, `t('settings.title', 'Settings')` is written, and
 * nobody regenerates. The key is now referenced by code that no catalogue
 * contains, so `whity-cli i18n:sync` never seeds it, so it never reaches the
 * translations table, so it never appears in /admin/translations — and a
 * translator finishes the language with that string still English, believing it
 * done. Nothing anywhere errors. The screen renders its fallback and looks fine
 * in the only language the author speaks.
 *
 * That is the whole failure mode: an extraction pipeline whose output is not
 * checked degrades silently and looks successful. So the catalogue is compared
 * byte-for-byte with a fresh extraction, exactly as public/openapi.json is
 * compared with a fresh generation from the live router.
 *
 * WHAT IT REFUSES TO GUESS
 * ------------------------
 * A key built at runtime — `t(entry.key)`, ``t(`status.${row.state}`)`` — is not
 * readable by any scanner. This guard does not skip those quietly: it fails on
 * them until the file declares the keys they can reach, or records a reason why
 * they cannot be enumerated. See \Whity\Core\i18n\TranslationKeyExtractor.
 *
 * DEAD KEYS ARE NOT THIS GUARD'S BUSINESS. A key that has left the source also
 * leaves the catalogue on the next regeneration, so it cannot linger here. But a
 * ROW in the translations table outlives its call site on purpose — a refactor
 * removes a screen for a week, and the row still holds the Arabic somebody
 * wrote. `whity-cli i18n:sync` reports those and deletes nothing, and CI has no
 * database to ask anyway.
 *
 * Mirrors scripts/ci-tenant-predicate-guard.php: standalone, no HTTP, no
 * database, exits non-zero on any violation.
 *
 * Usage:  php scripts/ci-i18n-catalog-drift.php [sourceRoot ...]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Whity\Core\i18n\TranslationCatalog;
use Whity\Core\i18n\TranslationKeyExtractor;

$baseDir = dirname(__DIR__);
$roots = array_slice($argv, 1);

foreach ($roots as $root) {
    if (!is_dir($baseDir . '/' . $root)) {
        fwrite(STDERR, "FAIL: not a source root: {$root}\n");
        exit(2);
    }
}

$extractor = new TranslationKeyExtractor($baseDir);
$report = $extractor->extract($roots === [] ? null : $roots);

if ($report['problems'] !== []) {
    fwrite(STDERR, 'FAIL: ' . count($report['problems']) . " translation-key problem(s) found.\n\n");
    fwrite(STDERR, "Every user-facing string must be readable from the source: the English catalogue is\n");
    fwrite(STDERR, "derived from the second argument of each t() call, so a key whose text or domain a\n");
    fwrite(STDERR, "scanner cannot see is a key that never reaches a translator. Fix it in whichever way\n");
    fwrite(STDERR, "is TRUE:\n\n");
    fwrite(STDERR, "  - the key is a literal and the English text was omitted → pass it as the second\n");
    fwrite(STDERR, "    argument: t('login.submit', 'Sign in')\n");
    fwrite(STDERR, "  - the key is computed from a table of known values → declare them:\n");
    fwrite(STDERR, '        ' . TranslationKeyExtractor::KEYS_TAG . " <domain>\n");
    fwrite(STDERR, "          some.key = The English text\n");
    fwrite(STDERR, "  - the keys genuinely cannot be enumerated in code (they are tenant data, or come\n");
    fwrite(STDERR, "    from a plugin) → record why:\n");
    fwrite(STDERR, '        // ' . TranslationKeyExtractor::DYNAMIC_IGNORE_TAG . " <reason>\n\n");

    foreach ($report['problems'] as $problem) {
        fwrite(STDERR, sprintf(
            "  %s:%d  [%s]\n    %s\n\n",
            $problem['file'],
            $problem['line'],
            $problem['code'],
            $problem['message']
        ));
    }

    exit(1);
}

$catalog = new TranslationCatalog($baseDir);
$differences = TranslationCatalog::diff($report['catalog'], $catalog->read());

if ($differences !== []) {
    fwrite(STDERR, 'FAIL: ' . count($differences) . " catalogue difference(s) between the source and "
        . TranslationCatalog::DIRECTORY . "/.\n\n");
    fwrite(STDERR, "Run `php bin/whity-cli i18n:extract` and commit the result. The catalogue is a\n");
    fwrite(STDERR, "projection of the code — it is never edited by hand, and English copy is changed at\n");
    fwrite(STDERR, "the call site.\n\n");

    foreach ($differences as $difference) {
        fwrite(STDERR, sprintf(
            "  %s / %s  [%s]\n    %s\n\n",
            $difference['domain'],
            $difference['key'],
            $difference['kind'],
            $difference['detail']
        ));
    }

    exit(1);
}

printf(
    "OK: %d translation key(s) across %d domain(s) in %d scanned file(s); the committed catalogue matches the source.\n",
    array_sum(array_map('count', $report['catalog'])),
    count($report['catalog']),
    $report['files']
);
exit(0);
