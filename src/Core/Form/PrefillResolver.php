<?php

declare(strict_types=1);

namespace Whity\Core\Form;

use PDO;

/**
 * Resolves {@see PrefillSource} values for ONE person in ONE tenant, at the
 * moment a form is rendered for them.
 *
 * RESOLVED ON READ, NEVER CAPTURED — AND THIS IS THE OPPOSITE OF
 * {@see \Whity\Core\Ou\PrimaryMembershipOu}'s RULE
 * ---------------------------------------------------------------
 * The two look similar and mean opposite things, so the difference is worth
 * stating rather than leaving for whoever reads them next to reconcile.
 *
 * `PrimaryMembershipOu` is CAPTURED: a document raised last year records the
 * unit its author was in last year, because re-deriving it on read would rewrite
 * history for every past record.
 *
 * A prefill value is RESOLVED, every time, and must be: it is not a record of
 * anything, it is a convenience shown to somebody who has not submitted yet, so
 * that they do not retype what the organisation already knows. Showing a stale
 * value here is not "preserving history", it is handing a person last year's
 * details to sign off on. The moment they SUBMIT, the value they saw is written
 * into `form_submissions.data` and becomes a captured fact like any other — the
 * transition from resolved to captured happens exactly once, at submit.
 *
 * WHAT AN UNBACKED SOURCE DOES
 * -----------------------------
 * Returns null and says so. Two of the five declared sources have no column in
 * this schema to read (see {@see PrefillSource}); this class does not paper over
 * that with an empty string, because an empty string is indistinguishable from
 * "the person has no phone number on file" and one of those is a platform gap
 * while the other is a fact about a person.
 *
 * TENANT SCOPING
 * ---------------
 * `profiles` and `profile_emails` are GLOBAL tables (no `tenant_id` column at
 * all — see {@see \Whity\Core\Tenant\TenantOwnedTables}), so the reads below
 * bind `profile_id` and nothing else, which is correct: a profile is one person
 * across every tenant they belong to and their display name does not change when
 * they switch tenants.
 *
 * `memberships` and `organizational_units` ARE tenant-owned, and the unit read
 * binds BOTH `profile_id` and `tenant_id` — because which unit somebody is in is
 * precisely the fact that differs per tenant, and a query that omitted the
 * predicate would hand a submitter a unit name from an unrelated organisation.
 *
 * Stateless apart from the injected handle — worker-safe.
 */
