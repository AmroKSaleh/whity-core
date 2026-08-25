<?php

declare(strict_types=1);

namespace Whity\Cli\Commands;

use Throwable;
use Whity\Core\i18n\TranslationCatalog;
use Whity\Core\i18n\TranslationKeyExtractor;
use Whity\Core\i18n\TranslationSync;
use Whity\Database\Database;

/**
 * `i18n:extract` and `i18n:sync` — the two halves of turning a screen's
 * hardcoded English into rows a translator can work on.
 *
 *   whity-cli i18n:extract            # source → database/i18n/<domain>.json
 *   whity-cli i18n:extract --check    # verify without writing (what CI runs)
 *   whity-cli i18n:sync               # catalogue → the translations table
 *   whity-cli i18n:sync --dry-run     # report what it would insert
 *   whity-cli i18n:sync --language=ar # database/i18n/ar/ → the same table
 *   whity-cli i18n:sync --all         # English and every committed locale
 *   whity-cli i18n:coverage           # per-domain translated/missing, no database
 *
 * The order matters, and so does the direction. `extract` reads code and writes
 * a file; `sync` reads that file and writes rows. Nothing ever flows back.
 *
 * `sync` INSERTS new keys and REFRESHES rows that still say what the file last
 * said — that second half is #1057, and without it a correction to an existing
 * string never reached an install that had already been seeded, because the
 * runtime prefers a stored row over the English in the call site. What it will
 * not touch is human work: an English string edited in the console, or a
 * finished Arabic translation, is reported as divergent and left, because saving
 * a string in /admin/translations clears the row's `source_managed` flag and
 * `sync`'s UPDATE requires it. Run `--dry-run` first to read every sentence it
 * would change.
 *
 * `--language=` DOES NOT MACHINE-TRANSLATE, and it is worth being explicit about
 * that, because a flag named for a language on a command that seeds strings
 * reads like one that might. It seeds a file a person wrote and committed
 * (`database/i18n/ar/documents.json`), for exactly the same reason English is
 * seeded from a file: strings that only exist in a database cannot be reviewed
 * in a diff, cannot ship in the image, and do not survive the database being
 * rebuilt.
 *
 * WHY NOT A MIGRATION. Migration 091 seeded the first converted screen, which
 * was right for one screen and is wrong for the next forty: every agent
 * converting an area would add a numbered file, collide with every other on the
 * next number, and invent its own seeding shape. `i18n:sync` is one seeding
 * route for all of them, and it can also be run against an already-deployed
 * database, which a migration that has already run cannot.
 */
final class I18nCommand implements NamedSubcommand
{
    /**
     * @param string|null $baseDir Repository root; defaults to this checkout.
     *                             Injectable so the refusal-to-wipe guard in
     *                             {@see self::extract()} can be tested against a
     *                             source tree that genuinely has nothing in it.
     */
    public function __construct(
        private readonly ?string $baseDir = null,
    ) {
    }

    /**
     * @param list<string> $argv Arguments AFTER the command name.
     */
    public function execute(array $argv, string $commandName): int
    {
        $baseDir = $this->baseDir ?? dirname(__DIR__, 3);

        return match ($commandName) {
            'i18n:extract' => $this->extract($baseDir, in_array('--check', $argv, true)),
            'i18n:sync' => $this->sync($baseDir, $argv),
            'i18n:coverage' => $this->coverage($baseDir, $argv),
            default => $this->usage($commandName),
        };
    }

