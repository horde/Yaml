<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Document\Node;

use InvalidArgumentException;

/**
 * A run of one or more blank lines in source.
 *
 * BlankLineNode is a first-class node alongside CommentNode, sibling
 * of MapEntry / SequenceItem / CommentNode in container children
 * lists, and of CommentNode in stream/document trivia lists.
 *
 * Holds a count (>= 1) of how many blank lines this node represents.
 * count must always be >= 1; "no blanks" means no node, not count = 0.
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/03-ast-and-document-model-2026-06-11.md §2.9
 */
final class BlankLineNode implements Node
{
    use NodeTrait;

    private int $count;

    public function __construct(int $count = 1)
    {
        if ($count < 1) {
            throw new InvalidArgumentException(
                'BlankLineNode count must be >= 1; got ' . $count,
            );
        }
        $this->count = $count;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function setCount(int $count): void
    {
        if ($count < 1) {
            throw new InvalidArgumentException(
                'BlankLineNode count must be >= 1; got ' . $count
                    . ' (to remove blanks, remove the node)',
            );
        }
        $this->count = $count;
    }
}
