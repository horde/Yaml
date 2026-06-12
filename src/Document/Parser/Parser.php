<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Document\Parser;

use Horde\Yaml\Document\AnchorIndex;
use Horde\Yaml\Document\LeniencyPolicy;
use Horde\Yaml\Document\Node\AliasNode;
use Horde\Yaml\Document\Node\BlankLineNode;
use Horde\Yaml\Document\Node\CommentNode;
use Horde\Yaml\Document\Node\Directive;
use Horde\Yaml\Document\Node\MapEntry;
use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\Node\MapStyle;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\ScalarStyle;
use Horde\Yaml\Document\Node\SequenceItem;
use Horde\Yaml\Document\Node\SequenceNode;
use Horde\Yaml\Document\Node\SequenceStyle;
use Horde\Yaml\Document\ParseException;
use Horde\Yaml\Document\Token;
use Horde\Yaml\Document\TokenType;
use Horde\Yaml\Document\TriviaToken;
use Horde\Yaml\Document\TriviaType;
use Horde\Yaml\Document\YamlDocument;
use Horde\Yaml\Document\YamlStream;

/**
 * Token[] to YamlStream parser.
 *
 * In C.02 scope:
 *
 *     Stream      -> StreamStart [Directive]* [Document]? StreamEnd
 *     Document    -> DocumentStart? Node DocumentEnd?
 *     Node        -> ScalarNode | MapNode
 *     MapNode     -> BlockMappingStart MapEntry+ BlockEnd
 *     MapEntry    -> Key Scalar Value Node
 *
 * Sequences, flow style, anchors, aliases, tags arrive in later
 * phases.
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/05-parser-strategy-2026-06-12.md §3
 */
final class Parser
{
    /** @var list<Token> */
    private array $tokens = [];
    private int $pos = 0;
    private ?AnchorIndex $currentAnchorIndex = null;
    private LeniencyPolicy $policy;

    public function __construct(?LeniencyPolicy $policy = null)
    {
        $this->policy = $policy ?? LeniencyPolicy::hordeCompat();
    }

