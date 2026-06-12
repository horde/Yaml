<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Document\Node;

use ArrayAccess;
use Countable;
use Generator;
use InvalidArgumentException;
use IteratorAggregate;
use Stringable;

/**
 * A YAML sequence (ordered list).
 *
 * Children are an ordered list of SequenceItem, CommentNode, and
 * BlankLineNode instances. Style is block or flow. Anchor and tag
 * are optional.
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/03-ast-and-document-model-2026-06-11.md §2.4
 */
final class SequenceNode implements Node, ArrayAccess, Countable, IteratorAggregate
{
    use NodeTrait;

    /** @var list<SequenceItem|CommentNode|BlankLineNode> */
    private array $children = [];

    private SequenceStyle $style;
    private ?string $anchor;
    private ?string $tag;
    private ?FlowFormat $flowFormat = null;

    public function __construct(
        SequenceStyle $style = SequenceStyle::Block,
        ?string $anchor = null,
        ?string $tag = null,
    ) {
        $this->style = $style;
        $this->anchor = $anchor;
        $this->tag = $tag;
    }

    public function getStyle(): SequenceStyle
    {
        return $this->style;
    }

    public function setStyle(SequenceStyle $style): void
    {
        $this->style = $style;
    }

    public function getFlowFormat(): ?FlowFormat
    {
        return $this->flowFormat;
    }

    public function setFlowFormat(?FlowFormat $format): void
    {
        $this->flowFormat = $format;
    }

    public function getAnchor(): ?string
    {
        return $this->anchor;
    }

    public function setAnchor(?string $anchor): void
    {
        $this->anchor = $anchor;
    }

    public function getTag(): ?string
    {
        return $this->tag;
    }

    public function setTag(?string $tag): void
    {
        $this->tag = $tag;
    }

    /**
     * @return list<SequenceItem|CommentNode|BlankLineNode>
     */
    public function children(): array
    {
        return $this->children;
    }

    /**
     * @return list<SequenceItem>
     */
    public function items(): array
    {
        $items = [];
        foreach ($this->children as $child) {
            if ($child instanceof SequenceItem) {
                $items[] = $child;
            }
        }
        return $items;
    }

    public function item(int $index): ?SequenceItem
    {
        return $this->items()[$index] ?? null;
    }

    /**
     * Package-internal: append a child to the children list.
     * Public mutation API is below.
     */
    public function appendChildInternal(SequenceItem|CommentNode|BlankLineNode $child): void
    {
        $child->setParent($this);
        $this->children[] = $child;
    }

    /**
     * Replace the item at $index. Retains the existing item's trivia.
     * Throws OutOfRangeException if index < 0 or index >= count of
     * items.
     *
     * @param Node|scalar|null|Stringable $value
     */
    public function setItemAt(int $index, mixed $value): SequenceItem
    {
        $items = $this->items();
        if ($index < 0 || $index >= count($items)) {
            throw new \Horde\Yaml\Document\OutOfRangeException(sprintf(
                'Index %d out of range for sequence with %d items',
                $index,
                count($items),
            ));
        }
        $items[$index]->setValueInternal($this->coerceValue($value));
        return $items[$index];
    }

    /**
     * Insert a new item before the item currently at $index.
     * `$index === count` is allowed and means append.
     *
     * @param Node|scalar|null|Stringable $value
     */
    public function insertItemAt(int $index, mixed $value): SequenceItem
    {
        $itemCount = count($this->items());
        if ($index < 0 || $index > $itemCount) {
            throw new \Horde\Yaml\Document\OutOfRangeException(sprintf(
                'Index %d out of range for insert (item count %d)',
                $index,
                $itemCount,
            ));
        }
        $valueNode = $this->coerceValue($value);
        $item = new SequenceItem($valueNode);
        $item->setParent($this);

        // Find the children-list position corresponding to item index.
        $childPos = $this->itemIndexToChildIndex($index);
        array_splice($this->children, $childPos, 0, [$item]);
        return $item;
    }

    /**
     * @param Node|scalar|null|Stringable $value
     */
    public function appendItem(mixed $value): SequenceItem
    {
        $valueNode = $this->coerceValue($value);
        $item = new SequenceItem($valueNode);
        $this->appendChildInternal($item);
        return $item;
    }

    /**
     * @param Node|scalar|null|Stringable $value
     */
    public function prependItem(mixed $value): SequenceItem
    {
        return $this->insertItemAt(0, $value);
    }

    /**
     * Remove the item at $index (or the given SequenceItem instance).
     * Adjacent trivia is unaffected.
     */
    public function removeItem(int|SequenceItem $item): void
    {
        $target = is_int($item) ? $this->item($item) : $item;
        if ($target === null) {
            return;
        }
        foreach ($this->children as $i => $child) {
            if ($child === $target) {
                array_splice($this->children, $i, 1);
                $target->setParent(null);
                return;
            }
        }
    }

