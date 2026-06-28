<?php

declare(strict_types=1);

namespace Whity\Core\Audit;

use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Tenant\TenantContext;

/**
 * AuditLogger writes the platform's security audit trail (WC-34).
 *
 * It is the SINGLE writer for the `audit_log` table: security-relevant actions
 * (logins, 2FA changes, role/permission/tenant/user/OU mutations) flow through
 * {@see self::record()} rather than scattering ad-hoc INSERTs across handlers.
 * Two wiring paths feed it:
 *
 *  1. Event subscription ({@see self::subscribe()}) — the CRUD handlers already
 *     fire `role.*`, `user.*`, `tenant.*`, `ou.*` hooks via the {@see HookManager}.
 *     This logger subscribes to the post-action events at a low priority (runs
 *     after the default listeners) and turns each into an audit row. No handler
 *     needs to know the audit subsystem exists.
 *  2. Explicit calls — the auth/2FA endpoints do not fire hooks, so they call
 *     {@see self::record()} directly for login success/failure and 2FA changes.
 *
 * Design guarantees:
 *  - Tenant scoping: every row carries a `tenant_id`. It is taken from the hook
 *    context / current {@see TenantContext}, falling back to the system tenant (0)
 *    for pre-auth actions (e.g. a failed login) where no tenant is resolved.
 *  - Actor + IP: resolved from {@see AuditContext} (set per request by the HTTP
 *    layer) unless explicitly provided to {@see self::record()} (the auth path,
 *    which knows the user before the request context is populated).
 *  - No secrets/PII: {@see self::sanitizeMetadata()} drops any key that could
 *    carry a password hash, TOTP secret/code or backup code before it is stored.
 *  - Fail-soft: a write failure is logged via the PSR-3 logger and swallowed —
 *    auditing must never break the user-facing action it is recording.
 *
 * Worker safety: this is process-scoped infrastructure (a single instance shared
 * across the requests a FrankenPHP worker serves). It holds only its collaborators
 * (PDO, logger), never per-request state — the request-specific actor/IP live in
 * the reset-between-requests {@see AuditContext}.
 */
final class AuditLogger implements AuditLoggerInterface
{
    /**
     * The system tenant id, used as the owning tenant for pre-auth / system
     * actions that have no resolved tenant (e.g. a failed login).
     */
    private const SYSTEM_TENANT_ID = 0;

    /**
     * Metadata keys that must NEVER be persisted (case-insensitive). Any key
     * containing one of these substrings is dropped by {@see self::sanitizeMetadata()}
     * so password hashes, TOTP secrets/codes and backup codes can never leak into
     * the audit trail.
     *
     * @var list<string>
     */
    private const FORBIDDEN_METADATA_KEYS = [
        'password',
        'secret',
        'token',
        'code',
        'hash',
        'backup_code',
        'two_factor_secret',
    ];

    private PDO $db;

    /**
     * PSR-3 logger used only to report a failed audit write. Defaults to a
     * {@see NullLogger} so tests stay output-clean.
     */
    private LoggerInterface $logger;

