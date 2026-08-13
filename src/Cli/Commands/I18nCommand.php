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
 *
 * The order matters, and so does the direction. `extract` reads code and writes
 * a file; `sync` reads that file and writes rows. Nothing ever flows back: the
 * database holds human work — an edited English string, a finished Arabic
 * translation — and no command here overwrites it.
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
        $catalog = (new TranslationCatalog($baseDir))->read();

        if ($catalog === []) {
            fwrite(STDERR, "FAIL: no catalogue found in " . TranslationCatalog::DIRECTORY
                . ". Run `php bin/whity-cli i18n:extract` first.\n");

            return 2;
        }

        try {
            $pdo = Database::connect()->getPdo();
        } catch (Throwable $e) {
            fwrite(STDERR, 'FAIL: could not connect to the database: ' . $e->getMessage() . "\n");

            return 2;
        }

        try {
            $report = (new TranslationSync($pdo))->sync($catalog, TranslationCatalog::SOURCE_LANGUAGE, $dryRun);
        } catch (Throwable $e) {
            fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");

            return 1;
        }

        $verb = $dryRun ? 'would insert' : 'inserted';
        printf(
            "%s: %d key(s) %s, %d already present (untouched).\n",
            $dryRun ? 'DRY RUN' : 'Synced ' . $report['language']['code'],
            count($report['inserted']),
            $verb,
            $report['present']
        );

        foreach (self::groupByDomain($report['inserted']) as $domain => $keys) {
            printf("  + %-24s %d key(s)\n", $domain, count($keys));
            foreach ($keys as $key) {
                echo "      {$key}\n";
            }
        }

        if ($report['divergent'] !== []) {
            printf(
                "\n%d key(s) whose English in the database differs from the source string. LEFT AS THEY ARE —\n"
                . "someone edited them in the console, and that edit outranks a scan:\n",
                count($report['divergent'])
            );
            foreach ($report['divergent'] as $row) {
                printf("  ~ %s / %s\n      database: %s\n      source:   %s\n", $row['domain'], $row['key'], $row['database'], $row['source']);
            }
        }

        if ($report['dead'] !== []) {
            printf(
                "\n%d key(s) in the database that no source file references any more. NOT DELETED — a key\n"
                . "outlives its last call site during a refactor, and the row may hold translations no\n"
                . "scan can regenerate. Remove them from /admin/translations if they are truly gone:\n",
                count($report['dead'])
            );
            foreach ($report['dead'] as $row) {
                printf("  - %s / %s\n", $row['domain'], $row['key']);
            }
        }

        if ($report['unmanaged'] !== []) {
            echo "\nDomains in the database that are not derived from frontend source (plugins, email\n"
                . "templates, keys added by hand). This command has no opinion about them:\n";
            foreach ($report['unmanaged'] as $domain => $count) {
                printf("  · %-24s %d key(s)\n", $domain, $count);
            }
        }

        echo "\nOnly " . TranslationCatalog::SOURCE_LANGUAGE . " is seeded, by design: the English text comes from\n"
            . "the call site, every other language is human work. Fill the rest in at /admin/translations.\n";

        return 0;
    }

    private function usage(string $commandName): int
    {
        fwrite(STDERR, "Unknown i18n command: {$commandName}\n");
        fwrite(STDERR, "Usage:\n");
        fwrite(STDERR, "  whity-cli i18n:extract [--check]\n");
        fwrite(STDERR, "  whity-cli i18n:sync [--dry-run]\n");

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
