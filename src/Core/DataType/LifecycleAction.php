<?php

declare(strict_types=1);

namespace Whity\Core\DataType;

/**
 * The vocabulary of lifecycle actions core can generate and enforce (WC-723).
 *
 * Deliberately five names and no more. The value of a SHARED lifecycle is that
 * "retire" means the same thing in every plugin; an open-ended action set would
 * reintroduce per-plugin vocabulary through the back door, which is the
 * inconsistency this exists to end. A plugin needing a sixth verb keeps its own
 * route for it — the escape hatch is the extension point, not this enum.
 */
final class LifecycleAction
{
    /** Read the record's lifecycle state and its blocking references. */
    public const READ = 'read';

    /**
     * "This should not exist" — a mistake, reversible, removable for real once
     * nothing references it.
     */
    public const TRASH = 'trash';

    /** Undo a trashing, returning the record to the type's default state. */
    public const RESTORE = 'restore';

    /**
     * "This served its purpose" — not a mistake and NOT reversible. Closed to
     * new references, permanently readable, never deletable.
     */
    public const RETIRE = 'retire';

    /** Remove the row for real. Guard-checked, and never available on a retired record. */
    public const DELETE = 'delete';

    /**
     * Every action, in the order a UI would present them.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::READ, self::TRASH, self::RESTORE, self::RETIRE, self::DELETE];
    }

    /**
     * Whether a string names a known action.
     *
     * @param string $action The candidate action name.
     */
    public static function isValid(string $action): bool
    {
        return in_array($action, self::all(), true);
    }

    /**
     * Static vocabulary only — never instantiated.
     */
    private function __construct()
    {
    }
}
