<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Document\Node;

use Horde\Yaml\Document\YamlDocument;
use Horde\Yaml\Document\YamlStream;

/**
 * Shared scaffolding for the position and parent-chain accessors of the
 * Node interface. Concrete node classes use this trait so they can stay
 * focused on their specific fields and behaviour.
 *
 * Parent links are package-internal: only mutation methods on container
 * nodes set them. Users do not call setParent() directly.
 */
trait NodeTrait
{
    private ?Node $parent = null;
    private int $line = 0;
    private int $column = 0;

    public function parent(): ?Node
    {
        return $this->parent;
    }

    public function line(): int
    {
        return $this->line;
    }

    public function column(): int
    {
        return $this->column;
    }

    public function document(): ?YamlDocument
    {
        // Walk up the parent chain. The first parent that is not a
        // Node is the YamlDocument that contains this subtree.
        $cur = $this->parent;
        while ($cur !== null) {
            $cur = $cur->parent();
        }
        // Exhausted parents. The root container's value is reached
        // through YamlDocument::root(); we have no direct back-link
        // from the root to its document. For now return null;
        // chapter K wires the back-link if a real consumer demands it.
        return null;
    }

    public function stream(): ?YamlStream
    {
        return $this->document()?->parent();
    }

    /**
     * Package-internal: set the structural parent of this node.
     */
    public function setParent(?Node $parent): void
    {
        $this->parent = $parent;
    }

    /**
     * Package-internal: stamp source position on this node. Called by
     * the parser when constructing nodes from tokens.
     */
    public function setPosition(int $line, int $column): void
    {
        $this->line = $line;
        $this->column = $column;
    }
}