    /**
     * @param list<Token> $tokens
     */
    public function parse(array $tokens): YamlStream
    {
        $this->tokens = $tokens;
        $this->pos = 0;

        $stream = new YamlStream();

        $this->expect(TokenType::StreamStart);

        // Stream-leading trivia: comments and blank lines that appear
        // before any directive or `---` start-marker. Carried by the
        // first such token's leadingTrivia. Drain here so the trivia
        // is captured at stream level, not consumed silently.
        //
        // Only drain when the first token is a Directive, DocumentStart,
        // or StreamEnd (zero-document file). When the stream opens
        // directly with content (no `---`), the trivia belongs to the
        // root container and is drained later by the BlockMappingStart /
        // BlockSequenceStart migration path. Leave it there.
        $first = $this->peek();
        if ($first !== null
            && count($first->leadingTrivia) > 0
            && (
                $first->type === TokenType::Directive
                || $first->type === TokenType::DocumentStart
                || $first->type === TokenType::StreamEnd
            )
        ) {
            $this->drainTriviaIntoStream($first->leadingTrivia, $stream, leading: true);
            $this->stripLeadingTriviaAt($this->pos);
        }

        // Buffer of directives whose handles apply to the next
        // document. The default %TAG handles per spec (! -> !,
        // !! -> tag:yaml.org,2002:) are seeded into every document
        // in addition to any user directives.
        $pendingTagHandles = [];
        $sawYamlDirective = false;
        $sawAnyDirective = false;
        while ($this->peek()?->type === TokenType::Directive) {
            $token = $this->consume();
            $directive = Directive::fromValue($token->value ?? '');
            $this->validateDirective($directive, $sawYamlDirective, $token->line);
            if ($directive->getName() === 'YAML') {
                $sawYamlDirective = true;
            }
            $sawAnyDirective = true;
            $stream->appendDirective($directive);
            if ($directive->getName() === 'TAG') {
                [$handle, $prefix] = $this->splitTagDirective(
                    $directive->getParameters(),
                );
                if ($handle !== null && $prefix !== null) {
                    $pendingTagHandles[$handle] = $prefix;
                }
            }
        }

        // Per YAML 1.2 §6.8: directives must be terminated by a `---`
        // start-of-document marker. A directive followed only by a
        // `...` end-marker (or by stream end) violates the spec.
        if ($sawAnyDirective
            && !$this->policy->acceptDirectiveOnlyDocument
            && $this->peek()?->type !== TokenType::DocumentStart
        ) {
            throw new ParseException(
                'Directive section must be terminated by a `---` start '
                    . 'marker (allow with acceptDirectiveOnlyDocument)',
            );
        }

        // Multi-document support: loop until StreamEnd, parsing each
        // document in turn. Documents are separated by `---` markers
        // (which the scanner emits as DocumentStart tokens).
        $sawAnyDocument = false;
        $previousDocHadEndMarker = false;
        while ($this->peek()?->type !== TokenType::StreamEnd) {
            // Per YAML 1.2 §9 a bare `...` end-marker ends the
            // current doc; consecutive `...` markers (possibly with
            // trivia between) do NOT spawn empty documents. Only an
            // explicit `---` start-marker creates a doc body.
            // After a doc has been parsed, drain leftover
            // DocumentEnd tokens so the next iteration starts at the
            // genuine next-doc boundary (yaml-test-suite M7A3).
            if ($sawAnyDocument) {
                while ($this->peek()?->type === TokenType::DocumentEnd) {
                    $this->consume();
                    $previousDocHadEndMarker = true;
                }
                if ($this->peek()?->type === TokenType::StreamEnd) {
                    break;
                }
                // A subsequent root node requires either an explicit
                // `---` (DocumentStart), a directive, or that the
                // previous doc ended with `...` (DocumentEnd).
                // Without one of those, the trailing content is a
                // parse error (yaml-test-suite KS4U, C2SP, BS4K).
                $next = $this->peek();
                if (!$previousDocHadEndMarker
                    && $next !== null
                    && $next->type !== TokenType::DocumentStart
                    && $next->type !== TokenType::Directive
                ) {
                    throw new ParseException(sprintf(
                        'Unexpected content after document at line %d column %d '
                            . '(missing `---` or `...` marker)',
                        $next->line,
                        $next->column,
                    ));
                }
            }
            $beforePos = $this->pos;
            $doc = $this->parseDocument();
            // Guard against infinite loop if parseDocument consumed
            // nothing (happens when input is malformed and the new
            // empty-body relaxation in Chapter AB would otherwise
            // spin). Force progress by surfacing an error.
            if ($this->pos === $beforePos) {
                $this->expect(TokenType::StreamEnd);
            }
            $doc->setParentStream($stream);
            // Apply pending %TAG handles to this document, then reset.
            foreach ($pendingTagHandles as $h => $p) {
                $doc->setTagHandle($h, $p);
            }
            $pendingTagHandles = [];
            $stream->appendInternalDocument($doc);
            $sawAnyDocument = true;
            $previousDocHadEndMarker = $doc->getEndMarker();

            // Drain the next structural token's leadingTrivia onto
            // THIS doc as trailing trivia. The next token is either a
            // DocumentStart (sibling doc starts), a Directive (a new
            // directive section), or StreamEnd. The trivia between
            // this doc's body and the next boundary belongs to this
            // doc; if it weren't drained here the next consume() would
            // discard it.
            $next = $this->peek();
            if ($next !== null
                && count($next->leadingTrivia) > 0
                && $next->type !== TokenType::StreamEnd
            ) {
                $this->drainTriviaIntoDoc($next->leadingTrivia, $doc);
                $this->stripLeadingTriviaAt($this->pos);
            }

            // After a document, more directives may appear.
            while ($this->peek()?->type === TokenType::Directive) {
                $token = $this->consume();
                $directive = Directive::fromValue($token->value ?? '');
                $this->validateDirective($directive, $sawYamlDirective, $token->line);
                if ($directive->getName() === 'YAML') {
                    $sawYamlDirective = true;
                }
                $stream->appendDirective($directive);
                if ($directive->getName() === 'TAG') {
                    [$handle, $prefix] = $this->splitTagDirective(
                        $directive->getParameters(),
                    );
                    if ($handle !== null && $prefix !== null) {
                        $pendingTagHandles[$handle] = $prefix;
                    }
                }
            }
        }

        // Stream-trailing trivia (or stream-leading trivia for a
        // documentless stream): everything that sat between the last
        // structural close and StreamEnd.
        $endTok = $this->peek();
        if ($endTok !== null
            && $endTok->type === TokenType::StreamEnd
            && count($endTok->leadingTrivia) > 0
        ) {
            $this->drainTriviaIntoStream(
                $endTok->leadingTrivia,
                $stream,
                leading: !$sawAnyDocument,
            );
            // Don't bother stripping. StreamEnd is consumed next and
            // its trivia is otherwise discarded on consume().
        }

        $this->expect(TokenType::StreamEnd);

        return $stream;
    }

