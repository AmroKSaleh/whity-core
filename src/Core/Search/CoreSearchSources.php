<?php

declare(strict_types=1);

namespace Whity\Core\Search;

use PDO;

/**
 * The record types core searches (WHIT-783): issued documents, forms, people
 * and organisational units. Each applies the same visibility as its list route.
 *
 * The queries are written here rather than borrowed from each list route's
 * repository, deliberately: those repositories match with `LOWER(...) LIKE`,
 * which folds nothing in Arabic, and the document repository is being reworked
 * by the editor's M4c. The visibility rules are restated, not reinvented: the
 * document rule mirrors {@see \Whity\Core\Document\DocumentRepository}'s
 * VISIBLE_TO_CALLER, and its real-engine test pins that the two agree.
 */
final class CoreSearchSources
{
    /** @return list<SearchSource> */
    public static function all(PDO $db): array
    {
        return [
            new DocumentsSearchSource($db),
            new FormsSearchSource($db),
            new PeopleSearchSource($db),
            new OusSearchSource($db),
        ];
    }

    /**
     * Fetch every row of a prepared statement bound with $params and the integer $limit.
     *
     * @param array<string, int|string> $params
     * @return list<array<string, mixed>>
     */
    public static function fetch(PDO $db, string $sql, array $params, int $limit): array
    {
        $stmt = $db->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
