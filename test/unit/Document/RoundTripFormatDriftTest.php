<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\Emitter\Emitter;
use Horde\Yaml\Document\Node\CommentNode;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Round-trip: eliminate format drift.
 *
 * Covers the three drift cases from
 * `~/php/horde-development/libraries/yaml/19-roundtrip-format-drift-2026-06-18.md`:
 *
 *   F1: EOL comment spacing (one space becomes two on round-trip).
 *   F2: `key: # eol\n  nested:` reflow (EOL becomes standalone).
 *   F3: multi-line flow trailing newline trimmed at root.
 */
#[CoversNothing]
final class RoundTripFormatDriftTest extends TestCase
{
    private function loader(): YamlStringLoader
    {
        return new YamlStringLoader();
    }

    private function emitter(): Emitter
    {
        return new Emitter();
    }

    private function assertByteIdenticalRoundTrip(string $source): void
    {
        $stream = $this->loader()->load($source);
        $output = $this->emitter()->emit($stream);
        $this->assertSame($source, $output);
    }

    // -----------------------------------------------------------------
    // F1: EOL comment spacing preservation.
    // -----------------------------------------------------------------

    public function testEolCommentSingleSpaceGapRoundTrips(): void
    {
        $source = "a: 1 # eol comment\nb: 2\n";
        $this->assertByteIdenticalRoundTrip($source);
    }

    public function testEolCommentDoubleSpaceGapRoundTrips(): void
    {
        $source = "a: 1  # eol comment\n";
        $this->assertByteIdenticalRoundTrip($source);
    }

    public function testEolCommentTabGapRoundTrips(): void
    {
        $source = "a: 1\t# eol comment\n";
        $this->assertByteIdenticalRoundTrip($source);
    }

    public function testEolCommentOnSequenceItemSingleSpaceRoundTrips(): void
    {
        $source = "- a # one\n- b\n";
        $this->assertByteIdenticalRoundTrip($source);
    }

    public function testSynthesizedEolCommentEmitsTwoSpaceDefault(): void
    {
        // Negative: programmatic setEolComment without a gap should
        // continue to emit the two-space default. Guards against the
        // gap fallback regressing for hand-built nodes.
        $stream = $this->loader()->load("a: 1\nb: 2\n");
        $entry = $stream->getDocument(0)->root()->entries()[0];
        $entry->setEolComment(new CommentNode('# new'));
        $output = $this->emitter()->emit($stream);
        $this->assertSame("a: 1  # new\nb: 2\n", $output);
    }

    // -----------------------------------------------------------------
    // F2: EOL on key whose value is a nested block.
    // -----------------------------------------------------------------

    public function testEolCommentOnMapKeyWithNestedMapValueRoundTrips(): void
    {
        $source = "key: # eol\n  nested: 1\n";
        $stream = $this->loader()->load($source);
        $entry = $stream->getDocument(0)->root()->entries()[0];
        $eol = $entry->getEolComment();
        $this->assertNotNull($eol);
        $this->assertSame('# eol', $eol->getText());
        $this->assertByteIdenticalRoundTrip($source);
    }

    public function testEolCommentOnMapKeyWithNestedSequenceValueRoundTrips(): void
    {
        $source = "items: # eol\n  - first\n  - second\n";
        $stream = $this->loader()->load($source);
        $entry = $stream->getDocument(0)->root()->entries()[0];
        $eol = $entry->getEolComment();
        $this->assertNotNull($eol);
        $this->assertSame('# eol', $eol->getText());
        $this->assertByteIdenticalRoundTrip($source);
    }

    public function testStandaloneCommentBelowKeyStillBecomesChildOfNestedValue(): void
    {
        // Negative: a comment NOT on the same line as `:` stays a
        // child of the nested value's container, NOT eol on the key.
        $source = "key:\n  # standalone\n  nested: 1\n";
        $stream = $this->loader()->load($source);
        $entry = $stream->getDocument(0)->root()->entries()[0];
        $this->assertNull($entry->getEolComment());
        $this->assertByteIdenticalRoundTrip($source);
    }

    public function testEolPlusStandaloneCombinationRoundTrips(): void
    {
        $source = "key: # eol\n  # standalone\n  nested: 1\n";
        $stream = $this->loader()->load($source);
        $entry = $stream->getDocument(0)->root()->entries()[0];
        $this->assertNotNull($entry->getEolComment());
        $this->assertSame('# eol', $entry->getEolComment()->getText());
        $this->assertByteIdenticalRoundTrip($source);
    }

    // -----------------------------------------------------------------
    // F3: multi-line flow trailing newline.
    // -----------------------------------------------------------------

    public function testRootFlowSequenceWithMidCommentTrailingNewlineRoundTrips(): void
    {
        $source = "[1, 2, # mid\n 3]\n";
        $this->assertByteIdenticalRoundTrip($source);
    }

    public function testRootFlowMappingMultiLineWithEolTrailingNewlineRoundTrips(): void
    {
        $source = "{a: 1, # eol\n b: 2}\n";
        $this->assertByteIdenticalRoundTrip($source);
    }

    public function testRootFlowSequenceSingleLineWithoutTrailingNewlineEmitsNone(): void
    {
        // Negative: source with no trailing newline must emit none.
        $source = "[1, 2, 3]";
        $this->assertByteIdenticalRoundTrip($source);
    }

    public function testFlowAsValueOfMapEntryStillRoundTrips(): void
    {
        // Negative: when the flow is NOT the root, the trailing
        // newline belongs to the parent map's line break and is
        // already handled correctly.
        $source = "key: [1, 2, 3]\n";
        $this->assertByteIdenticalRoundTrip($source);
    }
}
