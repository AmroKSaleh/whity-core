<?php

declare(strict_types=1);

namespace Whity\Core\Licensing;

use DateTimeImmutable;
use PDO;

/**
 * Issuing activation codes, and redeeming them.
 *
 * TWO OPERATIONS WITH OPPOSITE THREAT MODELS, which is why they live together
 * where the asymmetry is visible rather than in separate files where it is not.
 *
 * ISSUING is privileged and deliberate. An administrator or a salesperson
 * creates a code because something was sold; it is an ordinary authorised
 * action by a known actor, gated by RBAC like any other.
 *
 * REDEEMING is the opposite. The caller may be an end user or a student with no
 * account, no session and no relationship to the tenant at the moment they type
 * the code. They are authorised by *possession of the code itself* — a bearer
 * credential — so the code has to carry everything: which tenant, which grant,
 * and whether it may still be used. Nothing about the caller can be trusted,
 * including any tenant they claim to belong to.
 *
 * THE RACE IS REAL, NOT THEORETICAL. Thirty students in a room typing the last
 * code of a batch within the same second is a Tuesday. A read-then-write —
 * "fetch the code, check redemption_count < max_redemptions, then update" —
 * loses that race and hands out a licence nobody paid for. So redemption is a
 * SINGLE conditional UPDATE whose WHERE clause carries every precondition, and
 * the database decides the winner. The same shape {@see \Whity\Core\Payment\PaymentLedger}
 * uses to make settlement idempotent, for the same reason.
 *
 * A FAILED REDEMPTION IS DIAGNOSED SEPARATELY, and that split matters. The
 * atomic UPDATE returns nothing when it fails and cannot say why; a second,
 * best-effort read produces the reason. That read is advisory only — by the
 * time it runs the world may have moved on — but it is what turns "invalid
 * code" into "this code expired last week", which is the difference between a
 * support ticket and a person solving their own problem.
 */
final class ActivationService
{
    public function __construct(
        private readonly PDO $pdo,
        /** Injected so tests can place expiry and activation against a fixed today. */
        private readonly ?\Closure $clock = null,
    ) {
    }

    // ── issuing ─────────────────────────────────────────────────────────────

