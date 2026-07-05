<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/helpers.php';

/**
 * Worktree autoload override.
 *
 * The vendor/ directory in this worktree is a symlink to /app/vendor, whose
 * PSR-4 map and classmap resolve Whity\* to /app/src/* and Tests\* to
 * /app/tests/*.  We need them to resolve to *this* worktree instead.
 *
 * Strategy:
 * 1. Prepend the worktree src/tests paths for PSR-4 namespaces (prepend=true
 *    puts them before the /app paths so the worktree wins on first-match).
 * 2. Override classmap entries that point specific FQCNs at /app files
 *    (classmap beats PSR-4, so we must explicitly remap them).
 */
(static function (): void {
    $worktreeRoot = dirname(__DIR__);

    foreach (spl_autoload_functions() as $fn) {
        if (!is_array($fn)) {
            continue;
        }
        [$loader] = $fn;
        if (!($loader instanceof \Composer\Autoload\ClassLoader)) {
            continue;
        }

        // Prepend worktree source directories (takes priority via first-match).
        $loader->addPsr4('Whity\\Auth\\',     [$worktreeRoot . '/src/Auth'],     true);
        $loader->addPsr4('Whity\\Api\\',      [$worktreeRoot . '/src/Api'],      true);
        $loader->addPsr4('Whity\\Core\\',     [$worktreeRoot . '/src/Core'],     true);
        $loader->addPsr4('Whity\\Database\\', [$worktreeRoot . '/src/Database'], true);
        $loader->addPsr4('Whity\\Plugins\\',  [$worktreeRoot . '/plugins'],      true);
        $loader->addPsr4('Tests\\',           [$worktreeRoot . '/tests'],        true);

        // Classmap entries for files listed in composer.json "classmap" field
        // override PSR-4 and must be patched to point at the worktree.
        $loader->addClassMap([
            'Whity\\Auth\\AuthHandler'          => $worktreeRoot . '/src/Auth/AuthHandler.php',
            'Whity\\Auth\\DatabaseQueryWrapper'  => $worktreeRoot . '/src/Auth/AuthHandler.php',
        ]);

        break;
    }
})();
