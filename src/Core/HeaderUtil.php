<?php

declare(strict_types=1);

namespace Whity\Core;

/**
 * HTTP header normalization utility — host-side alias of the SDK helper.
 *
 * The implementation moved to the standalone `whity/plugin-sdk` package
 * (WC-162) together with the Request/Response shapes that use it.
 */
class HeaderUtil extends \Whity\Sdk\Http\HeaderUtil
{
}
