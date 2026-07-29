<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Queue\JobRegistry;
use Whity\Core\Queue\JobRepository;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Http\PaginationParams;

/**
 * Generic async-job submission + status API (WC-jobs-api, #3fb69c97).
 *
 *   POST /api/jobs           submit an allow-listed job for this tenant (jobs:submit)
 *   GET  /api/jobs           list this tenant's jobs, paginated (jobs:read)
 *   GET  /api/jobs/{id}      read one job's status/progress/result (jobs:read)
 *
 * SECURITY — fail-closed submission. Submission does NOT let a caller run any
 * registered handler: a job name is accepted only if its handler explicitly
 * opted into public submission ({@see JobRegistry::isSubmittable()}). An unknown
 * or internal-only name is rejected with a generic 422 that never reveals which
 * names exist. Every job is stamped with the caller's tenant (TenantContext) and
 * enqueued with result retention so it can be polled; GET is tenant-scoped, so a
 * foreign-tenant id is 404 — never a cross-tenant existence leak. Error bodies
 * are generic; raw exceptions are never surfaced.
 */
final class JobsApiHandler
{
    private const MAX_NAME_LENGTH = 191;
    private const MAX_QUEUE_LENGTH = 64;
    private const MAX_KEY_LENGTH = 191;

    /** Valid values for the optional ?status= list filter. */
    private const STATUSES = ['pending', 'reserved', 'dead', 'completed'];

    private JobRepository $jobs;
    private JobRegistry $registry;
    private RoleChecker $roleChecker;

    public function __construct(JobRepository $jobs, JobRegistry $registry, RoleChecker $roleChecker)
    {
        $this->jobs = $jobs;
        $this->registry = $registry;
        $this->roleChecker = $roleChecker;
    }

    /**
     * POST /api/jobs — enqueue a submittable job for the caller's tenant.
     */
    public function create(Request $request): Response
    {
        $auth = $this->authorize($request, CorePermissions::JOBS_SUBMIT);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$tenantId] = $auth;

        $body = JsonBody::parsed($request);

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            return Response::error('name is required and must be at most 191 characters', 422);
        }
        // Fail-closed: only handlers that opted into public submission may be
        // enqueued here. Generic message — do not disclose the allow-list.
        if (!$this->registry->isSubmittable($name)) {
            return Response::error('Unknown or non-submittable job type', 422);
        }

        $payload = $body['payload'] ?? [];
        if (!is_array($payload)) {
            return Response::error('payload must be an object', 422);
        }

        $queue = 'default';
        if (isset($body['queue']) && $body['queue'] !== '') {
            $queue = (string) $body['queue'];
            if (mb_strlen($queue) > self::MAX_QUEUE_LENGTH || preg_match('/^[a-z0-9_-]+$/', $queue) !== 1) {
                return Response::error('queue must be 1-64 chars of [a-z0-9_-]', 422);
            }
        }

        $key = null;
        if (isset($body['idempotency_key']) && $body['idempotency_key'] !== '') {
            if (!is_string($body['idempotency_key']) || mb_strlen($body['idempotency_key']) > self::MAX_KEY_LENGTH) {
                return Response::error('idempotency_key must be a string of at most 191 characters', 422);
            }
            $key = $body['idempotency_key'];
        }

        /** @var array<string, mixed> $payload */
        $id = $this->jobs->enqueue($tenantId, $name, $payload, [
            'queue'           => $queue,
            'idempotency_key' => $key,
            'retain_result'   => true,
        ]);

        if ($id === null) {
            // Deduped: a live job with this (tenant, idempotency_key) already
            // exists — return it (200), so a retried submit is safe and returns
            // the same job rather than creating a duplicate.
            $existing = $key !== null ? $this->jobs->findByIdempotencyKey($tenantId, $key) : null;
            if ($existing === null) {
                return Response::error('A job with this idempotency key already exists', 409);
            }

            return Response::json(['data' => $existing], 200);
        }

        return Response::json(['data' => $this->jobs->find($tenantId, $id)], 201);
    }

    /**
     * GET /api/jobs — list the caller's tenant's jobs (paginated).
     */
    public function list(Request $request): Response
    {
        $auth = $this->authorize($request, CorePermissions::JOBS_READ);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$tenantId] = $auth;

        $query = self::queryParams($request);
        $queue = isset($query['queue']) && $query['queue'] !== '' ? $query['queue'] : null;
        $status = isset($query['status']) && $query['status'] !== '' ? $query['status'] : null;
        if ($status !== null && !in_array($status, self::STATUSES, true)) {
            return Response::error('Invalid status filter', 422, ['allowed' => self::STATUSES]);
        }

        $p = PaginationParams::fromPath($request->getPath());
        $total = $this->jobs->countForTenant($tenantId, $queue, $status);
        $data = $this->jobs->listForTenant($tenantId, $queue, $status, $p->perPage, $p->offset);

        return Response::json(['data' => $data, 'pagination' => $p->meta($total)]);
    }

    /**
     * GET /api/jobs/{id} — read one job scoped to the caller's tenant.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $auth = $this->authorize($request, CorePermissions::JOBS_READ);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$tenantId] = $auth;

        $job = $this->jobs->find($tenantId, (int) ($params['id'] ?? 0));
        if ($job === null) {
            return Response::error('Job not found', 404);
        }

        return Response::json(['data' => $job]);
    }

    /**
     * Query params from $_GET (production) merged with the path query string
     * (tests), as string values.
     *
     * @return array<string, string>
     */
    private static function queryParams(Request $request): array
    {
        $query = [];
        foreach ($_GET as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $query[$k] = $v;
            }
        }
        $qs = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($qs) && $qs !== '') {
            parse_str($qs, $parsed);
            foreach ($parsed as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $query[$k] = $v;
                }
            }
        }

        return $query;
    }

    /**
     * @return array{0: int, 1: int}|Response
     */
    private function authorize(Request $request, string $permission): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $actor = $request->user;
        $userId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;
        if ($userId === null || !$this->roleChecker->hasPermissionForProfile($userId, $permission, $tenantId)) {
            return Response::error('Insufficient permissions', 403, ['required' => $permission]);
        }

        return [$tenantId, $userId];
    }
}
