<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Document;

use Horde\Yaml\Document\Node\BlankLineNode;
use Horde\Yaml\Document\Node\CommentNode;
use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\SequenceNode;
use ArrayAccess;
use Countable;
use Generator;
use IteratorAggregate;

/**
 * One YAML document inside a stream.
 *
 * Holds the document's root node (map, sequence, or scalar), markers
 * (`---`, `...`), leading and trailing trivia, and the per-document
 * anchor index.
 *
 * In this phase: minimal structure sufficient for empty-input loading
 * and the Node interface's document() walk-up. Root manipulation,
 * trivia handling, and the anchor index get filled in by later phases.
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/03-ast-and-document-model-2026-06-11.md §1.2
 */
final class YamlDocument implements ArrayAccess, Countable, IteratorAggregate
{
    private ?YamlStream $parent = null;
    private MapNode|SequenceNode|ScalarNode|null $root = null;
    private bool $startMarker = false;
    private bool $endMarker = false;
    private AnchorIndex $anchorIndex;
    /** @var array<string, string> Handle prefix to URI prefix from %TAG directives */
    private array $tagHandles = [];

    /**
     * Comments and blank-line groups that follow this document's
     * content (and its `...` end-marker, if any) but precede the next
     * document's start-marker. Drained from the next DocumentStart's
     * leadingTrivia by the parser. Empty for the last document of a
     * stream (those go onto the stream's trailing trivia instead).
     *
     * @var list<CommentNode|BlankLineNode>
     */
    private array $trailingTrivia = [];

    public function __construct()
    {
        $this->anchorIndex = new AnchorIndex();
    }

    /**
     * Per-document `%TAG` handle map. Maps a handle (`!`, `!!`, `!foo!`)
     * to its URI prefix. Used by the resolver to expand shorthand tags.
     *
     * @return array<string, string>
     */
    public function getTagHandles(): array
    {
        return $this->tagHandles;
    }

    /**
     * Package-internal: register a handle from a `%TAG` directive
     * preceding this document.
     */
    public function setTagHandle(string $handle, string $prefix): void
    {
        $this->tagHandles[$handle] = $prefix;
    }

    public function parent(): ?YamlStream
    {
        return $this->parent;
    }

    public function getStartMarker(): bool
    {
        return $this->startMarker;
    }

    public function setStartMarker(bool $present): void
    {
        $this->startMarker = $present;
    }

    public function getEndMarker(): bool
    {
        return $this->endMarker;
    }

    public function setEndMarker(bool $present): void
    {
        $this->endMarker = $present;
    }

    /**
     * Comments and blank-line groups that follow this document's
     * content. Drained from the NEXT structural token's leadingTrivia
     * when the parser closes this document. Empty for the last doc
     * (those go onto the stream's trailing trivia).
     *
     * @return list<CommentNode|BlankLineNode>
     */
    public function getTrailingTrivia(): array
    {
        return $this->trailingTrivia;
    }

    /**
     * Package-internal: append one trivia node to this document's
     * trailing list.
     */
    public function appendTrailingTriviaInternal(CommentNode|BlankLineNode $node): void
    {
        $this->trailingTrivia[] = $node;
    }

    public function root(): MapNode|SequenceNode|ScalarNode|null
    {
        return $this->root;
    }

    public function anchors(): AnchorIndex
    {
        return $this->anchorIndex;
    }

    /**
     * Convenience: return the MapEntry for $key on the root map, or
     * null if the root is not a map or the key doesn't exist.
     */
    public function getEntry(string $key): ?Node\MapEntry
    {
        if (!$this->root instanceof MapNode) {
            return null;
        }
        return $this->root->entry($key);
    }

    /**
     * Strict variant: throws KeyNotFoundException if the key is not
     * found, including when the root is not a map.
     */
    public function requireEntry(string $key): Node\MapEntry
    {
        $entry = $this->getEntry($key);
        if ($entry === null) {
            throw new KeyNotFoundException(
                "Key '$key' not found in document root",
                $key,
            );
        }
        return $entry;
    }

    /**
     * Convenience: descend through the root container along the
     * given path and return the final Node, or null at any miss.
     * String keys traverse maps; integer keys traverse sequences.
     *
     * Example:
     *   $doc->getNode('servers', 'mail', 'host')
     */
    public function getNode(int|string ...$path): ?Node\Node
    {
        $cur = $this->root;
        foreach ($path as $segment) {
            if ($cur instanceof MapNode && is_string($segment)) {
                $entry = $cur->entry($segment);
                if ($entry === null) {
                    return null;
                }
                $cur = $entry->getValue();
                continue;
            }
            if ($cur instanceof SequenceNode && is_int($segment)) {
                $item = $cur->item($segment);
                if ($item === null) {
                    return null;
                }
                $cur = $item->getValue();
                continue;
            }
            return null;
        }
        return $cur;
    }

    /**
     * Convenience: descend along the path and return the typed
     * scalar value (or null at any miss). Path can be passed as
     * variadic args, an array, or a dotted string.
     */
    public function valueAt(string|array $path, int|string ...$rest): string|int|float|bool|null
    {
        if (is_string($path)) {
            // Dotted string: split and use as path.
            if (str_contains($path, '.')) {
                $segments = explode('.', $path);
            } else {
                $segments = [$path];
            }
        } else {
            $segments = $path;
        }
        // Append any variadic rest.
        foreach ($rest as $r) {
            $segments[] = $r;
        }

        $node = $this->getNode(...$segments);
        if ($node instanceof ScalarNode) {
            return $node->getValue();
        }
        return null;
    }

    /**
     * Create a fresh YamlStream containing a deep clone of this
     * document. The clone is independent of the original; mutations
     * on either side do not affect the other. Anchor names are
     * registered with the new document's index. Stream-level
     * metadata (directives, leading file trivia, sibling documents)
     * is not copied per Stage 4 §4.8.
     */
    public function cloneDetached(): YamlDocument
    {
        $newStream = new YamlStream();
        $newStream->setLineEnding($this->parent?->getLineEnding() ?? "\n");
        $newStream->setTrailingNewline($this->parent?->getTrailingNewline() ?? false);
        return $newStream->appendDocument($this);
    }

    /**
     * Package-internal: assign the parent stream. Called by the parser
     * and by the stream's internal document-append helpers.
     */
    public function setParentStream(?YamlStream $parent): void
    {
        $this->parent = $parent;
    }

    /**
     * Package-internal: set the document's root node.
     */
    public function setRootInternal(MapNode|SequenceNode|ScalarNode|null $root): void
    {
        $this->root = $root;
    }

    // ----- ArrayAccess / Countable / IteratorAggregate (forwards to root) -----

    public function offsetExists(mixed $offset): bool
    {
        if (!$this->root instanceof ArrayAccess) {
            return false;
        }
        return $this->root->offsetExists($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        if ($this->root instanceof ScalarNode) {
            throw new InvalidAccessException(
                'Cannot subscript a scalar-rooted document; use $doc->root()->getValue().',
            );
        }
        if (!$this->root instanceof ArrayAccess) {
            return null;
        }
        return $this->root->offsetGet($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new UnsupportedOperationException(
            'ArrayAccess writes are unsupported on YamlDocument.',
        );
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new UnsupportedOperationException(
            'ArrayAccess unset is unsupported on YamlDocument.',
        );
    }

    public function count(): int
    {
        if ($this->root instanceof Countable) {
            return $this->root->count();
        }
        return 0;
    }

    public function getIterator(): Generator
    {
        if ($this->root instanceof IteratorAggregate) {
            yield from $this->root->getIterator();
        }
    }
}
