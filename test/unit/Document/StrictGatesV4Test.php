<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\LeniencyPolicy;
use Horde\Yaml\Document\ParseException;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Bucket 6 strict gates: yaml-test-suite cases that previously
 * loaded without raising and now correctly throw under
 * LeniencyPolicy::strictYaml12(). Each test pairs a positive
 * (must throw) assertion with at least one negative case that
 * must still load. This prevents the gate from over-firing.
 */
#[CoversNothing]
final class StrictGatesV4Test extends TestCase
{
    private function strict(): YamlStringLoader
    {
        return new YamlStringLoader(policy: LeniencyPolicy::strictYaml12());
    }

    /**
     * yaml-test-suite SY6V: `&anchor - sequence entry`.
     *
     * Per YAML 1.2 §8.2.1 a block sequence's `-` indicator must
     * begin a line (after optional indentation). `&anchor` followed
     * by a `-` indicator on the SAME LINE puts the dash mid-line at
     * deeper indent; the spec rejects this. Anchor on its own line
     * preceding an indented block sequence remains valid.
     */
    public function testAnchorOnSameLineAsBlockSequenceIndicatorErrors(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/Property cannot appear before block sequence indicator/');
        $this->strict()->load("&anchor - sequence entry\n");
    }

    public function testAnchorOnOwnLineBeforeBlockSequenceIsValid(): void
    {
        // Anchor on its own line, sequence on next line at column 1.
        $stream = $this->strict()->load("&anchor\n- one\n- two\n");
        $this->assertCount(1, $stream->getDocuments());
    }

    public function testAnchorOnMappingValueWithBlockSequenceBelowIsValid(): void
    {
        // `foo: &node\n- a`. Anchor on the value, sequence at the
        // same indent as the parent mapping (yaml-test-suite
        // RLU9-family). Must NOT trigger the SY6V gate.
        $stream = $this->strict()->load("foo: &node\n- a\n- b\n");
        $this->assertCount(1, $stream->getDocuments());
    }

    /**
     * yaml-test-suite EB22 / 9HCY: directive after document content.
     *
     * Per YAML 1.2 §6.8 directives appear before a document's
     * content node. A `%YAML` or `%TAG` directive after the first
     * content node, without an intervening `...` end-marker, is
     * invalid.
     */
    public function testDirectiveAfterDocumentContentErrors(): void
    {
        // EB22: scalar1 has a trailing # comment that terminates
        // the plain scalar; the next-line `%YAML` is a directive
        // after content.
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/Directive after document content/');
        $this->strict()->load("---\nscalar1 # comment\n%YAML 1.2\n---\nscalar2\n");
    }

    public function testTagDirectiveAfterDocumentContentErrors(): void
    {
        // 9HCY: tagged-scalar root then %TAG directive without
        // intervening ... end marker.
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/Directive after document content/');
        $this->strict()->load(
            "!foo \"bar\"\n%TAG ! tag:example.com,2000:app/\n---\n!foo \"bar\"\n"
        );
    }

    public function testDirectiveAfterDocumentEndMarkerIsValid(): void
    {
        // Directive between documents (after `...`) is OK.
        $stream = $this->strict()->load(
            "---\nscalar1\n...\n%YAML 1.2\n---\nscalar2\n"
        );
        $this->assertCount(2, $stream->getDocuments());
    }

    public function testMultiLinePlainScalarIncludingPercentLineIsValid(): void
    {
        // yaml-test-suite XLQ9: `scalar\n%YAML 1.2` is a multi-line
        // plain scalar (the % is folded into the scalar text via
        // tryConsumePlainContinuation), not a directive. Must not
        // trip the directive-after-content gate.
        $stream = $this->strict()->load("---\nscalar\n%YAML 1.2\n");
        $this->assertCount(1, $stream->getDocuments());
    }

    /**
     * yaml-test-suite 4JVG: two anchors on a single node.
     *
     * Per YAML 1.2 §6.9 a node carries at most one anchor. Two
     * stacked anchors with no intervening node-introducing token
     * mean the same node would be doubly anchored, which is invalid.
     */
    public function testTwoAnchorsOnSameNodeErrors(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/Multiple anchors on a single node/');
        $this->strict()->load("top: &outer\n  &inner val\n");
    }

    public function testTwoTagsOnSameNodeErrors(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/Multiple tags on a single node/');
        $this->strict()->load("top: !!str\n  !!int 42\n");
    }

