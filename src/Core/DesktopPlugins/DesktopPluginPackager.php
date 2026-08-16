<?php

declare(strict_types=1);

namespace Whity\Core\DesktopPlugins;

use FilesystemIterator;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use Whity\Api\DesktopPluginsApiHandler;
use Whity\Core\PluginInstaller;
use ZipArchive;

/**
 * Builds one obfuscated desktop-plugin package (.zip) from a plugin's source
 * directory, in the exact shape the desktop installer accepts.
 *
 * The output satisfies every check {@see \Whity\Core\PluginInstaller} enforces
 * server-side and the desktop `installer.rs` mirrors client-side:
 *   - exactly ONE top-level directory, whose name === the plugin name
 *     (case-sensitive) — the device rejects anything else before inspecting it;
 *   - the name matches {@see DesktopPluginsApiHandler::NAME_PATTERN} (becomes a
 *     directory on the device's filesystem);
 *   - the same size/entry/ratio guards, using {@see PluginInstaller}'s own
 *     public constants so the two can never drift.
 *
 * Every `.php` file is passed through {@see PluginObfuscator}; the obfuscator is
 * fail-closed, so a transform that would produce un-parseable PHP aborts the
 * build rather than shipping it. Non-PHP files are copied verbatim.
 *
 * The SHA-256 and size are computed ONCE, from the final zip on disk, after it
 * is fully written — never from a pre-image that could drift from the served
 * bytes.
 */
final class DesktopPluginPackager
{
    /** Directory/file names never copied into a package. */
    private const EXCLUDED_NAMES = [
        '.git', '.github', 'node_modules', 'vendor', '.idea', '.vscode',
        '.DS_Store', 'Thumbs.db', '.gitignore', '.gitattributes',
    ];

    public function __construct(
        private readonly PluginObfuscator $obfuscator = new PluginObfuscator(),
    ) {
    }

    /**
     * Package $sourceDir as plugin $name into the file $zipPath.
     *
     * @throws DesktopPluginReleaseException on any validation/guard failure.
     * @throws ObfuscationException if a source file cannot be obfuscated safely.
     */
    public function package(string $sourceDir, string $name, string $zipPath): PackageResult
    {
        if (preg_match(DesktopPluginsApiHandler::NAME_PATTERN, $name) !== 1) {
            throw new DesktopPluginReleaseException(
                "Invalid plugin name '{$name}': must match " . DesktopPluginsApiHandler::NAME_PATTERN
            );
        }

        $realSource = is_dir($sourceDir) ? realpath($sourceDir) : false;
        if ($realSource === false) {
            throw new DesktopPluginReleaseException("Source is not a directory: {$sourceDir}");
        }

        $this->assertNamespacesLoadUnder($realSource, $name);

        $stageRoot = $this->makeTempDir();
        try {
            // Everything lands under a single top-level directory === $name.
            $pluginRoot = $stageRoot . '/' . $name;
            if (!mkdir($pluginRoot, 0o755, true) && !is_dir($pluginRoot)) {
                throw new DesktopPluginReleaseException("Could not create staging directory: {$pluginRoot}");
            }

            $this->copyAndObfuscate($realSource, $pluginRoot);
            $this->assertUncompressedGuards($pluginRoot);
            $this->writeZip($stageRoot, $name, $zipPath);

            return $this->finaliseAndVerify($zipPath, $name);
        } finally {
            $this->removeTree($stageRoot);
        }
    }

    /**
     * Fail early (at release time, not on the device) if the source could not
     * load: every declared namespace must be the plugin name or nested under
     * it, because the device maps the top-level directory name to the namespace
     * root; and at least one class must implement a `PluginInterface`.
     */
    private function assertNamespacesLoadUnder(string $sourceDir, string $name): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new NodeFinder();
        $prefix = $name . '\\';

        // shortName => list of its supertypes' short names (extends + implements).
        /** @var array<string, list<string>> $graph */
        $graph = [];
        // Short names of the instantiable classes the device could `new`.
        /** @var list<string> $concrete */
        $concrete = [];

