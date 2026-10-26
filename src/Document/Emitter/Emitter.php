<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Document\Emitter;

use Horde\Yaml\Document\EmitException;
use Horde\Yaml\Document\Node\AliasNode;
use Horde\Yaml\Document\Node\BlankLineNode;
use Horde\Yaml\Document\Node\ChompMode;
use Horde\Yaml\Document\Node\CommentNode;
use Horde\Yaml\Document\Node\MapEntry;
use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\Node\MapStyle;
use Horde\Yaml\Document\Node\Node;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\ScalarStyle;
use Horde\Yaml\Document\Node\SequenceItem;
use Horde\Yaml\Document\Node\SequenceNode;
use Horde\Yaml\Document\Node\SequenceStyle;
use Horde\Yaml\Document\YamlDocument;
use Horde\Yaml\Document\YamlStream;

/**
 * AST to string emitter.
 *
 * Buffered output (whole document into memory, returned as a string).
 * Recursive descent: each node type has an emit method. Children
 * recursed into in source order.
 *
 * Phase C.03 scope: scalar-rooted documents and flat block mappings
 * with plain-scalar values. Nested mappings, sequences, anchors,
 * aliases, tags, and trivia positioning come in later phases.
 *
 */
final class Emitter
{
    private string $buffer = '';
    private string $lineEnding = "\n";

    public function emit(YamlStream $stream): string
    {
        $this->buffer = '';
        $this->lineEnding = $stream->getLineEnding();

        // Stream-leading trivia: comments and blank lines that appear
        // before any directive or document content. For a comment-only
        // file (no documents) this is the entire payload.
        $this->emitTrivia($stream->getLeadingTrivia(), indent: 0);

        // Emit directives at the top of the stream.
        foreach ($stream->getDirectives() as $directive) {
            $this->buffer .= '%' . $directive->getName();
            $params = $directive->getParameters();
            if ($params !== '') {
                $this->buffer .= ' ' . $params;
            }
            $this->buffer .= $this->lineEnding;
        }

        $docs = $stream->getDocuments();
        $docCount = count($docs);
        foreach ($docs as $i => $doc) {
            if ($i > 0 && !$doc->getStartMarker()) {
                $this->buffer .= '---' . $this->lineEnding;
            }
            $this->emitDocument(
                $doc,
                isLast: $i === $docCount - 1,
                streamHasTrailing: count($stream->getTrailingTrivia()) > 0
                    || $stream->getTrailingNewline(),
            );
        }

        // Stream-trailing trivia: comments and blank lines that follow
        // the last document's content.
        $this->emitTrivia($stream->getTrailingTrivia(), indent: 0);

        if ($stream->getTrailingNewline() && !str_ends_with($this->buffer, $this->lineEnding)) {
            $this->buffer .= $this->lineEnding;
        }

        return $this->buffer;
    }

    private function emitDocument(
        YamlDocument $doc,
        bool $isLast = true,
        bool $streamHasTrailing = false,
    ): void {
        if ($doc->getStartMarker()) {
            $this->buffer .= '---' . $this->lineEnding;
        }

        $root = $doc->root();
        if ($root !== null) {
            $this->emitNode($root, indent: 0);
        }

        // F3: a flow-style root emits bracket-to-bracket and ends WITHOUT
        // a newline. A block-style root naturally ends with one. If a
        // terminator follows (end-marker, doc-trailing trivia, next doc,
        // stream-trailing trivia, or stream-final newline), normalize so
        // it sits on its own line. When NO terminator is coming, leave
        // the buffer alone. The source had no trailing newline either.
        $terminatorComing = $doc->getEndMarker()
            || count($doc->getTrailingTrivia()) > 0
            || !$isLast
            || $streamHasTrailing;
        if ($terminatorComing && !str_ends_with($this->buffer, $this->lineEnding)) {
            $this->buffer .= $this->lineEnding;
        }

        if ($doc->getEndMarker()) {
            $this->buffer .= '...' . $this->lineEnding;
        }

        // Document-trailing trivia: comments and blank lines that
        // appeared after this doc's content but before the next
        // doc-marker (or directive section).
        $this->emitTrivia($doc->getTrailingTrivia(), indent: 0);
    }

    /**
     * Emit a list of trivia nodes (comments and blank-line groups) at
     * the given indent. Used for stream-leading, stream-trailing, and
     * document-trailing positions where trivia is not nested in a
     * MapNode or SequenceNode children list.
     *
     * @param list<CommentNode|BlankLineNode> $trivia
     */
    private function emitTrivia(array $trivia, int $indent): void
    {
        foreach ($trivia as $node) {
            if ($node instanceof CommentNode) {
                $this->emitStandaloneComment($node, $indent);
                continue;
            }
            if ($node instanceof BlankLineNode) {
                $this->emitBlankLines($node);
            }
        }
    }

    private function emitNode(Node $node, int $indent): void
    {
        if ($node instanceof ScalarNode) {
            $this->emitScalar($node);
            return;
        }
        if ($node instanceof MapNode || $node instanceof SequenceNode) {
            // A root collection's tag and anchor go before it: on the
            // same line when it is inline, on their own line when it is
            // a block. They were dropped.
            $properties = $this->renderValueProperties($node);
            if ($properties !== '') {
                $this->buffer .= $this->isInline($node)
                    ? $properties
                    : rtrim($properties) . $this->lineEnding;
            }
            if ($node instanceof MapNode) {
                $this->emitMapNode($node, $indent);
            } else {
                $this->emitSequenceNode($node, $indent);
            }
            return;
        }
        if ($node instanceof AliasNode) {
            // Top-level alias (rare). Emit `*name`.
            $this->buffer .= '*' . $node->getTargetName() . $this->lineEnding;
            return;
        }
    }