final class PrefillResolver
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Resolve every prefill source the given fields name, for one person.
     *
     * Keyed by `field_key` rather than by source, because that is what a renderer
     * needs: two fields may name the same source and each wants its own entry.
     * A field with no `prefill_source`, an unrecognised source, or an unbacked
     * one is simply absent from the result — absent means "nothing to pre-fill",
     * which is the same thing a renderer does with a null.
     *
     * A null `$profileId` (a service principal, which has no profile) resolves
     * nothing at all rather than failing: rendering a form for inspection is a
     * legitimate thing to do without being a person.
     *
     * ONE QUERY PER DISTINCT SOURCE, not one per field. A form with twelve fields
     * all reading `profile.display_name` asks the database once.
     *
     * @param list<array<string, mixed>> $fields Normalized `form_fields` rows.
     * @return array<string, string> field_key => resolved value.
     */
    public function forFields(int $tenantId, ?int $profileId, array $fields): array
    {
        if ($profileId === null) {
            return [];
        }

        $wanted = [];
        foreach ($fields as $field) {
            $source = $field['prefill_source'] ?? null;
            if (!is_string($source) || !PrefillSource::isBacked($source)) {
                continue;
            }
            $wanted[$source] = true;
        }

        if ($wanted === []) {
            return [];
        }

        $values = [];
        foreach (array_keys($wanted) as $source) {
            $value = $this->resolveOne($tenantId, $profileId, $source);
            if ($value !== null && $value !== '') {
                $values[$source] = $value;
            }
        }

        $byFieldKey = [];
        foreach ($fields as $field) {
            $source = $field['prefill_source'] ?? null;
            $key = $field['field_key'] ?? null;
            if (!is_string($source) || !is_string($key) || !isset($values[$source])) {
                continue;
            }
            $byFieldKey[$key] = $values[$source];
        }

        return $byFieldKey;
    }

    /**
     * One source, for one person. Null when the source is unbacked, unknown, or
     * simply has no value for this person.
     *
     * Exposed (rather than private) because the field editor asks it too, to show
     * an author what their own prefill choice would produce — the cheapest
     * possible way to catch "this resolves to nothing" while the form is still
     * being written instead of after it is published.
     */
    public function resolveOne(int $tenantId, int $profileId, string $source): ?string
    {
        return match ($source) {
            PrefillSource::DISPLAY_NAME => $this->displayName($profileId),
            PrefillSource::EMAIL => $this->email($profileId),
            PrefillSource::OU => $this->ouName($tenantId, $profileId),
            PrefillSource::OU_ID => $this->ouId($tenantId, $profileId),
            // PHONE and JOB_TITLE are declared but unbacked — no column in this
            // schema holds either. Returning null (rather than '') keeps
            // "the platform cannot know this" distinguishable from "this person
            // left it blank". See PrefillSource's class docblock.
            default => null,
        };
    }

    /**
     * `profiles` is a SANCTIONED GLOBAL table
     * ({@see \Whity\Core\Tenant\SanctionedGlobalTables}, ADR 0005) — no
     * `tenant_id` column exists on it, so there is no tenant predicate to bind
     * and none is missing. No guard annotation is needed here for the same
     * reason: the scanner already knows.
     */
    private function displayName(int $profileId): ?string
    {
        $stmt = $this->db->prepare('SELECT display_name FROM profiles WHERE id = :profile_id LIMIT 1');
        $stmt->execute([':profile_id' => $profileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }
        $name = $row['display_name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * The address to pre-fill with, in the order a person would expect: their
     * PRIMARY address, then a VERIFIED one, then whatever is on file.
     *
     * The ordering is explicit for the reason
     * {@see \Whity\Core\Ou\PrimaryMembershipOu} spells out about its own: a
     * `LIMIT 1` with no `ORDER BY` picks whichever row the query plan reached
     * first, which is stable in one database and different in a restore of it —
     * and here it would mean somebody's form pre-filling with a disused address
     * for no reason anybody could reproduce.
     *
     * `profile_emails` is a sanctioned global table (migration 029,
     * `UNIQUE (email)` across the whole install), so the read binds `profile_id`
     * only and the scanner requires no annotation.
     */
    private function email(int $profileId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT email
               FROM profile_emails
              WHERE profile_id = :profile_id
              ORDER BY is_primary DESC, verified DESC, id ASC
              LIMIT 1'
        );
        $stmt->execute([':profile_id' => $profileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }
        $email = $row['email'] ?? null;

        return is_string($email) && $email !== '' ? $email : null;
    }

    /**
     * The NAME of the unit this person acts from in this tenant.
     *
     * The membership ordering mirrors {@see \Whity\Core\Ou\PrimaryMembershipOu}
     * exactly — primary first, then the oldest membership holding a unit, and
     * only ACTIVE memberships — because two answers to "which unit is this
     * person in" that disagree by one `ORDER BY` is precisely the defect that
     * class exists to prevent. This resolves the NAME rather than the id, since
     * a prefilled text field wants something a person recognises; a form that
     * wants the id uses an `ou_ref` field instead.
     */
    /**
     * The same membership `ouName()` reads, as an id.
     *
     * Deliberately the SAME ordering — primary membership first, then oldest —
     * so a person whose name-valued field says one department cannot have their
     * reference-valued field point at another.
     */
    private function ouId(int $tenantId, int $profileId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT m.ou_id
               FROM memberships m
               JOIN organizational_units ou
                 ON ou.id = m.ou_id AND ou.tenant_id = m.tenant_id
              WHERE m.profile_id = :profile_id
                AND m.tenant_id = :tenant_id
                AND m.status = :status
                AND m.ou_id IS NOT NULL
              ORDER BY m.is_primary DESC, m.id ASC
              LIMIT 1'
        );
        $stmt->execute([
            ':profile_id' => $profileId,
            ':tenant_id' => $tenantId,
            ':status' => 'active',
        ]);
        $id = $stmt->fetchColumn();

        return $id === false || $id === null ? null : (string) $id;
    }

    private function ouName(int $tenantId, int $profileId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT ou.name
               FROM memberships m
               JOIN organizational_units ou
                 ON ou.id = m.ou_id AND ou.tenant_id = m.tenant_id
              WHERE m.profile_id = :profile_id
                AND m.tenant_id = :tenant_id
                AND m.status = :status
                AND m.ou_id IS NOT NULL
              ORDER BY m.is_primary DESC, m.id ASC
              LIMIT 1'
        );
        $stmt->execute([
            ':profile_id' => $profileId,
            ':tenant_id' => $tenantId,
            ':status' => 'active',
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }
        $name = $row['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }
}
