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
 * A YAML mapping (key-value collection).
 *
 * Children are an ordered list of MapEntry, CommentNode, and
 * BlankLineNode instances. Entries have unique keys. Style is block
 * or flow. Anchor and tag are optional.
 *
 * In C.02 scope: holds entries plus minimal style/structure for
 * round-trip. Public mutation API (setEntry/addEntry/insertX/etc.)
 * lands in chapter L.
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/03-ast-and-document-model-2026-06-11.md §2.2
 */
final class MapNode implements Node, ArrayAccess, Countable, IteratorAggregate
{
    use NodeTrait;

    /** @var list<MapEntry|CommentNode|BlankLineNode> */
    private array $children = [];

    private MapStyle $style;
    private ?string $anchor;
    private ?string $tag;
    private ?FlowFormat $flowFormat = null;

    public function __construct(
        MapStyle $style = MapStyle::Block,
        ?string $anchor = null,
        ?string $tag = null,
    ) {
        $this->style = $style;
        $this->anchor = $anchor;
        $this->tag = $tag;
    }

    public function getStyle(): MapStyle
    {
        return $this->style;
    }

    public function setStyle(MapStyle $style): void
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
     * @return list<MapEntry|CommentNode|BlankLineNode>
     */
    public function children(): array
    {
        return $this->children;
    }