        foreach ($this->phpFilesUnder($sourceDir) as $file) {
            $code = (string) file_get_contents($file);
            try {
                $ast = $parser->parse($code) ?? [];
            } catch (Throwable $e) {
                throw new DesktopPluginReleaseException(
                    'Source file does not parse: ' . $this->rel($sourceDir, $file) . ' — ' . $e->getMessage()
                );
            }

            /** @var list<Namespace_> $namespaces */
            $namespaces = $finder->findInstanceOf($ast, Namespace_::class);
            foreach ($namespaces as $ns) {
                $declared = $ns->name?->toString();
                if ($declared === null) {
                    continue; // global-namespace block — eager-required, still loads
                }
                if ($declared !== $name && !str_starts_with($declared . '\\', $prefix)) {
                    throw new DesktopPluginReleaseException(
                        "Namespace '{$declared}' in " . $this->rel($sourceDir, $file)
                        . " will not autoload: a plugin named '{$name}' must declare namespaces under '{$name}'."
                    );
                }
            }

            /** @var list<ClassLike> $classes */
            $classes = $finder->findInstanceOf($ast, ClassLike::class);
            foreach ($classes as $classLike) {
                $shortName = $classLike->name?->toString();
                if ($shortName === null) {
                    continue; // anonymous class — never discovered as a top-level plugin
                }

                $supertypes = [];
                if ($classLike instanceof Class_) {
                    if ($classLike->extends !== null) {
                        $supertypes[] = self::shortName($classLike->extends);
                    }
                    foreach ($classLike->implements as $iface) {
                        $supertypes[] = self::shortName($iface);
                    }
                    if (!$classLike->isAbstract()) {
                        $concrete[] = $shortName;
                    }
                } elseif ($classLike instanceof Interface_) {
                    foreach ($classLike->extends as $iface) {
                        $supertypes[] = self::shortName($iface);
                    }
                } elseif ($classLike instanceof Enum_) {
                    foreach ($classLike->implements as $iface) {
                        $supertypes[] = self::shortName($iface);
                    }
                }

                $graph[$shortName] = array_merge($graph[$shortName] ?? [], $supertypes);
            }
        }

        // A package is acceptable if some instantiable class (transitively,
        // through abstract bases and sub-interfaces DECLARED IN THIS SOURCE)
        // reaches PluginInterface — or reaches a supertype defined OUTSIDE the
        // source (an SDK interface/base that may itself extend PluginInterface),
        // which we give the benefit of the doubt rather than false-reject: the
        // device performs the authoritative `instanceof PluginInterface` check.
        foreach ($concrete as $class) {
            [$reachesPluginInterface, $touchesExternal] = self::climbTowardsPluginInterface($class, $graph);
            if ($reachesPluginInterface || $touchesExternal) {
                return;
            }
        }

