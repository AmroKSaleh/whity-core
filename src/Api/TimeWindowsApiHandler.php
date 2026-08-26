<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\TimeWindow\TimeWindowRepository;
use Whity\Core\TimeWindow\WindowCloseReporter;
use Whity\Core\TimeWindow\WindowRejectedException;
use Whity\Core\TimeWindow\WindowState;
use Whity\Core\TimeWindow\WindowTypeRepository;
use Whity\Http\InputLimits;
use Whity\Http\JsonBody;

/**
 * The periods themselves (#1070): listing, resolving, creating, adjusting,
 * closing and reopening.
 *
 * THERE IS NO DELETE, AND THAT IS DELIBERATE. A period is what records were
 * scoped to and rolled up by; removing one makes every roll-up that referenced
 * it unreproducible, and there is no version of "we closed the books on a period
 * that no longer exists" that means anything. A period created in error while
 * still empty can have its boundaries corrected; one that has been closed is
 * history.
 *
 * RESOLUTION IS A FILTER, NOT A SEPARATE ENDPOINT. `GET ...?type_id=X&on=DATE`
 * IS the question "which period of this kind contains this date", and it answers
 * with zero or one row. Zero is a real answer — no period covers the date — and
 * expressing it as an empty list rather than a 404 keeps the caller from having
 * to distinguish "no such kind" from "no period then".
 *
 * PERMISSIONS: reading on `time_windows:read`, creating and adjusting on
 * `time_windows:write`, closing on `time_windows:close`, reopening on
 * `time_windows:reopen`. Four gates because they are four authorities: an
 * institution will want fewer people able to unseal a period than to seal one,
 * and one grant covering both would make that impossible to express.
 */
final class TimeWindowsApiHandler
{
    public function __construct(
        private readonly TimeWindowRepository $windows,
        private readonly WindowTypeRepository $types,
        private readonly WindowCloseReporter $reporter,
    ) {
    }

