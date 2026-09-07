<?php

declare(strict_types=1);

/**
 * CI emitted-URL version guard (#1046).
 *
 * A URL the backend RETURNS to a client must already carry the version prefix
 * the router serves it at. `Router::versionPrefix()` applies at REGISTRATION, so
 * a declared path is correct written bare; `web/lib/api-client.ts` passes `/api`
 * paths through verbatim and adds nothing, so an emitted one is not. Declared
 * bare, emitted versioned — and both are `'/api/...'` literals, which is why
 * this reads the syntax around them rather than grepping.
 *
 * WHAT IT COST TO NOT HAVE THIS. The convention was already documented in
 * comments at four call sites before #1016. Seven emitters got it right by
 * reading their neighbours; one did not, and nothing caught it — for an entire
 * release, while every document viewer showed an error box on first click. It
 * survived that long because `documents.render_enabled` defaults to false, so no
 * unseeded install ever produced an artifact for the viewer to fail on.
 *
 * The detection logic is {@see \Whity\Core\Api\EmittedUrlVersionGuard} and is
 * pinned in BOTH directions by tests/Unit/Core/Api/EmittedUrlVersionGuardTest.php
 * — including a case asserting it fires on the real #1016 line when that fix is
 * reverted, which is the only evidence that matters for a guard named after it.
 *
 * Mirrors scripts/ci-tenant-predicate-guard.php: standalone, no HTTP/DB, exits
 * non-zero on any violation.
 *
 * Usage:  php scripts/ci-emitted-url-version-guard.php [path ...]
 *         (defaults to scanning src/)
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Whity\Core\Api\EmittedUrlVersionGuard;

$roots = array_slice($argv, 1);
if ($roots === []) {
    $roots = [dirname(__DIR__) . '/src'];
}

$guard = new EmittedUrlVersionGuard();
$violations = [];
foreach ($roots as $root) {
    if (!is_dir($root)) {
        fwrite(STDERR, "FAIL: not a directory: {$root}\n");
        exit(2);
    }
    $violations = array_merge($violations, $guard->scanDirectory($root));
}

if ($violations !== []) {
    fwrite(STDERR, "FAIL: a URL returned to a client is missing the /v1 prefix the router serves it at.\n\n");

    $projectRoot = dirname(__DIR__);
    foreach ($violations as $v) {
        $relative = str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $v['file']);
        $relative = str_replace('\\', '/', $relative);
        fwrite(STDERR, sprintf("  %s:%d\n      %s\n", $relative, $v['line'], $v['literal']));
    }

    fwrite(
        STDERR,
        "\nThe client fetches this string as written. `Router::versionPrefix()` adds /v1 when a\n"
        . "route is REGISTERED, not when a URL is emitted, and web/lib/api-client.ts passes /api\n"
        . "paths through unchanged — so an unversioned emission reaches a path nothing serves.\n\n"
        . "Pass it through the one definition instead of writing the prefix by hand:\n\n"
        . "    \$this->router->versionedPath('/api/things/1')   // -> /api/v1/things/1\n"
        . "    self::apiPath('/api/things/1')                  // inside CoreApiSchemas\n\n"
        . "A ROUTE DECLARATION is different and must stay bare — the router prefixes it. This\n"
        . "guard only reads returned expressions and `*url` values, so declarations are not\n"
        . "its business.\n\n"
        . "It is also blind to a REIMPLEMENTATION of the prefix arithmetic (#1020): a hand-rolled\n"
        . "copy produces correct output today and drifts later. Green here means no bare literal\n"
        . "is emitted, not that every emitted URL is right.\n"
    );

    exit(1);
}

printf("OK: no client-facing URL is emitted without the version prefix (%s).\n", implode(', ', $roots));
