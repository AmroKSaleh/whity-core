<?php

declare(strict_types=1);

namespace Whity\Core\i18n;

use PDO;
use RuntimeException;
use Whity\Core\Db\DbBool;

/**
 * Puts the extracted English catalogue into the `translations` table — and, far
 * more importantly, never takes anything out of it.
 *
 * WHY THE DATABASE AND NOT A FILE
 * -------------------------------
 * The catalogue file is the source of truth for what English SAYS in code. The
 * database is where translation WORK lives: per-tenant overrides, and the
 * `/admin/translations` console a human uses. A key that never reaches a row is
 * a key no translator can see, so extraction that stops at a file has not
 * actually delivered anything.
 *
 * THE ONE PROPERTY THAT MATTERS
 * -----------------------------
 * This class may only ever write a row that IT wrote. The failure mode is
 * silent and unrecoverable — a translator spends a week filling in Arabic,
 * someone runs the sync, and it is gone with nothing in the diff to notice — so
 * the guarantee is carried by the statements themselves, not by review:
 *
 *   - it contains no DELETE at all. Keys that vanish from the source are
 *     reported as dead and left in place, for a person to remove through the
 *     console if they truly want them gone;
 *   - its one UPDATE carries `tenant_id IS NULL AND source_managed = TRUE` in
 *     its own WHERE clause, so a tenant's row and a human's row are unreachable
 *     from here even if the PHP above it were wrong;
 *   - a row whose text a human has since edited stays edited, in every
 *     language. It is REPORTED as divergent, never corrected.
 *
 * A test asserts all three about this file's own source text.
 *
 * WHY IT UPDATES AT ALL (#1057)
 * -----------------------------
 * It used to insert and nothing else, and that froze every install at whatever
 * it was seeded with. The runtime prefers a stored row over the English
 * fallback compiled into the call site, so a CORRECTION to an existing key
 * never arrived: new keys came in, fixes did not, and the source file went on
 * looking authoritative while the running product said something else. Some of
 * what it went on saying was false — a public verification page naming the
 * platform as the issuer of a document (#1051), a refusal page asserting a
 * confirmation that had not happened.
 *
 * The reason that was tolerated for so long is that nothing could tell a row
 * this class seeded from a row a human wrote. `source_managed` (migration 124)
 * is that fact, and with it the two requirements stop competing: a row still
 * carrying what the catalogue last said is refreshed, and a row somebody has
 * saved in /admin/translations is not, because saving it cleared the flag.
 *
 * Running it twice still changes nothing the second time.
 *
 * Rows it writes are SYSTEM DEFAULTS (`tenant_id IS NULL`). A tenant that wants
 * different wording writes an override through the translations API — a
 * SEPARATE row carrying that tenant's id, which no statement here can reach.
 * Tenant wording was never what insert-only was protecting.
 *
 * ENGLISH ONLY, DELIBERATELY
 * --------------------------
 * The sync seeds {@see TranslationCatalog::SOURCE_LANGUAGE} and nothing else. It
 * does not machine-translate, and it does not copy English into another
 * language's rows — an untranslated key must be VISIBLY untranslated, or the
 * coverage view on /admin/translations would report a language as complete when
 * every string in it is still English.
 */
