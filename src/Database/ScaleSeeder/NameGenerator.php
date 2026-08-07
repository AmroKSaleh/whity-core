<?php

declare(strict_types=1);

namespace Whity\Database\ScaleSeeder;

/**
 * Deterministic, human-readable name/slug/email generation for the
 * scale-seeder (WC-35).
 *
 * Two kinds of method:
 *  - STATIC, pure functions of their integer inputs (slug/email composition:
 *    {@see self::tenantSlug()}, {@see self::ouSlug()}, {@see self::userEmail()}) —
 *    no PRNG draw involved, safe to call any number of times regardless of
 *    whether the row they key already exists.
 *  - INSTANCE methods that draw realistic-looking words from the injected
 *    {@see DeterministicRandom} ({@see self::companyName()}, {@see self::personName()},
 *    {@see self::ouName()}, {@see self::customRoleName()}). Callers MUST give each
 *    of these its own freshly-{@see DeterministicRandom::derive()}d generator
 *    (never a single stream shared across many entities) and MUST call them
 *    unconditionally — even when the row already exists and the drawn value
 *    ends up unused — so that a value drawn for entity X is byte-identical
 *    every time regardless of what happened to any OTHER entity in the same
 *    run (see {@see ScaleSeeder} for why: a shared stream desyncs the moment
 *    any earlier "reuse" branch skips a draw that the matching "create"
 *    branch would have made).
 *
 * Uniqueness discipline: several tables enforce UNIQUE constraints that span
 * MORE than one tenant (`tenants.name`/`tenants.slug` are globally unique,
 * `roles.name` is globally unique, `profile_emails.email` is globally
 * unique). For every such value this class embeds the numeric seed/tenant/
 * user index directly in the string, so uniqueness is guaranteed BY
 * CONSTRUCTION rather than by hoping the PRNG never repeats a word
 * combination. The PRNG-drawn words exist purely to make the data look
 * realistic, never to carry the uniqueness guarantee.
 */
final class NameGenerator
{
    /** @var list<string> */
    private const FIRST_NAMES = [
        'Alice', 'Bob', 'Carla', 'Daniel', 'Elena', 'Farid', 'Grace', 'Hassan', 'Irene', 'Jamal',
        'Karen', 'Liam', 'Maya', 'Noah', 'Omar', 'Priya', 'Quinn', 'Rania', 'Samuel', 'Tara',
        'Umar', 'Vera', 'Walid', 'Xenia', 'Yusuf', 'Zara', 'Adam', 'Bianca', 'Cyrus', 'Dalia',
    ];

    /** @var list<string> */
    private const LAST_NAMES = [
        'Anderson', 'Baptiste', 'Castillo', 'Delgado', 'Eriksson', 'Farouk', 'Gomez', 'Haddad',
        'Ibrahim', 'Johansson', 'Khalil', 'Larsson', 'Mansour', 'Nasser', 'Osei', 'Petrov',
        'Qureshi', 'Rahman', 'Salem', 'Tanaka', 'Ueda', 'Valdez', 'Winters', 'Xu', 'Yamamoto', 'Zimmer',
    ];

    /** @var list<string> */
    private const DEPARTMENT_WORDS = [
        'Engineering', 'Sales', 'Support', 'Finance', 'Operations', 'Marketing', 'Legal',
        'Human Resources', 'Research', 'Procurement', 'Logistics', 'Customer Success',
        'IT', 'Quality Assurance', 'Product', 'Security', 'Facilities', 'Compliance',
    ];

    /** @var list<string> */
    private const COMPANY_ADJECTIVES = [
        'Silverline', 'Bluepeak', 'Ironwood', 'Golden', 'Northgate', 'Crimson', 'Vertex',
        'Cobalt', 'Amber', 'Solaris', 'Granite', 'Meridian', 'Pioneer', 'Summit', 'Lumen',
    ];

    /** @var list<string> */
    private const COMPANY_NOUNS = [
        'Logistics', 'Systems', 'Holdings', 'Dynamics', 'Industries', 'Solutions', 'Works',
        'Networks', 'Ventures', 'Group', 'Technologies', 'Partners', 'Labs', 'Traders',
    ];

    /** @var list<string> */
    private const ROLE_WORDS = [
        'Manager', 'Coordinator', 'Specialist', 'Analyst', 'Lead', 'Officer', 'Supervisor',
    ];

    public function __construct(private readonly DeterministicRandom $rng)
    {
    }

    /** Globally-unique, human-readable tenant name (embeds seed + index). */
    public function companyName(int $seed, int $tenantIndex): string
    {
        $adjective = $this->rng->pick(self::COMPANY_ADJECTIVES);
        $noun = $this->rng->pick(self::COMPANY_NOUNS);

        return sprintf('%s %s %d-%d', $adjective, $noun, $seed, $tenantIndex);
    }

    /**
     * Globally-unique tenant slug (`tenants.slug` has no per-tenant scope).
     * Static: a pure function of its inputs, no PRNG draw involved.
     */
    public static function tenantSlug(int $seed, int $tenantIndex): string
    {
        return sprintf('scale-%d-t%d', $seed, $tenantIndex);
    }

    /**
     * A realistic-looking (first, last, display) triple. Not required to be
     * unique — real organisations have namesakes too.
     *
     * @return array{first: string, last: string, display: string}
     */
    public function personName(): array
    {
        $first = $this->rng->pick(self::FIRST_NAMES);
        $last = $this->rng->pick(self::LAST_NAMES);

        return ['first' => $first, 'last' => $last, 'display' => $first . ' ' . $last];
    }

    /** OU name: a department word plus the node's structural path label. */
    public function ouName(string $pathLabel): string
    {
        return sprintf('%s %s', $this->rng->pick(self::DEPARTMENT_WORDS), $pathLabel);
    }

    /**
     * OU slug, unique within (seed, tenant) — organizational_units is UNIQUE(tenant_id, slug).
     * Static: a pure function of its inputs, no PRNG draw involved.
     */
    public static function ouSlug(int $seed, int $tenantIndex, string $pathLabel): string
    {
        $normalized = strtolower(str_replace(['.', ' '], '-', $pathLabel));

        return sprintf('ou-%d-t%d-%s', $seed, $tenantIndex, $normalized);
    }

    /** Globally-unique custom role name (`roles.name` has no per-tenant scope). */
    public function customRoleName(int $seed, int $tenantIndex, int $roleIndex): string
    {
        $word = $this->rng->pick(self::ROLE_WORDS);

        return sprintf('Scale %s %d-t%d-r%d', $word, $seed, $tenantIndex, $roleIndex);
    }

    /**
     * Globally-unique, deterministic email for a scale-seeded user (`profile_emails.email` is global).
     * Static: a pure function of its inputs, no PRNG draw involved.
     */
    public static function userEmail(int $seed, int $tenantIndex, int $userIndex): string
    {
        return sprintf('scale-seed%d-t%d-u%d@example.test', $seed, $tenantIndex, $userIndex);
    }
}
