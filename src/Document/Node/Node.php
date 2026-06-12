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
 * Common contract for every node in the document layer's AST.
 *
 * Nodes form a strict tree (no cycles); every node has at most one
 * structural parent. The only node whose parent() returns null is the
 * YamlStream itself, which is not a Node but a separate root container.
 *
 * Position info is 1-based for source-loaded nodes. Synthesized nodes
 * (created post-parse via API) return 0 for both line() and column()
 * to indicate "not from source."
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/03-ast-and-document-model-2026-06-11.md §2.1
 */
interface Node
{
    /**
     * Return the structural parent of this node, or null if the node is
     * not yet attached to a tree (transient state during construction).
     */
    public function parent(): ?Node;

    /**
     * Return the 1-based line of the first significant token of this
     * node in source. Returns 0 for synthesized nodes.
     */
    public function line(): int;

    /**
     * Return the 1-based column of the first significant token of this
     * node in source. Returns 0 for synthesized nodes.
     */
    public function column(): int;

    /**
     * Convenience: walk up the parent chain to the containing
     * YamlDocument. Null if the node is not yet attached.
     */
    public function document(): ?YamlDocument;

    /**
     * Convenience: walk up to the containing YamlStream. Null if the
     * node is not yet attached.
     */
    public function stream(): ?YamlStream;
}
