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
use Stringable;

/**
 * One item inside a SequenceNode.
 *
 * SequenceItem is a Node so tree walks are uniform. Its value is a
 * value-position node (MapNode, SequenceNode, ScalarNode, or
 * AliasNode), never null. Carries an optional EOL comment.
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/03-ast-and-document-model-2026-06-11.md §2.5
 */
final class SequenceItem implements Node
{
    use NodeTrait;

    private MapNode|SequenceNode|ScalarNode|AliasNode|null $value = null;
    private ?CommentNode $eolComment;

    public function __construct(
        MapNode|SequenceNode|ScalarNode|AliasNode|null $value = null,
        ?CommentNode $eolComment = null,
    ) {
        if ($value !== null) {
            $this->value = $value;
            $value->setParent($this);
        }
        $this->eolComment = $eolComment;
        if ($eolComment !== null) {
            $eolComment->setParent($this);
        }
    }

    public function getValue(): MapNode|SequenceNode|ScalarNode|AliasNode|null
    {
        return $this->value;
    }

    /**
     * Set or replace this item's value. Accepts a value-position
     * Node, a scalar, null, or a Stringable.
     *
     * @param Node|scalar|null|Stringable $value
     */
    public function setValue(mixed $value): void
    {
        $this->setValueInternal($this->coerceValue($value));
    }

    /**
     * @param Node|scalar|null|Stringable $value
     */
    private function coerceValue(mixed $value): MapNode|SequenceNode|ScalarNode|AliasNode
    {
        if ($value instanceof MapNode
            || $value instanceof SequenceNode
            || $value instanceof ScalarNode
            || $value instanceof AliasNode
        ) {
            return $value;
        }
        if ($value instanceof MapEntry || $value instanceof SequenceItem
            || $value instanceof CommentNode || $value instanceof BlankLineNode
        ) {
            throw new InvalidArgumentException(
                'Value must be a value-position node; got ' . $value::class,
            );
        }
        if ($value === null || is_scalar($value)) {
            return new ScalarNode($value);
        }
        if ($value instanceof Stringable) {
            return new ScalarNode((string) $value);
        }
        throw new InvalidArgumentException(
            'Unsupported value type: ' . get_debug_type($value),
        );
    }

    /**
     * Package-internal: assign a new value. Public setValue API
     * lands in chapter L.
     */
    public function setValueInternal(MapNode|SequenceNode|ScalarNode|AliasNode $value): void
    {
        $this->value = $value;
        $value->setParent($this);
    }

    public function getEolComment(): ?CommentNode
    {
        return $this->eolComment;
    }

    /**
     * Set or clear the EOL comment on this item. Accepts a
     * CommentNode, a comment text string starting with `#`, or null.
     */
    public function setEolComment(CommentNode|string|null $comment): void
    {
        if ($comment === null) {
            if ($this->eolComment !== null) {
                $this->eolComment->setParent(null);
            }
            $this->eolComment = null;
            return;
        }
        if (is_string($comment)) {
            if (!str_starts_with($comment, '#')) {
                throw new InvalidArgumentException(
                    'Comment text must start with `#`; got: ' . $comment,
                );
            }
            $comment = new CommentNode($comment);
        }
        $this->eolComment = $comment;
        $comment->setParent($this);
    }

    /**
     * @deprecated Use setEolComment instead.
     */
    public function setEolCommentInternal(?CommentNode $comment): void
    {
        $this->setEolComment($comment);
    }
}
