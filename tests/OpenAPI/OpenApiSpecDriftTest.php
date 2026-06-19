<?php

declare(strict_types=1);

namespace Tests\OpenAPI;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\Router;
use Whity\OpenAPI\CoreApiSchemas;
use Whity\OpenAPI\SchemaGenerator;

/**
 * WC-179: spec-drift gate.
 *
 * The committed public/openapi.json is a GENERATED ARTIFACT. It is produced by
 * `php public/index.php generate:openapi`, which wires the live PHP router
 * (CoreApiSchemas::registerRoutes -> plugin load -> SchemaGenerator -> encode)
 * and writes deterministic JSON. If a route's method/path/schema changes but
 * the committed spec is not regenerated, the published contract silently lies
 * to the typed client (#168) and the schema-driven UI (#169).
 *
 * This gate regenerates the spec from the live router exactly as the command
 * does and FAILS when the committed file has drifted — the backend analogue of
 * the `web` job's typed-client `schema.d.ts` drift check (WC-168). It runs in
 * the existing `test` CI job, so a deliberate spec/router divergence fails CI
 * with no workflow change required.
 *
 * Faithful-to-CI regeneration: the spec is rebuilt over ONLY the committed
 * reference plugins (ExamplePlugin + HelloWorld + UiKitShowcase), the same set
 * a clean CI checkout sees. A real plugin deploy-copied into plugins/ on a dev machine
 * (gitignored — see plugins/.gitignore) contributes extra routes that are
 * never committed; comparing against the live plugins/ directory would make
 * this gate fail on dev boxes and pass in CI, which is exactly backwards. The
 * regeneration WIRING (router registration order, generator, encoder) is the
 * production path; only the plugin SOURCE is pinned to the tracked baseline.
 *
 * Runs in SEPARATE PROCESSES: regeneration loads the reference plugins from a
 * temp copy, and other suites load the same plugin classes from their real
 * paths — sharing one process would fatal on redeclare. Isolation keeps both
 * worlds clean.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OpenApiSpecDriftTest extends TestCase
{
    private const SPEC_PATH = __DIR__ . '/../../public/openapi.json';

    /**
     * Temp dir holding ONLY the committed reference plugins (see class docblock).
     */
    private static string $referencePluginsDir;

    public static function setUpBeforeClass(): void
    {
        $source = dirname(__DIR__, 2) . '/plugins';
        self::$referencePluginsDir = sys_get_temp_dir() . '/whity_drift_plugins_' . uniqid();
        mkdir(self::$referencePluginsDir, 0755, true);

        copy($source . '/ExamplePlugin.php', self::$referencePluginsDir . '/ExamplePlugin.php');
        self::copyDirectory($source . '/HelloWorld', self::$referencePluginsDir . '/HelloWorld');
        // UiKitShowcase is a COMMITTED reference/example plugin (not gitignored)
        // and, since WC-232, registers real demo routes — so a clean CI checkout
        // of plugins/ includes it and `generate:openapi` bakes its routes into
        // the committed spec. It therefore belongs in this baseline set.
        self::copyDirectory($source . '/UiKitShowcase', self::$referencePluginsDir . '/UiKitShowcase');
    }

    public static function tearDownAfterClass(): void
    {
        self::removeDirectory(self::$referencePluginsDir);
    }

    /**
     * THE DRIFT GATE: regenerate from the live router exactly as
     * `generate:openapi` does and assert the committed spec is byte-for-byte
     * identical. A route/schema change committed without regenerating the spec
     * fails here (and therefore in CI).
     */
    public function testCommittedSpecMatchesLiveRouterRegeneration(): void
    {
        // WC-206: the generator uses '/v1' to produce '/api/v1/' paths in the spec.
        ['spec' => $spec, 'errors' => $errors] = self::regenerate(new Router('/v1'));

        $this->assertSame([], $errors, 'The regenerated spec must be structurally valid before it is published');

        $this->assertSame(
            self::committedJson(),
            SchemaGenerator::encode($spec),
            'public/openapi.json has drifted from the live router: run '
            . '`php public/index.php generate:openapi` and commit the regenerated spec'
        );
    }

    /**
     * The gate's own teeth: a DELIBERATE spec/router divergence must be caught.
     *
     * Register one extra route on the router before regenerating; the resulting
     * spec gains a path the committed file lacks, so the byte-for-byte
     * comparison the gate relies on MUST report inequality. This proves the
     * gate fails on drift rather than passing vacuously.
     */
    public function testDeliberateRouterDivergenceIsDetected(): void
    {
        $diverged = new Router('');
        $diverged->register(
            'GET',
            '/api/__wc179_drift_probe',
            static fn (): \Whity\Core\Response => new \Whity\Core\Response(501, ''),
            null,
            null,
            null,
            ['summary' => 'WC-179 drift probe']
        );

        ['spec' => $spec] = self::regenerate($diverged);
        $regenerated = SchemaGenerator::encode($spec);

        $this->assertStringContainsString(
            '/api/__wc179_drift_probe',
            $regenerated,
            'The injected divergent route must appear in the regenerated spec'
        );
        // Compare via a boolean rather than assertNotSame: the two documents
        // are large, and PHPUnit's negated string-diff formatter chokes on
        // multi-kilobyte operands. A plain identity check is all the gate needs.
        $this->assertFalse(
            self::committedJson() === $regenerated,
            'A spec/router divergence MUST make the committed spec differ from regeneration — the gate has no teeth otherwise'
        );
    }

    /**
     * The committed spec, decoded for shape assertions.
     */
    public function testCommittedSpecIsValidJsonDocument(): void
    {
        $decoded = json_decode(self::committedJson(), true);
        $this->assertIsArray($decoded, 'public/openapi.json must be valid JSON');
        $this->assertSame('3.0.0', $decoded['openapi'] ?? null);
        $this->assertSame(\Whity\Core\CoreVersion::VERSION, $decoded['info']['version'] ?? null);
    }

    /**
     * The committed spec, normalized to LF so the comparison survives an
     * autocrlf checkout (the encoder always emits LF).
     */
    private static function committedJson(): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(self::SPEC_PATH));
    }

    /**
     * Regenerate exactly as `generate:openapi` does — core catalogue first,
     * then plugins (the runtime first-registration-wins ordering, WC-169) —
     * over the supplied router and the reference plugins only.
     *
     * @param Router $router The router to register routes onto and read back.
     * @return array{spec: array<string, mixed>, errors: list<string>}
     */
    private static function regenerate(Router $router): array
    {
        $loader = new PluginLoader(self::$referencePluginsDir, $router, null, new HookManager());
        CoreApiSchemas::registerRoutes($router);
        $loader->load();

        return (new SchemaGenerator('Whity Core API', \Whity\Core\CoreVersion::VERSION, $loader, $router))
            ->generateAndValidate();
    }

    private static function copyDirectory(string $from, string $to): void
    {
        mkdir($to, 0755, true);
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($items as $item) {
            $target = $to . '/' . $items->getSubPathname();
            $item->isDir() ? mkdir($target, 0755, true) : copy($item->getPathname(), $target);
        }
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
