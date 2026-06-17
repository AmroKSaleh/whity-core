<?php

declare(strict_types=1);

namespace Whity\Storage;

/**
 * Thrown by {@see StorageDriverInterface} implementations on any storage error
 * (missing key, permission denied, network failure, etc.).
 */
class StorageException extends \RuntimeException
{
}
