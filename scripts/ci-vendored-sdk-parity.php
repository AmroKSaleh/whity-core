<?php

declare(strict_types=1);

/**
 * CI vendored-SDK parity guard (#849).
 *
 * The Tauri desktop template carries its OWN copy of the plugin SDK at
 * templates/tauri-desktop/php-host/sdk/src. That copy is not redundancy for
 * its own sake: the offline host boots from a hand-rolled PSR-4 autoloader
 * with no `composer install` behind it (see php-host/public/index.php), so
 * every contract type a plugin `implements` has to be sitting on disk beside
 * the host before the device is ever powered on.
 *
 * The copy is re-vendored BY HAND, and re-vendoring is the step that gets
 * forgotten. By #849 the device tree had missed three SDK releases and was
 * still reporting 1.27.0 while core shipped 1.29.0. Nothing in the build
 * noticed, because nothing was looking: the re-vendor commits assert "verified
 * byte-identical via diff -rq" in prose, which is a claim about what somebody
 * remembered to run. The failure surfaced on a DEVICE instead — HelloWorld,
 * the reference plugin and the exact directory bin/desktop-plugin-release
 * packages for distribution, `implements PluginJobsInterface,
 * PluginEventsInterface`, and neither interface existed in the device's SDK,
 * so the entry class could not be DECLARED, let alone gated or loaded.
 *
 * Two checks, deliberately not one:
 *
 *   1. TREE PARITY — every path under sdk/src exists byte-identically under
 *      the device's copy. Blunt, and that bluntness is the value: it is the
 *      only check that sees drift INSIDE a file, e.g. a new static method on
 *      an existing class (Events::forPlugin(), the other half of what #849
 *      found), which loads perfectly well right up until a plugin calls it.
 *
 *   2. DEVICE BOOT — every in-tree plugin's entry class actually declares
 *      against the DEVICE's SDK, and the device's Sdk::VERSION satisfies the
 *      SDK constraint that plugin declares. This one asserts the OUTCOME
 *      ("a plugin can run on a device") rather than the bytes, so it keeps
 *      working when check 1 is legitimately relaxed by an entry in
 *      ALLOWED_DIVERGENCES below — and it fails for the newest interface
 *      automatically, without anyone remembering to extend a list.
 *
 * Neither check subsumes the other, which is why both are here. A file
 * comparison alone would have passed a device whose SDK is present but whose
 * host cannot load a plugin against it; a boot probe alone would have passed
 * the missing-method half of #849.
 *
 * Usage:  php scripts/ci-vendored-sdk-parity.php
 */

$projectRoot = dirname(__DIR__);
$canonicalSdk = $projectRoot . '/sdk/src';
$deviceHost = $projectRoot . '/templates/tauri-desktop/php-host';
$deviceSdk = $deviceHost . '/sdk/src';
$pluginsRoot = $projectRoot . '/plugins';

/**
 * Paths under sdk/src that the device's copy is ALLOWED to differ on, each
 * mapped to the reason it must.
 *
 * Empty, and expected to stay that way. Every divergence #849 found was lag,
 * not adaptation: the three files that differed were three core commits the
 * vendoring never caught up with, and nothing in the offline host reads the
 * SDK differently from the way core does. An entry here is a real decision —
 * it means a device runs a DIFFERENT contract from the one core publishes and
 * from the one plugin authors compile against — so it belongs in a diff a
 * reviewer can argue with, which is the whole reason this is a constant and
 * not a wildcard.
 *
 * @var array<string, string> relative path under sdk/src => why it differs
 */
const ALLOWED_DIVERGENCES = [
    // 'Some/File.php' => 'Reason the device must carry a different copy.',
];

$failures = [];

// ---------------------------------------------------------------------------
// 1. Tree parity
// ---------------------------------------------------------------------------

/**
 * Every *.php file under $root, as paths relative to it, sorted.
 *
 * @return list<string>
 */
function phpFilesRelativeTo(string $root): array
{
    if (!is_dir($root)) {
        fwrite(STDERR, "FAIL: expected an SDK tree at {$root}, found none.\n");
        exit(1);
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        /** @var SplFileInfo $fileInfo */
        if ($fileInfo->getExtension() !== 'php') {
            continue;
        }
        $relative = substr($fileInfo->getPathname(), strlen($root) + 1);
        $files[] = str_replace('\\', '/', $relative);
    }

    sort($files);

    return $files;
}

