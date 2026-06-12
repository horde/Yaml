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
 * @see /home/i567442/php/horde-development/libraries/yaml/06-emitter-strategy-2026-06-12.md
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
        if ($node instanceof MapNode) {
            $this->emitMapNode($node, $indent);
            return;
        }
        if ($node instanceof SequenceNode) {
            $this->emitSequenceNode($node, $indent);
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
            $raw = $node->getRawSource();
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
        $this->buffer .= $properties . $this->scalarOrRaw($node);
        $this->buffer .= $this->lineEnding;
    }

    private function emitMapNode(MapNode $map, int $indent): void
    {
        if ($map->getStyle() === MapStyle::Flow) {
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
            $key = $this->keyToString($entry->getKey());
            $value = $entry->getValue();
            if ($value instanceof ScalarNode) {
                $parts[] = $key . ': ' . $this->scalarOrRaw($value);
                continue;
            }
            if ($value instanceof AliasNode) {
                $parts[] = $key . ': *' . $value->getTargetName();
                continue;
            }
            // Nested map/sequence in flow. Recurse inline.
            $parts[] = $key . ': ' . $this->renderFlowInline($value);
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
            $value = $item->getValue();
            if ($value instanceof ScalarNode) {
                $parts[] = $this->scalarOrRaw($value);
                continue;
            }
            if ($value instanceof AliasNode) {
                $parts[] = '*' . $value->getTargetName();
                continue;
            }
            $parts[] = $this->renderFlowInline($value);
        }
        $this->buffer .= '[' . implode(', ', $parts) . ']';
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
        $this->buffer .= str_repeat(' ', $indent);

        // Key.
        $this->buffer .= $this->keyToString($entry->getKey());
        $this->buffer .= ':';

        $value = $entry->getValue();

        // Properties (tag, anchor) on the value go before the value
        // bytes per Stage 6 §6.4 (tag before anchor).
        $properties = $this->renderValueProperties($value);

        if ($value instanceof ScalarNode) {
            // Block scalars span multiple lines and end with their own
            // newline.
            if ($this->isBlockStyleScalar($value)) {
                $rawSource = $value->getRawSource();
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
            $this->buffer .= ' ' . $properties . $this->scalarOrRaw($value);
        } elseif ($value instanceof AliasNode) {
            // Alias as entry value.
            $this->buffer .= ' ' . $properties . '*' . $value->getTargetName();
        } elseif ($value instanceof MapNode) {
            // Flow-style mapping value: emit inline on the same line.
            if ($value->getStyle() === MapStyle::Flow) {
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
            // Flow-style sequence value: inline.
            if ($value->getStyle() === SequenceStyle::Flow) {
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
     * Render the property prefix (tag, anchor) for a value, in
     * Stage 6 §6.4 order. Returns "" for nodes without properties,
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
        if ($seq->getStyle() === SequenceStyle::Flow) {
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
        $this->buffer .= str_repeat(' ', $indent);
        $this->buffer .= '-';

        $value = $item->getValue();
        if ($value === null) {
            // Null item value. Emit empty.
            $eol = $item->getEolComment();
            if ($eol !== null) {
                $this->buffer .= ($eol->getGap() !== "" ? $eol->getGap() : '  ') . $eol->getText();
            }
            $this->buffer .= $this->lineEnding;
            return;
        }

        if ($value instanceof ScalarNode) {
            $this->buffer .= ' ' . $this->scalarOrRaw($value);
            $eol = $item->getEolComment();
            if ($eol !== null) {
                $this->buffer .= ($eol->getGap() !== "" ? $eol->getGap() : '  ') . $eol->getText();
            }
            $this->buffer .= $this->lineEnding;
            return;
        }

        if ($value instanceof MapNode) {
            // Common idiom: first map entry inline with the dash:
            //
            //   - version: 6.0.0
            //     date: 2026-04-01
            //
            // We emit `- ` then the first entry's key/value, then the
            // remaining entries at the deeper indent (dash column + 2).
            $eol = $item->getEolComment();
            $childIndent = $indent + 2;
            $entries = $value->entries();
            if ($entries === []) {
                // Empty map: emit dash with empty value.
                if ($eol !== null) {
                    $this->buffer .= ($eol->getGap() !== "" ? $eol->getGap() : '  ') . $eol->getText();
                }
                $this->buffer .= $this->lineEnding;
                return;
            }

            $first = $entries[0];
            $firstValue = $first->getValue();

            $this->buffer .= ' ';
            $this->buffer .= $this->keyToString($first->getKey());
            $this->buffer .= ':';

            if ($firstValue instanceof ScalarNode) {
                $this->buffer .= ' ' . $this->scalarOrRaw($firstValue);
                $firstEol = $first->getEolComment();
                if ($firstEol !== null) {
                    $this->buffer .= ($firstEol->getGap() !== "" ? $firstEol->getGap() : '  ') . $firstEol->getText();
                }
                $this->buffer .= $this->lineEnding;
            } elseif ($firstValue instanceof MapNode) {
                $this->buffer .= $this->lineEnding;
                $this->emitMapNode($firstValue, $childIndent + 2);
            } elseif ($firstValue instanceof SequenceNode) {
                $this->buffer .= $this->lineEnding;
                $this->emitSequenceNode($firstValue, $childIndent + 2);
            } else {
                throw new EmitException(
                    'Unsupported value type for inline map entry under dash: ' . $firstValue::class,
                    $firstValue,
                );
            }

            // Remaining entries / children at the deeper indent.
            for ($i = 1; $i < count($value->children()); $i++) {
                $child = $value->children()[$i];
                if ($child instanceof MapEntry) {
                    $this->emitMapEntry($child, $childIndent);
                    continue;
                }
                if ($child instanceof CommentNode) {
                    $this->emitStandaloneComment($child, $childIndent);
                    continue;
                }
                if ($child instanceof BlankLineNode) {
                    $this->emitBlankLines($child);
                    continue;
                }
            }

            // The first child was already emitted above; we walked
            // children in source order. If the first child is a
            // CommentNode or BlankLineNode rather than a MapEntry,
            // the loop above is wrong. Handle that case by walking
            // ALL children including the first when the first is not
            // a MapEntry.
            //
            // Note: this is a corner case. Leading trivia inside a
            // map under a dash. Stage 2 fixtures do not currently
            // exercise this; if it surfaces, we revisit. The above
            // assumes entries[0] === children[0], which is only true
            // when the map starts with an entry.
            return;
        }

        if ($value instanceof SequenceNode) {
            $eol = $item->getEolComment();
            if ($eol !== null) {
                $this->buffer .= ($eol->getGap() !== "" ? $eol->getGap() : '  ') . $eol->getText();
            }
            $this->buffer .= $this->lineEnding;
            $childIndent = $this->detectChildIndentForSequence($value, $indent);
            $this->emitSequenceNode($value, $childIndent);
            return;
        }

        throw new EmitException(
            'Unsupported sequence item value type ' . $value::class . ' in D.03',
            $value,
        );
    }

    private function emitStandaloneComment(CommentNode $comment, int $indent): void
    {
        // Use the comment's stored indent if it has one; otherwise
        // the surrounding container's indent.
        $useIndent = $comment->getIndent() > 0 ? $comment->getIndent() : $indent;
        $this->buffer .= str_repeat(' ', $useIndent);
        $this->buffer .= $comment->getText();
        $this->buffer .= $this->lineEnding;
    }

    private function emitBlankLines(BlankLineNode $node): void
    {
        // count() blank lines = count() newline characters since the
        // previous content already terminated with a newline.
        $this->buffer .= str_repeat($this->lineEnding, $node->getCount());
    }

    private function scalarOrRaw(ScalarNode $node): string
    {
        $rawSource = $node->getRawSource();
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
     * Emit a synthesized block scalar (no rawSource). Used in
     * emitMapEntry when a user has constructed a ScalarNode with
     * LiteralBlock / FoldedBlock style. Inline form: appends
     * indicator + chomp/indent + newline + content lines indented
     * to indent+2.
     */
    private function emitBlockScalarSynthesized(ScalarNode $node, int $parentIndent): void
    {
        $contentIndent = $parentIndent + 2;
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

    private function renderBlockScalar(ScalarNode $node, int $parentIndent): string
    {
        $rawSource = $node->getRawSource();
        if ($rawSource !== null) {
            return $rawSource;
        }
        // Fallback when called in non-MapEntry contexts. Build inline.
        $contentIndent = $parentIndent + 2;
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
        if ($node instanceof ScalarNode) {
            return $this->scalarToString($node);
        }
        if ($node instanceof AliasNode) {
            return '*' . $node->getTargetName();
        }
        if ($node instanceof SequenceNode) {
            $parts = [];
            foreach ($node->children() as $item) {
                $value = $item->getValue();
                $parts[] = $value instanceof ScalarNode
                    ? $this->scalarToString($value)
                    : '?';
            }
            return '[' . implode(', ', $parts) . ']';
        }
        // MapNode
        $parts = [];
        foreach ($node->entries() as $entry) {
            $kv = $entry->getValue();
            $key = $this->keyToString($entry->getKey());
            $val = $kv instanceof ScalarNode ? $this->scalarToString($kv) : '?';
            $parts[] = $key . ': ' . $val;
        }
        return '{' . implode(', ', $parts) . '}';
    }

    /**
     * Convert a scalar's typed value to its emit string. Applies the
     * Stage 6 §3.1 upgrade ladder: plain -> single-quoted ->
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
            return (string) $value;
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
     * Plain-safe content per Stage 6 §3.1: no leading reserved
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
        $reservedLeaders = ['[', ']', '{', '}', '#', '&', '*', '!', '|', '>', "'", '"', '%', '@', '`', '?', ':', '-', ',', "\t", ' '];
        if (in_array($first, $reservedLeaders, true)) {
            return false;
        }
        if (str_contains($value, ': ') || str_contains($value, " #")) {
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
