<?php

declare(strict_types=1);

namespace Whity\Api;

use Psr\Log\LoggerInterface;
use Whity\Api\Exception\CrossTenantReferenceException;
use Whity\Api\Exception\DuplicateRelationException;
use Whity\Api\Exception\PersonNotFoundException;
use Whity\Api\Exception\SelfRelationException;
use Whity\Core\Relations\PersonRepository;
use Whity\Core\Relations\RelationRepository;
use Whity\Core\Relations\RelationResolver;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Http\PaginationParams;

/**
 * Relations API Handler (WC-65 — Family Relations Management System).
 *
 * Owns the relationship-type vocabulary endpoint, the relation edge create/
 * delete endpoints, and the `users/{id}/relations` sugar that resolves a user to
 * their (auto-provisioned) shadow person. RBAC-gated by the router
 * (`relations:read` for reads, `relations:manage` for writes) and tenant-scoped
 * in every method via {@see TenantContext}.
 *
 * Polymorphism only at the boundary: `POST /api/relations` accepts references
 * that may name a user or a person and resolves each to a person id through
 * {@see RelationResolver} (auto-provisioning user shadows). Storage stays uniform
 * `person → person`. Integrity violations surface as typed domain exceptions that
 * this handler translates to safe 4xx responses, never leaking internals.
 *
 * No raw SQL lives here; all persistence goes through the repositories and the
 * resolver. FrankenPHP worker-safe (no request state in statics).
 */
class RelationsApiHandler
{
    private PersonRepository $persons;
    private RelationRepository $relations;
    private RelationResolver $resolver;
    private ?LoggerInterface $logger;

    /**
     * @param PersonRepository     $persons   Person data access (user→person sugar).
     * @param RelationRepository   $relations Relation data access.
     * @param RelationResolver     $resolver  Ref resolution + integrity validation.
     * @param LoggerInterface|null $logger    Optional PSR-3 logger for structured logs.
     */
    public function __construct(
        PersonRepository $persons,
        RelationRepository $relations,
        RelationResolver $resolver,
        ?LoggerInterface $logger = null
    ) {
        $this->persons = $persons;
        $this->relations = $relations;
        $this->resolver = $resolver;
        $this->logger = $logger;
    }

    /**
     * GET /api/relationship-types — the seeded vocabulary for the UI picker.
     *
     * @param Request $request The incoming request.
     * @return Response JSON list under `data`.
     */
    public function listTypes(Request $request): Response
    {
        try {
            $types = $this->relations->listTypes();

            return Response::json(['data' => $types], 200);
        } catch (\Exception $e) {
            $this->log('error', 'Failed to fetch relationship types', ['event' => 'relations.error', 'detail' => $e->getMessage()]);
            return Response::error('Failed to fetch relationship types', 500);
        }
    }

