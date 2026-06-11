<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * SDK identity (v1.1).
 *
 * {@see self::VERSION} is the version a host application evaluates plugin
 * SDK-constraints against ({@see PluginRequirementsInterface::getSdkConstraint()}).
 * It MUST match the `version` field in the package's composer.json — the host
 * test suite pins the two together so they cannot drift.
 *
 * Versioning policy (additive): new capabilities land in minor versions —
 * 1.0 (contract extraction) → 1.1 (requirements declaration, this class) →
 * 1.2 (frontend feature descriptor, planned). Breaking changes require a new
 * major version.
 */
final class Sdk
{
    /** The SDK contract version shipped by this package. */
    public const VERSION = '1.1.0';

    /**
     * Static identity only — never instantiated.
     */
    private function __construct()
    {
    }
}
