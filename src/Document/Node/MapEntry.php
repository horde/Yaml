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
 * One key-value pair inside a MapNode.
 *
 * MapEntry is itself a Node (per Stage 3 decision 11.1) so tree walks
 * are uniform. Its key is any value-position node, typically a
 * ScalarNode, but a compound key (MapNode, SequenceNode, AliasNode)
 * is permitted per YAML 1.2 §8.1.3 (`? key\n: value` form). Its
 * value is a value-position node (MapNode, SequenceNode, ScalarNode,
 * or AliasNode), never null. The optional eolComment is a
 * CommentNode sitting on the entry's line.
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/03-ast-and-document-model-2026-06-11.md §2.3
 */
final class MapEntry implements Node
{
    use NodeTrait;

    private MapNode|SequenceNode|ScalarNode|AliasNode $key;
    private MapNode|SequenceNode|ScalarNode|AliasNode $value;
    private ?CommentNode $eolComment;

    public function __construct(
        MapNode|SequenceNode|ScalarNode|AliasNode $key,
        MapNode|SequenceNode|ScalarNode|AliasNode $value,
        ?CommentNode $eolComment = null,
    ) {
        $this->key = $key;
        $this->key->setParent($this);
        $this->value = $value;
        $this->value->setParent($this);
        $this->eolComment = $eolComment;
        if ($eolComment !== null) {
            $eolComment->setParent($this);
        }
    }

    public function getKey(): MapNode|SequenceNode|ScalarNode|AliasNode
    {
        return $this->key;
    }

    /**
     * Convenience for the common scalar-key case. Compound keys
     * (mappings, sequences, aliases) are rendered as a synthesised
     * label so the method always returns a string. Callers needing
     * full fidelity should use getKey() and inspect the node type.
     */
    public function getKeyString(): string
    {
        if ($this->key instanceof ScalarNode) {
            $value = $this->key->getValue();
            return is_string($value) ? $value : (string) $value;
        }
        if ($this->key instanceof AliasNode) {
            return '*' . $this->key->getName();
        }
        if ($this->key instanceof SequenceNode) {
            return '[<sequence key>]';
        }
        return '{<mapping key>}';
    }

    public function getValue(): MapNode|SequenceNode|ScalarNode|AliasNode
    {
        return $this->value;
    }

    /**
     * Set or replace this entry's key. Accepts any value-position
     * node or a plain scalar (auto-wrapped in a fresh ScalarNode).
     */
    public function setKey(
        MapNode|SequenceNode|ScalarNode|AliasNode|string|int|float|bool $key,
    ): void {
        if ($key instanceof MapNode
            || $key instanceof SequenceNode
            || $key instanceof ScalarNode
            || $key instanceof AliasNode
        ) {
            $this->key = $key;
            $key->setParent($this);
            return;
        }
        $this->key = new ScalarNode($key);
        $this->key->setParent($this);
    }

    /**
     * Set or replace this entry's value. Accepts a value-position
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

    public function getEolComment(): ?CommentNode
    {
        return $this->eolComment;
    }

    /**
     * Set or clear the EOL comment on this entry. Accepts a
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
     * Package-internal: assign a new value. The public setValue API
     * lands in chapter L.
     */
    public function setValueInternal(MapNode|SequenceNode|ScalarNode|AliasNode $value): void
    {
        $this->value = $value;
        $value->setParent($this);
    }

    /**
     * Package-internal: assign or clear the EOL comment. Public API
     * lands in chapter L.
     *
     * @deprecated Use setEolComment instead.
     */
    public function setEolCommentInternal(?CommentNode $comment): void
    {
        $this->setEolComment($comment);
    }
}