    public function testAnchorOnMapAndAnchorOnFirstKeyIsValid(): void
    {
        // yaml-test-suite 7BMT shape: the scanner pre-emits
        // BlockMappingStart between the anchors so each lands on a
        // distinct node. Must not trip the multi-anchor gate.
        $stream = $this->strict()->load(
            "top: &outer\n  &inner key: val\n"
        );
        $this->assertCount(1, $stream->getDocuments());
    }

    public function testAnchorOnMapAndAnchorOnFirstKeyDeeperIsValid(): void
    {
        // U3XV shape: anchor on its own indented line, then anchor
        // on next indented line before a key. Two anchors -> two
        // nodes via scanner pre-emit.
        $stream = $this->strict()->load(
            "top:\n  &outer\n  &inner key: val\n"
        );
        $this->assertCount(1, $stream->getDocuments());
    }

    /**
     * Block-scalar leading-empty-line indent rule per YAML 1.2
     * §8.1.1.1: when no explicit indent indicator is given, the
     * implicit content indent is auto-detected from the first
     * non-empty line. Empty lines that PRECEDE the first content
     * line and have MORE leading whitespace than the content's
     * indent are invalid.
     *
     * Positive cases (must throw): yaml-test-suite 5LLU, S98Z, W9L4.
     */
    public function testFoldedScalarWithDeeperPrecedingEmptyLinesErrors(): void
    {
        // 5LLU shape: empty lines at 1/2/3 spaces preceding content
        // at 1 space.
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/empty line.*indent/i');
        $this->strict()->load(
            "block scalar: >\n \n  \n   \n invalid\n"
        );
    }

    public function testFoldedScalarWithOnlyEmptyLinesAndCommentErrors(): void
    {
        // S98Z shape: empty lines at 1/2/3 spaces preceding a
        // comment-content line at 1 space.
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/empty line.*indent/i');
        $this->strict()->load(
            "empty block scalar: >\n \n  \n   \n # comment\n"
        );
    }

    public function testLiteralScalarWithDeeperPrecedingEmptyLineErrors(): void
    {
        // W9L4 shape: single empty line at 5 spaces preceding
        // content at 2 spaces.
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/empty line.*indent/i');
        $this->strict()->load(
            "---\nblock scalar: |\n     \n  more spaces at the beginning\n  are invalid\n"
        );
    }

    public function testBlockScalarWithKeepChompAndTrailingEmptyLinesIsValid(): void
    {
        // yaml-test-suite 6FWR: `+` chomp, content at indent 1
        // FOLLOWED by empty lines (some with extra leading
        // whitespace). Trailing empty lines are content (preserved
        // by `+` chomp), and the spec rule only restricts PRECEDING
        // empty lines.
        $stream = $this->strict()->load(
            "--- |+\n ab\n \n  \n...\n"
        );
        $this->assertCount(1, $stream->getDocuments());
    }

    public function testBlockScalarWithExplicitIndentSkipsAutoDetectGate(): void
    {
        // Explicit indent indicator (`|2`). Even with deeper-leading
        // empty preceding lines, the indent is fixed by the
        // indicator, so the auto-detect gate must not fire.
        $stream = $this->strict()->load(
            "block: |2\n     \n  content\n"
        );
        $this->assertCount(1, $stream->getDocuments());
    }

    public function testBlockScalarWithContentFirstAndShallowerEmptyLinesIsValid(): void
    {
        // Content at indent 4, preceding "empty" line at 0 spaces.
        // Implicit indent = 4. Preceding empty line has 0 spaces
        // (< 4), gate must not fire.
        $stream = $this->strict()->load(
            "key: |\n\n    content\n"
        );
        $this->assertCount(1, $stream->getDocuments());
    }

    /**
     * Flow content indent rule per YAML 1.2 §10.3.2: every
     * continuation line of a flow node must be indented STRICTLY
     * MORE than the parent block context's indent. The "parent" is
     * the deepest open block container; at the document root the
     * parent indent is conceptually -1 (no constraint).
     *
     * Positive cases (must throw): 9C9N, CML9.
     * Negative cases (must continue to load): top-level flow with
     * content at indent 0 (8UDB), nested flow at deeper indent
     * (4FJ6).
     */
    public function testFlowContentLineMustBeIndentedDeeperThanParentBlock(): void
    {
        // 9C9N shape: `flow: [...]` is in a parent block-mapping
        // at indent 0; continuation lines `b,` and `c]` at col 1
        // (indent 0) violate the rule.
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/[Ff]low.*indented more than the parent/');
        $this->strict()->load("---\nflow: [a,\nb,\nc]\n");
    }

