<?php

/**
 * Copyright (c) 2013-2024 Nicolò Martini
 *
 * For the full copyright and license information, please view
 * the LICENSE.md file that was distributed with this source code.
 *
 * @see https://github.com/nicmart/Tree
 */

namespace Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager\Dependencies\Nicmart\Tree\Visitor;

use Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager\Dependencies\Nicmart\Tree\Node\NodeInterface;

class PostOrderVisitor implements Visitor
{
    /**
     * @return array<int, NodeInterface> $node
     */
    public function visit(NodeInterface $node): mixed
    {
        $nodes = [];

        foreach ($node->getChildren() as $child) {
            $nodes = \array_merge(
                $nodes,
                $child->accept($this),
            );
        }

        $nodes[] = $node;

        return $nodes;
    }
}
