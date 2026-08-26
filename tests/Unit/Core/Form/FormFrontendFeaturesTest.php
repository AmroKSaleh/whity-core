<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Whity\Core\Form\FormFrontendFeatures;
use Whity\Core\RBAC\CorePermissions;
use Whity\Sdk\Frontend\Blocks\BlockContract;
use Whity\Sdk\Frontend\Blocks\BlockValidator;

/**
 * The three core forms screens, checked against the SAME gate the host applies
 * at request time.
 *
 * WHY THIS TEST MATTERS MORE THAN IT LOOKS
 * -----------------------------------------
 * {@see \Whity\Api\FrontendFeaturesApiHandler} validates every `screen:'blocks'`
 * tree fail-closed and OMITS an invalid one, logging the reason. That is exactly
 * right at runtime and it means a typo in a descriptor does not 500 — the screen
 * simply is not there, which looks identical to a permission problem, a caching
 * problem, and a nav-registration mistake.
 *
 * So a broken tree here would ship as a missing menu item nobody can explain.
 * Running the real validator in a unit test is what turns that into a red build.
 */
final class FormFrontendFeaturesTest extends TestCase
{
    public function testEveryDescriptorPassesTheHostsOwnBlockValidator(): void
    {
        foreach (FormFrontendFeatures::all() as $feature) {
            $id = (string) $feature['id'];
            /** @var list<mixed> $blocks */
            $blocks = $feature['blocks'];

            $result = BlockValidator::validate($blocks);

            self::assertTrue(
                $result['ok'],
                "Feature '{$id}' has an invalid block tree, so the host would silently DROP it and the "
                . "screen would just be missing:\n  - " . implode("\n  - ", $result['errors'])
            );
        }
    }

    public function testTheThreeScreensAreDeclaredWithStableIds(): void
    {
        $ids = array_map(static fn (array $f): string => (string) $f['id'], FormFrontendFeatures::all());

        // The ids are part of the URL (`/admin/x/{featureId}`) and of the
        // navigation entries in public/index.php, so changing one breaks a
        // bookmark and a menu link at the same time.
        self::assertSame(
            [
                FormFrontendFeatures::BUILDER_ID,
                FormFrontendFeatures::CATALOG_ID,
                FormFrontendFeatures::MY_SUBMISSIONS_ID,
            ],
            $ids
        );
    }

    public function testEveryDescriptorCarriesTheContractsRequiredKeys(): void
    {
        foreach (FormFrontendFeatures::all() as $feature) {
            $id = (string) $feature['id'];

            foreach (['id', 'label', 'screen', 'blocks', 'requiredPermission'] as $key) {
                self::assertArrayHasKey($key, $feature, "Feature '{$id}' is missing '{$key}'.");
            }

            self::assertSame('blocks', $feature['screen']);
            self::assertIsArray($feature['blocks']);
            // The handler emits `plugin` on every feature and a client rendering
            // "provided by" needs something true to show.
            self::assertSame('core', $feature['plugin'] ?? null);
        }
    }

    public function testEveryGateIsARealCorePermission(): void
    {
        $expected = [
            FormFrontendFeatures::BUILDER_ID => CorePermissions::FORMS_MANAGE,
            FormFrontendFeatures::CATALOG_ID => CorePermissions::FORMS_READ,
            // NOT forms:read — the rows already name exactly one person, and
            // requiring the tenant-wide read permission would hide this screen
            // from precisely the people whose submissions are in it.
            FormFrontendFeatures::MY_SUBMISSIONS_ID => CorePermissions::FORMS_SUBMIT,
        ];

        foreach (FormFrontendFeatures::all() as $feature) {
            $id = (string) $feature['id'];
            $permission = (string) $feature['requiredPermission'];

            self::assertSame($expected[$id], $permission, "Feature '{$id}' gates on the wrong permission.");
            self::assertContains(
                $permission,
                CorePermissions::all(),
                "Feature '{$id}' names a permission the catalogue does not carry, so the per-caller "
                . 'filter would refuse everybody.'
            );
        }
    }

