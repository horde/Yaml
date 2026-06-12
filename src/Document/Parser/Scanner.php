<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Document\Parser;

use Horde\Yaml\Document\EncodingException;
use Horde\Yaml\Document\LeniencyPolicy;
use Horde\Yaml\Document\Node\ChompMode;
use Horde\Yaml\Document\Node\MapStyle;
use Horde\Yaml\Document\Node\ScalarStyle;
use Horde\Yaml\Document\ParseException;
use Horde\Yaml\Document\Token;
use Horde\Yaml\Document\TokenType;
use Horde\Yaml\Document\TriviaToken;
use Horde\Yaml\Document\TriviaType;

/**
 * Bytes to Token[] scanner.
 *
 * Phase C.01 scope: stream and document boundaries (B.01), plain
 * scalars (B.02), and block-style mappings with plain scalar keys
 * and plain scalar values. Block sequences, flow style, quoted
 * scalars, block scalars, anchors/aliases/tags arrive in later
 * phases.
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/05-parser-strategy-2026-06-12.md §2
 */
final class Scanner
{
    private string $source = '';
    private int $pos = 0;
    private int $length = 0;
    private int $line = 1;
    private int $column = 1;

    /** @var list<TriviaToken> */
    private array $triviaBuffer = [];

    /**
     * Stack of open block-context entries. Each entry is
     * [indent, kind] where kind is 'map' or 'seq'. The top of the
     * stack is the innermost currently-open block context. Empty
     * stack means top-level (no block context).
     *
     * @var list<array{int, string}>
     */
    private array $indentStack = [];

    /**
     * Column of the most recently emitted Anchor or Tag token in a
     * value position, or null when no pending property is in flight.
     * Used by the block-mapping-start path to honour the property's
     * host column when the property is at line start (e.g.
     * `&anchor key: value` continues an outer mapping at the
     * anchor's column rather than starting a nested map at the key's
     * column).
     */
    private ?int $pendingPropertyColumn = null;

    /**
     * Tracks whether we are inside a scanCompoundContent recursion.
     * When true, scanBlockSequenceItem's empty-value-with-deeper-
     * next-line path consumes the deeper content inline (rather than
     * returning to "outer scan loop" which only exists at the top
     * level). yaml-test-suite RZP5, XW4D: `- #comment\n  content`.
     */
    private bool $insideCompoundContent = false;

    /**
     * Depth of currently-open flow containers. Quoted scalars inside
     * a flow context must NOT consume the trailing newline. The
     * flow loop's newline handling validates indent and looks for
     * `,` separators (yaml-test-suite ZXT5).
     */
    private int $flowDepth = 0;

    private LeniencyPolicy $policy;

    public function __construct(?LeniencyPolicy $policy = null)
    {
        $this->policy = $policy ?? LeniencyPolicy::hordeCompat();
    }

    /**
     * @return list<Token>
     */
    public function scan(string $source): array
    {
        $this->validateUtf8($source);
        [$this->source, $this->length] = $this->normalizeNewlines($this->stripBom($source));
        $this->pos = 0;
        $this->line = 1;
        $this->column = 1;
        $this->triviaBuffer = [];
        $this->indentStack = [];
        $this->pendingPropertyColumn = null;
        $this->insideCompoundContent = false;
        $this->flowDepth = 0;

        $tokens = [];
        $tokens[] = $this->makeStructural(TokenType::StreamStart, 1, 1);

        while ($this->pos < $this->length) {
            // Collect trivia (comments and blank lines) at current position.
            if ($this->collectTrivia()) {
                continue;
            }

            // After trivia, check for document markers. Per YAML 1.2
            // §9.1.2 the markers `---` and `...` at column 1 must be
            // followed by whitespace, newline, or end-of-input.
            // `---word` is plain-scalar content, not a doc-start
            // (yaml-test-suite EXG3).
            if ($this->matchesDocumentMarkerAt('---')) {
                $this->emitBlockEndsTo(-1, $tokens);
                $tokens[] = $this->consumeDocumentStart();
                // If the marker line carried a property (anchor /
                // alias / tag), scan it now so it attaches to the
                // root node introduced by the marker. The property
                // sits at column > 1, but logically it's a fresh
                // start; advance past the trailing newline so the
                // next iteration picks up the value at line start.
                if ($this->pos < $this->length
                    && ($this->source[$this->pos] === '&'
                        || $this->source[$this->pos] === '*'
                        || $this->source[$this->pos] === '!')
                ) {
                    $this->scanProperties($tokens);
                    while ($this->pos < $this->length
                        && ($this->source[$this->pos] === ' '
                            || $this->source[$this->pos] === "\t")
                    ) {
                        $this->advance();
                    }
                    if ($this->pos < $this->length && $this->source[$this->pos] === "\n") {
                        $this->advance();
                    }
                }
                continue;
            }
            if ($this->matchesDocumentMarkerAt('...')) {
                $this->emitBlockEndsTo(-1, $tokens);
                $tokens[] = $this->consumeDocumentEnd();
                continue;
            }
            if ($this->matchesAtLineStart('%')) {
                // Per YAML 1.2 §6.8 a directive may appear only at
                // the start of the stream, after a `...` end marker,
                // or after another directive. A directive emitted
                // mid-document is invalid (yaml-test-suite EB22,
                // 9HCY). XLQ9-style plain-scalar continuations into
                // a `%`-prefixed line are folded by
                // tryConsumePlainContinuation BEFORE this branch
                // sees the byte, so the gate fires only for genuine
                // directives.
                $lastNonStructural = $this->lastNonStructuralEmitted($tokens);
                if ($lastNonStructural !== null
                    && $lastNonStructural !== TokenType::Directive
                    && $lastNonStructural !== TokenType::DocumentEnd
                ) {
                    throw new ParseException(sprintf(
                        'Directive after document content at line %d column %d',
                        $this->line,
                        $this->column,
                    ));
                }
                $tokens[] = $this->consumeDirective();
                continue;
            }

            // At a line position carrying content. Determine indent
            // and dispatch.
            $indent = $this->column - 1;

            // Dedent: emit BlockEnd for any open block contexts whose
            // indent exceeds the current line's indent.
            $this->emitBlockEndsTo($indent, $tokens);

            // Block sequence item: `-` followed by space or newline.
            if ($this->source[$this->pos] === '-'
                && $this->isBlockSequenceIndicatorAt($this->pos)
            ) {
                $top = $this->indentStack === [] ? null : end($this->indentStack);
                if ($top === null || $top[0] < $indent || $top[1] !== 'seq') {
                    $this->indentStack[] = [$indent, 'seq'];
                    $tokens[] = $this->makeStructural(
                        TokenType::BlockSequenceStart,
                        $this->line,
                        $this->column,
                    );
                }
                $this->scanBlockSequenceItem($tokens);
                continue;
            }

            // Anchor, alias, or tag indicator at value position. These
            // attach to the next-following node; the scanner emits a
            // dedicated token and continues.
            if ($this->source[$this->pos] === '&'
                || $this->source[$this->pos] === '*'
                || $this->source[$this->pos] === '!'
            ) {
                $propStartColumn = $this->column;
                $propStartLine = $this->line;
                // If this property is at a deeper indent than the
                // current top block container AND looks like the
                // beginning of a new block mapping entry (i.e. ends
                // with `key: ...` on the same line), pre-emit the
                // BlockMappingStart so the property attaches to the
                // upcoming key rather than to the parent container.
                // Required by yaml-test-suite 7BMT / U3XV where two
                // anchors stack: outer one for the mapping, inner
                // one for the first key.
                $top = $this->indentStack === [] ? null : end($this->indentStack);
                $preEmitted = false;
                // Sniff whether the leading property is an alias.
                // Alias-as-key (`*alias : value`) needs the
                // BlockMappingStart BEFORE the alias so the alias is
                // the entry's key node, not a sibling property.
                $startsWithAlias = $this->source[$this->pos] === '*';
                // Pre-emit BlockMappingStart when this property is at
                // a deeper indent than the current top container, OR
                // when the stack is empty (top-level) and the upcoming
                // line forms a block-mapping entry. yaml-test-suite
                // 7BMT / U3XV / 9KAX.
                $deeperThanTop = $top !== null && $top[0] < $indent;
                $topLevelMap = $top === null
                    && $this->propertySequenceLeadsToBlockKey($indent);
                if (($deeperThanTop || $topLevelMap)
                    && $this->propertySequenceLeadsToBlockKey($indent)
                ) {
                    // Two cases:
                    //  (a) The previous token was already a property
                    //      (an anchor/tag emitted by scanBlockMapEntry
                    //      for the parent's value). That property is
                    //      the MAP's anchor/tag; the upcoming
                    //      property/properties belong to the first
                    //      key. Emit BlockMappingStart BEFORE
                    //      scanning so the upcoming property lands
                    //      inside. yaml-test-suite 7BMT.
                    //  (b) Otherwise, the upcoming property is the
                    //      MAP's own anchor/tag (`top:\n  &mapAnchor
                    //      key: ...`-style). Scan it first, then
                    //      emit BlockMappingStart, so the property
                    //      ends up before BlockMappingStart and is
                    //      consumed by parseNode for the map. A
                    //      subsequent property on the next line will
                    //      land inside the map and attach to the
                    //      first key. yaml-test-suite U3XV.
                    $lastTokenType = $tokens === []
                        ? null
                        : $tokens[count($tokens) - 1]->type;
                    $previousWasProperty = $lastTokenType === TokenType::Anchor
                        || $lastTokenType === TokenType::Tag;
                    if ($previousWasProperty || $startsWithAlias) {
                        // Alias-as-key path: BlockMappingStart goes
                        // BEFORE the alias so the alias is the key
                        // node inside the new map.
                        $this->indentStack[] = [$indent, 'map'];
                        $tokens[] = $this->makeStructural(
                            TokenType::BlockMappingStart,
                            $this->line,
                            $this->column,
                        );
                        $preEmitted = true;
                        $this->scanProperties($tokens);
                    } else {
                        $this->scanProperties($tokens);
                        $this->indentStack[] = [$indent, 'map'];
                        $tokens[] = $this->makeStructural(
                            TokenType::BlockMappingStart,
                            $this->line,
                            $this->column,
                        );
                        $preEmitted = true;
                    }
                } else {
                    $this->scanProperties($tokens);
                }
                // Skip trailing whitespace and EOL comment so the
                // newline-only fall-through below fires for both
                // top-level (`&anchor\nvalue`) and indented
                // (`top4:\n  &node4\n  &k4 key4: ...`) shapes.
                while ($this->pos < $this->length
                    && ($this->source[$this->pos] === ' '
                        || $this->source[$this->pos] === "\t")
                ) {
                    $this->advance();
                }
                // Per YAML 1.2 §8.2.1 a block sequence's `-` indicator
                // begins a line. An anchor or tag immediately followed
                // on the SAME LINE by `-<space|tab|newline>` puts the
                // dash mid-line at deeper indent and is a parse error
                // (yaml-test-suite SY6V: `&anchor - sequence entry`).
                if ($this->line === $propStartLine
                    && $this->pos < $this->length
                    && $this->source[$this->pos] === '-'
                    && $this->isBlockSequenceIndicatorAt($this->pos)
                ) {
                    throw new ParseException(sprintf(
                        'Property cannot appear before block sequence indicator '
                            . '`-` on the same line at line %d column %d',
                        $propStartLine,
                        $propStartColumn,
                    ));
                }
                if ($this->pos < $this->length && $this->source[$this->pos] === '#') {
                    $commentLine = $this->line;
                    $commentColumn = $this->column;
                    $text = '';
                    while ($this->pos < $this->length && $this->source[$this->pos] !== "\n") {
                        $text .= $this->source[$this->pos];
                        $this->advance();
                    }
                    $this->triviaBuffer[] = TriviaToken::comment(
                        $text,
                        $commentLine,
                        $commentColumn,
                    );
                }
                // If the property has no inline value, advance past
                // the trailing newline so the next iteration picks up
                // the value at line start. Only do so when the
                // property's column is genuinely INSIDE the topmost
                // open block container (or the stack is empty).
                // `!!map` at column 1 inside an open `[0, 'map']` is
                // a sibling of that map. Leaving the newline in
                // place lets the dedent path fire and surfaces the
                // real error (yaml-test-suite H7J7).
                if ($this->pos < $this->length && $this->source[$this->pos] === "\n") {
                    $top = $this->indentStack === []
                        ? null
                        : end($this->indentStack);
                    $atIndent = ($top[0] ?? -1);
                    // The property's start column was captured before
                    // scanProperties advanced; recover it by walking
                    // back to the most recent property token.
                    $propColumn = $tokens[count($tokens) - 1]->column;
                    // If we just pre-emitted a BlockMappingStart, the
                    // newly-pushed container is the property's host
                    // and we should always advance past the trailing
                    // newline (the upcoming key follows on the next
                    // line).
                    if ($preEmitted
                        || $top === null
                        || ($propColumn - 1) > $atIndent
                    ) {
                        $this->advance();
                    }
                }
                // Remember the property's host column so that an
                // upcoming BlockMappingStart can attribute the new
                // entry to an existing mapping at the same indent
                // (yaml-test-suite ZWK4: `&anchor c: 3` continues the
                // outer mapping rather than starting a nested one at
                // `c`'s column). When multiple properties stack on a
                // single node (e.g. HMQ5's `!!str &a1 "foo":`),
                // remember the FIRST property's column.
                if ($this->pendingPropertyColumn === null) {
                    $this->pendingPropertyColumn = $propStartColumn;
                }
                continue;
            }

            // Flow container at top level.
            if ($this->source[$this->pos] === '['
                || $this->source[$this->pos] === '{'
            ) {
                $this->scanFlowContainer($tokens);
                continue;
            }

            // Decide what kind of content this is.
            if ($this->canStartScalar()) {
                if ($this->lineStartsBlockMappingEntry($indent)) {
                    // If a property was emitted earlier on this line
                    // at a smaller column, the new mapping entry
                    // belongs to that property's host indent, not
                    // the key's column. yaml-test-suite ZWK4.
                    $effectiveIndent = $indent;
                    if ($this->pendingPropertyColumn !== null) {
                        $effectiveIndent = $this->pendingPropertyColumn - 1;
                    }
                    $this->popSiblingSequenceAt($effectiveIndent, $tokens);
                    $top = $this->indentStack === [] ? null : end($this->indentStack);
                    if ($top === null || $top[0] < $effectiveIndent || $top[1] !== 'map') {
                        $this->indentStack[] = [$effectiveIndent, 'map'];
                        $tokens[] = $this->makeStructural(
                            TokenType::BlockMappingStart,
                            $this->line,
                            $this->column,
                        );
                    }
                    $this->pendingPropertyColumn = null;
                    $this->scanBlockMapEntry($tokens);
                    continue;
                }

                $this->pendingPropertyColumn = null;
                $tokens[] = $this->consumeScalar();
                continue;
            }

            // Explicit-key indicator `? ` at the start of a line: a
            // block-mapping entry whose key is on the indicator line
            // (or its continuation) and whose value follows on a
            // separate `:` line. Per YAML 1.2 §8.1.1, but this scanner
            // only supports the common scalar-key form needed for
            // `!!set` and similar `? member` lists.
            if ($this->source[$this->pos] === '?'
                && $this->isExplicitKeyIndicatorAt($this->pos)
            ) {
                $this->popSiblingSequenceAt($indent, $tokens);
                $top = $this->indentStack === [] ? null : end($this->indentStack);
                if ($top === null || $top[0] < $indent || $top[1] !== 'map') {
                    $this->indentStack[] = [$indent, 'map'];
                    $tokens[] = $this->makeStructural(
                        TokenType::BlockMappingStart,
                        $this->line,
                        $this->column,
                    );
                }
                $this->scanExplicitKeyEntry($tokens, $indent);
                continue;
            }

            // Value indicator `: ` at the start of a line: a block
            // mapping entry whose key is empty (implicit null). Per
            // YAML 1.2 §8.1.2.
            if ($this->source[$this->pos] === ':'
                && $this->isValueIndicatorAt($this->pos)
            ) {
                // If the immediately preceding token is an Alias or
                // a closed flow container (FlowSequenceEnd /
                // FlowMappingEnd), it is the entry's key node:
                // splice a Key marker BEFORE it (and skip the empty
                // implicit-key Scalar).
                //
                // - Alias-as-key: yaml-test-suite E76Z, 26DV.
                // - Flow-container-as-key: yaml-test-suite 6BFJ,
                //   LX3P (`[a]: b`), Q9WF (`{a, b}: ...`).
                $aliasAsKey = false;
                $flowAsKey = false;
                if ($tokens !== []) {
                    $lastTok = $tokens[count($tokens) - 1];
                    if ($lastTok->type === TokenType::Alias) {
                        $beforeAlias = count($tokens) >= 2
                            ? $tokens[count($tokens) - 2]->type
                            : null;
                        if ($beforeAlias === TokenType::Anchor
                            || $beforeAlias === TokenType::Tag
                        ) {
                            throw new ParseException(sprintf(
                                'Alias node may not be anchored or tagged at line %d column %d',
                                $lastTok->line,
                                $lastTok->column,
                            ));
                        }
                        $aliasAsKey = true;
                    } elseif ($lastTok->type === TokenType::FlowSequenceEnd
                        || $lastTok->type === TokenType::FlowMappingEnd
                    ) {
                        // A flow container is a valid implicit pair
                        // key only when it fits on a single line per
                        // YAML 1.2 §7.4.1 (yaml-test-suite C2SP:
                        // multi-line `[23\n]: 42` is invalid).
                        // Locate the matching open and check.
                        $depth = 0;
                        for ($i = count($tokens) - 1; $i >= 0; $i--) {
                            $tt = $tokens[$i]->type;
                            if ($tt === TokenType::FlowSequenceEnd
                                || $tt === TokenType::FlowMappingEnd
                            ) {
                                $depth++;
                                continue;
                            }
                            if ($tt === TokenType::FlowSequenceStart
                                || $tt === TokenType::FlowMappingStart
                            ) {
                                $depth--;
                                if ($depth === 0) {
                                    $flowAsKey = $tokens[$i]->line === $lastTok->line;
                                    break;
                                }
                            }
                        }
                    }
                }

                // If a property was emitted earlier on this line, the
                // mapping host indent is the property's column - 1,
                // not the colon's column - 1. Per yaml-test-suite
                // FH7J: `!!null : a` is a block-mapping entry whose
                // key node is the tagged-empty scalar `!!null`.
                $effectiveIndent = $indent;
                if ($this->pendingPropertyColumn !== null) {
                    $effectiveIndent = $this->pendingPropertyColumn - 1;
                }
                $this->popSiblingSequenceAt($effectiveIndent, $tokens);
                $top = $this->indentStack === [] ? null : end($this->indentStack);
                $needPush = $top === null || $top[0] < $effectiveIndent || $top[1] !== 'map';
                if ($needPush && $aliasAsKey) {
                    // Insert BlockMappingStart BEFORE the alias so
                    // the alias remains the entry's key node.
                    $aliasToken = array_pop($tokens);
                    $this->indentStack[] = [$effectiveIndent, 'map'];
                    $tokens[] = $this->makeStructural(
                        TokenType::BlockMappingStart,
                        $aliasToken->line,
                        $aliasToken->column,
                    );
                    $tokens[] = $aliasToken;
                } elseif ($flowAsKey) {
                    // Walk back to the matching FlowSequenceStart /
                    // FlowMappingStart so we can splice Key (and
                    // optionally BlockMappingStart) BEFORE the entire
                    // flow container.
                    $depth = 0;
                    $startIdx = null;
                    for ($i = count($tokens) - 1; $i >= 0; $i--) {
                        $tt = $tokens[$i]->type;
                        if ($tt === TokenType::FlowSequenceEnd
                            || $tt === TokenType::FlowMappingEnd
                        ) {
                            $depth++;
                            continue;
                        }
                        if ($tt === TokenType::FlowSequenceStart
                            || $tt === TokenType::FlowMappingStart
                        ) {
                            $depth--;
                            if ($depth === 0) {
                                $startIdx = $i;
                                break;
                            }
                        }
                    }
                    if ($startIdx !== null) {
                        $startTok = $tokens[$startIdx];
                        // The entry's host indent is the flow's
                        // opening column - 1, not the colon's column.
                        // Used for both the indent stack push and
                        // the deeper-next-line value detection
                        // (yaml-test-suite Q9WF).
                        $effectiveIndent = $startTok->column - 1;
                        $before = array_slice($tokens, 0, $startIdx);
                        $flow = array_slice($tokens, $startIdx);
                        $tokens = $before;
                        if ($needPush) {
                            $this->indentStack[] = [$effectiveIndent, 'map'];
                            $tokens[] = $this->makeStructural(
                                TokenType::BlockMappingStart,
                                $startTok->line,
                                $startTok->column,
                            );
                        }
                        $tokens[] = new Token(
                            type: TokenType::Key,
                            line: $startTok->line,
                            column: $startTok->column,
                        );
                        foreach ($flow as $ftok) {
                            $tokens[] = $ftok;
                        }
                    } elseif ($needPush) {
                        $this->indentStack[] = [$effectiveIndent, 'map'];
                        $tokens[] = $this->makeStructural(
                            TokenType::BlockMappingStart,
                            $this->line,
                            $this->column,
                        );
                    }
                } elseif ($needPush) {
                    $this->indentStack[] = [$effectiveIndent, 'map'];
                    $tokens[] = $this->makeStructural(
                        TokenType::BlockMappingStart,
                        $this->line,
                        $this->column,
                    );
                }
                $this->pendingPropertyColumn = null;
                if ($flowAsKey) {
                    // The flow container has already been emitted as
                    // the key node; emit only the Value indicator.
                    $this->advance(); // consume `:`
                    if ($this->pos < $this->length
                        && ($this->source[$this->pos] === ' '
                            || $this->source[$this->pos] === "\t")
                    ) {
                        $this->advance();
                    }
                    $tokens[] = new Token(
                        type: TokenType::Value,
                        line: $this->line,
                        column: $this->column,
                    );
                    // Skip a trailing EOL comment so the empty-value
                    // path fires for `: # comment\n  content` shapes
                    // (yaml-test-suite Q9WF).
                    if ($this->pos < $this->length && $this->source[$this->pos] === '#') {
                        while ($this->pos < $this->length && $this->source[$this->pos] !== "\n") {
                            $this->advance();
                        }
                    }
                    if ($this->pos >= $this->length || $this->source[$this->pos] === "\n") {
                        // Advance past the newline and look at the
                        // next line. If indented MORE than this
                        // entry's effectiveIndent, the value is a
                        // nested block. Let the outer scanner
                        // produce it (yaml-test-suite Q9WF). If at
                        // or shallower, this entry's value is empty.
                        if ($this->pos < $this->length && $this->source[$this->pos] === "\n") {
                            $this->advance();
                        }
                        $nextIndent = $this->peekNextLineIndent();
                        if ($nextIndent !== null && $nextIndent > $effectiveIndent) {
                            // Nested block ahead. The scan loop
                            // produces the BlockMappingStart and
                            // entries.
                            continue;
                        }
                        $tokens[] = new Token(
                            type: TokenType::Scalar,
                            line: $this->line,
                            column: $this->column,
                            value: '',
                            style: ScalarStyle::Plain,
                        );
                    } else {
                        $tokens[] = $this->consumeScalar();
                    }
                    continue;
                }
                $this->scanEmptyKeyEntry($tokens, $effectiveIndent);
                continue;
            }

            throw new ParseException(sprintf(
                'Unexpected character %s at line %d column %d',
                $this->describeChar($this->source[$this->pos]),
                $this->line,
                $this->column,
            ));
        }

        // Close any remaining open block contexts.
        $this->emitBlockEndsTo(-1, $tokens);

        $tokens[] = $this->makeStructural(TokenType::StreamEnd, $this->line, $this->column);

        return $tokens;
    }

