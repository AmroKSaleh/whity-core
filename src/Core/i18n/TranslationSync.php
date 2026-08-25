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
        $existing = $this->systemDefaults($languageId);

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
            'INSERT INTO translations (language_id, domain, key, translation, tenant_id, source_managed, created_at, updated_at)
             SELECT :language_id, :domain, :key, :translation, NULL, TRUE, NOW(), NOW()
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
        $refresh = $this->pdo->prepare(
            'UPDATE translations
                SET translation = :translation, updated_at = NOW()
              WHERE language_id = :language_id
                AND domain = :domain
                AND key = :key
                AND tenant_id IS NULL
                AND source_managed = TRUE'
        );

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
                    if (!$dryRun) {
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
            'dead' => self::deadKeys($catalog, $existing),
            'unmanaged' => self::unmanagedDomains($catalog, $existing),
            'dryRun' => $dryRun,
        ];
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
     * @return array<string, array<string, array{text: string, managed: bool}>>
     */
    private function systemDefaults(int $languageId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT domain, key, translation, source_managed FROM translations
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