    private function emitScalar(ScalarNode $node): void
    {
        $properties = $this->renderValueProperties($node);
        if ($this->isBlockStyleScalar($node)) {
            $raw = $this->rawSource($node);
            if ($raw !== null) {
                $this->buffer .= $properties . $raw;
            } else {
                if ($properties !== '') {
                    $this->buffer .= rtrim($properties) . $this->lineEnding;
                }
                $value = (string) $node->getValue();
                $lines = explode("\n", $value);
                if ($lines !== [] && end($lines) === '') {
                    array_pop($lines);
                }
                $indicator = $this->renderBlockIndicator($node);
                if ($properties === '') {
                    $this->buffer .= $indicator . $this->lineEnding;
                }
                foreach ($lines as $line) {
                    $this->buffer .= '  ' . $line . $this->lineEnding;
                }
            }
            if (!str_ends_with($this->buffer, $this->lineEnding)) {
                $this->buffer .= $this->lineEnding;
            }
            return;
        }
        // A Plain-styled synthesized scalar carrying a real newline
        // would otherwise fall through to double-quoted and emit `\n`
        // escapes, silently changing the byte sequence a reader gets
        // back. Upgrade to a literal block so newlines round-trip.
        if ($this->shouldUpgradePlainToLiteralBlock($node)) {
            if ($properties !== '') {
                $this->buffer .= rtrim($properties) . $this->lineEnding;
            }
            $this->emitPlainAsLiteralBlock($node, 0, atLineStart: true);
            return;
        }
        $this->buffer .= $properties . $this->scalarOrRaw($node);
        $this->buffer .= $this->lineEnding;
    }

    private function emitMapNode(MapNode $map, int $indent): void
    {
        if ($this->isInline($map)) {
            $this->emitFlowMap($map);
            return;
        }

        foreach ($map->children() as $child) {
            if ($child instanceof MapEntry) {
                $this->emitMapEntry($child, $indent);
                continue;
            }
            if ($child instanceof CommentNode) {
                $this->emitStandaloneComment($child, $indent);
                continue;
            }
            if ($child instanceof BlankLineNode) {
                $this->emitBlankLines($child);
                continue;
            }
        }
    }

    private function emitFlowMap(MapNode $map): void
    {
        $ff = $map->getFlowFormat();
        if ($ff !== null && !$ff->singleLine) {
            $this->buffer .= $ff->rawText;
            return;
        }
        $parts = [];
        foreach ($map->entries() as $entry) {
            $parts[] = $this->keyToString($entry->getKey()) . $this->colonGap($entry) . ': '
                . $this->renderFlowValue($entry->getValue());
        }
        $this->buffer .= '{' . implode(', ', $parts) . '}';
    }

    private function emitFlowSequence(SequenceNode $seq): void
    {
        $ff = $seq->getFlowFormat();
        if ($ff !== null && !$ff->singleLine) {
            $this->buffer .= $ff->rawText;
            return;
        }
        $parts = [];
        foreach ($seq->items() as $item) {
            $parts[] = $this->renderFlowValue($item->getValue());
        }
        $this->buffer .= '[' . implode(', ', $parts) . ']';
    }

    /**
     * A value inside a flow collection, with its tag and anchor. They
     * were dropped, so `[!!str 1]` came back as `[1]` and `[&a x, *a]`
     * as `[x, *a]`, an alias with nothing to refer to.
     */
    private function renderFlowValue(MapNode|SequenceNode|ScalarNode|AliasNode|null $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($value instanceof AliasNode) {
            return '*' . $value->getTargetName();
        }
        $properties = $this->renderValueProperties($value);
        if ($value instanceof ScalarNode) {
            return $properties . $this->scalarOrRaw($value);
        }
        return $properties . $this->renderFlowInline($value);
    }

    private function renderFlowInline(MapNode|SequenceNode|ScalarNode|AliasNode $node): string
    {
        $saved = $this->buffer;
        $this->buffer = '';
        if ($node instanceof MapNode) {
            $this->emitFlowMap($node);
        } elseif ($node instanceof SequenceNode) {
            $this->emitFlowSequence($node);
        } elseif ($node instanceof ScalarNode) {
            $this->buffer .= $this->scalarOrRaw($node);
        } elseif ($node instanceof AliasNode) {
            $this->buffer .= '*' . $node->getTargetName();
        }
        $rendered = $this->buffer;
        $this->buffer = $saved;
        return $rendered;
    }

