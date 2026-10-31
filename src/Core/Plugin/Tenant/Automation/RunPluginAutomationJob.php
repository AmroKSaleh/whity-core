<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Tenant\Automation;

use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\JobInterface;

/**
 * The background job that runs one workspace plugin automation run
 * (WHIT-763). Internal: only {@see AutomationTriggers} enqueues it, with the
 * run's tenant, and the job runner restores that tenant before calling it.
 */
final class RunPluginAutomationJob implements JobInterface
{
    public const NAME = 'core.plugin_automation.run';

    public function __construct(private readonly AutomationRunner $runner)
    {
    }

    public function handle(array $payload): array
    {
        $tenantId = TenantContext::getTenantId();
        $runId = $payload['run_id'] ?? null;
        if ($tenantId === null || !is_int($runId)) {
            return ['status' => 'invalid'];
        }

        return $this->runner->run($tenantId, $runId);
    }
}