    /** Rebuild the catalogue from the source that renders it. */
    private function extract(string $baseDir, bool $checkOnly): int
    {
        $extractor = new TranslationKeyExtractor($baseDir);
        $report = $extractor->extract();

        if ($report['problems'] !== []) {
            fwrite(STDERR, 'FAIL: ' . count($report['problems']) . " translation-key problem(s) found.\n\n");
            foreach ($report['problems'] as $problem) {
                fwrite(STDERR, sprintf(
                    "  %s:%d  [%s]\n    %s\n\n",
                    $problem['file'],
                    $problem['line'],
                    $problem['code'],
                    $problem['message']
                ));
            }

            return 1;
        }

        $catalog = new TranslationCatalog($baseDir);

        if ($checkOnly) {
            $differences = TranslationCatalog::diff($report['catalog'], $catalog->read());
            if ($differences !== []) {
                fwrite(STDERR, 'FAIL: the committed catalogue has drifted from the source in '
                    . count($differences) . " place(s).\n");
                fwrite(STDERR, "Run `php bin/whity-cli i18n:extract` and commit "
                    . TranslationCatalog::DIRECTORY . "/.\n\n");
                foreach ($differences as $difference) {
                    fwrite(STDERR, sprintf(
                        "  %s / %s  [%s]\n    %s\n\n",
                        $difference['domain'],
                        $difference['key'],
                        $difference['kind'],
                        $difference['detail']
                    ));
                }

                return 1;
            }

            printf(
                "OK: %d key(s) across %d domain(s) in %d file(s); the catalogue matches the source.\n",
                self::keyCount($report['catalog']),
                count($report['catalog']),
                $report['files']
            );

            return 0;
        }

        // `write()` mirrors: a domain that vanished from source loses its file.
        // Correct — and catastrophic if the scan found nothing because the
        // source is not there to scan. `web/` is absent from the release image
        // by design, so running this inside a container would otherwise delete
        // the entire catalogue and report success.
        if ($report['catalog'] === [] && $catalog->read() !== []) {
            fwrite(STDERR, "FAIL: no t() calls were found anywhere, but " . TranslationCatalog::DIRECTORY
                . " is not empty.\n");
            fwrite(STDERR, "Refusing to delete the catalogue on the strength of an empty scan — this is\n");
            fwrite(STDERR, "what it looks like to run from the wrong directory, or inside an image that\n");
            fwrite(STDERR, "ships no frontend source. Run it from a full checkout.\n");

            return 2;
        }

        $result = $catalog->write($report['catalog']);

        printf(
            "Extracted %d key(s) across %d domain(s) from %d file(s).\n",
            self::keyCount($report['catalog']),
            count($report['catalog']),
            $report['files']
        );
        foreach ($result['written'] as $file) {
            echo "  written    " . TranslationCatalog::DIRECTORY . "/{$file}\n";
        }
        foreach ($result['deleted'] as $file) {
            echo "  deleted    " . TranslationCatalog::DIRECTORY . "/{$file}  (no key in this domain is reachable from source any more)\n";
        }
        if ($result['unchanged'] !== []) {
            printf("  unchanged  %d domain file(s)\n", count($result['unchanged']));
        }
        echo "\nNext: `php bin/whity-cli i18n:sync` to seed the new keys into the translations table.\n";

        return 0;
    }

    /**
     * Seed the catalogue's English into the translations table, inserting only
     * what is missing.
     *
     * @param list<string> $argv
     */
    private function sync(string $baseDir, array $argv): int
    {
        $dryRun = in_array('--dry-run', $argv, true);
        $catalog = new TranslationCatalog($baseDir);

        try {
            $languages = self::requestedLanguages($catalog, $argv);
        } catch (Throwable $e) {
            fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");

            return 2;
        }

        /** @var array<string, array<string, array<string, string>>> $bundles */
        $bundles = [];
        foreach ($languages as $code) {
            $bundles[$code] = $code === TranslationCatalog::SOURCE_LANGUAGE
                ? $catalog->read()
                : $catalog->readLocale($code);

            if ($bundles[$code] === []) {
                if ($code === TranslationCatalog::SOURCE_LANGUAGE) {
                    fwrite(STDERR, 'FAIL: no catalogue found in ' . TranslationCatalog::DIRECTORY
                        . ". Run `php bin/whity-cli i18n:extract` first.\n");

                    return 2;
                }

                fwrite(STDERR, "FAIL: no catalogue found in " . TranslationCatalog::DIRECTORY . "/{$code}. "
                    . "A language is seeded from committed files, never invented here.\n");

                return 2;
            }
        }

        try {
            $pdo = Database::connect()->getPdo();
        } catch (Throwable $e) {
            fwrite(STDERR, 'FAIL: could not connect to the database: ' . $e->getMessage() . "\n");

            return 2;
        }

        $sync = new TranslationSync($pdo);

        foreach ($bundles as $code => $bundle) {
            try {
                $report = $sync->sync($bundle, $code, $dryRun);
            } catch (Throwable $e) {
                fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");

                return 1;
            }

            self::printSyncReport($report);
        }

        echo "\nEvery language here came from a committed file. English is generated from the call\n"
            . "sites; the rest are written by hand in " . TranslationCatalog::DIRECTORY . "/<code>/ and\n"
            . "reviewed in a diff. Per-tenant wording is still an override, made at /admin/translations.\n";

        return 0;
    }

