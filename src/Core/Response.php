<?php

declare(strict_types=1);

namespace Whity\Core;

/**
 * HTTP response wrapper — the host-side alias of the SDK response shape.
 *
 * The concrete implementation (status/body/headers, json()/error() factories,
 * send()) lives in the standalone `whity/plugin-sdk` package (WC-162) so
 * plugins can construct responses without depending on whity-core. The SDK
 * factories use late static binding, so `Whity\Core\Response::json()` still
 * returns this subclass and every core-typed signature keeps holding.
 *
 * SDK-typed plugin handlers return the SDK base type; the HTTP pipeline
 * (HttpKernel + middleware) accepts the base where plugin returns flow.
 */
class Response extends \Whity\Sdk\Http\Response
{
}