    /**
     * Mint a code for a tenant. Privileged: callers must already have checked
     * the RBAC capability — this class does not, deliberately, because it is
     * also used by seeding and by bulk import where there is no request.
     *
     * @param int|null                $licensedDeviceId Bind to one unit now, or null to
     *                                                  bind on redemption.
     * @param int                     $maxRedemptions   1 = single use, the default.
     * @param DateTimeImmutable|null  $expiresAt        null = no expiry.
     *
     * @return array{id: int, code: string}
     */
    public function issue(
        int $tenantId,
        ?int $licensedDeviceId = null,
        int $maxRedemptions = 1,
        ?DateTimeImmutable $expiresAt = null,
    ): array {
        if ($maxRedemptions < 1) {
            throw LicensingException::of(LicensingException::REASON_BAD_REDEMPTION_LIMIT, 'maxRedemptions must be >= 1.');
        }

        // Retry on the astronomically unlikely collision rather than surfacing
        // it. 50 bits means this effectively never runs, but "effectively
        // never" is not "never", and a unique-violation reaching a salesperson
        // as a 500 would be an unexplainable failure of an ordinary action.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = ActivationCode::generate();
            $canonical = ActivationCode::canonicalize($code);

            $statement = $this->pdo->prepare('
                INSERT INTO device_activation_codes
                    (tenant_id, code, licensed_device_id, max_redemptions, redemption_count, expires_at, created_at, updated_at)
                VALUES
                    (:tenant, :code, :device, :max, 0, :expires, NOW(), NOW())
                ON CONFLICT (code) DO NOTHING
                RETURNING id
            ');
            $statement->bindValue(':tenant', $tenantId, PDO::PARAM_INT);
            $statement->bindValue(':code', $canonical);
            $statement->bindValue(':device', $licensedDeviceId, $licensedDeviceId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $statement->bindValue(':max', $maxRedemptions, PDO::PARAM_INT);
            $statement->bindValue(':expires', $expiresAt?->format('Y-m-d H:i:s'), $expiresAt === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $statement->execute();

            $id = $statement->fetchColumn();
            if ($id !== false) {
                // The FORMATTED code is returned, because it is about to be
                // printed on something a person will read. The canonical form
                // is what the database holds.
                return ['id' => (int) $id, 'code' => ActivationCode::format($canonical)];
            }
        }

        throw LicensingException::of(LicensingException::REASON_MINT_FAILED, 'Exhausted retries minting a unique code.');
    }

    // ── redeeming ───────────────────────────────────────────────────────────

    /**
     * Redeem a code against a device, activating it.
     *
     * @param string   $code            As typed. Decoration and case are irrelevant.
     * @param int|string|null $device The unit being activated, when the code is not
     *                                already bound to one. A SERIAL (string) is what an
     *                                end user can supply — they can read it off the
     *                                hardware — and it is resolved inside the tenant
     *                                the code names, never globally.
     * @param int|null $redeemedByUserId Null is expected: a student may have no account.
     *
     * @return array{licensed_device_id: int, tenant_id: int}
     *
     * @throws LicensingException carrying a REASON code; the handler owns the wording.
     */
    public function redeem(
        string $code,
        int|string|null $device = null,
        ?int $redeemedByUserId = null,
        ?string $fromIp = null,
    ): array {
        // CHEAPEST CHECK FIRST, and it is not merely an optimisation. A typo is
        // overwhelmingly the most common failure, and rejecting it here means a
        // mistyped code never reaches the database — so it cannot be confused
        // with an unknown one, and cannot be used to probe which codes exist.
        if (!ActivationCode::isWellFormed($code)) {
            throw LicensingException::of(LicensingException::REASON_INVALID, 'Check characters did not verify.');
        }

        $canonical = ActivationCode::canonicalize($code);
        $now = $this->now();

        // THE ATOMIC CLAIM. Every precondition is in the WHERE clause, so the
        // database — not this process — decides who gets the last redemption of
        // a batch. Nothing is read first; there is no window to lose.
        // @tenant-guard-ignore: the tenant is this query's OUTPUT, not its input. A code is globally unique precisely so a redeeming student — who has no session and no tenant — can be resolved from the code alone; a tenant predicate here could only come from the untrusted caller, which is how one customer's code gets applied to another's account. Everything after this point is scoped to the tenant this returns.
        $claim = $this->pdo->prepare('
            UPDATE device_activation_codes
               SET redemption_count = redemption_count + 1,
                   updated_at = :now
             WHERE code = :code
               AND revoked_at IS NULL
               AND (expires_at IS NULL OR expires_at > :now2)
               AND redemption_count < max_redemptions
            RETURNING id, tenant_id, licensed_device_id
        ');
        $claim->bindValue(':code', $canonical);
        $claim->bindValue(':now', $now->format('Y-m-d H:i:s'));
        $claim->bindValue(':now2', $now->format('Y-m-d H:i:s'));
        $claim->execute();

        /** @var array{id: int|string, tenant_id: int|string, licensed_device_id: int|string|null}|false $row */
        $row = $claim->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            // Best-effort diagnosis. Advisory by nature — the world may have
            // moved since the UPDATE — but it is the difference between
            // "invalid code" and "this expired last week".
            throw LicensingException::of($this->diagnoseFailedRedemption($canonical, $now), 'Atomic claim matched no row.');
        }

        $codeId = (int) $row['id'];
        $tenantId = (int) $row['tenant_id'];

        // A code bound at issue time wins over anything the caller supplies.
        // Otherwise a caller could redeem a code minted for one unit against a
        // different one — which is how a licence ends up on hardware nobody
        // sold it for.
        // A SERIAL IS RESOLVED INSIDE THE CODE'S TENANT, never outside it. The
        // caller may hand us a serial because that is what a person can read off
        // the unit — but looking one up without a tenant predicate is a
        // cross-tenant read, and would also mean two customers holding hardware
        // with the same manufacturer serial could reach each other's row. The
        // code decided the tenant a moment ago; that is the scope.
        $deviceId = $row['licensed_device_id'] !== null
            ? (int) $row['licensed_device_id']
            : (is_string($device) ? $this->deviceIdForSerial($tenantId, $device) : $device);

        if ($deviceId === null) {
            throw LicensingException::of(LicensingException::REASON_DEVICE_REQUIRED, 'Unbound code redeemed without a device.');
        }

        $this->activate($tenantId, $deviceId, $now);
        $this->recordRedemption($tenantId, $codeId, $deviceId, $redeemedByUserId, $fromIp, $now);

        return ['licensed_device_id' => $deviceId, 'tenant_id' => $tenantId];
    }

    /** Resolve a serial WITHIN a tenant. There is no unscoped variant, on purpose. */
    private function deviceIdForSerial(int $tenantId, string $serial): ?int
    {
        $statement = $this->pdo->prepare('
            SELECT id FROM licensed_devices WHERE tenant_id = :tenant AND serial_number = :serial_no
        ');
        $statement->bindValue(':tenant', $tenantId, PDO::PARAM_INT);
        $statement->bindValue(':serial_no', $serial);
        $statement->execute();

        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Mark the unit active.
     *
     * TENANT-SCOPED, and that predicate is load-bearing rather than defensive.
     * The device id arrives from the caller on the open redemption path, so
     * without it somebody could redeem their own code against another
     * customer's hardware. The tenant comes from the CODE, which is the only
     * thing here that was not supplied by the caller.
     *
     * Idempotent on activated_at: a unit redeemed twice under a multi-use code
     * keeps the date it FIRST entered service, because that is the date an
     * activation-based invoice is computed from and it must not drift.
     */
    private function activate(int $tenantId, int $deviceId, DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare("
            UPDATE licensed_devices
               SET status = 'active',
                   activated_at = COALESCE(activated_at, :now),
                   last_seen_at = :now2,
                   updated_at = :now3
             WHERE id = :device
               AND tenant_id = :tenant
               AND status <> 'retired'
        ");
        $statement->bindValue(':device', $deviceId, PDO::PARAM_INT);
        $statement->bindValue(':tenant', $tenantId, PDO::PARAM_INT);
        $statement->bindValue(':now', $now->format('Y-m-d H:i:s'));
        $statement->bindValue(':now2', $now->format('Y-m-d H:i:s'));
        $statement->bindValue(':now3', $now->format('Y-m-d H:i:s'));
        $statement->execute();

        if ($statement->rowCount() === 0) {
            // The code was valid and has now been counted, but there is no such
            // device in that tenant — or it is retired. Refusing loudly beats
            // reporting success over a device that was never touched.
            throw LicensingException::of(LicensingException::REASON_DEVICE_UNKNOWN, "Device absent from the code's tenant, or retired.");
        }
    }

    private function recordRedemption(
        int $tenantId,
        int $codeId,
        int $deviceId,
        ?int $userId,
        ?string $fromIp,
        DateTimeImmutable $now,
    ): void {
        $statement = $this->pdo->prepare('
            INSERT INTO device_activation_redemptions
                (tenant_id, activation_code_id, licensed_device_id, redeemed_by_user_id, redeemed_from_ip, redeemed_at)
            VALUES (:tenant, :code, :device, :user, :ip, :now)
        ');
        $statement->bindValue(':tenant', $tenantId, PDO::PARAM_INT);
        $statement->bindValue(':code', $codeId, PDO::PARAM_INT);
        $statement->bindValue(':device', $deviceId, PDO::PARAM_INT);
        $statement->bindValue(':user', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':ip', $fromIp, $fromIp === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':now', $now->format('Y-m-d H:i:s'));
        $statement->execute();
    }

    /**
     * Why a redemption did not take.
     *
     * DELIBERATELY DOES NOT DISTINGUISH "no such code" FROM A TYPO, because
     * both reach here only after the checksum passed, and telling an
     * unauthenticated caller which well-formed codes exist turns this endpoint
     * into an oracle. Expired, revoked and exhausted are safe to state: knowing
     * them requires already holding a real code.
     */
    private function diagnoseFailedRedemption(string $canonical, DateTimeImmutable $now): string
    {
        // @tenant-guard-ignore: diagnosing a refusal for the same tenantless caller as the claim above. It reads only whether a code is revoked, expired or exhausted — never who owns it — and the message it produces says nothing a caller could not learn by holding the code.
        $statement = $this->pdo->prepare('
            SELECT revoked_at, expires_at, redemption_count, max_redemptions
              FROM device_activation_codes
             WHERE code = :code
        ');
        $statement->bindValue(':code', $canonical);
        $statement->execute();

        /** @var array{revoked_at: ?string, expires_at: ?string, redemption_count: int|string, max_redemptions: int|string}|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return LicensingException::REASON_INVALID;
        }

        if ($row['revoked_at'] !== null) {
            return LicensingException::REASON_REVOKED;
        }

        if ($row['expires_at'] !== null && $row['expires_at'] <= $now->format('Y-m-d H:i:s')) {
            return LicensingException::REASON_EXPIRED;
        }

        if ((int) $row['redemption_count'] >= (int) $row['max_redemptions']) {
            return LicensingException::REASON_ALREADY_USED;
        }

        // It looked usable a moment ago and the UPDATE still failed — a
        // concurrent redemption took the last one between the two statements.
        // Saying "already used" is the truthful account of what happened.
        return LicensingException::REASON_ALREADY_USED;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock !== null ? ($this->clock)() : new DateTimeImmutable();
    }
}