    private function emitMapEntry(MapEntry $entry, int $indent): void
    {
        if ($entry->isExplicitKey() || $this->keyNeedsExplicitForm($entry->getKey())) {
            $this->emitExplicitEntry($entry, $indent);
            return;
        }

        $this->buffer .= str_repeat(' ', $indent);

        // Key.
        $this->buffer .= $this->keyToString($entry->getKey());
        $this->buffer .= $this->colonGap($entry) . ':';

        $value = $entry->getValue();

        // Properties (tag, anchor) on the value go before the value
        // bytes (tag before anchor).
        $properties = $this->renderValueProperties($value);

        if ($value instanceof ScalarNode) {
            // Block scalars span multiple lines and end with their own
            // newline.
            if ($this->isBlockStyleScalar($value)) {
                $rawSource = $this->rawSource($value);
                if ($rawSource !== null) {
                    $this->buffer .= ' ' . $properties . $rawSource;
                } else {
                    if ($properties !== '') {
                        $this->buffer .= ' ' . rtrim($properties);
                    }
                    $this->emitBlockScalarSynthesized($value, $indent);
                }
                if (!str_ends_with($this->buffer, $this->lineEnding)) {
                    $this->buffer .= $this->lineEnding;
                }
                return;
            }
            // Plain-styled synthesized value carrying real newlines:
            // upgrade to literal block so a reader gets the same bytes
            // back instead of an escaped `\n` sequence. See
            // shouldUpgradePlainToLiteralBlock for the gating.
            if ($this->shouldUpgradePlainToLiteralBlock($value)) {
                if ($properties !== '') {
                    $this->buffer .= ' ' . rtrim($properties);
                }
                $this->emitPlainAsLiteralBlock($value, $indent, atLineStart: false);
                if (!str_ends_with($this->buffer, $this->lineEnding)) {
                    $this->buffer .= $this->lineEnding;
                }
                return;
            }
            $this->buffer .= $this->inlineValue($properties, $value);
        } elseif ($value instanceof AliasNode) {
            // Alias as entry value.
            $this->buffer .= ' ' . $properties . '*' . $value->getTargetName();
        } elseif ($value instanceof MapNode) {
            // Flow-style or empty mapping value: emit inline on the
            // same line. An empty block mapping has no block form.
            if ($this->isInline($value)) {
                $this->buffer .= ' ' . $properties;
                $this->emitFlowMap($value);
                $eol = $entry->getEolComment();
                if ($eol !== null) {
                    $this->buffer .= ($eol->getGap() !== "" ? $eol->getGap() : '  ') . $eol->getText();
                }
                $this->buffer .= $this->lineEnding;
                return;
            }
            // Nested block mapping.
            $childIndent = $this->detectChildIndentForMap($value, $indent);

            if ($properties !== '') {
                $this->buffer .= ' ' . rtrim($properties);
            }

            $eol = $entry->getEolComment();
            if ($eol !== null) {
                $this->buffer .= ($eol->getGap() !== "" ? $eol->getGap() : '  ') . $eol->getText();
            }

            $this->buffer .= $this->lineEnding;
            $this->emitMapNode($value, $childIndent);
            return;
        } elseif ($value instanceof SequenceNode) {
            // Flow-style or empty sequence value: inline.
            if ($this->isInline($value)) {
                $this->buffer .= ' ' . $properties;
                $this->emitFlowSequence($value);
                $eol = $entry->getEolComment();
                if ($eol !== null) {
                    $this->buffer .= ($eol->getGap() !== "" ? $eol->getGap() : '  ') . $eol->getText();
                }
                $this->buffer .= $this->lineEnding;
                return;
            }
            $childIndent = $this->detectChildIndentForSequence($value, $indent);

            if ($properties !== '') {
                $this->buffer .= ' ' . rtrim($properties);
            }

            $eol = $entry->getEolComment();
            if ($eol !== null) {
                $this->buffer .= ($eol->getGap() !== "" ? $eol->getGap() : '  ') . $eol->getText();
            }

            $this->buffer .= $this->lineEnding;
            $this->emitSequenceNode($value, $childIndent);
            return;
        } else {
            throw new EmitException(
                'Unsupported entry value type ' . $value::class . ' in H.06',
                $value,
            );
        }

        // EOL comment, if any.
        $eol = $entry->getEolComment();
        if ($eol !== null) {
            $this->buffer .= ($eol->getGap() !== "" ? $eol->getGap() : '  ') . $eol->getText();
        }

        $this->buffer .= $this->lineEnding;
    }

    /**
     * Emit `? key` and `: value` (YAML 1.2 §8.2.2). The emitter had no
     * explicit form: it wrote every key as an implicit one, so a block
     * scalar or block collection key, or one spanning lines, came back
     * as a document that does not load, and a compound key was folded
     * onto one line.
     */
    private function emitExplicitEntry(MapEntry $entry, int $indent): void
    {
        $this->emitIndicatorNode('?', $entry->getKey(), $entry->getKeyEolComment(), $indent, $entry->line());

        $value = $entry->getValue();
        $eol = $entry->getEolComment();
        // `? key` alone has a null value. Kept that way only when the
        // source had no `:` line and the value is still an empty node.
        if (!$entry->hasExplicitColon()
            && $eol === null
            && $value instanceof ScalarNode
            && $value->getValue() === null
            && $this->scalarOrRaw($value) === ''
            && $this->renderValueProperties($value) === ''
        ) {
            return;
        }
        $this->emitIndicatorNode(':', $value, $eol, $indent, $entry->valueIndicatorLine());
    }

    /**
     * Whether a key cannot be written implicitly, before `:` on one
     * line. YAML 1.2 §7.4.1 limits an implicit key to a single line of
     * at most 1024 characters, so a block scalar, a block collection,
     * and anything that spans lines need the explicit form.
     */
    private function keyNeedsExplicitForm(MapNode|SequenceNode|ScalarNode|AliasNode $key): bool
    {
        if ($key instanceof AliasNode) {
            return false;
        }
        if ($key instanceof ScalarNode) {
            if ($this->isBlockStyleScalar($key) || $this->shouldUpgradePlainToLiteralBlock($key)) {
                return true;
            }
        } elseif (!$this->isInline($key)) {
            return true;
        }
        $rendered = $this->keyToString($key);
        return str_contains($rendered, "\n") || strlen($rendered) > 1024;
    }

