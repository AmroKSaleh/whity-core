<?php

declare(strict_types=1);

namespace Whity\Core\Document\Render;

/**
 * The render request itself is not acceptable — a malformed `dataRows`, or a
 * batch/size ceiling exceeded.
 *
 * Distinct from {@see RenderServiceUnavailableException}, which says the
 * SERVICE could not do the work (503). This one says the CALLER asked for
 * something that will not be attempted (422), and its message is written to be
 * shown: it names the limit that was hit, because a bare "too large" leaves the
 * caller guessing at a number that is tenant-configurable and therefore not
 * knowable from the outside.
 */
final class DocumentRenderRejectedException extends \RuntimeException
{
}
