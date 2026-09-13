<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Entitlement;

use PHPUnit\Framework\TestCase;
use Whity\Core\Entitlement\EntitlementDefinition;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\EntitlementValidationException;
use Whity\Core\Entitlement\PluginEntitlements;

/**
 * Plugins selling their own limits, and the rules that stop them colliding.
 *
 * A vertical product sells things core has never heard of — a course plugin
 * sells "max students", an exam plugin sells "exams per month" — and those have
 * to reach the same tier editor, be priced by the same people, and be resolved
 * by the same three layers. What they must NOT do is quietly redefine something
 * core already means, or disagree with another plugin about one key, because
 * neither is a conflict anybody would find by reading.
 */
final class PluginEntitlementsTest extends TestCase
{
    protected function setUp(): void
    {
        PluginEntitlements::reset();
    }

    protected function tearDown(): void
    {
        PluginEntitlements::reset();
    }

    public function testADeclaredLimitJoinsTheCatalogue(): void
    {
        PluginEntitlements::register($this->students(500));

        self::assertTrue(EntitlementRegistry::isKnown('courses.students.max'));
        self::assertSame('500', EntitlementRegistry::defaultFor('courses.students.max'));
        self::assertContains('courses.students.max', EntitlementRegistry::keys());
        self::assertArrayHasKey('courses.students.max', EntitlementRegistry::catalogue());
    }

    /** The editor needs to know who sells a limit, to group it under them. */
    public function testTheCatalogueSaysWhoOwnsEachLimit(): void
    {
        PluginEntitlements::register($this->students(500));
        $catalogue = EntitlementRegistry::catalogue();

        self::assertSame('courses', $catalogue['courses.students.max']['owner']);
        self::assertNull(
            $catalogue[EntitlementRegistry::MEMBERS_MAX]['owner'],
            'A core limit has no plugin owner.'
        );
    }

    /** A plugin may sell a metered limit too — "exams per month". */
    public function testAPluginMayDeclareAMeteredLimit(): void
    {
        PluginEntitlements::register(new EntitlementDefinition(
            'courses.exams.per_month',
            EntitlementDefinition::TYPE_INT,
            '-1',
            'Exams this workspace may run each calendar month.',
            EntitlementDefinition::PERIOD_MONTH,
            'courses',
        ));

        self::assertSame(EntitlementDefinition::PERIOD_MONTH, EntitlementRegistry::periodFor('courses.exams.per_month'));
    }

    // ── Ownership ───────────────────────────────────────────────────────────

    /**
     * CORE NAMES ARE NOT PLUGIN-OWNABLE — the same rule the loader already
     * applies to permissions and routes. A plugin redefining `members.max`
     * would change what every tier in the product means.
     */
    public function testAPluginCannotShadowACoreLimit(): void
    {
        $this->expectException(EntitlementValidationException::class);

        PluginEntitlements::register(new EntitlementDefinition(
            EntitlementRegistry::MEMBERS_MAX,
            EntitlementDefinition::TYPE_INT,
            '5',
            'Trying to redefine a core limit.',
            null,
            'courses',
        ));
    }

    /** And only under its own namespace, so two plugins cannot meet. */
    public function testAPluginCannotDeclareOutsideItsOwnNamespace(): void
    {
        $this->expectException(EntitlementValidationException::class);

        PluginEntitlements::register(new EntitlementDefinition(
            'exams.students.max',
            EntitlementDefinition::TYPE_INT,
            '5',
            'Declared under somebody else\'s namespace.',
            null,
            'courses',
        ));
    }

    public function testAnUnnamespacedKeyIsRefused(): void
    {
        $this->expectException(EntitlementValidationException::class);

        PluginEntitlements::register(new EntitlementDefinition(
            'students',
            EntitlementDefinition::TYPE_INT,
            '5',
            'No namespace at all.',
            null,
            'courses',
        ));
    }

    /**
     * TWO PLUGINS DISAGREEING ABOUT ONE KEY IS ALWAYS AN ERROR. Keeping either
     * one silently prices a feature that the other half of the code does not
     * enforce.
     */
    public function testTwoPluginsCannotDefineTheSameKeyDifferently(): void
    {
        PluginEntitlements::register($this->students(500));

        $this->expectException(EntitlementValidationException::class);
        PluginEntitlements::register($this->students(10));
    }

