<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Whity\Core\Form\FieldType;

/**
 * The field-kind vocabulary (migration 127).
 *
 * The first test here is the one that earns its keep: the kinds exist in TWO
 * places — this PHP whitelist and a CHECK constraint on `form_fields.field_type`
 * — and a schema that accepts a kind no code understands, or refuses one the
 * builder offers, is a defect nobody notices until an author picks the wrong
 * item from a dropdown. So the migration source is READ and compared, the same
 * way RouteTemplateVocabularyTest pins the routing vocabulary across two
 * migrations rather than trusting whoever edits one to remember the other.
 */
final class FieldTypeTest extends TestCase
{
    public function testWhitelistMatchesTheCheckConstraintInMigration127(): void
    {
        $migration = dirname(__DIR__, 4) . '/database/migrations/127_create_forms.php';
        self::assertFileExists($migration);

        $source = (string) file_get_contents($migration);

        $m = [];
        self::assertSame(
            1,
            preg_match('/CHECK\s*\(field_type\s+IN\s*\((.*?)\)\s*\)/s', $source, $m),
            'Migration 127 must constrain field_type with a CHECK ... IN (...) list.'
        );
        $body = $m[1] ?? '';
        self::assertNotSame('', $body, 'The CHECK must capture its IN (...) body.');

        $found = [];
        $matched = preg_match_all("/'([a-z_]+)'/", $body, $found);
        self::assertGreaterThan(0, $matched, 'The CHECK list must hold single-quoted kind literals.');

        $inMigration = $found[1];
        sort($inMigration);

        $inCode = FieldType::all();
        sort($inCode);

        self::assertSame(
            $inMigration,
            $inCode,
            'FieldType::all() drifted from migration 127\'s CHECK constraint. A kind in one and not '
            . 'the other means either the database refuses something the builder offers, or it '
            . 'accepts something no validator or renderer understands.'
        );
    }

    public function testEveryDeclaredKindIsValidAndNothingElseIs(): void
    {
        foreach (FieldType::all() as $type) {
            self::assertTrue(FieldType::isValid($type), "{$type} must be a valid kind.");
        }

        // Plausible-looking near-misses rather than obvious junk: these are the
        // spellings somebody actually types.
        foreach (['string', 'boolean', 'dropdown', 'TEXT', 'multi_select', ''] as $notAKind) {
            self::assertFalse(FieldType::isValid($notAKind), "{$notAKind} must not be a valid kind.");
        }
    }

    public function testOnlyAuthoredChoiceKindsRequireOptions(): void
    {
        self::assertTrue(FieldType::requiresOptions(FieldType::SELECT));
        self::assertTrue(FieldType::requiresOptions(FieldType::MULTISELECT));

        // The reference pickers resolve their choices from the tenant's live
        // people and units, so an author supplying options for one is describing
        // a picker the renderer will not build. This is the assertion that keeps
        // somebody from "helpfully" adding them to the option-bearing list.
        self::assertFalse(FieldType::requiresOptions(FieldType::PROFILE_REF));
        self::assertFalse(FieldType::requiresOptions(FieldType::OU_REF));

        self::assertFalse(FieldType::requiresOptions(FieldType::TEXT));
        self::assertFalse(FieldType::requiresOptions(FieldType::CHECKBOX));
    }

    public function testOnlyMultiselectIsMultiValued(): void
    {
        self::assertTrue(FieldType::isMultiValued(FieldType::MULTISELECT));

        foreach (FieldType::all() as $type) {
            if ($type === FieldType::MULTISELECT) {
                continue;
            }
            self::assertFalse(
                FieldType::isMultiValued($type),
                "{$type} stores one value, so an array answer for it is malformed rather than a list."
            );
        }
    }

    public function testTheTwoReferenceKindsAreTheOnesNeedingATenantLookup(): void
    {
        self::assertTrue(FieldType::isReference(FieldType::PROFILE_REF));
        self::assertTrue(FieldType::isReference(FieldType::OU_REF));

        foreach ([FieldType::TEXT, FieldType::NUMBER, FieldType::SELECT, FieldType::FILE] as $literal) {
            self::assertFalse(
                FieldType::isReference($literal),
                "{$literal} is a literal a person typed — there is no row to look up in the tenant."
            );
        }
    }
}
