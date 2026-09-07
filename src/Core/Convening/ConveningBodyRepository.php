<?php

declare(strict_types=1);

namespace Whity\Core\Convening;

use PDO;
use Whity\Core\Db\DbBool;

/**
 * The standing bodies, and who sits on them.
 *
 * BODIES AND MEMBERSHIP LIVE IN ONE REPOSITORY on purpose. A body with no seats
 * is not a half-built record, it is a body nobody has appointed yet — but every
 * question anybody asks of a body ("who chairs it?", "who do we invite?", "may
 * this decision be carried to a route, and by whom?") is a question about both
 * tables at once. Splitting them would put a join in every caller and give two
 * classes one invariant to keep.
 *
 * MEMBERSHIP IS TIME-BOUNDED, NEVER DELETED
 * -----------------------------------------
 * {@see removeMember()} stamps `left_at`; it does not delete. A decision taken in
 * March was taken by the body as it was constituted in March, and a removed row
 * makes that unreconstructible — which matters most in exactly the case somebody
 * will care about, when a decision is questioned a year later. The partial unique
 * index (migration 130) is on CURRENT seats only, so a person can leave and
 * rejoin without either row being edited.
 *
 * TENANT PREDICATE ON EVERY STATEMENT. Every SELECT, UPDATE and DELETE below
 * binds `tenant_id` directly rather than relying on a join through
 * `convening_bodies` — the guard polices the statement it can see, and a member
 * read that trusted an unseen join is exactly the shape that has leaked before.
 */
final class ConveningBodyRepository
{
    /**
     * The locale a bare string label is filed under, and the one a legacy
     * non-JSON value is reported as. Not a display default: nothing here picks a
     * language for a reader (see {@see LocalizedText}).
     */
    public const FALLBACK_LOCALE = 'en';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Every body in the tenant, active first, then by key.
     *
     * Unpaginated, deliberately. A tenant accumulates documents without bound but
     * not standing bodies — a few dozen over the life of an institution is a lot
     * — and a paginated picker of committees is a page-two nobody would reach.
     *
     * @return list<array<string, mixed>>
     */
    public function listForTenant(int $tenantId, ?bool $activeOnly = null): array
    {
        $sql = 'SELECT id, tenant_id, body_key, name, ou_id, description, is_active, created_at, updated_at
                  FROM convening_bodies
                 WHERE tenant_id = :tenant_id';
        $params = [':tenant_id' => $tenantId];

        if ($activeOnly === true) {
            // A literal rather than a bound parameter. `execute()` binds every
            // value as a STRING, and a boolean column compared against a bound
            // '1' is one driver setting away from being an error on one engine
            // and a silent no-match on another — the #891 hazard, arriving from
            // the write side instead of the read side.
            $sql .= ' AND is_active = TRUE';
        }

        $sql .= ' ORDER BY is_active DESC, body_key ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::normalizeBody(...), $rows);
    }

