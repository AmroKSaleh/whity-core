<?php

declare(strict_types=1);

namespace Whity\Core\DesktopPlugins;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;
use Throwable;

/**
 * Source-level PHP obfuscator for desktop-plugin releases (WC-desktop-plugins).
 *
 * WHY SOURCE-LEVEL ONLY. The desktop app ships an unmodified FrankenPHP runtime
 * and loads a downloaded plugin exactly the way the offline host loads a bundled
 * one: {@see \Whity\PluginHost\PluginDiscovery} eager-requires every `.php` file
 * under the plugin directory, and a per-directory PSR-4 autoloader
 * ({@see \Whity\PluginHost\PluginRuntimeLoader}) maps the plugin's TOP-LEVEL
 * DIRECTORY NAME to its namespace root, resolving each class to a file BY PATH.
 * That has a hard consequence for obfuscation:
 *
 *   The output MUST remain plain, directly-interpretable PHP whose class,
 *   interface, trait, enum, namespace, method, property, constant and function
 *   NAMES — and the files they live in — are byte-for-byte unchanged. Renaming
 *   any of those would break PSR-4 resolution (wrong file), the
 *   PluginInterface contract (the host calls getName()/getRoutes()/… by name),
 *   or route dispatch (handlers are `[$obj, 'methodName']` pairs). So a
 *   conventional class/method-renaming obfuscator, run with its defaults, would
 *   produce a package the device silently quarantines.
 *
 * WHAT IS THEREFORE SAFE, and all this class does:
 *   1. Strip every comment and docblock (always). Removes the authored intent.
 *   2. Rename LOCAL variables inside "clean" function scopes (default on).
 *      Provably behaviour-preserving — see {@see LocalVariableRenamer}.
 *   3. Encode string literals as `\base64_decode('…')` (opt-in). Hides
 *      plain-text strings from `strings`/grep; see {@see StringLiteralEncoder}.
 * Re-printing from the AST also normalises all original formatting.
 *
 * FAIL-CLOSED. The transformed source is re-parsed before it is returned; if it
 * no longer parses, {@see ObfuscationException} is thrown rather than shipping a
 * broken package. The name-preservation invariant is covered by the unit tests.
 *
 * This is obfuscation (raise the effort to read/modify), not encryption. It is
 * deliberately NOT a bytecode encoder (ionCube/SourceGuardian): that would
 * require bundling a proprietary loader extension into every platform's
 * FrankenPHP build, which is out of scope.
 */
final class PluginObfuscator
{
    private readonly Parser $parser;
    private readonly PrettyPrinter\Standard $printer;

    /**
     * @param bool $renameLocals Rename local variables in clean scopes.
     * @param bool $encodeStrings Encode string literals as base64_decode() calls.
     * @param bool $stripComments Strip all comments/docblocks (kept configurable
     *        only so a caller can produce a diffable, comment-free-but-readable
     *        build; the release pipeline always leaves this on).
     */
    public function __construct(
        private readonly bool $renameLocals = true,
        private readonly bool $encodeStrings = false,
        private readonly bool $stripComments = true,
    ) {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->printer = new PrettyPrinter\Standard();
    }

    /**
     * Obfuscate a PHP file's contents, returning the transformed source.
     *
     * @throws ObfuscationException if the file cannot be read, parsed, or if the
     *         transformed output no longer parses.
     */
    public function obfuscateFile(string $path): string
    {
        $code = @file_get_contents($path);
        if ($code === false) {
            throw new ObfuscationException("Could not read source file: {$path}");
        }

        try {
            return $this->obfuscate($code);
        } catch (ObfuscationException $e) {
            throw new ObfuscationException("{$e->getMessage()} (in {$path})", 0, $e);
        }
    }

    /**
     * Obfuscate a PHP source string.
     *
     * @throws ObfuscationException on parse failure (input or output).
     */
    public function obfuscate(string $code): string
    {
        try {
            $ast = $this->parser->parse($code);
        } catch (Throwable $e) {
            throw new ObfuscationException('Could not parse source: ' . $e->getMessage(), 0, $e);
        }

        if ($ast === null) {
            throw new ObfuscationException('Parser returned no statements.');
        }

        if ($this->stripComments) {
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new CommentStripper());
            $ast = $traverser->traverse($ast);
        }

        if ($this->renameLocals) {
            // Not a standard visitor: renaming needs the full variable set of a
            // scope known before any rename, and precise scope boundaries.
            LocalVariableRenamer::apply($ast);
        }

        if ($this->encodeStrings) {
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new StringLiteralEncoder());
            $ast = $traverser->traverse($ast);
        }

        $output = $this->printer->prettyPrintFile($ast);

        // Fail-closed: never emit a package whose PHP no longer parses.
        try {
            $reparsed = (new ParserFactory())->createForNewestSupportedVersion()->parse($output);
        } catch (Throwable $e) {
            throw new ObfuscationException('Transformed source no longer parses: ' . $e->getMessage(), 0, $e);
        }
        if ($reparsed === null) {
            throw new ObfuscationException('Transformed source produced no statements.');
        }

        return $output;
    }
}