final class TranslationSync
{
    /**
     * Whether `translations.source_managed` exists, resolved once per instance.
     * Null until asked. See {@see self::hasProvenanceColumn()} for why this is
     * not simply assumed true.
     */
    private ?bool $hasProvenanceColumn = null;

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * Insert every catalogue key that has no row yet, and refresh every row that
     * still carries what the catalogue last said.
     *
     * @param array<string, array<string, string>> $catalog domain => key => text
     * @return array{
     *     language: array{code: string, id: int},
     *     inserted: list<array{domain: string, key: string, text: string}>,
     *     updated: list<array{domain: string, key: string, from: string, to: string}>,
     *     present: int,
     *     divergent: list<array{domain: string, key: string, database: string, source: string}>,
     *     overridden: array{rows: int, tenants: int},
     *     dead: list<array{domain: string, key: string, text: string}>,
     *     unmanaged: array<string, int>,
     *     dryRun: bool
     * }
     */
    public function sync(
        array $catalog,
        string $languageCode = TranslationCatalog::SOURCE_LANGUAGE,
        bool $dryRun = false,
    ): array {
        $languageId = $this->languageId($languageCode);

        // Whether this database can tell a seeded row from a human's yet.
        //
        // IT CANNOT DURING MIGRATION 121, and that is not a hypothetical: 121
        // seeds the catalogues and runs BEFORE 124 adds the column, so on every
        // fresh install this class executes at least once against a table that
        // has no provenance on it. Referencing the column there is not a
        // degraded result but a hard failure — PostgreSQL aborts the entire
        // transaction on the first unknown column and every later statement in
        // the migration dies with it, so `migrate run` cannot complete and the
        // install never finishes.
        //
        // Without the column there is no way to know whose text a row holds, so
        // the only safe behaviour is the pre-#1057 one: insert what is missing,
        // touch nothing that exists, report every difference as divergent. That
        // is what falls out of this flag, because a row read without provenance
        // is read as `managed: false`.
        $provenance = $this->hasProvenanceColumn();
        $existing = $this->systemDefaults($languageId, $provenance);

        $inserted = [];
        $updated = [];
        $divergent = [];
        $present = 0;

        $insert = $this->pdo->prepare(
            // NOT EXISTS rather than ON CONFLICT: the unique index is
            // (language_id, domain, key, tenant_id) and these rows carry a NULL
            // tenant_id, which both PostgreSQL and SQLite treat as distinct from
            // every other NULL — so the constraint would never fire and a replay
            // would duplicate every string. The same reasoning as migration 091.
            //
            // `source_managed = TRUE` is set HERE and nowhere else in the
            // codebase: this statement is the only thing that may claim a row
            // for the catalogue. The column defaults to FALSE precisely so that
            // a row written by any other path stays out of reach.
            $provenance
                ? 'INSERT INTO translations (language_id, domain, key, translation, tenant_id, source_managed, created_at, updated_at)
             SELECT :language_id, :domain, :key, :translation, NULL, TRUE, NOW(), NOW()
             WHERE NOT EXISTS (
                 SELECT 1 FROM translations
                 WHERE language_id = :existing_language_id
                   AND domain = :existing_domain
                   AND key = :existing_key
                   AND tenant_id IS NULL
             )'
                : 'INSERT INTO translations (language_id, domain, key, translation, tenant_id, created_at, updated_at)
             SELECT :language_id, :domain, :key, :translation, NULL, NOW(), NOW()
             WHERE NOT EXISTS (
                 SELECT 1 FROM translations
                 WHERE language_id = :existing_language_id
                   AND domain = :existing_domain
                   AND key = :existing_key
                   AND tenant_id IS NULL
             )'
        );

        // The refresh, and the entire safety argument is in its WHERE clause.
        //
        // `tenant_id IS NULL` keeps a tenant's override unreachable;
        // `source_managed = TRUE` keeps a row a human has saved unreachable. Both
        // predicates are on the MUTATING statement rather than on a read above
        // it, the same defence-in-depth the tenant-scoped writes in
        // {@see TranslationRepository::update()} use: a bug in the PHP that
        // chooses which keys to pass here cannot turn into a lost translation,
        // because the statement itself will match no row.
        //
        // Prepared ONLY when the column exists. PostgreSQL validates a
        // statement at PREPARE time, so preparing this against a pre-124 table
        // would fail — and abort the migration's transaction — before a single
        // row was considered.
        $refresh = $provenance ? $this->pdo->prepare(
            'UPDATE translations
                SET translation = :translation, updated_at = NOW()
              WHERE language_id = :language_id
                AND domain = :domain
                AND key = :key
                AND tenant_id IS NULL
                AND source_managed = TRUE'
        ) : null;

        foreach ($catalog as $domain => $keys) {
            foreach ($keys as $key => $text) {
                $current = $existing[$domain][$key] ?? null;

                if ($current !== null) {
                    $present++;

                    if ($current['text'] === $text) {
                        continue;
                    }

                    // Divergent AND human-authored: the edit outranks the file,
                    // and always did. Reported so a person can decide.
                    if (!$current['managed']) {
                        $divergent[] = [
                            'domain' => $domain,
                            'key' => $key,
                            'database' => $current['text'],
                            'source' => $text,
                        ];
                        continue;
                    }

                    // Divergent because the SOURCE moved. Nobody has touched
                    // this row since the sync wrote it, so the correction is
                    // simply late.
                    if (!$dryRun && $refresh !== null) {
                        $refresh->execute([
                            ':translation' => $text,
                            ':language_id' => $languageId,
                            ':domain' => $domain,
                            ':key' => $key,
                        ]);
                    }

                    $updated[] = [
                        'domain' => $domain,
                        'key' => $key,
                        'from' => $current['text'],
                        'to' => $text,
                    ];
                    continue;
                }

                if (!$dryRun) {
                    $insert->execute([
                        ':language_id' => $languageId,
                        ':domain' => $domain,
                        ':key' => $key,
                        ':translation' => $text,
                        ':existing_language_id' => $languageId,
                        ':existing_domain' => $domain,
                        ':existing_key' => $key,
                    ]);
                }

                $inserted[] = ['domain' => $domain, 'key' => $key, 'text' => $text];
            }
        }

        return [
            'language' => ['code' => $languageCode, 'id' => $languageId],
            'inserted' => $inserted,
            'updated' => $updated,
            'present' => $present,
            'divergent' => $divergent,
            'overridden' => $this->tenantOverrides($languageId, $catalog),
            'dead' => self::deadKeys($catalog, $existing),
            'unmanaged' => self::unmanagedDomains($catalog, $existing),
            'dryRun' => $dryRun,
        ];
    }

