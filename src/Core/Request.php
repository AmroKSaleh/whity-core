<?php

declare(strict_types=1);

namespace Whity\Core;

/**
 * HTTP request wrapper — the host-side alias of the SDK request shape.
 *
 * The concrete implementation (headers, body, per-request attribute bag,
 * {@see \Whity\Sdk\Http\Request::ATTR_JWT_CLAIMS}) lives in the standalone
 * `whity/plugin-sdk` package (WC-162) so plugins can type-hint against it
 * without depending on whity-core. Core code keeps constructing and hinting
 * this subclass; every Whity\Core\Request IS an SDK request, so SDK-typed
 * plugin handlers receive it transparently.
 */
class Request extends \Whity\Sdk\Http\Request
{
}
