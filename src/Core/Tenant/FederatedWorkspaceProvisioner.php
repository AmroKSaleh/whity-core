<?php

declare(strict_types=1);

namespace Whity\Core\Tenant;

use PDO;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * A workspace of their own for somebody whose first federated sign-in finds
 * them a member of nothing.
 *
 * WHY THIS EXISTS
 * ---------------
 * A person who signs in through the OPERATOR's identity provider (global trust,
 * tenant 0) for the first time gets a profile from
 * {@see \Whity\Core\Identity\FederatedIdentityLinker} and no membership, because
 * nothing says which tenant they belong to. They then bounce with
 * `no_membership`, and the only way in is an administrator creating a tenant for
 * them by hand. For an instance that sells self-service workspaces through an
 * external identity provider (the provider owns sign-up and billing, this
 * instance owns the product) that hand step is exactly the step that must not
 * exist. This is the federated twin of {@see \Whity\Api\RegisterApiHandler}:
 * the provider has already verified the address, so there is no password to
 * set and no approval queue to wait in.
 *
 * WHEN IT ACTS — every condition, or nothing
 * ------------------------------------------
 *  - The operator turned it on: {@see SettingsRegistry::SSO_SELF_PROVISION_WORKSPACE},
 *    global-only, default OFF. A sovereign instance stays operator-provisioned.
 *  - The caller only asks for global-trust providers. A tenant's own
 *    bring-your-own IdP must never be able to mint tenants on this instance.
 *  - The profile holds NO membership row in ANY status. An invited, suspended
 *    or pending membership means somebody already decided where this person
 *    belongs, and a fresh workspace would route around that decision.
 *
 * The profile row is locked for the check-then-create (PostgreSQL), so two
 * callbacks racing for one person produce one workspace, not two.
 *
 * WHAT THEY GET
 * -------------
 * A tenant created through {@see TenantProvisioner} (so it is announced with
 * `tenant.created` and given everything core gives every tenant), and an ACTIVE,
 * primary membership with the base `admin` role — the same owner role
 * self-registration grants. Limits, plans and anything a product wants to seed
 * are the business of `tenant.created` listeners and entitlements, not of this
 * class.
 */
final class FederatedWorkspaceProvisioner
{
    /** The base (global, NULL-tenant) role the workspace owner is granted. */
    public const OWNER_ROLE = 'admin';

    /** How many "Name 2", "Name 3"… to try before giving up on a free name. */
    private const NAME_ATTEMPTS = 50;

    public function __construct(
        private readonly PDO $db,
        private readonly SettingsService $settings,
        private readonly ?HookManager $hooks = null,
    ) {
    }

    public function enabled(): bool
    {
        $global = $this->settings->getGlobal();
        return ($global[SettingsRegistry::SSO_SELF_PROVISION_WORKSPACE] ?? 'false') === 'true';
    }

    /**
     * Create the person's workspace, or do nothing.
     *
     * @return int|null the new tenant id, or null when nothing was created
     *                  (switched off, already a member somewhere, or the owner
     *                  role is missing on an unseeded platform)
     */
    public function provisionFor(int $profileId, string $email): ?int
    {
        if (!$this->enabled()) {
            return null;
        }

        $grant = null;
        $tenantId = null;
        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }

