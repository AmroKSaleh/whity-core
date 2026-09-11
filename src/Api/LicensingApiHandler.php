<?php

declare(strict_types=1);

namespace Whity\Api;

use DateTimeImmutable;
use PDO;
use Whity\Auth\RoleChecker;
use Whity\Core\Licensing\ActivationCode;
use Whity\Core\Licensing\ActivationService;
use Whity\Core\Licensing\LicensingException;
use Whity\Core\RateLimit\ClientIp;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Per-device licensing over HTTP.
 *
 * THE PRIVILEGED SURFACE AND THE PUBLIC ONE ARE DIFFERENT ANIMALS, and this
 * class keeps them adjacent so the difference cannot be forgotten.
 *
 * Everything except {@see self::redeem()} is an authenticated, tenant-scoped,
 * RBAC-gated action by a known actor: provisioning stock, minting a code
 * because something was sold, listing what exists.
 *
 * `redeem()` is none of those things. It has no capability, because the caller
 * may be an end user or a student with no account, no session and no
 * relationship to the tenant at the moment they type the code. They are
 * authorised by POSSESSION, and everything the endpoint needs — which tenant,
 * which unit, whether the grant is still live — comes from the code itself.
 * Nothing from the caller is trusted, including any tenant they name.
 *
 * That is also why registering it is TWO edits and not one: a route, and an
 * entry in {@see \Whity\Http\Middleware\EnforceTenantIsolation}'s public
 * patterns. Miss the second and the middleware refuses the request before
 * routing ever happens, producing a 401 on an endpoint that is supposed to be
 * open — a failure this project has already shipped once.
 */
