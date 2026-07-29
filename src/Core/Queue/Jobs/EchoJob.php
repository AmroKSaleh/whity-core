<?php

declare(strict_types=1);

namespace Whity\Core\Queue\Jobs;

use Whity\Sdk\JobInterface;

/**
 * A minimal, side-effect-free diagnostic job (WC-jobs-api). Registered as the
 * one API-SUBMITTABLE core job so the generic POST /api/jobs → worker → GET
 * status/result path is exercisable out of the box, and as the reference for
 * how a handler opts into public submission (JobRegistry::register(..., true)).
 *
 * It simply echoes its payload back as the job result, so a caller that submits
 * it and later polls GET /api/jobs/{id} sees `result.echoed` equal to what they
 * sent. Pure and therefore trivially idempotent (re-running yields the same
 * result), satisfying the at-least-once JobInterface contract.
 */
final class EchoJob implements JobInterface
{
    public const NAME = 'core.diagnostics.echo';

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        return ['echoed' => $payload];
    }
}
