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

/**
 * An interpolated placeholder: `{org}`, `{when}`, `{count}`.
 *
 * Deliberately the same shape the renderer substitutes, so this check and the
 * runtime cannot disagree about what counts as one.
 */
const PLACEHOLDER_PATTERN = '/\{[a-zA-Z_][a-zA-Z0-9_]*\}/';
use Whity\Core\i18n\TranslationDomain;
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

/*
 * ─── The other languages ─────────────────────────────────────────────────────
 *
 * Everything above guards the GENERATED half: English is a projection of the
 * code, so the only question worth asking is whether the projection is current.
 * A hand-written language cannot be checked that way — there is nothing to
 * regenerate it from, and its answer to "is it complete?" is nearly always "no",
 * legitimately, because English gains a key the instant a developer writes one
 * and the translation follows in a later PR.
 *
 * So this half checks the things that ARE decidable, and counts the rest:
 *
 *   FAIL — an orphan key. Translated, but no English key of that name exists, so
 *          nothing will ever render it. This is what a rename leaves behind, and
 *          it is the one failure that masquerades as finished work: the file
 *          gets longer, the coverage percentage goes UP, and the screen stays
 *          English. This is also the direction that makes "a key added to one
 *          locale and not another" a build failure rather than a discovery
 *          six months later.
 *   FAIL — an empty translation. Renders as an empty string, not as the English
 *          fallback, so it is strictly worse than leaving the key out.
 *   FAIL — bytes that are not what renderLocale() produces. Same reasoning as
 *          the English catalogue: a translator's editor must not be able to
 *          reorder the file and bury the next real change in noise.
 *   REPORT — missing keys, as a per-domain count. Never a failure; failing here
 *          would mean no English string could be added without a translator in
 *          the same PR, and the practical effect of that rule is that people
 *          stop using t() at all.
 */

$localeFailures = [];
$catalogSource = $report['catalog'];
$localeCodes = $catalog->localeCodes();

foreach ($localeCodes as $code) {
    $localeDirectory = $catalog->localeDirectory($code);

    foreach (scandir($localeDirectory) ?: [] as $entry) {
        $path = $localeDirectory . '/' . $entry;
        if (!is_file($path) || !str_ends_with($entry, '.json')) {
            continue;
        }

        $domain = TranslationCatalog::domainFromFileName($entry);
        if (!TranslationDomain::isValid($domain)) {
            $localeFailures[] = [
                'where' => TranslationCatalog::DIRECTORY . "/{$code}/{$entry}",
                'detail' => "'{$domain}' is not a valid translation domain, so no lookup will ever ask for it.",
            ];
            continue;
        }

        $contents = (string) file_get_contents($path);
        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            $localeFailures[] = [
                'where' => TranslationCatalog::DIRECTORY . "/{$code}/{$entry}",
                'detail' => 'Not valid JSON.',
            ];
            continue;
        }

        $keys = [];
        foreach ((array) ($decoded['keys'] ?? []) as $key => $text) {
            if (is_string($key) && is_string($text)) {
                $keys[$key] = $text;
            }
        }

        $expected = TranslationCatalog::renderLocale($domain, $code, $keys);
        if (str_replace("\r\n", "\n", $contents) !== $expected) {
            $localeFailures[] = [
                'where' => TranslationCatalog::DIRECTORY . "/{$code}/{$entry}",
                'detail' => 'Not in canonical form (sorted keys, the "domain"/"language"/"notice"/"keys" header, '
                    . 'four-space indent, exactly one trailing newline). Reformat it and commit.',
            ];
        }

        foreach ($keys as $key => $text) {
            if (trim($text) === '') {
                $localeFailures[] = [
                    'where' => TranslationCatalog::DIRECTORY . "/{$code}/{$entry}",
                    'detail' => "'{$key}' is empty. An empty translation renders as nothing — it does NOT fall "
                        . 'back to English. Delete the key instead.',
                ];
            }

            // A PLACEHOLDER IS DATA, NOT DECORATION. `{org}` is an organisation's
            // name and `{when}` a date; a translation that drops one renders a
            // sentence with the fact missing, and nothing else notices — the
            // string is present, non-empty, and in the right language. One that
            // INVENTS a placeholder is worse: the token renders literally, so a
            // reader sees `{count}` on screen.
            //
            // Checked here rather than trusted because the failure lands in a
            // language the reviewer of a translation PR often cannot read.
            $english = $catalogSource[$domain][$key] ?? null;
            if (is_string($english)) {
                preg_match_all(PLACEHOLDER_PATTERN, $english, $inEnglish);
                preg_match_all(PLACEHOLDER_PATTERN, $text, $inTranslation);

                $wanted = array_values(array_unique($inEnglish[0]));
                $found = array_values(array_unique($inTranslation[0]));
                sort($wanted);
                sort($found);

                if ($wanted !== $found) {
                    $dropped = array_diff($wanted, $found);
                    $invented = array_diff($found, $wanted);
                    $detail = "'{$key}' does not carry the same placeholders as the English.";

                    if ($dropped !== []) {
                        $detail .= ' Missing: ' . implode(', ', $dropped)
                            . ' — the sentence would render without that value.';
                    }

                    if ($invented !== []) {
                        $detail .= ' Not in the English: ' . implode(', ', $invented)
                            . ' — nothing will replace it, so it renders literally.';
                    }

                    $localeFailures[] = [
                        'where' => TranslationCatalog::DIRECTORY . "/{$code}/{$entry}",
                        'detail' => $detail,
                    ];
                }
            }
        }
    }

    $coverage = TranslationCatalog::coverage($catalogSource, $catalog->readLocale($code));
    foreach ($coverage['domains'] as $domain => $row) {
        foreach ($row['orphans'] as $key) {
            $localeFailures[] = [
                'where' => TranslationCatalog::DIRECTORY . '/' . $code . '/' . TranslationCatalog::fileNameFor($domain),
                'detail' => "'{$key}' is translated but no English key of that name exists. It was renamed or "
                    . 'deleted at the call site; move the translation to the new key, or drop it.',
            ];
        }
    }
}

if ($localeFailures !== []) {
    fwrite(STDERR, "\nFAIL: " . count($localeFailures) . " problem(s) in the hand-written locale catalogues.\n\n");
    foreach ($localeFailures as $failure) {
        fwrite(STDERR, sprintf("  %s\n    %s\n\n", $failure['where'], $failure['detail']));
    }

    exit(1);
}

foreach ($localeCodes as $code) {
    $coverage = TranslationCatalog::coverage($catalogSource, $catalog->readLocale($code));
    printf(
        "OK: %s is %d/%d (%.1f%%), %d key(s) still English. Per domain: %s\n",
        $code,
        $coverage['translated'],
        $coverage['total'],
        $coverage['total'] === 0 ? 0.0 : ($coverage['translated'] / $coverage['total']) * 100,
        $coverage['missing'],
        implode(', ', array_map(
            static fn (string $domain, array $row): string => "{$domain} {$row['translated']}/{$row['total']}",
            array_keys($coverage['domains']),
            array_values($coverage['domains'])
        ))
    );
}

exit(0);
