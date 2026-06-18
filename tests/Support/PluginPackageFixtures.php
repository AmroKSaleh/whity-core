<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use ZipArchive;

/**
 * Builds plugin-upload packages programmatically for the WC-220 installer tests.
 *
 * The malicious archives (zip-slip, zip-bomb) are BUILT here at test time rather
 * than committed as binaries: a committed malicious archive may trip repository
 * scanners, and the threat model is clearer when the bytes are produced in code.
 * Valid packages are assembled with {@see ZipArchive}; the zip-slip archive is
 * hand-assembled because ZipArchive normalises away `..` traversal entries.
 *
 * Every method returns the absolute path to a freshly written file under a
 * caller-provided working directory, so a test owns the lifecycle and cleanup.
 */
final class PluginPackageFixtures
{
    /**
     * Source of a directory plugin that implements PluginInterface +
     * PluginRequirementsInterface (compatible constraints) and declares one
     * migration class. The {placeholders} are substituted per call so tests can
     * vary the plugin name and class namespace independently.
     */
    private const PLUGIN_TEMPLATE = <<<'PHP'
<?php

declare(strict_types=1);

namespace {NAMESPACE};

use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginRequirementsInterface;
use Whity\Sdk\MigrationInterface;

final class {CLASS} implements PluginInterface, PluginRequirementsInterface
{
    public function getName(): string
    {
        return '{PLUGIN_NAME}';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getRoutes(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [];
    }

    public function getHooks(): array
    {
        return [];
    }

    public function getMigrations(): array
    {
        return [\{NAMESPACE}\Migrations\{MIGRATION_CLASS}::class];
    }

    public function getSdkConstraint(): string
    {
        return '{SDK_CONSTRAINT}';
    }

    public function getCoreConstraint(): string
    {
        return '{CORE_CONSTRAINT}';
    }

    public function getPluginDependencies(): array
    {
        return [];
    }
}

namespace {NAMESPACE}\Migrations;

use Whity\Sdk\MigrationInterface;

final class {MIGRATION_CLASS} implements MigrationInterface
{
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS {TABLE} (id INTEGER PRIMARY KEY, label VARCHAR(50))'
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS {TABLE}');
    }
}
PHP;

    /**
     * Source of a single-file plugin (no migrations). Lands directly under
     * plugins/<Name>.php so its FQCN is Whity\Plugins\<Name>.
     */
    private const SINGLE_FILE_TEMPLATE = <<<'PHP'
<?php

declare(strict_types=1);

namespace Whity\Plugins;

use Whity\Sdk\PluginInterface;

final class {CLASS} implements PluginInterface
{
    public function getName(): string
    {
        return '{PLUGIN_NAME}';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getRoutes(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [];
    }

    public function getHooks(): array
    {
        return [];
    }

    public function getMigrations(): array
    {
        return [];
    }
}
PHP;

    /**
     * Render the directory-plugin Plugin.php source for a given plugin name.
     *
     * @param string $pluginName The plugin's declared name (also the dir name).
     * @param string $sdkConstraint SDK constraint (default: compatible '^1.5').
     * @param string $coreConstraint Core constraint (default: none).
     * @param string|null $migrationClass Migration class short name.
     * @param string|null $table Table name the migration creates.
     * @return string The PHP source.
     */
    public static function directoryPluginSource(
        string $pluginName,
        string $sdkConstraint = '^1.5',
        string $coreConstraint = '',
        ?string $migrationClass = null,
        ?string $table = null,
    ): string {
        $migrationClass ??= 'Create' . $pluginName . 'Table';
        $table ??= strtolower($pluginName) . '_items';

        return strtr(self::PLUGIN_TEMPLATE, [
            '{NAMESPACE}' => $pluginName,
            '{CLASS}' => 'Plugin',
            '{PLUGIN_NAME}' => $pluginName,
            '{MIGRATION_CLASS}' => $migrationClass,
            '{SDK_CONSTRAINT}' => $sdkConstraint,
            '{CORE_CONSTRAINT}' => $coreConstraint,
            '{TABLE}' => $table,
        ]);
    }

