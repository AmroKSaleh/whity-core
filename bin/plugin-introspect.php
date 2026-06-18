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
 * TAMPER RESISTANCE (WC-220 hardening): the staged plugin's top-level code runs
 * during `require` and could `echo` a forged result then `exit` before this
 * script emits anything. To make pre-emit plugin output non-authoritative:
 *  - the parent passes a single-use random NONCE as the first argument, which
 *    this script captures and SCRUBS (from the argv copy, $_SERVER, $GLOBALS,
 *    $argc) BEFORE loading any plugin code, so the plugin cannot read it;
 *  - an output buffer is opened at the very top and DISCARDED immediately
 *    before the genuine emit;
 *  - the genuine result is written between NONCE-KEYED sentinel markers and the
 *    process exits 0.
 * The parent reads ONLY the bytes between the markers carrying THIS run's
 * nonce, and requires exactly one valid JSON object and a zero exit code.
 * Anything else — including a plugin that itself printed forged markers (it
 * cannot know the nonce) and then exited — is treated as an inspection failure.
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
    // The parent passes a single-use random NONCE as the first argument. It is
    // embedded in the result markers so a plugin that itself prints forged
    // markers at top level (then exits before the genuine emit) cannot forge a
    // block the parent will accept: the parent requires the markers to carry
    // the exact nonce it generated, which the plugin cannot guess (WC-220 M3).
    // Capture it FIRST, then SCRUB it from every place the about-to-run plugin
    // code could read it ($argv copy, $_SERVER, $GLOBALS, $argc) so the plugin
    // cannot recover the nonce and replay it.
    $nonce = isset($argv[1]) ? (string) $argv[1] : '';

    $files = array_slice($argv, 2);

    // Scrub the nonce from anywhere the untrusted plugin code could observe it.
    if (isset($_SERVER['argv']) && is_array($_SERVER['argv'])) {
        unset($_SERVER['argv'][1]);
        $_SERVER['argv'] = array_values($_SERVER['argv']);
        $_SERVER['argc'] = count($_SERVER['argv']);
    }
    if (isset($GLOBALS['argv']) && is_array($GLOBALS['argv'])) {
        unset($GLOBALS['argv'][1]);
        $GLOBALS['argv'] = array_values($GLOBALS['argv']);
    }
    if (isset($GLOBALS['argc'])) {
        $GLOBALS['argc'] = isset($GLOBALS['argv']) && is_array($GLOBALS['argv'])
            ? count($GLOBALS['argv'])
            : 0;
    }
    $argv = [];

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

    $before = get_declared_classes();
    foreach ($files as $file) {
        if (!is_string($file) || !is_file($file)) {
            continue;
        }
        try {
            require_once $file;
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