    public function testFlowCommentLineMustBeIndentedDeeperThanParentBlock(): void
    {
        // CML9 shape: comment `#  xxx` at col 1 inside flow with
        // parent at indent 0.
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/[Ff]low.*indented more than the parent/');
        $this->strict()->load("key: [ word1\n#  xxx\n  word2 ]\n");
    }

    public function testTopLevelFlowWithMultilineContentAtColumnZeroIsValid(): void
    {
        // 8UDB shape: at the document root the parent block
        // indent is -1, so any column >= 0 satisfies "> -1". This
        // multi-line plain-scalar-in-flow form must continue to
        // load.
        $stream = $this->strict()->load(
            "[\n\"double\n quoted\", 'single\n           quoted',\nplain\n text, [ nested ],\nsingle: pair,\n]\n"
        );
        $this->assertCount(1, $stream->getDocuments());
    }

    public function testNestedFlowWithDeeperIndentationIsValid(): void
    {
        // 4FJ6 shape: flow within flow at deeper indent.
        $stream = $this->strict()->load(
            "---\n[\n  [ a, [ [[b,c]]: d, e]]: 23\n]\n"
        );
        $this->assertCount(1, $stream->getDocuments());
    }

    public function testQuotedScalarSpanningLinesAtDocumentRootIsValid(): void
    {
        // KSS4 shape: quoted scalar across lines at top level.
        // Not a flow container per se but the quoted-continuation
        // logic should not trigger the flow-content-indent gate.
        $stream = $this->strict()->load(
            "--- \"quoted\nstring\"\n--- &node foo\n"
        );
        $this->assertCount(2, $stream->getDocuments());
    }

    /**
     * Flow `:` newline-fold per YAML 1.2 §10.3.1: line breaks fold
     * to spaces inside a flow node, so `{foo\n: bar}` is logically
     * `{foo: bar}`. The `:` on a fresh line still introduces the
     * value of the same-document key.
     *
     * Positive cases (must load): 4MUZ/02, VJP3/01.
     */
    public function testFlowMappingValueIndicatorOnNextLineIsValid(): void
    {
        // 4MUZ/02 shape: `{foo\n: bar}` at document root.
        $stream = $this->strict()->load("{foo\n: bar}\n");
        $this->assertCount(1, $stream->getDocuments());
    }

    public function testIndentedFlowMappingWithKeyAndColonOnSeparateLinesIsValid(): void
    {
        // VJP3/01 shape: `k: {\n k\n :\n v\n }`. Flow inside
        // block-mapping value, multi-line with each token on its
        // own line, indented to satisfy the flow-content-indent
        // rule.
        $stream = $this->strict()->load(
            "k: {\n k\n :\n v\n }\n"
        );
        $this->assertCount(1, $stream->getDocuments());
    }

    public function testFlatLeftFlowContinuationStillErrors(): void
    {
        // VJP3/00 shape: same as VJP3/01 but content lines are
        // flush-left at col 1 (parent indent 0). Even after the
        // newline-fold relaxation, the indent rule must still fire.
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/[Ff]low.*indented more than the parent/');
        $this->strict()->load("k: {\nk\n:\nv\n}\n");
    }

    /**
     * Inside a flow container, `%` is just plain-scalar content per
     * YAML 1.2 §6.8 (directives are ONLY at start-of-stream or
     * after `...`). Currently the flow scanner errors on a `%` mid-
     * flow; this case verifies a multi-line flow plain scalar can
     * fold across a `%`-led line. yaml-test-suite UT92.
     */
    public function testPercentInsideFlowIsPlainContent(): void
    {
        $stream = $this->strict()->load(
            "---\n{ matches\n% : 20 }\n...\n---\n# Empty\n...\n"
        );
        $this->assertCount(2, $stream->getDocuments());
    }