    /**
     * Build a valid directory-plugin zip: <Name>/Plugin.php (+ a migration).
     *
     * @param string $workDir Directory to write the .zip into (must exist).
     * @param string $pluginName Plugin/dir name.
     * @param string $sdkConstraint SDK constraint.
     * @param string $coreConstraint Core constraint.
     * @return string Absolute path to the created .zip.
     */
    public static function validDirectoryZip(
        string $workDir,
        string $pluginName = 'AcmeUploaded',
        string $sdkConstraint = '^1.5',
        string $coreConstraint = '',
    ): string {
        $zipPath = $workDir . '/' . $pluginName . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create zip at {$zipPath}");
        }

        $zip->addFromString(
            $pluginName . '/Plugin.php',
            self::directoryPluginSource($pluginName, $sdkConstraint, $coreConstraint)
        );
        // A non-PHP companion file proves the extractor copies the whole tree.
        $zip->addFromString($pluginName . '/README.md', "# {$pluginName}\n");

        $zip->close();

        return $zipPath;
    }

    /**
     * Build a valid single-file plugin: <Name>.php (no nesting). Returned as a
     * .php artifact (not zipped) — the installer's single-file path.
     *
     * @param string $workDir Directory to write the .php into (must exist).
     * @param string $pluginName Plugin/class name.
     * @return string Absolute path to the created .php.
     */
    public static function validSinglePhp(string $workDir, string $pluginName = 'AcmeSingle'): string
    {
        $path = $workDir . '/' . $pluginName . '.php';
        $source = strtr(self::SINGLE_FILE_TEMPLATE, [
            '{CLASS}' => $pluginName,
            '{PLUGIN_NAME}' => $pluginName,
        ]);
        if (file_put_contents($path, $source) === false) {
            throw new RuntimeException("Could not write single-file plugin at {$path}");
        }

        return $path;
    }

    /**
     * Build a valid directory-plugin zip whose declared migration THROWS in
     * up() — used to prove migration-on-enable leaves the plugin disabled.
     *
     * @param string $workDir Directory to write the .zip into (must exist).
     * @param string $pluginName Plugin/dir name.
     * @return string Absolute path to the created .zip.
     */
    public static function throwingMigrationZip(string $workDir, string $pluginName = 'ThrowingMig'): string
    {
        $zipPath = $workDir . '/' . $pluginName . '.zip';
        $source = <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$pluginName};

        use Whity\\Sdk\\PluginInterface;
        use Whity\\Sdk\\PluginRequirementsInterface;
        use Whity\\Sdk\\MigrationInterface;

        final class Plugin implements PluginInterface, PluginRequirementsInterface
        {
            public function getName(): string { return '{$pluginName}'; }
            public function getVersion(): string { return '1.0.0'; }
            public function getRoutes(): array { return []; }
            public function getPermissions(): array { return []; }
            public function getHooks(): array { return []; }
            public function getMigrations(): array { return [\\{$pluginName}\\Migrations\\BoomMigration::class]; }
            public function getSdkConstraint(): string { return '^1.5'; }
            public function getCoreConstraint(): string { return ''; }
            public function getPluginDependencies(): array { return []; }
        }

        namespace {$pluginName}\\Migrations;

        use Whity\\Sdk\\MigrationInterface;

        final class BoomMigration implements MigrationInterface
        {
            public function up(\\PDO \$pdo): void
            {
                // Invalid SQL: forces the migration to fail on any real engine.
                \$pdo->exec('CREATE TABLE this is not valid sql (((');
            }