    /**
     * GET /api/relations — the tenant's family graph edges (raw stored direction).
     *
     * Feeds the react-flow graph view: persons (nodes) come from `GET /api/persons`
     * and the edges come from here. Returns one entry per stored relationship.
     *
     * @param Request $request The incoming request.
     * @return Response JSON edges under `data`.
     */
    public function listEdges(Request $request): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 400);
            }

            $p     = PaginationParams::fromPath($request->getPath());
            $total = $this->relations->countEdges($tenantId);
            $edges = $this->relations->listEdges($tenantId, $p->perPage, $p->offset);

            return Response::json(['data' => $edges, 'pagination' => $p->meta($total)], 200);
        } catch (\Exception $e) {
            $this->log('error', 'Failed to fetch relations', ['event' => 'relations.error', 'detail' => $e->getMessage()]);
            return Response::error('Failed to fetch relations', 500);
        }
    }

    /**
     * POST /api/relations — create ONE relation edge from polymorphic references.
     *
     * Body: `{from: {kind:'user'|'person', id}, to: {kind:'user'|'person', id},
     * relationshipTypeId: int}`. Each ref is resolved to a person id (auto-
     * provisioning user shadows); the resolver enforces same-tenant, both-exist,
     * no-self, no-duplicate and type-exists. ONE edge is inserted; the reciprocal
     * is derived at read time.
     *
     * @param Request $request The incoming request.
     * @return Response JSON created edge (201) or a typed 4xx.
     */
    public function create(Request $request): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 400);
            }

            $body = JsonBody::parsed($request);

            $from = $this->parseRef($body, 'from');
            $to = $this->parseRef($body, 'to');
            if ($from === null || $to === null) {
                return Response::error(
                    'from and to must each be {kind:"user"|"person", id:int}',
                    400
                );
            }

            if (!isset($body['relationshipTypeId']) || !is_numeric($body['relationshipTypeId'])) {
                return Response::error('relationshipTypeId is required', 400);
            }
            $relationshipTypeId = (int) $body['relationshipTypeId'];

            $resolved = $this->resolver->resolveRelation($from, $to, $relationshipTypeId, $tenantId);

            // The stored edge belongs to the persons' tenant (== acting tenant for
            // a non-system caller). Re-derive it from the resolved from-person so
            // the system tenant stores the edge with the real tenant_id.
            $fromPerson = $this->persons->findById($resolved['fromPersonId'], 0);
            $storeTenant = $fromPerson !== null ? (int) $fromPerson['tenant_id'] : $tenantId;

            $relationId = $this->relations->insert(
                $storeTenant,
                $resolved['fromPersonId'],
                $resolved['toPersonId'],
                $resolved['relationshipTypeId']
            );

            $this->log('info', 'Relation created', [
                'event' => 'relations.create',
                'tenant_id' => $storeTenant,
                'relation_id' => $relationId,
                'from_person_id' => $resolved['fromPersonId'],
                'to_person_id' => $resolved['toPersonId'],
                'relationship_type_id' => $resolved['relationshipTypeId'],
            ]);

            return Response::json([
                'data' => [
                    'id' => $relationId,
                    'fromPersonId' => $resolved['fromPersonId'],
                    'toPersonId' => $resolved['toPersonId'],
                    'relationshipTypeId' => $resolved['relationshipTypeId'],
                ],
            ], 201);
        } catch (SelfRelationException $e) {
            return Response::error('A person cannot be related to itself', 422);
        } catch (DuplicateRelationException $e) {
            return Response::error('That relation already exists', 422);
        } catch (CrossTenantReferenceException $e) {
            // Treated as not-found so cross-tenant existence is never disclosed.
            $this->log('warning', 'Relation rejected: cross-tenant reference', [
                'event' => 'relations.cross_tenant',
                'tenant_id' => TenantContext::getTenantId(),
            ]);
            return Response::error('Person not found', 404);
        } catch (PersonNotFoundException $e) {
            return Response::error('Person or relationship type not found', 404);
        } catch (\Exception $e) {
            $this->log('error', 'Failed to create relation', ['event' => 'relations.error', 'detail' => $e->getMessage()]);
            return Response::error('Failed to create relation', 500);
        }
    }

    /**
     * DELETE /api/relations/{id} — remove an edge.
     *
     * @param Request              $request The incoming request.
     * @param array<string, mixed> $params  Route params (expects `id`).
     * @return Response 204 on success, or an error.
     */
    public function delete(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? null;
            if ($id === null || !is_numeric($id)) {
                return Response::error('Relation ID is required', 400);
            }

            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 400);
            }

            $deleted = $this->relations->delete((int) $id, $tenantId);
            if ($deleted === 0) {
                return Response::error('Relation not found', 404);
            }

            $this->log('info', 'Relation deleted', [
                'event' => 'relations.delete',
                'tenant_id' => $tenantId,
                'relation_id' => (int) $id,
            ]);

            return Response::json([], 204);
        } catch (\Exception $e) {
            $this->log('error', 'Failed to delete relation', ['event' => 'relations.error', 'detail' => $e->getMessage()]);
            return Response::error('Failed to delete relation', 500);
        }
    }

    /**
     * GET /api/users/{id}/relations — sugar: resolve a user → their shadow person
     * and return that node's relations (reciprocal-derived).
     *
     * Read-only: resolves the user's EXISTING shadow person without provisioning one
     * (a relations:read path must never write). A user with no shadow yet simply has
     * no relations; a user outside the acting tenant is not-found.
     *
     * @param Request              $request The incoming request.
     * @param array<string, mixed> $params  Route params (expects `id`).
     * @return Response JSON relations under `data`, or 404.
     */
    public function userRelations(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? null;
            if ($id === null || !is_numeric($id)) {
                return Response::error('User ID is required', 400);
            }

            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 400);
            }

            // Resolve user → EXISTING shadow person WITHOUT provisioning (read path
            // must not write). Missing / cross-tenant user → 404; a user with no
            // shadow yet simply has no relations.
            $personId = $this->resolver->resolveExistingUserPerson((int) $id, $tenantId);
            if ($personId === null) {
                return Response::json([
                    'data' => ['personId' => null, 'relations' => []],
                ], 200);
            }

            $relations = $this->relations->listForPerson($personId, $tenantId);
            $mapped = array_map(
                static fn (array $r): array => [
                    'relationId'           => (int) $r['relationId'],
                    'otherPersonId'        => (int) $r['otherPersonId'],
                    'otherPersonName'      => (string) $r['otherPersonName'],
                    'otherPersonHasAccount' => $r['otherPersonUserId'] !== null,
                    'typeId'               => (int) $r['typeId'],
                    'typeName'             => (string) $r['typeName'],
                    'direction'            => (string) $r['direction'],
                ],
                $relations
            );

            return Response::json([
                'data' => [
                    'personId' => $personId,
                    'relations' => $mapped,
                ],
            ], 200);
        } catch (CrossTenantReferenceException | PersonNotFoundException $e) {
            return Response::error('User not found', 404);
        } catch (\Exception $e) {
            $this->log('error', 'Failed to fetch user relations', ['event' => 'relations.error', 'detail' => $e->getMessage()]);
            return Response::error('Failed to fetch user relations', 500);
        }
    }

    /**
     * Parse a `{kind, id}` reference from the body under `$key`.
     *
     * @param array<string, mixed> $body
     * @return array{kind: string, id: int}|null Null when absent/malformed.
     */
    private function parseRef(array $body, string $key): ?array
    {
        if (!isset($body[$key]) || !is_array($body[$key])) {
            return null;
        }
        $ref = $body[$key];

        $kind = isset($ref['kind']) ? (string) $ref['kind'] : '';
        if ($kind !== RelationResolver::KIND_USER && $kind !== RelationResolver::KIND_PERSON) {
            return null;
        }

        if (!isset($ref['id']) || !is_numeric($ref['id'])) {
            return null;
        }

        return ['kind' => $kind, 'id' => (int) $ref['id']];
    }

    /**
     * Emit a structured log record when a logger is configured.
     *
     * @param string               $level   PSR-3 log level (e.g. `info`).
     * @param string               $message The human-readable message.
     * @param array<string, mixed> $context Structured context (includes tenant_id).
     */
    private function log(string $level, string $message, array $context): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->log($level, $message, $context);
    }
}