    /**
     * Document-marker recognition per YAML 1.2 §9.1.2: `---` and
     * `...` at column 1 introduce/end a document only when followed
     * by whitespace, newline, or end-of-input. `---word` (no
     * separator) is plain-scalar content (yaml-test-suite EXG3).
     */
    public function testTripleDashGluedToContentIsPlainScalar(): void
    {
        // EXG3: `---\n---word1\nword2`. Line 2 `---word1` is NOT
        // a document marker (no separator after `---`); it folds
        // with the next line into a multi-line plain scalar.
        $stream = $this->strict()->load("---\n---word1\nword2\n");
        $this->assertCount(1, $stream->getDocuments());
    }

    public function testTripleDashFollowedBySpaceAndContentIsDocumentMarker(): void
    {
        // `--- foo` is a doc-start marker with inline scalar `foo`.
        $stream = $this->strict()->load("--- foo\n");
        $this->assertCount(1, $stream->getDocuments());
    }

    public function testTripleDotGluedToContentIsPlainScalar(): void
    {
        // Symmetric check: `...word` is plain content, not doc-end.
        // (No yaml-test-suite case for this; defensive.)
        $stream = $this->strict()->load("---\n...word\n");
        $this->assertCount(1, $stream->getDocuments());
    }

    /**
     * Plain-scalar continuation per YAML 1.2 §7.3.3
     * (`ns-plain-multi-line`): continuation lines are
     * `ns-plain-char` content. The first-char restrictions
     * (`ns-plain-first` excludes `#`, `&`, `*`, `!`, `|`, `>`,
     * `'`, `"`, `%`, `@`, `\``, plus `-`/`?`/`:` followed by
     * whitespace) apply ONLY to the start of the scalar, NOT to
     * continuation lines. Continuations terminate on:
     *
     *   - `---` / `...` at column 1 (doc marker)
     *   - `#` at line start (comment-only line)
     *   - dedent at or below parent block indent
     *   - mid-line `: ` (mapping value indicator)
     *   - mid-line ` #` (EOL comment)
     *
     * Reference: PyYAML scan_plain / scan_plain_spaces.
     */
    public function testContinuationLineWithAmpersandIsPlainContent(): void
    {
        // 3MYT: `---\nk:#foo\n &a !t s` is one plain scalar
        // `"k:#foo &a !t s"`.
        $stream = $this->strict()->load("---\nk:#foo\n &a !t s\n");
        $this->assertCount(1, $stream->getDocuments());
        $root = $stream->getDocuments()[0]->root();
        $this->assertNotNull($root);
        $this->assertSame('k:#foo &a !t s', $root->getValue());
    }

    public function testContinuationLineWithDashIsPlainContent(): void
    {
        // AB8U: `- single multiline\n - sequence entry` is a
        // sequence with ONE item, the item being the plain scalar
        // `"single multiline - sequence entry"`.
        $stream = $this->strict()->load(
            "- single multiline\n - sequence entry\n"
        );
        $root = $stream->getDocuments()[0]->root();
        $this->assertNotNull($root);
        $items = $root->children();
        $this->assertCount(1, $items);
        $this->assertSame(
            'single multiline - sequence entry',
            $items[0]->getValue()->getValue()
        );
    }

    public function testContinuationLineWithAllPrintableCharsIsPlainContent(): void
    {
        // FBC9: many printable chars including `,`, `?`, `:`, `-`
        // on continuation lines.
        $stream = $this->strict()->load(
            "safe: a!\"#\$%&'()*+,-./09:;<=>?@AZ[\\]^_`az{|}~\n"
            . "     !\"#\$%&'()*+,-./09:;<=>?@AZ[\\]^_`az{|}~\n"
            . "safe question mark: ?foo\n"
            . "safe colon: :foo\n"
            . "safe dash: -foo\n"
        );
        $this->assertCount(1, $stream->getDocuments());
    }

    public function testCommentOnlyLineTerminatesPlainContinuation(): void
    {
        // A comment-only line still terminates a plain scalar; it
        // is not folded into the content. This case is single-doc:
        // `plain\n# comment` -> scalar 'plain' with a trailing
        // comment as trivia (loader returns 1 doc, scalar 'plain').
        $stream = $this->strict()->load("plain\n# comment\n");
        $root = $stream->getDocuments()[0]->root();
        $this->assertNotNull($root);
        $this->assertSame('plain', $root->getValue());
    }

    public function testSiblingKeyTerminatesPlainScalar(): void
    {
        // Indent-rule prevents `key: plain\nother: val` from
        // folding into one scalar. Two sibling entries.
        $stream = $this->strict()->load("key: plain\nother: val\n");
        $root = $stream->getDocuments()[0]->root();
        $entries = $root->entries();
        $this->assertCount(2, $entries);
    }