    /**
     * Render the property prefix (tag, anchor) for a value, in
     * Returns "" for nodes without properties,
     * otherwise a string ending in a single space (so the caller
     * can concatenate the value bytes directly).
     */
    private function renderValueProperties(Node $node): string
    {
        $tag = null;
        $anchor = null;
        if ($node instanceof ScalarNode || $node instanceof MapNode || $node instanceof SequenceNode) {
            $tag = $node->getTag();
            $anchor = $node->getAnchor();
        }
        $parts = [];
        if ($tag !== null) {
            $parts[] = $tag;
        }
        if ($anchor !== null) {
            $parts[] = '&' . $anchor;
        }
        return $parts === [] ? '' : implode(' ', $parts) . ' ';
    }

    /**
     * Determine the indent step for a nested MapNode relative to its
     * parent indent.
     */
    private function detectChildIndentForMap(MapNode $nested, int $parentIndent): int
    {
        foreach ($nested->entries() as $child) {
            if ($child->column() > 0) {
                return $child->column() - 1;
            }
        }
        return $parentIndent + 2;
    }

    /**
     * Determine the indent step for a nested SequenceNode relative to
     * its parent indent. The detected indent is where the `-`
     * indicator sits.
     */
    private function detectChildIndentForSequence(SequenceNode $nested, int $parentIndent): int
    {
        foreach ($nested->items() as $item) {
            if ($item->column() > 0) {
                return $item->column() - 1;
            }
        }
        return $parentIndent + 2;
    }

    private function emitSequenceNode(SequenceNode $seq, int $indent): void
    {
        if ($this->isInline($seq)) {
            $this->emitFlowSequence($seq);
            return;
        }

        foreach ($seq->children() as $child) {
            if ($child instanceof SequenceItem) {
                $this->emitSequenceItem($child, $indent);
                continue;
            }
            if ($child instanceof CommentNode) {
                $this->emitStandaloneComment($child, $indent);
                continue;
            }
            if ($child instanceof BlankLineNode) {
                $this->emitBlankLines($child);
                continue;
            }
        }
    }

    private function emitSequenceItem(SequenceItem $item, int $indent): void
    {
        $this->emitIndicatorNode('-', $item->getValue(), $item->getEolComment(), $indent, $item->line());
    }

    /**
     * Emit a block indicator at $indent and the node it introduces: `-`
     * for a sequence item, and `?` and `:` for the key and value of an
     * explicit mapping entry. The three take a node the same way.
     *
     * $line is the indicator's source line, 0 when unknown. A block
     * sequence that started on that line is kept compact (`- - x`).
     */
    private function emitIndicatorNode(
        string $indicator,
        MapNode|SequenceNode|ScalarNode|AliasNode|null $value,
        ?CommentNode $eol,
        int $indent,
        int $line = 0,
    ): void {
        $this->buffer .= str_repeat(' ', $indent);
        $this->buffer .= $indicator;

        if ($value === null) {
            // Null item value. Emit empty.
            $this->emitEolComment($eol);
            $this->buffer .= $this->lineEnding;
            return;
        }

        $properties = $this->renderValueProperties($value);

        if ($value instanceof ScalarNode) {
            // Block scalars carry their own line breaks, exactly as in
            // emitMapEntry; appending another left a blank line after
            // every block scalar in a sequence.
            if ($this->isBlockStyleScalar($value)) {
                $rawSource = $this->rawSource($value);
                if ($rawSource !== null) {
                    $this->buffer .= ' ' . $properties . $rawSource;
                } else {
                    if ($properties !== '') {
                        $this->buffer .= ' ' . rtrim($properties);
                    }
                    $this->emitBlockScalarSynthesized($value, $indent);
                }
                if (!str_ends_with($this->buffer, $this->lineEnding)) {
                    $this->buffer .= $this->lineEnding;
                }
                return;
            }
            if ($this->shouldUpgradePlainToLiteralBlock($value)) {
                if ($properties !== '') {
                    $this->buffer .= ' ' . rtrim($properties);
                }
                $this->emitPlainAsLiteralBlock($value, $indent, atLineStart: false);
                return;
            }
            $this->buffer .= $this->inlineValue($properties, $value);
            $this->emitEolComment($eol);
            $this->buffer .= $this->lineEnding;
            return;
        }

        if ($value instanceof AliasNode) {
            $this->buffer .= ' *' . $value->getTargetName();
            $this->emitEolComment($eol);
            $this->buffer .= $this->lineEnding;
            return;
        }

        // A flow collection is written inline after the dash. So is an
        // empty block collection, which has no block form: written as a
        // block it was nothing at all, and read back as null.
        if ($this->isInline($value)) {
            $this->buffer .= ' ' . $properties . $this->renderFlowInline($value);
            $this->emitEolComment($eol);
            $this->buffer .= $this->lineEnding;
            return;
        }

        $compactMap = $value instanceof MapNode
            && $value->children()[0] instanceof MapEntry;
        // A nested block sequence stays on the indicator's line only when
        // it was written there; `-\n  - x` keeps its line break.
        $compactSequence = $value instanceof SequenceNode
            && $value->children()[0] instanceof SequenceItem
            && $line > 0
            && $value->children()[0]->line() === $line;
        if ($properties === '' && $eol === null && ($compactMap || $compactSequence)) {
            // Common idiom: first map entry inline with the dash:
            //
            //   - version: 6.0.0
            //     date: 2026-04-01
            //
            // The collection is emitted at the indicator column + 2,
            // through the same path as any other block collection, and
            // the first line's indent is then replaced with the
            // indicator. The two are the same width, so every line
            // keeps its column.
            $childIndent = $indent + 2;
            $saved = $this->buffer;
            $this->buffer = '';
            if ($value instanceof MapNode) {
                $this->emitMapNode($value, $childIndent);
            } else {
                $this->emitSequenceNode($value, $childIndent);
            }
            $rendered = $this->buffer;
            $this->buffer = $saved;
            $this->buffer .= ' ' . substr($rendered, $childIndent);
            return;
        }

        // A block collection carrying properties or an EOL comment
        // starts on the next line; the properties stay on the dash's
        // line, where they apply to the collection rather than to its
        // first key.
        if ($properties !== '') {
            $this->buffer .= ' ' . rtrim($properties);
        }
        $this->emitEolComment($eol);
        $this->buffer .= $this->lineEnding;
        if ($value instanceof MapNode) {
            $this->emitMapNode($value, $this->detectChildIndentForMap($value, $indent));
            return;
        }
        $this->emitSequenceNode($value, $this->detectChildIndentForSequence($value, $indent));
    }