    /**
     * Validate a directive against the active LeniencyPolicy. Throws
     * when the policy disallows a deviation.
     */
    private function validateDirective(
        Directive $directive,
        bool $sawYamlDirective,
        int $line,
    ): void {
        if ($directive->getName() === 'YAML') {
            if ($sawYamlDirective && !$this->policy->acceptDuplicateYamlDirective) {
                throw new ParseException(sprintf(
                    'Duplicate %%YAML directive at line %d (allow with '
                        . 'acceptDuplicateYamlDirective)',
                    $line,
                ));
            }
            // Spec form: `%YAML <major>.<minor>` followed optionally
            // by a trailing comment. Anything else is malformed.
            $args = trim($directive->getParameters());
            // Strip a trailing `#...` line comment if present (must be
            // separated from the version by whitespace per §6.8.1).
            $args = preg_replace('/\s+#.*$/', '', $args) ?? $args;
            if (!$this->policy->acceptMalformedYamlDirectiveArguments
                && !preg_match('/^\d+\.\d+$/', $args)
            ) {
                throw new ParseException(sprintf(
                    'Malformed %%YAML directive arguments "%s" at line %d '
                        . '(allow with acceptMalformedYamlDirectiveArguments)',
                    $directive->getParameters(),
                    $line,
                ));
            }
        }
    }