    public function testEmptyMappingValueDoesNotConsumeDeeperSubSequence(): void
    {
        // `key:\n  - item`. Empty mapping value followed by an
        // indented block sequence; the deeper `-` IS a sub-seq
        // start (not a plain-scalar continuation, since no plain
        // scalar was in flight).
        $stream = $this->strict()->load("key:\n  - item1\n  - item2\n");
        $root = $stream->getDocuments()[0]->root();
        $value = $root->entries()[0]->getValue();
        $this->assertCount(2, $value->children());
    }

    /**
     * Consecutive `...` end-markers, with optional trivia
     * (comments / blank lines) between them, do NOT create an
     * empty document. Per YAML 1.2 §9 a `...` ends the current
     * doc; a second `...` immediately after is redundant trivia,
     * not a marker for a new empty doc.
     *
     * yaml-test-suite M7A3:
     *   Bare
     *   document
     *   ...
     *   # No document
     *   ...
     *   |
     *   %!PS-Adobe-2.0 # Not the first line
     *
     * Spec events show TWO docs (the bare scalar and the literal
     * block scalar). Our parser previously produced 3 because the
     * second `...` spawned an empty middle doc.
     */
    public function testConsecutiveEndMarkersDoNotCreateEmptyDocument(): void
    {
        $stream = $this->strict()->load(
            "Bare\ndocument\n...\n# No document\n...\n|\n%!PS-Adobe-2.0 # Not the first line\n"
        );
        $this->assertCount(2, $stream->getDocuments());
    }

    public function testTwoStartMarkersInRowProduceTwoDocuments(): void
    {
        // Two `---` markers each start a doc. The first has empty
        // body, the second has scalar content.
        $stream = $this->strict()->load("---\n---\nfoo\n");
        $this->assertCount(2, $stream->getDocuments());
    }

    public function testStartEmptyEndStartProducesTwoDocs(): void
    {
        // `---\n...\n---\nfoo\n`. Explicit empty doc followed by
        // a new doc. Must produce 2 docs.
        $stream = $this->strict()->load("---\n...\n---\nfoo\n");
        $this->assertCount(2, $stream->getDocuments());
    }

    public function testSingleEndMarkerAfterContentProducesOneDoc(): void
    {
        $stream = $this->strict()->load("---\nfoo\n...\n");
        $this->assertCount(1, $stream->getDocuments());
    }

    /**
     * Flow-mapping-as-key with the value on subsequent indented
     * lines forming a nested block mapping. Per YAML 1.2 §7.4 a
     * flow node may be the implicit key of a block mapping; the
     * value follows after `:` either inline or as a deeper block.
     *
     * yaml-test-suite Q9WF:
     *   { first: Sammy, last: Sosa }:
     *   # Statistics:
     *     hr:  # Home runs
     *        65
     *     avg: # Average
     *      0.278
     *
     * Spec events: outer MAP { flow-key -> inner-MAP { hr: 65,
     * avg: 0.278 } }. One document.
     */
    public function testFlowMappingKeyWithNestedBlockMappingValue(): void
    {
        $stream = $this->strict()->load(
            "{ first: Sammy, last: Sosa }:\n"
            . "# Statistics:\n"
            . "  hr:  # Home runs\n"
            . "     65\n"
            . "  avg: # Average\n"
            . "   0.278\n"
        );
        $this->assertCount(1, $stream->getDocuments());
        $root = $stream->getDocuments()[0]->root();
        $entries = $root->entries();
        $this->assertCount(1, $entries);
        $value = $entries[0]->getValue();
        // Value is the inner block mapping with hr / avg.
        $this->assertNotNull($value);
        // Compound-key shape: key node is itself a mapping.
        $this->assertCount(2, $value->entries());
    }

    public function testFlowMappingKeyWithInlineScalarValue(): void
    {
        // `{a: 1}: scalar`. Single-line shape, value is inline.
        $stream = $this->strict()->load("{a: 1}: scalar\n");
        $this->assertCount(1, $stream->getDocuments());
        $root = $stream->getDocuments()[0]->root();
        $this->assertCount(1, $root->entries());
        $this->assertSame('scalar', $root->entries()[0]->getValue()->getValue());
    }

