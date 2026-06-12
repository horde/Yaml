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
use Horde\Yaml\Document\Node\BlankLineNode;
use Horde\Yaml\Document\Node\CommentNode;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Round-trip: stream- and document-level trivia preservation.
 *
 * Covers the four lost-data cases from
 * `~/php/horde-development/libraries/yaml/18-roundtrip-lost-data-2026-06-18.md`:
 *
 *   L1: stream-trailing comment after the last document.
 *   L2: pre-document-marker comment (before the first `---`).
 *   L3: comment-only file (zero documents).
 *   L4: inter-document comment (attached to previous doc as trailing).
 *
 * Plus three regression negatives: comment positions that already
 * round-trip today must continue to do so.
 */
#[CoversNothing]
final class RoundTripStreamTriviaTest extends TestCase
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

    public function testStreamTrailingCommentRoundTrips(): void
    {
        // L1: comment after the last document's content.
        $source = "key: value\n# trailing footer\n";
        $stream = $this->loader()->load($source);
        $trailing = $stream->getTrailingTrivia();
        $this->assertCount(1, $trailing);
        $this->assertInstanceOf(CommentNode::class, $trailing[0]);
        $this->assertSame('# trailing footer', $trailing[0]->getText());
        $this->assertByteIdenticalRoundTrip($source);
    }

    public function testPreDocumentMarkerCommentRoundTrips(): void
    {
        // L2: comment before the first `---` belongs to the stream.
        $source = "# pre-doc\n---\nx: 1\n";
        $stream = $this->loader()->load($source);
        $leading = $stream->getLeadingTrivia();
        $this->assertCount(1, $leading);
        $this->assertInstanceOf(CommentNode::class, $leading[0]);
        $this->assertSame('# pre-doc', $leading[0]->getText());
        $this->assertByteIdenticalRoundTrip($source);
    }

    public function testCommentOnlyFileRoundTrips(): void
    {
        // L3: zero documents, all content is stream-leading trivia.
        $source = "# only-comments\n# nothing else\n";
        $stream = $this->loader()->load($source);
        $this->assertCount(0, $stream->getDocuments());
        $leading = $stream->getLeadingTrivia();
        $this->assertCount(2, $leading);
        $this->assertSame('# only-comments', $leading[0]->getText());
        $this->assertSame('# nothing else', $leading[1]->getText());
        $this->assertByteIdenticalRoundTrip($source);
    }

    public function testInterDocumentCommentAttachesToPreviousDoc(): void
    {
        // L4: a comment between two docs belongs to the previous
        // doc as trailing trivia.
        $source = "---\na: 1\n# between\n---\nb: 2\n";
        $stream = $this->loader()->load($source);
        $docs = $stream->getDocuments();
        $this->assertCount(2, $docs);
        $trailing0 = $docs[0]->getTrailingTrivia();
        $this->assertCount(1, $trailing0);
        $this->assertSame('# between', $trailing0[0]->getText());
        $this->assertByteIdenticalRoundTrip($source);
    }

    public function testStreamLeadingCommentBeforeContentStillRoundTrips(): void
    {
        // Negative: the existing case 1.3 in `02-roundtrip-semantics`
        // (a leading comment before the first key in a no-`---`
        // single-doc stream) must continue to round-trip. This goes
        // onto the root map's children, NOT stream-leading.
        $source = "# leading file header\n# second header line\nkey: value\n";
        $this->assertByteIdenticalRoundTrip($source);
    }

    public function testCommentBetweenSiblingMapEntriesStillRoundTrips(): void
    {
        // Negative: case 1.1 in `02-roundtrip-semantics`. Standalone
        // comment between sibling entries stays as a child of the
        // parent map.
        $source = "a: 1\n# inline note\nb: 2\n";
        $this->assertByteIdenticalRoundTrip($source);
    }

    public function testBlankLinePreservationStillWorks(): void
    {
        // Negative: case 2.x in `02-roundtrip-semantics`. Blank lines
        // between entries become BlankLineNode children of the parent.
        $source = "a: 1\n\nb: 2\n";
        $this->assertByteIdenticalRoundTrip($source);
    }
}
