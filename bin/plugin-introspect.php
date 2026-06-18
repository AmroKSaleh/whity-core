<?php

/**
 * Isolated plugin introspector (WC-220).
 *
 * Run as a short-lived CHILD process by {@see \Whity\Core\PluginInstaller} so
 * untrusted uploaded plugin code is NEVER loaded into the host process. Given a
 * list of staged PHP file paths (argv), it:
 *  - loads the host autoloader so the SDK contract types resolve;
 *  - requires each staged file, catching parse/load errors;
 *  - finds the classes implementing {@see \Whity\Sdk\PluginInterface};
 *  - requires EXACTLY ONE, instantiates it, and reads getName()/getVersion()
 *    plus the route/permission counts and (optional) SDK/core constraints;
 *  - prints a single JSON object to stdout, delimited by unique sentinel
 *    markers, and exits.
 *
 * The class definition + constructor + accessor calls run only here, in this
 * disposable process, so they cannot pollute a long-lived FrankenPHP worker or
 * clash with the loader when it later loads the committed copy.
 *
 * TAMPER RESISTANCE (WC-220 hardening, re-review M3): the staged plugin's
 * top-level code runs during `require` and could `echo` a forged result then
 * `exit` before this script emits anything. To make pre-emit plugin output
 * non-authoritative the genuine result is written between NONCE-KEYED sentinel
 * markers and the parent reads ONLY the bytes between the markers carrying THIS
 * run's nonce. The plugin therefore cannot forge a block the parent accepts —
 * unless it can RECOVER the nonce.
 *
 * Recovering the nonce must be impossible. An earlier design passed the nonce as
 * a command-line argument and scrubbed it from `$argv`/`$_SERVER`/`$GLOBALS`
 * before loading the plugin. That was BROKEN: the kernel still exposes the
 * ORIGINAL argv at `/proc/self/cmdline` (and env at `/proc/self/environ`), which
 * PHP-level scrubbing cannot remove, so a plugin could read the nonce there and
 * forge an accepted block. The robust fix is to never put the secret anywhere
 * the child's own process can read:
 *  - the nonce is delivered over STDIN (NOT argv, NOT env), so it appears in no
 *    process-visible table (`/proc/self/cmdline`, `/proc/self/environ`);
 *  - it is read and consumed FIRST, before any plugin code runs; re-reading
 *    STDIN yields EOF, so the plugin cannot recover it from there either;
 *  - the plugin file is required inside an ISOLATED closure that does NOT import
 *    the `$nonce` variable, so the plugin's top-level code cannot read it from
 *    the enclosing scope.
 * With cmdline, environ, stdin, and the variable scope all closed, the plugin
 * has no channel to learn the nonce, so it cannot print a marker pair the parent
 * will accept. A plugin that prints forged markers (wrong/blank nonce) or
 * `exit(0)`s without the genuine emit yields no valid nonce-keyed block → the
 * parent treats it as an inspection failure.
 *
 * Defence in depth still applies: an output buffer is opened at the very top and
 * DISCARDED immediately before the genuine emit, so pre-emit plugin output never
 * reaches stdout.
 *
 * Output contract (stdout):
 *  - ===WC-INTROSPECT-BEGIN:<nonce>==={json}===WC-INTROSPECT-END:<nonce>===
 *  - the JSON object is either
 *    {"status":"ok","plugin":{name,version,routes_count,permissions_count,
 *    sdk_constraint,core_constraint}} or
 *    {"status":"error","reason":"none|multiple|load|instantiate|introspect"}
 *
 * This script exits 0 on a logical error (the structured reason carries the
 * outcome); only a fatal it cannot trap yields a non-zero exit, which the
 * parent treats as a generic inspection failure.
 *
 * @return void
 */

declare(strict_types=1);

