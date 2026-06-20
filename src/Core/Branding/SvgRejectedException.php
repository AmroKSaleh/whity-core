<?php

declare(strict_types=1);

namespace Whity\Core\Branding;

/** Thrown when an SVG cannot be safely parsed/sanitized (Tenant Branding). */
final class SvgRejectedException extends \RuntimeException
{
}