final class LicensingApiHandler
{
    private const MAX_BULK_SERIALS = 500;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ActivationService $activations,
        private readonly RoleChecker $roleChecker,
        private readonly ?\Closure $clock = null,
    ) {
    }

    // ── the privileged surface ──────────────────────────────────────────────

    /** GET /api/licensing/devices — this tenant's units. */
    public function devices(Request $request): Response
    {
        $tenantId = $this->authorize($request, CorePermissions::LICENSING_VIEW);
        if ($tenantId instanceof Response) {
            return $tenantId;
        }

        $statement = $this->pdo->prepare('
            SELECT id, serial_number, label, status, provisioned_at, activated_at, last_seen_at
              FROM licensed_devices
             WHERE tenant_id = :tenant
             ORDER BY id DESC
             LIMIT 500
        ');
        $statement->bindValue(':tenant', $tenantId, PDO::PARAM_INT);
        $statement->execute();

        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'serial_number' => (string) $row['serial_number'],
                'label' => $row['label'] !== null ? (string) $row['label'] : null,
                'status' => (string) $row['status'],
                'provisioned_at' => $row['provisioned_at'],
                'activated_at' => $row['activated_at'],
                'last_seen_at' => $row['last_seen_at'],
            ];
        }

        return Response::json(['data' => $rows]);
    }

    /**
     * POST /api/licensing/devices — provision serials in bulk.
     *
     * BULK BECAUSE STOCK ARRIVES IN BOXES. Sales will not add five hundred
     * units one at a time, and an endpoint that forces them to is an endpoint
     * that gets scripted badly against.
     *
     * ALREADY-KNOWN SERIALS ARE SKIPPED, NOT REJECTED. A re-uploaded
     * spreadsheet is the normal way this goes wrong, and failing the whole
     * batch on row 400 leaves the caller with no idea which 399 landed. The
     * response says how many were created and how many were already there, so
     * a partial import is legible rather than mysterious.
     */
    public function provision(Request $request): Response
    {
        $tenantId = $this->authorize($request, CorePermissions::LICENSING_MANAGE);
        if ($tenantId instanceof Response) {
            return $tenantId;
        }

        $body = JsonBody::parsed($request);
        $serials = $body['serial_numbers'] ?? null;

        if (!is_array($serials) || $serials === []) {
            return Response::error('Provide serial_numbers as a non-empty array.', 422);
        }

        if (count($serials) > self::MAX_BULK_SERIALS) {
            return Response::error(
                sprintf('Too many serials in one request (limit %d).', self::MAX_BULK_SERIALS),
                422
            );
        }

        $created = 0;
        $existing = 0;
        $rejected = [];

        $statement = $this->pdo->prepare("
            INSERT INTO licensed_devices (tenant_id, serial_number, label, status, provisioned_at, created_at, updated_at)
            VALUES (:tenant, :serial, :label, 'provisioned', :now, :now2, :now3)
            ON CONFLICT (tenant_id, serial_number) DO NOTHING
            RETURNING id
        ");

        $now = $this->now()->format('Y-m-d H:i:s');

        foreach ($serials as $entry) {
            $serial = is_array($entry) ? ($entry['serial_number'] ?? null) : $entry;
            $label = is_array($entry) ? ($entry['label'] ?? null) : null;

            if (!is_string($serial) || trim($serial) === '') {
                $rejected[] = ['serial_number' => $serial, 'reason' => 'empty or not a string'];
                continue;
            }

            $serial = trim($serial);
            if (strlen($serial) > 128) {
                $rejected[] = ['serial_number' => substr($serial, 0, 32) . '…', 'reason' => 'longer than 128 characters'];
                continue;
            }

            $statement->bindValue(':tenant', $tenantId, PDO::PARAM_INT);
            $statement->bindValue(':serial', $serial);
            $statement->bindValue(':label', is_string($label) && $label !== '' ? $label : null,
                is_string($label) && $label !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $statement->bindValue(':now', $now);
            $statement->bindValue(':now2', $now);
            $statement->bindValue(':now3', $now);
            $statement->execute();

            if ($statement->fetchColumn() !== false) {
                $created++;
            } else {
                $existing++;
            }
        }

        return Response::json([
            'created' => $created,
            'already_present' => $existing,
            'rejected' => $rejected,
        ], $rejected === [] ? 200 : 207);
    }

    /**
     * POST /api/licensing/codes — mint an activation code.
     *
     * The commercial act. Returns the code ONCE, formatted for printing; it is
     * stored canonically and is not retrievable in full afterwards by design,
     * the same way a generated API token is not.
     */
    public function issueCode(Request $request): Response
    {
        $tenantId = $this->authorize($request, CorePermissions::LICENSING_ISSUE);
        if ($tenantId instanceof Response) {
            return $tenantId;
        }

        $body = JsonBody::parsed($request);

        $deviceId = $body['licensed_device_id'] ?? null;
        $deviceId = is_int($deviceId) ? $deviceId : (is_string($deviceId) && ctype_digit($deviceId) ? (int) $deviceId : null);

        $maxRedemptions = $body['max_redemptions'] ?? 1;
        $maxRedemptions = is_int($maxRedemptions) ? $maxRedemptions : 1;

        $expiresAt = null;
        if (isset($body['expires_at']) && is_string($body['expires_at']) && $body['expires_at'] !== '') {
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $body['expires_at'])
                ?: DateTimeImmutable::createFromFormat('Y-m-d', $body['expires_at']);
            if ($parsed === false) {
                return Response::error('expires_at must be YYYY-MM-DD or YYYY-MM-DD HH:MM:SS.', 422);
            }
            $expiresAt = $parsed;
        }

        // A device named here MUST belong to the caller's tenant. Without this
        // an authorised salesperson at one customer could mint a code bound to
        // another customer's hardware — the tenant predicate is the whole
        // protection, since the id itself is just a number from the request.
        if ($deviceId !== null && !$this->deviceBelongsToTenant($deviceId, $tenantId)) {
            return Response::error('No such device.', 404);
        }

        try {
            $issued = $this->activations->issue($tenantId, $deviceId, $maxRedemptions, $expiresAt);
        } catch (LicensingException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::json([
            'id' => $issued['id'],
            'code' => $issued['code'],
            'max_redemptions' => $maxRedemptions,
            'expires_at' => $expiresAt?->format('Y-m-d H:i:s'),
        ], 201);
    }

    /** POST /api/licensing/codes/{id}/revoke — kill a code that leaked. */
    /** @param array<string, string> $params */
    public function revokeCode(Request $request, array $params): Response
    {
        $tenantId = $this->authorize($request, CorePermissions::LICENSING_MANAGE);
        if ($tenantId instanceof Response) {
            return $tenantId;
        }

        $id = (int) ($params['id'] ?? 0);

        $statement = $this->pdo->prepare('
            UPDATE device_activation_codes
               SET revoked_at = :now, updated_at = :now2
             WHERE id = :id AND tenant_id = :tenant AND revoked_at IS NULL
        ');
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->bindValue(':tenant', $tenantId, PDO::PARAM_INT);
        $statement->bindValue(':now', $this->now()->format('Y-m-d H:i:s'));
        $statement->bindValue(':now2', $this->now()->format('Y-m-d H:i:s'));
        $statement->execute();

        if ($statement->rowCount() === 0) {
            return Response::error('No such code, or it was already revoked.', 404);
        }

        return Response::json(['revoked' => true]);
    }

    // ── the public surface ──────────────────────────────────────────────────

    /**
     * POST /api/public/licensing/redeem — activate a device with a code.
     *
     * UNAUTHENTICATED, DELIBERATELY. See the class docblock. The protections
     * here are the code's own 50 bits of entropy, its check characters, the
     * platform's pre-auth IP rate limiter, and the fact that every fact used to
     * resolve the activation comes from the code rather than the caller.
     *
     * THE ERROR MESSAGES ARE WRITTEN FOR A STUDENT, not an operator: what
     * happened and what to do. They never distinguish an unknown code from a
     * mistyped one, because doing so would let an anonymous caller learn which
     * well-formed codes exist.
     */
    public function redeem(Request $request): Response
    {
        $body = JsonBody::parsed($request);

        $code = $body['code'] ?? null;
        if (!is_string($code) || $code === '') {
            return Response::error('Enter your activation code.', 422);
        }

        // A SERIAL, NEVER AN INTERNAL ID. A person can read a serial off the
        // unit in front of them; they cannot know its row id, and accepting one
        // would let a caller enumerate rows by number.
        //
        // It is passed through UNRESOLVED. Turning a serial into an id here
        // would need a query with no tenant predicate — the caller has no
        // tenant — which is a cross-tenant read, and would let two customers
        // holding hardware with the same manufacturer serial reach each other's
        // row. The service resolves it inside the tenant the CODE names, which
        // is the only trustworthy scope available.
        $serial = $body['serial_number'] ?? null;
        $device = is_string($serial) && trim($serial) !== '' ? trim($serial) : null;

        try {
            $result = $this->activations->redeem(
                $code,
                $device,
                null,
                ClientIp::fromRequest($request),
            );
        } catch (LicensingException $e) {
            return Response::error($e->getMessage(), 422);
        }

        // Deliberately thin. It confirms success and nothing about the tenant,
        // the code's remaining uses, or anything else the caller did not
        // already know.
        return Response::json(['activated' => true, 'device_id' => $result['licensed_device_id']]);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function deviceBelongsToTenant(int $deviceId, int $tenantId): bool
    {
        $statement = $this->pdo->prepare('
            SELECT 1 FROM licensed_devices WHERE id = :id AND tenant_id = :tenant
        ');
        $statement->bindValue(':id', $deviceId, PDO::PARAM_INT);
        $statement->bindValue(':tenant', $tenantId, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchColumn() !== false;
    }


    private function authorize(Request $request, string $permission): int|Response
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

        return $tenantId;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock !== null ? ($this->clock)() : new DateTimeImmutable();
    }
}
