<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Document;

use Horde\Yaml\Document\Node\AliasNode;
use Horde\Yaml\Document\Node\BlankLineNode;
use Horde\Yaml\Document\Node\CommentNode;
use Horde\Yaml\Document\Node\MapEntry;
use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\SequenceItem;
use Horde\Yaml\Document\Node\SequenceNode;
use InvalidArgumentException;
use OutOfRangeException;

/**
 * The root container of a parsed YAML file.
 *
 * Holds an ordered list of YamlDocument instances plus stream-level
 * metadata: directives, leading and trailing trivia, line ending,
 * trailing-newline flag.
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/03-ast-and-document-model-2026-06-11.md §1.1
 */
final class YamlStream
{
    /** @var list<YamlDocument> */
    private array $documents = [];

    /** @var list<Node\Directive> */
    private array $directives = [];

    /**
     * Comments and blank-line groups that appear before any directive,
     * `---` start-marker, or content node. Drained from the first
     * structural token's leadingTrivia by the parser. For a comment-only
     * file (no documents at all) this carries everything.
     *
     * @var list<CommentNode|BlankLineNode>
     */
    private array $leadingTrivia = [];

    /**
     * Comments and blank-line groups that appear after the last
     * document's content (and after its `...` end-marker, if any).
     * Drained from `StreamEnd.leadingTrivia` by the parser when the
     * stream has at least one document.
     *
     * @var list<CommentNode|BlankLineNode>
     */
    private array $trailingTrivia = [];

    private string $lineEnding = "\n";
    private bool $trailingNewline = false;

    /**
     * Return the document at $index (default 0). Throws
     * IndexOutOfBoundsException if out of range.
     */
    public function getDocument(int $index = 0): YamlDocument
    {
        if (!isset($this->documents[$index])) {
            throw new OutOfRangeException(sprintf(
                'No document at index %d (stream has %d documents)',
                $index,
                count($this->documents),
            ));
        }
        return $this->documents[$index];
    }

    /**
     * @return list<YamlDocument>
     */
    public function getDocuments(): array
    {
        return $this->documents;
    }

    public function documentCount(): int
    {
        return count($this->documents);
    }

    public function getLineEnding(): string
    {
        return $this->lineEnding;
    }

    public function setLineEnding(string $eol): void
    {
        $this->lineEnding = $eol;
    }

    public function getTrailingNewline(): bool
    {
        return $this->trailingNewline;
    }

    public function setTrailingNewline(bool $trailingNewline): void
    {
        $this->trailingNewline = $trailingNewline;
    }

    /**
     * @return list<Node\Directive>
     */
    public function getDirectives(): array
    {
        return $this->directives;
    }

    public function appendDirective(Node\Directive $directive): void
    {
        $this->directives[] = $directive;
    }

    /**
     * Comments and blank-line groups that precede any directive or
     * content in the stream. Empty unless the source had pre-stream
     * trivia (or unless this stream has no documents and is entirely
     * trivia).
     *
     * @return list<CommentNode|BlankLineNode>
     */
    public function getLeadingTrivia(): array
    {
        return $this->leadingTrivia;
    }

    /**
     * Comments and blank-line groups that follow the last document's
     * content. Empty unless the source had file-trailing trivia.
     *
     * @return list<CommentNode|BlankLineNode>
     */
    public function getTrailingTrivia(): array
    {
        return $this->trailingTrivia;
    }

    /**
     * Package-internal: append one trivia node to the stream's leading
     * list. Used by the parser to drain `StreamStart.leadingTrivia` and
     * (for a documentless stream) `StreamEnd.leadingTrivia`.
     */
    public function appendLeadingTriviaInternal(CommentNode|BlankLineNode $node): void
    {
        $this->leadingTrivia[] = $node;
    }

    /**
     * Package-internal: append one trivia node to the stream's trailing
     * list. Used by the parser to drain `StreamEnd.leadingTrivia` when
     * the stream has at least one document.
     */
    public function appendTrailingTriviaInternal(CommentNode|BlankLineNode $node): void
    {
        $this->trailingTrivia[] = $node;
    }

    /**
     * Append a deep-clone of $doc to the end of this stream's
     * documents list. Returns the cloned document.
     */
    public function appendDocument(YamlDocument $doc): YamlDocument
    {
        $clone = $this->cloneDocument($doc);
        $clone->setStartMarker(true);
        $clone->setParentStream($this);
        $this->documents[] = $clone;
        return $clone;
    }

    /**
     * Prepend a deep-clone of $doc to the beginning of this stream's
     * documents list.
     */
    public function prependDocument(YamlDocument $doc): YamlDocument
    {
        $clone = $this->cloneDocument($doc);
        $clone->setParentStream($this);
        array_unshift($this->documents, $clone);
        return $clone;
    }

    /**
     * Insert a deep-clone of $doc at the given index.
     */
    public function insertDocumentAt(int $index, YamlDocument $doc): YamlDocument
    {
        if ($index < 0 || $index > count($this->documents)) {
            throw new OutOfRangeException(sprintf(
                'Index %d out of range for documentCount %d',
                $index,
                count($this->documents),
            ));
        }
        $clone = $this->cloneDocument($doc);
        if ($index > 0) {
            $clone->setStartMarker(true);
        }
        $clone->setParentStream($this);
        array_splice($this->documents, $index, 0, [$clone]);
        return $clone;
    }