    /**
     * Emit BlockEnd tokens for every open block context whose indent
     * exceeds $targetIndent. Pass -1 to close all open contexts.
     *
     * @param list<Token> $tokens
     */
    private function emitBlockEndsTo(int $targetIndent, array &$tokens): void
    {
        $popped = false;
        while ($this->indentStack !== [] && end($this->indentStack)[0] > $targetIndent) {
            array_pop($this->indentStack);
            $tokens[] = new Token(
                type: TokenType::BlockEnd,
                line: $this->line,
                column: $this->column,
            );
            $popped = true;
        }
        // Any pending property column captured at a now-popped indent
        // is no longer relevant. Clear it so subsequent lines at a
        // shallower indent don't inherit a stale host column
        // (yaml-test-suite XW4D: `&node` inside a deeper context
        // bleeding into `block:` at column 1).
        if ($popped) {
            $this->pendingPropertyColumn = null;
        }
    }

    /**
     * Pop a sibling block sequence from the indent stack when about
     * to start a block mapping entry at the same indent. Per YAML 1.2
     * §8.1.1 a block sequence may share its parent mapping's column;
     * when the next entry is the parent mapping's next key, the inner
     * sequence must close before the new mapping entry begins.
     *
     * Only pops when an enclosing block mapping at the same indent
     * exists below the sequence on the stack. Otherwise the sequence
     * is the document root and switching to a mapping at the same
     * indent is a parse error left to the parser to surface.
     *
     * @param list<Token> $tokens
     */
    private function popSiblingSequenceAt(int $indent, array &$tokens): void
    {
        // Verify there is a block mapping at the same indent below
        // the topmost sequence at this indent.
        $hasParentMap = false;
        for ($i = count($this->indentStack) - 1; $i >= 0; $i--) {
            [$ind, $kind] = $this->indentStack[$i];
            if ($ind !== $indent) {
                break;
            }
            if ($kind === 'map') {
                $hasParentMap = true;
                break;
            }
        }
        if (!$hasParentMap) {
            return;
        }
        while ($this->indentStack !== []) {
            $top = end($this->indentStack);
            if ($top[0] !== $indent || $top[1] !== 'seq') {
                return;
            }
            array_pop($this->indentStack);
            $tokens[] = new Token(
                type: TokenType::BlockEnd,
                line: $this->line,
                column: $this->column,
            );
        }
    }

    /**
     * Is the byte at $i a block-sequence `-` indicator? (i.e. `-`
     * followed by space, tab, or newline.)
     */
    private function isBlockSequenceIndicatorAt(int $i): bool
    {
        if (($this->source[$i] ?? '') !== '-') {
            return false;
        }
        $next = $this->source[$i + 1] ?? "\n";
        return $next === ' ' || $next === "\t" || $next === "\n";
    }

    private function isExplicitKeyIndicatorAt(int $i): bool
    {
        if (($this->source[$i] ?? '') !== '?') {
            return false;
        }
        $next = $this->source[$i + 1] ?? "\n";
        return $next === ' ' || $next === "\t" || $next === "\n";
    }

    private function isValueIndicatorAt(int $i): bool
    {
        if (($this->source[$i] ?? '') !== ':') {
            return false;
        }
        $next = $this->source[$i + 1] ?? "\n";
        return $next === ' ' || $next === "\t" || $next === "\n";
    }

    /**
     * Scan one block-sequence item.
     *
     * Emits BlockEntry for the `-` indicator, then the item value
     * (in D.01 scope: a plain scalar on the same line, or empty if
     * the line ends after the `-`). Nested mappings/sequences as
     * sequence values arrive in D.04.
     *
     * @param list<Token> $tokens
     */
    private function scanBlockSequenceItem(array &$tokens): void
    {
        $dashLine = $this->line;
        $dashColumn = $this->column;
        $dashIndent = $this->column - 1;

        $this->advance(); // consume `-`

        $tokens[] = new Token(
            type: TokenType::BlockEntry,
            line: $dashLine,
            column: $dashColumn,
            leadingTrivia: $this->flushTrivia(),
        );

        // Skip inter-token whitespace (space or tab, one or more)
        // after the dash. Per YAML 1.2 §6.4 `s-separate-in-line`
        // is `s-white+`. Tab is a valid separator after a block
        // indicator (yaml-test-suite A2M4), and `- - x` may have
        // multiple spaces between the two dashes.
        while ($this->pos < $this->length
            && ($this->source[$this->pos] === ' '
                || $this->source[$this->pos] === "\t")
        ) {
            $this->advance();
        }

        // Skip a trailing `#...` line comment so the empty-value
        // newline check below fires for `-  # comment\n` (per
        // YAML 1.2 §6.6 a comment ends the line). The comment text is
        // captured as trivia for the next token.
        if ($this->pos < $this->length && $this->source[$this->pos] === '#') {
            $commentLine = $this->line;
            $commentColumn = $this->column;
            $text = '';
            while ($this->pos < $this->length && $this->source[$this->pos] !== "\n") {
                $text .= $this->source[$this->pos];
                $this->advance();
            }
            $this->triviaBuffer[] = TriviaToken::comment(
                $text,
                $commentLine,
                $commentColumn,
            );
        }

        // Empty value? End of line means either an empty scalar or a
        // nested block (depending on next-line indent).
        if ($this->pos >= $this->length || $this->source[$this->pos] === "\n") {
            if ($this->pos < $this->length && $this->source[$this->pos] === "\n") {
                $this->advance();
            }
            $nextIndent = $this->peekNextLineIndent();
            if ($nextIndent !== null && $nextIndent > $dashIndent) {
                // Nested block: usually the outer scan loop produces
                // it. But when scanBlockSequenceItem is called from
                // scanCompoundContent (which has its own seq loop and
                // does NOT redispatch to the outer scanner), the
                // deeper content needs to be consumed inline as the
                // entry's value or it gets miscategorised as a
                // sibling that ends the seq (yaml-test-suite RZP5,
                // XW4D: `- #comment\n  content`).
                if ($this->insideCompoundContent) {
                    $this->scanCompoundContent($tokens, $dashIndent);
                }
                return;
            }
            $tokens[] = new Token(
                type: TokenType::Scalar,
                line: $this->line,
                column: $this->column,
                value: '',
                style: ScalarStyle::Plain,
            );
            return;
        }

        // The item value can be a plain scalar, a flow container, or
        // a block mapping (block mapping starting on the same line as
        // the `-`, with the first key right after the dash).
        //
        // The flow-container check has to come BEFORE the
        // lineStartsBlockMappingEntry check: a line like
        //   - { name: Jan, email: j@x }
        // contains a `: ` and would otherwise be misclassified as a
        // block-mapping key, with `{ name` as the key string.
        if ($this->source[$this->pos] === '['
            || $this->source[$this->pos] === '{'
        ) {
            $this->scanFlowContainer($tokens);
            if ($this->pos < $this->length && $this->source[$this->pos] === "\n") {
                $this->advance();
            }
            return;
        }

        // Nested block sequence on the same line: `- - item`.
        if ($this->source[$this->pos] === '-'
            && $this->isBlockSequenceIndicatorAt($this->pos)
        ) {
            // Push the inner sequence indent (column of the inner
            // dash) and recurse via the outer scan loop's
            // BlockSequenceStart path. Caller's loop emits the inner
            // start; we just leave position at the inner `-`.
            return;
        }

        // Explicit-key entry on the same line as the `-`:
        //   `- ? key\n  : value`
        // The map's indent is the column of the `?` indicator.
        // yaml-test-suite V9D5.
        if ($this->source[$this->pos] === '?'
            && $this->isExplicitKeyIndicatorAt($this->pos)
        ) {
            $mapIndent = $this->column - 1;
            $top = $this->indentStack === [] ? null : end($this->indentStack);
            if ($top === null || $top[0] < $mapIndent || $top[1] !== 'map') {
                $this->indentStack[] = [$mapIndent, 'map'];
                $tokens[] = $this->makeStructural(
                    TokenType::BlockMappingStart,
                    $this->line,
                    $this->column,
                );
            }
            $this->scanExplicitKeyEntry($tokens, $mapIndent);
            return;
        }

        if ($this->lineStartsBlockMappingEntry($this->column - 1)) {
            // Block mapping starting on the same line as the `-`.
            // The map's indent is the column of the first key.
            $mapIndent = $this->column - 1;
            $top = $this->indentStack === [] ? null : end($this->indentStack);
            if ($top === null || $top[0] < $mapIndent || $top[1] !== 'map') {
                $this->indentStack[] = [$mapIndent, 'map'];
                $tokens[] = $this->makeStructural(
                    TokenType::BlockMappingStart,
                    $this->line,
                    $this->column,
                );
            }
            $this->scanBlockMapEntry($tokens);
            return;
        }

        // Anchor / alias / tag preceding the value.
        if ($this->source[$this->pos] === '&'
            || $this->source[$this->pos] === '*'
            || $this->source[$this->pos] === '!'
        ) {
            $this->scanProperties($tokens);
            // Skip trailing whitespace / EOL comment so the
            // newline-only fall-through below fires correctly when
            // the property has no inline value (`- !!map # comment`
            // with the value on the next indented line).
            while ($this->pos < $this->length
                && ($this->source[$this->pos] === ' '
                    || $this->source[$this->pos] === "\t")
            ) {
                $this->advance();
            }
            if ($this->pos < $this->length && $this->source[$this->pos] === '#') {
                while ($this->pos < $this->length && $this->source[$this->pos] !== "\n") {
                    $this->advance();
                }
            }
        }

        // Flow container as sequence-item value, after properties.
        if ($this->pos < $this->length
            && ($this->source[$this->pos] === '['
                || $this->source[$this->pos] === '{')
        ) {
            $this->scanFlowContainer($tokens);
            if ($this->pos < $this->length && $this->source[$this->pos] === "\n") {
                $this->advance();
            }
            return;
        }

        if ($this->canStartScalar()) {
            $tokens[] = $this->consumeScalar();
            return;
        }

        // After scanProperties an alias may stand alone (no following
        // scalar), in which case the previous loop already emitted the
        // Alias token and we're done.
        if ($this->pos >= $this->length || $this->source[$this->pos] === "\n") {
            if ($this->pos < $this->length) {
                $this->advance();
            }
            return;
        }

        throw new ParseException(sprintf(
            'Expected scalar value or nested structure after `-` at line %d column %d',
            $this->line,
            $this->column,
        ));
    }