    public function testFlowMappingKeyAtSameIndentAsSiblingEntry(): void
    {
        // `{a: 1}:\nfoo: bar`. Flow-as-key empty value, then
        // sibling `foo: bar` at same indent. Two entries.
        $stream = $this->strict()->load("{a: 1}:\nfoo: bar\n");
        $root = $stream->getDocuments()[0]->root();
        $this->assertCount(2, $root->entries());
    }

    /**
     * Multi-doc gate per YAML 1.2 §9: a stream is a sequence of
     * documents, each beginning with an optional `---` marker.
     * After the first document, a subsequent root node WITHOUT an
     * intervening `---` (DocumentStart) or `...` (DocumentEnd)
     * marker is invalid.
     *
     * Positive cases (must throw): KS4U, C2SP, BS4K.
     */
    public function testTrailingTopLevelContentAfterClosingFlowErrors(): void
    {
        // KS4U: `[seq]\ninvalid item`.
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/[Uu]nexpected.*content.*after.*document/');
        $this->strict()->load(
            "---\n[\nsequence item\n]\ninvalid item\n"
        );
    }

    public function testMultilineFlowSeqFollowedByImplicitMappingErrors(): void
    {
        // C2SP: `[23\n]: 42` (multi-line flow not legal as
        // implicit-pair key, the flow-as-key splice is suppressed
        // by the same-line check, leaving a sibling mapping that
        // the gate now rejects).
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/[Uu]nexpected.*content.*after.*document/');
        $this->strict()->load("[23\n]: 42\n");
    }

    public function testTwoTopLevelScalarsSeparatedByCommentLineErrors(): void
    {
        // BS4K: `word1  # comment\nword2`. The EOL comment ends
        // word1's plain scalar; word2 at same indent is a second
        // root node without separator -> gate fires.
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/[Uu]nexpected.*content.*after.*document/');
        $this->strict()->load("word1  # comment\nword2\n");
    }

    public function testTwoDocumentsSeparatedByExplicitMarkerStillLoads(): void
    {
        // Two-doc with explicit separator stays valid.
        $stream = $this->strict()->load("---\nfirst\n---\nsecond\n");
        $this->assertCount(2, $stream->getDocuments());
    }

    /**
     * TAB handling per YAML 1.2 §6.1: TAB is valid as a separator
     * (after structural indicators like `-`, `?`, `:`) and as
     * content (inside scalars, on whitespace-only lines), but is
     * never permitted in the indentation prefix of a content line.
     *
     * Reference behaviour: libfyaml fy_ws_indentation_check (the
     * column-aware scanner that distinguishes "indentation prefix"
     * from "content after indentation"). PyYAML rejects tabs more
     * aggressively; we follow the spec / libfyaml.
     *
     * yaml-test-suite cases:
     *   A2M4    pass: TAB after `-` indicator is separator
     *   DK95/01 error: TAB at start of quoted continuation is
     *                  invalid (tab in indentation prefix)
     */
    public function testTabAfterBlockSequenceIndicatorIsValidSeparator(): void
    {
        // A2M4: `? a\n: -<TAB>b\n  -  -<TAB>c\n     - d\n`
        $yaml = "? a\n: -\tb\n  -  -\tc\n     - d\n";
        $stream = $this->strict()->load($yaml);
        $this->assertCount(1, $stream->getDocuments());
    }

    public function testMultipleSpacesBetweenDashesAreValid(): void
    {
        // `- -  x`. Outer dash, inner dash, two-space gap.
        $stream = $this->strict()->load("- -  x\n");
        $this->assertCount(1, $stream->getDocuments());
    }

    public function testTabBetweenDashAndItemIsValid(): void
    {
        // `-<TAB>x\n-<TAB>y`. Tab as separator after dash.
        $stream = $this->strict()->load("-\tx\n-\ty\n");
        $root = $stream->getDocuments()[0]->root();
        $items = $root->children();
        $this->assertCount(2, $items);
    }

    public function testTabAtStartOfQuotedContinuationLineErrors(): void
    {
        // DK95/01: `foo: "bar\n<TAB>baz"`.
        // TAB at column 1 of a quoted continuation line is in the
        // indentation prefix position; spec rejects.
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/[Tt]ab.*indentation/');
        $this->strict()->load("foo: \"bar\n\tbaz\"\n");
    }