    /**
     * How much tenant wording this run stepped around: override rows, for keys
     * this catalogue covers, in this language.
     *
     * WHY A SYNC COUNTS SOMETHING IT NEVER TOUCHES
     * ---------------------------------------------
     * Because "never touches" is the claim, and an operator running this against
     * a customised production install has no other way to check it. Every other
     * number here is about rows that changed; this one is about rows that did
     * not, and it is the only one that can be compared against what the
     * customer knows they customised. Zero on an install with tenant overrides
     * is a bug report; the same number before and after a deploy is the
     * evidence that a refresh left tenant wording alone.
     *
     * It is a COUNT and not a list on purpose: the strings belong to tenants,
     * and a platform-wide seeding command printing their private wording to an
     * operator's terminal would be a disclosure dressed up as a progress report.
     *
     * These rows are unreachable from here by construction — every statement in
     * this class carries `tenant_id IS NULL`, and
     * {@see \Whity\Api\TranslationsApiHandler::writeAccessFor()} will not let a
     * tenant write a system-default row either — so this is a measurement, not a
     * guard. The guard is in the WHERE clauses.
     *
     * @param array<string, array<string, string>> $catalog
     * @return array{rows: int, tenants: int}
     */
    private function tenantOverrides(int $languageId, array $catalog): array
    {
        $statement = $this->pdo->prepare(
            'SELECT domain, key, tenant_id FROM translations
             WHERE language_id = :language_id AND tenant_id IS NOT NULL'
        );
        $statement->execute([':language_id' => $languageId]);

        $rows = 0;
        $tenants = [];

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $domain = (string) $row['domain'];
            $key = (string) $row['key'];

            // Only overrides of keys THIS run considered. A tenant's own
            // invented key, or one from a domain the catalogue does not cover,
            // was never in the path of anything here and counting it would
            // inflate the reassurance.
            if (!isset($catalog[$domain][$key])) {
                continue;
            }

            $rows++;
            $tenants[(string) $row['tenant_id']] = true;
        }