    /**
     * Look ahead from the current position past one or more
     * `&anchor` / `!tag` properties (which may be inline, separated
     * by spaces, or split across lines) to see whether the upcoming
     * non-property token introduces a block-mapping key (i.e. ends
     * with `: ` on its line). Used to detect anchored/tagged keys at
     * a deeper indent than the surrounding container so the scanner
     * can pre-emit BlockMappingStart in front of the property.
     */
    private function propertySequenceLeadsToBlockKey(int $indent): bool
    {
        $i = $this->pos;
        while ($i < $this->length) {
            $c = $this->source[$i];
            // Skip over property tokens (anchor, alias, or tag) and
            // their trailing whitespace.
            if ($c === '&' || $c === '*' || $c === '!') {
                $i++;
                while ($i < $this->length) {
                    $cc = $this->source[$i];
                    if ($cc === ' ' || $cc === "\t" || $cc === "\n"
                        || $cc === ',' || $cc === '[' || $cc === ']'
                        || $cc === '{' || $cc === '}'
                    ) {
                        break;
                    }
                    $i++;
                }
                continue;
            }
            if ($c === ' ' || $c === "\t") {
                $i++;
                continue;
            }
            // After a `\n`, only continue if the next line starts at
            // the SAME indent as the property (otherwise it's a
            // dedent and not part of this property sequence).
            if ($c === "\n") {
                $i++;
                $thisIndent = 0;
                while ($i < $this->length && $this->source[$i] === ' ') {
                    $thisIndent++;
                    $i++;
                }
                if ($thisIndent !== $indent) {
                    return false;
                }
                continue;
            }
            // Non-property, non-whitespace, non-newline. Reuse the
            // existing helper to test whether this position starts a
            // block-mapping entry (`key: ...`).
            $savedPos = $this->pos;
            $this->pos = $i;
            $isMapEntry = $this->lineStartsBlockMappingEntry($indent);
            $this->pos = $savedPos;
            return $isMapEntry;
        }
        return false;
    }

    /**
     * Look ahead from the current position to determine whether this
     * line starts a block-mapping entry: a plain or quoted scalar
     * followed by `:` followed by whitespace or end-of-line.
     */
    private function lineStartsBlockMappingEntry(int $indent): bool
    {
        $i = $this->pos;

        // Skip a quoted scalar (single or double). Quoted keys are
        // common.
        if ($i < $this->length && $this->source[$i] === "'") {
            $i++;
            while ($i < $this->length) {
                $c = $this->source[$i];
                if ($c === "\n") {
                    return false;
                }
                if ($c === "'") {
                    if (($this->source[$i + 1] ?? '') === "'") {
                        $i += 2;
                        continue;
                    }
                    $i++;
                    break;
                }
                $i++;
            }
        } elseif ($i < $this->length && $this->source[$i] === '"') {
            $i++;
            while ($i < $this->length) {
                $c = $this->source[$i];
                if ($c === "\n") {
                    return false;
                }
                if ($c === '\\') {
                    // Skip escape sequence (basic; full handling in
                    // E.02).
                    $i += 2;
                    continue;
                }
                if ($c === '"') {
                    $i++;
                    break;
                }
                $i++;
            }
        }

        while ($i < $this->length) {
            $c = $this->source[$i];

            if ($c === "\n") {
                return false;
            }
            // Don't get tripped up by `#` end-of-line comments.
            if ($c === '#' && $i > $this->pos
                && ($this->source[$i - 1] === ' ' || $this->source[$i - 1] === "\t")
            ) {
                return false;
            }
            // A `: ` (colon followed by space, tab, or newline)
            // signals a key boundary.
            if ($c === ':') {
                $next = $this->source[$i + 1] ?? "\n";
                if ($next === ' ' || $next === "\t" || $next === "\n") {
                    return true;
                }
            }
            $i++;
        }
        return false;
    }

    /**
     * Scan one block-mapping entry: implicit Key token, plain-scalar
     * key, Value token after `:`, optional plain-scalar value or
     * nested block (which the outer scan loop produces from the
     * indent of subsequent lines).
     *
     * @param list<Token> $tokens
     */
    /**
     * Scan one block-mapping entry introduced by `? ` (explicit-key
     * indicator). Supports the common form used for sets:
     *
     *   ? scalarKey
     *   : scalarValue
     *
     * Or with implicit empty value (matches `!!set` member lists):
     *
     *   ? memberA
     *   ? memberB
     *
     * @param list<Token> $tokens
     */
    private function scanExplicitKeyEntry(array &$tokens, int $entryIndent): void
    {
        $qLine = $this->line;
        $qColumn = $this->column;
        $this->advance(); // consume `?`
        // Skip exactly one whitespace after `?`.
        if ($this->pos < $this->length
            && ($this->source[$this->pos] === ' ' || $this->source[$this->pos] === "\t")
        ) {
            $this->advance();
        }

        $tokens[] = new Token(
            type: TokenType::Key,
            line: $qLine,
            column: $qColumn,
            leadingTrivia: $this->flushTrivia(),
        );

        // Skip an inline EOL comment after `?` so the empty-key
        // path correctly fires.
        if ($this->pos < $this->length && $this->source[$this->pos] === '#') {
            while ($this->pos < $this->length && $this->source[$this->pos] !== "\n") {
                $this->advance();
            }
        }

        $compoundKeyEmitted = false;
        // Empty key on the same line as `?`. Decide whether the key
        // is truly empty (no indented content follows) or a compound
        // key (`?\n  - a\n  - b\n: value`, where the indented content
        // is the key). Per YAML 1.2 §8.1.3.
        if ($this->pos >= $this->length || $this->source[$this->pos] === "\n") {
            // Advance past the newline.
            if ($this->pos < $this->length && $this->source[$this->pos] === "\n") {
                $this->advance();
            }
            $nextIndent = $this->peekNextLineIndent();
            // A block sequence may indent to the SAME column as the
            // `?` indicator and still be the explicit key (YAML 1.2
            // §8.1.3). Other content types must be deeper.
            $sequenceAtSameIndent = $nextIndent !== null
                && $nextIndent === $entryIndent
                && $this->nextLineStartsBlockSequence($nextIndent);
            if (($nextIndent !== null && $nextIndent > $entryIndent)
                || $sequenceAtSameIndent
            ) {
                // Compound key. The upcoming indented block is the
                // key. Recursively scan content until we hit a line
                // at entryIndent starting with `:` or `?` or
                // dedented past entryIndent. The pushed indent
                // context bounds the recursion.
                $this->scanCompoundKeyOrValue($tokens, $entryIndent);
                $compoundKeyEmitted = true;
            } else {
                $tokens[] = new Token(
                    type: TokenType::Scalar,
                    line: $this->line,
                    column: $this->column,
                    value: '',
                    style: ScalarStyle::Plain,
                );
            }
        } elseif ($this->source[$this->pos] === '-'
            && $this->isBlockSequenceIndicatorAt($this->pos)
        ) {
            // Inline compound key starting with a block sequence on
            // the same line as `?`: `? - item1\n  - item2`. The
            // sequence's indent is the column of this `-`.
            $this->scanCompoundKeyOrValue($tokens, $entryIndent);
            $compoundKeyEmitted = true;
        } elseif ($this->source[$this->pos] === '['
            || $this->source[$this->pos] === '{'
        ) {
            // Inline compound key with a flow container:
            // `? []: x` (yaml-test-suite M2N8/01).
            $this->scanFlowContainer($tokens);
            $compoundKeyEmitted = true;
        } elseif ($this->lineStartsBlockMappingEntry($this->column - 1)) {
            // Inline compound key that is itself a block mapping:
            // `? earth: blue\n  : moon: white`, where `earth: blue`
            // is a single-entry mapping serving as the key. Per
            // yaml-test-suite V9D5.
            $keyMapIndent = $this->column - 1;
            $this->indentStack[] = [$keyMapIndent, 'map'];
            $tokens[] = $this->makeStructural(
                TokenType::BlockMappingStart,
                $this->line,
                $this->column,
            );
            $this->scanBlockMapEntry($tokens);
            array_pop($this->indentStack);
            $tokens[] = new Token(
                type: TokenType::BlockEnd,
                line: $this->line,
                column: $this->column,
            );
            $compoundKeyEmitted = true;
        } else {
            $tokens[] = $this->consumeScalar();
        }

        // Look for an optional `: value` following on a subsequent
        // line, indented at $entryIndent (same as the `?` column).
        $savedPos = $this->pos;
        $savedLine = $this->line;
        $savedColumn = $this->column;

        // Skip blank lines and trivia.
        while ($this->pos < $this->length) {
            $c = $this->source[$this->pos];
            if ($c === ' ' || $c === "\t" || $c === "\n") {
                $this->advance();
                continue;
            }
            break;
        }

        // Did we land on a `:` at the right indent? Either at
        // entryIndent column on a subsequent line (`? key\n: value`)
        // or on the SAME line as `?` (inline form `? key: value`,
        // yaml-test-suite M2N8/01).
        $colonColumn = $this->column - 1;
        $colonOnSameLine = $this->line === $qLine;
        if ($this->pos < $this->length
            && $this->source[$this->pos] === ':'
            && ($colonColumn === $entryIndent || $colonOnSameLine)
        ) {
            // Following character must be whitespace or newline.
            $next = $this->source[$this->pos + 1] ?? "\n";
            if ($next === ' ' || $next === "\t" || $next === "\n") {
                $vLine = $this->line;
                $vColumn = $this->column;
                $this->advance(); // consume `:`
                if ($this->pos < $this->length
                    && ($this->source[$this->pos] === ' ' || $this->source[$this->pos] === "\t")
                ) {
                    $this->advance();
                }
                $tokens[] = new Token(
                    type: TokenType::Value,
                    line: $vLine,
                    column: $vColumn,
                );
                // Skip a trailing EOL comment after `: ` so the
                // empty-value path fires for `: # comment\n  - x`.
                if ($this->pos < $this->length && $this->source[$this->pos] === '#') {
                    while ($this->pos < $this->length && $this->source[$this->pos] !== "\n") {
                        $this->advance();
                    }
                }
                if ($this->pos >= $this->length || $this->source[$this->pos] === "\n") {
                    // Advance past the newline and look for an
                    // indented compound value.
                    if ($this->pos < $this->length && $this->source[$this->pos] === "\n") {
                        $this->advance();
                    }
                    $valueIndent = $this->peekNextLineIndent();
                    $valueSequenceAtSameIndent = $valueIndent !== null
                        && $valueIndent === $entryIndent
                        && $this->nextLineStartsBlockSequence($valueIndent);
                    if (($valueIndent !== null && $valueIndent > $entryIndent)
                        || $valueSequenceAtSameIndent
                    ) {
                        $this->scanCompoundKeyOrValue($tokens, $entryIndent);
                    } else {
                        $tokens[] = new Token(
                            type: TokenType::Scalar,
                            line: $this->line,
                            column: $this->column,
                            value: '',
                            style: ScalarStyle::Plain,
                        );
                    }
                } elseif ($this->source[$this->pos] === '-'
                    && $this->isBlockSequenceIndicatorAt($this->pos)
                ) {
                    // Inline compound value: `: - item\n  - item`.
                    // The sequence's indent is the column of this
                    // `-` (which is greater than entryIndent).
                    $this->scanCompoundKeyOrValue($tokens, $entryIndent);
                } elseif ($this->source[$this->pos] === '['
                    || $this->source[$this->pos] === '{'
                ) {
                    // Inline flow container as compound value.
                    $this->scanFlowContainer($tokens);
                } elseif ($this->lineStartsBlockMappingEntry($this->column - 1)) {
                    // Inline compound value that is itself a block
                    // mapping: `: moon: white`. yaml-test-suite V9D5.
                    $valueMapIndent = $this->column - 1;
                    $this->indentStack[] = [$valueMapIndent, 'map'];
                    $tokens[] = $this->makeStructural(
                        TokenType::BlockMappingStart,
                        $this->line,
                        $this->column,
                    );
                    $this->scanBlockMapEntry($tokens);
                    array_pop($this->indentStack);
                    $tokens[] = new Token(
                        type: TokenType::BlockEnd,
                        line: $this->line,
                        column: $this->column,
                    );
                } else {
                    $tokens[] = $this->consumeScalar();
                }
                return;
            }
        }

        // No `:` line follows. Implicit empty value (treated as null).
        // Roll back to the saved position so the outer loop can pick
        // up the next entry.
        $this->pos = $savedPos;
        $this->line = $savedLine;
        $this->column = $savedColumn;
        $tokens[] = new Token(
            type: TokenType::Value,
            line: $qLine,
            column: $qColumn,
        );
        $tokens[] = new Token(
            type: TokenType::Scalar,
            line: $qLine,
            column: $qColumn,
            value: '',
            style: ScalarStyle::Plain,
        );
    }

    /**
     * Recursively scan content for an explicit-key compound key or
     * value. Pushes a temporary indent boundary so the outer scan
     * loop returns control to us when it dedents back to the entry
     * indent (where `:` or `?` introduces the next part of the
     * outer mapping entry).
     *
     * @param list<Token> $tokens
     */
    private function scanCompoundKeyOrValue(array &$tokens, int $entryIndent): void
    {
        // Push an end-of-content sentinel: the inner content's
        // indent is at least entryIndent+1. We mark the boundary so
        // the outer scan loop (which is recursive via scanContent)
        // emits BlockEnd and returns when it dedents below it.
        $startTokenCount = count($tokens);
        $this->scanCompoundContent($tokens, $entryIndent);
        // If the recursive scan emitted nothing (e.g. only blanks),
        // attach an empty scalar so the parser's expected
        // Key->Node->Value->Node shape is preserved.
        if (count($tokens) === $startTokenCount) {
            $tokens[] = new Token(
                type: TokenType::Scalar,
                line: $this->line,
                column: $this->column,
                value: '',
                style: ScalarStyle::Plain,
            );
        }
    }

    /**
     * Scan one node (block mapping, block sequence, flow container
     * or scalar) at indent > $entryIndent. Stops when the next line
     * dedents back to $entryIndent or shallower. Reuses the outer
     * scan's per-line dispatch by running it inline.
     *
     * @param list<Token> $tokens
     */
    private function scanCompoundContent(array &$tokens, int $entryIndent): void
    {
        $wasInside = $this->insideCompoundContent;
        $this->insideCompoundContent = true;
        try {
            $this->scanCompoundContentInner($tokens, $entryIndent);
        } finally {
            $this->insideCompoundContent = $wasInside;
        }
    }