    /**
     * True when a collection is written inline rather than as a block:
     * it is flow-styled, or it is empty and so has no block form.
     */
    private function isInline(MapNode|SequenceNode $node): bool
    {
        if ($node instanceof MapNode) {
            return $node->getStyle() === MapStyle::Flow || $node->entries() === [];
        }
        return $node->getStyle() === SequenceStyle::Flow || $node->items() === [];
    }

    /**
     * A scalar written after `key:` or `-`, with its properties and the
     * space before them. An empty node is nothing at all, so `a:` stays
     * `a:` rather than gaining a trailing space; properties on one stay,
     * without the space after them (`a: &x`).
     */
    private function inlineValue(string $properties, ScalarNode $value): string
    {
        $text = $this->scalarOrRaw($value);
        $text = $text === '' ? rtrim($properties) : $properties . $text;
        return $text === '' ? '' : ' ' . $text;
    }

    private function emitEolComment(?CommentNode $eol): void
    {
        if ($eol !== null) {
            $this->buffer .= ($eol->getGap() !== "" ? $eol->getGap() : '  ') . $eol->getText();
        }
    }

    private function emitStandaloneComment(CommentNode $comment, int $indent): void
    {
        // Use the comment's own column when the parser recorded one, so a
        // comment comes back where its author put it. null means no column was
        // recorded - a comment built programmatically rather than read from a
        // document - and those follow the surrounding container.
        //
        // Nullable rather than `> 0`: column 1 is a real position, and a
        // flush-left comment inside an indented block is a deliberate style
        // that was previously indistinguishable from "unset".
        $useIndent = $comment->getIndent() ?? $indent;
        $this->buffer .= str_repeat(' ', $useIndent);
        $this->buffer .= $comment->getText();
        $this->buffer .= $this->lineEnding;
    }

    /**
     * A node's raw source, in the line ending this document uses.
     *
     * Raw source is captured after the scanner has normalised CRLF to LF, so a
     * multi-line scalar re-emitted verbatim carried LF into a CRLF document -
     * a block scalar was the one construct that still broke a Windows file's
     * round trip after the stream learned its line ending.
     *
     * A no-op for LF documents, which is every document until one says
     * otherwise.
     */
    private function rawSource(ScalarNode $node): ?string
    {
        $raw = $node->getRawSource();

        if ($raw === null || $this->lineEnding === "\n") {
            return $raw;
        }

        return str_replace("\n", $this->lineEnding, $raw);
    }

    private function emitBlankLines(BlankLineNode $node): void
    {
        // count() blank lines = count() newline characters since the
        // previous content already terminated with a newline.
        $this->buffer .= str_repeat($this->lineEnding, $node->getCount());
    }

    private function scalarOrRaw(ScalarNode $node): string
    {
        $rawSource = $this->rawSource($node);
        if ($rawSource !== null) {
            return $rawSource;
        }
        return $this->scalarToString($node);
    }

    private function isBlockStyleScalar(ScalarNode $node): bool
    {
        $style = $node->getStyle();
        return $style === ScalarStyle::LiteralBlock
            || $style === ScalarStyle::FoldedBlock;
    }

