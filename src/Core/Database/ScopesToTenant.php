<?php

declare(strict_types=1);

namespace Whity\Core\Database;

use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * Automatic tenant-scoped query filtering.
 *
 * This trait is the platform's tenant-isolation enforcement layer at the query
 * level. A model or repository that `use`s it inherits helpers that transparently
 * append a parameterised `tenant_id` predicate to SELECT/UPDATE/DELETE statements
 * and auto-populate `tenant_id` on INSERTs, so developers do not have to remember
 * to filter by tenant on every query.
 *
 * Tenant ids are INTEGERS in this codebase (the acceptance-criteria example uses a
 * string id; the deviation is intentional — see WC-18 {@see TenantContext}). The
 * special tenant id 0 denotes the SYSTEM tenant and is a fully valid, scopeable
 * value distinct from the "unresolved" (null) state.
 *
 * Security model (no silent fallback):
 *  - System mode ON ({@see TenantContext::isSystemMode()}): scoping is bypassed and
 *    the statement runs system-wide, unchanged. System mode is only enabled by
 *    trusted, non-request contexts (migrations, admin CLI) and is audit-logged at
 *    the source.
 *  - System mode OFF and tenant UNRESOLVED (id === null): the query is REFUSED with
 *    a {@see TenantScopeException} (fail closed). Running without a tenant filter
 *    would leak every tenant's rows, so we never guess a default.
 *  - System mode OFF and a tenant is resolved: the tenant predicate is injected
 *    using a bound parameter — the tenant id is NEVER string-interpolated into SQL.
 *  - A statement whose shape cannot be safely rewritten is REFUSED rather than run
 *    unscoped or rewritten into something broken.
 *
 * Usage:
 * ```php
 * class UserRepository
 * {
 *     use ScopesToTenant;
 *
 *     public function __construct(private Database $db) {}
 *
 *     public function all(): array
 *     {
 *         // With TenantContext set to N, runs: SELECT * FROM users WHERE tenant_id = :param
 *         return $this->tenantScopedQuery($this->db, 'SELECT * FROM users')->fetchAll();
 *     }
 * }
 * ```
 */
trait ScopesToTenant
{
    /**
     * Name of the tenant column injected into / populated on statements.
     *
     * Override in the consuming class if the tenant column is named differently.
     */
    protected string $tenantColumn = 'tenant_id';

    /**
     * Reserved bound-parameter placeholder for the injected tenant id.
     *
     * Prefixed to make a collision with a caller-supplied parameter improbable;
     * a collision is still detected and rejected (see scope methods).
     */
    private string $tenantScopeParam = 'whity_scope_tenant_id';

    /**
     * Run a tenant-scoped query through the connection manager.
     *
     * The statement is rewritten according to the security model documented on
     * the trait, then executed via {@see Database::query()} with the tenant id
     * bound as a parameter (never interpolated).
     *
     * @param Database              $db     Worker-scoped connection manager.
     * @param string                $sql    SELECT/UPDATE/DELETE/INSERT statement.
     * @param array<string, mixed>  $params Caller-supplied bound parameters.
     * @return \PDOStatement Executed statement.
     * @throws TenantScopeException If the context is unresolved or the SQL cannot
     *                              be safely scoped.
     */
    protected function tenantScopedQuery(Database $db, string $sql, array $params = []): \PDOStatement
    {
        [$scopedSql, $scopedParams] = $this->applyTenantScope($sql, $params);

        return $db->query($scopedSql, $scopedParams);
    }

    /**
     * Rewrite a statement to enforce tenant scoping and return the scoped SQL
     * together with the (possibly augmented) parameter array.
     *
     * Exposed (protected) so callers that need the rewritten SQL without executing
     * it — or that drive a different execution path — can reuse the same logic.
     *
     * @param string               $sql    SELECT/UPDATE/DELETE/INSERT statement.
     * @param array<string, mixed> $params Caller-supplied bound parameters.
     * @return array{0: string, 1: array<string, mixed>} [scopedSql, scopedParams].
     * @throws TenantScopeException If the context is unresolved or the SQL cannot
     *                              be safely scoped.
     */
    protected function applyTenantScope(string $sql, array $params = []): array
    {
        // System mode: trusted cross-tenant operation; do not scope.
        if (TenantContext::isSystemMode()) {
            return [$sql, $params];
        }

        // Fail closed: never run a query unscoped when no tenant is resolved.
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            throw TenantScopeException::unresolvedContext();
        }

        $type = $this->statementType($sql);