    /**
     * Split the parameter portion of a `%TAG` directive into the
     * handle and the URI prefix. Returns [null, null] on malformed
     * input.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function splitTagDirective(string $params): array
    {
        $params = trim($params);
        if ($params === '') {
            return [null, null];
        }
        $space = strpos($params, ' ');
        if ($space === false) {
            $tab = strpos($params, "\t");
            if ($tab === false) {
                return [null, null];
            }
            $space = $tab;
        }
        $handle = substr($params, 0, $space);
        $prefix = ltrim(substr($params, $space + 1));
        if ($handle === '' || $prefix === '') {
            return [null, null];
        }
        return [$handle, $prefix];
    }

    private function parseDocument(): YamlDocument
    {
        $doc = new YamlDocument();
        $this->currentAnchorIndex = $doc->anchors();

        if ($this->peek()?->type === TokenType::DocumentStart) {
            $this->consume();
            $doc->setStartMarker(true);
        }

        $root = $this->parseNode();
        // Empty document body (e.g. between two `---` markers) is
        // legal: root stays null and getRoot() returns null.
        if ($root !== null) {
            $doc->setRootInternal($root);
        }

        if ($this->peek()?->type === TokenType::DocumentEnd) {
            $this->consume();
            $doc->setEndMarker(true);
        }

        $this->currentAnchorIndex = null;

        return $doc;
    }

    /**
     * Parse a single node (scalar / mapping / sequence / alias) at
     * the current position. Pending Anchor and Tag tokens are
     * consumed and applied to the resulting node. Alias tokens
     * produce an AliasNode directly.
     */
    private function parseNode(): MapNode|SequenceNode|ScalarNode|AliasNode|null
    {
        $token = $this->peek();
        if ($token === null) {
            return null;
        }

        // Properties: anchor and tag tokens precede the node and
        // attach to it. Per YAML 1.2 §6.9 a node carries at most ONE
        // anchor and ONE tag. Two anchors or two tags stacked here
        // (with no intervening node-introducer to separate them)
        // mean both would attach to the same node, which is invalid
        // (yaml-test-suite 4JVG).
        $anchor = null;
        $tag = null;
        while (true) {
            $t = $this->peek();
            if ($t === null) {
                break;
            }
            if ($t->type === TokenType::Anchor) {
                if ($anchor !== null) {
                    throw new ParseException(sprintf(
                        'Multiple anchors on a single node at line %d column %d',
                        $t->line,
                        $t->column,
                    ));
                }
                $anchor = $this->consume()->value;
                continue;
            }
            if ($t->type === TokenType::Tag) {
                if ($tag !== null) {
                    throw new ParseException(sprintf(
                        'Multiple tags on a single node at line %d column %d',
                        $t->line,
                        $t->column,
                    ));
                }
                $tag = $this->consume()->value;
                continue;
            }
            break;
        }

        $token = $this->peek();
        if ($token === null) {
            // Anchor or tag with no following node. Emit an empty
            // scalar to attach them to.
            if ($anchor !== null || $tag !== null) {
                $node = new ScalarNode(value: null, style: ScalarStyle::Plain);
                if ($anchor !== null) {
                    $node->setAnchor($anchor);
                    $this->currentAnchorIndex?->register($anchor, $node);
                }
                if ($tag !== null) {
                    $node->setTag($tag);
                }
                return $node;
            }
            return null;
        }

        $node = match ($token->type) {
            TokenType::Scalar => $this->parseScalar(),
            TokenType::BlockMappingStart => $this->parseBlockMapping(),
            TokenType::BlockSequenceStart => $this->parseBlockSequence(),
            TokenType::FlowMappingStart => $this->parseFlowMapping(),
            TokenType::FlowSequenceStart => $this->parseFlowSequence(),
            TokenType::Alias => $this->parseAlias(),
            default => null,
        };

        // Per YAML 1.2 §7.1 an alias references an existing node
        // and may NOT itself carry an anchor or tag (yaml-test-suite
        // SR86: `&b *a`).
        if ($node instanceof AliasNode && ($anchor !== null || $tag !== null)) {
            throw new ParseException(sprintf(
                'Alias node may not be anchored or tagged at line %d column %d',
                $token->line,
                $token->column,
            ));
        }

        if ($node === null) {
            // The next token doesn't introduce a node (e.g. Key,
            // BlockEnd). If we collected an anchor or tag, materialise
            // an empty scalar so they have something to attach to.
            // YAML 1.2 §7.1: an anchored or tagged node may have an
            // empty content position.
            if ($anchor !== null || $tag !== null) {
                $node = new ScalarNode(value: null, style: ScalarStyle::Plain);
                if ($anchor !== null) {
                    $node->setAnchor($anchor);
                    $this->currentAnchorIndex?->register($anchor, $node);
                }
                if ($tag !== null) {
                    $node->setTag($tag);
                }
                return $node;
            }
            return null;
        }

        // Apply anchor and tag if collected. Anchors register with
        // the document's index; tags are stamped verbatim on the node.
        if ($anchor !== null && method_exists($node, 'setAnchor')) {
            $node->setAnchor($anchor);
            $this->currentAnchorIndex?->register($anchor, $node);
        }
        if ($tag !== null && method_exists($node, 'setTag')) {
            $node->setTag($tag);
        }

        return $node;
    }

    private function parseAlias(): AliasNode
    {
        $token = $this->expect(TokenType::Alias);
        $node = new AliasNode($token->value ?? '', $this->currentAnchorIndex);
        $node->setPosition($token->line, $token->column);
        return $node;
    }

