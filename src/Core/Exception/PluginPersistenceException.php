<?php

declare(strict_types=1);

namespace Whity\Core\Exception;

/**
 * Raised when an administrative plugin disable / re-enable was applied to the
 * CURRENT worker's in-memory state, but the on-disk signal that lets every OTHER
 * FrankenPHP worker converge (WC-210) could not be persisted (a read-only /
 * full / permission-denied `plugins/` directory).
 *
 * The operation is therefore only PARTIALLY applied — the fleet will not
 * converge — so the caller must NOT report success; it should surface the
 * failure and the operator should fix the filesystem and retry.
 */
final class PluginPersistenceException extends \RuntimeException
{
}