    /**
     * True when a Plain-styled scalar carrying real newlines should
     * be upgraded to a synthesized literal block on emit.
     *
     * Motivation: the Plain -> SingleQuoted -> DoubleQuoted upgrade
     * ladder in emitString ends at DoubleQuoted for any string that
     * contains "\n", which then escapes each newline as the two
     * characters `\n`. That is a silent round-trip data change for
     * callers that hand the AST a real multi-line string (for
     * example horde/HordeYmlFile writing changelog entries).
     * Emitting a literal block instead keeps newlines as newlines.
     *
     * Gating:
     *  - Style must be Plain. Pinned SingleQuoted / DoubleQuoted are
     *    honored (the existing style upgrade for pinned-single with
     *    a newline still goes to DoubleQuoted).
     *  - Value must be a string containing at least one "\n".
     *  - No rawSource pinned (loader preserved a specific form).
     *  - No tag pinned (a tag disambiguates the type and belongs on
     *    the caller-chosen style).
     *  - Content must be literal-block-safe: only "\n" and "\t" as
     *    low control bytes, no lines starting with whitespace (would
     *    require an explicit indent indicator we do not synthesize
     *    yet), no lines consisting only of whitespace before a chomp
     *    boundary. Otherwise fall through to double-quoted.
     */
    private function shouldUpgradePlainToLiteralBlock(ScalarNode $node): bool
    {
        if ($node->getStyle() !== ScalarStyle::Plain) {
            return false;
        }
        if ($node->getRawSource() !== null) {
            return false;
        }
        if ($node->getTag() !== null) {
            return false;
        }
        $value = $node->getValue();
        if (!is_string($value) || !str_contains($value, "\n")) {
            return false;
        }
        // Only "\n" and "\t" are permitted as low control bytes. "\r",
        // form feed, NUL, etc. force double-quoted with hex escapes.
        for ($i = 0, $n = strlen($value); $i < $n; $i++) {
            $byte = $value[$i];
            $ord = ord($byte);
            if ($ord < 0x20 && $byte !== "\n" && $byte !== "\t") {
                return false;
            }
        }
        // A line starting with space or tab is ambiguous without an
        // explicit indent indicator (`|2` etc.). We do not synthesize
        // that yet, so fall back to double-quoted for those.
        //
        // A line that is only spaces or tabs is also risky at chomp
        // boundaries. Refuse those too.
        $lines = explode("\n", $value);
        // Strip a single trailing empty line (from a final "\n"); it
        // just marks Clip chomp and is fine.
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }
        foreach ($lines as $line) {
            if ($line === '') {
                continue; // blank line inside content is fine.
            }
            $first = $line[0];
            if ($first === ' ' || $first === "\t") {
                return false;
            }
            if (trim($line, " \t") === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * Emit a Plain-styled multi-line string as a literal block. The
     * caller has already written the map-entry key + `:` (or the
     * root position is empty). The indicator is chosen from the
     * value's trailing bytes:
     *
     *   ends with exactly one "\n"           -> `|`  (Clip)
     *   does not end with "\n"                -> `|-` (Strip)
     *   ends with more than one trailing "\n" -> `|+` (Keep)
     *
     * Content lines are written at parentIndent + 2. Blank interior
     * lines are emitted as bare newlines (no trailing spaces).
     *
     * $atLineStart is true when the caller sits at column 0 (root
     * scalar path); false when the caller has just written "key:" or
     * similar and needs a single leading space before the indicator.
     */
    private function emitPlainAsLiteralBlock(
        ScalarNode $node,
        int $parentIndent,
        bool $atLineStart,
    ): void {
        $value = (string) $node->getValue();

        // Pick chomp from the trailing newline count.
        $trailingNewlines = 0;
        for ($i = strlen($value) - 1; $i >= 0 && $value[$i] === "\n"; $i--) {
            $trailingNewlines++;
        }
        if ($trailingNewlines === 0) {
            $indicator = '|-';
        } elseif ($trailingNewlines === 1) {
            $indicator = '|';
        } else {
            $indicator = '|+';
        }

        if (!$atLineStart) {
            $this->buffer .= ' ';
        }
        $this->buffer .= $indicator . $this->lineEnding;

        $contentIndent = $parentIndent + 2;
        $lines = explode("\n", $value);
        // Drop trailing empties: chomp handles them. `|-` has zero
        // trailing "\n" so nothing to drop. `|` has one, giving one
        // empty tail element. `|+` has more than one; those we do not
        // re-emit because chomp restores them on read.
        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }
        foreach ($lines as $line) {
            if ($line === '') {
                $this->buffer .= $this->lineEnding;
                continue;
            }
            $this->buffer .= str_repeat(' ', $contentIndent) . $line . $this->lineEnding;
        }
    }

    /**
     * Emit a synthesized block scalar (no rawSource). Used in
     * emitMapEntry when a user has constructed a ScalarNode with
     * LiteralBlock / FoldedBlock style. Inline form: appends
     * indicator + chomp/indent + newline + content lines indented
     * to indent+2.
     */
    private function emitBlockScalarSynthesized(ScalarNode $node, int $parentIndent): void
    {
        $contentIndent = $this->blockContentIndent($node, $parentIndent);
        $this->buffer .= ' ' . $this->renderBlockIndicator($node);
        $this->buffer .= $this->lineEnding;
        $value = (string) $node->getValue();
        $lines = explode("\n", $value);
        // Strip a trailing empty line (the implicit one from a final
        // newline). Chomp logic handles it.
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }
        foreach ($lines as $line) {
            $this->buffer .= str_repeat(' ', $contentIndent) . $line . $this->lineEnding;
        }
    }

    /**
     * The column a block scalar's content is written at. An indentation
     * indicator (`>1`) gives it relative to the parent's indent (YAML
     * 1.2 §8.1.1.1); writing it at the parent + 2 regardless added a
     * leading space to every line of `>1` content, changing the value.
     */
    private function blockContentIndent(ScalarNode $node, int $parentIndent): int
    {
        return $parentIndent + ($node->getIndentIndicator() ?? 2);
    }

