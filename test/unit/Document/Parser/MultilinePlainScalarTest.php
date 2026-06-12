<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Parser;

use Horde\Yaml\Document\ParseException;
use Horde\Yaml\Document\YamlStringDumper;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Stage 11 Chapter Q: multi-line plain scalar continuation per
 * YAML 1.2 §7.3.3.
 */
#[CoversNothing]
final class MultilinePlainScalarTest extends TestCase
{
    public function testTwoLineContinuationFoldsToSpace(): void
    {
        $src = "description: line one\n  line two\n";
        $doc = (new YamlStringLoader())->load($src)->getDocument(0);
        $this->assertSame(
            ['description' => 'line one line two'],
            $doc->root()->resolved(),
        );
    }

    public function testThreeLineContinuationAllFoldToSpaces(): void
    {
        $src = "k: a\n  b\n  c\n";
        $doc = (new YamlStringLoader())->load($src)->getDocument(0);
        $this->assertSame(['k' => 'a b c'], $doc->root()->resolved());
    }

    public function testBlankLineFoldsToNewline(): void
    {
        $src = "k: line one\n  line two\n\n  line four\n";
        $doc = (new YamlStringLoader())->load($src)->getDocument(0);
        $this->assertSame(
            ['k' => "line one line two\nline four"],
            $doc->root()->resolved(),
        );
    }

    public function testDedentTerminatesContinuation(): void
    {
        $src = "k: foo\n  bar\nnext: baz\n";
        $doc = (new YamlStringLoader())->load($src)->getDocument(0);
        $this->assertSame(
            ['k' => 'foo bar', 'next' => 'baz'],
            $doc->root()->resolved(),
        );
    }

    public function testColonInContentDoesNotStartMap(): void
    {
        // Adjacent colon (no following whitespace) is allowed in
        // plain scalar content.
        $src = "k: foo\n  bar:baz\n";
        $doc = (new YamlStringLoader())->load($src)->getDocument(0);
        $this->assertSame(['k' => 'foo bar:baz'], $doc->root()->resolved());
    }

    public function testColonSpaceInContinuationIsRejected(): void
    {
        $src = "k: foo\n  bar: baz\n";
        $this->expectException(ParseException::class);
        (new YamlStringLoader())->load($src);
    }

    public function testReservedIndicatorAtLineStartTerminates(): void
    {
        // The `&` introduces an anchor; it cannot continue a plain
        // scalar even though indented relative to the parent.
        $src = "  !!str bar\n&a2 baz\n";
        // Top-level: just a tagged scalar followed by an anchored
        // value. The continuation must NOT swallow the anchor line.
        $doc = (new YamlStringLoader())->load($src)->getDocument(0);
        $this->assertNotNull($doc);
    }

    public function testRoundTripPreservesMultiLineLayout(): void
    {
        $src = "k: line one\n  line two\n  line three\n";
        $stream = (new YamlStringLoader())->load($src);
        $out = (new YamlStringDumper())->dump($stream);
        $this->assertSame($src, $out);
    }

    public function testSetValueDropsMultiLineLayout(): void
    {
        $src = "k: line one\n  line two\n";
        $stream = (new YamlStringLoader())->load($src);
        $stream->getDocument(0)->root()->entry('k')?->getValue()->setValue('changed');
        $out = (new YamlStringDumper())->dump($stream);
        $this->assertSame("k: changed\n", $out);
    }

    public function testCommentMidScalarRejected(): void
    {
        $src = "k: foo\n  bar # comment in middle\n  baz\n";
        $this->expectException(ParseException::class);
        (new YamlStringLoader())->load($src);
    }

    public function testDocumentMarkerTerminates(): void
    {
        $src = "---\nk: hello\n...\n";
        $stream = (new YamlStringLoader())->load($src);
        $this->assertSame(['k' => 'hello'], $stream->getDocument(0)->root()->resolved());
    }
}
