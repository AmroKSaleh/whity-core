<?php

declare(strict_types=1);

namespace Tests\Core\RBAC;

use PHPUnit\Framework\TestCase;
use Whity\Core\RBAC\CorePermissionDescriptions;
use Whity\Core\RBAC\CorePermissions;

/**
 * The catalogue must describe every permission, and describe it in words.
 *
 * `permissions.description` is rendered and searched in the role editor: it is
 * what somebody reads while deciding whether to grant a capability. On a fresh
 * database 43 of 63 rows said `Core permission (groups:read)` — the slug,
 * restated — because migration 013 pre-created every permission with filler and
 * every later migration's real text hit `ON CONFLICT DO NOTHING`.
 *
 * That is fixed by a code-owned map plus a migration that syncs it. This test is
 * what stops it happening again: adding a permission without describing it fails
 * the build, instead of quietly shipping one more `Core permission (x)` that
 * nobody notices until they are staring at the role editor.
 */
final class CorePermissionDescriptionsTest extends TestCase
{
    /**
     * The one that matters: coverage. `CorePermissions::all()` is the catalogue,
     * so anything in it needs text.
     */
    public function testEveryCorePermissionHasADescription(): void
    {
        $described = CorePermissionDescriptions::all();

        $missing = [];
        foreach (CorePermissions::all() as $slug) {
            if (!array_key_exists($slug, $described) || trim($described[$slug]) === '') {
                $missing[] = $slug;
            }
        }

        self::assertSame(
            [],
            $missing,
            "Every core permission needs a description — it is what an administrator reads when "
            . "deciding to grant it. Add these to CorePermissionDescriptions::all():\n  "
            . implode("\n  ", $missing)
        );
    }

    /**
     * Filler is worse than absence, because it LOOKS answered. Both known shapes
     * are refused: the generated placeholder, and the slug repeated back (which
     * three OU permissions genuinely shipped with).
     */
    public function testNoDescriptionIsFiller(): void
    {
        $filler = [];
        foreach (CorePermissionDescriptions::all() as $slug => $text) {
            if (stripos($text, 'core permission (') === 0 || trim($text) === $slug) {
                $filler[] = $slug . ' => ' . $text;
            }
        }

        self::assertSame([], $filler, "These describe nothing:\n  " . implode("\n  ", $filler));
    }

    /**
     * A bare noun is a label, not an explanation: `groups:read => "Groups"` tells
     * a reader nothing the slug did not.
     *
     * The bar is two words, and deliberately no higher. "Delete users" is a
     * complete and accurate account of what `users:delete` does, and an earlier
     * draft of this test demanded three — which would have been satisfied by
     * padding four perfectly good descriptions until they were longer and no
     * clearer. The test exists to catch absence of meaning, not brevity.
     */
    public function testDescriptionsExplainRatherThanLabel(): void
    {
        $tooShort = [];
        foreach (CorePermissionDescriptions::all() as $slug => $text) {
            if (str_word_count($text) < 2) {
                $tooShort[] = $slug . ' => ' . $text;
            }
        }

        self::assertSame([], $tooShort, "Too terse to explain a grant:\n  " . implode("\n  ", $tooShort));
    }

    /**
     * The map must not describe permissions that do not exist. A stale entry is
     * harmless at runtime — the migration matches on `name` and updates zero
     * rows — but it is a lie about the catalogue, and the next person to read
     * this file will believe it.
     */
    public function testTheMapDescribesNothingThatIsNotAPermission(): void
    {
        $known = CorePermissions::all();

        $unknown = array_values(array_filter(
            array_keys(CorePermissionDescriptions::all()),
            static fn (string $slug): bool => !in_array($slug, $known, true)
        ));

        self::assertSame([], $unknown, "Described but not declared:\n  " . implode("\n  ", $unknown));
    }

    /** `for()` answers for a core slug and declines for anything else. */
    public function testForReturnsNullOutsideTheCoreCatalogue(): void
    {
        self::assertNotNull(CorePermissionDescriptions::for(CorePermissions::AUDIT_READ));
        self::assertNull(
            CorePermissionDescriptions::for('someplugin:doathing'),
            "A plugin's permissions are described by the plugin, not here"
        );
    }
}
