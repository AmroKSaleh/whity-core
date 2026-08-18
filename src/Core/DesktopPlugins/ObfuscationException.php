<?php

declare(strict_types=1);

namespace Whity\Core\DesktopPlugins;

use RuntimeException;

/**
 * Thrown when source cannot be obfuscated safely — a parse failure on the input,
 * or (fail-closed) transformed output that no longer parses. Never carries raw
 * source in its message.
 */
final class ObfuscationException extends RuntimeException
{
}