    public function insertDocumentBefore(YamlDocument $reference, YamlDocument $doc): YamlDocument
    {
        $idx = $this->indexOf($reference);
        return $this->insertDocumentAt($idx, $doc);
    }

    public function insertDocumentAfter(YamlDocument $reference, YamlDocument $doc): YamlDocument
    {
        $idx = $this->indexOf($reference);
        return $this->insertDocumentAt($idx + 1, $doc);
    }

    public function removeDocument(int $index): void
    {
        if (!isset($this->documents[$index])) {
            throw new OutOfRangeException(sprintf(
                'No document at index %d',
                $index,
            ));
        }
        $this->documents[$index]->setParentStream(null);
        array_splice($this->documents, $index, 1);
    }

    /**
     * Package-internal: append a fully-constructed document to this
     * stream without cloning. Used by the parser.
     */
    public function appendInternalDocument(YamlDocument $doc): void
    {
        $this->documents[] = $doc;
    }

    private function indexOf(YamlDocument $doc): int
    {
        foreach ($this->documents as $i => $existing) {
            if ($existing === $doc) {
                return $i;
            }
        }
        throw new InvalidArgumentException('Reference document is not in this stream');
    }

    /**
     * Deep-clone a document including its root subtree. Anchors are
     * preserved per-clone (each document has its own anchor index
     * per Stage 3 §5).
     */
    private function cloneDocument(YamlDocument $doc): YamlDocument
    {
        $copy = new YamlDocument();
        $copy->setStartMarker($doc->getStartMarker());
        $copy->setEndMarker($doc->getEndMarker());
        $root = $doc->root();
        if ($root !== null) {
            $clonedRoot = self::cloneValueNode($root, $copy->anchors());
            $copy->setRootInternal($clonedRoot);
        }
        return $copy;
    }

    /**
     * Deep-clone a value-position node, registering anchors with the
     * given index.
     */
    public static function cloneValueNode(
        MapNode|SequenceNode|ScalarNode|AliasNode $node,
        AnchorIndex $newIndex,
    ): MapNode|SequenceNode|ScalarNode|AliasNode {
        if ($node instanceof ScalarNode) {
            $copy = new ScalarNode(
                value: $node->getValue(),
                style: $node->getStyle(),
                rawSource: $node->getRawSource(),
                chomp: $node->getChomp(),
                indentIndicator: $node->getIndentIndicator(),
                anchor: $node->getAnchor(),
                tag: $node->getTag(),
            );
            if ($copy->getAnchor() !== null) {
                $newIndex->register($copy->getAnchor(), $copy);
            }
            return $copy;
        }
        if ($node instanceof MapNode) {
            $copy = new MapNode(
                style: $node->getStyle(),
                anchor: $node->getAnchor(),
                tag: $node->getTag(),
            );
            $copy->setFlowFormat($node->getFlowFormat());
            if ($copy->getAnchor() !== null) {
                $newIndex->register($copy->getAnchor(), $copy);
            }
            foreach ($node->children() as $child) {
                if ($child instanceof MapEntry) {
                    $clonedKey = self::cloneValueNode($child->getKey(), $newIndex);
                    $clonedValue = self::cloneValueNode($child->getValue(), $newIndex);
                    $entry = new MapEntry($clonedKey, $clonedValue);
                    $eol = $child->getEolComment();
                    if ($eol !== null) {
                        $entry->setEolComment(new CommentNode($eol->getText()));
                    }
                    $copy->appendChildInternal($entry);
                } elseif ($child instanceof CommentNode) {
                    $copy->appendChildInternal(new CommentNode($child->getText()));
                } elseif ($child instanceof BlankLineNode) {
                    $copy->appendChildInternal(new BlankLineNode($child->getCount()));
                }
            }
            return $copy;
        }
        if ($node instanceof SequenceNode) {
            $copy = new SequenceNode(
                style: $node->getStyle(),
                anchor: $node->getAnchor(),
                tag: $node->getTag(),
            );
            $copy->setFlowFormat($node->getFlowFormat());
            if ($copy->getAnchor() !== null) {
                $newIndex->register($copy->getAnchor(), $copy);
            }
            foreach ($node->children() as $child) {
                if ($child instanceof SequenceItem) {
                    $clonedValue = self::cloneValueNode($child->getValue(), $newIndex);
                    $item = new SequenceItem($clonedValue);
                    $eol = $child->getEolComment();
                    if ($eol !== null) {
                        $item->setEolComment(new CommentNode($eol->getText()));
                    }
                    $copy->appendChildInternal($item);
                } elseif ($child instanceof CommentNode) {
                    $copy->appendChildInternal(new CommentNode($child->getText()));
                } elseif ($child instanceof BlankLineNode) {
                    $copy->appendChildInternal(new BlankLineNode($child->getCount()));
                }
            }
            return $copy;
        }
        // AliasNode: bind to the new index.
        return new AliasNode($node->getTargetName(), $newIndex);
    }
}