    /**
     * Map an item index (0-based count of SequenceItem children only)
     * to a children-list index. If item index equals item count,
     * returns the children-list length (i.e. append position).
     */
    private function itemIndexToChildIndex(int $itemIndex): int
    {
        $count = 0;
        foreach ($this->children as $i => $child) {
            if ($child instanceof SequenceItem) {
                if ($count === $itemIndex) {
                    return $i;
                }
                $count++;
            }
        }
        return count($this->children);
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
     * Append a standalone comment as the last child of this sequence.
     */
    public function appendComment(CommentNode|string $comment): CommentNode
    {
        $node = $this->coerceComment($comment);
        $this->appendChildInternal($node);
        return $node;
    }

    /**
     * Insert a comment before the given reference. Reference can be
     * an int (item index), a SequenceItem, a CommentNode, or a
     * BlankLineNode that's a child of this sequence.
     */
    public function insertCommentBefore(
        int|SequenceItem|CommentNode|BlankLineNode $reference,
        CommentNode|string $comment,
    ): CommentNode {
        $node = $this->coerceComment($comment);
        $idx = $this->indexOfReference($reference);
        $node->setParent($this);
        array_splice($this->children, $idx, 0, [$node]);
        return $node;
    }

    public function insertCommentAfter(
        int|SequenceItem|CommentNode|BlankLineNode $reference,
        CommentNode|string $comment,
    ): CommentNode {
        $node = $this->coerceComment($comment);
        $idx = $this->indexOfReference($reference);
        $node->setParent($this);
        array_splice($this->children, $idx + 1, 0, [$node]);
        return $node;
    }

    public function removeComment(CommentNode $comment): void
    {
        foreach ($this->children as $i => $child) {
            if ($child === $comment) {
                array_splice($this->children, $i, 1);
                $comment->setParent(null);
                return;
            }
        }
    }

    public function appendBlankLines(int $count = 1): BlankLineNode
    {
        $node = new BlankLineNode($count);
        $this->appendChildInternal($node);
        return $node;
    }

    public function insertBlankLinesBefore(
        int|SequenceItem|CommentNode|BlankLineNode $reference,
        int $count = 1,
    ): BlankLineNode {
        $node = new BlankLineNode($count);
        $idx = $this->indexOfReference($reference);
        $node->setParent($this);
        array_splice($this->children, $idx, 0, [$node]);
        return $node;
    }

    public function insertBlankLinesAfter(
        int|SequenceItem|CommentNode|BlankLineNode $reference,
        int $count = 1,
    ): BlankLineNode {
        $node = new BlankLineNode($count);
        $idx = $this->indexOfReference($reference);
        $node->setParent($this);
        array_splice($this->children, $idx + 1, 0, [$node]);
        return $node;
    }

    public function removeBlankLines(BlankLineNode $node): void
    {
        foreach ($this->children as $i => $child) {
            if ($child === $node) {
                array_splice($this->children, $i, 1);
                $node->setParent(null);
                return;
            }
        }
    }

    public function commentBefore(int|SequenceItem $item): ?CommentNode
    {
        $idx = $this->indexOfReference($item);
        if ($idx <= 0) {
            return null;
        }
        $prev = $this->children[$idx - 1];
        return $prev instanceof CommentNode ? $prev : null;
    }

    private function coerceComment(CommentNode|string $comment): CommentNode
    {
        if ($comment instanceof CommentNode) {
            return $comment;
        }
        if (!str_starts_with($comment, '#')) {
            throw new InvalidArgumentException(
                'Comment text must start with `#`; got: ' . $comment,
            );
        }
        return new CommentNode($comment);
    }

    /**
     * @param int|SequenceItem|CommentNode|BlankLineNode $reference
     */
    private function indexOfReference(
        int|SequenceItem|CommentNode|BlankLineNode $reference,
    ): int {
        if (is_int($reference)) {
            $itemIndex = -1;
            foreach ($this->children as $i => $child) {
                if ($child instanceof SequenceItem) {
                    $itemIndex++;
                    if ($itemIndex === $reference) {
                        return $i;
                    }
                }
            }
            throw new InvalidArgumentException("No item at index $reference");
        }
        foreach ($this->children as $i => $child) {
            if ($child === $reference) {
                return $i;
            }
        }
        throw new InvalidArgumentException('Reference is not a child of this sequence');
    }

    // ----- ArrayAccess (reads only; aliases resolved on read) -----

    public function offsetExists(mixed $offset): bool
    {
        if (!is_int($offset)) {
            return false;
        }
        return $this->item($offset) !== null;
    }

    public function offsetGet(mixed $offset): ?Node
    {
        if (!is_int($offset)) {
            return null;
        }
        $item = $this->item($offset);
        if ($item === null) {
            return null;
        }
        $value = $item->getValue();
        if ($value instanceof AliasNode) {
            return $value->target();
        }
        return $value;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \Horde\Yaml\Document\UnsupportedOperationException(
            'ArrayAccess writes are unsupported. Use setItemAt($index, $value), insertItemAt(), or appendItem().',
        );
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \Horde\Yaml\Document\UnsupportedOperationException(
            'ArrayAccess unset is unsupported. Use removeItem($index).',
        );
    }

    public function count(): int
    {
        return count($this->items());
    }

    public function getIterator(): Generator
    {
        foreach ($this->items() as $i => $item) {
            $value = $item->getValue();
            if ($value instanceof AliasNode) {
                $value = $value->target();
            }
            yield $i => $value;
        }
    }
}
