<?php

declare(strict_types=1);

namespace Whity\Core\Inbox;

/**
 * One contributor of items to the caller's inbox (#881).
 *
 * #881's framing, worth keeping because it is the whole reason this interface
 * exists rather than each subsystem shipping its own screen:
 *
 *   "If every plugin owns its own queue and supplies its own source, then a user
 *    with items from several plugins has several inboxes. Someone senior — who
 *    receives things from more than one system — opens the app and has no single
 *    answer to 'what needs me today'. Each list is correct; the union of them
 *    exists nowhere."
 *
 * WHY THIS LANDS WITH #947 ITEM 3 RATHER THAN WAITING FOR #881
 * -----------------------------------------------------------
 * `document_route_recipients` IS an inbox — an open row is an item awaiting
 * somebody. Shipping it as its own endpoint beside the aggregate would be the
 * exact mistake #881 was raised to prevent, and #947 says so: "two inbox
 * surfaces would be the same mistake as two audit trails." So routing's
 * recipients register HERE, as core's first source.
 *
 * WHAT THIS DELIBERATELY DOES NOT DECIDE
 * --------------------------------------
 * #881 names three questions a single-source list does not have to answer, and
 * says they need deciding before an aggregate ships: ordering across
 * heterogeneous sources, per-source failure isolation, and pagination across
 * sources (which is #867 one level up). None of them is answered here, and that
 * is deliberate — this PR would be deciding them by accident.
 *
 * What it provides is the SEAM: a registered, namespaced source with a paginated
 * read and a count, addressed one source at a time
 * (`GET /api/me/inbox?source=…`). When the aggregate lands it reads this same
 * registry and this same interface; the source does not change. That is exactly
 * the "cheap hedge" #881 describes, made real on the server rather than left as
 * a convention each team follows differently.
 *
 * SELF-SCOPED, ALWAYS
 * -------------------
 * Every method takes `(tenantId, profileId)` and must answer only for THAT
 * person in THAT tenant. There is no "list everyone's items" on this interface,
 * because an inbox is by definition the caller's own — the same posture
 * `/api/me/notifications` and `/api/me/sessions` take, and the reason the
 * endpoint needs no RBAC permission.
 *
 * WHY THE NOTIFICATION INBOX IS NOT A SOURCE
 * ------------------------------------------
 * `/api/me/notifications` ({@see \Whity\Api\InboxApiHandler}) is not folded in
 * here, and that is a judgement rather than an omission. A notification is
 * something you READ — subject, body, `read_at`. An inbox item is something
 * that is WAITING FOR YOU TO ACT. #881's aggregate answers "what needs me
 * today", and mixing a read/unread feed into it would answer a different
 * question less well: the count that matters would be diluted by anything that
 * merely happened. Folding it in would also silently change the contract of an
 * endpoint clients already consume.
 */
interface InboxSourceInterface
{
    /**
     * A short human name for the source, shown wherever items are grouped or
     * filtered by origin.
     */
    public function label(): string;

    /**
     * A page of the caller's items, newest first.
     *
     * @param bool $openOnly When true (the ordinary inbox), only items still
     *        awaiting the caller. When false, their history too — a source that
     *        has no notion of a closed item may ignore this and return the same
     *        rows either way.
     *
     * @return list<InboxItem>
     */
    public function list(int $tenantId, int $profileId, bool $openOnly, int $limit, int $offset): array;

    /**
     * How many items the same predicate as {@see list()} matches.
     *
     * Must be the real total rather than the size of the returned page: a
     * post-filtered count is a total the caller cannot reach, which is the
     * defect every list surface in this codebase records having paid for once.
     */
    public function count(int $tenantId, int $profileId, bool $openOnly): int;
}