    public function testNoDescriptorInventsABlockType(): void
    {
        $known = BlockContract::types();

        foreach (FormFrontendFeatures::all() as $feature) {
            /** @var list<mixed> $blocks */
            $blocks = $feature['blocks'];
            foreach (self::typesIn($blocks) as $type) {
                // Adding a block TYPE means changing the contract, writing a
                // renderer on every platform, AND adding a live instance to the
                // UI-kit showcase, whose every-type coverage test fails without
                // one. Core's own screens reuse what already exists.
                self::assertContains(
                    $type,
                    $known,
                    "Block type '{$type}' is not in the contract — a core screen must not introduce one."
                );
            }
        }
    }

    public function testEveryApiPathIsWrittenInItsEMITTEDVersionedForm(): void
    {
        foreach (FormFrontendFeatures::all() as $feature) {
            $id = (string) $feature['id'];
            /** @var list<mixed> $blocks */
            $blocks = $feature['blocks'];

            foreach (self::apiPathsIn($blocks) as $path) {
                // Router::register() prepends '/v1' to a DECLARED path, so
                // index.php registers '/api/forms' and the live URL is
                // '/api/v1/forms'. A block's source is a URL a client will FETCH,
                // not a declaration, so it must carry the version itself —
                // otherwise every one of these 404s at runtime while every test
                // that never issues a request stays green.
                self::assertStringStartsWith(
                    '/api/v1/',
                    $path,
                    "Feature '{$id}' names '{$path}', which is the declared form, not the emitted one."
                );
            }
        }
    }

    /**
     * Every `type` in a tree, including inside every child slot the contract
     * declares — asking {@see BlockContract::childSlots()} rather than reaching
     * for `children`, so a slot added later is not silently skipped here.
     *
     * @param list<mixed> $blocks
     * @return list<string>
     */
    private static function typesIn(array $blocks): array
    {
        $types = [];
        foreach ($blocks as $node) {
            if (!is_array($node) || !isset($node['type']) || !is_string($node['type'])) {
                continue;
            }
            $types[] = $node['type'];
            foreach (BlockContract::knownChildSlots() as $slot) {
                if (isset($node[$slot]) && is_array($node[$slot])) {
                    /** @var list<mixed> $children */
                    $children = $node[$slot];
                    foreach (self::typesIn($children) as $childType) {
                        $types[] = $childType;
                    }
                }
            }
        }

        return $types;
    }

    /**
     * Every API path anywhere in a tree — `source`, `submit.endpoint`,
     * `action.endpoint` and each `rowActions[].endpoint`.
     *
     * @param list<mixed> $blocks
     * @return list<string>
     */
    private static function apiPathsIn(array $blocks): array
    {
        $paths = [];
        foreach ($blocks as $node) {
            if (!is_array($node)) {
                continue;
            }

            if (isset($node['source']) && is_string($node['source'])) {
                $paths[] = $node['source'];
            }
            foreach (['submit', 'action'] as $spec) {
                if (isset($node[$spec]['endpoint']) && is_string($node[$spec]['endpoint'])) {
                    $paths[] = $node[$spec]['endpoint'];
                }
            }
            if (isset($node['rowActions']) && is_array($node['rowActions'])) {
                foreach ($node['rowActions'] as $action) {
                    if (is_array($action) && isset($action['endpoint']) && is_string($action['endpoint'])) {
                        $paths[] = $action['endpoint'];
                    }
                }
            }

            foreach (BlockContract::knownChildSlots() as $slot) {
                if (isset($node[$slot]) && is_array($node[$slot])) {
                    /** @var list<mixed> $children */
                    $children = $node[$slot];
                    foreach (self::apiPathsIn($children) as $childPath) {
                        $paths[] = $childPath;
                    }
                }
            }
        }

        return $paths;
    }
}