        throw new DesktopPluginReleaseException(
            "No class in '{$sourceDir}' implements PluginInterface — the device would quarantine this package."
        );
    }

    /**
     * Walk a class's supertype chain within the source graph.
     *
     * @param array<string, list<string>> $graph
     * @return array{0: bool, 1: bool} [reaches PluginInterface, touches a supertype defined outside the source]
     */
    private static function climbTowardsPluginInterface(string $start, array $graph): array
    {
        $seen = [];
        $queue = [$start];
        $reaches = false;
        $external = false;

        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;

            foreach ($graph[$current] ?? [] as $supertype) {
                if ($supertype === 'PluginInterface') {
                    $reaches = true;
                } elseif (isset($graph[$supertype])) {
                    $queue[] = $supertype;
                } else {
                    // Defined outside the source (e.g. an SDK interface/base).
                    $external = true;
                }
            }
        }

        return [$reaches, $external];
    }

    private static function shortName(Node\Name $name): string
    {
        $parts = $name->getParts();

        return (string) end($parts);
    }

    /** Recursively copy $from into $to, obfuscating every .php file. */
    private function copyAndObfuscate(string $from, string $to): void
    {
        $items = scandir($from);
        if ($items === false) {
            throw new DesktopPluginReleaseException("Could not read source directory: {$from}");
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || in_array($item, self::EXCLUDED_NAMES, true)) {
                continue;
            }

            $src = $from . '/' . $item;
            $dst = $to . '/' . $item;

            if (is_dir($src)) {
                if (!mkdir($dst, 0o755, true) && !is_dir($dst)) {
                    throw new DesktopPluginReleaseException("Could not create directory: {$dst}");
                }
                $this->copyAndObfuscate($src, $dst);
                continue;
            }

            if (str_ends_with(strtolower($item), '.php')) {
                file_put_contents($dst, $this->obfuscator->obfuscateFile($src));
            } else {
                if (!copy($src, $dst)) {
                    throw new DesktopPluginReleaseException("Could not copy file: {$src}");
                }
            }
        }
    }

    /**
     * Enforce the entry-count / per-entry / total-uncompressed guards against
     * the staged tree, BEFORE compressing it — mirroring PluginInstaller.
     */
    private function assertUncompressedGuards(string $pluginRoot): void
    {
        $entryCount = 0;
        $totalUncompressed = 0;

        foreach ($this->allFilesUnder($pluginRoot) as $file) {
            $entryCount++;
            if ($entryCount > PluginInstaller::MAX_ZIP_ENTRIES) {
                throw new DesktopPluginReleaseException(
                    'Package has too many entries (limit ' . PluginInstaller::MAX_ZIP_ENTRIES . ').'
                );
            }

            $size = (int) filesize($file);
            if ($size > PluginInstaller::MAX_ENTRY_UNCOMPRESSED_BYTES) {
                throw new DesktopPluginReleaseException(
                    'A file exceeds the per-entry uncompressed limit ('
                    . PluginInstaller::MAX_ENTRY_UNCOMPRESSED_BYTES . ' bytes): ' . basename($file)
                );
            }

            $totalUncompressed += $size;
            if ($totalUncompressed > PluginInstaller::MAX_TOTAL_UNCOMPRESSED_BYTES) {
                throw new DesktopPluginReleaseException(
                    'Package exceeds the total uncompressed limit ('
                    . PluginInstaller::MAX_TOTAL_UNCOMPRESSED_BYTES . ' bytes).'
                );
            }
        }

        if ($entryCount === 0) {
            throw new DesktopPluginReleaseException('Package would be empty.');
        }
    }

    /** Zip $stageRoot/$name into $zipPath, entries prefixed with "$name/". */
    private function writeZip(string $stageRoot, string $name, string $zipPath): void
    {
        $parent = dirname($zipPath);
        if (!is_dir($parent) && !mkdir($parent, 0o755, true) && !is_dir($parent)) {
            throw new DesktopPluginReleaseException("Could not create output directory: {$parent}");
        }
        if (is_file($zipPath) && !unlink($zipPath)) {
            throw new DesktopPluginReleaseException("Could not overwrite existing file: {$zipPath}");
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new DesktopPluginReleaseException("Could not create zip: {$zipPath}");
        }

        $pluginRoot = $stageRoot . '/' . $name;
        foreach ($this->allFilesUnder($pluginRoot) as $file) {
            // Local name relative to the stage root, so the single top-level
            // directory in the archive is exactly "$name".
            $local = str_replace('\\', '/', substr($file, strlen($stageRoot) + 1));
            if (!$zip->addFile($file, $local)) {
                $zip->close();
                throw new DesktopPluginReleaseException("Could not add to zip: {$local}");
            }
        }

        if (!$zip->close()) {
            throw new DesktopPluginReleaseException("Could not finalise zip: {$zipPath}");
        }
    }

    /** Enforce post-compression guards, then compute the checksum and size. */
    private function finaliseAndVerify(string $zipPath, string $name): PackageResult
    {
        clearstatcache(true, $zipPath);
        $sizeBytes = (int) filesize($zipPath);
        if ($sizeBytes > PluginInstaller::MAX_UPLOAD_BYTES) {
            throw new DesktopPluginReleaseException(
                'Package exceeds the maximum size (' . PluginInstaller::MAX_UPLOAD_BYTES . ' bytes).'
            );
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new DesktopPluginReleaseException("Could not reopen zip for verification: {$zipPath}");
        }

        $entryCount = $zip->numFiles;
        $totalUncompressed = 0;
        $totalCompressed = 0;
        for ($i = 0; $i < $entryCount; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                $zip->close();
                throw new DesktopPluginReleaseException('Could not stat a zip entry during verification.');
            }
            $totalUncompressed += (int) $stat['size'];
            $totalCompressed += (int) $stat['comp_size'];
        }
        $zip->close();

        if ($entryCount > PluginInstaller::MAX_ZIP_ENTRIES) {
            throw new DesktopPluginReleaseException(
                'Package has too many entries (limit ' . PluginInstaller::MAX_ZIP_ENTRIES . ').'
            );
        }
        if (PluginInstaller::exceedsCompressionRatio($totalUncompressed, $totalCompressed)) {
            throw new DesktopPluginReleaseException('Package compression ratio exceeds the safe limit.');
        }

        $sha256 = hash_file('sha256', $zipPath);
        if ($sha256 === false) {
            throw new DesktopPluginReleaseException("Could not hash package: {$zipPath}");
        }

        return new PackageResult(
            name: $name,
            zipPath: $zipPath,
            sha256: $sha256,
            sizeBytes: $sizeBytes,
            entryCount: $entryCount,
            uncompressedBytes: $totalUncompressed,
        );
    }

    /**
     * @return list<string> Absolute paths of every regular file under $dir.
     */
    private function allFilesUnder(string $dir): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $info) {
            /** @var \SplFileInfo $info */
            if ($info->isFile()) {
                $files[] = $info->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return list<string> Absolute paths of every .php file under $dir,
     *                      excluding the never-copied directories.
     */
    private function phpFilesUnder(string $dir): array
    {
        $files = [];
        foreach ($this->allFilesUnder($dir) as $file) {
            if (!str_ends_with(strtolower($file), '.php')) {
                continue;
            }
            $normalised = str_replace('\\', '/', $file);
            foreach (self::EXCLUDED_NAMES as $excluded) {
                if (str_contains($normalised, '/' . $excluded . '/')) {
                    continue 2;
                }
            }
            $files[] = $file;
        }

        return $files;
    }

    private function rel(string $base, string $path): string
    {
        return ltrim(str_replace('\\', '/', substr($path, strlen($base))), '/');
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/whity-dpkg-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new DesktopPluginReleaseException("Could not create temporary directory: {$dir}");
        }

        return $dir;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $info) {
            /** @var \SplFileInfo $info */
            if ($info->isDir()) {
                @rmdir($info->getPathname());
            } else {
                @unlink($info->getPathname());
            }
        }
        @rmdir($dir);
    }
}
