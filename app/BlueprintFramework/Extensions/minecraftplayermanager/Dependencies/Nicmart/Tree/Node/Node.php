<?php

/**
 * Copyright (c) 2013-2024 Nicolò Martini
 *
 * For the full copyright and license information, please view
 * the LICENSE.md file that was distributed with this source code.
 *
 * @see https://github.com/nicmart/Tree
 */

namespace Pterodactyl\BlueprintFramework\Extensions\minecraftplayermanager\Dependencies\Nicmart\Tree\Node;

class Node implements NodeInterface
{
    use NodeTrait;

    /**
     * @param array<int, NodeInterface> $children
     */
    public function __construct(
        mixed $value = null,
        array $children = [],
    ) {
        $this->setValue($value);

        if ([] === $children) {
            return;
        }

        $this->setChildren($children);
    }
}