        try {
            $displayName = $this->lockProfile($profileId);
            if ($displayName === null || $this->hasAnyMembership($profileId)) {
                if ($ownTx) {
                    $this->db->rollBack();
                }
                return null;
            }

            $roleId = $this->ownerRoleId();
            if ($roleId === null) {
                error_log('[sso-workspace] base "' . self::OWNER_ROLE . '" role missing; platform not seeded');
                if ($ownTx) {
                    $this->db->rollBack();
                }
                return null;
            }

            [$name, $slug] = $this->freeNameAndSlug($this->baseName($displayName, $email));

            $tenantId = TenantProvisioner::withCoreSteps($this->db, $this->hooks)->create($name, $slug);

            // is_primary: this is the person's first and only membership.
            // Written as a boolean literal, not 1 — PostgreSQL rejects the integer.
            $this->db->prepare(
                "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at)
                 VALUES (:profile_id, :tenant_id, :role_id, NULL, true, 'active', NOW())"
            )->execute([
                ':profile_id' => $profileId,
                ':tenant_id'  => $tenantId,
                ':role_id'    => $roleId,
            ]);

            $grant = [
                'profile_id'    => $profileId,
                'tenant_id'     => $tenantId,
                'membership_id' => (int) $this->db->lastInsertId(),
                'role_id'       => $roleId,
                'role_name'     => self::OWNER_ROLE,
                'ou_id'         => null,
                'status'        => 'active',
                'is_primary'    => true,
                'via'           => 'sso_self_provision',
            ];

            if ($ownTx) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        // Post-commit: an assertion for an append-only trail must not be made
        // before the write it describes is durable. Best-effort — a listener
        // failure must not undo a workspace that now exists.
        if ($this->hooks !== null) {
            try {
                $this->hooks->dispatch('user.membership.added', $grant);
                $this->hooks->dispatchAsync('tenant.created.async', ['id' => $tenantId, 'name' => $name]);
            } catch (\Throwable $e) {
                error_log('[sso-workspace] post-commit hooks failed for tenant ' . $tenantId . ': ' . $e->getMessage());
            }
        }

        return $tenantId;
    }

    /**
     * Lock the profile row for the duration of the transaction and return its
     * display name, or null when there is no such profile.
     */
    private function lockProfile(int $profileId): ?string
    {
        $forUpdate = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? ' FOR UPDATE' : '';
        // @tenant-guard-ignore: profiles is a sanctioned GLOBAL table (ADR 0005 §1)
        $stmt = $this->db->prepare('SELECT display_name FROM profiles WHERE id = :id' . $forUpdate);
        $stmt->execute([':id' => $profileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return trim((string) ($row['display_name'] ?? ''));
    }

    private function hasAnyMembership(int $profileId): bool
    {
        // @tenant-guard-ignore: the question is "a member of ANY tenant?", which is cross-tenant by definition
        $stmt = $this->db->prepare('SELECT 1 FROM memberships WHERE profile_id = :id LIMIT 1');
        $stmt->execute([':id' => $profileId]);
        return $stmt->fetchColumn() !== false;
    }

    private function ownerRoleId(): ?int
    {
        // @tenant-guard-ignore: base roles are global (NULL tenant_id); looked up by unique name
        $stmt = $this->db->prepare('SELECT id FROM roles WHERE name = :name AND tenant_id IS NULL LIMIT 1');
        $stmt->execute([':name' => self::OWNER_ROLE]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private function baseName(string $displayName, string $email): string
    {
        $name = $displayName !== '' ? $displayName : $email;
        if ($name === '') {
            $name = 'Workspace';
        }
        // Leave room for a " 50" suffix inside the VARCHAR(255) column.
        return mb_substr($name, 0, 240);
    }

    /**
     * A tenant name and slug nobody holds yet. Names may be in any script; a
     * slug is ASCII, so a name with no Latin letters or digits falls back to
     * "workspace".
     *
     * @return array{0: string, 1: string}
     */
    private function freeNameAndSlug(string $base): array
    {
        $slugBase = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $base));
        $slugBase = trim($slugBase, '-');
        if ($slugBase === '') {
            $slugBase = 'workspace';
        }
        $slugBase = substr($slugBase, 0, 240);

        for ($n = 1; $n <= self::NAME_ATTEMPTS; $n++) {
            $name = $n === 1 ? $base : $base . ' ' . $n;
            $slug = $n === 1 ? $slugBase : $slugBase . '-' . $n;
            // @tenant-guard-ignore: the tenants table is the tenant registry itself, not a tenant-scoped resource
            $stmt = $this->db->prepare('SELECT 1 FROM tenants WHERE name = :name OR slug = :slug LIMIT 1');
            $stmt->execute([':name' => $name, ':slug' => $slug]);
            if ($stmt->fetchColumn() === false) {
                return [$name, $slug];
            }
        }

        // Fifty people of the same name: stop guessing and make it unique.
        $suffix = bin2hex(random_bytes(4));
        return [$base . ' ' . $suffix, $slugBase . '-' . $suffix];
    }
}
