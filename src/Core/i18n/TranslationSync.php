<?php

declare(strict_types=1);

namespace Whity\Core\i18n;

use PDO;
use RuntimeException;

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
 * This class INSERTS. It contains no UPDATE and no DELETE, and a test asserts
 * that about its own source text, because the failure mode here is silent and
 * unrecoverable: a translator spends a week filling in Arabic, someone runs the
 * sync, and it is gone with nothing in the diff to notice. Structural
 * impossibility is the only guarantee worth having, so:
 *
 *   - existing rows are never written to, in any language, in any tenant scope;
 *   - a row whose English text a human has since edited stays edited (it is
 *     REPORTED as divergent, never corrected);
 *   - keys that vanish from the source are reported as dead and left in place,
 *     for a person to delete through the console if they truly want them gone.
 *
 * Running it twice therefore changes nothing the second time.
 *
 * Rows it writes are SYSTEM DEFAULTS (`tenant_id IS NULL`). A tenant that wants
 * different wording writes an override through the translations API; nothing in
 * an extraction run belongs to a tenant.
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
     * Insert every catalogue key that has no row yet.
     *
     * @param array<string, array<string, string>> $catalog domain => key => English text
     * @return array{
     *     language: array{code: string, id: int},
     *     inserted: list<array{domain: string, key: string, text: string}>,
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
        $divergent = [];
        $present = 0;

        $insert = $this->pdo->prepare(
            // NOT EXISTS rather than ON CONFLICT: the unique index is
            // (language_id, domain, key, tenant_id) and these rows carry a NULL
            // tenant_id, which both PostgreSQL and SQLite treat as distinct from
            // every other NULL — so the constraint would never fire and a replay
            // would duplicate every string. The same reasoning as migration 091.
            'INSERT INTO translations (language_id, domain, key, translation, tenant_id, created_at, updated_at)
             SELECT :language_id, :domain, :key, :translation, NULL, NOW(), NOW()
             WHERE NOT EXISTS (
                 SELECT 1 FROM translations
                 WHERE language_id = :existing_language_id
                   AND domain = :existing_domain
                   AND key = :existing_key
                   AND tenant_id IS NULL
             )'
        );

        foreach ($catalog as $domain => $keys) {
            foreach ($keys as $key => $text) {
                $current = $existing[$domain][$key] ?? null;

                if ($current !== null) {
                    $present++;
                    if ($current !== $text) {
                        $divergent[] = [
                            'domain' => $domain,
                            'key' => $key,
                            'database' => $current,
                            'source' => $text,
                        ];
                    }
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
     * @param array<string, array<string, string>> $existing
     * @return list<array{domain: string, key: string, text: string}>
     */
    private static function deadKeys(array $catalog, array $existing): array
    {
        $dead = [];

        foreach ($existing as $domain => $keys) {
            if (!array_key_exists($domain, $catalog)) {
                continue;
            }
            foreach ($keys as $key => $text) {
                if (!array_key_exists($key, $catalog[$domain])) {
                    $dead[] = ['domain' => $domain, 'key' => $key, 'text' => $text];
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
     * @param array<string, array<string, string>> $existing
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
     * Every system-default row for a language, as domain => key => text.
     *
     * @return array<string, array<string, string>>
     */
    private function systemDefaults(int $languageId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT domain, key, translation FROM translations
             WHERE language_id = :language_id AND tenant_id IS NULL'
        );
        $statement->execute([':language_id' => $languageId]);

        $rows = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $rows[(string) $row['domain']][(string) $row['key']] = (string) $row['translation'];
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