    /**
     * Which languages a `sync` invocation covers.
     *
     * Default is English alone, which keeps every existing invocation and every
     * runbook that calls it doing exactly what it did before.
     *
     * @param list<string> $argv
     * @return list<string>
     */
    private static function requestedLanguages(TranslationCatalog $catalog, array $argv): array
    {
        if (in_array('--all', $argv, true)) {
            return array_values(array_unique([TranslationCatalog::SOURCE_LANGUAGE, ...$catalog->localeCodes()]));
        }

        foreach ($argv as $argument) {
            if (str_starts_with($argument, '--language=')) {
                $code = substr($argument, strlen('--language='));
                if ($code === '') {
                    throw new \RuntimeException('--language= needs a code, e.g. --language=ar');
                }

                return [$code];
            }
        }

        return [TranslationCatalog::SOURCE_LANGUAGE];
    }

    /**
     * @param array{
     *     language: array{code: string, id: int},
     *     inserted: list<array{domain: string, key: string, text: string}>,
     *     updated: list<array{domain: string, key: string, from: string, to: string}>,
     *     present: int,
     *     divergent: list<array{domain: string, key: string, database: string, source: string}>,
     *     overridden: array{rows: int, tenants: int},
     *     dead: list<array{domain: string, key: string, text: string}>,
     *     unmanaged: array<string, int>,
     *     dryRun: bool
     * } $report
     */
    private static function printSyncReport(array $report): void
    {
        $code = $report['language']['code'];

        // THE HEADLINE IS THREE NUMBERS, AND THE THIRD IS THE POINT.
        //
        // This command now both writes and declines to write, and a line saying
        // "synced" leaves an operator exactly as uncertain as they were before
        // running it. So the summary separates what arrived (inserted,
        // refreshed) from what was deliberately left alone (kept), because on a
        // customised install the second is the number they are actually worried
        // about — and it is the one that should be non-zero.
        $kept = count($report['divergent']) + $report['overridden']['rows'];

        printf(
            "\n%s [%s]: %d inserted, %d refreshed, %d left alone (%d already matched the file).\n",
            $report['dryRun'] ? 'DRY RUN' : 'Synced',
            $code,
            count($report['inserted']),
            count($report['updated']),
            $kept,
            $report['present'] - count($report['updated']) - count($report['divergent'])
        );

        foreach (self::groupByDomain($report['inserted']) as $domain => $keys) {
            printf("  + %-24s %d key(s)\n", $domain, count($keys));
        }

        // Printed key by key with both texts, not summarised. A refresh is the
        // one thing this command does that CHANGES what a user already reads, so
        // an operator running --dry-run before a deploy has to be able to see
        // every sentence it would change, not a count of them.
        if ($report['updated'] !== []) {
            printf(
                "\n%d key(s) whose source text changed and whose row nobody had edited — %s to match\n"
                . "the committed file. This is how a correction reaches an install that was already seeded:\n",
                count($report['updated']),
                $report['dryRun'] ? 'would be refreshed' : 'refreshed'
            );
            foreach ($report['updated'] as $row) {
                printf("  ~ %s / %s\n      was: %s\n      now: %s\n", $row['domain'], $row['key'], $row['from'], $row['to']);
            }
        }

        if ($report['divergent'] !== []) {
            printf(
                "\n%d system-default key(s) whose text in the database differs from the committed file.\n"
                . "LEFT AS THEY ARE — somebody saved them in /admin/translations, which cleared the row's\n"
                . "`source_managed` flag, and that edit outranks a file. Delete the row in the console if\n"
                . "you want the file's wording back:\n",
                count($report['divergent'])
            );
            foreach ($report['divergent'] as $row) {
                printf("  ~ %s / %s\n      database: %s\n      file:     %s\n", $row['domain'], $row['key'], $row['database'], $row['source']);
            }
        }

        // Counted, never listed — the wording belongs to the tenants. See
        // TranslationSync::tenantOverrides().
        if ($report['overridden']['rows'] > 0) {
            printf(
                "\n%d tenant override row(s) across %d tenant(s) shadow keys in this catalogue, and were\n"
                . "NOT VISITED. A tenant's wording is a separate row carrying its tenant_id; every statement\n"
                . "in the sync is scoped to `tenant_id IS NULL`, so a refresh cannot reach one. This number\n"
                . "staying the same across a deploy is what proves that:\n",
                $report['overridden']['rows'],
                $report['overridden']['tenants']
            );
        }

        if ($report['dead'] !== []) {
            printf(
                "\n%d key(s) in the database that the committed catalogue no longer has. NOT DELETED — a key\n"
                . "outlives its last call site during a refactor, and the row may hold translations no\n"
                . "scan can regenerate. Remove them from /admin/translations if they are truly gone:\n",
                count($report['dead'])
            );
            foreach ($report['dead'] as $row) {
                printf("  - %s / %s\n", $row['domain'], $row['key']);
            }
        }

        if ($report['unmanaged'] !== []) {
            echo "\nDomains in the database this catalogue does not cover (plugins, email templates,\n"
                . "keys added by hand). This command has no opinion about them:\n";
            foreach ($report['unmanaged'] as $domain => $count) {
                printf("  · %-24s %d key(s)\n", $domain, $count);
            }
        }
    }