(static function (array $argv): void {
    // The parent delivers a single-use random NONCE over STDIN (NOT argv, NOT
    // env), so it appears in no process-visible table the about-to-run plugin
    // code could read — not `/proc/self/cmdline`, not `/proc/self/environ`
    // (WC-220 M3 re-review). Read and CONSUME it FIRST, before any plugin code
    // runs: a later re-read of STDIN by the plugin yields EOF, so the plugin
    // cannot recover the nonce from there either. Hold it in a local variable
    // that the isolated plugin-require closure below does NOT import.
    $nonce = '';
    $stdinLine = fgets(STDIN);
    if (is_string($stdinLine)) {
        $nonce = trim($stdinLine);
    }

    // The staged plugin files are the only argv entries (no secret in argv).
    $files = array_slice($argv, 1);

    // Unique sentinels delimiting the genuine result on stdout, suffixed with
    // the unguessable nonce. Kept in sync with
    // PluginInstaller::INTROSPECT_BEGIN_MARKER / INTROSPECT_END_MARKER.
    $beginMarker = '===WC-INTROSPECT-BEGIN:' . $nonce . '===';
    $endMarker = '===WC-INTROSPECT-END:' . $nonce . '===';

    // Capture EVERYTHING the (untrusted) plugin code prints during require/
    // instantiate so it can never reach stdout ahead of — or be confused with —
    // the genuine, marker-delimited result emitted below.
    ob_start();

    /**
     * Discard any buffered plugin output and emit the genuine result between the
     * sentinel markers, then exit 0 so no further (plugin) code can run or print.
     *
     * @param array<string, mixed> $payload
     */
    $emit = static function (array $payload) use ($beginMarker, $endMarker): never {
        // Drop every output buffer the plugin (or this script) opened, so the
        // only thing written to the real stdout is the delimited result.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{"status":"error","reason":"introspect"}';
        }

        fwrite(STDOUT, $beginMarker . $json . $endMarker);
        exit(0);
    };

    if ($nonce === '') {
        $emit(['status' => 'error', 'reason' => 'introspect']);
    }

    // Locate the host autoloader (this script lives in <root>/bin).
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        $emit(['status' => 'error', 'reason' => 'introspect']);
    }
    require $autoload;

    if ($files === []) {
        $emit(['status' => 'error', 'reason' => 'none']);
    }

    // Require the staged plugin file(s) inside an ISOLATED closure that does NOT
    // `use` the $nonce (or any secret) — the plugin's top-level code runs here
    // and so cannot read the nonce from the enclosing scope. Combined with the
    // nonce never being in argv/env (cmdline/environ) and STDIN already being
    // consumed to EOF, the plugin has no channel to recover the nonce.
    $loadPlugin = static function (string $pluginFile): void {
        require $pluginFile;
    };

    $before = get_declared_classes();
    foreach ($files as $file) {
        if (!is_string($file) || !is_file($file)) {
            continue;
        }
        try {
            $loadPlugin($file);
        } catch (\Throwable $e) {
            $emit(['status' => 'error', 'reason' => 'load']);
        }
    }
    $after = get_declared_classes();

    $pluginClasses = [];
    foreach (array_diff($after, $before) as $class) {
        try {
            $reflection = new \ReflectionClass($class);
            if ($reflection->isInstantiable() && $reflection->implementsInterface(\Whity\Sdk\PluginInterface::class)) {
                $pluginClasses[] = $class;
            }
        } catch (\Throwable) {
            continue;
        }
    }
    $pluginClasses = array_values(array_unique($pluginClasses));

    if (count($pluginClasses) === 0) {
        $emit(['status' => 'error', 'reason' => 'none']);
    }
    if (count($pluginClasses) > 1) {
        $emit(['status' => 'error', 'reason' => 'multiple']);
    }

    $fqcn = $pluginClasses[0];

    try {
        /** @var \Whity\Sdk\PluginInterface $plugin */
        $plugin = new $fqcn();
    } catch (\Throwable $e) {
        $emit(['status' => 'error', 'reason' => 'instantiate']);
    }

    try {
        $name = $plugin->getName();
        $version = $plugin->getVersion();
        $routesCount = count($plugin->getRoutes());
        $permissionsCount = count($plugin->getPermissions());

        $sdkConstraint = null;
        $coreConstraint = null;
        if ($plugin instanceof \Whity\Sdk\PluginRequirementsInterface) {
            $sdkConstraint = $plugin->getSdkConstraint();
            $coreConstraint = $plugin->getCoreConstraint();
        }
    } catch (\Throwable $e) {
        $emit(['status' => 'error', 'reason' => 'introspect']);
    }

    $emit([
        'status' => 'ok',
        'plugin' => [
            'name' => $name,
            'version' => $version,
            'routes_count' => $routesCount,
            'permissions_count' => $permissionsCount,
            'sdk_constraint' => $sdkConstraint,
            'core_constraint' => $coreConstraint,
        ],
    ]);
})($argv);