    private function parseBlockMapping(): MapNode
    {
        $start = $this->expect(TokenType::BlockMappingStart);
        $map = new MapNode();
        $map->setPosition($start->line, $start->column);

        // Drain leading trivia from BlockMappingStart into the map's
        // children. These are comments and blank lines that appeared
        // before the map's first entry within the map's lexical scope.
        $this->migrateTrivia($start->leadingTrivia, $map);

        while (
            $this->peek()?->type === TokenType::Key
            || $this->peek()?->type === TokenType::Anchor
            || $this->peek()?->type === TokenType::Tag
        ) {
            // Properties (Anchor / Tag) preceding a key apply to the
            // next entry's key node. Consume and stash them so the
            // entry can attach them. Per yaml-test-suite HMQ5 / ZWK4
            // a key may be tagged and/or anchored.
            $pendingAnchor = null;
            $pendingTag = null;
            while (
                $this->peek()?->type === TokenType::Anchor
                || $this->peek()?->type === TokenType::Tag
            ) {
                $tok = $this->consume();
                if ($tok->type === TokenType::Anchor) {
                    $pendingAnchor = $tok;
                } else {
                    $pendingTag = $tok;
                }
            }
            // After draining properties the next token must be Key.
            if ($this->peek()?->type !== TokenType::Key) {
                break;
            }
            $keyToken = $this->peek();
            // Drain leading trivia accumulated before this entry. The
            // scanner usually places it on the Scalar after the Key
            // (see Scanner.consumePlainScalar), but we drain both to
            // be defensive.
            $this->migrateTrivia($keyToken->leadingTrivia, $map);
            $next = $this->tokens[$this->pos + 1] ?? null;
            if ($next !== null && $next->type === TokenType::Scalar) {
                $this->migrateTrivia($next->leadingTrivia, $map);
            }
            $entry = $this->parseBlockMapEntry();
            // Properties on the key are stored as anchor/tag on the
            // key node, mirroring how an inline anchored key
            // (`&anchor key:`) is handled. AliasNode keys do not
            // accept anchors/tags (an alias references an existing
            // node). Silently ignore those cases.
            $keyNode = $entry->getKey();
            if ($pendingAnchor !== null && !($keyNode instanceof AliasNode)) {
                $keyNode->setAnchor($pendingAnchor->value ?? '');
            }
            if ($pendingTag !== null && !($keyNode instanceof AliasNode)) {
                $keyNode->setTag($pendingTag->value ?? '');
            }
            $map->appendChildInternal($entry);
        }

        // Drain leading trivia from BlockEnd: comments/blanks that
        // appeared after the last entry but before the dedent.
        $endToken = $this->peek();
        if ($endToken !== null && $endToken->type === TokenType::BlockEnd) {
            $this->migrateTrivia($endToken->leadingTrivia, $map);
        }

        $this->expect(TokenType::BlockEnd);
        return $map;
    }

    private function parseBlockSequence(): SequenceNode
    {
        $start = $this->expect(TokenType::BlockSequenceStart);
        $seq = new SequenceNode();
        $seq->setPosition($start->line, $start->column);

        $this->migrateTrivia($start->leadingTrivia, $seq);

        while ($this->peek()?->type === TokenType::BlockEntry) {
            $entryToken = $this->peek();
            $this->migrateTrivia($entryToken->leadingTrivia, $seq);
            $item = $this->parseBlockSequenceItem();
            $seq->appendChildInternal($item);
        }

        $endToken = $this->peek();
        if ($endToken !== null && $endToken->type === TokenType::BlockEnd) {
            $this->migrateTrivia($endToken->leadingTrivia, $seq);
        }

        $this->expect(TokenType::BlockEnd);
        return $seq;
    }

    /**
     * Migrate a list of trivia tokens (comments, blank-line groups)
     * into a container's children list as CommentNode and
     * BlankLineNode instances, preserving source order. The trivia
     * is treated as first-class content per the comments-as-first-
     * class commitment; no ownership or attachment to nearby
     * entries is implied.
     *
     * @param list<TriviaToken>            $trivia
     * @param MapNode|SequenceNode         $container
     */
    private function migrateTrivia(array $trivia, MapNode|SequenceNode $container): void
    {
        foreach ($trivia as $t) {
            if ($t->type === TriviaType::Comment) {
                $node = new CommentNode($t->text, 0, $t->gap);
                $node->setPosition($t->line, $t->column);
                $container->appendChildInternal($node);
                continue;
            }
            if ($t->type === TriviaType::BlankLines) {
                $node = new BlankLineNode($t->count);
                $node->setPosition($t->line, $t->column);
                $container->appendChildInternal($node);
            }
        }
    }

    /**
     * Drain a TriviaToken list into a stream's leading or trailing
     * trivia slot. Materializes CommentNode and BlankLineNode in
     * source order. Used to capture pre-stream and post-stream
     * trivia (yaml round-trip §18 cases L1, L2, L3).
     *
     * @param list<TriviaToken> $trivia
     */
    private function drainTriviaIntoStream(
        array $trivia,
        YamlStream $stream,
        bool $leading,
    ): void {
        foreach ($trivia as $t) {
            $node = $this->triviaToNode($t);
            if ($node === null) {
                continue;
            }
            if ($leading) {
                $stream->appendLeadingTriviaInternal($node);
            } else {
                $stream->appendTrailingTriviaInternal($node);
            }
        }
    }

