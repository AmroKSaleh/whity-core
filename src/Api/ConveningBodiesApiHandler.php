<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Convening\ConveningBodyRepository;
use Whity\Core\Convening\ConveningRejectedException;
use Whity\Core\Convening\LocalizedText;
use Whity\Core\Convening\MemberRole;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\InputLimits;
use Whity\Http\JsonBody;

/**
 * The standing bodies and who sits on them.
 *
 * PERMISSIONS: reading on `convening:read`, every write on `convening:manage`.
 * Two gates rather than four, because constituting a body and appointing its
 * members are one authority — an organisation that lets somebody create a
 * committee is not going to withhold from them the ability to say who is on it.
 * The third slug, `convening:decide`, appears in {@see MeetingsApiHandler} and
 * nowhere here: nothing on this surface can move a document.
 *
 * THERE IS NO MEMBERSHIP DELETE, ONLY A DEPARTURE. `DELETE .../members/{id}`
 * stamps `left_at` — see {@see ConveningBodyRepository::removeMember()}. The verb
 * is DELETE because that is what the caller means and what a REST client expects;
 * what happens underneath is a departure, because a decision taken in March was
 * taken by the body as it was constituted in March.
 */
final class ConveningBodiesApiHandler
{
    public function __construct(private readonly ConveningBodyRepository $bodies)
    {
    }

