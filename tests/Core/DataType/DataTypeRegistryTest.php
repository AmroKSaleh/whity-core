<?php

declare(strict_types=1);

namespace Tests\Core\DataType;

use PHPUnit\Framework\TestCase;
use Whity\Core\DataType\DataTypeRegistry;
use Whity\Core\DataType\InvalidDataTypeException;
use Whity\Core\DataType\LifecycleAction;
use Whity\Core\Tenant\TableOwnershipRegistry;

/**
 * WC-723 Door 2: what a plugin may declare, and what it may not.
 *
 * The load-bearing case is ownership. A referential guard is an aggregate over
 * the referencing table — "how many rows in acme_entries point at this?" — so a
 * guard over a table the plugin does not own is a way to count rows in somebody
 * else's data by declaration alone. Piece 1 exists to make that refusable; these
 * pin the refusal.
 *
 * The second theme is honest degradation: an action exists only when BOTH its
 * lifecycle support and its permission are declared, so a generated screen can
 * never offer a control the endpoint would refuse.
 */
final class DataTypeRegistryTest extends TestCase
{
    /**
     * An ownership map where `Acme` owns two tenant tables and one global one,
     * and `Globex` owns a table of its own.
     */
    private function ownership(): TableOwnershipRegistry
    {
        $tables = new TableOwnershipRegistry();
        $tables->register('Acme', [
            'acme_records' => TableOwnershipRegistry::SCOPE_TENANT,
            'acme_entries' => TableOwnershipRegistry::SCOPE_TENANT,
            'acme_counter' => TableOwnershipRegistry::SCOPE_GLOBAL,
        ]);
        $tables->register('Globex', [
            'globex_secrets' => TableOwnershipRegistry::SCOPE_TENANT,
        ]);

        return $tables;
    }

    private function registry(): DataTypeRegistry
    {
        return new DataTypeRegistry($this->ownership());
    }

