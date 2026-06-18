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
 *  - prints a single JSON object to stdout and exits.
 *
 * The class definition + constructor + accessor calls run only here, in this
 * disposable process, so they cannot pollute a long-lived FrankenPHP worker or
 * clash with the loader when it later loads the committed copy.
 *
 * Output contract (stdout, single JSON object):
 *  - {"status":"ok","plugin":{name,version,routes_count,permissions_count,
 *    sdk_constraint,core_constraint}}
 *  - {"status":"error","reason":"none|multiple|load|instantiate|introspect"}
 *
 * This script intentionally has NO side effects beyond stdout and exits 0 even
 * on a logical error (the structured reason carries the outcome); only a fatal
 * it cannot trap yields a non-zero exit, which the parent treats as a generic
 * inspection failure.
 *
 * @return void
 */

declare(strict_types=1);

(static function (array $argv): void {
    $emit = static function (array $payload): void {
        // Single line of JSON on stdout; nothing else must precede it.
        echo json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{"status":"error","reason":"introspect"}';
    };

    // Locate the host autoloader (this script lives in <root>/bin).
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        $emit(['status' => 'error', 'reason' => 'introspect']);
        return;
    }
    require $autoload;

    $files = array_slice($argv, 1);
    if ($files === []) {
        $emit(['status' => 'error', 'reason' => 'none']);
        return;
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
            return;
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
        return;
    }
    if (count($pluginClasses) > 1) {
        $emit(['status' => 'error', 'reason' => 'multiple']);
        return;
    }

    $fqcn = $pluginClasses[0];

    try {
        /** @var \Whity\Sdk\PluginInterface $plugin */
        $plugin = new $fqcn();
    } catch (\Throwable $e) {
        $emit(['status' => 'error', 'reason' => 'instantiate']);
        return;
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
        return;
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