    /**
     * GET /api/v1/convening-bodies — the tenant's bodies, active first.
     */
    public function list(Request $request): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }

            $query = self::queryParams($request);
            $activeOnly = isset($query['active']) && $query['active'] === 'true' ? true : null;

            return Response::json(['data' => $this->bodies->listForTenant($tenantId, $activeOnly)]);
        } catch (\Exception $e) {
            error_log('[ConveningBodiesApiHandler] list failed: ' . $e->getMessage());

            return Response::error('Failed to fetch convening bodies', 500);
        }
    }

    /**
     * GET /api/v1/convening-bodies/{id} — one body, with its CURRENT seats.
     *
     * The membership travels with the body because the two are never wanted
     * apart: "which committee is this" is half an answer without "and who is on
     * it". Past seats are behind `?history=true` rather than always present — a
     * body that has run for a decade carries a membership history nobody asked
     * for on the ordinary read.
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
            $body = $this->bodies->find($tenantId, $id);
            if ($body === null) {
                return Response::error('Convening body not found', 404);
            }

            $query = self::queryParams($request);
            $members = isset($query['history']) && $query['history'] === 'true'
                ? $this->bodies->allMembers($tenantId, $id)
                : $this->bodies->currentMembers($tenantId, $id);

            return Response::json(['data' => $body + ['members' => $members]]);
        } catch (\Exception $e) {
            error_log('[ConveningBodiesApiHandler] show failed: ' . $e->getMessage());

            return Response::error('Failed to fetch convening body', 500);
        }
    }

    /**
     * POST /api/v1/convening-bodies — constitute a body.
     */
    public function create(Request $request): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }

            $body = JsonBody::parsed($request);
            $key = trim((string) ($body['body_key'] ?? ''));
            if ($key === '') {
                return Response::error('body_key is required', 422);
            }

            $name = LocalizedText::normalize(
                $body['name'] ?? null,
                ConveningBodyRepository::FALLBACK_LOCALE,
                'name'
            );

            $description = isset($body['description']) && is_string($body['description'])
                ? trim($body['description'])
                : null;
            if ($description !== null && ($tooLong = InputLimits::firstViolation([
                'description' => [$description, InputLimits::TEXT_MAX],
            ]))) {
                return $tooLong;
            }

            $ouId = self::intField($body, 'ou_id');

            $id = $this->bodies->create($tenantId, $key, $name, $ouId, $description);

            return Response::json(['data' => $this->bodies->find($tenantId, $id)], 201);
        } catch (ConveningRejectedException $e) {
            // ->clientMessage, never ->getMessage(): only text somebody wrote FOR
            // a caller reaches a caller (WC-186). Every catch site below follows.
            return Response::error($e->clientMessage, 422);
        } catch (\Exception $e) {
            error_log('[ConveningBodiesApiHandler] create failed: ' . $e->getMessage());

            return Response::error('Failed to create convening body', 500);
        }
    }

    /**
     * PATCH /api/v1/convening-bodies/{id} — rename, re-home, retire, revive.
     *
     * `body_key` is not accepted: decision numbers already quote it. See
     * {@see ConveningBodyRepository::update()}.
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
            if ($this->bodies->find($tenantId, $id) === null) {
                return Response::error('Convening body not found', 404);
            }

            $body = JsonBody::parsed($request);
            $fields = [];

            if (array_key_exists('name', $body)) {
                $fields['name'] = LocalizedText::normalize(
                    $body['name'],
                    ConveningBodyRepository::FALLBACK_LOCALE,
                    'name'
                );
            }
            if (array_key_exists('ou_id', $body)) {
                $fields['ou_id'] = $body['ou_id'] === null ? null : self::intField($body, 'ou_id');
            }
            if (array_key_exists('description', $body)) {
                $fields['description'] = $body['description'] === null
                    ? null
                    : trim((string) $body['description']);
            }
            if (array_key_exists('is_active', $body)) {
                $fields['is_active'] = (bool) $body['is_active'];
            }
            if (array_key_exists('body_key', $body)) {
                return Response::error(
                    'body_key cannot be changed. Every decision number this body has minted quotes '
                    . 'it, and changing it would leave those numbers naming a body that no longer '
                    . 'exists.',
                    422
                );
            }

            $this->bodies->update($tenantId, $id, $fields);

            return Response::json(['data' => $this->bodies->find($tenantId, $id)]);
        } catch (ConveningRejectedException $e) {
            return Response::error($e->clientMessage, 422);
        } catch (\Exception $e) {
            error_log('[ConveningBodiesApiHandler] update failed: ' . $e->getMessage());

            return Response::error('Failed to update convening body', 500);
        }
    }

    /**
     * DELETE /api/v1/convening-bodies/{id} — refused once the body has met.
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }

            $id = (int) ($params['id'] ?? 0);
            if ($this->bodies->find($tenantId, $id) === null) {
                return Response::error('Convening body not found', 404);
            }

            $this->bodies->delete($tenantId, $id);

            return Response::json(['data' => ['deleted' => true]]);
        } catch (ConveningRejectedException $e) {
            // 409, not 422: the request is well-formed and the refusal is about
            // the STATE of the resource, which is what a conflict means.
            return Response::error($e->clientMessage, 409);
        } catch (\Exception $e) {
            error_log('[ConveningBodiesApiHandler] delete failed: ' . $e->getMessage());

            return Response::error('Failed to delete convening body', 500);
        }
    }

    /**
     * GET /api/v1/convening-bodies/{id}/members
     *
     * @param array<string, string> $params
     */
    public function listMembers(Request $request, array $params): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }

            $id = (int) ($params['id'] ?? 0);
            if ($this->bodies->find($tenantId, $id) === null) {
                return Response::error('Convening body not found', 404);
            }

            $query = self::queryParams($request);
            $members = isset($query['history']) && $query['history'] === 'true'
                ? $this->bodies->allMembers($tenantId, $id)
                : $this->bodies->currentMembers($tenantId, $id);

            return Response::json(['data' => $members]);
        } catch (\Exception $e) {
            error_log('[ConveningBodiesApiHandler] listMembers failed: ' . $e->getMessage());

            return Response::error('Failed to fetch members', 500);
        }
    }

    /**
     * POST /api/v1/convening-bodies/{id}/members — seat somebody, or move their
     * seat.
     *
     * @param array<string, string> $params
     */
    public function addMember(Request $request, array $params): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }

            $id = (int) ($params['id'] ?? 0);
            if ($this->bodies->find($tenantId, $id) === null) {
                return Response::error('Convening body not found', 404);
            }

            $body = JsonBody::parsed($request);
            $profileId = self::intField($body, 'profile_id');
            if ($profileId === null) {
                return Response::error('profile_id is required and must be an integer', 422);
            }

            $memberRole = isset($body['member_role']) && is_string($body['member_role'])
                ? $body['member_role']
                : MemberRole::MEMBER;

            $this->bodies->addMember($tenantId, $id, $profileId, $memberRole);

            return Response::json(['data' => $this->bodies->currentMembers($tenantId, $id)], 201);
        } catch (ConveningRejectedException $e) {
            return Response::error($e->clientMessage, 422);
        } catch (\Exception $e) {
            error_log('[ConveningBodiesApiHandler] addMember failed: ' . $e->getMessage());

            return Response::error('Failed to add member', 500);
        }
    }

    /**
     * DELETE /api/v1/convening-bodies/{id}/members/{profileId} — stand down.
     *
     * @param array<string, string> $params
     */
    public function removeMember(Request $request, array $params): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }

            $id = (int) ($params['id'] ?? 0);
            if ($this->bodies->find($tenantId, $id) === null) {
                return Response::error('Convening body not found', 404);
            }

            $profileId = (int) ($params['profileId'] ?? 0);
            if (!$this->bodies->removeMember($tenantId, $id, $profileId)) {
                return Response::error('That person does not currently sit on this body', 404);
            }

            return Response::json(['data' => $this->bodies->currentMembers($tenantId, $id)]);
        } catch (\Exception $e) {
            error_log('[ConveningBodiesApiHandler] removeMember failed: ' . $e->getMessage());

            return Response::error('Failed to remove member', 500);
        }
    }

    // -- helpers ------------------------------------------------------------

    /**
     * Query params from $_GET (production) merged with the path query string
     * (tests), path last — the same precedence {@see TimeWindowsApiHandler} uses,
     * so a test that puts params in the path and production traffic resolve
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
