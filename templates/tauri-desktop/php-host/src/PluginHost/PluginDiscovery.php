<?php

declare(strict_types=1);

namespace Whity\PluginHost;

use Whity\Sdk\PluginInterface;

/**
 * Scans direct subdirectories of the plugins root for classes implementing
 * PluginInterface — the "arbitrary plugin" replacement for a manually
 * maintained WHITY_PLUGINS FQCN list.
 *
 * Technique: require_once every .php file under a plugin directory (same
 * eager-require approach production's own live-discovery path uses), then
 * diff get_declared_classes() before/after to find what that directory
 * contributed, and reflect each new class for PluginInterface. This needs no
 * `<Dir>Plugin.php` naming convention — a plugin's entry class can be named
 * anything — but callers must register the PSR-4 autoloader (see
 * PluginRuntimeLoader::registerPluginNamespaces()/registerAutoloader())
 * BEFORE calling discover(), so cross-file class references inside a
 * directory resolve regardless of require order.
 *
 * A directory that contributes zero PluginInterface implementers is logged,
 * not fatal — one malformed/empty plugin directory must never take down
 * discovery for the rest.
 */
final class PluginDiscovery
{
    /**
     * @return list<class-string<PluginInterface>>
     */
    public static function discover(string $pluginsRoot): array
    {
        if (!is_dir($pluginsRoot)) {
            return [];
        }

        $entries = scandir($pluginsRoot);
        if ($entries === false) {
            return [];
        }

        $found = [];

        foreach ($entries as $entry) {
            if (str_starts_with($entry, '.')) {
                continue;
            }

            $dirPath = $pluginsRoot . '/' . $entry;
            if (!is_dir($dirPath)) {
                continue;
            }

            $before = get_declared_classes();
            foreach (self::phpFilesUnder($dirPath) as $file) {
                require_once $file;
            }
            $newClasses = array_diff(get_declared_classes(), $before);

            $contributed = [];
            foreach ($newClasses as $class) {
                if (self::isLoadablePlugin($class)) {
                    $contributed[] = $class;
                }
            }

            if ($contributed === []) {
                error_log("[php-host] plugin directory '{$entry}' contributed no loadable PluginInterface class");
                continue;
            }

            array_push($found, ...$contributed);
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private static function phpFilesUnder(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            if ($fileInfo->getExtension() === 'php') {
                $files[] = $fileInfo->getPathname();
            }
        }

        return $files;
    }

    private static function isLoadablePlugin(string $class): bool
    {
        if (!class_exists($class)) {
            return false;
        }

        $reflection = new \ReflectionClass($class);
        if ($reflection->isAbstract() || $reflection->isInterface()) {
            return false;
        }

        return $reflection->implementsInterface(PluginInterface::class);
    }
}
