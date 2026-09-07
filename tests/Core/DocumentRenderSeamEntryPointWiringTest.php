<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * #1072: the HTTP entry point must actually WIRE the SDK rendering seam, and
 * must wire it over the shared renderer rather than a second one.
 *
 * A contract nobody registers is worse than no contract: `\Whity\app()` fails
 * closed, so every plugin that adopted the seam would throw at the first call —
 * and the fallback a plugin author reaches for when that happens is the thing
 * the seam exists to remove, a renderer of its own reading its own environment
 * variables.
 *
 * Registering a SECOND FlowDocumentRenderer would be quieter and worse. The
 * ceilings, the settings chain and the client all live in that object; a
 * duplicate built from different arguments would enforce a different policy for
 * plugins than for the HTTP render path, with nothing anywhere reporting that
 * the two disagree. This is the same drift {@see PermissionResolverEntryPointWiringTest}
 * pins for the permission resolver, for the same reason.
 *
 * public/index.php cannot be executed in a unit test (it is a full worker
 * bootstrap), so the wiring is pinned by scanning its source — the technique
 * the sibling wiring tests already use.
 */
final class DocumentRenderSeamEntryPointWiringTest extends TestCase
{
    public function testHttpEntryPointRegistersTheSeamUnderTheSdkInterface(): void
    {
        $source = $this->read(__DIR__ . '/../../public/index.php');

        self::assertMatchesRegularExpression(
            '/register_service\(\s*\n?\s*\\\\?Whity\\\\Sdk\\\\Render\\\\DocumentRenderer::class/',
            $source,
            'public/index.php must register the renderer under the SDK INTERFACE name. Under a '
            . 'core class name a plugin would have to type-hint a core type, which the SDK '
            . 'contract test forbids it from even referencing.'
        );
    }

    public function testTheSeamIsBuiltOverTheHostsOwnRendererAndIssuer(): void
    {
        $source = $this->read(__DIR__ . '/../../public/index.php');

        self::assertMatchesRegularExpression(
            '/new\s+\\\\?Whity\\\\Core\\\\Document\\\\Render\\\\SdkDocumentRenderer\(\s*\n?\s*\$flowDocumentRenderer\s*,\s*\n?\s*\$documentIssuer\s*,/',
            $source,
            'The seam must be built over the SAME $documentIssuer the HTTP render path uses, so a '
            . 'plugin-issued document gets the identical storage routing, immutability rule and '
            . 'provenance stamping as one a person issues.'
        );
    }

    public function testExactlyOneFlowRendererIsConstructed(): void
    {
        $source = $this->read(__DIR__ . '/../../public/index.php');

        self::assertSame(
            1,
            preg_match_all('/new\s+\\\\?Whity\\\\Core\\\\Document\\\\Render\\\\FlowDocumentRenderer\(/', $source),
            'Exactly one FlowDocumentRenderer may be constructed. A second one would carry its own '
            . 'settings service and client, so plugins and HTTP callers could end up enforcing '
            . 'different ceilings against different render services with nothing reporting it.'
        );
    }

    /**
     * The gap is real and is documented in the SDK contract; this pins that the
     * two statements agree.
     *
     * Issuing a document needs the per-tenant storage stack, and building a
     * second copy of that in the CLI kernel is the split-backend hazard the
     * storage factory's own docblock warns about — two drivers from one set of
     * settings, disagreeing about where a tenant's files live. So the seam is
     * HTTP-only for now, and the contract SAYS so rather than leaving a plugin
     * author to discover it as a container error inside a queue worker.
     *
     * When the CLI kernel does gain the storage stack, this test is the thing
     * that should fail — it is a reminder, not an endorsement.
     */
    public function testTheCliGapIsDeclaredInTheContractRatherThanLeftToBeDiscovered(): void
    {
        $cli = $this->read(__DIR__ . '/../../src/Cli/Commands/BaseCommand.php');
        $contract = $this->read(__DIR__ . '/../../sdk/src/Render/DocumentRenderer.php');

        $wiredInCli = str_contains($cli, 'Whity\\Sdk\\Render\\DocumentRenderer::class');
        $documentedAsHttpOnly = str_contains($contract, 'HTTP entry point only');

        self::assertTrue(
            $wiredInCli xor $documentedAsHttpOnly,
            $wiredInCli
                ? 'The CLI kernel now registers the rendering seam — remove the "HTTP entry point '
                  . 'only" limitation from the SDK contract, and pin the CLI wiring the way the '
                  . 'HTTP wiring is pinned above.'
                : 'The CLI kernel does not register the rendering seam, so the SDK contract must '
                  . 'say so. A plugin author calling this from a queue worker otherwise meets an '
                  . 'unexplained container error.'
        );
    }

    private function read(string $path): string
    {
        $source = file_get_contents($path);
        self::assertIsString($source, "Could not read {$path}.");

        return $source;
    }
}