        return match ($type) {
            'select', 'update', 'delete' => $this->injectWherePredicate($sql, $params, $tenantId),
            'insert' => $this->injectInsertTenant($sql, $params, $tenantId),
            default => throw TenantScopeException::unsupportedStatement(
                'unrecognised or unsupported statement type'
            ),
        };
    }

    /**
     * Determine the leading statement keyword (lower-cased), ignoring leading
     * whitespace and SQL comments.
     *
     * @param string $sql The raw SQL.
     * @return string One of select|update|delete|insert, or '' if unrecognised.
     */
    private function statementType(string $sql): string
    {
        $trimmed = ltrim($sql);
        if (preg_match('/^(select|update|delete|insert)\b/i', $trimmed, $m) === 1) {
            return strtolower($m[1]);
        }

        return '';
    }

    /**
     * Inject a parameterised tenant predicate into a SELECT/UPDATE/DELETE.
     *
     * If the statement already has a WHERE clause, the predicate is AND-ed onto
     * the front of it (wrapping the caller's condition in parentheses so operator
     * precedence cannot weaken the tenant filter). Otherwise a WHERE clause is
     * inserted before any trailing GROUP BY / ORDER BY / LIMIT / etc.
     *
     * To keep rewriting deterministic and safe, statements containing a JOIN or a
     * sub-SELECT are refused: a single unqualified `tenant_id` predicate could be
     * ambiguous or under-scope such a statement.
     *
     * @param string               $sql      The statement.
     * @param array<string, mixed> $params   Caller parameters.
     * @param int                  $tenantId Resolved tenant id (0 = system tenant).
     * @return array{0: string, 1: array<string, mixed>}
     * @throws TenantScopeException If the statement shape cannot be safely scoped.
     */
    private function injectWherePredicate(string $sql, array $params, int $tenantId): array
    {
        if (preg_match('/\bjoin\b/i', $sql) === 1) {
            throw TenantScopeException::unsupportedStatement(
                'statements with JOINs require an explicit, table-qualified tenant predicate'
            );
        }

        // A nested SELECT (subquery) means more than one logical row source; a
        // single top-level predicate cannot guarantee isolation of the inner one.
        if (preg_match('/\(\s*select\b/i', $sql) === 1) {
            throw TenantScopeException::unsupportedStatement(
                'statements containing a subquery cannot be auto-scoped safely'
            );
        }

        $placeholder = $this->reserveTenantPlaceholder($params);
        $predicate = sprintf('%s = :%s', $this->tenantColumn, $placeholder);

        // Split off any trailing clauses we must keep AFTER the WHERE condition.
        // (GROUP BY / HAVING / ORDER BY / LIMIT / OFFSET / FOR UPDATE / RETURNING)
        $tailPattern = '/\s+(group\s+by|having|order\s+by|limit|offset|for\s+update|for\s+share|returning)\b/i';

        if (preg_match('/\bwhere\b/i', $sql) === 1) {
            // Merge: WHERE (<existing condition>) AND <tenant predicate>
            $scoped = (string) preg_replace_callback(
                '/\bwhere\b(.*?)(' . substr($tailPattern, 1, -2) . '|$)/is',
                function (array $matches) use ($predicate): string {
                    $existing = trim($matches[1]);
                    $tail = trim($matches[2]);
                    $merged = 'WHERE (' . $existing . ') AND ' . $predicate;
                    return $tail === '' ? $merged : $merged . ' ' . $tail;
                },
                $sql,
                1
            );
        } else {
            // No WHERE: insert one before any trailing clause, else append.
            if (preg_match($tailPattern, $sql, $m, PREG_OFFSET_CAPTURE) === 1) {
                $pos = (int) $m[0][1];
                $scoped = rtrim(substr($sql, 0, $pos))
                    . ' WHERE ' . $predicate
                    . ' ' . ltrim(substr($sql, $pos));
            } else {
                $scoped = rtrim(rtrim($sql), ';') . ' WHERE ' . $predicate;
                // Preserve a trailing semicolon if the caller supplied one.
                if (str_ends_with(rtrim($sql), ';')) {
                    $scoped .= ';';
                }
            }
        }

        $params[$placeholder] = $tenantId;

        return [$scoped, $params];
    }

    /**
     * Auto-populate the tenant column on an INSERT.
     *
     * Supports the common `INSERT INTO t (cols...) VALUES (...)` form. If the
     * tenant column is already listed, the caller's value is trusted (the model
     * layer / boundary validation is responsible for it); otherwise the column
     * and a bound tenant parameter are added to every VALUES row.
     *
     * Forms that cannot be safely rewritten (INSERT ... SELECT, INSERT without an
     * explicit column list, INSERT ... SET) are refused so we never silently
     * insert a row with the wrong/no tenant.
     *
     * @param string               $sql      The INSERT statement.
     * @param array<string, mixed> $params   Caller parameters.
     * @param int                  $tenantId Resolved tenant id (0 = system tenant).
     * @return array{0: string, 1: array<string, mixed>}
     * @throws TenantScopeException If the INSERT shape cannot be safely scoped.
     */
    private function injectInsertTenant(string $sql, array $params, int $tenantId): array
    {
        if (preg_match('/\)\s*select\b/i', $sql) === 1 || preg_match('/\bvalues\b/i', $sql) !== 1) {
            throw TenantScopeException::unsupportedStatement(
                'only INSERT ... (columns) VALUES (...) can be auto-scoped'
            );
        }

        // Capture the column list: INSERT INTO <table> ( <cols> ) VALUES ...
        if (
            preg_match(
                '/^(\s*insert\s+into\s+[^\s(]+\s*)\(([^)]*)\)(\s*values\b.*)$/is',
                $sql,
                $m
            ) !== 1
        ) {
            throw TenantScopeException::unsupportedStatement(
                'INSERT must have an explicit ( column list ) to be auto-scoped'
            );
        }

        $head = $m[1];
        $columns = array_map('trim', explode(',', $m[2]));
        $valuesClause = $m[3];

        // Already lists the tenant column: trust the caller-provided value.
        foreach ($columns as $col) {
            if (strcasecmp($this->stripIdentifierQuotes($col), $this->tenantColumn) === 0) {
                return [$sql, $params];
            }
        }

        $placeholder = $this->reserveTenantPlaceholder($params);
        $columns[] = $this->tenantColumn;

        // Append the tenant placeholder to each VALUES (...) tuple.
        $rewrittenValues = preg_replace_callback(
            '/\(([^()]*)\)/',
            function (array $matches) use ($placeholder): string {
                $inner = trim($matches[1]);
                $sep = $inner === '' ? '' : ', ';
                return '(' . $inner . $sep . ':' . $placeholder . ')';
            },
            $valuesClause
        );

        if (!is_string($rewrittenValues)) {
            throw TenantScopeException::unsupportedStatement('failed to rewrite VALUES tuples');
        }

        $scoped = $head . '(' . implode(', ', $columns) . ')' . $rewrittenValues;
        $params[$placeholder] = $tenantId;

        return [$scoped, $params];
    }

    /**
     * Return a tenant-scope placeholder name, guarding against a collision with a
     * caller-supplied parameter of the same name.
     *
     * @param array<string, mixed> $params Caller parameters.
     * @return string The reserved placeholder name (no leading colon).
     * @throws TenantScopeException If the caller already uses the reserved name.
     */
    private function reserveTenantPlaceholder(array $params): string
    {
        $name = $this->tenantScopeParam;
        if (array_key_exists($name, $params) || array_key_exists(':' . $name, $params)) {
            throw TenantScopeException::reservedParameterCollision($name);
        }

        return $name;
    }

    /**
     * Strip surrounding identifier quotes ("col" or `col`) from a column token.
     *
     * @param string $identifier The raw column token.
     * @return string The unquoted identifier.
     */
    private function stripIdentifierQuotes(string $identifier): string
    {
        return trim($identifier, "\"` \t");
    }

    /**
     * Boot hook retained for ORM/reflection-based loaders.
     *
     * Reflection-driven model initialisation (see the WC-7 reflection loader)
     * invokes `boot<TraitName>()` if present. There is no global query-scope
     * registry to attach to yet, so this is intentionally a no-op; the active
     * enforcement path is {@see tenantScopedQuery()} / {@see applyTenantScope()}.
     *
     * @return void
     */
    protected static function bootScopesToTenant(): void
    {
        // No-op: scoping is performed explicitly via tenantScopedQuery() /
        // applyTenantScope(). Kept so reflection-based boot does not break.
    }

    /**
     * Populate this record's tenant column from the current context before persist.
     *
     * If the record's tenant column is unset, it is filled from
     * {@see TenantContext}. System mode is honoured: a system-mode persist with no
     * explicit tenant leaves the column null (system-owned). Outside system mode,
     * an unresolved context fails closed.
     *
     * @return void
     * @throws TenantScopeException If the context is unresolved (and not system mode).
     */
    protected function setTenantIdBeforePersist(): void
    {
        if ($this->tenant_id !== null) {
            return;
        }

        if (TenantContext::isSystemMode()) {
            return;
        }

        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            throw TenantScopeException::unresolvedContext();
        }

        $this->tenant_id = $tenantId;
    }

    /**
     * Validate that this record belongs to the current tenant.
     *
     * A cross-tenant access attempt (record tenant != context tenant) is a hard
     * failure. System mode bypasses the check (trusted cross-tenant operation).
     *
     * @return void
     * @throws TenantScopeException If the boundary is violated or the context is
     *                              unresolved (and not system mode).
     */
    protected function validateTenantBoundary(): void
    {
        if (TenantContext::isSystemMode()) {
            return;
        }

        $currentTenant = TenantContext::getTenantId();
        if ($currentTenant === null) {
            throw TenantScopeException::unresolvedContext();
        }

        if ($this->tenant_id !== $currentTenant) {
            throw TenantScopeException::unsupportedStatement(
                sprintf(
                    'tenant boundary violation: record belongs to tenant %s, context is tenant %d',
                    var_export($this->tenant_id, true),
                    $currentTenant
                )
            );
        }
    }
}
