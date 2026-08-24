<?php

declare(strict_types=1);

namespace Whity\Core\Tenant;

use PDO;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Whity\Core\Document\DocumentBlockRepository;
use Whity\Core\Document\DocumentStarterSeeder;
use Whity\Core\Document\DocumentTemplateRepository;
use Whity\Core\Hooks\HookManager;

/**
 * How a tenant comes into existence, and what it is given when it does (#1012).
 *
 * THE BUG THIS CLASS IS THE FIX FOR
 * ---------------------------------
 * "Create a tenant" was spelled three different ways — an INSERT in
 * {@see \Whity\Database\Seeder::seed()}, an INSERT in
 * {@see \Whity\Api\TenantsApiHandler::create()}, an INSERT in
 * {@see \Whity\Api\RegisterApiHandler} — and only the middle one dispatched
 * `tenant.created`. Starter templates and header/footer blocks hang off that
 * event, so the Default Tenant, which every fresh install opens the designer in,
 * was the ONE tenant that never got them. The product requirement it defeated is
 * explicit: the designer must never present an empty document.
 *
 * The one-line fix was to dispatch the event from the seeder as well. It was
 * refused for two reasons. It does not actually work — the CLI `seed` path exits
 * `public/index.php` long before the bootstrap that REGISTERS that listener, so
 * the dispatch would reach nobody. And it fixes today's symptom only: a fourth
 * INSERT site would reintroduce it, and a second core listener on
 * `tenant.created` would skip the default tenant again just as silently.
 *
 * So creation goes through here instead, and what a tenant must be given to be a
 * working tenant is a {@see TenantProvisioningStep} listed in one place, run by
 * every path that uses this. `tenant.created` keeps its job — announcing the fact
 * to audit and to plugins — and stops being the thing core provisioning secretly
 * depends on.
 *
 * THE TWO INSERT SITES THAT ARE STILL NOT THIS, AND WHY
 * ----------------------------------------------------
 *  - {@see \Whity\Database\ScaleSeeder\ScaleSeeder} creates tenants by the
 *    thousand for load testing, and DELIBERATELY does not want provisioning: six
 *    designer rows per tenant is not what that fixture is measuring. A tenant
 *    that should not be provisioned is a legitimate case, which is why this class
 *    is the way a tenant is provisioned rather than the only way a row appears.
 *  - {@see \Whity\Api\RegisterApiHandler} — self-service signup — SHOULD be
 *    here and is not yet. It has exactly the bug above: a workspace somebody
 *    registers gets no starters, because that INSERT dispatches nothing either.
 *    Moving it is a few lines now that this exists, but it also gives an
 *    unauthenticated public endpoint a `tenant.created` dispatch it has never
 *    had — an audit write and every registered plugin listener, inside its
 *    transaction — and that belongs in a change somebody is reviewing for that,
 *    not in a ride-along. Reported rather than done quietly.
 *
 * CREATED vs ALREADY THERE
 * ------------------------
 * {@see self::findOrCreate()} exists for the seeder, which is run repeatedly
 * against the same database. It announces `tenant.created` only when it actually
 * created something — a seed re-run must not append a second "tenant created"
 * assertion to an append-only audit trail for a tenant that has existed for a
 * year. It runs the provisioning STEPS every time, which is the opposite choice
 * and deliberate: steps are idempotent, and running them unconditionally is how
 * an install that predates this fix (or predates a step added later) picks it up
 * on its next `seed` instead of needing to be rebuilt.
 */
final class TenantProvisioner
{
    /** @var list<TenantProvisioningStep> */
    private array $steps;

    /**
     * @param iterable<TenantProvisioningStep> $steps
     */
    public function __construct(
        private readonly PDO $db,
        iterable $steps = [],
        private readonly ?HookManager $hooks = null,
    ) {
        $this->steps = is_array($steps) ? array_values($steps) : iterator_to_array($steps, false);
    }

    /**
     * The provisioner every caller should build unless it has a reason not to:
     * this PDO, the core steps, and whatever hook manager the caller has (none,
     * for the CLI seeder, which has no bootstrap and therefore no listeners).
     */
    public static function withCoreSteps(
        PDO $db,
        ?HookManager $hooks = null,
        ?LoggerInterface $logger = null,
    ): self {
        return new self($db, self::coreSteps($db, $logger), $hooks);
    }

