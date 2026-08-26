<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Convening;

use PHPUnit\Framework\TestCase;
use Whity\Core\Convening\ConveningFeatures;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Router;
use Whity\Sdk\Frontend\Blocks\BlockContract;
use Whity\Sdk\Frontend\Blocks\BlockValidator;

/**
 * The three convening screens, checked the way the HOST checks them.
 *
 * WHY THIS TEST EXISTS AT ALL. A `screen: 'blocks'` feature whose tree fails
 * validation is DROPPED FAIL-CLOSED by
 * {@see \Whity\Api\FrontendFeaturesApiHandler} — the endpoint still returns 200,
 * the other screens still appear, and the broken one is simply absent from the
 * navigation. That is the right runtime behaviour and it is indistinguishable,
 * to anyone looking at the sidebar, from a permission problem, a caching
 * problem, or a screen nobody built. So the tree is validated here, against the
 * same {@see BlockValidator} the host runs, rather than discovered missing.
 *
 * AND WHY THE PATHS ARE ASSERTED. Route paths are REGISTERED unversioned and the
 * router prepends `/v1`; a block's `source` is a URL a browser fetches and must
 * carry the prefix itself. Getting that wrong produces a screen that renders
 * perfectly and shows an error box where its data should be — which is exactly
 * what happened to `content_url` once, on every document in the viewer.
 */
final class ConveningFeaturesTest extends TestCase
{
    public function testEveryDescriptorCarriesAValidBlockTree(): void
    {
        foreach (ConveningFeatures::all(new Router('/v1')) as $feature) {
            $blocks = $feature['blocks'] ?? null;
            self::assertIsArray($blocks, "feature '{$feature['id']}' must carry a blocks array");

            $result = BlockValidator::validate($blocks);
            self::assertTrue(
                $result['ok'],
                "feature '{$feature['id']}' has an invalid block tree and would be dropped silently: "
                . implode('; ', $result['errors'])
            );
        }
    }

    public function testEveryDescriptorIsGatedOnAConveningPermission(): void
    {
        $allowed = [
            CorePermissions::CONVENING_READ,
            CorePermissions::CONVENING_MANAGE,
            CorePermissions::CONVENING_DECIDE,
        ];

        foreach (ConveningFeatures::all(new Router('/v1')) as $feature) {
            self::assertContains(
                $feature['requiredPermission'],
                $allowed,
                "feature '{$feature['id']}' must be gated on a convening permission — a descriptor with "
                . 'no gate, or one borrowed from another subsystem, is a screen appearing for people '
                . 'the routes behind it will then refuse'
            );
            self::assertContains(
                $feature['requiredPermission'],
                CorePermissions::all(),
                'and the slug must be one the catalogue actually carries, or nobody can ever hold it'
            );
        }
    }

    public function testEveryApiPathInEveryTreeIsVersioned(): void
    {
        $router = new Router('/v1');
        $paths = [];

        foreach (ConveningFeatures::all($router) as $feature) {
            /** @var list<mixed> $blocks */
            $blocks = $feature['blocks'];
            self::collectApiPaths($blocks, $paths);

            $resource = $feature['resource'] ?? null;
            if (is_array($resource) && isset($resource['basePath'])) {
                $paths[] = (string) $resource['basePath'];
            }
        }

        self::assertNotSame([], $paths, 'the fixture must find some paths, or this test proves nothing');

        foreach ($paths as $path) {
            self::assertStringStartsWith(
                '/api/v1/',
                $path,
                "'{$path}' is not versioned. The router serves /api/v1/..., so an unversioned source "
                . 'renders a screen whose data never arrives — and whose capability controls are all '
                . 'disabled, because the base path matches no registered route.'
            );
        }
    }

    public function testTheVersionPrefixIsTakenFromTheRouterRatherThanHardcoded(): void
    {
        // A router with NO prefix must produce unprefixed paths. If the paths
        // were string literals this assertion would fail, which is the point:
        // it proves the emission goes through Router::versionedPath() rather
        // than merely happening to start with /api/v1 today.
        $paths = [];
        foreach (ConveningFeatures::all(new Router('')) as $feature) {
            /** @var list<mixed> $blocks */
            $blocks = $feature['blocks'];
            self::collectApiPaths($blocks, $paths);
        }

        foreach ($paths as $path) {
            self::assertStringStartsNotWith('/api/v1/', $path);
        }
    }