    /**
     * Drain a TriviaToken list onto a document's trailing-trivia slot.
     * Used to capture inter-document trivia (yaml round-trip §18 case L4).
     *
     * @param list<TriviaToken> $trivia
     */
    private function drainTriviaIntoDoc(array $trivia, YamlDocument $doc): void
    {
        foreach ($trivia as $t) {
            $node = $this->triviaToNode($t);
            if ($node === null) {
                continue;
            }
            $doc->appendTrailingTriviaInternal($node);
        }
    }

    /**
     * Materialize a TriviaToken as a CommentNode or BlankLineNode.
     * Returns null for unsupported trivia kinds (none today).
     */
    private function triviaToNode(TriviaToken $t): CommentNode|BlankLineNode|null
    {
        if ($t->type === TriviaType::Comment) {
            $node = new CommentNode($t->text, 0, $t->gap);
            $node->setPosition($t->line, $t->column);
            return $node;
        }
        if ($t->type === TriviaType::BlankLines) {
            $node = new BlankLineNode($t->count);
            $node->setPosition($t->line, $t->column);
            return $node;
        }
        return null;
    }

    /**
     * Replace the token at $idx in `$this->tokens` with a fresh Token
     * that has the same fields except an empty leadingTrivia list.
     * Used after a drain to prevent the trivia from being re-attached
     * by downstream code paths that read leadingTrivia (e.g. the
     * BlockMappingStart drain in parseBlockMap).
     */
    private function stripLeadingTriviaAt(int $idx): void
    {
        $tok = $this->tokens[$idx] ?? null;
        if ($tok === null) {
            return;
        }
        $this->tokens[$idx] = new Token(
            type: $tok->type,
            line: $tok->line,
            column: $tok->column,
            value: $tok->value,
            style: $tok->style,
            chomp: $tok->chomp,
            indentIndicator: $tok->indentIndicator,
            leadingTrivia: [],
            trailingTrivia: $tok->trailingTrivia,
            rawSource: $tok->rawSource,
        );
    }

    /**
     * Replace the token at $idx with a copy whose leadingTrivia has
     * had its first entry shifted off. Used by the F2 EOL-on-key
     * extraction path so a same-line `#` comment lifted to the
     * entry's eolComment isn't also emitted as a child of the
     * nested container.
     */
    private function stripFirstLeadingTriviaAt(int $idx): void
    {
        $tok = $this->tokens[$idx] ?? null;
        if ($tok === null || count($tok->leadingTrivia) === 0) {
            return;
        }
        $remaining = $tok->leadingTrivia;
        array_shift($remaining);
        $this->tokens[$idx] = new Token(
            type: $tok->type,
            line: $tok->line,
            column: $tok->column,
            value: $tok->value,
            style: $tok->style,
            chomp: $tok->chomp,
            indentIndicator: $tok->indentIndicator,
            leadingTrivia: $remaining,
            trailingTrivia: $tok->trailingTrivia,
            rawSource: $tok->rawSource,
        );
    }

    private function parseBlockSequenceItem(): SequenceItem
    {
        $entryToken = $this->expect(TokenType::BlockEntry);

        // EOL comment, if any, is on the upcoming value token's
        // trailing trivia.
        $valueLeader = $this->peek();
        $eolComment = null;
        if ($valueLeader !== null && $valueLeader->type === TokenType::Scalar) {
            $eolComment = $this->extractEolCommentFromTrailingTrivia($valueLeader);
        }

        $value = $this->parseNode();
        if ($value === null) {
            throw new ParseException(sprintf(
                'Expected value after BlockEntry at line %d column %d',
                $entryToken->line,
                $entryToken->column,
            ));
        }

        $item = new SequenceItem($value, $eolComment);
        $item->setPosition($entryToken->line, $entryToken->column);
        return $item;
    }

