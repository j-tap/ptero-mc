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

class PreOrderVisitor implements Visitor
{
    /**
     * @return array<int, NodeInterface> $node
     */
    public function visit(NodeInterface $node): array
    {
        $nodes = [
            $node,
        ];

        foreach ($node->getChildren() as $child) {
            $nodes = \array_merge(
                $nodes,
                $child->accept($this),
            );
        }

        return $nodes;
    }
}
