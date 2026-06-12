<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Document;

use Horde\Yaml\Document\Node\Node;

/**
 * Per-document anchor index.
 *
 * Maps anchor names (`&foo`) to the nodes they're attached to. Used
 * by AliasNode::target() to resolve aliases at call time. Anchors are
 * document-local per YAML 1.2 spec.
 *
 * Skeleton in this phase. Full implementation lands when the parser
 * starts stamping anchors (chapter H).
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/03-ast-and-document-model-2026-06-11.md §5
 */
final class AnchorIndex
{
    /** @var array<string, Node> */
    private array $entries = [];

    public function register(string $name, Node $node): void
    {
        $this->entries[$name] = $node;
    }

    public function lookup(string $name): ?Node
    {
        return $this->entries[$name] ?? null;
    }

    public function unregister(string $name): void
    {
        unset($this->entries[$name]);
    }

    public function has(string $name): bool
    {
        return isset($this->entries[$name]);
    }
}
