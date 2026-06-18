<?php

declare(strict_types=1);

namespace Whity\Core;

use Composer\Semver\Semver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Exception\PluginAlreadyInstalled;
use Whity\Core\Exception\PluginExtractionUnsafe;
use Whity\Core\Exception\PluginIncompatible;
use Whity\Core\Exception\PluginInstallException;
use Whity\Core\Exception\PluginNameUnsafe;
use Whity\Core\Exception\PluginPackageInvalid;
use Whity\Sdk\Http\UploadedFile;
use Whity\Sdk\Sdk;
use ZipArchive;

/**
 * Staged installer for uploaded plugin packages (WC-220).
 *
 * Accepts an untrusted uploaded `.zip` or single `.php`, validates it through a
 * defense-in-depth pipeline, and — only on success — commits it to disk LANDING
 * DISABLED (via the existing {@see PluginLoader} sentinel model) so no plugin
 * code is ever executed before an explicit Enable. ANY failure after the upload
 * is staged to a private temp dir performs a full filesystem rollback, leaving
 * the host exactly as it was.
 *
 * Trust model: dev/self-hosted convenience — a trusted, RBAC-gated, audited
 * admin action, not marketplace-grade sandboxing. The guards are nonetheless
 * real: zip-slip, zip-bomb (count/size/ratio), a strict name allowlist, the
 * WC-211 version gate, and a no-overwrite collision check. No raw exception
 * detail is surfaced to clients — failures are typed domain exceptions the
 * endpoint maps to a uniform envelope.
 */
final class PluginInstaller
{
    /** Hard cap on the accepted upload size (bytes): 32 MiB. */
    public const MAX_UPLOAD_BYTES = 33_554_432;

    /** Maximum number of entries permitted in an uploaded archive. */
    public const MAX_ZIP_ENTRIES = 2_000;

    /** Maximum uncompressed size of any single archive entry (bytes): 16 MiB. */
    public const MAX_ENTRY_UNCOMPRESSED_BYTES = 16_777_216;

    /** Maximum total uncompressed size of an archive (bytes): 64 MiB. */
    public const MAX_TOTAL_UNCOMPRESSED_BYTES = 67_108_864;

    /**
     * Maximum overall compression ratio (uncompressed / compressed). A higher
     * ratio is the classic zip-bomb signature. Only evaluated once enough
     * compressed bytes exist for the ratio to be meaningful.
     */
    public const MAX_COMPRESSION_RATIO = 200;

    /** Below this many compressed bytes the ratio check is skipped (tiny files). */
    private const RATIO_MIN_COMPRESSED_BYTES = 256;

    /** The safe filesystem-name allowlist a plugin name must match. */
    private const NAME_PATTERN = '/^[A-Za-z0-9_-]+$/';

    /**
     * Default wall-clock deadline (seconds) for the introspection child before
     * it is forcibly terminated. A malicious plugin whose top-level code,
     * constructor, or accessors loop/sleep forever would otherwise block the
     * host read indefinitely; the parent enforces this bound itself rather than
     * trusting the child to exit (WC-220 M1).
     */
    public const DEFAULT_INTROSPECT_TIMEOUT_SECONDS = 8;

    /**
     * Hard child resource limits passed to the introspection PHP binary so a
     * runaway plugin cannot exhaust host memory or CPU even within the deadline.
     */
    private const CHILD_MEMORY_LIMIT = '128M';
    private const CHILD_MAX_EXECUTION_TIME = '10';

    /**
     * Sentinel marker prefixes/suffix delimiting the genuine introspection
     * result on the child's stdout. The full marker is
     * `<PREFIX><nonce><SUFFIX>`, where the nonce is a single-use random token
     * delivered to the child over STDIN (never argv/env, so it is absent from
     * `/proc/self/cmdline` and `/proc/self/environ`) and consumed before any
     * plugin code runs. The parent parses ONLY the bytes between the nonce-keyed
     * markers, so any output the untrusted plugin printed before the genuine
     * emit (a forged result — even one wrapped in forged markers with a guessed
     * nonce — or a version-gate-bypass attempt) is non-authoritative
     * (WC-220 M3). Kept in sync with bin/plugin-introspect.php.
     */
    private const INTROSPECT_BEGIN_MARKER = '===WC-INTROSPECT-BEGIN:';
    private const INTROSPECT_END_MARKER = '===WC-INTROSPECT-END:';
    private const INTROSPECT_MARKER_SUFFIX = '===';

    /** Wall-clock deadline (seconds) for the introspection child. */
    private readonly int $introspectTimeoutSeconds;

    /**
     * @param string $pluginDir Absolute path to the host plugins directory.
     * @param PluginLoader|null $pluginLoader Live loader; reloaded after a stage.
     * @param AuditLogger|null $auditLogger Audit sink for the `plugin.upload` record.
     * @param LoggerInterface $logger Server-side logger (failures only); defaults to a no-op.
     * @param int|null $introspectTimeoutSeconds Optional override of the child
     *   introspection deadline (seconds); tests lower it so a looping fixture is
     *   rejected fast. Null uses {@see DEFAULT_INTROSPECT_TIMEOUT_SECONDS}.
     */
    public function __construct(
        private readonly string $pluginDir,
        private readonly ?PluginLoader $pluginLoader = null,
        private readonly ?AuditLogger $auditLogger = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?int $introspectTimeoutSeconds = null,
    ) {
        $this->introspectTimeoutSeconds = ($introspectTimeoutSeconds !== null && $introspectTimeoutSeconds > 0)
            ? $introspectTimeoutSeconds
            : self::DEFAULT_INTROSPECT_TIMEOUT_SECONDS;
    }

