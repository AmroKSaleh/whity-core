<?php

declare(strict_types=1);

namespace Whity\Core\Document\Organizer;

/**
 * The columns a document list may be ORDERED by — a closed vocabulary, for the
 * same reason {@see DocumentCriteria}'s is closed.
 *
 * WHY AN ENUM AND NOT A STRING
 * ----------------------------
 * An ORDER BY is the one place in a query where a request-supplied string is
 * routinely interpolated, because there is no way to bind an identifier as a
 * parameter. So the request never supplies one: it names a case, and
 * {@see \Whity\Core\Document\DocumentRepository::orderSql()} maps that case to a
 * LITERAL fragment in a `match` PHPStan proves exhaustive. Adding a sortable
 * column is therefore a static-analysis error until its fragment is written,
 * rather than a silent fallthrough to whatever the default was.
 *
 * WHY THESE THREE AND NOT `id`
 * ----------------------------
 * These are exactly the three columns a browser SHOWS and a person can read
 * back: the title, when it was raised, and which template it came from. `id` is
 * not among them even though it is what the default order uses, because a sort
 * control offering "id" invites somebody to read meaning into a surrogate key —
 * and the meaning they would read (chronology) is already `created_at`, said
 * honestly.
 *
 * `origin_ou_id` is likewise absent: sorting by it would order documents by an
 * integer while the column displays a NAME resolved on the client from a
 * separate, separately-permissioned request (`ous:read`, which the plain user
 * role does not hold — migration 101). A caller who cannot read unit names would
 * get rows ordered by something invisible to them, which is a sort that cannot
 * be checked by the person looking at it.
 */
enum DocumentSortField: string
{
    case Title = 'title';
    case CreatedAt = 'created_at';
    /**
     * The SNAPSHOT taken at issue time, not a join. A document whose template
     * was renamed or deleted keeps the name it was issued under (migration 108
     * makes the foreign key ON DELETE SET NULL on purpose), so ordering by this
     * column groups documents by what they SAY they came from — which is what
     * the column displays.
     */
    case TemplateName = 'template_name';
}
