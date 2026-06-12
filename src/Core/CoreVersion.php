<?php

declare(strict_types=1);

namespace Whity\Core;

/**
 * The platform core version — the single source of truth (WC-172).
 *
 * Plain MAJOR.MINOR.PATCH semver, no `v` prefix. Everything else derives
 * from this constant:
 *  - `GET /api/health` reports it so operators can read a deployment's version
 *    remotely;
 *  - `php public/index.php update:check` compares it against the latest
 *    GitHub release;
 *  - the release workflow (.github/workflows/release.yml) REFUSES to publish
 *    a tag whose name does not equal `v` + this constant, so a release can
 *    never ship with a lying version.
 *
 * Bump it in the same PR that prepares a release, then tag the merge commit
 * `v<VERSION>` (see docs/wiki/Core-Update.md).
 */
final class CoreVersion
{
    public const VERSION = '0.1.0';

    private function __construct()
    {
    }
}