/**
 * Compare on CONTENT, not on the raw bytes of the file: a Windows checkout can
 * hand this CRLF where CI's Linux checkout has LF, and a guard that fails on
 * the line endings of the machine it ran on is a guard people learn to ignore.
 * Everything that actually distinguishes two versions of a PHP file survives
 * this normalisation.
 */
function contentHash(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        fwrite(STDERR, "FAIL: could not read {$path}.\n");
        exit(1);
    }

    return hash('sha256', str_replace("\r\n", "\n", $contents));
}

$canonicalFiles = phpFilesRelativeTo($canonicalSdk);
$deviceFiles = phpFilesRelativeTo($deviceSdk);

foreach ($canonicalFiles as $relative) {
    if (isset(ALLOWED_DIVERGENCES[$relative])) {
        continue;
    }

    if (!in_array($relative, $deviceFiles, true)) {
        $failures[] = sprintf(
            '  [missing]   %s — present in sdk/src, absent from the device copy. A plugin that '
            . 'implements or references it cannot even be DECLARED on a device.',
            $relative
        );
        continue;
    }

    if (contentHash($canonicalSdk . '/' . $relative) !== contentHash($deviceSdk . '/' . $relative)) {
        $failures[] = sprintf('  [differs]   %s — the device copy is a different version of this file.', $relative);
    }
}

foreach ($deviceFiles as $relative) {
    if (isset(ALLOWED_DIVERGENCES[$relative]) || in_array($relative, $canonicalFiles, true)) {
        continue;
    }

    // An extra file is drift in the other direction: something was added to
    // the device's SDK without being added to the SDK core publishes, so
    // plugin authors compile against a contract their device does not have and
    // vice versa. Same class of bug, opposite sign.
    $failures[] = sprintf(
        '  [extra]     %s — present in the device copy, absent from sdk/src. The device would be '
        . 'running a contract the published SDK does not contain.',
        $relative
    );
}

if ($failures !== []) {
    fwrite(STDERR, "FAIL: the desktop template's vendored SDK has drifted from sdk/src.\n\n");
    fwrite(STDERR, implode("\n", $failures) . "\n\n");
    fwrite(
        STDERR,
        "Re-vendor it:\n\n"
        . "    cp -R sdk/src/. templates/tauri-desktop/php-host/sdk/src/\n"
        . "    diff -rq sdk/src templates/tauri-desktop/php-host/sdk/src\n\n"
        . "Then commit the device copy alongside the sdk/src change, in the SAME commit — the two\n"
        . "trees are one artifact and splitting them is how they drift. If a file genuinely must\n"
        . "differ on a device, add it to ALLOWED_DIVERGENCES in " . basename(__FILE__) . " WITH the\n"
        . "reason, so the exception is reviewable rather than remembered.\n"
    );
    exit(1);
}

// ---------------------------------------------------------------------------
// 2. Device boot
// ---------------------------------------------------------------------------

// This script deliberately never requires vendor/autoload.php. Core's own
// `Whity\Sdk\` PSR-4 root maps at sdk/src, so with Composer's autoloader in
// play the probe below would resolve every contract type from CORE and report
// that the device is fine no matter what the device actually ships — it would
// be measuring the thing it is supposed to be checking against. The three
// prefixes registered here are exactly the three php-host/public/index.php
// registers, pointed at exactly the trees a device has on disk.
spl_autoload_register(static function (string $class) use ($deviceHost): void {
    $map = [
        'Whity\\Sdk\\' => $deviceHost . '/sdk/src/',
        'Whity\\' => $deviceHost . '/src/',
        'Composer\\Semver\\' => $deviceHost . '/vendor/composer/semver/src/',
    ];
    foreach ($map as $prefix => $baseDir) {
        $length = strlen($prefix);
        if (strncmp($prefix, $class, $length) !== 0) {
            continue;
        }
        $file = $baseDir . str_replace('\\', '/', substr($class, $length)) . '.php';
        if (is_file($file)) {
            require_once $file;

            return;
        }
    }
});