    /**
     * A minimal valid declaration for `Acme`.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function declaration(array $overrides = []): array
    {
        return $overrides + [
            'table' => 'acme_records',
            'key' => 'id',
            'label' => ['en' => 'Record'],
            'lifecycle' => [
                'column' => 'status',
                'states' => ['draft', 'active', 'retired', 'trashed'],
                'default_state' => 'active',
                'trashable' => true,
                'retirable' => true,
            ],
            'blocks_delete' => [
                ['table' => 'acme_entries', 'column' => 'record_id', 'label' => 'recorded entries'],
            ],
            'permissions' => [
                'read' => 'acme:read',
                'trash' => 'acme:manage',
                'restore' => 'acme:manage',
                'retire' => 'acme:retire',
                'delete' => 'acme:manage',
            ],
        ];
    }

    // ==================== Ownership is the gate ====================

    public function testAPluginIsRefusedAGuardOverATableItDoesNotOwn(): void
    {
        $registry = $this->registry();

        $this->expectException(InvalidDataTypeException::class);
        $this->expectExceptionMessage("may not declare table 'globex_secrets'");

        $registry->register('Acme', [
            'record' => $this->declaration([
                'blocks_delete' => [
                    // Another plugin's table. Accepting this would turn the guard
                    // vocabulary into a row-counting probe of Globex's data.
                    ['table' => 'globex_secrets', 'column' => 'record_id', 'label' => 'secrets'],
                ],
            ]),
        ]);
    }

    public function testTheRefusalNamesTheActualOwnerSoTheMistakeIsDiagnosable(): void
    {
        try {
            $this->registry()->register('Acme', [
                'record' => $this->declaration([
                    'blocks_delete' => [
                        ['table' => 'globex_secrets', 'column' => 'record_id', 'label' => 'secrets'],
                    ],
                ]),
            ]);
            self::fail('A guard over another plugin\'s table must be refused.');
        } catch (InvalidDataTypeException $e) {
            self::assertStringContainsString("owned by 'globex'", $e->getMessage());
        }
    }

    public function testAPluginIsRefusedAGuardOverATableNobodyOwns(): void
    {
        $this->expectException(InvalidDataTypeException::class);
        $this->expectExceptionMessage('no source has declared it');

        $this->registry()->register('Acme', [
            'record' => $this->declaration([
                'blocks_delete' => [
                    ['table' => 'not_declared_anywhere', 'column' => 'record_id', 'label' => 'ghosts'],
                ],
            ]),
        ]);
    }

    public function testAPluginIsRefusedADataTypeOverACoreTable(): void
    {
        $this->expectException(InvalidDataTypeException::class);
        $this->expectExceptionMessage("owned by 'core'");

        $this->registry()->register('Acme', [
            'record' => $this->declaration(['table' => 'audit_log', 'blocks_delete' => []]),
        ]);
    }

    public function testATypeOverANonTenantScopedTableIsRefused(): void
    {
        // Tenant isolation is non-negotiable: no tenant predicate could be bound
        // to a table declared global, so the type is refused rather than served
        // unscoped.
        $this->expectException(InvalidDataTypeException::class);
        $this->expectExceptionMessage('not declared tenant-scoped');

        $this->registry()->register('Acme', [
            'counter' => $this->declaration(['table' => 'acme_counter', 'blocks_delete' => []]),
        ]);
    }

    public function testAValidDeclarationOverOwnedTablesIsAccepted(): void
    {
        $registry = $this->registry();
        $keys = $registry->register('Acme', ['record' => $this->declaration()]);

        self::assertSame(['acme:record'], $keys);
        $definition = $registry->get('acme:record');
        self::assertNotNull($definition);
        self::assertSame('acme_records', $definition->table());
        self::assertSame('Acme', $definition->source());
        self::assertCount(1, $definition->guards());
        self::assertSame('recorded entries', $definition->guards()[0]->label());
    }

    // ==================== Namespacing ====================

    public function testTwoPluginsDeclaringTheSameSlugDoNotCollide(): void
    {
        $tables = $this->ownership();
        $registry = new DataTypeRegistry($tables);

        $registry->register('Acme', ['record' => $this->declaration(['blocks_delete' => []])]);
        $registry->register('Globex', [
            'record' => [
                'table' => 'globex_secrets',
                'permissions' => ['read' => 'globex:read'],
            ],
        ]);

        self::assertTrue($registry->has('acme:record'));
        self::assertTrue($registry->has('globex:record'));
        self::assertFalse($registry->has('record'), 'A bare slug must never become a registered key.');
    }

    public function testCanonicalKeyIsTheOnePlaceTheNamespacingRuleLives(): void
    {
        self::assertSame('acme:record', DataTypeRegistry::canonicalKey('Acme', 'record'));
        self::assertSame(
            'acmewidgets:record',
            DataTypeRegistry::canonicalKey('Acme\\Widgets\\AcmeWidgets', 'record')
        );
    }

    // ==================== Honest degradation ====================

    public function testAnActionWithNoDeclaredPermissionIsNotOffered(): void
    {
        $registry = $this->registry();
        $registry->register('Acme', [
            'record' => $this->declaration([
                'blocks_delete' => [],
                'permissions' => ['read' => 'acme:read', 'trash' => 'acme:manage', 'restore' => 'acme:manage'],
            ]),
        ]);

        $definition = $registry->get('acme:record');
        self::assertNotNull($definition);

        self::assertTrue(
            $definition->supports(LifecycleAction::RETIRE),
            'The lifecycle structurally supports retirement…'
        );
        self::assertFalse(
            $definition->offers(LifecycleAction::RETIRE),
            '…but with no permission declared the action is NOT offered — omitted, not ungated.'
        );
        self::assertNotContains(LifecycleAction::RETIRE, $definition->offeredActions());
        self::assertContains(LifecycleAction::TRASH, $definition->offeredActions());
    }

    public function testATypeWithNoLifecycleOffersNoTrashOrRetire(): void
    {
        $registry = $this->registry();
        $registry->register('Acme', [
            'record' => [
                'table' => 'acme_records',
                'permissions' => [
                    'read' => 'acme:read',
                    'trash' => 'acme:manage',
                    'retire' => 'acme:manage',
                    'delete' => 'acme:manage',
                ],
            ],
        ]);

        $definition = $registry->get('acme:record');
        self::assertNotNull($definition);
        self::assertFalse($definition->offers(LifecycleAction::TRASH));
        self::assertFalse($definition->offers(LifecycleAction::RETIRE));
        self::assertTrue(
            $definition->offers(LifecycleAction::DELETE),
            'Delete still applies — it is simply not a two-step when nothing is trashable.'
        );
    }

    public function testTheGeneratedContractPublishesOnlyOfferedActions(): void
    {
        $registry = $this->registry();
        $registry->register('Acme', [
            'record' => $this->declaration([
                'permissions' => ['read' => 'acme:read', 'trash' => 'acme:manage', 'restore' => 'acme:manage'],
            ]),
        ]);

        $definition = $registry->get('acme:record');
        self::assertNotNull($definition);
        $payload = $definition->toArray();

        self::assertSame(['read', 'trash', 'restore'], $payload['actions']);
        self::assertSame(
            [['table' => 'acme_entries', 'column' => 'record_id', 'label' => 'recorded entries']],
            $payload['blocks_delete'],
            'The reference graph is published as metadata so a screen can explain a refusal.'
        );
    }

    // ==================== Lifecycle validation ====================

    public function testTrashableWithoutAStateColumnIsRefusedLoudly(): void
    {
        // Silently dropping a requested `trashable` would leave the plugin
        // believing deletes are recoverable when they are permanent.
        $this->expectException(InvalidDataTypeException::class);
        $this->expectExceptionMessage("'column' is required");

        $this->registry()->register('Acme', [
            'record' => $this->declaration([
                'blocks_delete' => [],
                'lifecycle' => ['trashable' => true],
            ]),
        ]);
    }

    public function testAStateNamedAsTrashedMustBeOneOfTheDeclaredStates(): void
    {
        $this->expectException(InvalidDataTypeException::class);

        $this->registry()->register('Acme', [
            'record' => $this->declaration([
                'blocks_delete' => [],
                'lifecycle' => [
                    'column' => 'status',
                    'states' => ['active'],
                    'trashable' => true,
                    'trashed_state' => 'binned',
                ],
            ]),
        ]);
    }

    public function testTrashedAndRetiredMayNotBeTheSameState(): void
    {
        // Collapsing them loses the distinction the whole lifecycle exists for.
        $this->expectException(InvalidDataTypeException::class);
        $this->expectExceptionMessage('must differ');

        $this->registry()->register('Acme', [
            'record' => $this->declaration([
                'blocks_delete' => [],
                'lifecycle' => [
                    'column' => 'status',
                    'states' => ['active', 'gone'],
                    'default_state' => 'active',
                    'trashable' => true,
                    'retirable' => true,
                    'trashed_state' => 'gone',
                    'retired_state' => 'gone',
                ],
            ]),
        ]);
    }

    public function testTheDefaultStateMayNotBeTheTrashedState(): void
    {
        $this->expectException(InvalidDataTypeException::class);

        $this->registry()->register('Acme', [
            'record' => $this->declaration([
                'blocks_delete' => [],
                'lifecycle' => [
                    'column' => 'status',
                    'states' => ['trashed', 'active'],
                    'default_state' => 'trashed',
                    'trashable' => true,
                ],
            ]),
        ]);
    }

    public function testRetirableAloneIsValid(): void
    {
        // The two axes are independent: a type may be retirable without being
        // trashable, and vice versa.
        $registry = $this->registry();
        $registry->register('Acme', [
            'record' => $this->declaration([
                'blocks_delete' => [],
                'lifecycle' => [
                    'column' => 'status',
                    'states' => ['active', 'retired'],
                    'default_state' => 'active',
                    'retirable' => true,
                ],
            ]),
        ]);

        $definition = $registry->get('acme:record');
        self::assertNotNull($definition);
        self::assertTrue($definition->lifecycle()->isRetirable());
        self::assertFalse($definition->lifecycle()->isTrashable());
        self::assertTrue($definition->offers(LifecycleAction::RETIRE));
        self::assertFalse($definition->offers(LifecycleAction::TRASH));
    }

    // ==================== Identifier + permission validation ====================

    public function testAnInjectedIdentifierIsRefused(): void
    {
        $this->expectException(InvalidDataTypeException::class);

        $this->registry()->register('Acme', [
            'record' => $this->declaration([
                'blocks_delete' => [],
                'key' => 'id; DROP TABLE roles; --',
            ]),
        ]);
    }

    public function testAMalformedPermissionSlugIsRefused(): void
    {
        $this->expectException(InvalidDataTypeException::class);

        $this->registry()->register('Acme', [
            'record' => $this->declaration([
                'blocks_delete' => [],
                'permissions' => ['read' => 'not a slug'],
            ]),
        ]);
    }

    public function testAnActionOutsideTheLifecycleVocabularyIsRefused(): void
    {
        $this->expectException(InvalidDataTypeException::class);

        $this->registry()->register('Acme', [
            'record' => $this->declaration([
                'blocks_delete' => [],
                'permissions' => ['obliterate' => 'acme:manage'],
            ]),
        ]);
    }

    public function testAGuardWithoutALabelIsRefused(): void
    {
        // The label IS the refusal message; a guard without one could only
        // produce a 409 the user cannot act on.
        $this->expectException(InvalidDataTypeException::class);
        $this->expectExceptionMessage("'label' is required");

        $this->registry()->register('Acme', [
            'record' => $this->declaration([
                'blocks_delete' => [['table' => 'acme_entries', 'column' => 'record_id']],
            ]),
        ]);
    }

    public function testAMalformedSlugIsRefused(): void
    {
        $this->expectException(InvalidDataTypeException::class);

        $this->registry()->register('Acme', ['Record:Thing' => $this->declaration(['blocks_delete' => []])]);
    }

    public function testAValidTypeDeclaredBeforeAMalformedOneSurvives(): void
    {
        $registry = $this->registry();

        try {
            $registry->register('Acme', [
                'record' => $this->declaration(['blocks_delete' => []]),
                'broken' => ['table' => 'globex_secrets'],
            ]);
            self::fail('The malformed declaration must be reported.');
        } catch (InvalidDataTypeException) {
            // expected
        }

        self::assertTrue(
            $registry->has('acme:record'),
            'Rejection is per data type — one bad entry must not discard the rest.'
        );
        self::assertFalse($registry->has('acme:broken'));
    }
}