    /**
     * One body, tenant-scoped. Null when it does not exist OR belongs to another
     * tenant — the caller cannot tell the two apart, which is the point.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $tenantId, int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, body_key, name, ou_id, description, is_active, created_at, updated_at
               FROM convening_bodies
              WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalizeBody($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByKey(int $tenantId, string $bodyKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, body_key, name, ou_id, description, is_active, created_at, updated_at
               FROM convening_bodies
              WHERE tenant_id = :tenant_id AND body_key = :body_key'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':body_key' => $bodyKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalizeBody($row);
    }

    /**
     * @param array<string, string> $name Locale => label, already normalized.
     *
     * @throws ConveningRejectedException When the key is malformed or taken.
     */
    public function create(
        int $tenantId,
        string $bodyKey,
        array $name,
        ?int $ouId,
        ?string $description
    ): int {
        self::assertKey($bodyKey);

        if ($this->findByKey($tenantId, $bodyKey) !== null) {
            throw ConveningRejectedException::because(
                "This tenant already has a convening body with the key '{$bodyKey}'. Keys are how "
                . 'decision numbers are built and how integrations name a body, so two bodies cannot '
                . 'share one.'
            );
        }

        $stmt = $this->db->prepare(
            'INSERT INTO convening_bodies (tenant_id, body_key, name, ou_id, description, is_active)
             VALUES (:tenant_id, :body_key, :name, :ou_id, :description, :is_active)'
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':body_key', $bodyKey, PDO::PARAM_STR);
        $stmt->bindValue(':name', LocalizedText::encode($name), PDO::PARAM_STR);
        $stmt->bindValue(':ou_id', $ouId, $ouId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(
            ':description',
            $description,
            $description === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $stmt->bindValue(':is_active', true, PDO::PARAM_BOOL);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update the mutable fields of a body.
     *
     * `body_key` IS NOT MUTABLE and is not accepted here. Decision numbers
     * already minted contain it, and an integration binds to it; editing it in
     * place would silently repoint every one of those at a body that, as far as
     * the string is concerned, no longer exists. A body that needs a different
     * key is a different body. {@see \Whity\Core\TimeWindow\WindowTypeRepository}
     * refuses a key edit for the same reason.
     *
     * @param array<string, mixed> $fields Any of `name` (locale map), `ou_id`,
     *        `description`, `is_active`.
     *
     * @throws ConveningRejectedException When nothing updatable was supplied.
     */
    public function update(int $tenantId, int $id, array $fields): void
    {
        $sets = [];
        $params = [':tenant_id' => $tenantId, ':id' => $id];

        if (isset($fields['name']) && is_array($fields['name'])) {
            $sets[] = 'name = :name';
            /** @var array<string, string> $nameMap */
            $nameMap = $fields['name'];
            $params[':name'] = LocalizedText::encode($nameMap);
        }
        if (array_key_exists('ou_id', $fields)) {
            $sets[] = 'ou_id = :ou_id';
            $params[':ou_id'] = $fields['ou_id'] === null ? null : (int) $fields['ou_id'];
        }
        if (array_key_exists('description', $fields)) {
            $sets[] = 'description = :description';
            $params[':description'] = $fields['description'] === null
                ? null
                : (string) $fields['description'];
        }
        // `is_active` is applied as a SQL LITERAL rather than a bound value, for
        // the reason listForTenant() records: `execute()` binds everything as a
        // string, and a boolean column set from a bound '1' is driver-dependent.
        // The value is a PHP bool by then, so there is nothing here a caller
        // could interpolate.
        // The four spellings #891 is about ('t', 'f', 'true', 'false') are how a
        // driver hands a BOOLEAN column BACK; the READ path here goes through
        // DbBool::of() in normalizeBody(). This is the write path.
        if (array_key_exists('is_active', $fields)) {
            // @db-bool-ignore: a REQUEST value on the way IN, from the parsed JSON body — not a database read
            $sets[] = 'is_active = ' . ((bool) $fields['is_active'] ? 'TRUE' : 'FALSE');
        }

        if ($sets === []) {
            throw ConveningRejectedException::because(
                'Nothing to update. Supply at least one of: name, ou_id, description, is_active. '
                . 'The body key is immutable — decision numbers already quote it.'
            );
        }

        $sets[] = 'updated_at = NOW()';

        $stmt = $this->db->prepare(
            'UPDATE convening_bodies SET ' . implode(', ', $sets)
            . ' WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute($params);
    }

    /**
     * Delete a body.
     *
     * REFUSED, NEVER FORCED, once it has met. Migration 130 cascades meetings
     * from a body so a tenant teardown completes, but reaching that cascade from
     * an ordinary delete would destroy a minute-book — including decisions that
     * have already advanced somebody's document through a routing chain, whose
     * trail would then point at a body that does not exist.
     * {@see \Whity\Core\Ou\OuTypeRepository::delete()} refuses to strand units on
     * the same reasoning. A body that has finished its work is deactivated.
     *
     * @throws ConveningRejectedException When the body has met.
     */
    public function delete(int $tenantId, int $id): void
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM meetings WHERE tenant_id = :tenant_id AND body_id = :body_id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':body_id' => $id]);
        $meetings = (int) $stmt->fetchColumn();

        if ($meetings > 0) {
            throw ConveningRejectedException::because(
                "This body has {$meetings} meeting(s) on record and cannot be deleted — deleting it "
                . 'would destroy their agendas and decisions, some of which may have approved '
                . 'documents. Deactivate it instead: it stays readable and stops appearing where a '
                . 'body is chosen.'
            );
        }

        $stmt = $this->db->prepare(
            'DELETE FROM convening_bodies WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);
    }

    // -- membership ---------------------------------------------------------

    /**
     * The body's CURRENT seats, chair first.
     *
     * Ordered by seat precedence and then by id, which is the order
     * {@see DecisionRouteBridge} wants and the order a reader expects — a
     * membership list that puts the chair somewhere in the middle alphabetically
     * reads as unordered.
     *
     * @return list<array<string, mixed>>
     */
    public function currentMembers(int $tenantId, int $bodyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, body_id, profile_id, member_role, joined_at, left_at
               FROM convening_body_members
              WHERE tenant_id = :tenant_id AND body_id = :body_id AND left_at IS NULL
              ORDER BY id ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':body_id' => $bodyId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $members = array_map(self::normalizeMember(...), $rows);

        usort($members, static function (array $a, array $b): int {
            $bySeat = MemberRole::precedence((string) $a['member_role'])
                <=> MemberRole::precedence((string) $b['member_role']);

            return $bySeat !== 0 ? $bySeat : ((int) $a['id'] <=> (int) $b['id']);
        });

        return $members;
    }

    /**
     * Every seat ever held on the body, current and past.
     *
     * The read that answers "how was this body constituted when it took that
     * decision" — which is why past seats are kept at all.
     *
     * @return list<array<string, mixed>>
     */
    public function allMembers(int $tenantId, int $bodyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, body_id, profile_id, member_role, joined_at, left_at
               FROM convening_body_members
              WHERE tenant_id = :tenant_id AND body_id = :body_id
              ORDER BY id ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':body_id' => $bodyId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::normalizeMember(...), $rows);
    }

    public function isCurrentMember(int $tenantId, int $bodyId, int $profileId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM convening_body_members
              WHERE tenant_id = :tenant_id AND body_id = :body_id
                AND profile_id = :profile_id AND left_at IS NULL
              LIMIT 1'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':body_id' => $bodyId,
            ':profile_id' => $profileId,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Seat somebody on the body, or move the seat they already hold.
     *
     * Appointing a current member to a different seat UPDATES their open row
     * rather than closing it and opening another. A chair who becomes secretary
     * did not leave the body for an instant, and a pair of rows saying they did
     * would make the membership history read as a departure and a re-appointment
     * that never happened.
     *
     * @throws ConveningRejectedException When the seat is not one of the three.
     */
    public function addMember(int $tenantId, int $bodyId, int $profileId, string $memberRole): int
    {
        if (!MemberRole::isValid($memberRole)) {
            throw ConveningRejectedException::because(
                "'{$memberRole}' is not a seat on a convening body; expected one of: "
                . implode(', ', MemberRole::all()) . '.'
            );
        }

        $stmt = $this->db->prepare(
            'SELECT id FROM convening_body_members
              WHERE tenant_id = :tenant_id AND body_id = :body_id
                AND profile_id = :profile_id AND left_at IS NULL'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':body_id' => $bodyId,
            ':profile_id' => $profileId,
        ]);
        $existing = $stmt->fetchColumn();

        if ($existing !== false) {
            $update = $this->db->prepare(
                'UPDATE convening_body_members SET member_role = :member_role
                  WHERE tenant_id = :tenant_id AND id = :id'
            );
            $update->execute([
                ':member_role' => $memberRole,
                ':tenant_id' => $tenantId,
                ':id' => (int) $existing,
            ]);

            return (int) $existing;
        }

        $insert = $this->db->prepare(
            'INSERT INTO convening_body_members (tenant_id, body_id, profile_id, member_role)
             VALUES (:tenant_id, :body_id, :profile_id, :member_role)'
        );
        $insert->execute([
            ':tenant_id' => $tenantId,
            ':body_id' => $bodyId,
            ':profile_id' => $profileId,
            ':member_role' => $memberRole,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * End somebody's seat. Idempotent: standing down twice is standing down once.
     *
     * @return bool Whether a seat was open to end.
     */
    public function removeMember(int $tenantId, int $bodyId, int $profileId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE convening_body_members SET left_at = NOW()
              WHERE tenant_id = :tenant_id AND body_id = :body_id
                AND profile_id = :profile_id AND left_at IS NULL'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':body_id' => $bodyId,
            ':profile_id' => $profileId,
        ]);

        return $stmt->rowCount() > 0;
    }

    // -- internals ----------------------------------------------------------

    /**
     * @throws ConveningRejectedException
     */
    public static function assertKey(string $bodyKey): void
    {
        // Lower-case, digits, underscore and hyphen. The same shape every other
        // stable key in this platform takes, and narrow for a reason that is not
        // aesthetic: the key is interpolated into a decision number that people
        // quote in correspondence and paste into search boxes, so a key
        // containing a slash or a space would produce numbers nobody can quote
        // unambiguously.
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $bodyKey) !== 1) {
            throw ConveningRejectedException::because(
                "'{$bodyKey}' is not a usable body key. Use lower-case letters, digits, '-' and '_' "
                . '(up to 64 characters, starting with a letter or digit) — the key is quoted inside '
                . 'every decision number this body mints.'
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeBody(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            'body_key' => (string) $row['body_key'],
            'name' => LocalizedText::decode($row['name'] ?? null, self::FALLBACK_LOCALE),
            // BESIDE the map, never instead of it. A localizing client reads
            // `name`; a surface that can only hold one string — a notification
            // subject, a server-driven table cell — reads this. See
            // LocalizedText::preferred().
            'display_name' => LocalizedText::preferred(
                LocalizedText::decode($row['name'] ?? null, self::FALLBACK_LOCALE),
                self::FALLBACK_LOCALE,
                (string) $row['body_key']
            ),
            'ou_id' => $row['ou_id'] !== null ? (int) $row['ou_id'] : null,
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            // Through DbBool, never a bare cast: a BOOLEAN comes back as bool,
            // '1'/'0', 't'/'f' or 'true'/'false' depending on driver and PDO
            // settings, and `(bool) 'false'` is TRUE (#891).
            'is_active' => DbBool::of($row['is_active'] ?? null),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeMember(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            'body_id' => (int) $row['body_id'],
            'profile_id' => (int) $row['profile_id'],
            'member_role' => (string) $row['member_role'],
            'joined_at' => (string) $row['joined_at'],
            'left_at' => $row['left_at'] !== null ? (string) $row['left_at'] : null,
        ];
    }
}