    private function scanCompoundContentInner(array &$tokens, int $entryIndent): void
    {
        // Determine the actual content indent (first non-blank line).
        while ($this->pos < $this->length) {
            $c = $this->source[$this->pos];
            if ($c === "\n") {
                $this->advance();
                continue;
            }
            if ($c === ' ' || $c === "\t") {
                $this->advance();
                continue;
            }
            break;
        }
        if ($this->pos >= $this->length) {
            return;
        }
        $contentIndent = $this->column - 1;
        // A block sequence may indent to the same column as the
        // explicit-key indicator (YAML 1.2 §8.1.3). Other content
        // types must be strictly deeper.
        $isSequenceAtSameIndent = $contentIndent === $entryIndent
            && $this->source[$this->pos] === '-'
            && $this->isBlockSequenceIndicatorAt($this->pos);
        if ($contentIndent <= $entryIndent && !$isSequenceAtSameIndent) {
            return;
        }

        // Dispatch on the leading character of the content node.
        $c = $this->source[$this->pos];

        if ($c === '-' && $this->isBlockSequenceIndicatorAt($this->pos)) {
            $this->indentStack[] = [$contentIndent, 'seq'];
            $tokens[] = $this->makeStructural(
                TokenType::BlockSequenceStart,
                $this->line,
                $this->column,
            );
            // Repeatedly scan block-sequence items until we dedent
            // below contentIndent.
            while ($this->pos < $this->length) {
                // Skip blank lines / trivia.
                while ($this->pos < $this->length
                    && ($this->source[$this->pos] === ' '
                        || $this->source[$this->pos] === "\t"
                        || $this->source[$this->pos] === "\n")
                ) {
                    if ($this->atLineStart() && $this->source[$this->pos] === "\n") {
                        $this->advance();
                        continue;
                    }
                    $this->advance();
                }
                if ($this->pos >= $this->length) {
                    break;
                }
                $thisIndent = $this->column - 1;
                if ($thisIndent < $contentIndent) {
                    break;
                }
                if ($this->source[$this->pos] !== '-'
                    || !$this->isBlockSequenceIndicatorAt($this->pos)
                ) {
                    break;
                }
                // A deeper `-` at the SAME line position (after the
                // previous entry's nested-on-same-line return)
                // requires a nested sequence. Recurse via
                // scanCompoundContent so the inner items are
                // properly grouped under their own
                // BlockSequenceStart (yaml-test-suite A2M4:
                // `-  -<TAB>c`).
                if ($thisIndent > $contentIndent) {
                    $this->scanCompoundContent($tokens, $contentIndent);
                    continue;
                }
                $this->scanBlockSequenceItem($tokens);
            }
            // Pop and emit BlockEnd.
            array_pop($this->indentStack);
            $tokens[] = new Token(
                type: TokenType::BlockEnd,
                line: $this->line,
                column: $this->column,
            );
            return;
        }

        if ($c === '[' || $c === '{') {
            $this->scanFlowContainer($tokens);
            return;
        }

        if ($c === '|' && $this->isBlockScalarHeaderAt($this->pos)) {
            $tokens[] = $this->consumeBlockScalar(folded: false);
            return;
        }
        if ($c === '>' && $this->isBlockScalarHeaderAt($this->pos)) {
            $tokens[] = $this->consumeBlockScalar(folded: true);
            return;
        }

        // Block mapping starting on this line: `key: value` (possibly
        // followed by more entries on subsequent lines at
        // contentIndent). yaml-test-suite V9D5 / M2N8/00 / M2N8/01.
        if ($this->canStartScalar()
            && $this->lineStartsBlockMappingEntry($contentIndent)
        ) {
            $this->indentStack[] = [$contentIndent, 'map'];
            $tokens[] = $this->makeStructural(
                TokenType::BlockMappingStart,
                $this->line,
                $this->column,
            );
            $this->scanBlockMapEntry($tokens);
            // Continue with subsequent entries at the same indent.
            while ($this->pos < $this->length) {
                while ($this->pos < $this->length
                    && ($this->source[$this->pos] === ' '
                        || $this->source[$this->pos] === "\t"
                        || $this->source[$this->pos] === "\n")
                ) {
                    $this->advance();
                }
                if ($this->pos >= $this->length) {
                    break;
                }
                $thisIndent = $this->column - 1;
                if ($thisIndent !== $contentIndent) {
                    break;
                }
                if (!$this->canStartScalar()
                    || !$this->lineStartsBlockMappingEntry($contentIndent)
                ) {
                    break;
                }
                $this->scanBlockMapEntry($tokens);
            }
            array_pop($this->indentStack);
            $tokens[] = new Token(
                type: TokenType::BlockEnd,
                line: $this->line,
                column: $this->column,
            );
            return;
        }

        // Bare `:` block-mapping entry (empty implicit key) on this
        // line: `: value` (yaml-test-suite M2N8/00 `- ? : x`).
        if ($c === ':' && $this->isValueIndicatorAt($this->pos)) {
            $this->indentStack[] = [$contentIndent, 'map'];
            $tokens[] = $this->makeStructural(
                TokenType::BlockMappingStart,
                $this->line,
                $this->column,
            );
            $this->scanEmptyKeyEntry($tokens, $contentIndent);
            array_pop($this->indentStack);
            $tokens[] = new Token(
                type: TokenType::BlockEnd,
                line: $this->line,
                column: $this->column,
            );
            return;
        }

        // Plain or quoted scalar (single-line for now; multi-line
        // compound keys with mappings are out of scope here).
        if ($this->canStartScalar()) {
            $tokens[] = $this->consumeScalar();
            return;
        }

        // Unsupported compound shape. Fall through silently. The
        // calling scanCompoundKeyOrValue will emit an empty scalar.
    }

    /**
     * Scan a block-mapping entry whose key is empty: a line starting
     * with `: value` per YAML 1.2 §8.1.2. Emits Key + empty-Scalar +
     * Value + value-scalar tokens.
     *
     * @param list<Token> $tokens
     */
    private function scanEmptyKeyEntry(array &$tokens, int $entryIndent): void
    {
        $colonLine = $this->line;
        $colonColumn = $this->column;

        // If the immediately preceding token is an Alias, treat it
        // as the key node: insert Key BEFORE the alias and skip the
        // empty Scalar (yaml-test-suite E76Z: `*b : *a` is an alias as
        // mapping key). An Alias preceded by an Anchor or Tag is
        // invalid (yaml-test-suite SU74: `&b *alias : value`).
        $aliasIsKey = false;
        if ($tokens !== []
            && $tokens[count($tokens) - 1]->type === TokenType::Alias
        ) {
            $beforeAlias = count($tokens) >= 2
                ? $tokens[count($tokens) - 2]->type
                : null;
            if ($beforeAlias === TokenType::Anchor
                || $beforeAlias === TokenType::Tag
            ) {
                $aliasToken = $tokens[count($tokens) - 1];
                throw new ParseException(sprintf(
                    'Alias node may not be anchored or tagged at line %d column %d',
                    $aliasToken->line,
                    $aliasToken->column,
                ));
            }
            $aliasIsKey = true;
            $aliasToken = array_pop($tokens);
            $tokens[] = new Token(
                type: TokenType::Key,
                line: $aliasToken->line,
                column: $aliasToken->column,
                leadingTrivia: $aliasToken->leadingTrivia,
            );
            $tokens[] = $aliasToken;
        } else {
            // Implicit Key marker with empty Scalar.
            $tokens[] = new Token(
                type: TokenType::Key,
                line: $colonLine,
                column: $colonColumn,
                leadingTrivia: $this->flushTrivia(),
            );
            $tokens[] = new Token(
                type: TokenType::Scalar,
                line: $colonLine,
                column: $colonColumn,
                value: '',
                style: ScalarStyle::Plain,
            );
        }

        // Consume the `:` and emit Value.
        $this->advance();
        $tokens[] = new Token(
            type: TokenType::Value,
            line: $colonLine,
            column: $colonColumn,
        );

        // Skip a single space/tab after the colon.
        if ($this->pos < $this->length
            && ($this->source[$this->pos] === ' ' || $this->source[$this->pos] === "\t")
        ) {
            $this->advance();
        }

        // Empty value (just `:` on the line).
        if ($this->pos >= $this->length || $this->source[$this->pos] === "\n") {
            $tokens[] = new Token(
                type: TokenType::Scalar,
                line: $this->line,
                column: $this->column,
                value: '',
                style: ScalarStyle::Plain,
            );
            if ($this->pos < $this->length && $this->source[$this->pos] === "\n") {
                $this->advance();
            }
            return;
        }

        // Otherwise consume the value scalar.
        $tokens[] = $this->consumeScalar();
    }

    private function scanBlockMapEntry(array &$tokens): void
    {
        // The "key indent" used to decide whether the next line is a
        // nested block (greater) or a sibling/dedent (≤) is the
        // enclosing mapping's indent, NOT the key scalar's column.
        // When properties precede the key (e.g. HMQ5's
        // `!!str &a1 "foo":`) the key sits to the right of the
        // mapping's actual indent.
        $keyIndent = $this->indentStack === []
            ? $this->column - 1
            : end($this->indentStack)[0];

        // Implicit Key marker (positionally just before the key
        // scalar).
        $tokens[] = new Token(
            type: TokenType::Key,
            line: $this->line,
            column: $this->column,
        );

        $tokens[] = $this->consumeScalar(stopAtColon: true);

        // Now we expect `:`.
        if ($this->pos >= $this->length || $this->source[$this->pos] !== ':') {
            throw new ParseException(sprintf(
                'Expected `:` after key at line %d column %d',
                $this->line,
                $this->column,
            ));
        }
        $colonLine = $this->line;
        $colonColumn = $this->column;
        $this->advance();

        $tokens[] = new Token(
            type: TokenType::Value,
            line: $colonLine,
            column: $colonColumn,
        );

        // Skip whitespace (one or more spaces/tabs) after the colon.
        // YAML 1.2 §8.1.2 allows aligned-column formatting with
        // multiple spaces between the colon and the value. Capture
        // the bytes so an EOL comment that follows can record its
        // gap for byte-identical round-trip.
        $gap = '';
        while ($this->pos < $this->length
            && ($this->source[$this->pos] === ' ' || $this->source[$this->pos] === "\t")
        ) {
            $gap .= $this->source[$this->pos];
            $this->advance();
        }

        // Trailing comment after `:` introduces an empty value;
        // consume the comment to end of line so it becomes trivia
        // for the following content.
        if ($this->pos < $this->length && $this->source[$this->pos] === '#') {
            $commentLine = $this->line;
            $commentColumn = $this->column;
            $text = '';
            while ($this->pos < $this->length && $this->source[$this->pos] !== "\n") {
                $text .= $this->source[$this->pos];
                $this->advance();
            }
            $this->triviaBuffer[] = TriviaToken::comment(
                $text,
                $commentLine,
                $commentColumn,
                $gap,
            );
        }

        // Empty value on this line.
        if ($this->pos >= $this->length || $this->source[$this->pos] === "\n") {
            // Consume the newline.
            if ($this->pos < $this->length && $this->source[$this->pos] === "\n") {
                $this->advance();
            }

            // Look ahead to the next non-trivia line's indent. If it
            // exceeds keyIndent, the outer scan loop will see deeper
            // content and start a nested block mapping. If not, this
            // entry has an empty scalar value.
            $nextIndent = $this->peekNextLineIndent();
            if ($nextIndent !== null && $nextIndent > $keyIndent) {
                // Nested block ahead. The scan loop will emit the
                // BlockMappingStart and the entries.
                return;
            }

            // Per YAML 1.2 §8.1.1: a block sequence may indent to the
            // same column as its parent mapping key when it is the
            // value of that mapping entry. Detect this case so the
            // outer loop can emit BlockSequenceStart instead of us
            // synthesising an empty scalar value.
            if ($nextIndent !== null
                && $nextIndent === $keyIndent
                && $this->nextLineStartsBlockSequence($nextIndent)
            ) {
                return;
            }

            // No deeper content. This is an empty scalar.
            $tokens[] = new Token(
                type: TokenType::Scalar,
                line: $this->line,
                column: $this->column,
                value: '',
                style: ScalarStyle::Plain,
                leadingTrivia: $this->flushTrivia(),
            );
            return;
        }

        // Anchor / alias / tag preceding the value scalar.
        if ($this->source[$this->pos] === '&'
            || $this->source[$this->pos] === '*'
            || $this->source[$this->pos] === '!'
        ) {
            $this->scanProperties($tokens);
            // Skip trailing whitespace / EOL comment so the
            // newline-only fall-through below fires correctly when
            // the property has no inline value (e.g.
            // `top2: &node2 # comment`, with the value on the next
            // indented line).
            while ($this->pos < $this->length
                && ($this->source[$this->pos] === ' '
                    || $this->source[$this->pos] === "\t")
            ) {
                $this->advance();
            }
            if ($this->pos < $this->length && $this->source[$this->pos] === '#') {
                $commentLine = $this->line;
                $commentColumn = $this->column;
                $text = '';
                while ($this->pos < $this->length && $this->source[$this->pos] !== "\n") {
                    $text .= $this->source[$this->pos];
                    $this->advance();
                }
                $this->triviaBuffer[] = TriviaToken::comment(
                    $text,
                    $commentLine,
                    $commentColumn,
                );
            }
            // After properties, the value may be empty (alias stands
            // alone) or a scalar follows.
            if ($this->pos >= $this->length || $this->source[$this->pos] === "\n") {
                if ($this->pos < $this->length) {
                    $this->advance();
                }
                return;
            }
        }

        // Flow container as map value.
        if ($this->source[$this->pos] === '['
            || $this->source[$this->pos] === '{'
        ) {
            $this->scanFlowContainer($tokens);
            // Consume the trailing newline if present.
            if ($this->pos < $this->length && $this->source[$this->pos] === "\n") {
                $this->advance();
            }
            return;
        }

        if ($this->canStartScalar()) {
            $tokens[] = $this->consumeScalar();
            return;
        }

        throw new ParseException(sprintf(
            'Expected scalar value at line %d column %d',
            $this->line,
            $this->column,
        ));
    }

    /**
     * Peek ahead from the current position (which should be just
     * after a newline) to find the indent of the next line that
     * carries non-trivia content. Returns null if no such line exists
     * before EOF or before a `---` / `...` marker.
     */
    private function peekNextLineIndent(): ?int
    {
        $i = $this->pos;
        while ($i < $this->length) {
            // Count leading spaces.
            $indent = 0;
            while ($i < $this->length && $this->source[$i] === ' ') {
                $indent++;
                $i++;
            }
            if ($i >= $this->length) {
                return null;
            }
            $c = $this->source[$i];
            if ($c === "\n") {
                // Blank line. Keep looking.
                $i++;
                continue;
            }
            if ($c === '#') {
                // Comment line. Keep looking.
                while ($i < $this->length && $this->source[$i] !== "\n") {
                    $i++;
                }
                if ($i < $this->length) {
                    $i++;
                }
                continue;
            }
            // Document or stream markers count as no-deeper content.
            if ($c === '-' && substr($this->source, $i, 3) === '---') {
                return null;
            }
            if ($c === '.' && substr($this->source, $i, 3) === '...') {
                return null;
            }
            return $indent;
        }
        return null;
    }

    /**
     * After a peekNextLineIndent() of $expectedIndent, check whether
     * the upcoming non-trivia line begins with a block sequence
     * indicator (`- ` or `-\n`). Used to disambiguate the empty-value
     * vs sequence-as-value case in scanBlockMapEntry.
     */
    private function nextLineStartsBlockSequence(int $expectedIndent): bool
    {
        $i = $this->pos;
        while ($i < $this->length) {
            $indent = 0;
            while ($i < $this->length && $this->source[$i] === ' ') {
                $indent++;
                $i++;
            }
            if ($i >= $this->length) {
                return false;
            }
            $c = $this->source[$i];
            if ($c === "\n") {
                $i++;
                continue;
            }
            if ($c === '#') {
                while ($i < $this->length && $this->source[$i] !== "\n") {
                    $i++;
                }
                if ($i < $this->length) {
                    $i++;
                }
                continue;
            }
            if ($indent !== $expectedIndent) {
                return false;
            }
            return $this->isBlockSequenceIndicatorAt($i);
        }
        return false;
    }

    /**
     * Validate UTF-8 upfront. On failure, locate the first invalid
     * byte and throw with line/column of that position.
     */
    private function validateUtf8(string $source): void
    {
        if (mb_check_encoding($source, 'UTF-8')) {
            return;
        }

        $line = 1;
        $column = 1;
        $length = strlen($source);
        for ($i = 0; $i < $length; $i++) {
            if (!mb_check_encoding(substr($source, 0, $i + 1), 'UTF-8')) {
                throw new EncodingException(sprintf(
                    'Invalid UTF-8 byte at line %d column %d',
                    $line,
                    $column,
                ));
            }
            $byte = $source[$i];
            if ($byte === "\n") {
                $line++;
                $column = 1;
            } elseif ($byte === "\r") {
                $line++;
                $column = 1;
                if (isset($source[$i + 1]) && $source[$i + 1] === "\n") {
                    $i++;
                }
            } else {
                $column++;
            }
        }
    }

    private function stripBom(string $source): string
    {
        if (str_starts_with($source, "\xEF\xBB\xBF")) {
            return substr($source, 3);
        }
        return $source;
    }

    /**
     * @return array{string, int}
     */
    private function normalizeNewlines(string $source): array
    {
        if (str_contains($source, "\r")) {
            $source = strtr(str_replace("\r\n", "\n", $source), ["\r" => "\n"]);
        }
        return [$source, strlen($source)];
    }

    private function collectTrivia(): bool
    {
        $consumed = false;
        // Bytes of inter-token whitespace consumed since the last
        // newline within this trivia run. Captured so an EOL-style
        // comment (one written on the same line as a preceding token)
        // can record its gap for byte-identical round-trip.
        $pendingGap = '';

        while ($this->pos < $this->length) {
            // Blank-line run.
            if ($this->atLineStart() && $this->source[$this->pos] === "\n") {
                $pendingGap = '';
                $startLine = $this->line;
                $startColumn = $this->column;
                $count = 0;
                while ($this->pos < $this->length && $this->source[$this->pos] === "\n") {
                    $count++;
                    $this->advance();
                }
                $this->triviaBuffer[] = TriviaToken::blankLines($count, $startLine, $startColumn);
                $consumed = true;
                continue;
            }

            // Skip leading inline whitespace before a comment or
            // marker. Inline whitespace is not trivia in its own right.
            // It's just inter-token gap. A whitespace-only line counts
            // as a blank line.
            if ($this->source[$this->pos] === ' ' || $this->source[$this->pos] === "\t") {
                $i = $this->pos;
                while ($i < $this->length
                    && ($this->source[$i] === ' ' || $this->source[$i] === "\t")
                ) {
                    $i++;
                }
                if ($i >= $this->length || $this->source[$i] === "\n") {
                    // Whitespace-only line: skip the spaces and the
                    // newline as blank-line trivia.
                    while ($this->pos < $i) {
                        $this->advance();
                    }
                    if ($this->pos < $this->length && $this->source[$this->pos] === "\n") {
                        $this->advance();
                    }
                    $pendingGap = '';
                    $consumed = true;
                    continue;
                }
                // Inter-token gap: record the byte and advance.
                $pendingGap .= $this->source[$this->pos];
                $this->advance();
                continue;
            }

            // Standalone comment. Per YAML 1.2 §6.6 a comment must be
            // preceded by whitespace or begin a line. If the previous
            // byte was a non-whitespace, non-newline character (i.e.
            // we are mid-line and glued to a token), reject.
            if ($this->source[$this->pos] === '#') {
                $prev = $this->pos > 0 ? $this->source[$this->pos - 1] : "\n";
                if ($prev !== ' ' && $prev !== "\t" && $prev !== "\n") {
                    throw new ParseException(sprintf(
                        'Comment `#` must be preceded by whitespace at line %d column %d',
                        $this->line,
                        $this->column,
                    ));
                }
                $startLine = $this->line;
                $startColumn = $this->column;
                $text = '';
                while ($this->pos < $this->length && $this->source[$this->pos] !== "\n") {
                    $text .= $this->source[$this->pos];
                    $this->advance();
                }
                $this->triviaBuffer[] = TriviaToken::comment(
                    $text,
                    $startLine,
                    $startColumn,
                    $pendingGap,
                );
                $pendingGap = '';
                $consumed = true;
                if ($this->pos < $this->length && $this->source[$this->pos] === "\n") {
                    $this->advance();
                }
                continue;
            }

            break;
        }

        return $consumed;
    }

