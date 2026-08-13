<?php

declare(strict_types=1);

namespace Whity\Native;

/**
 * Thrown when a call to the Rust native bridge (hardware access — printers,
 * later scanners) fails for any reason: unreachable, unauthorized, or a
 * non-2xx response. Every NativeBridgeClient failure mode normalizes to this
 * one type, mirroring RenderServiceClient's RenderServiceUnavailableException.
 */
final class NativeBridgeUnavailableException extends \RuntimeException
{
}