            public function down(\\PDO \$pdo): void {}
        }
        PHP;

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create zip at {$zipPath}");
        }
        $zip->addFromString($pluginName . '/Plugin.php', $source);
        $zip->close();

        return $zipPath;
    }

    /**
     * Build a zip whose plugin declares an impossible core constraint, so the
     * WC-211 version gate rejects it. SDK constraint stays satisfiable.
     *
     * @param string $workDir Directory to write the .zip into (must exist).
     * @param string $pluginName Plugin/dir name.
     * @return string Absolute path to the created .zip.
     */
    public static function incompatibleZip(string $workDir, string $pluginName = 'IncompatibleUploaded'): string
    {
        // Core is 0.1.0; '^99.0' can never be satisfied.
        return self::validDirectoryZip($workDir, $pluginName, '^1.5', '^99.0');
    }

    /**
     * Build a zip whose plugin's TOP-LEVEL code blocks forever (a busy loop
     * bounded only by a long sleep). Introspecting it must hit the installer's
     * wall-clock deadline rather than hang the worker (WC-220 M1).
     *
     * @param string $workDir Directory to write the .zip into (must exist).
     * @param string $pluginName Plugin/dir name.
     * @return string Absolute path to the created .zip.
     */
    public static function hangingTopLevelZip(string $workDir, string $pluginName = 'HangingPlugin'): string
    {
        $zipPath = $workDir . '/' . $pluginName . '.zip';
        $source = <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$pluginName};

        use Whity\\Sdk\\PluginInterface;

        // TOP-LEVEL hang: runs during `require` in the introspection child,
        // before any plugin class is even reflected. A blocking host read would
        // never return; the bounded read must terminate the child at its deadline.
        \\sleep(120);

        final class Plugin implements PluginInterface
        {
            public function getName(): string { return '{$pluginName}'; }
            public function getVersion(): string { return '1.0.0'; }
            public function getRoutes(): array { return []; }
            public function getPermissions(): array { return []; }
            public function getHooks(): array { return []; }
            public function getMigrations(): array { return []; }
        }
        PHP;

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create zip at {$zipPath}");
        }
        $zip->addFromString($pluginName . '/Plugin.php', $source);
        $zip->close();

        return $zipPath;
    }

    /**
     * Build a zip whose plugin's TOP-LEVEL code ECHOES a fully forged
     * introspection result (a satisfiable null constraint + a bogus name) and
     * then exits, attempting to forge the metadata the host trusts (WC-220 M3).
     *
     * The forged result claims the impossible core constraint is NOT present
     * (null) so a naive host would skip the version gate. The GENUINE plugin,
     * however, declares an impossible core constraint, so a correctly-hardened
     * host reads the genuine (marker-delimited) result and still rejects it.
     *
     * @param string $workDir Directory to write the .zip into (must exist).
     * @param string $pluginName Plugin/dir name (also the genuine getName()).
     * @return string Absolute path to the created .zip.
     */
    public static function forgedIntrospectionZip(string $workDir, string $pluginName = 'ForgedPlugin'): string
    {
        $zipPath = $workDir . '/' . $pluginName . '.zip';
        // Forge a result with NO constraints (so a tricked host skips the gate)
        // and a bogus name — emitted at top level, before the genuine emit.
        $forged = json_encode([
            'status' => 'ok',
            'plugin' => [
                'name' => $pluginName,
                'version' => '9.9.9',
                'routes_count' => 999,
                'permissions_count' => 999,
                'sdk_constraint' => null,
                'core_constraint' => null,
            ],
        ], JSON_UNESCAPED_SLASHES);

        $forgedExport = var_export($forged, true);

        // The genuine plugin declares an IMPOSSIBLE core constraint (^99.0):
        // honest introspection -> version gate rejects. The top-level echo tries
        // to forge a constraint-free result and exit before the genuine emit.
        $source = <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$pluginName};

        use Whity\\Sdk\\PluginInterface;
        use Whity\\Sdk\\PluginRequirementsInterface;

        // Attempt to forge the ENTIRE introspection result at top level, then
        // exit so the genuine emit "never runs". A hardened child discards this
        // pre-emit output (it sits outside the sentinel markers) and the host
        // parses ONLY the genuine marker-delimited block.
        echo {$forgedExport};

        final class Plugin implements PluginInterface, PluginRequirementsInterface
        {
            public function getName(): string { return '{$pluginName}'; }
            public function getVersion(): string { return '1.0.0'; }
            public function getRoutes(): array { return []; }
            public function getPermissions(): array { return []; }
            public function getHooks(): array { return []; }
            public function getMigrations(): array { return []; }
            public function getSdkConstraint(): string { return '^1.5'; }
            public function getCoreConstraint(): string { return '^99.0'; }
            public function getPluginDependencies(): array { return []; }
        }
        PHP;

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create zip at {$zipPath}");
        }
        $zip->addFromString($pluginName . '/Plugin.php', $source);
        $zip->close();

        return $zipPath;
    }

    /**
     * Build a zip whose plugin's TOP-LEVEL code prints a fully-forged result
     * WRAPPED IN forged sentinel markers and then `exit(0)`s, so the genuine
     * emit never runs (WC-220 M3, hardest case). Because the forged markers
     * cannot carry the parent's single-use nonce, the parent finds no genuine
     * nonce-keyed block and rejects the upload as an inspection failure.
     *
     * @param string $workDir Directory to write the .zip into (must exist).
     * @param string $pluginName Plugin/dir name.
     * @return string Absolute path to the created .zip.
     */
    public static function forgedMarkersThenExitZip(string $workDir, string $pluginName = 'ForgedExit'): string
    {
        $zipPath = $workDir . '/' . $pluginName . '.zip';
        $forged = json_encode([
            'status' => 'ok',
            'plugin' => [
                'name' => $pluginName,
                'version' => '9.9.9',
                'routes_count' => 1,
                'permissions_count' => 1,
                'sdk_constraint' => null,
                'core_constraint' => null,
            ],
        ], JSON_UNESCAPED_SLASHES);
        $forgedExport = var_export($forged, true);

        // Guess the marker shape with a blank/zero nonce; the real nonce is
        // random and was scrubbed, so this cannot match the parent's nonce.
        $source = <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$pluginName};

        use Whity\\Sdk\\PluginInterface;

        // Print forged, marker-wrapped result then exit before the genuine emit.
        echo '===WC-INTROSPECT-BEGIN:===' . {$forgedExport} . '===WC-INTROSPECT-END:===';
        exit(0);

        final class Plugin implements PluginInterface
        {
            public function getName(): string { return '{$pluginName}'; }
            public function getVersion(): string { return '1.0.0'; }
            public function getRoutes(): array { return []; }
            public function getPermissions(): array { return []; }
            public function getHooks(): array { return []; }
            public function getMigrations(): array { return []; }
        }
        PHP;

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create zip at {$zipPath}");
        }
        $zip->addFromString($pluginName . '/Plugin.php', $source);
        $zip->close();

        return $zipPath;
    }

    /**
     * Build a zip whose plugin's TOP-LEVEL code AND constructor write a marker
     * file to a caller-chosen path — proof of whether the plugin's code was
     * executed in-host. Used to assert a STAGED DISABLED directory plugin is
     * never `require`d/instantiated before an explicit enable (WC-220 M4).
     *
     * @param string $workDir Directory to write the .zip into (must exist).
     * @param string $markerPath Absolute path the plugin will create when run.
     * @param string $pluginName Plugin/dir name.
     * @return string Absolute path to the created .zip.
     */
    public static function executionMarkerZip(
        string $workDir,
        string $markerPath,
        string $pluginName = 'MarkerPlugin',
    ): string {
        $zipPath = $workDir . '/' . $pluginName . '.zip';
        $markerExport = var_export($markerPath, true);

        $source = <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$pluginName};

        use Whity\\Sdk\\PluginInterface;

        // TOP-LEVEL side effect: writing this proves the file was `require`d.
        @\\file_put_contents({$markerExport}, "top-level\\n", FILE_APPEND);

        final class Plugin implements PluginInterface
        {
            public function __construct()
            {
                // CONSTRUCTOR side effect: proves the plugin was instantiated.
                @\\file_put_contents({$markerExport}, "constructed\\n", FILE_APPEND);
            }
            public function getName(): string { return '{$pluginName}'; }
            public function getVersion(): string { return '1.0.0'; }
            public function getRoutes(): array { return []; }
            public function getPermissions(): array { return []; }
            public function getHooks(): array { return []; }
            public function getMigrations(): array { return []; }
        }
        PHP;

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create zip at {$zipPath}");
        }
        $zip->addFromString($pluginName . '/Plugin.php', $source);
        $zip->close();

        return $zipPath;
    }

    /**
     * Build a zip containing NO class implementing PluginInterface.
     *
     * @param string $workDir Directory to write the .zip into (must exist).
     * @param string $name Archive base name.
     * @return string Absolute path to the created .zip.
     */
    public static function noPluginZip(string $workDir, string $name = 'EmptyPackage'): string
    {
        $zipPath = $workDir . '/' . $name . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create zip at {$zipPath}");
        }
        $zip->addFromString($name . '/notes.txt', "no plugin here\n");
        $zip->close();

        return $zipPath;
    }

    /**
     * Build a zip containing TWO distinct classes implementing PluginInterface.
     *
     * @param string $workDir Directory to write the .zip into (must exist).
     * @return string Absolute path to the created .zip.
     */
    public static function multiPluginZip(string $workDir): string
    {
        $zipPath = $workDir . '/MultiPackage.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create zip at {$zipPath}");
        }
        $zip->addFromString('MultiPackage/PluginOne.php', self::singlePluginClassSource('MultiPackage', 'PluginOne', 'AlphaUploaded'));
        $zip->addFromString('MultiPackage/PluginTwo.php', self::singlePluginClassSource('MultiPackage', 'PluginTwo', 'BetaUploaded'));
        $zip->close();

        return $zipPath;
    }

    /**
     * Build a zip whose plugin derives an UNSAFE name (contains a path
     * separator / dots) — so the name allowlist rejects it. The archive is
     * well-formed and contains exactly one plugin class; only getName() is bad.
     *
     * @param string $workDir Directory to write the .zip into (must exist).
     * @return string Absolute path to the created .zip.
     */
    public static function unsafeNameZip(string $workDir): string
    {
        $zipPath = $workDir . '/UnsafeName.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create zip at {$zipPath}");
        }
        // Top-level dir is a safe slug, but getName() returns a traversal string.
        $zip->addFromString(
            'UnsafeNameDir/Plugin.php',
            self::singlePluginClassSource('UnsafeNameDir', 'Plugin', '../../evil')
        );
        $zip->close();

        return $zipPath;
    }

    /**
     * Build a ZIP-SLIP archive: a well-formed central directory whose single
     * entry name escapes the extraction root via `..`. Hand-assembled because
     * ZipArchive refuses to store a traversal path verbatim.
     *
     * @param string $workDir Directory to write the .zip into (must exist).
     * @param string $entryName The (malicious) stored entry name.
     * @return string Absolute path to the created .zip.
     */
    public static function zipSlipArchive(
        string $workDir,
        string $entryName = '../evil_zipslip_marker.php',
    ): string {
        $zipPath = $workDir . '/zip-slip.zip';
        $content = "<?php /* pwned */\n";
        $bytes = self::buildStoredZip([$entryName => $content]);
        if (file_put_contents($zipPath, $bytes) === false) {
            throw new RuntimeException("Could not write zip-slip archive at {$zipPath}");
        }

        return $zipPath;
    }

    /**
     * Build a ZIP-BOMB archive: a small compressed payload that inflates far
     * beyond the per-entry / total / ratio caps. Uses DEFLATE on a long run of
     * a single byte, which compresses thousands-to-one.
     *
     * The uncompressed payload is materialised on disk in fixed-size chunks
     * (constant memory in the test process) and then added with
     * {@see ZipArchive::addFile()}, so building the fixture itself never
     * allocates the full uncompressed size.
     *
     * @param string $workDir Directory to write the .zip into (must exist).
     * @param int $uncompressedBytes Target uncompressed size of the single entry.
     * @return string Absolute path to the created .zip.
     */
    public static function zipBombArchive(string $workDir, int $uncompressedBytes = 80_000_000): string
    {
        $zipPath = $workDir . '/zip-bomb.zip';
        $payloadPath = $workDir . '/bomb-payload.bin';

        $handle = fopen($payloadPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Could not write bomb payload at {$payloadPath}");
        }
        $chunk = str_repeat('A', 1_048_576); // 1 MiB of a single byte.
        $written = 0;
        while ($written < $uncompressedBytes) {
            $remaining = $uncompressedBytes - $written;
            $slice = $remaining < strlen($chunk) ? substr($chunk, 0, $remaining) : $chunk;
            fwrite($handle, $slice);
            $written += strlen($slice);
        }
        fclose($handle);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create zip at {$zipPath}");
        }
        $zip->addFile($payloadPath, 'Bomb/Plugin.php');
        $zip->close();

        return $zipPath;
    }

    /**
     * Build a multipart/form-data body carrying a single file field plus the
     * matching Content-Type header (with the boundary), as a real upload would.
     *
     * @param string $fieldName The multipart field name (e.g. 'package').
     * @param string $filename The client filename.
     * @param string $contents The raw file bytes.
     * @param string $mediaType The part's declared Content-Type.
     * @return array{0: string, 1: string} [contentTypeHeader, body].
     */
    public static function multipartBody(
        string $fieldName,
        string $filename,
        string $contents,
        string $mediaType = 'application/octet-stream',
    ): array {
        $boundary = '----WhityTestBoundary' . bin2hex(random_bytes(8));
        $crlf = "\r\n";

        $body = '--' . $boundary . $crlf
            . 'Content-Disposition: form-data; name="' . $fieldName . '"; filename="' . $filename . '"' . $crlf
            . 'Content-Type: ' . $mediaType . $crlf
            . $crlf
            . $contents . $crlf
            . '--' . $boundary . '--' . $crlf;

        return ['multipart/form-data; boundary=' . $boundary, $body];
    }

    /**
     * Render a single plugin class (no migration) in a given namespace.
     *
     * @param string $namespace The PHP namespace.
     * @param string $className The class short name.
     * @param string $pluginName The value getName() returns.
     * @return string The PHP source.
     */
    private static function singlePluginClassSource(string $namespace, string $className, string $pluginName): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Whity\\Sdk\\PluginInterface;

        final class {$className} implements PluginInterface
        {
            public function getName(): string
            {
                return '{$pluginName}';
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getRoutes(): array
            {
                return [];
            }

            public function getPermissions(): array
            {
                return [];
            }

            public function getHooks(): array
            {
                return [];
            }

            public function getMigrations(): array
            {
                return [];
            }
        }
        PHP;
    }

    /**
     * Assemble a minimal STORED (uncompressed) zip from a name => content map,
     * with a valid local-file-header + central-directory structure. Used for the
     * zip-slip fixture where the stored entry name must be byte-for-byte
     * preserved (including `..`).
     *
     * @param array<string, string> $entries Map of entry name to raw content.
     * @return string The complete zip byte stream.
     */
    private static function buildStoredZip(array $entries): string
    {
        $local = '';
        $central = '';
        $offset = 0;

        foreach ($entries as $name => $content) {
            $crc = crc32($content);
            $size = strlen($content);
            $nameLen = strlen($name);

            // Local file header (PK\x03\x04), method 0 (stored), no data descriptor.
            $localHeader = "PK\x03\x04"
                . pack('v', 20)        // version needed
                . pack('v', 0)         // general purpose flag
                . pack('v', 0)         // compression method = store
                . pack('v', 0)         // mod time
                . pack('v', 0)         // mod date
                . pack('V', $crc)
                . pack('V', $size)     // compressed size
                . pack('V', $size)     // uncompressed size
                . pack('v', $nameLen)
                . pack('v', 0)         // extra field length
                . $name;

            $local .= $localHeader . $content;

            // Central directory file header (PK\x01\x02).
            $central .= "PK\x01\x02"
                . pack('v', 20)        // version made by
                . pack('v', 20)        // version needed
                . pack('v', 0)         // flags
                . pack('v', 0)         // method
                . pack('v', 0)         // mod time
                . pack('v', 0)         // mod date
                . pack('V', $crc)
                . pack('V', $size)
                . pack('V', $size)
                . pack('v', $nameLen)
                . pack('v', 0)         // extra len
                . pack('v', 0)         // comment len
                . pack('v', 0)         // disk number start
                . pack('v', 0)         // internal attrs
                . pack('V', 0)         // external attrs
                . pack('V', $offset)   // local header offset
                . $name;

            $offset += strlen($localHeader) + $size;
        }

        $count = count($entries);
        $eocd = "PK\x05\x06"
            . pack('v', 0)             // disk number
            . pack('v', 0)             // disk with central dir
            . pack('v', $count)        // entries on this disk
            . pack('v', $count)        // total entries
            . pack('V', strlen($central))
            . pack('V', strlen($local))
            . pack('v', 0);            // comment length

        return $local . $central . $eocd;
    }
}