// Plugin directories are PSR-4-mapped by directory name, the rule
// PluginRuntimeLoader::registerPluginNamespaces() applies on a device.
spl_autoload_register(static function (string $class) use ($pluginsRoot): void {
    $separator = strpos($class, '\\');
    if ($separator === false) {
        return;
    }
    $root = substr($class, 0, $separator);
    $file = $pluginsRoot . '/' . $root . '/' . str_replace('\\', '/', substr($class, $separator + 1)) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// EVERY in-tree plugin, not a hand-listed subset. templates/tauri-desktop
// bundles none of them: a device gets its plugin set by mandatory sync from
// the connected tenant's catalog, which an operator populates with
// bin/desktop-plugin-release pointed at exactly these directories. So there is
// no such thing as an in-tree plugin that is out of scope for a device, and a
// curated list here would only encode which plugins somebody remembered —
// which is the failure mode this whole script exists to remove.
$pluginDirectories = [];
foreach (glob($pluginsRoot . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
    $pluginDirectories[] = basename($directory);
}
sort($pluginDirectories);

if ($pluginDirectories === []) {
    fwrite(STDERR, "FAIL: no plugin directories under plugins/ — the device-boot probe would pass vacuously.\n");
    exit(1);
}

$deviceSdkVersion = Whity\Sdk\Sdk::VERSION;
$probed = [];

foreach ($pluginDirectories as $directory) {
    // Discover ONE directory at a time — via the host's own PluginDiscovery,
    // not a reimplementation of it, so this exercises the same require-then-
    // reflect mechanics a device uses. The other directories are passed as
    // already-claimed so a plugin whose class cannot be declared reports as
    // itself instead of aborting the scan for everything after it.
    $others = array_values(array_diff($pluginDirectories, [$directory]));

    try {
        $fqcns = Whity\PluginHost\PluginDiscovery::discover($pluginsRoot, $others);
    } catch (Throwable $e) {
        $failures[] = sprintf(
            '  [%s] cannot be loaded against the device SDK: %s: %s',
            $directory,
            get_class($e),
            $e->getMessage()
        );
        continue;
    }

    if ($fqcns === []) {
        $failures[] = sprintf(
            '  [%s] contributed no class implementing Whity\Sdk\PluginInterface under the device SDK.',
            $directory
        );
        continue;
    }

    foreach ($fqcns as $fqcn) {
        try {
            $plugin = new $fqcn();
        } catch (Throwable $e) {
            $failures[] = sprintf('  [%s] %s could not be constructed: %s: %s', $directory, $fqcn, get_class($e), $e->getMessage());
            continue;
        }

        $probed[] = $fqcn;

        if (!$plugin instanceof Whity\Sdk\PluginRequirementsInterface) {
            continue;
        }

        // Only the SDK constraint. The host's full PluginRequirementsGate also
        // gates on core version and inter-plugin dependencies, and both of
        // those may legitimately reject a plugin on a device that is in no way
        // out of SDK parity — folding them in would make this guard fail for
        // reasons it is not about, and the first such failure is the one that
        // gets it disabled.
        $constraint = $plugin->getSdkConstraint();
        if ($constraint === '') {
            continue;
        }

        try {
            $satisfied = Composer\Semver\Semver::satisfies($deviceSdkVersion, $constraint);
        } catch (UnexpectedValueException) {
            $failures[] = sprintf('  [%s] declares an unparseable SDK constraint %s.', $directory, var_export($constraint, true));
            continue;
        }

        if (!$satisfied) {
            $failures[] = sprintf(
                '  [%s] requires plugin SDK %s, but the device SDK reports %s — a device quarantines it.',
                $directory,
                $constraint,
                $deviceSdkVersion
            );
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "FAIL: an in-tree plugin cannot boot against the desktop template's SDK.\n\n");
    fwrite(STDERR, implode("\n", $failures) . "\n\n");
    fwrite(
        STDERR,
        "The device SDK reports " . $deviceSdkVersion . " (templates/tauri-desktop/php-host/sdk/src/Sdk.php).\n\n"
        . "If this fired on a MISSING contract type, the device's SDK copy is behind sdk/src — re-vendor it\n"
        . "as described above. If it fired on an SDK CONSTRAINT, the device's Sdk::VERSION is behind the\n"
        . "version core publishes, which is the same re-vendor. If the plugin is the thing that is wrong,\n"
        . "it is declaring a constraint no shipped SDK satisfies and the plugin needs the fix.\n"
    );
    exit(1);
}

printf(
    "OK: %d SDK files vendored identically into the desktop template, and %d in-tree plugin(s) boot against its SDK %s.\n",
    count($canonicalFiles),
    count($probed),
    $deviceSdkVersion
);
exit(0);