    /**
     * @return list<MapEntry>
     */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->children as $child) {
            if ($child instanceof MapEntry) {
                $entries[] = $child;
            }
        }
        return $entries;
    }

    /**
     * Find an entry by key string.
     */
    public function entry(string $key): ?MapEntry
    {
        foreach ($this->children as $child) {
            if ($child instanceof MapEntry && $child->getKeyString() === $key) {
                return $child;
            }
        }
        return null;
    }

    /**
     * Return the merge-key entry (`<<: *anchor`) if present, null
     * otherwise. Per Stage 1 §2.4: merge keys are preserved as syntax
     * in the AST; the resolved() view expands them on demand.
     */
    public function mergeEntry(): ?MapEntry
    {
        return $this->entry('<<');
    }

    /**
     * Return a flat array view of the effective configuration, with
     * merge keys (`<<:`) expanded per YAML 1.2 merge semantics.
     *
     * Precedence rules:
     * - Explicit keys in this map override any merged keys.
     * - For multi-merge (`<<: [*a, *b]`), earlier aliases take
     *   precedence over later ones.
     * - The `<<` key itself does not appear in the resolved view.
     *
     * Each value is the typed PHP scalar for a ScalarNode, the
     * resolved view for nested MapNodes, the items array for
     * SequenceNodes (recursively resolved), or the AliasNode's
     * resolved target for AliasNode values.
     *
     * Read-only: mutation must go through the syntactic form
     * (entries() / setEntry / addEntry) and the resolved view
     * recomputes on the next call.
     *
     * @return array<int|string, mixed>
     */
    public function resolved(): array
    {
        return $this->resolvedWithGuard([]);
    }

    /**
     * Resolve with a visited set tracking which MapNodes are
     * currently being resolved as merge sources higher up the call
     * stack. A re-entry on a node already on the stack is a merge
     * cycle (`a: <<: *b`, `b: <<: *a`) and throws rather than
     * stack-overflowing.
     *
     * @param list<MapNode> $visiting
     * @return array<int|string, mixed>
     */
    private function resolvedWithGuard(array $visiting): array
    {
        foreach ($visiting as $seen) {
            if ($seen === $this) {
                throw new \Horde\Yaml\Document\StructuralException(
                    'Cyclic merge key reference detected',
                );
            }
        }
        $visiting[] = $this;

        $merged = [];

        // First pass: apply merges (lowest precedence).
        $mergeEntry = $this->mergeEntry();
        if ($mergeEntry !== null) {
            $mergeValue = $mergeEntry->getValue();
            $sources = $this->collectMergeSources($mergeValue);
            // Earlier aliases win over later aliases. Apply in reverse
            // so that earlier aliases overwrite later ones.
            foreach (array_reverse($sources) as $source) {
                foreach ($source->resolvedWithGuard($visiting) as $k => $v) {
                    $merged[$k] = $v;
                }
            }
        }

        // Second pass: explicit keys win over merged ones.
        foreach ($this->children as $child) {
            if (!$child instanceof MapEntry) {
                continue;
            }
            $key = $child->getKeyString();
            if ($key === '<<') {
                continue;
            }
            $merged[$key] = $this->resolveValue($child->getValue(), $visiting);
        }

        return $merged;
    }

    /**
     * Collect a flat list of MapNode sources from a merge value
     * (AliasNode, SequenceNode of aliases, or MapNode for inline).
     *
     * @return list<MapNode>
     */
    private function collectMergeSources(MapNode|SequenceNode|ScalarNode|AliasNode $value): array
    {
        if ($value instanceof MapNode) {
            return [$value];
        }
        if ($value instanceof AliasNode) {
            $target = $value->target();
            if ($target instanceof MapNode) {
                return [$target];
            }
            return [];
        }
        if ($value instanceof SequenceNode) {
            $out = [];
            foreach ($value->items() as $item) {
                $iv = $item->getValue();
                $out = array_merge($out, $this->collectMergeSources($iv));
            }
            return $out;
        }
        return [];
    }

    /**
     * Resolve a single value into a PHP-array-friendly form.
     *
     * @param list<MapNode> $visiting
     */
    private function resolveValue(MapNode|SequenceNode|ScalarNode|AliasNode $value, array $visiting = []): mixed
    {
        if ($value instanceof ScalarNode) {
            // A TagHandler may have placed a domain value on the node;
            // prefer that over the lexical value when present.
            if ($value->hasResolvedValue()) {
                return $value->getResolvedValue();
            }
            return $value->getValue();
        }
        if ($value instanceof MapNode) {
            return $value->resolvedWithGuard($visiting);
        }
        if ($value instanceof SequenceNode) {
            $out = [];
            foreach ($value->items() as $item) {
                $out[] = $this->resolveValue($item->getValue(), $visiting);
            }
            return $out;
        }
        if ($value instanceof AliasNode) {
            return $this->resolveValue($value->target(), $visiting);
        }
        return null;
    }

    /**
     * Package-internal: append a child to the children list.
     * Mutation API for users (setEntry, addEntry, etc.) is below.
     */
    public function appendChildInternal(MapEntry|CommentNode|BlankLineNode $child): void
    {
        $child->setParent($this);
        $this->children[] = $child;
    }

    /**
     * Replace the existing entry for $key with a new entry, retaining
     * the original entry's trivia (leading comments, EOL comment,
     * position in the children list). If the key doesn't exist, append
     * a new entry to the end of the children list.
     *
     * @param Node|scalar|null|Stringable $value
     */
    public function setEntry(string $key, mixed $value): MapEntry
    {
        $valueNode = $this->coerceValue($value);
        $existing = $this->entry($key);
        if ($existing !== null) {
            $existing->setValueInternal($valueNode);
            return $existing;
        }
        $entry = new MapEntry(new ScalarNode($key), $valueNode);
        $this->appendChildInternal($entry);
        return $entry;
    }

    /**
     * Append a new entry. Throws DuplicateKeyException if the key
     * already exists.
     *
     * @param Node|scalar|null|Stringable $value
     */
    public function addEntry(string $key, mixed $value): MapEntry
    {
        if ($this->entry($key) !== null) {
            throw new \Horde\Yaml\Document\DuplicateKeyException(
                "Key '$key' already exists in this map",
                $key,
            );
        }
        $valueNode = $this->coerceValue($value);
        $entry = new MapEntry(new ScalarNode($key), $valueNode);
        $this->appendChildInternal($entry);
        return $entry;
    }

    /**
     * Insert a new entry before the given reference.
     *
     * @param Node|scalar|null|Stringable $value
     */
    public function insertEntryBefore(
        string|MapEntry|CommentNode|BlankLineNode $reference,
        string $key,
        mixed $value,
    ): MapEntry {
        $valueNode = $this->coerceValue($value);
        $idx = $this->indexOfReference($reference);
        $entry = new MapEntry(new ScalarNode($key), $valueNode);
        $entry->setParent($this);
        array_splice($this->children, $idx, 0, [$entry]);
        return $entry;
    }

    /**
     * Insert a new entry after the given reference.
     *
     * @param Node|scalar|null|Stringable $value
     */
    public function insertEntryAfter(
        string|MapEntry|CommentNode|BlankLineNode $reference,
        string $key,
        mixed $value,
    ): MapEntry {
        $valueNode = $this->coerceValue($value);
        $idx = $this->indexOfReference($reference);
        $entry = new MapEntry(new ScalarNode($key), $valueNode);
        $entry->setParent($this);
        array_splice($this->children, $idx + 1, 0, [$entry]);
        return $entry;
    }

    /**
     * Remove the entry with the given key (or remove the given
     * MapEntry instance). Adjacent trivia (standalone comments,
     * blank-line nodes) is unaffected.
     */
    public function removeEntry(string|MapEntry $entry): void
    {
        $target = is_string($entry) ? $this->entry($entry) : $entry;
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
     * Coerce a user-supplied value to a value-position Node. Accepts
     * Node, scalar, null, or Stringable (per Stage 4 §4.1).
     *
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
                'Value must be a value-position node (MapNode, SequenceNode, ScalarNode, or AliasNode); got '
                    . $value::class,
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
     * Append a standalone comment as the last child of this map.
     *
     * Accepts either a CommentNode or a comment text string (which is
     * wrapped in a fresh CommentNode). The text must start with `#`.
     */
    public function appendComment(CommentNode|string $comment): CommentNode
    {
        $node = $this->coerceComment($comment);
        $this->appendChildInternal($node);
        return $node;
    }

    /**
     * Insert a comment before the given reference. Reference can be
     * a string (matched as a map-entry key), a MapEntry, a
     * CommentNode, or a BlankLineNode that's a child of this map.
     */
    public function insertCommentBefore(
        string|MapEntry|CommentNode|BlankLineNode $reference,
        CommentNode|string $comment,
    ): CommentNode {
        $node = $this->coerceComment($comment);
        $idx = $this->indexOfReference($reference);
        $node->setParent($this);
        array_splice($this->children, $idx, 0, [$node]);
        return $node;
    }

    /**
     * Insert a comment after the given reference. Reference semantics
     * are the same as insertCommentBefore.
     */
    public function insertCommentAfter(
        string|MapEntry|CommentNode|BlankLineNode $reference,
        CommentNode|string $comment,
    ): CommentNode {
        $node = $this->coerceComment($comment);
        $idx = $this->indexOfReference($reference);
        $node->setParent($this);
        array_splice($this->children, $idx + 1, 0, [$node]);
        return $node;
    }

    /**
     * Remove a CommentNode child from this map.
     */
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

    /**
     * Append a blank-line group as the last child of this map.
     */
    public function appendBlankLines(int $count = 1): BlankLineNode
    {
        $node = new BlankLineNode($count);
        $this->appendChildInternal($node);
        return $node;
    }

    public function insertBlankLinesBefore(
        string|MapEntry|CommentNode|BlankLineNode $reference,
        int $count = 1,
    ): BlankLineNode {
        $node = new BlankLineNode($count);
        $idx = $this->indexOfReference($reference);
        $node->setParent($this);
        array_splice($this->children, $idx, 0, [$node]);
        return $node;
    }

    public function insertBlankLinesAfter(
        string|MapEntry|CommentNode|BlankLineNode $reference,
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

    /**
     * Return the immediately-preceding standalone CommentNode of the
     * referenced entry, if one exists at children-list-position - 1.
     * Returns null otherwise. Convenience accessor; does not assert
     * ownership (per the comments-as-first-class commitment).
     */
    public function commentBefore(string|MapEntry $entry): ?CommentNode
    {
        $idx = $this->indexOfReference($entry);
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
     * @param string|MapEntry|CommentNode|BlankLineNode $reference
     */
    private function indexOfReference(
        string|MapEntry|CommentNode|BlankLineNode $reference,
    ): int {
        if (is_string($reference)) {
            foreach ($this->children as $i => $child) {
                if ($child instanceof MapEntry && $child->getKeyString() === $reference) {
                    return $i;
                }
            }
            throw new InvalidArgumentException("No entry with key '$reference'");
        }
        foreach ($this->children as $i => $child) {
            if ($child === $reference) {
                return $i;
            }
        }
        throw new InvalidArgumentException('Reference is not a child of this map');
    }

    // ----- ArrayAccess (reads only; merges and aliases resolved) -----

    public function offsetExists(mixed $offset): bool
    {
        $key = is_string($offset) ? $offset : (string) $offset;
        // Resolve through merge keys for membership checks.
        return array_key_exists($key, $this->resolved());
    }

    public function offsetGet(mixed $offset): ?Node
    {
        $key = is_string($offset) ? $offset : (string) $offset;
        // First try the explicit entry.
        $entry = $this->entry($key);
        if ($entry !== null) {
            $value = $entry->getValue();
            // Resolve aliases on read.
            if ($value instanceof AliasNode) {
                return $value->target();
            }
            return $value;
        }
        // Not explicit. Check merge expansion. Look at the merge
        // entry's value(s); for each merged map, check if it has this
        // key. Apply YAML 1.2 precedence: earlier merge wins.
        $mergeEntry = $this->mergeEntry();
        if ($mergeEntry === null) {
            return null;
        }
        $sources = $this->collectMergeSources($mergeEntry->getValue());
        foreach ($sources as $source) {
            $sourceEntry = $source->entry($key);
            if ($sourceEntry !== null) {
                $value = $sourceEntry->getValue();
                if ($value instanceof AliasNode) {
                    return $value->target();
                }
                return $value;
            }
            // Also check the source's own merge.
            $deeper = $source->offsetGet($key);
            if ($deeper !== null) {
                return $deeper;
            }
        }
        return null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \Horde\Yaml\Document\UnsupportedOperationException(
            'ArrayAccess writes are unsupported. Use setEntry($key, $value) or addEntry($key, $value).',
        );
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \Horde\Yaml\Document\UnsupportedOperationException(
            'ArrayAccess unset is unsupported. Use removeEntry($key).',
        );
    }

    // ----- Countable: counts entries (matches default foreach) -----

    public function count(): int
    {
        return count($this->entries());
    }

    // ----- IteratorAggregate: yields key => Node, entries only -----

    public function getIterator(): Generator
    {
        foreach ($this->entries() as $entry) {
            $value = $entry->getValue();
            if ($value instanceof AliasNode) {
                $value = $value->target();
            }
            yield $entry->getKeyString() => $value;
        }
    }
}
