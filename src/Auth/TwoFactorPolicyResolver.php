<?php

declare(strict_types=1);

namespace Whity\Auth;

use Psr\Log\LoggerInterface;
use Whity\Database\Database;

/**
 * Resolves whether admin-enforced 2FA policy (WC-525) applies to a profile,
 * and — when it does — the strictest (earliest) enrollment deadline across
 * every applicable policy.
 *
 * A policy applies to a profile via any of three scopes, all tenant scoped:
 *  1. `scope_type = 'tenant'` — every membership in the tenant.
 *  2. `scope_type = 'ou'` — the profile's membership OU or any ANCESTOR of it
 *     (mirrors {@see RoleChecker}'s OU-parent-chain walk: same
 *     `organizational_units.parent_id` traversal, same cycle/depth guards).
 *  3. `scope_type = 'user'` — the profile directly (`scope_id` = profile id).
 *
 * When a profile sits under more than one applicable policy (e.g. tenant-wide
 * AND an OU policy AND a user-specific one), the STRICTEST wins, fail-secure:
 * enforcement is required if ANY applicable policy says so, and the deadline
 * is the EARLIEST `created_at + grace_period_days` among them.
 *
 * The deadline is computed, not stored — this class is the only place that
 * combines a policy row with the current time, so {@see AuthHandler} (the
 * single session-issuing chokepoint, WC-525 PR-2) never duplicates the
 * arithmetic.
 */
final class TwoFactorPolicyResolver
{
    private const MAX_HIERARCHY_DEPTH = 64;

    private Database $db;
    private ?LoggerInterface $logger;

    public function __construct(Database $db, ?LoggerInterface $logger = null)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Whether ANY policy applies to this profile's membership in this tenant.
     *
     * @param int      $tenantId  The resolved tenant id.
     * @param int      $profileId The profile id.
     * @param int|null $ouId      The profile's membership OU id, or null when
     *                            the membership has no OU assignment.
     */
    public function isEnforced(int $tenantId, int $profileId, ?int $ouId): bool
    {
        return $this->applicablePolicies($tenantId, $profileId, $ouId) !== [];
    }

    /**
     * The strictest (earliest) enrollment deadline across every applicable
     * policy, as a unix timestamp, or null when no policy applies.
     *
     * A grace period of 0 days yields a deadline equal to the policy's
     * creation time — i.e. enforced immediately.
     *
     * @param int      $tenantId  The resolved tenant id.
     * @param int      $profileId The profile id.
     * @param int|null $ouId      The profile's membership OU id, or null.
     */
    public function enforcementDeadline(int $tenantId, int $profileId, ?int $ouId): ?int
    {
        $policies = $this->applicablePolicies($tenantId, $profileId, $ouId);
        if ($policies === []) {
            return null;
        }

        $deadlines = array_map(
            static fn(array $policy): int => $policy['created_at_epoch'] + $policy['grace_period_days'] * 86400,
            $policies
        );

        return min($deadlines);
    }

    /**
     * Every policy row (tenant-wide, OU-chain, and user-specific) that applies
     * to this profile, tenant scoped.
     *
     * created_at is read as the raw driver string and parsed with strtotime()
     * in PHP rather than a SQL epoch function — this codebase's convention
     * (see {@see AuthHandler}) avoids driver-specific date SQL (Postgres'
     * EXTRACT() has no SQLite equivalent, and the real-engine test suite runs
     * this exact query against SQLite).
     *
     * @return list<array{created_at_epoch: int, grace_period_days: int}>
     */
    private function applicablePolicies(int $tenantId, int $profileId, ?int $ouId): array
    {
        $scopeConditions = ["(scope_type = 'tenant')"];
        $params = [':tenantId' => $tenantId];

        $scopeConditions[] = '(scope_type = \'user\' AND scope_id = :profileId)';
        $params[':profileId'] = $profileId;

        $ouChainIds = $ouId !== null ? $this->buildOuChainIds($ouId, $tenantId) : [];
        if ($ouChainIds !== []) {
            $placeholders = [];
            foreach ($ouChainIds as $index => $chainOuId) {
                $key = ':ouId' . $index;
                $placeholders[] = $key;
                $params[$key] = $chainOuId;
            }
            $scopeConditions[] = '(scope_type = \'ou\' AND scope_id IN (' . implode(', ', $placeholders) . '))';
        }

        $statement = $this->db->query(
            'SELECT grace_period_days, created_at
             FROM two_factor_policies
             WHERE tenant_id = :tenantId AND (' . implode(' OR ', $scopeConditions) . ')',
            $params
        );
        $results = $statement->fetchAll();

        if ($results === []) {
            return [];
        }

        return array_map(
            static function (array $row): array {
                $epoch = strtotime((string) $row['created_at']);
                return [
                    'created_at_epoch' => $epoch !== false ? $epoch : time(),
                    'grace_period_days' => (int) $row['grace_period_days'],
                ];
            },
            $results
        );
    }

    /**
     * Build the full OU-chain ids list starting from $ouId, walking up to the
     * root via `organizational_units.parent_id`. Mirrors
     * {@see RoleChecker::buildOuChainIds()} exactly — same visited-set cycle
     * detection and {@see self::MAX_HIERARCHY_DEPTH} bound — so a malformed OU
     * hierarchy can never hang policy resolution either.
     *
     * @return array<int, int> Distinct OU ids (own OU + ancestors).
     */
    private function buildOuChainIds(int $ouId, int $tenantId): array
    {
        $ouIds = [];
        $visited = [];
        $currentOuId = $ouId;
        $depth = 0;

        while ($currentOuId !== null) {
            if (isset($visited[$currentOuId])) {
                $this->warnCircularOuChain($ouId, $currentOuId, $tenantId, array_keys($visited));
                break;
            }

            if ($depth >= self::MAX_HIERARCHY_DEPTH) {
                $this->warnOuMaxDepthExceeded($ouId, $tenantId);
                break;
            }

            $visited[$currentOuId] = true;
            $depth++;
            $ouIds[$currentOuId] = true;

            $currentOuId = $this->getParentOuId($currentOuId, $tenantId);
        }

        return array_keys($ouIds);
    }

    private function getParentOuId(int $ouId, int $tenantId): ?int
    {
        $statement = $this->db->query(
            'SELECT parent_id FROM organizational_units WHERE id = :ouId AND tenant_id = :tenantId',
            [':ouId' => $ouId, ':tenantId' => $tenantId]
        );
        $result = $statement->fetch();

        if ($result === false || $result['parent_id'] === null) {
            return null;
        }

        return (int) $result['parent_id'];
    }

    /**
     * @param array<int, int> $chain The visited OU-id chain leading to the cycle.
     */
    private function warnCircularOuChain(int $startOuId, int $repeatOuId, int $tenantId, array $chain): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->warning('Circular organizational-unit hierarchy detected; 2FA policy OU resolution terminated', [
            'event' => 'auth.two_factor_policy.ou_hierarchy_cycle_detected',
            'tenant_id' => $tenantId,
            'start_ou_id' => $startOuId,
            'repeated_ou_id' => $repeatOuId,
            'visited_chain' => $chain,
        ]);
    }

    private function warnOuMaxDepthExceeded(int $startOuId, int $tenantId): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->warning('Organizational-unit hierarchy exceeded maximum depth; 2FA policy OU resolution terminated', [
            'event' => 'auth.two_factor_policy.ou_hierarchy_max_depth_exceeded',
            'tenant_id' => $tenantId,
            'start_ou_id' => $startOuId,
            'max_depth' => self::MAX_HIERARCHY_DEPTH,
        ]);
    }
}