    /**
     * @param PDO                  $db     Database connection.
     * @param LoggerInterface|null $logger Optional PSR-3 logger for write failures.
     */
    public function __construct(PDO $db, ?LoggerInterface $logger = null)
    {
        $this->db = $db;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Record an audit entry.
     *
     * All inputs are optional except the action key. The tenant id, actor and IP
     * default to the current request context when not supplied. Metadata is
     * sanitized before storage. The method is fail-soft: any error is logged and
     * swallowed so it can never interrupt the action being audited.
     *
     * @param string                $action     Stable action key (e.g. `auth.login.success`).
     * @param array{
     *     tenant_id?: int|null,
     *     actor_user_id?: int|null,
     *     target_type?: string|null,
     *     target_id?: int|null,
     *     metadata?: array<string, mixed>,
     *     ip_address?: string|null
     * } $options Optional fields. Anything omitted is resolved from context.
     * @return void
     */
    public function record(string $action, array $options = []): void
    {
        try {
            $tenantId = $this->resolveTenantId($options['tenant_id'] ?? null);
            $actorUserId = array_key_exists('actor_user_id', $options)
                ? $options['actor_user_id']
                : AuditContext::getActorUserId();
            $ipAddress = array_key_exists('ip_address', $options)
                ? $options['ip_address']
                : AuditContext::getIpAddress();

            $targetType = $options['target_type'] ?? null;
            $targetId = $options['target_id'] ?? null;
            $metadata = $this->sanitizeMetadata($options['metadata'] ?? []);

            $encodedMetadata = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encodedMetadata === false) {
                $encodedMetadata = '{}';
            }

            $stmt = $this->db->prepare(
                'INSERT INTO audit_log
                    (tenant_id, actor_user_id, action, target_type, target_id, metadata, ip_address, created_at)
                 VALUES (:tenant_id, :actor_user_id, :action, :target_type, :target_id, :metadata, :ip_address, NOW())'
            );
            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':actor_user_id' => $actorUserId,
                ':action' => $action,
                ':target_type' => $targetType,
                ':target_id' => $targetId,
                ':metadata' => $encodedMetadata,
                ':ip_address' => $ipAddress,
            ]);
        } catch (\Throwable $e) {
            // Auditing must never break the action it records. Log and move on.
            $this->logger->error('Failed to write audit log entry', [
                'event' => 'audit.write_failed',
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Subscribe this logger to the core CRUD lifecycle hooks.
     *
     * Each post-action event is mapped to a stable audit action key and a target
     * type/id derived from the hook payload. Listeners run at a high priority
     * number (low precedence) so they execute AFTER the handlers' own listeners,
     * and they always return the data unchanged so the filter chain is never
     * disturbed (see HOOK_SYSTEM.md best practices).
     *
     * @param HookManager $hooks The hook manager to subscribe to.
     * @return void
     */
    public function subscribe(HookManager $hooks): void
    {
        // Priority 50: well after the default (10) handler listeners.
        $priority = 50;

        // Map of hook event => [audit action, target type, payload id key].
        $crudEvents = [
            'role.created'    => ['role.created', 'role', 'id'],
            'role.updated'    => ['role.updated', 'role', 'id'],
            'role.deleted'    => ['role.deleted', 'role', 'id'],
            'user.created'    => ['user.created', 'user', 'id'],
            'user.updated'    => ['user.updated', 'user', 'id'],
            'user.deleted'    => ['user.deleted', 'user', 'id'],
            'tenant.created'  => ['tenant.created', 'tenant', 'id'],
            'tenant.updated'  => ['tenant.updated', 'tenant', 'id'],
            'tenant.deleted'  => ['tenant.deleted', 'tenant', 'id'],
            'ou.created'      => ['ou.created', 'ou', 'id'],
            'ou.updated'      => ['ou.updated', 'ou', 'id'],
            'ou.deleted'      => ['ou.deleted', 'ou', 'id'],
        ];

        foreach ($crudEvents as $event => [$action, $targetType, $idKey]) {
            $hooks->listen($event, function (array $data, array $context) use ($action, $targetType, $idKey): array {
                $this->recordFromHook($action, $targetType, $idKey, $data, $context);
                return $data;
            }, $priority);
        }

        // OU role assignment / removal carry both an ou_id and a role_id; the OU
        // is the audit target and the role id is captured in metadata.
        $hooks->listen('ou.role_assigned', function (array $data, array $context): array {
            $this->recordOuRoleChange('ou.role_assigned', $data, $context);
            return $data;
        }, $priority);

        $hooks->listen('ou.role_removed', function (array $data, array $context): array {
            $this->recordOuRoleChange('ou.role_removed', $data, $context);
            return $data;
        }, $priority);
    }

    /**
     * Translate a generic CRUD hook payload into an audit record.
     *
     * @param string               $action     The audit action key.
     * @param string               $targetType The audited entity type.
     * @param string               $idKey      The payload key holding the target id.
     * @param array<string, mixed> $data       The hook payload.
     * @param array<string, mixed> $context    The hook context (carries tenant_id).
     * @return void
     */
    private function recordFromHook(string $action, string $targetType, string $idKey, array $data, array $context): void
    {
        $targetId = isset($data[$idKey]) && is_numeric($data[$idKey]) ? (int) $data[$idKey] : null;

        // Tenant: prefer the payload's tenant_id (the affected resource's tenant),
        // then the hook context, then the system tenant.
        $tenantId = null;
        if (isset($data['tenant_id']) && is_numeric($data['tenant_id'])) {
            $tenantId = (int) $data['tenant_id'];
        } elseif (isset($context['tenant_id']) && is_int($context['tenant_id'])) {
            $tenantId = $context['tenant_id'];
        }

        $this->record($action, [
            'tenant_id' => $tenantId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'metadata' => $this->hookMetadata($data),
        ]);
    }

    /**
     * Translate an OU role assignment/removal hook payload into an audit record.
     *
     * @param string               $action  The audit action key.
     * @param array<string, mixed> $data    The hook payload (ou_id, role_id, tenant_id).
     * @param array<string, mixed> $context The hook context.
     * @return void
     */
    private function recordOuRoleChange(string $action, array $data, array $context): void
    {
        $ouId = isset($data['ou_id']) && is_numeric($data['ou_id']) ? (int) $data['ou_id'] : null;
        $roleId = isset($data['role_id']) && is_numeric($data['role_id']) ? (int) $data['role_id'] : null;

        $tenantId = null;
        if (isset($data['tenant_id']) && is_numeric($data['tenant_id'])) {
            $tenantId = (int) $data['tenant_id'];
        } elseif (isset($context['tenant_id']) && is_int($context['tenant_id'])) {
            $tenantId = $context['tenant_id'];
        }

        $this->record($action, [
            'tenant_id' => $tenantId,
            'target_type' => 'ou',
            'target_id' => $ouId,
            'metadata' => ['role_id' => $roleId],
        ]);
    }

    /**
     * Build sanitized metadata from a CRUD hook payload.
     *
     * Drops the id/tenant_id (already first-class columns) and any secret/PII
     * keys, keeping the remaining scalar context (e.g. a role name, an OU slug).
     *
     * @param array<string, mixed> $data The hook payload.
     * @return array<string, mixed> The metadata to store.
     */
    private function hookMetadata(array $data): array
    {
        unset($data['id'], $data['tenant_id'], $data['_context']);

        return $this->sanitizeMetadata($data);
    }

    /**
     * Resolve the owning tenant id for a record.
     *
     * Order: explicit value, then the current request's {@see TenantContext},
     * then the system tenant (0) for pre-auth/system actions. Never fails closed
     * here — an unresolved audit row is still worth keeping under the system
     * tenant rather than being dropped.
     *
     * @param int|null $explicit An explicitly supplied tenant id, if any.
     * @return int The resolved tenant id.
     */
    private function resolveTenantId(?int $explicit): int
    {
        if ($explicit !== null) {
            return $explicit;
        }

        $current = TenantContext::getTenantId();
        if ($current !== null) {
            return $current;
        }

        return self::SYSTEM_TENANT_ID;
    }

    /**
     * Strip secret/PII keys from a metadata array before it is stored.
     *
     * Any key whose (lower-cased) name contains a forbidden substring is dropped,
     * and the filter recurses into nested arrays so a buried secret is removed
     * too. Non-scalar, non-array values are dropped to keep metadata serializable
     * and small.
     *
     * @param array<string, mixed> $metadata The raw metadata.
     * @return array<string, mixed> The sanitized metadata.
     */
    private function sanitizeMetadata(array $metadata): array
    {
        $clean = [];

        foreach ($metadata as $key => $value) {
            // PHP may surface a numeric-string key as an int; normalise to string
            // before the case-insensitive forbidden-substring check.
            $keyName = strtolower((string) $key);

            $forbidden = false;
            foreach (self::FORBIDDEN_METADATA_KEYS as $needle) {
                if (str_contains($keyName, $needle)) {
                    $forbidden = true;
                    break;
                }
            }
            if ($forbidden) {
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->sanitizeMetadata($value);
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            }
            // Objects/resources are intentionally dropped.
        }

        return $clean;
    }
}