    /**
     * Per-domain translated/missing for every committed language.
     *
     * Reads files and nothing else, so it runs in CI, in a container with no
     * database, and on a laptop with the stack down. That matters more than it
     * sounds: the reason six domains had no Arabic for as long as they did is
     * that the gap was not a number anybody could see. "Translate everything" has
     * no finish line; `documents 0/508` has one.
     *
     * @param list<string> $argv
     */
    private function coverage(string $baseDir, array $argv): int
    {
        $catalog = new TranslationCatalog($baseDir);
        $source = $catalog->read();

        if ($source === []) {
            fwrite(STDERR, 'FAIL: no catalogue found in ' . TranslationCatalog::DIRECTORY
                . ". Run `php bin/whity-cli i18n:extract` first.\n");

            return 2;
        }

        $codes = $catalog->localeCodes();
        if ($codes === []) {
            printf(
                "%d English key(s) across %d domain(s), and no other language is committed.\n",
                self::keyCount($source),
                count($source)
            );

            return 0;
        }

        $failOnOrphans = in_array('--strict', $argv, true);
        $orphansSeen = 0;

        foreach ($codes as $code) {
            $coverage = TranslationCatalog::coverage($source, $catalog->readLocale($code));
            $orphansSeen += $coverage['orphans'];

            printf("\n%s — %d/%d key(s) translated (%s), %d missing\n",
                $code,
                $coverage['translated'],
                $coverage['total'],
                self::percentage($coverage['translated'], $coverage['total']),
                $coverage['missing']
            );
            printf("  %-24s %8s %10s %8s\n", 'domain', 'total', 'translated', 'missing');
            foreach ($coverage['domains'] as $domain => $row) {
                printf(
                    "  %-24s %8d %10d %8d   %s\n",
                    $domain,
                    $row['total'],
                    $row['translated'],
                    $row['missing'],
                    self::percentage($row['translated'], $row['total'])
                );
            }

            foreach ($coverage['domains'] as $domain => $row) {
                if ($row['orphans'] === []) {
                    continue;
                }
                printf(
                    "\n  %d orphan key(s) in %s/%s/%s — translated, but no English key of that name\n"
                    . "  exists any more, so nothing will ever render them:\n",
                    count($row['orphans']),
                    TranslationCatalog::DIRECTORY,
                    $code,
                    TranslationCatalog::fileNameFor($domain)
                );
                foreach ($row['orphans'] as $key) {
                    echo "    ! {$key}\n";
                }
            }
        }

        if ($failOnOrphans && $orphansSeen > 0) {
            fwrite(STDERR, "\nFAIL: {$orphansSeen} orphan key(s). Rename or delete them.\n");

            return 1;
        }

        return 0;
    }

    private static function percentage(int $part, int $whole): string
    {
        if ($whole === 0) {
            return '—';
        }

        return sprintf('%5.1f%%', ($part / $whole) * 100);
    }

    private function usage(string $commandName): int
    {
        fwrite(STDERR, "Unknown i18n command: {$commandName}\n");
        fwrite(STDERR, "Usage:\n");
        fwrite(STDERR, "  whity-cli i18n:extract [--check]\n");
        fwrite(STDERR, "  whity-cli i18n:sync [--dry-run] [--language=<code> | --all]\n");
        fwrite(STDERR, "  whity-cli i18n:coverage [--strict]\n");

        return 2;
    }

    /** @param array<string, array<string, string>> $catalog */
    private static function keyCount(array $catalog): int
    {
        return array_sum(array_map('count', $catalog));
    }

    /**
     * @param list<array{domain: string, key: string, text: string}> $rows
     * @return array<string, list<string>>
     */
    private static function groupByDomain(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['domain']][] = $row['key'];
        }
        ksort($grouped);

        return $grouped;
    }
}