    /**
     * An IDENTICAL re-declaration is fine, because a plugin can be loaded twice
     * in one process by tooling and tests — and failing there would make the
     * catalogue depend on load order.
     */
    public function testAnIdenticalRedeclarationIsHarmless(): void
    {
        PluginEntitlements::register($this->students(500));
        PluginEntitlements::register($this->students(500));

        self::assertSame('500', EntitlementRegistry::defaultFor('courses.students.max'));
    }

    // ── Declarations that would break something later ───────────────────────

    /**
     * A DEFAULT ITS OWN TYPE WOULD REJECT is refused at declaration, not at the
     * moment a tenant resolves it. Accepted here, every request for every
     * tenant would then be resolving a value that fails validation.
     */
    public function testADefaultThatIsInvalidForItsTypeIsRefused(): void
    {
        $this->expectException(EntitlementValidationException::class);

        PluginEntitlements::register(new EntitlementDefinition(
            'courses.students.max',
            EntitlementDefinition::TYPE_INT,
            'lots',
            'A default that is not a number.',
            null,
            'courses',
        ));
    }

    /** A limit nobody can read is a limit nobody can price. */
    public function testALimitWithNoDescriptionIsRefused(): void
    {
        $this->expectException(EntitlementValidationException::class);

        PluginEntitlements::register(new EntitlementDefinition(
            'courses.students.max',
            EntitlementDefinition::TYPE_INT,
            '500',
            '',
            null,
            'courses',
        ));
    }

    /** A flag cannot be metered: there is nothing to count. */
    public function testAMeteredBoolIsRefused(): void
    {
        $this->expectException(EntitlementValidationException::class);

        PluginEntitlements::register(new EntitlementDefinition(
            'courses.enabled',
            EntitlementDefinition::TYPE_BOOL,
            'true',
            'A flag that claims to reset weekly.',
            EntitlementDefinition::PERIOD_WEEK,
            'courses',
        ));
    }

    public function testAnUnknownPeriodIsRefused(): void
    {
        $this->expectException(EntitlementValidationException::class);

        PluginEntitlements::register(new EntitlementDefinition(
            'courses.exams.per_fortnight',
            EntitlementDefinition::TYPE_INT,
            '-1',
            'A window nothing knows how to close.',
            'fortnight',
            'courses',
        ));
    }

    // ── Lifecycle ───────────────────────────────────────────────────────────

    /**
     * REGISTERING AFTER BOOT IS REFUSED, and this is the subtle one. The
     * catalogue is process-level static and there are eight FrankenPHP workers.
     * A declaration arriving inside a request would be known to ONE of them, so
     * a tenant's limits would depend on which worker answered — a bug that
     * reads as flakiness and would be chased for days.
     */
    public function testDeclaringAfterTheCatalogueIsSealedIsRefused(): void
    {
        PluginEntitlements::seal();

        $this->expectException(EntitlementValidationException::class);
        PluginEntitlements::register($this->students(500));
    }

    /**
     * A DEACTIVATED PLUGIN'S LIMITS LEAVE WITH IT. Otherwise a priced feature
     * stays in the catalogue with nothing enforcing it — worse than not selling
     * it, because somebody is paying.
     */
    public function testDeactivatingAPluginWithdrawsItsLimits(): void
    {
        PluginEntitlements::register($this->students(500));
        PluginEntitlements::register(new EntitlementDefinition(
            'exams.attempts.max',
            EntitlementDefinition::TYPE_INT,
            '3',
            'Attempts per exam.',
            null,
            'exams',
        ));

        PluginEntitlements::forget('courses');

        self::assertFalse(EntitlementRegistry::isKnown('courses.students.max'));
        self::assertTrue(
            EntitlementRegistry::isKnown('exams.attempts.max'),
            'Withdrawing one plugin must not take another plugin with it.'
        );
    }

    private function students(int $default): EntitlementDefinition
    {
        return new EntitlementDefinition(
            'courses.students.max',
            EntitlementDefinition::TYPE_INT,
            (string) $default,
            'Maximum students this workspace may enrol.',
            null,
            'courses',
        );
    }
}