    private function parseFlowSequence(): SequenceNode
    {
        $start = $this->expect(TokenType::FlowSequenceStart);
        $seq = new SequenceNode(style: SequenceStyle::Flow);
        $seq->setPosition($start->line, $start->column);

        // Multi-line flow source span goes onto FlowFormat for
        // round-trip preservation.
        if ($start->rawSource !== null) {
            $seq->setFlowFormat(new \Horde\Yaml\Document\Node\FlowFormat(
                singleLine: false,
                rawText: $start->rawSource,
            ));
        }

        $expectingItem = true;
        while (true) {
            $token = $this->peek();
            if ($token === null) {
                throw new ParseException('Unterminated flow sequence');
            }
            if ($token->type === TokenType::FlowSequenceEnd) {
                $this->consume();
                return $seq;
            }
            if ($token->type === TokenType::FlowEntry) {
                $this->consume();
                $expectingItem = true;
                continue;
            }

            // Item value.
            $itemValue = $this->parseNode();
            if ($itemValue === null) {
                throw new ParseException(sprintf(
                    'Unexpected %s in flow sequence',
                    $token->type->name,
                ));
            }

            // Flow pair: `scalar : value` inside a flow sequence is a
            // single-entry mapping per YAML 1.2 §7.4. The key may be
            // any flow node; the parser produces it as a Node and
            // wraps it in a Flow MapNode entry.
            if ($this->peek()?->type === TokenType::Value) {
                $this->consume();
                $valueNode = $this->parseNode();
                $pairValue = $valueNode ?? new ScalarNode(
                    value: null,
                    style: ScalarStyle::Plain,
                );
                $keyForEntry = $itemValue instanceof ScalarNode
                    ? $itemValue
                    : new ScalarNode(value: '', style: ScalarStyle::Plain);
                $pair = new MapNode(style: MapStyle::Flow);
                $pair->setPosition($itemValue->line(), $itemValue->column());
                $entry = new MapEntry($keyForEntry, $pairValue);
                $entry->setPosition($itemValue->line(), $itemValue->column());
                $pair->appendChildInternal($entry);
                $itemValue = $pair;
            }

            $item = new SequenceItem($itemValue);
            $item->setPosition($token->line, $token->column);
            $seq->appendChildInternal($item);
            $expectingItem = false;
        }
    }

    private function parseFlowMapping(): MapNode
    {
        $start = $this->expect(TokenType::FlowMappingStart);
        $map = new MapNode(style: MapStyle::Flow);
        $map->setPosition($start->line, $start->column);

        if ($start->rawSource !== null) {
            $map->setFlowFormat(new \Horde\Yaml\Document\Node\FlowFormat(
                singleLine: false,
                rawText: $start->rawSource,
            ));
        }

        while (true) {
            $token = $this->peek();
            if ($token === null) {
                throw new ParseException('Unterminated flow mapping');
            }
            if ($token->type === TokenType::FlowMappingEnd) {
                $this->consume();
                return $map;
            }
            if ($token->type === TokenType::FlowEntry) {
                $this->consume();
                continue;
            }

            // Key. Any value-position node (scalar, sequence,
            // mapping, alias, or null). Compound flow keys come
            // from `[a,b]: c` form per YAML 1.2 §7.4 and align with
            // the block-mapping compound-key support.
            $keyNode = $this->parseNode();
            if ($keyNode === null) {
                throw new ParseException(sprintf(
                    'Expected key in flow mapping at line %d column %d',
                    $token->line,
                    $token->column,
                ));
            }

            // Optional Value token.
            $value = new ScalarNode(value: null, style: ScalarStyle::Plain);
            if ($this->peek()?->type === TokenType::Value) {
                $this->consume();
                $valueNode = $this->parseNode();
                if ($valueNode !== null) {
                    $value = $valueNode;
                }
            }

            $entry = new MapEntry($keyNode, $value);
            $entry->setPosition($keyNode->line(), $keyNode->column());
            $map->appendChildInternal($entry);
        }
    }

    /**
     * If the given token's trailingTrivia contains a comment, build
     * a CommentNode and return it. Used by parseBlockMapEntry and
     * parseBlockSequenceItem to populate the entry's eolComment slot.
     *
     * Note: this does not modify the token (which is readonly). The
     * trailing trivia is consumed conceptually by being assigned to
     * the AST; the token stays untouched and will not be re-emitted
     * by anything downstream.
     */
    private function extractEolCommentFromTrailingTrivia(Token $token): ?CommentNode
    {
        foreach ($token->trailingTrivia as $t) {
            if ($t->type === TriviaType::Comment) {
                $node = new CommentNode($t->text, 0, $t->gap);
                $node->setPosition($t->line, $t->column);
                return $node;
            }
        }
        return null;
    }