        return ['rows' => $rows, 'tenants' => count($tenants)];
    }

    /**
     * Keys the database still has in a domain the catalogue DOES cover, but
     * which no longer appear in the source.
     *
     * Reported, never deleted. A key legitimately outlives its last call site
     * for the length of a refactor, and the row may carry translations in
     * languages nobody can regenerate. Deleting on the strength of a scan would
     * make a rename indistinguishable from a data loss.
     *
     * @param array<string, array<string, string>> $catalog
     * @param array<string, array<string, array{text: string, managed: bool}>> $existing
     * @return list<array{domain: string, key: string, text: string}>
     */
    private static function deadKeys(array $catalog, array $existing): array
    {
        $dead = [];

        foreach ($existing as $domain => $keys) {
            if (!array_key_exists($domain, $catalog)) {
                continue;
            }
            foreach ($keys as $key => $row) {
                if (!array_key_exists($key, $catalog[$domain])) {
                    $dead[] = ['domain' => $domain, 'key' => $key, 'text' => $row['text']];
                }
            }
        }

        usort($dead, static fn (array $a, array $b): int => [$a['domain'], $a['key']] <=> [$b['domain'], $b['key']]);

        return $dead;
    }

    /**
     * Domains that exist in the database but are not derived from source at all
     * — strings seeded by a plugin, by a backend template, or created by hand in
     * the console.
     *
     * Called out separately from dead keys, because they are not stale: nothing
     * in the frontend source was ever expected to produce them, so an extraction
     * run has no opinion about them.
     *
     * @param array<string, array<string, string>> $catalog
     * @param array<string, array<string, array{text: string, managed: bool}>> $existing
     * @return array<string, int>
     */
    private static function unmanagedDomains(array $catalog, array $existing): array
    {
        $unmanaged = [];

        foreach ($existing as $domain => $keys) {
            if (!array_key_exists($domain, $catalog)) {
                $unmanaged[$domain] = count($keys);
            }
        }

        ksort($unmanaged);

        return $unmanaged;
    }

    /**
     * Every system-default row for a language, as domain => key => {text, managed}.
     *
     * `managed` is read through {@see DbBool} rather than cast, because the two
     * engines this runs on disagree about how a BOOLEAN comes back and a wrong
     * answer here is the difference between refreshing a row and overwriting a
     * translator's work. A row missing the column entirely — which cannot happen
     * after migration 124, but is what a partially-migrated database looks like
     * — reads as FALSE, the hands-off direction.
     *
     * @param bool $provenance Whether `source_managed` exists on this database.
     *                         When it does not, every row reads as unmanaged,
     *                         which is what makes a pre-124 run insert-only.
     * @return array<string, array<string, array{text: string, managed: bool}>>
     */
    private function systemDefaults(int $languageId, bool $provenance): array
    {
        $statement = $this->pdo->prepare(
            $provenance
                ? 'SELECT domain, key, translation, source_managed FROM translations
                   WHERE language_id = :language_id AND tenant_id IS NULL'
                : 'SELECT domain, key, translation FROM translations
                   WHERE language_id = :language_id AND tenant_id IS NULL'
        );
        $statement->execute([':language_id' => $languageId]);

        $rows = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $rows[(string) $row['domain']][(string) $row['key']] = [
                'text' => (string) $row['translation'],
                'managed' => DbBool::of($row['source_managed'] ?? false),
            ];
        }

        return $rows;
    }

    /**
     * Whether `translations.source_managed` exists on this database.
     *
     * ASKED OF THE CATALOGUE, NEVER BY TRYING A QUERY AND CATCHING THE FAILURE.
     * On PostgreSQL a failed statement aborts the whole transaction, so a
     * probe-by-exception inside migration 121's single seeding transaction
     * would not be a probe at all — it would be the thing that kills the
     * install. Both branches below are ordinary reads that succeed either way.
     *
     * The PostgreSQL branch binds `table_schema = current_schema()`, which
     * matters more than it looks: `information_schema.columns` spans EVERY
     * schema in the database, and a test harness that builds throwaway schemas
     * (or any deployment with more than one) will otherwise answer for somebody
     * else's table. Migration 094 carries the unqualified version of this same
     * lookup and mis-answers exactly that way.
     */
    private function hasProvenanceColumn(): bool
    {
        if ($this->hasProvenanceColumn !== null) {
            return $this->hasProvenanceColumn;
        }

        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            $statement = $this->pdo->query(
                "SELECT 1 FROM information_schema.columns
                  WHERE table_schema = current_schema()
                    AND table_name = 'translations'
                    AND column_name = 'source_managed'"
            );

            return $this->hasProvenanceColumn = $statement !== false && $statement->fetchColumn() !== false;
        }

        $statement = $this->pdo->query('PRAGMA table_info(translations)');
        if ($statement === false) {
            return $this->hasProvenanceColumn = false;
        }

        /** @var array<string, mixed> $column */
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $column) {
            if (($column['name'] ?? '') === 'source_managed') {
                return $this->hasProvenanceColumn = true;
            }
        }

        return $this->hasProvenanceColumn = false;
    }

    private function languageId(string $code): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM languages WHERE code = :code');
        $statement->execute([':code' => $code]);
        $id = $statement->fetchColumn();

        if ($id === false) {
            throw new RuntimeException(
                "No language row for '{$code}'. Run the migrations first: the catalogue is seeded "
                . 'against an existing language, never against one this command invents.'
            );
        }

        return (int) $id;
    }
}