    public function testSpaceThenTabInQuotedContinuationIsValid(): void
    {
        // DK95/02 shape: `foo: "bar\n  <TAB>baz"`. The `  ` is the
        // indent prefix; the TAB is content of the quoted scalar.
        $stream = $this->strict()->load("foo: \"bar\n  \tbaz\"\n");
        $this->assertCount(1, $stream->getDocuments());
    }

    public function testTabOnlyLineBetweenContentIsBlankLine(): void
    {
        // DK95/04 shape: `foo: 1\n<TAB>\nbar: 2`. Whitespace-only
        // line is a blank line; TAB is fine.
        $stream = $this->strict()->load("foo: 1\n\t\nbar: 2\n");
        $root = $stream->getDocuments()[0]->root();
        $this->assertCount(2, $root->entries());
    }

    public function testQuotedContinuationWithSpacesAndContentIsValid(): void
    {
        // Standard case: `--- "quoted\n  string"` (leading spaces
        // on continuation, no TAB).
        $stream = $this->strict()->load("--- \"quoted\n  string\"\n");
        $this->assertCount(1, $stream->getDocuments());
    }

    /**
     * Two flow items must be separated by a comma. Per YAML 1.2
     * §7.4 a flow sequence has comma-separated entries; a missing
     * comma between two adjacent items is invalid.
     *
     * yaml-test-suite ZXT5: `[ "key"\n  :value ]`. The quoted
     * scalar `"key"` and the plain scalar `:value` are two items
     * with no comma between. Error per spec.
     */
    public function testTwoFlowItemsWithoutCommaErrors(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/[Mm]issing.*comma|[Ee]xpected.*[,\]]/');
        $this->strict()->load("[ \"key\"\n  :value ]\n");
    }

    public function testFlowItemsWithCommasAreValid(): void
    {
        // Sanity check: comma-separated flow items still load.
        $stream = $this->strict()->load("[ \"key\", value, 1, 2 ]\n");
        $this->assertCount(1, $stream->getDocuments());
    }

    public function testFlowMappingWithImplicitPairOnNewlineStillValid(): void
    {
        // VJP3/01: `k: {\n k\n :\n v\n }` is valid per §10.3.1
        // (newline-fold inside flow MAPPING).
        $stream = $this->strict()->load("k: {\n k\n :\n v\n }\n");
        $this->assertCount(1, $stream->getDocuments());
    }

    public function testMultiLineFlowSeqWithProperStructureLoads(): void
    {
        // 4FJ6: properly comma-separated multi-line flow.
        $stream = $this->strict()->load(
            "---\n[\n  [ a, [ [[b,c]]: d, e]]: 23\n]\n"
        );
        $this->assertCount(1, $stream->getDocuments());
    }

    public function testFlowSeqAsKeyOfBlockMapping(): void
    {
        // 6BFJ: single-line flow seq as block-mapping key.
        $stream = $this->strict()->load(
            "&mapping\n&key [ &item a, b, c ]: value\n"
        );
        $this->assertCount(1, $stream->getDocuments());
    }

    public function testNamedTagHandleScopedToNextDocumentErrors(): void
    {
        // QLJ7: `%TAG !prefix!` directives apply only to the next
        // document (per §6.8.2.4). Using `!prefix!` in a subsequent
        // document where the handle is no longer registered must error.
        $this->expectException(ParseException::class);
        $this->strict()->load(
            "%TAG !prefix! tag:example.com,2011:\n"
                . "--- !prefix!A\n"
                . "a: b\n"
                . "--- !prefix!B\n"
                . "c: d\n"
        );
    }

    public function testNamedTagHandleAppliesToFirstDocumentOnly(): void
    {
        // Negative for QLJ7: first document uses the directive
        // legitimately.
        $stream = $this->strict()->load(
            "%TAG !prefix! tag:example.com,2011:\n"
                . "--- !prefix!A\n"
                . "a: b\n"
        );
        $this->assertCount(1, $stream->getDocuments());
    }

    public function testDefaultSecondaryAndPrimaryHandlesStillLoad(): void
    {
        // Negative: `!!str` (built-in secondary handle) and a primary
        // local tag `!local` need no directive and must continue to
        // load. Guards against over-firing the QLJ7 gate.
        $stream = $this->strict()->load(
            "value: !!str 42\nlocal: !local thing\n"
        );
        $this->assertCount(1, $stream->getDocuments());
    }
}
