<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Document\Node;

/**
 * A standalone comment in the document.
 *
 * CommentNode is a first-class addressable node, not metadata attached
 * to nearby content (per the comments-as-first-class commitment in
 * Stage 2 §0). It appears as a child in the parent map's or sequence's
 * children list, or in the leading/trailing trivia list of a stream
 * or document.
 *
 * EOL comments are CommentNode instances too; they live in the
 * eolComment slot of a MapEntry, SequenceItem, or AliasNode rather
 * than in a children list.
 *
 * Holds the comment text (including the leading `#`) and the source
 * indent level.
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/03-ast-and-document-model-2026-06-11.md §2.8
 */
final class CommentNode implements Node
{
    use NodeTrait;

    private string $text;
    private int $indent;
    private string $gap;

    /**
     * @param string $text   Comment text including the leading `#`. Default
     *                       empty for synthesized nodes; production code
     *                       should always provide explicit text.
     * @param int    $indent Source indent (number of leading spaces).
     * @param string $gap    Whitespace bytes that sat between the preceding
     *                       structural token and the `#`. For an EOL comment
     *                       this is the gap before the hash; for a standalone
     *                       comment it duplicates the indent and is normally
     *                       left empty (the standalone path uses the indent).
     *                       Empty for synthesized nodes; the emitter falls
     *                       back to a sensible default when emitting.
     */
    public function __construct(string $text = '', int $indent = 0, string $gap = '')
    {
        $this->text = $text;
        $this->indent = $indent;
        $this->gap = $gap;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function setText(string $text): void
    {
        $this->text = $text;
    }

    public function getIndent(): int
    {
        return $this->indent;
    }

    public function setIndent(int $indent): void
    {
        $this->indent = $indent;
    }

    public function getGap(): string
    {
        return $this->gap;
    }

    public function setGap(string $gap): void
    {
        $this->gap = $gap;
    }
}