    /**
     * GET /api/v1/time-windows — periods, narrowed by kind, state, containing
     * period, and/or a date they must contain.
     */
    public function list(Request $request): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }
            $query = self::queryParams($request);

            $typeId = null;
            if (isset($query['type_id']) && $query['type_id'] !== '') {
                if (preg_match('/^\d+$/', (string) $query['type_id']) !== 1) {
                    return Response::error('type_id must be an integer', 422);
                }
                $typeId = (int) $query['type_id'];
                if ($this->types->find($tenantId, $typeId) === null) {
                    return Response::error('type_id does not name a period kind in this tenant', 422);
                }
            }

            $state = null;
            if (isset($query['state']) && $query['state'] !== '') {
                $state = (string) $query['state'];
                if (!WindowState::isState($state)) {
                    return Response::error(
                        "state must be one of: " . implode(', ', WindowState::states()),
                        422
                    );
                }
            }

            $onDate = null;
            if (isset($query['on']) && $query['on'] !== '') {
                $onDate = TimeWindowRepository::normalizeDate($query['on'], 'on');
            }

            $parentId = null;
            if (isset($query['parent_id']) && $query['parent_id'] !== '') {
                if (preg_match('/^\d+$/', (string) $query['parent_id']) !== 1) {
                    return Response::error('parent_id must be an integer', 422);
                }
                $parentId = (int) $query['parent_id'];
            }

            return Response::json([
                'data' => $this->windows->listForTenant($tenantId, $typeId, $state, $onDate, $parentId),
            ]);
        } catch (WindowRejectedException $e) {
            // ->clientMessage, never ->getMessage(): only text somebody wrote FOR
            // a caller reaches a caller, and giving it its own field is what
            // makes that structural rather than a habit. See the exception's
            // docblock, and WC-186. Every catch site below follows suit.
            return Response::error($e->clientMessage, 422);
        } catch (\Exception $e) {
            error_log('[TimeWindowsApiHandler] list failed: ' . $e->getMessage());

            return Response::error('Failed to fetch time windows', 500);
        }
    }

    /**
     * GET /api/v1/time-windows/{id} — one period, with its seal trail.
     *
     * The trail travels with the period rather than living behind a second
     * request, because the two facts are never wanted apart: "is this closed" is
     * only half an answer without "and has it ever been reopened, by whom, and
     * why".
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }
            $id = (int) ($params['id'] ?? 0);
            $window = $this->windows->find($tenantId, $id);
            if ($window === null) {
                return Response::error('Time window not found', 404);
            }

            return Response::json([
                'data' => $window + ['trail' => $this->windows->trail($tenantId, $id)],
            ]);
        } catch (\Exception $e) {
            error_log('[TimeWindowsApiHandler] show failed: ' . $e->getMessage());

            return Response::error('Failed to fetch time window', 500);
        }
    }

    /**
     * POST /api/v1/time-windows — define a period, with explicit boundaries.
     */
    public function create(Request $request): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }
            $body = JsonBody::parsed($request);

            $typeId = self::intField($body, 'window_type_id');
            if ($typeId === null) {
                return Response::error('window_type_id is required and must be an integer', 422);
            }

            $key = trim((string) ($body['key'] ?? ''));
            if ($key === '') {
                return Response::error('key is required', 422);
            }
            $label = array_key_exists('label', $body) ? trim((string) $body['label']) : $key;
            if ($label === '') {
                $label = $key;
            }
            if ($tooLong = InputLimits::firstViolation([
                'key' => [$key, InputLimits::NAME_MAX],
                'label' => [$label, InputLimits::NAME_MAX],
            ])) {
                return $tooLong;
            }

            $parentWindowId = array_key_exists('parent_window_id', $body)
                ? self::intField($body, 'parent_window_id')
                : null;
            if (array_key_exists('parent_window_id', $body)
                && $body['parent_window_id'] !== null
                && $parentWindowId === null
            ) {
                return Response::error('parent_window_id must be an integer or null', 422);
            }

            $id = $this->windows->create(
                $tenantId,
                $typeId,
                $parentWindowId,
                $key,
                $label,
                (string) ($body['starts_on'] ?? ''),
                (string) ($body['ends_on'] ?? '')
            );

            return Response::json(['data' => $this->windows->find($tenantId, $id)], 201);
        } catch (WindowRejectedException $e) {
            return Response::error($e->clientMessage, 422);
        } catch (\Exception $e) {
            error_log('[TimeWindowsApiHandler] create failed: ' . $e->getMessage());

            return Response::error('Failed to create time window', 500);
        }
    }

    /**
     * PATCH /api/v1/time-windows/{id} — relabel or move the boundaries.
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }
            $id = (int) ($params['id'] ?? 0);
            if ($this->windows->find($tenantId, $id) === null) {
                return Response::error('Time window not found', 404);
            }

            $body = JsonBody::parsed($request);
            /** @var array{label?: string, starts_on?: string, ends_on?: string, parent_window_id?: int|null} $fields */
            $fields = [];

            if (array_key_exists('label', $body)) {
                $label = trim((string) $body['label']);
                if ($label === '') {
                    return Response::error('label must not be empty', 422);
                }
                if ($tooLong = InputLimits::firstViolation(['label' => [$label, InputLimits::NAME_MAX]])) {
                    return $tooLong;
                }
                $fields['label'] = $label;
            }
            if (array_key_exists('starts_on', $body)) {
                $fields['starts_on'] = (string) $body['starts_on'];
            }
            if (array_key_exists('ends_on', $body)) {
                $fields['ends_on'] = (string) $body['ends_on'];
            }
            if (array_key_exists('parent_window_id', $body)) {
                $parent = self::intField($body, 'parent_window_id');
                if ($body['parent_window_id'] !== null && $parent === null) {
                    return Response::error('parent_window_id must be an integer or null', 422);
                }
                $fields['parent_window_id'] = $parent;
            }
            if ($fields === []) {
                return Response::error('Nothing to update', 422);
            }

            $this->windows->update($tenantId, $id, $fields);

            return Response::json(['data' => $this->windows->find($tenantId, $id)]);
        } catch (WindowRejectedException $e) {
            return Response::error($e->clientMessage, 422);
        } catch (\Exception $e) {
            error_log('[TimeWindowsApiHandler] update failed: ' . $e->getMessage());

            return Response::error('Failed to update time window', 500);
        }
    }

    /**
     * GET /api/v1/time-windows/{id}/close-report — what closing would seal.
     *
     * Gated on read, not on close: being able to see what a period holds before
     * anyone touches it is exactly the information somebody needs in order to
     * ASK for the close, and it changes nothing.
     *
     * @param array<string, string> $params
     */
    public function closeReport(Request $request, array $params): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }
            $id = (int) ($params['id'] ?? 0);
            $report = $this->reporter->report($tenantId, $id);
            if ($report === null) {
                return Response::error('Time window not found', 404);
            }

            return Response::json(['data' => $report->toArray()]);
        } catch (\Exception $e) {
            error_log('[TimeWindowsApiHandler] closeReport failed: ' . $e->getMessage());

            return Response::error('Failed to build the close report', 500);
        }
    }

    /**
     * POST /api/v1/time-windows/{id}/close — seal it.
     *
     * The response carries the report the close was made against, not just the
     * new state. What was still unfinished at the moment of sealing is precisely
     * the fact somebody will want afterwards, and it is unrecoverable once the
     * period is closed and the work moves on.
     *
     * @param array<string, string> $params
     */
    public function close(Request $request, array $params): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }
            $id = (int) ($params['id'] ?? 0);
            $report = $this->reporter->report($tenantId, $id);
            if ($report === null) {
                return Response::error('Time window not found', 404);
            }

            $body = JsonBody::parsed($request);
            $cascade = ($body['cascade'] ?? false) === true;
            $reason = array_key_exists('reason', $body) ? trim((string) $body['reason']) : null;
            if ($reason !== null
                && ($tooLong = InputLimits::firstViolation(['reason' => [$reason, InputLimits::TEXT_MAX]]))
            ) {
                return $tooLong;
            }

            $closed = $this->windows->close($tenantId, $id, self::actorProfileId($request), $reason, $cascade);

            return Response::json([
                'data' => [
                    'window' => $this->windows->find($tenantId, $id),
                    'closed_ids' => $closed,
                    'report' => $report->toArray(),
                ],
            ]);
        } catch (WindowRejectedException $e) {
            return Response::error($e->clientMessage, 422);
        } catch (\Exception $e) {
            error_log('[TimeWindowsApiHandler] close failed: ' . $e->getMessage());

            return Response::error('Failed to close time window', 500);
        }
    }

    /**
     * POST /api/v1/time-windows/{id}/reopen — unseal it, on the record.
     *
     * A reason is REQUIRED. Not a form nicety: this is the one act in the
     * subsystem that undoes something other people have relied on, and the
     * question six months later is never whether it happened but why.
     *
     * @param array<string, string> $params
     */
    public function reopen(Request $request, array $params): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }
            $id = (int) ($params['id'] ?? 0);
            $window = $this->windows->find($tenantId, $id);
            if ($window === null) {
                return Response::error('Time window not found', 404);
            }

            $body = JsonBody::parsed($request);
            $reason = trim((string) ($body['reason'] ?? ''));
            if ($reason === '') {
                return Response::error(
                    'reason is required to reopen a closed period, and it is recorded permanently',
                    422
                );
            }
            if ($tooLong = InputLimits::firstViolation(['reason' => [$reason, InputLimits::TEXT_MAX]])) {
                return $tooLong;
            }

            $this->windows->reopen($tenantId, $id, self::actorProfileId($request), $reason);

            return Response::json([
                'data' => $this->windows->find($tenantId, $id)
                    + ['trail' => $this->windows->trail($tenantId, $id)],
            ]);
        } catch (WindowRejectedException $e) {
            return Response::error($e->clientMessage, 422);
        } catch (\Exception $e) {
            error_log('[TimeWindowsApiHandler] reopen failed: ' . $e->getMessage());

            return Response::error('Failed to reopen time window', 500);
        }
    }

    /**
     * Query params from $_GET (production) merged with the path query string
     * (tests), path last — the same precedence {@see OuTypesApiHandler} uses, so
     * a test that puts params in the path and production traffic resolve
     * identically.
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

    private static function tenantId(): int|Response
    {
        $tenantId = TenantContext::getTenantId();

        return $tenantId ?? Response::error('Tenant context is required', 403);
    }

    /**
     * Who is performing the act.
     *
     * Null is tolerated rather than refused: a service principal has no profile,
     * and a trail row that records the act with an absent actor is strictly more
     * than no row at all. The route gate has already established that the caller
     * may do this.
     */
    private static function actorProfileId(Request $request): ?int
    {
        $actor = $request->user;

        return is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function intField(array $body, string $field): ?int
    {
        $raw = $body[$field] ?? null;
        if (is_int($raw)) {
            return $raw;
        }
        if (is_string($raw) && preg_match('/^\d+$/', $raw) === 1) {
            return (int) $raw;
        }

        return null;
    }
}