    /**
     * Validate, stage, and commit an uploaded plugin package (landing DISABLED).
     *
     * @param UploadedFile $package The uploaded multipart file part.
     * @return array{id: string, name: string, enabled: bool, file: string, version: string|null, status: string, routes_count: int|null, permissions_count: int|null}
     *   The staged plugin entry, matching a GET /api/v1/plugins item (disabled).
     * @throws PluginInstallException On any validation failure (filesystem clean).
     */
    public function installFromUpload(UploadedFile $package): array
    {
        // 1. Accept & cap — before touching the filesystem.
        $kind = $this->validateUpload($package);

        // 2. Stage to a private, unique temp working dir.
        $workDir = $this->makeWorkDir();
        $committedPath = null;
        $auditName = null;
        $auditSize = $package->getSize();
        $auditSha = null;

        try {
            $stagedArtifact = $workDir . '/' . ($kind === 'zip' ? 'package.zip' : 'package.php');
            $this->copyUpload($package, $stagedArtifact);
            $auditSha = hash_file('sha256', $stagedArtifact) ?: null;

            // 3. Extract (zip) with hard guards, or treat as a single-file plugin.
            if ($kind === 'zip') {
                $extractRoot = $workDir . '/extracted';
                $this->mkdirOrFail($extractRoot);
                $this->safeExtract($stagedArtifact, $extractRoot);
                $sourceRoot = $extractRoot;
            } else {
                $sourceRoot = $stagedArtifact;
            }

            // 4. Locate & validate EXACTLY ONE PluginInterface class, reading its
            //    metadata in an ISOLATED subprocess (never loading plugin code
            //    into this process — see introspectPlugin()).
            [$meta, $isSingleFile, $artifactPath] = $this->locatePlugin($kind, $sourceRoot);

            // 5. Derive + allowlist the name (a filesystem path segment).
            $pluginName = $this->deriveAndValidateName($meta, $isSingleFile ? null : basename($artifactPath));
            $auditName = $pluginName;

            // 6. Version gate (WC-211) — never stage an incompatible plugin.
            $this->assertCompatible($meta, $pluginName);

            // 7. Collision = reject (v1, no overwrite/upgrade).
            $this->assertNoCollision($pluginName);

            // 8. Commit to disk landing DISABLED (existing sentinel model).
            $committedPath = $this->commitDisabled($pluginName, $isSingleFile, $artifactPath);

            // 9. Converge: the loader lists it disabled.
            $this->pluginLoader?->reload();

            // 10. Audit success (secret-free).
            $this->audit('plugin.upload', $pluginName, $auditSize, $auditSha, 'staged');

            // 11. (rollback handled in catch) — success path cleans only temp.
            $this->removeRecursive($workDir);

            return $this->stagedEntry($meta, $pluginName, $isSingleFile);
        } catch (Throwable $e) {
            // Roll back: temp working dir AND any partially-committed artifact.
            $this->removeRecursive($workDir);
            if ($committedPath !== null) {
                $this->removeRecursive($committedPath);
            }

            // The audit sink must never mask the precise typed failure: a
            // throwing audit here would otherwise replace the typed exception
            // with a generic 500 (WC-220 minor). Isolate it so the original
            // exception always propagates.
            try {
                $this->audit('plugin.upload', $auditName, $auditSize, $auditSha, 'rejected');
            } catch (Throwable $auditError) {
                $this->logger->error('[PluginInstaller] audit sink threw during rollback', [
                    'event' => 'plugin.upload.audit_failed',
                    'error' => $auditError->getMessage(),
                ]);
            }

            if ($e instanceof PluginInstallException) {
                throw $e;
            }

            // Anything unexpected becomes a generic invalid-package error; the
            // detail is logged server-side, never surfaced to the client.
            $this->logger->error('[PluginInstaller] unexpected install failure', [
                'event' => 'plugin.upload.error',
                'error' => $e->getMessage(),
            ]);
            throw new PluginPackageInvalid('The uploaded package could not be installed.', [], $e);
        }
    }

    /**
     * Validate the upload envelope and detect the package kind by CONTENT.
     *
     * The client filename/extension is attacker-controlled, so the kind is
     * decided by the bytes: a ZIP local-file-header magic (`PK\x03\x04`) means
     * zip; a leading `<?php` (after optional BOM/whitespace) means a single PHP
     * plugin. Anything else is rejected.
     *
     * @param UploadedFile $package The uploaded file part.
     * @return 'zip'|'php' The detected package kind.
     * @throws PluginPackageInvalid On a transport error, empty/oversized upload,
     *                              or content that is neither zip nor PHP.
     */
    private function validateUpload(UploadedFile $package): string
    {
        if ($package->getError() !== UPLOAD_ERR_OK) {
            throw new PluginPackageInvalid('The file upload did not complete successfully.');
        }

        $size = $package->getSize();
        if ($size <= 0) {
            throw new PluginPackageInvalid('The uploaded package is empty.');
        }
        if ($size > self::MAX_UPLOAD_BYTES) {
            throw new PluginPackageInvalid(
                'The uploaded package exceeds the maximum allowed size.',
                ['max_bytes' => self::MAX_UPLOAD_BYTES]
            );
        }

        $streamPath = $package->getStreamPath();
        if (!is_file($streamPath)) {
            throw new PluginPackageInvalid('The uploaded package could not be read.');
        }

        $head = (string) file_get_contents($streamPath, false, null, 0, 64);
        if ($head === '') {
            throw new PluginPackageInvalid('The uploaded package is empty.');
        }

        if (str_starts_with($head, "PK\x03\x04")) {
            return 'zip';
        }

        // Plausibly a PHP file: strip an optional UTF-8 BOM + leading whitespace,
        // then require a `<?php` (or short-echo `<?=`) opening tag.
        $trimmed = ltrim($head, "\xEF\xBB\xBF \t\r\n");
        if (str_starts_with($trimmed, '<?php') || str_starts_with($trimmed, '<?=')) {
            return 'php';
        }

        throw new PluginPackageInvalid('Only .zip or .php plugin packages are accepted.');
    }

