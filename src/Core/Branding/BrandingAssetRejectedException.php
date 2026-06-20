<?php

declare(strict_types=1);

namespace Whity\Core\Branding;

/**
 * Thrown when an uploaded branding asset fails validation (Tenant Branding).
 *
 * Carries a controlled, user-facing reason string via {@see reason()} so the
 * API layer can surface helpful feedback (e.g. "Unsupported file type",
 * "exceeds max size") without leaking raw exception text into the response
 * (WC-186 regression guard).
 */
final class BrandingAssetRejectedException extends \RuntimeException
{
    private string $userReason;

    public function __construct(string $userReason, ?\Throwable $previous = null)
    {
        $this->userReason = $userReason;
        parent::__construct($userReason, 0, $previous);
    }

    /**
     * The safe, user-facing rejection reason.
     *
     * Use this — never getMessage() — in API response bodies.
     */
    public function reason(): string
    {
        return $this->userReason;
    }
}