    private function consumeDocumentStart(): Token
    {
        $line = $this->line;
        $column = $this->column;
        $this->advance();
        $this->advance();
        $this->advance();
        // Skip a single space/tab after `---`. If anything else
        // (besides newline / EOL comment) remains on the line, the
        // outer scanner loop will pick it up. `--- text`, `--- !!str`,
        // `--- |` etc. all introduce the document's root node.
        if ($this->pos < $this->length
            && ($this->source[$this->pos] === ' ' || $this->source[$this->pos] === "\t")
        ) {
            $this->advance();
            // Skip additional whitespace (tabs are allowed before
            // node content even though not allowed in indentation).
            while ($this->pos < $this->length
                && ($this->source[$this->pos] === ' ' || $this->source[$this->pos] === "\t")
            ) {
                $this->advance();
            }
        }
        // EOL or EOL comment: nothing more on this line.
        if ($this->pos >= $this->length || $this->source[$this->pos] === "\n") {
            if ($this->pos < $this->length) {
                $this->advance();
            }
            return $this->makeStructural(TokenType::DocumentStart, $line, $column);
        }
        if ($this->source[$this->pos] === '#') {
            // Comment to end of line.
            while ($this->pos < $this->length && $this->source[$this->pos] !== "\n") {
                $this->advance();
            }
            if ($this->pos < $this->length) {
                $this->advance();
            }
            return $this->makeStructural(TokenType::DocumentStart, $line, $column);
        }
        // The marker line carries node content. Block mappings and
        // block sequences cannot start here per YAML 1.2 §9.1.2.
        // Their indent rules require a fresh line. Allowed shapes:
        // a plain or quoted scalar, a flow container, a tag/anchor
        // (introducing a node on a following line), or a block
        // scalar header.
        if ($this->lineStartsBlockMappingEntry($this->column - 1)) {
            throw new ParseException(sprintf(
                'A block mapping cannot start on the document marker '
                    . 'line at line %d column %d',
                $this->line,
                $this->column,
            ));
        }
        if ($this->source[$this->pos] === '-'
            && $this->isBlockSequenceIndicatorAt($this->pos)
        ) {
            throw new ParseException(sprintf(
                'A block sequence cannot start on the document marker '
                    . 'line at line %d column %d',
                $this->line,
                $this->column,
            ));
        }
        // A property on the marker line introduces the next node;
        // consume it here and skip any trailing newline so the next
        // logical content can be picked up at the start of a fresh
        // line. (We do this only on the marker line, not at the
        // general top-level dispatch, to avoid swallowing dedent
        // boundaries elsewhere.)
        return $this->makeStructural(TokenType::DocumentStart, $line, $column);
    }

    private function consumeDocumentEnd(): Token
    {
        $line = $this->line;
        $column = $this->column;
        $this->advance();
        $this->advance();
        $this->advance();
        $this->skipRestOfLine();
        return $this->makeStructural(TokenType::DocumentEnd, $line, $column);
    }

    private function consumeDirective(): Token
    {
        $line = $this->line;
        $column = $this->column;
        $this->advance();
        $value = '';
        while ($this->pos < $this->length && $this->source[$this->pos] !== "\n") {
            $value .= $this->source[$this->pos];
            $this->advance();
        }
        if ($this->pos < $this->length && $this->source[$this->pos] === "\n") {
            $this->advance();
        }
        return $this->makeStructural(TokenType::Directive, $line, $column, value: rtrim($value));
    }

    private function skipRestOfLine(): void
    {
        while ($this->pos < $this->length && $this->source[$this->pos] !== "\n") {
            if ($this->source[$this->pos] === ' ' || $this->source[$this->pos] === "\t") {
                $this->advance();
                continue;
            }
            // Trailing line comment is permitted on document marker
            // lines per YAML 1.2 §6.6.
            if ($this->source[$this->pos] === '#') {
                while ($this->pos < $this->length && $this->source[$this->pos] !== "\n") {
                    $this->advance();
                }
                break;
            }
            throw new ParseException(sprintf(
                'Unexpected content after document marker at line %d column %d',
                $this->line,
                $this->column,
            ));
        }
        if ($this->pos < $this->length && $this->source[$this->pos] === "\n") {
            $this->advance();
        }
    }

    private function makeStructural(
        TokenType $type,
        int $line,
        int $column,
        ?string $value = null,
    ): Token {
        return new Token(
            type: $type,
            line: $line,
            column: $column,
            value: $value,
            leadingTrivia: $this->flushTrivia(),
        );
    }

    private function advance(): void
    {
        $byte = $this->source[$this->pos];
        if ($byte === "\n") {
            $this->line++;
            $this->column = 1;
            $this->pos++;
            return;
        }

        $byteOrd = ord($byte);
        if (($byteOrd & 0x80) === 0) {
            $this->pos++;
        } elseif (($byteOrd & 0xE0) === 0xC0) {
            $this->pos += 2;
        } elseif (($byteOrd & 0xF0) === 0xE0) {
            $this->pos += 3;
        } elseif (($byteOrd & 0xF8) === 0xF0) {
            $this->pos += 4;
        } else {
            $this->pos++;
        }
        $this->column++;
    }

    /**
     * Return the bytes of the UTF-8 character at the current
     * position. Used when accumulating scalar content so multi-byte
     * sequences are copied whole rather than truncated to their
     * leading byte.
     */
    private function currentChar(): string
    {
        $byte = $this->source[$this->pos];
        $byteOrd = ord($byte);
        $len = 1;
        if (($byteOrd & 0x80) === 0) {
            $len = 1;
        } elseif (($byteOrd & 0xE0) === 0xC0) {
            $len = 2;
        } elseif (($byteOrd & 0xF0) === 0xE0) {
            $len = 3;
        } elseif (($byteOrd & 0xF8) === 0xF0) {
            $len = 4;
        }
        return substr($this->source, $this->pos, $len);
    }

    private function atLineStart(): bool
    {
        return $this->column === 1;
    }

    private function matchesAtLineStart(string $literal): bool
    {
        if (!$this->atLineStart()) {
            return false;
        }
        return substr($this->source, $this->pos, strlen($literal)) === $literal;
    }

    /**
     * Are we positioned at a document marker (`---` or `...`) at
     * column 1, followed by whitespace, newline, or end-of-input?
     * Per YAML 1.2 §9.1.2 the trailing separator is mandatory.
     * `---word` is plain-scalar content, not a doc-start.
     */
    private function matchesDocumentMarkerAt(string $marker): bool
    {
        if (!$this->matchesAtLineStart($marker)) {
            return false;
        }
        $after = $this->source[$this->pos + strlen($marker)] ?? "\n";
        return $after === ' ' || $after === "\t" || $after === "\n";
    }

    private function describeChar(string $byte): string
    {
        $ord = ord($byte);
        if ($ord >= 32 && $ord < 127) {
            return "'$byte'";
        }
        return sprintf('0x%02X', $ord);
    }

