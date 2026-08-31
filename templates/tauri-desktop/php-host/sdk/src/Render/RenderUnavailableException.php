<?php

declare(strict_types=1);

namespace Whity\Sdk\Render;

/**
 * The render tier could not do the work (SDK 1.41).
 *
 * The request was fine; the machinery was not. The renderer is an OPTIONAL
 * component — a separate headless-browser container that a sovereign
 * deployment may simply not run — so this is an ordinary, expected outcome and
 * not an exceptional one. Every plugin that renders must have an answer for it.
 *
 * Also thrown when rendering is switched OFF for the instance, and that is not
 * a special case worth its own type: from a plugin's side "the operator has not
 * enabled this" and "the container is restarting" call for the same handling —
 * do not lose the user's work, do not retry in a tight loop, and say that the
 * document could not be produced right now.
 *
 * The message is for LOGS. It names the failing component and is written for an
 * operator, so a host must not interpolate it into a response body — the
 * distinction {@see RenderRejectedException} draws with its `clientMessage`
 * exists precisely because this type has no equivalent.
 */
final class RenderUnavailableException extends \RuntimeException
{
}