    private function parseBlockMapEntry(): MapEntry
    {
        $keyToken = $this->expect(TokenType::Key);

        // Key node. Any value-position node. The common case is a
        // ScalarNode (`key: value`); compound keys come from the
        // explicit-key form `? key\n: value` per YAML 1.2 §8.1.3 and
        // may be a MapNode, SequenceNode, or AliasNode. The scope
        // doc (§2.2) was updated 2026-06-18 to admit compound keys.
        $keyPeek = $this->peek();
        if ($keyPeek === null) {
            throw new ParseException(sprintf(
                'Expected key after Key indicator at line %d column %d',
                $keyToken->line,
                $keyToken->column,
            ));
        }
        if ($keyPeek->type === TokenType::Scalar) {
            $keyNode = $this->buildScalarNode($this->consume());
        } else {
            $compound = $this->parseNode();
            if ($compound === null) {
                throw new ParseException(sprintf(
                    'Expected key node after Key indicator at line %d column %d',
                    $keyToken->line,
                    $keyToken->column,
                ));
            }
            $keyNode = $compound;
        }

        $valueToken = $this->expect(TokenType::Value);

        // Capture the trailing trivia of the upcoming value token to
        // produce the entry's EOL comment, if any.
        $valueLeader = $this->peek();
        $eolComment = null;
        if ($valueLeader !== null && $valueLeader->type === TokenType::Scalar) {
            $eolComment = $this->extractEolCommentFromTrailingTrivia($valueLeader);
        }

        // F2: when the value is a nested block (mapping/sequence),
        // the scanner emits a BlockMappingStart / BlockSequenceStart
        // for the inner container next. A `# comment` written on the
        // SAME line as `:` lands on that inner token's leadingTrivia.
        // Extract it as the entry's EOL comment so round-trip puts
        // the `#` back on the colon's line instead of as a standalone
        // comment under the nested value.
        if (
            $eolComment === null
            && $valueLeader !== null
            && (
                $valueLeader->type === TokenType::BlockMappingStart
                || $valueLeader->type === TokenType::BlockSequenceStart
            )
            && count($valueLeader->leadingTrivia) > 0
        ) {
            $first = $valueLeader->leadingTrivia[0];
            if (
                $first->type === TriviaType::Comment
                && $first->line === $valueToken->line
            ) {
                $eolComment = new CommentNode($first->text, 0, $first->gap);
                $eolComment->setPosition($first->line, $first->column);
                // Strip this one trivia entry from the inner token so
                // it isn't re-emitted as a child of the nested value.
                $this->stripFirstLeadingTriviaAt($this->pos);
            }
        }

        // Value: a scalar, a nested mapping, or other node types.
        $value = $this->parseNode();
        if ($value === null) {
            throw new ParseException(sprintf(
                'Expected value after Value indicator at line %d column %d',
                $valueToken->line,
                $valueToken->column,
            ));
        }

        $entry = new MapEntry($keyNode, $value, $eolComment);
        $entry->setPosition($keyToken->line, $keyToken->column);
        return $entry;
    }

    private function parseScalar(): ScalarNode
    {
        return $this->buildScalarNode($this->consume());
    }

    private function buildScalarNode(Token $token): ScalarNode
    {
        $style = $token->style instanceof ScalarStyle ? $token->style : ScalarStyle::Plain;
        $node = new ScalarNode(
            value: $token->value ?? '',
            style: $style,
            rawSource: $token->rawSource,
            chomp: $token->chomp,
            indentIndicator: $token->indentIndicator,
        );
        $node->setPosition($token->line, $token->column);
        return $node;
    }

    private function peek(): ?Token
    {
        return $this->tokens[$this->pos] ?? null;
    }

    private function consume(): Token
    {
        return $this->tokens[$this->pos++];
    }

    private function expect(TokenType $type): Token
    {
        $token = $this->peek();
        if ($token === null || $token->type !== $type) {
            throw new ParseException(sprintf(
                'Expected %s at line %d column %d, got %s',
                $type->name,
                $token?->line ?? 0,
                $token?->column ?? 0,
                $token?->type->name ?? 'end-of-stream',
            ));
        }
        return $this->consume();
    }
}