    private function canStartPlainScalar(): bool
    {
        if ($this->pos >= $this->length) {
            return false;
        }
        $c = $this->source[$this->pos];

        if ($c === "\n" || $c === "\t" || $c === ' ') {
            return false;
        }

        if (in_array($c, ['[', ']', '{', '}', ',', '#', '&', '*', '!', "'", '"', '%', '@', '`'], true)) {
            return false;
        }

        // `|` and `>` are block-scalar indicators only when followed
        // by a valid header character. `>=8.1` is a plain scalar.
        if ($c === '|' || $c === '>') {
            if ($this->isBlockScalarHeaderAt($this->pos)) {
                return false;
            }
            // Otherwise fall through: starts a plain scalar.
        }

        if (in_array($c, ['-', '?', ':'], true)) {
            $next = $this->source[$this->pos + 1] ?? '';
            if ($next === ' ' || $next === "\t" || $next === "\n" || $next === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the current position can start a scalar (plain or
     * quoted or block). Quoted scalars start with `'` or `"`; block
     * scalars start with `|` or `>`.
     */
    private function canStartScalar(): bool
    {
        if ($this->pos >= $this->length) {
            return false;
        }
        $c = $this->source[$this->pos];
        if ($c === "'" || $c === '"') {
            return true;
        }
        if ($c === '|' || $c === '>') {
            // Block scalar indicator only if followed by a valid
            // header character: digit, `+`, `-`, space, tab,
            // newline, or comment marker. `>=8.1` is a plain scalar,
            // not a folded block scalar.
            if ($this->isBlockScalarHeaderAt($this->pos)) {
                return true;
            }
            return $this->canStartPlainScalar();
        }
        return $this->canStartPlainScalar();
    }

    /**
     * Consume a scalar (plain, single-quoted, double-quoted, or
     * block) at the current position.
     *
     * @param bool $stopAtColon If true and we're consuming a plain
     *                          scalar, stop at `:` followed by
     *                          whitespace/EOL (used for mapping keys).
     *                          Quoted and block scalars naturally end
     *                          so this flag has no effect on them.
     */
    /**
     * Scan zero or more property tokens (anchors, aliases, tags) at
     * the current position. These tokens attach to the next-following
     * value in source order. The parser links them to the value
     * during AST construction.
     *
     * @param list<Token> $tokens
     */
    private function scanProperties(array &$tokens): void
    {
        while ($this->pos < $this->length) {
            // Skip inline whitespace between properties.
            if ($this->source[$this->pos] === ' ' || $this->source[$this->pos] === "\t") {
                $this->advance();
                continue;
            }
            $c = $this->source[$this->pos];
            if ($c === '&') {
                $tokens[] = $this->consumeAnchorOrAlias(TokenType::Anchor);
                continue;
            }
            if ($c === '*') {
                $tokens[] = $this->consumeAnchorOrAlias(TokenType::Alias);
                continue;
            }
            if ($c === '!') {
                $tokens[] = $this->consumeTag();
                continue;
            }
            break;
        }
    }

    /**
     * Consume `&name` or `*name`. The leading `&` or `*` has been
     * verified by the caller.
     */
    private function consumeAnchorOrAlias(TokenType $type): Token
    {
        $startLine = $this->line;
        $startColumn = $this->column;
        $this->advance(); // consume `&` or `*`

        $name = '';
        while ($this->pos < $this->length) {
            $c = $this->source[$this->pos];
            if ($c === ' ' || $c === "\t" || $c === "\n"
                || $c === ',' || $c === '[' || $c === ']'
                || $c === '{' || $c === '}'
            ) {
                break;
            }
            $name .= $c;
            $this->advance();
        }

        if ($name === '') {
            throw new ParseException(sprintf(
                'Empty %s name at line %d column %d',
                $type === TokenType::Anchor ? 'anchor' : 'alias',
                $startLine,
                $startColumn,
            ));
        }

        return new Token(
            type: $type,
            line: $startLine,
            column: $startColumn,
            value: $name,
            leadingTrivia: $this->flushTrivia(),
        );
    }

    /**
     * Consume `!tag`, `!!tag`, or `!handle!suffix`. The leading `!`
     * has been verified by the caller.
     */
    private function consumeTag(): Token
    {
        $startLine = $this->line;
        $startColumn = $this->column;
        $tag = '';

        // Verbatim form `!<...>`: all characters between `<` and `>`
        // are part of the URI, including commas.
        if (($this->source[$this->pos + 1] ?? '') === '<') {
            $tag = '!<';
            $this->advance();
            $this->advance();
            while ($this->pos < $this->length) {
                $c = $this->source[$this->pos];
                if ($c === '>') {
                    $tag .= '>';
                    $this->advance();
                    break;
                }
                if ($c === "\n") {
                    throw new ParseException(sprintf(
                        'Unterminated verbatim tag at line %d column %d',
                        $startLine,
                        $startColumn,
                    ));
                }
                $tag .= $c;
                $this->advance();
            }
            return new Token(
                type: TokenType::Tag,
                line: $startLine,
                column: $startColumn,
                value: $tag,
                leadingTrivia: $this->flushTrivia(),
            );
        }

        while ($this->pos < $this->length) {
            $c = $this->source[$this->pos];
            if ($c === ' ' || $c === "\t" || $c === "\n"
                || $c === ',' || $c === '[' || $c === ']'
                || $c === '{' || $c === '}'
            ) {
                break;
            }
            $tag .= $c;
            $this->advance();
        }
        // `!` alone is the non-specific tag per YAML 1.2 §6.9.1.
        // `!!` alone is a malformed shorthand (handle without suffix).
        if ($tag === '!!') {
            throw new ParseException(sprintf(
                'Empty tag at line %d column %d',
                $startLine,
                $startColumn,
            ));
        }
        // A tag must be followed by whitespace, newline, EOF, or a
        // flow-context indicator (`,` `]` `}`). A glued non-whitespace
        // continuation like `!tag{}content` is a malformed tag (the
        // opening `{` is not a flow start because it is glued to the
        // tag name with no separator).
        if ($this->pos < $this->length) {
            $next = $this->source[$this->pos];
            if ($next === '{') {
                throw new ParseException(sprintf(
                    'Tag at line %d column %d must be followed by whitespace',
                    $startLine,
                    $startColumn,
                ));
            }
        }
        return new Token(
            type: TokenType::Tag,
            line: $startLine,
            column: $startColumn,
            value: $tag,
            leadingTrivia: $this->flushTrivia(),
        );
    }

    private function consumeScalar(bool $stopAtColon = false): Token
    {
        $c = $this->source[$this->pos] ?? '';
        if ($c === "'") {
            return $this->consumeSingleQuotedScalar();
        }
        if ($c === '"') {
            return $this->consumeDoubleQuotedScalar();
        }
        if ($c === '|' && $this->isBlockScalarHeaderAt($this->pos)) {
            return $this->consumeBlockScalar(folded: false);
        }
        if ($c === '>' && $this->isBlockScalarHeaderAt($this->pos)) {
            return $this->consumeBlockScalar(folded: true);
        }
        return $this->consumePlainScalar($stopAtColon);
    }

    /**
     * `|` or `>` at $i begins a block scalar header only when
     * followed by a valid header character. `>=8.1` is a plain
     * scalar starting with `>` because `=` is not a header char.
     * Note: `0` IS allowed through here so the block-scalar consumer
     * can raise the proper "indent may not be 0" error.
     */
    private function isBlockScalarHeaderAt(int $i): bool
    {
        $next = $this->source[$i + 1] ?? "\n";
        return $next === ' ' || $next === "\t" || $next === "\n"
            || $next === '#' || $next === '+' || $next === '-'
            || ctype_digit($next);
    }

    /**
     * Scan a flow sequence `[...]` or flow mapping `{...}`. Emits
     * FlowSequenceStart / FlowMappingStart, then items separated by
     * FlowEntry tokens, then FlowSequenceEnd / FlowMappingEnd.
     *
     * The source span from opening to closing bracket is captured on
     * the start token's rawSource field for round-trip preservation
     * of multi-line flow layout (per Stage 2 §3.5 / §9.1).
     *
     * @param list<Token> $tokens
     */
    private function scanFlowContainer(array &$tokens): void
    {
        $this->flowDepth++;
        try {
            $this->scanFlowContainerInner($tokens);
        } finally {
            $this->flowDepth--;
        }
    }

    private function scanFlowContainerInner(array &$tokens): void
    {
        $startLine = $this->line;
        $startColumn = $this->column;
        $rawStart = $this->pos;
        $startChar = $this->source[$this->pos];
        $isMapping = $startChar === '{';
        $endChar = $isMapping ? '}' : ']';
        $startType = $isMapping ? TokenType::FlowMappingStart : TokenType::FlowSequenceStart;
        $endType = $isMapping ? TokenType::FlowMappingEnd : TokenType::FlowSequenceEnd;

        // Per YAML 1.2 §10.3.2 every continuation line of a flow
        // node must be indented STRICTLY MORE than the parent block
        // context's indent. Capture the parent now (the deepest open
        // block container, or -1 at document root) so the loop can
        // validate each new line.
        $parentBlockIndent = $this->indentStack === []
            ? -1
            : end($this->indentStack)[0];

        // Consume opening bracket.
        $this->advance();
        $startTokenIndex = count($tokens);
        $tokens[] = new Token(
            type: $startType,
            line: $startLine,
            column: $startColumn,
            leadingTrivia: $this->flushTrivia(),
        );

        $expectingItem = true;
        $justOpenedOrSeparated = true;
        // True when an implicit-pair Value indicator (`:`) was just
        // emitted and the upcoming token is the pair's value. Used
        // to allow that one scalar/container after the `:` even
        // though `justOpenedOrSeparated` is false.
        $expectingValue = false;
        while ($this->pos < $this->length) {
            // Skip inline whitespace and newlines (multi-line flow
            // preserved via rawSource on the start token). On every
            // newline, validate the next non-blank/non-closing line's
            // indent against the parent block context per
            // YAML 1.2 §10.3.2 (yaml-test-suite 9C9N, CML9).
            $c = $this->source[$this->pos];
            if ($c === ' ' || $c === "\t") {
                $this->advance();
                continue;
            }
            if ($c === "\n") {
                $this->advance();
                $this->validateFlowContinuationIndent(
                    $parentBlockIndent,
                    $endChar,
                    $startLine,
                    $startColumn,
                );
                continue;
            }

            // Document markers `---` and `...` are invalid inside a
            // flow container per YAML 1.2 §7.4. They are only valid
            // at column 1 between documents.
            if ($this->column === 1
                && ($c === '-' || $c === '.')
                && substr($this->source, $this->pos, 3) === ($c === '-' ? '---' : '...')
            ) {
                throw new ParseException(sprintf(
                    'Document marker `%s` is not allowed inside flow content '
                        . 'at line %d column %d',
                    substr($this->source, $this->pos, 3),
                    $this->line,
                    $this->column,
                ));
            }

            // Comments inside flow: skip to end of line. The comment
            // bytes are preserved in the start token's rawSource span
            // (captured at close), so round-trip still works.
            //
            // Per YAML 1.2 §6.6 a comment must be preceded by a
            // whitespace character. Reject `#` glued to the prior
            // token (e.g. `[a, b, c,#invalid]`).
            if ($c === '#') {
                $prev = $this->pos > 0 ? $this->source[$this->pos - 1] : "\n";
                if ($prev !== ' ' && $prev !== "\t" && $prev !== "\n") {
                    throw new ParseException(sprintf(
                        'Comment `#` must be preceded by whitespace at line %d column %d',
                        $this->line,
                        $this->column,
                    ));
                }
                while ($this->pos < $this->length && $this->source[$this->pos] !== "\n") {
                    $this->advance();
                }
                continue;
            }

            // Closing bracket?
            if ($c === $endChar) {
                $closeLine = $this->line;
                $closeColumn = $this->column;
                $this->advance();

                // Capture raw source span (open through close,
                // inclusive) for round-trip.
                $rawSource = substr($this->source, $rawStart, $this->pos - $rawStart);

                // If the span includes a newline, this is multi-line;
                // store rawSource on the start token so the emitter
                // can reproduce it.
                if (str_contains($rawSource, "\n")) {
                    $existing = $tokens[$startTokenIndex];
                    $tokens[$startTokenIndex] = new Token(
                        type: $existing->type,
                        line: $existing->line,
                        column: $existing->column,
                        value: $existing->value,
                        style: $existing->style,
                        chomp: $existing->chomp,
                        indentIndicator: $existing->indentIndicator,
                        leadingTrivia: $existing->leadingTrivia,
                        trailingTrivia: $existing->trailingTrivia,
                        rawSource: $rawSource,
                    );
                }

                $trailing = $this->collectTrailingEolTrivia();
                if ($this->pos < $this->length && $this->source[$this->pos] === "\n") {
                    $this->advance();
                }

                $tokens[] = new Token(
                    type: $endType,
                    line: $closeLine,
                    column: $closeColumn,
                    trailingTrivia: $trailing,
                );
                return;
            }

            // Entry separator.
            if ($c === ',') {
                if ($justOpenedOrSeparated) {
                    throw new ParseException(sprintf(
                        'Unexpected `,` at line %d column %d (empty flow entry)',
                        $this->line,
                        $this->column,
                    ));
                }
                $tokens[] = new Token(
                    type: TokenType::FlowEntry,
                    line: $this->line,
                    column: $this->column,
                );
                $this->advance();
                $expectingItem = true;
                $justOpenedOrSeparated = true;
                $expectingValue = false;
                continue;
            }

            // Bare `:` at item position (e.g. `[: value]`,
            // `{: value}`). Emit an empty scalar as implicit key,
            // then the Value token. The parser turns the resulting
            // pair into a flow-pair entry.
            if ($c === ':' && $expectingItem) {
                $colonLine = $this->line;
                $colonColumn = $this->column;
                $tokens[] = new Token(
                    type: TokenType::Scalar,
                    line: $colonLine,
                    column: $colonColumn,
                    value: null,
                    style: ScalarStyle::Plain,
                );
                $tokens[] = new Token(
                    type: TokenType::Value,
                    line: $colonLine,
                    column: $colonColumn,
                );
                $this->advance();
                $expectingItem = false;
                $justOpenedOrSeparated = false;
                $expectingValue = true;
                continue;
            }

            // Explicit-key indicator `?` in flow at item position.
            // Per YAML 1.2 §7.4 a flow mapping or sequence may
            // contain explicit-key pairs (`? key : value`). The
            // scanner emits an implicit empty Scalar to act as a
            // flow item marker, then the key is consumed as an
            // ordinary flow scalar by subsequent loop iterations,
            // followed by `:` which produces the Value indicator.
            if ($c === '?' && $this->isExplicitKeyIndicatorAt($this->pos)
                && $expectingItem
            ) {
                $this->advance();
                // Skip following whitespace (`? foo` form).
                if ($this->pos < $this->length
                    && ($this->source[$this->pos] === ' '
                        || $this->source[$this->pos] === "\t")
                ) {
                    $this->advance();
                }
                // Stay in expectingItem mode. The key follows.
                continue;
            }

            // Properties (anchor / alias / tag) before a scalar.
            //
            // Anchor (`&`) and tag (`!`) are properties that precede
            // a scalar/container. They don't change the "between
            // items" state because the upcoming scalar IS the item.
            // An alias (`*`) IS the item itself. Clear the
            // separator state so the next thing must be `,` or `]`/`}`.
            if ($c === '&' || $c === '*' || $c === '!') {
                if ($c === '*' && !$justOpenedOrSeparated && !$expectingValue) {
                    throw new ParseException(sprintf(
                        'Missing comma between flow entries at line %d column %d',
                        $this->line,
                        $this->column,
                    ));
                }
                $isAlias = $c === '*';
                $this->scanProperties($tokens);
                if ($isAlias) {
                    // Alias ended the item; allow optional `:`
                    // implicit-pair value indicator after.
                    $expectingValue = $this->emitFlowValueIfPresent($tokens, $isMapping);
                    $expectingItem = false;
                    $justOpenedOrSeparated = false;
                }
                continue;
            }

            // Nested flow container.
            if ($c === '[' || $c === '{') {
                if (!$justOpenedOrSeparated && !$expectingValue) {
                    throw new ParseException(sprintf(
                        'Missing comma between flow entries at line %d column %d',
                        $this->line,
                        $this->column,
                    ));
                }
                $this->scanFlowContainer($tokens);
                $expectingValue = $this->emitFlowValueIfPresent($tokens, $isMapping);
                $expectingItem = false;
                $justOpenedOrSeparated = false;
                continue;
            }

            // Inside a flow container `%` is plain-scalar content
            // per YAML 1.2 §6.8 (directives are ONLY emitted at
            // start-of-stream or after a `...` end marker; the
            // outer scan handles those). `consumeFlowScalar`'s
            // plain-scalar reader accepts any byte that isn't a
            // flow indicator or whitespace, but `canStartScalar`
            // currently rejects `%` as a starter. Special-case it
            // here so a leading `%` inside flow becomes the start
            // of a plain scalar (yaml-test-suite UT92).
            if ($c === '%') {
                if (!$justOpenedOrSeparated && !$expectingValue) {
                    throw new ParseException(sprintf(
                        'Missing comma between flow entries at line %d column %d',
                        $this->line,
                        $this->column,
                    ));
                }
                $tokens[] = $this->consumeFlowScalar($isMapping);
                $expectingValue = $this->emitFlowValueIfPresent($tokens, $isMapping);
                $expectingItem = false;
                $justOpenedOrSeparated = false;
                continue;
            }

            // Scalar item. Both flow mappings and flow sequences may
            // see `key: value`. Flow sequences accept implicit pair
            // entries per YAML 1.2 §7.4.
            if ($this->canStartScalar()) {
                // Per YAML 1.2 §7.3.3 a plain scalar in flow cannot
                // start with `-` followed by a flow indicator.
                if ($this->source[$this->pos] === '-') {
                    $next = $this->source[$this->pos + 1] ?? "\n";
                    if ($next === ',' || $next === ']' || $next === '}') {
                        throw new ParseException(sprintf(
                            'Bare `-` followed by flow indicator `%s` at line %d column %d',
                            $next,
                            $this->line,
                            $this->column,
                        ));
                    }
                }
                // Per YAML 1.2 §7.4 flow entries are comma-separated.
                // Two adjacent items without a `,` between them is a
                // parse error (yaml-test-suite ZXT5).
                if (!$justOpenedOrSeparated && !$expectingValue) {
                    throw new ParseException(sprintf(
                        'Missing comma between flow entries at line %d column %d',
                        $this->line,
                        $this->column,
                    ));
                }
                $tokens[] = $this->consumeFlowScalar($isMapping);
                $expectingValue = $this->emitFlowValueIfPresent($tokens, $isMapping);
                $expectingItem = false;
                $justOpenedOrSeparated = false;
                continue;
            }

            throw new ParseException(sprintf(
                'Unexpected character %s in flow context at line %d column %d',
                $this->describeChar($c),
                $this->line,
                $this->column,
            ));
        }

        throw new ParseException(sprintf(
            'Unterminated flow %s starting at line %d column %d',
            $isMapping ? 'mapping' : 'sequence',
            $startLine,
            $startColumn,
        ));
    }

    /**
     * After consuming a `\n` inside a flow container, look ahead to
     * the next non-blank, non-comment, non-closing line and verify
     * its leading-space count is strictly greater than the parent
     * block context's indent. Per YAML 1.2 §10.3.2.
     *
     * Pure validation: does not consume bytes. The caller's loop
     * picks up at the same position and resumes its dispatch.
     */
    private function validateFlowContinuationIndent(
        int $parentBlockIndent,
        string $endChar,
        int $startLine,
        int $startColumn,
    ): void {
        // Skip any number of blank lines and continuation whitespace.
        // The check fires for the first line carrying real content
        // (or a comment, also subject to the indent rule).
        $i = $this->pos;
        while ($i < $this->length) {
            $indent = 0;
            while ($i < $this->length && $this->source[$i] === ' ') {
                $i++;
                $indent++;
            }
            if ($i >= $this->length) {
                return;
            }
            $c = $this->source[$i];
            if ($c === "\n") {
                // Whitespace-only line. Keep looking.
                $i++;
                continue;
            }
            if ($c === $endChar) {
                // Closing bracket on this line. No indent check.
                return;
            }
            // Non-blank content (or a `#` comment line). Validate.
            if ($indent <= $parentBlockIndent) {
                throw new ParseException(sprintf(
                    'Flow content must be indented more than the parent '
                        . 'block context (parent indent %d, line indent %d) '
                        . 'in flow opened at line %d column %d',
                    $parentBlockIndent,
                    $indent,
                    $startLine,
                    $startColumn,
                ));
            }
            return;
        }
    }

    /**
     * After consuming a scalar or nested container inside a flow
     * context, look for a `:` and emit a Value token if found.
     *
     * In a flow MAPPING (`{...}`), per YAML 1.2 §10.3.1 line breaks
     * fold to spaces, so the `:` may appear on a subsequent line
     * (the indent rule is enforced separately). In a flow SEQUENCE
     * (`[...]`), an implicit-pair key (§7.4.1) must be a single
     * line. The `:` may NOT be on a separate line.
     *
     * @param list<Token> $tokens
     */
    private function emitFlowValueIfPresent(array &$tokens, bool $inFlowMapping = false): bool
    {
        $i = $this->pos;
        while ($i < $this->length) {
            $c = $this->source[$i];
            if ($c === ' ' || $c === "\t") {
                $i++;
                continue;
            }
            if ($c === "\n") {
                // Newlines between key and `:` are only legal in a
                // flow mapping. In a flow sequence, an implicit-pair
                // key must be on a single line. Bail without
                // emitting a Value so the outer flow loop sees the
                // `:` on the next line as a different shape (and
                // either errors or treats it as plain content).
                if (!$inFlowMapping) {
                    return false;
                }
                $i++;
                continue;
            }
            if ($c === '#' && $inFlowMapping) {
                while ($i < $this->length && $this->source[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            break;
        }
        if ($i < $this->length && $this->source[$i] === ':') {
            // Advance up to the colon (capturing line/column moves).
            while ($this->pos < $i) {
                $this->advance();
            }
            $tokens[] = new Token(
                type: TokenType::Value,
                line: $this->line,
                column: $this->column,
            );
            $this->advance();
            return true;
        }
        return false;
    }

    /**
     * Consume a scalar inside a flow context. Plain scalars in flow
     * stop at `,`, `]`, `}`, `: ` (for flow mapping keys), ` #` (EOL
     * comment), or end-of-line. Quoted and block scalars use their
     * normal consumers.
     */
    private function consumeFlowScalar(bool $inFlowMapping): Token
    {
        $c = $this->source[$this->pos] ?? '';
        if ($c === "'") {
            return $this->consumeSingleQuotedScalar();
        }
        if ($c === '"') {
            return $this->consumeDoubleQuotedScalar();
        }

        $startLine = $this->line;
        $startColumn = $this->column;
        $value = '';
        // For continuation indent check: parent block context's
        // indent (or -1 at the document root), per §10.3.2.
        $parentBlockIndent = $this->indentStack === []
            ? -1
            : end($this->indentStack)[0];
        while ($this->pos < $this->length) {
            $c = $this->source[$this->pos];
            if ($c === ',' || $c === ']' || $c === '}') {
                break;
            }
            if ($c === "\n") {
                // Try to fold a continuation line per YAML 1.2 §7.3.3.
                // The next line must be indented strictly more than
                // the parent block context's indent (any column at
                // the document root) and not start with a flow
                // structural indicator. Otherwise terminate the
                // scalar here.
                $save = ['pos' => $this->pos, 'line' => $this->line, 'col' => $this->column];
                $this->advance();
                $indent = 0;
                while ($this->pos < $this->length && $this->source[$this->pos] === ' ') {
                    $indent++;
                    $this->advance();
                }
                if ($this->pos >= $this->length
                    || $this->source[$this->pos] === "\n"
                    || $indent <= $parentBlockIndent
                ) {
                    // Roll back so the flow loop sees the newline.
                    $this->pos = $save['pos'];
                    $this->line = $save['line'];
                    $this->column = $save['col'];
                    break;
                }
                $cc = $this->source[$this->pos];
                if ($cc === ',' || $cc === ']' || $cc === '}'
                    || $cc === ':' || $cc === '#'
                ) {
                    // Roll back: structural indicator at line start
                    // ends the scalar.
                    $this->pos = $save['pos'];
                    $this->line = $save['line'];
                    $this->column = $save['col'];
                    break;
                }
                // Fold the line break into a space and continue.
                $value = rtrim($value, " \t") . ' ';
                continue;
            }
            // ` #` (whitespace + hash) ends a flow scalar.
            if ($c === '#' && $value !== '' && (
                substr($value, -1) === ' ' || substr($value, -1) === "\t"
            )) {
                break;
            }
            // `: ` (colon + ws) ends a flow-mapping key.
            if ($c === ':') {
                $next = $this->source[$this->pos + 1] ?? "\n";
                if ($next === ' ' || $next === "\t" || $next === ','
                    || $next === ']' || $next === '}'
                    || $next === "\n"
                ) {
                    break;
                }
            }
            $value .= $this->currentChar();
            $this->advance();
        }

        $value = rtrim($value, " \t");

        return new Token(
            type: TokenType::Scalar,
            line: $startLine,
            column: $startColumn,
            value: $value,
            style: ScalarStyle::Plain,
            leadingTrivia: $this->flushTrivia(),
        );
    }

    /**
     * Consume a literal (`|`) or folded (`>`) block scalar.
     *
     * Per YAML 1.2:
     *   |[+|-][1-9]? content...
     *   >[+|-][1-9]? content...
     *
     * Indicator order doesn't matter (e.g. `|+2` and `|2+` both
     * legal). Content begins on the next line, indented more than
     * the block scalar's parent indent (or as specified by the
     * explicit indent indicator).
     */
    private function consumeBlockScalar(bool $folded): Token
    {
        $startLine = $this->line;
        $startColumn = $this->column;
        $rawStart = $this->pos;

        // Consume the `|` or `>` indicator.
        $this->advance();

        // Parse chomp and explicit indent indicators in any order.
        $chomp = ChompMode::Clip;
        $indentIndicator = null;
        for ($i = 0; $i < 2; $i++) {
            if ($this->pos >= $this->length) {
                break;
            }
            $c = $this->source[$this->pos];
            if ($c === '+') {
                $chomp = ChompMode::Keep;
                $this->advance();
                continue;
            }
            if ($c === '-') {
                $chomp = ChompMode::Strip;
                $this->advance();
                continue;
            }
            if (ctype_digit($c) && $c !== '0') {
                $indentIndicator = (int) $c;
                $this->advance();
                continue;
            }
            if ($c === '0') {
                throw new ParseException(sprintf(
                    'Block scalar indent indicator may not be 0 at line %d column %d',
                    $this->line,
                    $this->column,
                ));
            }
            break;
        }

        // Capture trailing trivia on the same line (typically an EOL
        // comment after the indicator).
        $trailing = $this->collectTrailingEolTrivia();

        // Consume the line break terminating the indicator line.
        if ($this->pos < $this->length && $this->source[$this->pos] === "\n") {
            $this->advance();
        }

        // Determine the parent indent (indent of the structural
        // element introducing this block scalar). Used to validate
        // content indent.
        $parentIndent = $this->indentStack === [] ? -1 : end($this->indentStack)[0];

        // Determine effective content indent.
        $contentIndent = null;
        if ($indentIndicator !== null) {
            $contentIndent = $parentIndent + $indentIndicator;
        }

        // Read content lines until we hit a line indented at or
        // below the parent indent (or EOF).
        $lines = [];
        $detectedIndent = null;

        // Per YAML 1.2 §8.1.1.1, when no explicit indent indicator
        // is given the implicit content indent is auto-detected from
        // the first non-empty line. An empty line that PRECEDES the
        // first content line and has MORE leading spaces than that
        // line's indent is invalid (yaml-test-suite 5LLU, S98Z,
        // W9L4). Track the maximum.
        $maxLeadingOfPrecedingEmpty = 0;

        while ($this->pos < $this->length) {
            // Measure leading spaces of this line.
            $lineStart = $this->pos;
            $leadingSpaces = 0;
            while ($this->pos < $this->length && $this->source[$this->pos] === ' ') {
                $leadingSpaces++;
                $this->advance();
            }

            // Empty line (just newline or EOF).
            if ($this->pos >= $this->length || $this->source[$this->pos] === "\n") {
                $lines[] = ['empty', ''];
                if ($contentIndent === null && $leadingSpaces > $maxLeadingOfPrecedingEmpty) {
                    $maxLeadingOfPrecedingEmpty = $leadingSpaces;
                }
                if ($this->pos < $this->length) {
                    $this->advance();
                }
                continue;
            }

            // First non-empty line determines auto-detected indent
            // when no explicit indicator was given.
            if ($contentIndent === null) {
                $contentIndent = $leadingSpaces;
                if ($contentIndent <= $parentIndent) {
                    // Content not indented enough. Block scalar has no
                    // content. Rewind and break.
                    $this->pos = $lineStart;
                    $this->column = 1;
                    break;
                }
                // §8.1.1.1: a preceding empty line cannot have more
                // leading whitespace than the auto-detected content
                // indent.
                if ($maxLeadingOfPrecedingEmpty > $contentIndent) {
                    throw new ParseException(sprintf(
                        'Block scalar empty line indent (%d) exceeds first '
                            . 'content line indent (%d) at line %d',
                        $maxLeadingOfPrecedingEmpty,
                        $contentIndent,
                        $this->line,
                    ));
                }
                $detectedIndent = $contentIndent;
            }

            if ($leadingSpaces < $contentIndent) {
                // Line dedented below content indent. Block scalar
                // ends. Rewind to start of this line.
                $this->pos = $lineStart;
                $this->column = 1;
                break;
            }

            // Capture content from contentIndent to end of line.
            $content = '';
            // The leading spaces beyond contentIndent are part of the
            // content (preserved in literal style; folded normalizes
            // differently).
            $extraSpaces = $leadingSpaces - $contentIndent;
            $content .= str_repeat(' ', $extraSpaces);
            while ($this->pos < $this->length && $this->source[$this->pos] !== "\n") {
                $content .= $this->currentChar();
                $this->advance();
            }
            $lines[] = ['content', $content];
            if ($this->pos < $this->length && $this->source[$this->pos] === "\n") {
                $this->advance();
            }
        }

        // Build the value from collected lines.
        $value = $this->buildBlockScalarValue($lines, $folded, $chomp);

        // Capture rawSource: from the `|`/`>` indicator through the
        // last consumed line. The current pos points to the start of
        // the line that ended the block scalar (or EOF).
        $rawSource = substr($this->source, $rawStart, $this->pos - $rawStart);

        return new Token(
            type: TokenType::Scalar,
            line: $startLine,
            column: $startColumn,
            value: $value,
            style: $folded ? ScalarStyle::FoldedBlock : ScalarStyle::LiteralBlock,
            chomp: $chomp,
            indentIndicator: $indentIndicator,
            leadingTrivia: $this->flushTrivia(),
            trailingTrivia: $trailing,
            rawSource: $rawSource,
        );
    }

    /**
     * Build the block-scalar value from a sequence of (kind, text)
     * tuples and apply chomping.
     *
     * @param list<array{string, string}> $lines
     */
    private function buildBlockScalarValue(array $lines, bool $folded, ChompMode $chomp): string
    {
        // Strip trailing empty lines for chomp Clip and Strip; keep
        // them for Keep. We always retain or drop the *final* newline
        // based on chomp and content presence.
        if ($lines === []) {
            return '';
        }

        // Find the index of the last content line.
        $lastContentIdx = -1;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if ($lines[$i][0] === 'content') {
                $lastContentIdx = $i;
                break;
            }
        }
        if ($lastContentIdx === -1) {
            // Only empty lines.
            return '';
        }

        // Trailing empty lines (after last content). For Clip /
        // Strip, drop them. For Keep, keep them.
        $trailingEmpties = count($lines) - 1 - $lastContentIdx;
        if ($chomp !== ChompMode::Keep) {
            array_splice($lines, $lastContentIdx + 1);
        }

        // Build value with line endings.
        $parts = [];
        if ($folded) {
            // Folded: empty lines fold to a single newline; consecutive
            // content lines join with a space. (Detail: this is a
            // simplification of YAML 1.2 §8.1.3; full spec also handles
            // more-indented lines as literal. Refined when fixtures
            // demand it.)
            $i = 0;
            $n = count($lines);
            while ($i < $n) {
                if ($lines[$i][0] === 'content') {
                    $parts[] = $lines[$i][1];
                    if ($i + 1 < $n) {
                        if ($lines[$i + 1][0] === 'content') {
                            $parts[] = ' ';
                        } else {
                            // Empty line(s) between content: fold N
                            // empty lines to N newlines.
                            $emptyCount = 0;
                            $j = $i + 1;
                            while ($j < $n && $lines[$j][0] === 'empty') {
                                $emptyCount++;
                                $j++;
                            }
                            // YAML §8.1.3: each empty line is one
                            // newline (the first replaces the implicit
                            // space).
                            $parts[] = str_repeat("\n", $emptyCount);
                            $i = $j - 1;
                        }
                    }
                }
                $i++;
            }
        } else {
            // Literal: lines join with newlines literally.
            foreach ($lines as $i => [$kind, $text]) {
                if ($kind === 'content') {
                    $parts[] = $text;
                }
                // After every line (content or empty) emit a newline,
                // except this is composed below as joined newline.
                if ($i < count($lines) - 1) {
                    $parts[] = "\n";
                }
            }
        }

        $value = implode('', $parts);

        // Apply final chomp: ensure exactly the right number of
        // trailing newlines.
        if ($chomp === ChompMode::Strip) {
            $value = rtrim($value, "\n");
        } elseif ($chomp === ChompMode::Clip) {
            $value = rtrim($value, "\n") . "\n";
        } else {
            // Keep: append a newline for each trailing empty line we
            // recorded, plus one final newline for the last content
            // line.
            $value = rtrim($value, "\n") . "\n";
            $value .= str_repeat("\n", $trailingEmpties);
        }

        return $value;
    }

    /**
     * Consume a single-quoted scalar.
     *
     * Per YAML 1.2: `'...'` delimits; the only escape is `''` (doubled
     * single quote) -> `'`. Backslashes and other characters are
     * literal. Multi-line single-quoted strings are not supported in
     * E.01; the scanner throws on a newline before the closing quote.
     */
    private function consumeSingleQuotedScalar(): Token
    {
        $startLine = $this->line;
        $startColumn = $this->column;

        // Capture the parent block context's indent for the
        // quoted-continuation indent check.
        $parentBlockIndent = $this->indentStack === []
            ? -1
            : end($this->indentStack)[0];

        // Consume the opening quote.
        $this->advance();

        $value = '';
        while ($this->pos < $this->length) {
            $c = $this->source[$this->pos];

            if ($c === "\n") {
                $value = rtrim($value, " \t");
                // Validate continuation indent before the line-
                // breaks consumer eats leading whitespace.
                $savedPos = $this->pos;
                $savedLine = $this->line;
                $savedColumn = $this->column;
                $this->advance();
                if (!$this->quotedContinuationIndentValid($parentBlockIndent)) {
                    throw new ParseException(sprintf(
                        'Quoted scalar continuation line at line %d column %d '
                            . 'must be indented more than the parent block '
                            . '(parent indent %d). Tab does not count as '
                            . 'indentation per YAML 1.2 §6.1.',
                        $this->line,
                        $this->column,
                        $parentBlockIndent,
                    ));
                }
                $this->pos = $savedPos;
                $this->line = $savedLine;
                $this->column = $savedColumn;
                $value .= $this->consumeQuotedLineBreaks();
                // Document marker reached during folding (yaml-test-
                // suite RXY3: `---\n'\n...\n'`).
                if ($this->isDocumentMarkerHere()) {
                    throw new ParseException(sprintf(
                        'Unterminated single-quoted scalar at line %d column %d '
                            . '(document marker reached)',
                        $startLine,
                        $startColumn,
                    ));
                }
                continue;
            }

            if ($c === "'") {
                // Doubled single quote -> literal `'`.
                if (($this->source[$this->pos + 1] ?? '') === "'") {
                    $value .= "'";
                    $this->advance();
                    $this->advance();
                    continue;
                }
                // Closing quote.
                $this->advance();

                // Capture trailing trivia after the close (EOL comment etc).
                $trailing = $this->collectTrailingEolTrivia();

                // Consume the line break if at end of line, but
                // only in block context. In a flow container,
                // leave the newline for the flow loop's indent
                // validation and comma checks.
                if ($this->flowDepth === 0
                    && $this->pos < $this->length
                    && $this->source[$this->pos] === "\n"
                ) {
                    $this->advance();
                }

                return new Token(
                    type: TokenType::Scalar,
                    line: $startLine,
                    column: $startColumn,
                    value: $value,
                    style: ScalarStyle::SingleQuoted,
                    leadingTrivia: $this->flushTrivia(),
                    trailingTrivia: $trailing,
                );
            }

            $value .= $this->currentChar();
            $this->advance();
        }

        throw new ParseException(sprintf(
            'Unterminated single-quoted scalar starting at line %d column %d',
            $startLine,
            $startColumn,
        ));
    }

    /**
     * Inside a quoted scalar: consume one or more line breaks plus
     * the leading whitespace of each subsequent line. Per YAML 1.2
     * §7.5.2 / §7.4.1:
     *
     * - A single line break between content lines folds to one space.
     * - Each *additional* line break (blank line) emits a literal `\n`.
     * - Leading whitespace on continuation is stripped from the value.
     *
     * The continuation line must be indented (at least one space or
     * tab). A line that starts at column 1 is treated as outside the
     * scalar's scope and the consumer falls through, eventually
     * raising "Unterminated ...".
     */
    /**
     * Walk back through the emitted token list, skipping BlockEnd
     * and StreamStart, and return the type of the first non-skip
     * token, i.e. the most recent "content-bearing" emission.
     * Returns null when no such token has been emitted yet.
     *
     * Used by the directive-after-content gate (yaml-test-suite
     * EB22, 9HCY): a directive is legal only when this returns
     * null, Directive, or DocumentEnd.
     *
     * @param list<Token> $tokens
     */
    private function lastNonStructuralEmitted(array $tokens): ?TokenType
    {
        for ($i = count($tokens) - 1; $i >= 0; $i--) {
            $t = $tokens[$i]->type;
            if ($t === TokenType::BlockEnd || $t === TokenType::StreamStart) {
                continue;
            }
            return $t;
        }
        return null;
    }

    /**
     * Are we positioned at the start of a document marker line
     * (`---` or `...` at column 1, followed by whitespace, newline,
     * or end-of-input)? Per YAML 1.2 §9.2.
     */
    private function isDocumentMarkerHere(): bool
    {
        if ($this->column !== 1) {
            return false;
        }
        if ($this->pos + 3 > $this->length) {
            return false;
        }
        $three = substr($this->source, $this->pos, 3);
        if ($three !== '---' && $three !== '...') {
            return false;
        }
        $after = $this->source[$this->pos + 3] ?? "\n";
        return $after === ' ' || $after === "\t" || $after === "\n";
    }

    /**
     * After a `\n` inside a quoted scalar, look ahead at the
     * continuation line. Per YAML 1.2 §7.5 the continuation must be
     * indented strictly more than the parent block context (or any
     * indent if at the document root). Leading-space count is
     * what counts as indentation; TAB is content (yaml-test-suite
     * 7A4E, PRH3: TAB-led continuation lines at top level are
     * valid). At parent indent 0 a TAB-only-leading line has 0
     * spaces and fails the rule (yaml-test-suite DK95/01,
     * QB6E).
     *
     * @return bool true if continuation is valid; false (and
     *              position rolled back to before the newline) if
     *              the next line dedented below the parent.
     */
    private function quotedContinuationIndentValid(int $parentBlockIndent): bool
    {
        // The next line's content begins after spaces only. TAB
        // does not count as indentation.
        $i = $this->pos;
        $spaces = 0;
        while ($i < $this->length && $this->source[$i] === ' ') {
            $i++;
            $spaces++;
        }
        // Allow any indent on whitespace-only / empty lines.
        if ($i >= $this->length) {
            return true;
        }
        $c = $this->source[$i];
        if ($c === "\n") {
            return true;
        }
        // Tab as the first non-space byte means content at indent
        // = $spaces, with TAB counted as content. This is valid
        // when $spaces > $parentBlockIndent.
        return $spaces > $parentBlockIndent;
    }

    private function consumeQuotedLineBreaks(): string
    {
        $newlines = 0;
        while ($this->pos < $this->length) {
            $c = $this->source[$this->pos];
            if ($c === "\n") {
                $newlines++;
                $this->advance();
                // Continuation must be indented. If the next line
                // begins with content at column 1 (or end-of-file),
                // we leave the position at that line and let the
                // outer consumer raise "Unterminated".
                $next = $this->source[$this->pos] ?? '';
                if ($next !== ' ' && $next !== "\t" && $next !== "\n") {
                    break;
                }
                // Per YAML 1.2 §9.2 a `---` or `...` line at column 1
                // ends the current document. A quoted scalar may not
                // span document markers. Probe past leading whitespace
                // to check if we landed on one (yaml-test-suite
                // 9MQT/01: `--- "a\n... x\nb"` is invalid).
                $probe = $this->pos;
                while ($probe < $this->length
                    && ($this->source[$probe] === ' '
                        || $this->source[$probe] === "\t")
                ) {
                    $probe++;
                }
                // Document marker only counts at column 1.
                if ($probe === $this->pos
                    && $probe + 3 <= $this->length
                    && (substr($this->source, $probe, 3) === '---'
                        || substr($this->source, $probe, 3) === '...')
                ) {
                    break;
                }
                while ($this->pos < $this->length
                    && ($this->source[$this->pos] === ' '
                        || $this->source[$this->pos] === "\t")
                ) {
                    $this->advance();
                }
                continue;
            }
            break;
        }
        if ($newlines === 0) {
            return '';
        }
        if ($newlines === 1) {
            return ' ';
        }
        return str_repeat("\n", $newlines - 1);
    }

    /**
     * Consume a double-quoted scalar.
     *
     * Per YAML 1.2: `"..."` delimits; the value supports backslash
     * escape sequences for whitespace, control characters, and
     * Unicode (`\xHH`, `\uHHHH`, `\UHHHHHHHH`). Multi-line
     * double-quoted strings are not supported in E.02; the scanner
     * throws on a newline before the closing quote.
     */
    private function consumeDoubleQuotedScalar(): Token
    {
        $startLine = $this->line;
        $startColumn = $this->column;

        // Capture the parent block context's indent for the
        // quoted-continuation indent check (YAML 1.2 §7.5,
        // yaml-test-suite DK95/01).
        $parentBlockIndent = $this->indentStack === []
            ? -1
            : end($this->indentStack)[0];

        // Consume the opening quote.
        $this->advance();

        $value = '';
        while ($this->pos < $this->length) {
            $c = $this->source[$this->pos];

            if ($c === "\n") {
                $value = rtrim($value, " \t");
                // Validate continuation indent BEFORE the line-
                // breaks consumer eats the leading whitespace.
                // Tabs in indent prefix are content of the scalar
                // ONLY when there are enough spaces preceding to
                // satisfy the indent rule (yaml-test-suite
                // DK95/01 vs DK95/02).
                $savedPos = $this->pos;
                $savedLine = $this->line;
                $savedColumn = $this->column;
                $this->advance(); // step past the `\n` to inspect the new line
                if (!$this->quotedContinuationIndentValid($parentBlockIndent)) {
                    throw new ParseException(sprintf(
                        'Quoted scalar continuation line at line %d column %d '
                            . 'must be indented more than the parent block '
                            . '(parent indent %d). Tab does not count as '
                            . 'indentation per YAML 1.2 §6.1.',
                        $this->line,
                        $this->column,
                        $parentBlockIndent,
                    ));
                }
                $this->pos = $savedPos;
                $this->line = $savedLine;
                $this->column = $savedColumn;
                $value .= $this->consumeQuotedLineBreaks();
                // After folding line breaks, if we ended up on a
                // document marker `---` or `...` at column 1, the
                // scalar is unterminated per YAML 1.2 §9.2
                // (yaml-test-suite 9MQT/01).
                if ($this->isDocumentMarkerHere()) {
                    throw new ParseException(sprintf(
                        'Unterminated double-quoted scalar at line %d column %d '
                            . '(document marker reached)',
                        $startLine,
                        $startColumn,
                    ));
                }
                continue;
            }

            if ($c === '"') {
                // Closing quote.
                $this->advance();
                $trailing = $this->collectTrailingEolTrivia();
                // In a flow context, leave the trailing newline for
                // the flow loop to validate (yaml-test-suite ZXT5).
                // In block context, advance past it as before so
                // the next line's indent is computed correctly.
                if ($this->flowDepth === 0
                    && $this->pos < $this->length
                    && $this->source[$this->pos] === "\n"
                ) {
                    $this->advance();
                }
                return new Token(
                    type: TokenType::Scalar,
                    line: $startLine,
                    column: $startColumn,
                    value: $value,
                    style: ScalarStyle::DoubleQuoted,
                    leadingTrivia: $this->flushTrivia(),
                    trailingTrivia: $trailing,
                );
            }

            if ($c === '\\') {
                // Line-break suppression: `\<newline>` removes the
                // newline plus the next line's leading whitespace
                // entirely. No folding, no space, no literal newline.
                if (($this->source[$this->pos + 1] ?? '') === "\n") {
                    $this->advance(); // backslash
                    $this->advance(); // newline
                    while ($this->pos < $this->length
                        && ($this->source[$this->pos] === ' '
                            || $this->source[$this->pos] === "\t")
                    ) {
                        $this->advance();
                    }
                    continue;
                }
                $value .= $this->consumeDoubleQuotedEscape($startLine, $startColumn);
                continue;
            }

            // Multi-byte UTF-8 character: copy all bytes of the
            // sequence before advancing.
            $value .= $this->currentChar();
            $this->advance();
        }

        throw new ParseException(sprintf(
            'Unterminated double-quoted scalar starting at line %d column %d',
            $startLine,
            $startColumn,
        ));
    }

    /**
     * Consume a backslash escape inside a double-quoted scalar and
     * return the resolved bytes.
     */
    private function consumeDoubleQuotedEscape(int $scalarLine, int $scalarColumn): string
    {
        // Consume the backslash.
        $this->advance();
        if ($this->pos >= $this->length) {
            throw new ParseException(sprintf(
                'Unterminated escape sequence in double-quoted scalar at line %d column %d',
                $scalarLine,
                $scalarColumn,
            ));
        }
        $c = $this->source[$this->pos];

        // Simple single-character escapes per YAML 1.2 spec.
        $simple = [
            '0' => "\0",
            'a' => "\x07",
            'b' => "\x08",
            't' => "\t",
            "\t" => "\t",
            'n' => "\n",
            'v' => "\x0B",
            'f' => "\f",
            'r' => "\r",
            'e' => "\x1B",
            ' ' => ' ',
            '"' => '"',
            '/' => '/',
            '\\' => '\\',
            'N' => "\xC2\x85",     // U+0085 NEL
            '_' => "\xC2\xA0",     // U+00A0 NBSP
            'L' => "\xE2\x80\xA8", // U+2028 LINE SEPARATOR
            'P' => "\xE2\x80\xA9", // U+2029 PARAGRAPH SEPARATOR
        ];

        if (isset($simple[$c])) {
            $this->advance();
            return $simple[$c];
        }

        if ($c === 'x') {
            $this->advance();
            return $this->consumeHexEscape(2, $scalarLine, $scalarColumn);
        }
        if ($c === 'u') {
            $this->advance();
            return $this->consumeHexEscape(4, $scalarLine, $scalarColumn);
        }
        if ($c === 'U') {
            $this->advance();
            return $this->consumeHexEscape(8, $scalarLine, $scalarColumn);
        }

        throw new ParseException(sprintf(
            'Unknown escape sequence \\%s in double-quoted scalar at line %d column %d',
            $c,
            $this->line,
            $this->column,
        ));
    }

    /**
     * Consume $count hex digits and return the corresponding UTF-8
     * byte sequence for the resolved code point.
     */
    private function consumeHexEscape(int $count, int $scalarLine, int $scalarColumn): string
    {
        $hex = '';
        for ($i = 0; $i < $count; $i++) {
            if ($this->pos >= $this->length) {
                throw new ParseException(sprintf(
                    'Truncated hex escape in double-quoted scalar at line %d column %d',
                    $scalarLine,
                    $scalarColumn,
                ));
            }
            $c = $this->source[$this->pos];
            if (!ctype_xdigit($c)) {
                throw new ParseException(sprintf(
                    'Invalid hex digit %s in escape sequence at line %d column %d',
                    $this->describeChar($c),
                    $this->line,
                    $this->column,
                ));
            }
            $hex .= $c;
            $this->advance();
        }
        $codepoint = hexdec($hex);
        return mb_chr($codepoint, 'UTF-8') ?: '';
    }

    /**
     * Collect trailing trivia (typically an EOL comment) after a
     * scalar's content closes but before the line break. Skips
     * leading whitespace on the trailing portion.
     *
     * @return list<TriviaToken>
     */
    private function collectTrailingEolTrivia(): array
    {
        $trailing = [];
        // Skip whitespace, capturing the exact bytes so the emitter
        // can reproduce the original gap before the `#`.
        $hadWhitespace = false;
        $gap = '';
        while ($this->pos < $this->length
            && ($this->source[$this->pos] === ' ' || $this->source[$this->pos] === "\t")
        ) {
            $hadWhitespace = true;
            $gap .= $this->source[$this->pos];
            $this->advance();
        }
        // EOL comment requires a leading whitespace gap.
        if ($hadWhitespace
            && $this->pos < $this->length
            && $this->source[$this->pos] === '#'
        ) {
            $startLine = $this->line;
            $startColumn = $this->column;
            $text = '';
            while ($this->pos < $this->length && $this->source[$this->pos] !== "\n") {
                $text .= $this->source[$this->pos];
                $this->advance();
            }
            $trailing[] = TriviaToken::comment($text, $startLine, $startColumn, $gap);
        }
        return $trailing;
    }

    /**
     * Consume a plain scalar.
     *
     * @param bool $stopAtColon If true, stop at `:` followed by
     *                          whitespace/EOL (used when scanning a
     *                          mapping key).
     */
    private function consumePlainScalar(bool $stopAtColon = false): Token
    {
        $startLine = $this->line;
        $startColumn = $this->column;
        $rawStart = $this->pos;

        $value = '';
        $trailing = [];

        while ($this->pos < $this->length) {
            $c = $this->source[$this->pos];

            if ($c === "\n") {
                break;
            }

            // Per YAML 1.2 §7.3.3 a plain scalar may not contain
            // `: ` (colon followed by whitespace). Always break on
            // this sequence. In key context the existing branch
            // below covers it, in value context the bare `:` cannot
            // legally appear inside the scalar text.
            if ($c === ':') {
                $next = $this->source[$this->pos + 1] ?? "\n";
                if ($next === ' ' || $next === "\t" || $next === "\n") {
                    break;
                }
            }

            // End-of-line comment: `#` preceded by whitespace.
            if ($c === '#' && $value !== '' && (
                substr($value, -1) === ' ' || substr($value, -1) === "\t"
            )) {
                // Capture the gap before trimming so the emitter can
                // reproduce the original spacing between value and `#`.
                $trimmed = rtrim($value, " \t");
                $gap = substr($value, strlen($trimmed));
                $value = $trimmed;
                $commentLine = $this->line;
                $commentColumn = $this->column;
                $text = '';
                while ($this->pos < $this->length && $this->source[$this->pos] !== "\n") {
                    $text .= $this->currentChar();
                    $this->advance();
                }
                $trailing[] = TriviaToken::comment($text, $commentLine, $commentColumn, $gap);
                break;
            }

            $value .= $this->currentChar();
            $this->advance();
        }

        $value = rtrim($value, " \t");

        // Multi-line plain scalar continuation per YAML 1.2 §7.3.3.
        // Only when stopAtColon is false (we're in value context, not
        // key context) and the trailing comment branch did not fire.
        $rawSource = null;
        if (!$stopAtColon
            && $trailing === []
            && $this->pos < $this->length
            && $this->source[$this->pos] === "\n"
        ) {
            $parentIndent = $this->indentStack === []
                ? -1
                : end($this->indentStack)[0];
            $continuation = $this->tryConsumePlainContinuation(
                $value,
                $startLine,
                $startColumn,
                $rawStart,
                $parentIndent,
            );
            if ($continuation !== null) {
                [$value, $rawSource] = $continuation;
            }
        }

        // Consume the line break unless we're stopping mid-line at a
        // colon. In that case the caller (scanBlockMapEntry) takes
        // over.
        if (!$stopAtColon
            && $this->pos < $this->length
            && $this->source[$this->pos] === "\n"
        ) {
            $this->advance();
        }

        return new Token(
            type: TokenType::Scalar,
            line: $startLine,
            column: $startColumn,
            value: $value,
            style: ScalarStyle::Plain,
            leadingTrivia: $this->flushTrivia(),
            trailingTrivia: $trailing,
            rawSource: $rawSource,
        );
    }

    /**
     * Try to consume one or more continuation lines of a plain
     * scalar. Per YAML 1.2 §7.3.3:
     *
     * - Continuation lines must be indented strictly more than the
     *   parent block context's indent.
     * - Adjacent continuation lines fold to a single space between
     *   them.
     * - A blank line between two content lines folds to one `\n`
     *   in the value.
     * - Continuation stops on a less-indented line, an indicator
     *   (`#`, `:`, `-` followed by space) at the continuation
     *   indent, or end-of-stream.
     *
     * Returns null if no continuation occurred (parser state is
     * unchanged). Otherwise returns [folded value, raw source span
     * from the scalar's first byte to the end of its last
     * continuation line].
     *
     * @return array{string, string}|null
     */
    private function tryConsumePlainContinuation(
        string $firstLine,
        int $startLine,
        int $startColumn,
        int $rawStart,
        int $parentIndent,
    ): ?array {
        // Snapshot state so we can roll back if the next line is not
        // a continuation.
        $savedPos = $this->pos;
        $savedLine = $this->line;
        $savedColumn = $this->column;

        $value = $firstLine;
        $blankRun = 0;
        $consumedAny = false;
        $lastLineEnd = $this->pos;

        while ($this->pos < $this->length && $this->source[$this->pos] === "\n") {
            // Provisionally consume the newline.
            $this->advance();

            // Measure the indent of the next line.
            $lineStart = $this->pos;
            $indent = 0;
            while ($lineStart + $indent < $this->length
                && $this->source[$lineStart + $indent] === ' '
            ) {
                $indent++;
            }

            // Blank or whitespace-only line.
            if ($lineStart + $indent >= $this->length
                || $this->source[$lineStart + $indent] === "\n"
            ) {
                $blankRun++;
                // Skip indent + (newline handled by next iteration's
                // loop condition).
                while ($this->pos < $this->length
                    && $this->source[$this->pos] === ' '
                ) {
                    $this->advance();
                }
                if ($this->pos >= $this->length) {
                    // EOF. No continuation possible.
                    break;
                }
                // Newline still ahead; loop continues.
                continue;
            }

            // Non-blank line. It must be indented strictly more
            // than the parent indent to qualify as continuation.
            if ($indent <= $parentIndent) {
                break;
            }

            // Document end/start markers terminate any plain scalar
            // even when they appear at a "more indented" position
            // (per spec they always start at column 1, but be
            // defensive).
            if ($indent === 0
                && $lineStart + 3 <= $this->length
                && (substr($this->source, $lineStart, 3) === '---'
                    || substr($this->source, $lineStart, 3) === '...')
            ) {
                break;
            }

            // Per YAML 1.2 §7.3.3 (`ns-plain-multi-line`)
            // continuation lines are `ns-plain-char` content. The
            // first-char restrictions (`ns-plain-first`) apply only
            // to the start of the scalar, NOT to continuations.
            // Only a `#` at line start (a comment-only line) and
            // the doc markers `---` / `...` (already handled above)
            // terminate the continuation. Other reserved indicators
            // (`-`, `?`, `&`, `*`, `!`, etc.) are plain content.
            // Reference: PyYAML scan_plain. yaml-test-suite 3MYT,
            // AB8U, FBC9.
            $first = $this->source[$lineStart + $indent];
            if ($first === '#') {
                break;
            }
            // `%` at line start is NOT a continuation breaker:
            // directives appear only at start-of-stream or after a
            // `...` end marker. A `%` byte mid-document is part of
            // the plain scalar's content (yaml-test-suite XLQ9:
            // `scalar\n%YAML 1.2` folds to one scalar).

            // The line is a continuation. Skip the indent.
            for ($i = 0; $i < $indent; $i++) {
                $this->advance();
            }

            // Read the content of the line (no folding inside the
            // line; trailing whitespace stripped). A `: ` (colon
            // followed by space/tab/newline) inside a continuation
            // line means this line is actually a new mapping entry,
            // not scalar content. Abort continuation per YAML 1.2
            // §7.3.3 and let the outer scanner re-process it.
            $lineContent = '';
            $contentStart = $this->pos;
            $invalid = false;
            while ($this->pos < $this->length && $this->source[$this->pos] !== "\n") {
                $cc = $this->source[$this->pos];
                if ($cc === ':') {
                    $next = $this->source[$this->pos + 1] ?? "\n";
                    if ($next === ' ' || $next === "\t" || $next === "\n") {
                        $invalid = true;
                        break;
                    }
                }
                if ($cc === '#'
                    && $this->pos > $contentStart
                    && ($this->source[$this->pos - 1] === ' '
                        || $this->source[$this->pos - 1] === "\t")
                ) {
                    // ` #` introduces an end-of-line comment per
                    // YAML 1.2 §6.6. Ends this continuation line
                    // cleanly. Merge the current line's content into
                    // the scalar value, skip past the comment, and
                    // stop reading further continuation lines.
                    $lineContent = rtrim($lineContent, " \t");
                    if ($lineContent !== '') {
                        if ($blankRun > 0) {
                            $value .= str_repeat("\n", $blankRun);
                            $blankRun = 0;
                        } else {
                            $value .= ' ';
                        }
                        $value .= $lineContent;
                        $consumedAny = true;
                    }
                    while ($this->pos < $this->length && $this->source[$this->pos] !== "\n") {
                        $this->advance();
                    }
                    $lastLineEnd = $this->pos;
                    break 2;
                }
                $lineContent .= $this->currentChar();
                $this->advance();
            }
            if ($invalid) {
                throw new ParseException(sprintf(
                    'Plain scalar continuation contains an indicator '
                        . 'at line %d column %d',
                    $startLine + substr_count(
                        substr($this->source, $rawStart, $this->pos - $rawStart),
                        "\n",
                    ),
                    $this->pos - $lineStart + 1,
                ));
            }
            $lineContent = rtrim($lineContent, " \t");

            // Empty content after rtrim is treated as a blank line.
            if ($lineContent === '') {
                $blankRun++;
                continue;
            }

            // Fold whitespace between previous content and this line.
            if ($blankRun > 0) {
                $value .= str_repeat("\n", $blankRun);
                $blankRun = 0;
            } else {
                $value .= ' ';
            }
            $value .= $lineContent;
            $consumedAny = true;
            $lastLineEnd = $this->pos;
        }

        if (!$consumedAny) {
            // Roll back: no continuation occurred.
            $this->pos = $savedPos;
            $this->line = $savedLine;
            $this->column = $savedColumn;
            return null;
        }

        // Rewind to the end of the last consumed content line so the
        // outer loop's "consume the trailing newline" branch fires
        // exactly once.
        $this->pos = $lastLineEnd;
        // We didn't track line/column updates accurately during
        // continuation; recompute by scanning rawSource.
        $rawSource = substr($this->source, $rawStart, $this->pos - $rawStart);
        $newlines = substr_count($rawSource, "\n");
        $this->line = $startLine + $newlines;
        $lastNl = strrpos($rawSource, "\n");
        if ($lastNl === false) {
            $this->column = $startColumn + strlen($rawSource);
        } else {
            $this->column = strlen($rawSource) - $lastNl;
        }

        return [$value, $rawSource];
    }

    /**
     * @return list<TriviaToken>
     */
    private function flushTrivia(): array
    {
        $leading = $this->triviaBuffer;
        $this->triviaBuffer = [];
        return $leading;
    }
}