    /**
     * What core gives every tenant. ADD TO THIS LIST rather than to a
     * `tenant.created` listener when the thing being added is part of what a
     * tenant IS — see {@see TenantProvisioningStep} for the line between them.
     *
     * @return list<TenantProvisioningStep>
     */
    public static function coreSteps(PDO $db, ?LoggerInterface $logger = null): array
    {
        return [
            // A tenant is never handed an empty designer: four starter templates
            // and the company header/footer blocks (WC-515 REMAINING #3). Needs
            // two repositories over this PDO and nothing else — no storage
            // driver, no settings service — which is why it is cheap enough to
            // be composed here and to run in the CLI seed path.
            new DocumentStarterSeeder(
                new DocumentTemplateRepository($db),
                new DocumentBlockRepository($db),
                $logger,
            ),
        ];
    }

    /**
     * Create a NEW tenant and provision it.
     *
     * The caller has already established that the name and slug are free; this
     * does not re-check, exactly as the INSERT it replaces did not.
     */
    public function create(string $name, string $slug): int
    {
        $tenantId = $this->insert($name, $slug);
        $this->announce($tenantId, $name, $slug);
        $this->runSteps($tenantId, $name);

        return $tenantId;
    }

    /**
     * The tenant called $name, created if it is not there yet, provisioned
     * either way.
     *
     * For the seeder. See the class docblock for why the announcement is
     * conditional and the steps are not.
     */
    public function findOrCreate(string $name, ?string $slug = null): int
    {
        $existing = $this->findByName($name);
        if ($existing !== null) {
            $this->runSteps($existing, $name);

            return $existing;
        }

        // ON CONFLICT, and then a re-read rather than the INSERT's own id: two
        // seeds racing (a container start-up running one while an operator runs
        // another) must both end up with the same single tenant rather than one
        // of them failing on the unique index.
        $this->insertIfAbsent($name, $slug);
        $tenantId = $this->findByName($name);
        if ($tenantId === null) {
            throw new RuntimeException(sprintf('Tenant "%s" could not be created or found.', $name));
        }

        $this->announce($tenantId, $name, $slug);
        $this->runSteps($tenantId, $name);

        return $tenantId;
    }

    private function insert(string $name, string $slug): int
    {
        $sql = 'INSERT INTO tenants (name, slug, created_at) VALUES (:name, :slug, NOW())';
        $params = [':name' => $name, ':slug' => $slug];

        if ($this->driver() === 'pgsql') {
            $stmt = $this->db->prepare($sql . ' RETURNING id');
            $stmt->execute($params);

            return (int) $stmt->fetchColumn();
        }

        $this->db->prepare($sql)->execute($params);

        return (int) $this->db->lastInsertId();
    }

    /**
     * The seeder's INSERT: `slug` is omitted when the caller has none rather
     * than written as NULL, so the statement stays exactly the one the Default
     * Tenant has always been created by.
     */
    private function insertIfAbsent(string $name, ?string $slug): void
    {
        if ($slug === null) {
            $this->db->prepare(
                'INSERT INTO tenants (name, created_at) VALUES (:name, NOW()) ON CONFLICT (name) DO NOTHING'
            )->execute([':name' => $name]);

            return;
        }

        $this->db->prepare(
            'INSERT INTO tenants (name, slug, created_at) VALUES (:name, :slug, NOW()) ON CONFLICT (name) DO NOTHING'
        )->execute([':name' => $name, ':slug' => $slug]);
    }

    private function findByName(string $name): ?int
    {
        // @tenant-guard-ignore: `tenants` is the tenant registry itself, not a tenant-owned table
        $stmt = $this->db->prepare('SELECT id FROM tenants WHERE name = :name');
        $stmt->execute([':name' => $name]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Announce the creation to audit and to plugins.
     *
     * Synchronous and BEFORE the steps for the reason
     * {@see \Whity\Api\TenantsApiHandler::create()} recorded when this dispatch
     * lived there: a listener that seeds tenant-scoped roles must have done so
     * by the time the caller resolves an initial role. Absent entirely in the
     * CLI seed path, which has no hook manager and no listeners — which is
     * precisely why core provisioning is a step and not a listener.
     */
    private function announce(int $tenantId, string $name, ?string $slug): void
    {
        $this->hooks?->dispatch('tenant.created', [
            'id'   => $tenantId,
            'name' => $name,
            'slug' => $slug,
        ]);
    }

    private function runSteps(int $tenantId, string $name): void
    {
        foreach ($this->steps as $step) {
            $step->provisionTenant($tenantId, $name);
        }
    }

    private function driver(): string
    {
        $name = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);

        return is_string($name) ? $name : '';
    }
}