    private function renderBlockScalar(ScalarNode $node, int $parentIndent): string
    {
        $rawSource = $this->rawSource($node);
        if ($rawSource !== null) {
            return $rawSource;
        }
        // Fallback when called in non-MapEntry contexts. Build inline.
        $contentIndent = $this->blockContentIndent($node, $parentIndent);
        $out = $this->renderBlockIndicator($node) . $this->lineEnding;
        $value = (string) $node->getValue();
        $lines = explode("\n", $value);
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }
        foreach ($lines as $line) {
            $out .= str_repeat(' ', $contentIndent) . $line . $this->lineEnding;
        }
        return rtrim($out, $this->lineEnding);
    }

    private function renderBlockIndicator(ScalarNode $node): string
    {
        $indicator = $node->getStyle() === ScalarStyle::LiteralBlock ? '|' : '>';
        $chomp = $node->getChomp();
        if ($chomp === ChompMode::Strip) {
            $indicator .= '-';
        } elseif ($chomp === ChompMode::Keep) {
            $indicator .= '+';
        }
        $indent = $node->getIndentIndicator();
        if ($indent !== null) {
            $indicator .= (string) $indent;
        }
        return $indicator;
    }

    /**
     * Convert a key node to its emit string. Common case is a
     * ScalarNode (delegates to scalarToString); compound keys
     * (sequences, mappings, aliases) per YAML 1.2 §8.1.3 emit a
     * synthesised flow representation. Compound-key emission
     * fidelity is best-effort. Round-tripping yaml-test-suite
     * compound-key fixtures is a known limitation.
     */
    private function keyToString(
        MapNode|SequenceNode|ScalarNode|AliasNode $node,
    ): string {
        if ($node instanceof AliasNode) {
            return '*' . $node->getTargetName();
        }
        // A key's tag and anchor were dropped, so `&a a: b` came back
        // as `a: b` and any `*a` after it no longer resolved.
        return $this->renderValueProperties($node) . $this->keyBodyToString($node);
    }

    /**
     * What goes between a key and its `:`: the spaces the source had
     * (`key : value`), which were dropped. An alias key always has at
     * least one, because an anchor name may contain ":", so `*a: b` is
     * the alias "a:" with no value.
     */
    private function colonGap(MapEntry $entry): string
    {
        $gap = $entry->getColonGap();
        if ($gap === '' && $entry->getKey() instanceof AliasNode) {
            return ' ';
        }
        return $gap;
    }

    private function keyBodyToString(MapNode|SequenceNode|ScalarNode $node): string
    {
        if ($node instanceof ScalarNode) {
            // As written, like a value: a key ignored its raw source, so
            // `0x1F: a` came back as `31: a`, `~: a` as `null: a`, and
            // an empty key as `null`.
            return $this->scalarOrRaw($node);
        }
        // A compound key is a flow collection, emitted as any other.
        // It had its own renderer, which wrote "?" for anything but a
        // scalar inside it, including an alias.
        return $this->renderFlowInline($node);
    }

    /**
     * Convert a scalar's typed value to its emit string. Applies the
     * Upgrade ladder: plain -> single-quoted ->
     * double-quoted. The user-set style is honored when compatible
     * with the content; otherwise the emitter upgrades to the lowest
     * sufficient style.
     */
    private function scalarToString(ScalarNode $node): string
    {
        $value = $node->getValue();

        $style = $node->getStyle();
        if ($style === ScalarStyle::LiteralBlock || $style === ScalarStyle::FoldedBlock) {
            // Block scalars need indent context; callers like
            // emitMapEntry handle them via emitBlockScalarSynthesized.
            // If we got here it's because someone called
            // scalarToString directly on a block scalar. Assume
            // indent 0.
            return $this->renderBlockScalar($node, 0);
        }

        // null / bool / int / float emit unquoted regardless of the
        // node's style metadata. The typed PHP value is the canonical
        // form. (User-pinned quoted booleans/ints are stored as
        // strings, not as typed values.)
        if ($value === null) {
            return $this->plainOrUpgrade($node, 'null');
        }
        if ($value === true) {
            return $this->plainOrUpgrade($node, 'true');
        }
        if ($value === false) {
            return $this->plainOrUpgrade($node, 'false');
        }
        if (is_int($value)) {
            return $this->plainOrUpgrade($node, (string) $value);
        }
        if (is_float($value)) {
            if (is_infinite($value)) {
                return $value < 0 ? '-.inf' : '.inf';
            }
            if (is_nan($value)) {
                return '.nan';
            }
            // var_export keeps a "." or an exponent, so 1.0 stays a float
            // rather than reading back as the integer 1, and uses the
            // shortest form that reads back as the same double, where a
            // string cast rounds to `precision` (14) digits.
            return var_export($value, true);
        }

        // String value. Apply the style ladder.
        return $this->emitString($node, $value);
    }

    /**
     * For a typed-value scalar (null/bool/int/float) whose style is
     * Plain, emit the canonical text. If the user has pinned a quoted
     * style (e.g. setStyle(DoubleQuoted) on a node holding int 42),
     * still emit quoted using the canonical text as the body.
     */
    private function plainOrUpgrade(ScalarNode $node, string $canonical): string
    {
        $style = $node->getStyle();
        if ($style === ScalarStyle::SingleQuoted) {
            return "'" . str_replace("'", "''", $canonical) . "'";
        }
        if ($style === ScalarStyle::DoubleQuoted) {
            return '"' . $this->escapeForDoubleQuoted($canonical) . '"';
        }
        return $canonical;
    }

    private function emitString(ScalarNode $node, string $value): string
    {
        $style = $node->getStyle();
        $tag = $node->getTag();

        // Honor the user's preferred style if it can represent the
        // content; otherwise upgrade.
        if ($style === ScalarStyle::Plain) {
            if ($this->isPlainSafe($value, $tag)) {
                return $value;
            }
            // Upgrade to single-quoted unless content forces double.
            if ($this->isSingleQuotedSafe($value)) {
                return "'" . str_replace("'", "''", $value) . "'";
            }
            return '"' . $this->escapeForDoubleQuoted($value) . '"';
        }

        if ($style === ScalarStyle::SingleQuoted) {
            if ($this->isSingleQuotedSafe($value)) {
                return "'" . str_replace("'", "''", $value) . "'";
            }
            return '"' . $this->escapeForDoubleQuoted($value) . '"';
        }

        // DoubleQuoted: always representable.
        return '"' . $this->escapeForDoubleQuoted($value) . '"';
    }

    /**
     * Plain-safe content: no leading reserved
     * indicator; no `: ` or ` #` inside; no control characters; not
     * empty; does not look like a different YAML type after parsing
     * (e.g. the string "true" must be quoted to stay a string).
     *
     * Exception: when the node carries an explicit tag, the tag
     * disambiguates type and the content can stay plain regardless
     * of regex matches.
     */
    private function isPlainSafe(string $value, ?string $tag = null): bool
    {
        if ($value === '') {
            return false;
        }
        $first = $value[0];
        $reservedLeaders = ['[', ']', '{', '}', '#', '&', '*', '!', '|', '>', "'", '"', '%', '@', '`', ',', "\t", ' '];
        if (in_array($first, $reservedLeaders, true)) {
            return false;
        }
        // "?", ":" and "-" are indicators only when they stand alone or are
        // followed by a space. YAML 1.2 ns-plain-first admits them otherwise,
        // so "--cert-dir=/tmp" and "-single-dash" are valid plain scalars and
        // quoting them rewrites a line the author did not write that way.
        if (in_array($first, ['?', ':', '-'], true)) {
            if (strlen($value) === 1) {
                return false;
            }
            $second = $value[1];
            if ($second === ' ' || $second === "\t") {
                return false;
            }
        }
        if (str_contains($value, ': ') || str_contains($value, " #")) {
            return false;
        }
        // A ":" is content only when a plain-safe character follows it.
        // At the end of the value, or before a tab, it is a mapping
        // indicator: `key: oidc:` does not parse.
        if (str_ends_with($value, ':') || str_contains($value, ":\t") || str_contains($value, "\t#")) {
            return false;
        }
        // Control characters.
        for ($i = 0, $n = strlen($value); $i < $n; $i++) {
            $ord = ord($value[$i]);
            if ($ord < 0x20 && $value[$i] !== "\t") {
                return false;
            }
        }
        // Trailing whitespace requires quoting.
        if (str_ends_with($value, ' ') || str_ends_with($value, "\t")) {
            return false;
        }
        // Content that would round-trip to a non-string typed value
        // must be quoted to stay a string. Skip this check when an
        // explicit tag is present (the tag disambiguates type).
        if ($tag === null && $this->wouldReparseAsNonString($value)) {
            return false;
        }
        return true;
    }

    private function wouldReparseAsNonString(string $value): bool
    {
        // null patterns
        if (in_array($value, ['null', 'Null', 'NULL', '~', ''], true)) {
            return true;
        }
        // bool patterns
        if (in_array($value, ['true', 'True', 'TRUE', 'false', 'False', 'FALSE'], true)) {
            return true;
        }
        // integer (decimal/octal/hex)
        if (preg_match('/^[-+]?[0-9]+$/', $value)) {
            return true;
        }
        if (preg_match('/^0[ox][0-9a-fA-F]+$/', $value)) {
            return true;
        }
        // float (general/.inf/.nan)
        if (preg_match('/^[-+]?(?:\.[0-9]+|[0-9]+(?:\.[0-9]*)?)(?:[eE][-+]?[0-9]+)?$/', $value)) {
            return true;
        }
        if (preg_match('/^[-+]?\.(?:inf|Inf|INF)$/', $value)) {
            return true;
        }
        if (preg_match('/^\.(?:nan|NaN|NAN)$/', $value)) {
            return true;
        }
        return false;
    }

    /**
     * Single-quoted style cannot represent characters that need
     * escape sequences (control characters except tab; non-breaking
     * spaces won't escape cleanly; etc.). Returns true when the
     * value contains only printable / tab / newline-free characters.
     */
    private function isSingleQuotedSafe(string $value): bool
    {
        for ($i = 0, $n = strlen($value); $i < $n; $i++) {
            $byte = $value[$i];
            $ord = ord($byte);
            if ($byte === "\n") {
                // Multi-line single-quoted strings are not supported
                // in our scope; fall through to double-quoted.
                return false;
            }
            if ($ord < 0x20 && $byte !== "\t") {
                return false;
            }
        }
        return true;
    }

    private function escapeForDoubleQuoted(string $value): string
    {
        $out = '';
        for ($i = 0, $n = strlen($value); $i < $n; $i++) {
            $byte = $value[$i];
            if ($byte === '"') {
                $out .= '\\"';
                continue;
            }
            if ($byte === '\\') {
                $out .= '\\\\';
                continue;
            }
            if ($byte === "\n") {
                $out .= '\\n';
                continue;
            }
            if ($byte === "\t") {
                $out .= '\\t';
                continue;
            }
            if ($byte === "\r") {
                $out .= '\\r';
                continue;
            }
            $ord = ord($byte);
            if ($ord < 0x20) {
                $out .= sprintf('\\x%02X', $ord);
                continue;
            }
            $out .= $byte;
        }
        return $out;
    }
}
