<?php

declare(strict_types=1);

namespace Whity\Api\Exception;

use RuntimeException;

/**
 * Raised when re-parenting an organizational unit would create a cycle.
 *
 * An OU's `parent_id` chain must form a tree: moving an OU under itself or under
 * any of its own descendants would create a loop that breaks hierarchy traversal
 * (role inheritance, tree rendering). This domain exception is caught by
 * {@see \Whity\Api\OusApiHandler::update()} and translated into a safe 422
 * response — the move is rejected and no row is changed.
 */
class OuHierarchyCycleException extends RuntimeException
{
    /**
     * Create an exception describing a rejected cyclic re-parent.
     *
     * @param int $ouId       The OU being moved.
     * @param int $newParentId The proposed new parent that would create a cycle.
     * @return self
     */
    public static function forMove(int $ouId, int $newParentId): self
    {
        return new self(
            "Cannot move organizational unit {$ouId} under {$newParentId}: "
            . 'the target is the unit itself or one of its descendants'
        );
    }
}
