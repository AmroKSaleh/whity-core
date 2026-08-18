<?php

declare(strict_types=1);

namespace Whity\Core\DesktopPlugins;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Clears the `comments` attribute (regular AND doc comments) from every node, so
 * the pretty-printer emits none — independent of the printer's own comment
 * handling. This is what removes all authored intent (docblocks, inline notes)
 * from a released package.
 */
final class CommentStripper extends NodeVisitorAbstract
{
    public function enterNode(Node $node): null
    {
        $node->setAttribute('comments', []);

        return null;
    }
}
