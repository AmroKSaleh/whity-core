<?php

declare(strict_types=1);

namespace Whity\Core\Exception;

/**
 * Raised when an uploaded plugin's declared SDK/core constraints are not
 * satisfied by this host (WC-220, reusing the WC-211 version gate).
 *
 * Evaluated BEFORE the artifact is committed to disk: an incompatible plugin is
 * never staged. Maps to HTTP 422 (well-formed package, but unprocessable on
 * this host).
 */
final class PluginIncompatible extends PluginInstallException
{
}