    /**
     * Copy the uploaded temp file into the private working dir.
     *
     * Uses a copy (not moveTo) so the SDK's single-move temp file remains the
     * source of truth and the host's own multipart cleanup still applies.
     *
     * @param UploadedFile $package The uploaded file part.
     * @param string $target Absolute destination path inside the work dir.
     * @return void
     * @throws PluginPackageInvalid When the copy fails.
     */
    private function copyUpload(UploadedFile $package, string $target): void
    {
        if (!@copy($package->getStreamPath(), $target)) {
            throw new PluginPackageInvalid('The uploaded package could not be staged.');
        }
    }

    /**
     * Extract a zip into $extractRoot with zip-slip + zip-bomb hard guards.
     *
     * Two passes: the FIRST inspects the central directory (entry count, per-entry
     * and total uncompressed sizes, overall ratio, and per-entry path safety) and
     * writes NOTHING; only if every guard passes does the SECOND pass write files,
     * each path re-anchored under the realpath of $extractRoot.
     *
     * @param string $zipPath Absolute path to the staged zip.
     * @param string $extractRoot Absolute path to the (existing, empty) target dir.
     * @return void
     * @throws PluginExtractionUnsafe On any zip-slip or zip-bomb violation.
     * @throws PluginPackageInvalid When the archive cannot be opened/read.
     */
    private function safeExtract(string $zipPath, string $extractRoot): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new PluginPackageInvalid('The uploaded archive could not be opened.');
        }

        try {
            $entryCount = $zip->numFiles;
            if ($entryCount === 0) {
                throw new PluginPackageInvalid('The uploaded archive is empty.');
            }
            if ($entryCount > self::MAX_ZIP_ENTRIES) {
                throw new PluginExtractionUnsafe(
                    'The archive contains too many entries.',
                    ['max_entries' => self::MAX_ZIP_ENTRIES]
                );
            }

            $realRoot = realpath($extractRoot);
            if ($realRoot === false) {
                throw new PluginPackageInvalid('The extraction directory is unavailable.');
            }
            $anchor = rtrim($realRoot, '/\\') . DIRECTORY_SEPARATOR;

            $totalUncompressed = 0;
            $totalCompressed = 0;

            // PASS 1: inspect-only. Validate every entry before any write.
            for ($i = 0; $i < $entryCount; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    throw new PluginPackageInvalid('The archive entry could not be read.');
                }

                /** @var string $name */
                $name = $stat['name'];
                $this->assertSafeEntryPath($name, $anchor);

                // Directory entries (trailing slash) carry no payload.
                if (str_ends_with($name, '/')) {
                    continue;
                }

                $uncompressed = (int) $stat['size'];
                $compressed = (int) $stat['comp_size'];

                if ($uncompressed > self::MAX_ENTRY_UNCOMPRESSED_BYTES) {
                    throw new PluginExtractionUnsafe(
                        'An archive entry is too large when uncompressed.',
                        ['max_entry_bytes' => self::MAX_ENTRY_UNCOMPRESSED_BYTES]
                    );
                }

                $totalUncompressed += $uncompressed;
                $totalCompressed += $compressed;

                if ($totalUncompressed > self::MAX_TOTAL_UNCOMPRESSED_BYTES) {
                    throw new PluginExtractionUnsafe(
                        'The archive is too large when fully uncompressed.',
                        ['max_total_bytes' => self::MAX_TOTAL_UNCOMPRESSED_BYTES]
                    );
                }
            }

            // Overall compression-ratio guard (zip-bomb). Skip when too little
            // compressed data exists for the ratio to be meaningful.
            if (
                $totalCompressed >= self::RATIO_MIN_COMPRESSED_BYTES
                && $totalUncompressed / $totalCompressed > self::MAX_COMPRESSION_RATIO
            ) {
                throw new PluginExtractionUnsafe(
                    'The archive compression ratio exceeds the safe limit.',
                    ['max_ratio' => self::MAX_COMPRESSION_RATIO]
                );
            }

            // PASS 2: write. Every path is re-checked and re-anchored.
            for ($i = 0; $i < $entryCount; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    throw new PluginPackageInvalid('The archive entry could not be read.');
                }
                /** @var string $name */
                $name = $stat['name'];

                $target = $this->resolveEntryTarget($name, $realRoot, $anchor);

                if (str_ends_with($name, '/')) {
                    $this->mkdirOrFail($target);
                    continue;
                }

                $this->mkdirOrFail(dirname($target));

                $contents = $zip->getFromIndex($i);
                if ($contents === false) {
                    throw new PluginPackageInvalid('An archive entry could not be extracted.');
                }
                if (file_put_contents($target, $contents) === false) {
                    throw new PluginPackageInvalid('An archive entry could not be written.');
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Reject any entry name that could escape the extraction root (zip-slip).
     *
     * An entry is unsafe if it is absolute (`/...`), carries a Windows drive
     * letter (`C:`) or backslash, or contains a `..` traversal component. The
     * check is on the DECLARED name and is purely structural (no filesystem
     * access), so it runs in the inspect-only pass before anything is written.
     *
     * @param string $name The raw archive entry name.
     * @param string $anchor The realpath-anchored extraction root (trailing sep).
     * @return void
     * @throws PluginExtractionUnsafe When the entry name is unsafe.
     */
    private function assertSafeEntryPath(string $name, string $anchor): void
    {
        if ($name === '') {
            throw new PluginExtractionUnsafe('The archive contains an entry with an empty name.');
        }

        // Absolute path (POSIX) or backslash (Windows separator inside a zip).
        if (str_starts_with($name, '/') || str_contains($name, '\\')) {
            throw new PluginExtractionUnsafe('The archive contains an unsafe (absolute) entry path.');
        }

        // Drive-letter prefix (e.g. C:foo).
        if (preg_match('/^[A-Za-z]:/', $name) === 1) {
            throw new PluginExtractionUnsafe('The archive contains an unsafe (drive-letter) entry path.');
        }

        // Any `..` traversal component.
        foreach (explode('/', $name) as $segment) {
            if ($segment === '..') {
                throw new PluginExtractionUnsafe('The archive contains an unsafe (traversal) entry path.');
            }
        }
    }

    /**
     * Resolve and re-anchor an entry's on-disk target, failing closed if the
     * normalized path would land outside the extraction root.
     *
     * Belt-and-braces alongside {@see assertSafeEntryPath()}: even if a name
     * slipped the structural check, the normalized join must still sit under the
     * anchored root or the write is refused.
     *
     * @param string $name The (already structurally validated) entry name.
     * @param string $realRoot The realpath of the extraction root.
     * @param string $anchor The anchored root (trailing separator).
     * @return string The absolute, in-root target path.
     * @throws PluginExtractionUnsafe When the resolved path escapes the root.
     */
    private function resolveEntryTarget(string $name, string $realRoot, string $anchor): string
    {
        $target = rtrim($realRoot, '/\\') . '/' . ltrim($name, '/');

        // Normalize without touching the filesystem: collapse separators and
        // resolve `.`/`..` lexically, then confirm containment.
        $normalized = $this->lexicalNormalize($target);
        if (!str_starts_with($normalized . DIRECTORY_SEPARATOR, $anchor) && $normalized . '/' !== $anchor) {
            // Also accept the canonical forward-slash form on Windows.
            $forward = str_replace('\\', '/', $normalized) . '/';
            $forwardAnchor = str_replace('\\', '/', $anchor);
            if (!str_starts_with($forward, $forwardAnchor)) {
                throw new PluginExtractionUnsafe('An archive entry resolves outside the extraction root.');
            }
        }

        return $target;
    }

    /**
     * Lexically normalize a path (resolve `.`/`..`, collapse separators) without
     * any filesystem access — safe for paths that do not yet exist.
     *
     * @param string $path The path to normalize.
     * @return string The normalized path.
     */
    private function lexicalNormalize(string $path): string
    {
        $isAbs = str_starts_with($path, '/') || preg_match('#^[A-Za-z]:#', $path) === 1;
        $parts = preg_split('#[/\\\\]+#', $path) ?: [];
        $stack = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($stack);
                continue;
            }
            $stack[] = $part;
        }
        $joined = implode('/', $stack);

        return $isAbs && !str_starts_with($joined, '/') && preg_match('#^[A-Za-z]:#', $joined) !== 1
            ? '/' . $joined
            : $joined;
    }

    /**
     * Find EXACTLY ONE PluginInterface class in the staged source and read its
     * metadata, in an ISOLATED subprocess.
     *
     * Plugin code is NEVER loaded into the host process. A short-lived child PHP
     * process loads the SDK autoloader plus the staged file(s), discovers the
     * single class implementing {@see PluginInterface}, instantiates it (so the
     * constructor + accessor side-effects run only in the disposable child), and
     * emits its metadata as JSON. This avoids two real hazards of in-process
     * reflection: a class-redefinition clash when the loader later loads the
     * committed copy, and leaking a staged-then-rolled-back plugin's class into a
     * long-lived FrankenPHP worker. It is also a small isolation boundary for
     * untrusted upload code.
     *
     * @param 'zip'|'php' $kind The package kind.
     * @param string $sourceRoot The extraction root (zip) or the staged .php path.
     * @return array{0: array{name: string, version: string, routes_count: int, permissions_count: int, sdk_constraint: ?string, core_constraint: ?string}, 1: bool, 2: string}
     *   [metadata, isSingleFile, artifactPath]. artifactPath is the top-level
     *   plugin dir (zip) or the staged .php file (single).
     * @throws PluginPackageInvalid On zero or multiple plugin classes / load error.
     */
    private function locatePlugin(string $kind, string $sourceRoot): array
    {
        if ($kind === 'php') {
            $meta = $this->introspect([$sourceRoot]);

            return [$meta, true, $sourceRoot];
        }

        // Zip: locate the single top-level directory containing the plugin.
        $topDir = $this->singleTopLevelDir($sourceRoot);
        $phpFiles = $this->phpFilesRecursive($topDir);
        if ($phpFiles === []) {
            throw new PluginPackageInvalid('The package contains no plugin class.');
        }

        $meta = $this->introspect($phpFiles);

        return [$meta, false, $topDir];
    }

    /**
     * Introspect a set of staged PHP files in a child process and return the
     * single plugin's metadata.
     *
     * @param list<string> $files Absolute paths to load in the child.
     * @return array{name: string, version: string, routes_count: int, permissions_count: int, sdk_constraint: ?string, core_constraint: ?string}
     * @throws PluginPackageInvalid When the child reports zero/multiple plugins
     *                              or fails to load the package.
     */
    private function introspect(array $files): array
    {
        $script = $this->introspectorScriptPath();
        $php = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : 'php';

        // Single-use, unguessable nonce keying the result markers (WC-220 M3):
        // the child embeds it in its genuine emit, so a plugin that prints
        // forged markers cannot make the parent accept a forged block (the
        // parent requires THIS nonce). The nonce is delivered over STDIN — NOT
        // argv, NOT env — so it never appears in any process-visible table the
        // untrusted plugin could read (`/proc/self/cmdline`,
        // `/proc/self/environ`), which PHP-level scrubbing of $argv/$_SERVER
        // could not cover (re-review fix). The child consumes it before any
        // plugin code runs and loads the plugin in an isolated scope, so the
        // plugin has no channel to recover it.
        $nonce = bin2hex(random_bytes(16));

        // Pass hard child resource limits so a runaway plugin cannot exhaust
        // host memory/CPU even within the wall-clock deadline (WC-220 M1). The
        // staged files are the only argv entries — the nonce is NEVER an
        // argument (it would be readable via /proc/self/cmdline).
        $cmd = array_merge(
            [
                $php,
                '-d', 'memory_limit=' . self::CHILD_MEMORY_LIMIT,
                '-d', 'max_execution_time=' . self::CHILD_MAX_EXECUTION_TIME,
                $script,
            ],
            $files
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new PluginPackageInvalid('The package could not be inspected.');
        }

        // Hand the nonce to the child over its stdin pipe, then close stdin so
        // the child's first read sees the single line and any later read by the
        // plugin sees EOF. Writing happens before the bounded read drains
        // stdout (the nonce line is tiny — well within the pipe buffer — so this
        // never deadlocks).
        if (is_resource($pipes[0])) {
            @fwrite($pipes[0], $nonce . "\n");
            @fclose($pipes[0]);
        }

        // Bounded, non-blocking read with a wall-clock deadline. A malicious
        // plugin whose top-level/constructor/accessor code loops or sleeps
        // forever would make a blocking read hang the worker indefinitely
        // (WC-220 M1); instead we stop reading at the deadline, terminate the
        // child, and treat the outcome as a generic inspection failure.
        [$stdout, $stderr, $timedOut, $exitCode] = $this->readChildBounded($process, $pipes);

        if ($timedOut) {
            $this->logger->error('[PluginInstaller] introspection timed out', [
                'event' => 'plugin.upload.introspect_timeout',
                'timeout_seconds' => $this->introspectTimeoutSeconds,
            ]);
            throw new PluginPackageInvalid('The package could not be inspected.');
        }

        // The child must exit cleanly (0). A non-zero exit (uncatchable fatal,
        // OOM, kill) is a generic inspection failure — never trust its output.
        if ($exitCode !== 0) {
            $this->logger->error('[PluginInstaller] introspection child exited non-zero', [
                'event' => 'plugin.upload.introspect_failed',
                'exit_code' => $exitCode,
                'stderr' => $stderr,
            ]);
            throw new PluginPackageInvalid('The package could not be inspected.');
        }

        // Parse ONLY the genuine, NONCE-KEYED marker-delimited result. Any
        // output the untrusted plugin printed before the genuine emit (e.g. a
        // forged JSON result — even one wrapped in forged markers — echoed then
        // exit()) cannot carry this run's nonce, so it is ignored and cannot
        // forge metadata or bypass the version gate (WC-220 M3).
        $result = $this->extractDelimitedResult($stdout, $nonce);
        if ($result === null) {
            $this->logger->error('[PluginInstaller] introspection produced no delimited result', [
                'event' => 'plugin.upload.introspect_failed',
                'stderr' => $stderr,
            ]);
            throw new PluginPackageInvalid('The package could not be inspected.');
        }

        $decoded = json_decode($result, true);
        if (!is_array($decoded) || !isset($decoded['status'])) {
            $this->logger->error('[PluginInstaller] introspection produced no result', [
                'event' => 'plugin.upload.introspect_failed',
                'stderr' => $stderr,
            ]);
            throw new PluginPackageInvalid('The package could not be inspected.');
        }

        if ($decoded['status'] !== 'ok') {
            $reason = is_string($decoded['reason'] ?? null) ? $decoded['reason'] : 'invalid';

            return match ($reason) {
                'none' => throw new PluginPackageInvalid('The package contains no plugin class.'),
                'multiple' => throw new PluginPackageInvalid('The package contains more than one plugin class.'),
                default => throw new PluginPackageInvalid('The package could not be inspected.'),
            };
        }

        $plugin = $decoded['plugin'] ?? null;
        if (!is_array($plugin) || !isset($plugin['name']) || !is_string($plugin['name'])) {
            throw new PluginPackageInvalid('The package could not be inspected.');
        }

        return [
            'name' => $plugin['name'],
            'version' => is_string($plugin['version'] ?? null) ? $plugin['version'] : '',
            'routes_count' => is_int($plugin['routes_count'] ?? null) ? $plugin['routes_count'] : 0,
            'permissions_count' => is_int($plugin['permissions_count'] ?? null) ? $plugin['permissions_count'] : 0,
            'sdk_constraint' => is_string($plugin['sdk_constraint'] ?? null) ? $plugin['sdk_constraint'] : null,
            'core_constraint' => is_string($plugin['core_constraint'] ?? null) ? $plugin['core_constraint'] : null,
        ];
    }

    /**
     * Read a child process's stdout/stderr without blocking, enforcing a
     * wall-clock deadline; terminate the child on deadline (WC-220 M1).
     *
     * Pipes are switched to non-blocking and drained in a {@see stream_select()}
     * loop. When the deadline elapses before the child closes its pipes, the
     * child is {@see proc_terminate()}'d (SIGKILL) and reaped. Returns the bytes
     * captured so far, whether the deadline was hit, and the child's exit code
     * (-1 when it was killed / never reported a code).
     *
     * @param resource $process The proc_open() process handle.
     * @param array<int, resource> $pipes The [0=>stdin,1=>stdout,2=>stderr] pipes.
     * @return array{0: string, 1: string, 2: bool, 3: int} [stdout, stderr, timedOut, exitCode]
     */
    private function readChildBounded($process, array $pipes): array
    {
        // stdin already carried the single nonce line and was closed by the
        // caller (so the child's later reads see EOF); close it here only if it
        // somehow remains open.
        if (is_resource($pipes[0])) {
            @fclose($pipes[0]);
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        $deadline = microtime(true) + $this->introspectTimeoutSeconds;
        $timedOut = false;

        while ($open !== []) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $timedOut = true;
                break;
            }

            $read = array_values($open);
            $write = null;
            $except = null;

            $sec = (int) $remaining;
            $usec = (int) (($remaining - $sec) * 1_000_000);
            $ready = @stream_select($read, $write, $except, $sec, $usec);

            if ($ready === false) {
                // Interrupted (e.g. by a signal); re-evaluate the deadline.
                continue;
            }
            if ($ready === 0) {
                // select() timed out with nothing ready: deadline reached.
                $timedOut = true;
                break;
            }

            foreach ($read as $stream) {
                $chunk = fread($stream, 8192);
                $key = array_search($stream, $open, true);
                if ($chunk === false || $chunk === '') {
                    // EOF (or error): the child closed this pipe.
                    if ($key !== false) {
                        unset($open[$key]);
                    }
                    continue;
                }
                if ($key === 1) {
                    $stdout .= $chunk;
                } elseif ($key === 2) {
                    $stderr .= $chunk;
                }
            }
        }

        if ($timedOut) {
            // Force-kill the runaway child, then reap it so no zombie lingers.
            @proc_terminate($process, 9);
        }

        // Close any pipes the loop did not already drain to EOF.
        foreach ([1, 2] as $i) {
            if (is_resource($pipes[$i])) {
                @fclose($pipes[$i]);
            }
        }

        $exitCode = proc_close($process);
        if ($timedOut) {
            // A terminated child's reported code is unreliable; force a failure.
            $exitCode = -1;
        }

        return [$stdout, $stderr, $timedOut, $exitCode];
    }

    /**
     * Extract the single genuine result JSON between the NONCE-KEYED
     * introspection sentinel markers, or null when absent / malformed
     * (WC-220 M3).
     *
     * The markers embed this run's single-use nonce, which the child scrubbed
     * before any plugin code ran. A plugin therefore cannot print a marker pair
     * the parent will accept (it cannot know the nonce). Output the plugin
     * printed before the genuine emit — including forged markers with a
     * guessed/blank nonce, or a forged result then `exit()` — lives outside the
     * nonce-keyed markers (or carries the wrong nonce) and is ignored.
     *
     * @param string $stdout The child's raw stdout.
     * @param string $nonce The single-use nonce this run generated.
     * @return string|null The delimited JSON payload, or null.
     */
    private function extractDelimitedResult(string $stdout, string $nonce): ?string
    {
        if ($nonce === '') {
            return null;
        }
        $begin = self::INTROSPECT_BEGIN_MARKER . $nonce . self::INTROSPECT_MARKER_SUFFIX;
        $end = self::INTROSPECT_END_MARKER . $nonce . self::INTROSPECT_MARKER_SUFFIX;

        // The genuine emit is always the LAST thing written (it exits 0 right
        // after). Anchor on the last begin marker so a forged earlier marker
        // pair cannot win, and require a matching end after it.
        $beginPos = strrpos($stdout, $begin);
        if ($beginPos === false) {
            return null;
        }
        $payloadStart = $beginPos + strlen($begin);
        $endPos = strpos($stdout, $end, $payloadStart);
        if ($endPos === false) {
            return null;
        }

        $payload = substr($stdout, $payloadStart, $endPos - $payloadStart);

        // A forged result cannot smuggle a second begin/end marker inside the
        // genuine payload: reject if the payload itself contains either marker.
        if (str_contains($payload, $begin) || str_contains($payload, $end)) {
            return null;
        }

        return $payload === '' ? null : $payload;
    }

    /**
     * Absolute path to the bundled introspector child script.
     *
     * @return string
     * @throws PluginPackageInvalid When the script is missing.
     */
    private function introspectorScriptPath(): string
    {
        $path = dirname(__DIR__, 2) . '/bin/plugin-introspect.php';
        if (!is_file($path)) {
            throw new PluginPackageInvalid('The package inspector is unavailable.');
        }

        return $path;
    }

    /**
     * Derive the plugin name and enforce the filesystem-safe allowlist.
     *
     * The declared getName() (read in the child) is authoritative; for a
     * directory plugin it must additionally match the archive's top-level
     * directory name (so the on-disk PSR-4 prefix and the declared name agree).
     * Any value failing `^[A-Za-z0-9_-]+$` is rejected — this name becomes a path
     * under plugins/.
     *
     * @param array{name: string, version: string, routes_count: int, permissions_count: int, sdk_constraint: ?string, core_constraint: ?string} $meta
     * @param string|null $topDirName The archive top-level dir (zip) or null (single).
     * @return string The validated, safe plugin name.
     * @throws PluginNameUnsafe When the name (or dir) is unsafe or they disagree.
     */
    private function deriveAndValidateName(array $meta, ?string $topDirName): string
    {
        $declared = $meta['name'];

        if (preg_match(self::NAME_PATTERN, $declared) !== 1) {
            throw new PluginNameUnsafe('The plugin name contains unsafe characters.');
        }

        if ($topDirName !== null) {
            if (preg_match(self::NAME_PATTERN, $topDirName) !== 1) {
                throw new PluginNameUnsafe('The package directory name contains unsafe characters.');
            }
            if ($topDirName !== $declared) {
                throw new PluginPackageInvalid(
                    'The plugin directory name does not match the declared plugin name.'
                );
            }
        }

        return $declared;
    }

    /**
     * Evaluate the WC-211 SDK + core version gates against this host.
     *
     * Reuses the loader's gate semantics: a plugin not declaring SDK/core
     * constraints is unconstrained; otherwise its SDK constraint is checked
     * against {@see Sdk::VERSION} and its core constraint against
     * {@see CoreVersion::VERSION} via composer/semver. An unparseable constraint
     * fails closed.
     *
     * @param array{name: string, version: string, routes_count: int, permissions_count: int, sdk_constraint: ?string, core_constraint: ?string} $meta
     * @param string $pluginName The plugin's name (for the message; safe).
     * @return void
     * @throws PluginIncompatible When a constraint is unsatisfied/unparseable.
     */
    private function assertCompatible(array $meta, string $pluginName): void
    {
        $this->assertConstraint($meta['sdk_constraint'] ?? '', Sdk::VERSION, 'SDK', $pluginName);
        $this->assertConstraint($meta['core_constraint'] ?? '', CoreVersion::VERSION, 'core', $pluginName);
    }

    /**
     * Check one composer-style constraint against a host version.
     *
     * @param string $constraint The declared constraint ('' = no constraint).
     * @param string $hostVersion The host version to satisfy.
     * @param string $what 'SDK' or 'core' (for the safe message).
     * @param string $pluginName The plugin name (safe detail).
     * @return void
     * @throws PluginIncompatible When unsatisfied or unparseable.
     */
    private function assertConstraint(string $constraint, string $hostVersion, string $what, string $pluginName): void
    {
        if ($constraint === '') {
            return;
        }

        try {
            $satisfied = Semver::satisfies($hostVersion, $constraint);
        } catch (\UnexpectedValueException $e) {
            throw new PluginIncompatible(
                "The plugin declares an unparseable {$what} version constraint.",
                ['plugin' => $pluginName, 'constraint' => $constraint],
                $e
            );
        }

        if (!$satisfied) {
            throw new PluginIncompatible(
                "The plugin is not compatible with this host's {$what} version.",
                ['plugin' => $pluginName, 'required' => $constraint, 'host' => $hostVersion]
            );
        }
    }

    /**
     * Reject any name that already exists on disk (v1: no overwrite/upgrade).
     *
     * @param string $name The validated plugin name.
     * @return void
     * @throws PluginAlreadyInstalled When a dir/.php/.php.disabled already exists.
     */
    private function assertNoCollision(string $name): void
    {
        $candidates = [
            $this->pluginDir . '/' . $name,
            $this->pluginDir . '/' . $name . '.php',
            $this->pluginDir . '/' . $name . '.php.disabled',
        ];
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                throw new PluginAlreadyInstalled(
                    'A plugin with this name is already installed.',
                    ['plugin' => $name]
                );
            }
        }
    }

    /**
     * Atomically land the validated artifact in plugins/ marked DISABLED, using
     * the existing {@see PluginLoader} sentinel model.
     *
     * The commit is ATOMIC and disabled-BY-CONSTRUCTION (WC-220 M2): the final
     * artifact — INCLUDING its {@see PluginLoader::DIR_DISABLED_SENTINEL} marker
     * (directory) or its `.disabled` suffix (single file) — is fully prepared in
     * a temp location ON THE SAME FILESYSTEM as plugins/, then moved into the
     * live path with a SINGLE {@see rename()}. There is therefore never a window
     * in which a partially-written, or sentinel-less (and thus ACTIVE), artifact
     * exists at the live path: discovery sees either nothing or the complete,
     * already-disabled artifact. Any failure removes the temp artifact and
     * leaves nothing at the live path.
     *
     * The destination is re-anchored under the realpath of plugins/ so the name
     * (already allowlisted) cannot, even via symlink tricks, land outside it.
     *
     * @param string $name The validated plugin name.
     * @param bool $isSingleFile Whether this is a single-file plugin.
     * @param string $artifactPath The staged source (top dir or .php file).
     * @return string The committed on-disk path (for rollback on later failure).
     * @throws PluginInstallException When the move/write fails or anchoring fails.
     */
    private function commitDisabled(string $name, bool $isSingleFile, string $artifactPath): string
    {
        $this->mkdirOrFail($this->pluginDir);
        $realPluginDir = realpath($this->pluginDir);
        if ($realPluginDir === false) {
            throw new PluginPackageInvalid('The plugins directory is unavailable.');
        }
        $anchor = rtrim($realPluginDir, '/\\') . DIRECTORY_SEPARATOR;

        if ($isSingleFile) {
            $dest = $this->pluginDir . '/' . $name . '.php.disabled';
            $this->assertUnder($dest, $anchor);

            // Prepare on a same-dir temp path, then atomically rename into place.
            $temp = $this->pluginDir . '/.' . $name . '.tmp_' . bin2hex(random_bytes(8)) . '.php.disabled';
            try {
                if (!@copy($artifactPath, $temp)) {
                    throw new PluginPackageInvalid('The plugin file could not be installed.');
                }
                if (!@rename($temp, $dest)) {
                    throw new PluginPackageInvalid('The plugin file could not be installed.');
                }
            } catch (Throwable $e) {
                $this->removeRecursive($temp);
                throw $e;
            }

            return $dest;
        }

        $dest = $this->pluginDir . '/' . $name;
        $this->assertUnder($dest, $anchor);

        // Prepare the COMPLETE, already-disabled tree in a same-filesystem temp
        // dir (sibling of the live path), then a single atomic rename lands it.
        $temp = $this->pluginDir . '/.' . $name . '.tmp_' . bin2hex(random_bytes(8));
        try {
            $this->copyTree($artifactPath, $temp);

            // Establish the disabled sentinel BEFORE the artifact is live, so the
            // directory can never appear at the live path without it (which the
            // loader would otherwise treat as ENABLED, running untrusted code).
            $sentinel = $temp . '/' . PluginLoader::DIR_DISABLED_SENTINEL;
            if (file_put_contents($sentinel, '') === false) {
                throw new PluginPackageInvalid('The plugin could not be marked disabled.');
            }

            if (!@rename($temp, $dest)) {
                throw new PluginPackageInvalid('The plugin could not be installed.');
            }
        } catch (Throwable $e) {
            // Remove the prepared-but-unmoved temp; nothing landed at $dest.
            $this->removeRecursive($temp);
            throw $e;
        }

        return $dest;
    }

    /**
     * Confirm a destination's parent resolves under the anchored plugins dir.
     *
     * @param string $dest The intended destination path.
     * @param string $anchor The anchored plugins dir (trailing separator).
     * @return void
     * @throws PluginNameUnsafe When the destination would escape the plugins dir.
     */
    private function assertUnder(string $dest, string $anchor): void
    {
        $parent = realpath(dirname($dest));
        if ($parent === false) {
            throw new PluginPackageInvalid('The plugins directory is unavailable.');
        }
        if (!str_starts_with(rtrim($parent, '/\\') . DIRECTORY_SEPARATOR, $anchor)) {
            throw new PluginNameUnsafe('The plugin destination resolves outside the plugins directory.');
        }
    }

    /**
     * Build the staged plugin entry returned to the caller (status: disabled).
     *
     * Shape matches a GET /api/v1/plugins item for a freshly staged plugin.
     *
     * @param array{name: string, version: string, routes_count: int, permissions_count: int, sdk_constraint: ?string, core_constraint: ?string} $meta
     * @param string $name The plugin name.
     * @param bool $isSingleFile Whether it landed as a single file.
     * @return array{id: string, name: string, enabled: bool, file: string, version: string|null, status: string, routes_count: int|null, permissions_count: int|null}
     */
    private function stagedEntry(array $meta, string $name, bool $isSingleFile): array
    {
        return [
            'id' => $name,
            'name' => $name,
            'enabled' => false,
            'file' => $isSingleFile ? $name . '.php.disabled' : $name,
            'version' => $meta['version'] !== '' ? $meta['version'] : null,
            'status' => 'disabled',
            'routes_count' => $meta['routes_count'],
            'permissions_count' => $meta['permissions_count'],
        ];
    }

    /**
     * Emit a secret-free `plugin.upload` audit record when a sink is wired.
     *
     * @param string $action The audit action key.
     * @param string|null $pluginName The plugin name (may be null pre-derivation).
     * @param int $size The uploaded package size in bytes.
     * @param string|null $sha256 The package SHA-256, when computed.
     * @param string $result 'staged' or 'rejected'.
     * @return void
     */
    private function audit(string $action, ?string $pluginName, int $size, ?string $sha256, string $result): void
    {
        $this->auditLogger?->record($action, [
            'target_type' => 'plugin',
            'metadata' => [
                'plugin' => $pluginName,
                'size_bytes' => $size,
                'sha256' => $sha256,
                'result' => $result,
            ],
        ]);
    }

    // ─── filesystem helpers ─────────────────────────────────────────────────

    /**
     * Create a unique, private temp working directory.
     *
     * @return string The absolute path to the created directory.
     * @throws PluginPackageInvalid When the directory cannot be created.
     */
    private function makeWorkDir(): string
    {
        $base = sys_get_temp_dir() . '/whity_plugin_upload_' . bin2hex(random_bytes(8));
        $this->mkdirOrFail($base);

        return $base;
    }

    /**
     * Create a directory (recursively) or throw.
     *
     * @param string $dir The directory path.
     * @return void
     * @throws PluginPackageInvalid When creation fails.
     */
    private function mkdirOrFail(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new PluginPackageInvalid('A working directory could not be created.');
        }
    }

    /**
     * The single top-level directory inside an extracted archive.
     *
     * v1 packages a plugin as exactly one top-level directory (plugins/<Name>/).
     * Reject a root that is empty or contains multiple/loose top-level entries.
     *
     * @param string $extractRoot The extraction root.
     * @return string The absolute path to the single top-level directory.
     * @throws PluginPackageInvalid When the layout is not a single top-level dir.
     */
    private function singleTopLevelDir(string $extractRoot): string
    {
        $entries = scandir($extractRoot);
        if ($entries === false) {
            throw new PluginPackageInvalid('The extracted package could not be read.');
        }
        $entries = array_values(array_diff($entries, ['.', '..']));

        if (count($entries) !== 1 || !is_dir($extractRoot . '/' . $entries[0])) {
            throw new PluginPackageInvalid(
                'The archive must contain exactly one top-level plugin directory.'
            );
        }

        return $extractRoot . '/' . $entries[0];
    }

    /**
     * All PHP files under a directory (recursive), forward-slashed.
     *
     * @param string $dir The directory to scan.
     * @return list<string> Absolute PHP file paths.
     */
    private function phpFilesRecursive(string $dir): array
    {
        $files = [];
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $fileInfo) {
                if ($fileInfo instanceof \SplFileInfo && $fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                    $files[] = str_replace('\\', '/', (string) $fileInfo->getRealPath());
                }
            }
        } catch (\Throwable) {
            return [];
        }
        sort($files);

        return $files;
    }

    /**
     * Recursively copy a directory tree into a (new) destination.
     *
     * @param string $source The source directory.
     * @param string $dest The destination directory (created).
     * @return void
     * @throws PluginPackageInvalid When a copy operation fails.
     */
    private function copyTree(string $source, string $dest): void
    {
        $this->mkdirOrFail($dest);
        $entries = scandir($source);
        if ($entries === false) {
            throw new PluginPackageInvalid('The plugin package could not be read.');
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $from = $source . '/' . $entry;
            $to = $dest . '/' . $entry;
            if (is_dir($from)) {
                $this->copyTree($from, $to);
            } elseif (!@copy($from, $to)) {
                throw new PluginPackageInvalid('The plugin package could not be installed.');
            }
        }
    }

    /**
     * Recursively remove a file or directory; silent when absent.
     *
     * @param string $path The path to remove.
     * @return void
     */
    private function removeRecursive(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        if ($entries === false) {
            return;
        }
        foreach (array_diff($entries, ['.', '..']) as $entry) {
            $this->removeRecursive($path . '/' . (string) $entry);
        }
        @rmdir($path);
    }
}