    public function testNavigationItemsAreDerivedFromTheDescriptors(): void
    {
        $router = new Router('/v1');
        $features = ConveningFeatures::all($router);
        $items = ConveningFeatures::navigationItems($router);

        self::assertCount(count($features), $items);

        foreach ($items as $index => $item) {
            $feature = $features[$index];
            self::assertSame($feature['id'], $item['id']);
            self::assertSame($feature['label'], $item['label']);
            self::assertSame(
                '/admin/x/' . (string) $feature['id'],
                $item['href'],
                'the menu entry must point at the host screen route the features endpoint serves, or '
                . 'the link goes somewhere nothing renders'
            );
            self::assertSame($feature['requiredPermission'], $item['requiredPermission']);
        }
    }

    public function testTheMeetingsListLinksToTheMeetingDetailScreenThatExists(): void
    {
        $features = ConveningFeatures::all(new Router('/v1'));
        $ids = array_map(static fn (array $f): string => (string) $f['id'], $features);

        $hrefs = [];
        foreach ($features as $feature) {
            /** @var list<mixed> $blocks */
            $blocks = $feature['blocks'];
            self::collectRowActionHrefs($blocks, $hrefs);
        }

        self::assertNotSame([], $hrefs, 'the meetings list declares a row action; the walker must find it');

        foreach ($hrefs as $href) {
            if (!str_starts_with($href, '/admin/x/')) {
                continue;
            }
            self::assertContains(
                substr($href, strlen('/admin/x/')),
                $ids,
                "'{$href}' points at a screen this subsystem does not declare — an internal link to a "
                . 'feature id nothing serves renders an empty page rather than an error'
            );
        }
    }

    /**
     * Walk every child slot the contract declares, not merely `children`.
     *
     * {@see BlockContract::childSlots()} is asked rather than a slot list being
     * restated here, for the reason the contract itself records: a walker that
     * does not know about a slot silently skips everything inside it, and a test
     * that skipped a slot would report success over an unchecked subtree.
     *
     * @param list<mixed>  $blocks
     * @param list<string> $out
     */
    private static function collectApiPaths(array $blocks, array &$out): void
    {
        foreach ($blocks as $node) {
            if (!is_array($node)) {
                continue;
            }

            foreach (['source'] as $prop) {
                if (isset($node[$prop]) && is_string($node[$prop]) && str_starts_with($node[$prop], '/api/')) {
                    // A record path may carry `{token}` segments; the prefix is
                    // what is under test, so the token is left alone.
                    $out[] = $node[$prop];
                }
            }

            $type = isset($node['type']) && is_string($node['type']) ? $node['type'] : '';
            foreach (BlockContract::childSlots($type) as $slot) {
                if (isset($node[$slot]) && is_array($node[$slot])) {
                    /** @var list<mixed> $children */
                    $children = $node[$slot];
                    self::collectApiPaths($children, $out);
                }
            }
        }
    }

    /**
     * @param list<mixed>  $blocks
     * @param list<string> $out
     */
    private static function collectRowActionHrefs(array $blocks, array &$out): void
    {
        foreach ($blocks as $node) {
            if (!is_array($node)) {
                continue;
            }

            if (isset($node['rowActions']) && is_array($node['rowActions'])) {
                foreach ($node['rowActions'] as $action) {
                    if (is_array($action) && isset($action['href']) && is_string($action['href'])) {
                        $out[] = $action['href'];
                    }
                }
            }

            $type = isset($node['type']) && is_string($node['type']) ? $node['type'] : '';
            foreach (BlockContract::childSlots($type) as $slot) {
                if (isset($node[$slot]) && is_array($node[$slot])) {
                    /** @var list<mixed> $children */
                    $children = $node[$slot];
                    self::collectRowActionHrefs($children, $out);
                }
            }
        }
    }
}
