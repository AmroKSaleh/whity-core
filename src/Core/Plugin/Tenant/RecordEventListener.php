<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Tenant;

use Whity\Sdk\Manifest\PluginManifest;

/**
 * Told about every write to a workspace plugin's record, inside the write's
 * transaction (WHIT-763). The automation triggers are the one listener.
 */
interface RecordEventListener
{
    public function recordWritten(int $tenantId, PluginManifest $manifest, RecordEvent $event): void;
}
