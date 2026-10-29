<?php

declare(strict_types=1);

// One temp directory per ParaTest worker. ParaTest gives every worker process a
// distinct TEST_TOKEN; plain `vendor/bin/phpunit` sets none and is unaffected.
//
// Many tests build paths from sys_get_temp_dir() with FIXED names
// (`wc205_migrations_shared`, `whity-storage-test`, `whity_<slug>`, the
// heartbeat file) or scan it for leftovers (PluginInstallerTest globs
// `whity_plugin_upload_*` and asserts none remain). Serially that is fine; with
// workers running side by side, one worker's fixture is another's leftover, and
// one worker's cleanup deletes a directory another is still using. Pointing
// TMPDIR at a per-token subdirectory fixes every such path in one place instead
// of renaming each of them, and keeps fixing the ones written later.
//
// This must run before anything calls sys_get_temp_dir(): PHP resolves TMPDIR on
// the first call and caches the answer for the life of the process. Child
// processes the tests spawn (proc_open) inherit the same TMPDIR.
$paratestToken = getenv('TEST_TOKEN');
if (is_string($paratestToken) && $paratestToken !== '') {
    // TMPDIR is what PHP reads on Linux and macOS; Windows reads TMP / TEMP.
    $baseTmp = getenv('TMPDIR') ?: getenv('TMP') ?: getenv('TEMP') ?: '/tmp';
    $workerTmp = rtrim((string) $baseTmp, '/\\')
        . DIRECTORY_SEPARATOR . 'whity-paratest-' . getmypid() . '-' . $paratestToken;
    if (!is_dir($workerTmp) && !mkdir($workerTmp, 0o700, true) && !is_dir($workerTmp)) {
        throw new RuntimeException("Cannot create per-worker temp directory {$workerTmp}");
    }
    foreach (['TMPDIR', 'TMP', 'TEMP'] as $tmpVar) {
        putenv($tmpVar . '=' . $workerTmp);
        $_ENV[$tmpVar] = $workerTmp;
    }
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/helpers.php';
