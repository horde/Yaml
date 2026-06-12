<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Parser;

use Horde\Yaml\Document\YamlStringDumper;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Stage 11 Chapter T: block scalar header and content edge cases
 * per YAML 1.2 §8.1.
 *
 * Locks in correct behaviour for the consumer's full matrix:
 * literal vs folded, all three chomping modes, explicit indent
 * indicator before/after chomp, leading blanks, and round-trip
 * fidelity.
 */
#[CoversNothing]
final class BlockScalarHeaderTest extends TestCase
{
    private function valueOf(string $src, string $key = 'k'): mixed
    {
        return (new YamlStringLoader())
            ->load($src)
            ->getDocument(0)
            ->root()
            ->entry($key)
            ?->getValue()
            ->getValue();
    }

    public function testLiteralClipDefault(): void
    {
        $v = $this->valueOf("k: |\n  hello\n  world\n\n\n");
        $this->assertSame("hello\nworld\n", $v);
    }

    public function testLiteralStrip(): void
    {
        $v = $this->valueOf("k: |-\n  hello\n  world\n\n\n");
        $this->assertSame("hello\nworld", $v);
    }

    public function testLiteralKeep(): void
    {
        $v = $this->valueOf("k: |+\n  hello\n  world\n\n\n");
        $this->assertSame("hello\nworld\n\n\n", $v);
    }

    public function testFoldedClip(): void
    {
        $v = $this->valueOf("k: >\n  hello\n  world\n");
        $this->assertSame("hello world\n", $v);
    }

    public function testFoldedParagraphBreak(): void
    {
        $v = $this->valueOf("k: >\n  hello\n  world\n\n  next paragraph\n");
        $this->assertSame("hello world\nnext paragraph\n", $v);
    }

    public function testFoldedKeepWithTrailingBlanks(): void
    {
        $v = $this->valueOf("k: >+\n  one\n  two\n\n\n");
        $this->assertSame("one two\n\n\n", $v);
    }

    public function testExplicitIndentIndicator(): void
    {
        // |2 with content at six spaces means strip 2, keep 4 spaces.
        $v = $this->valueOf("literal: |2\n      first line\n      second line\n", 'literal');
        $this->assertSame("    first line\n    second line\n", $v);
    }

    public function testIndicatorOrderIndentBeforeChomp(): void
    {
        $v = $this->valueOf("k: |2-\n   x\n   y\n");
        $this->assertSame(" x\n y", $v);
    }

    public function testIndicatorOrderChompBeforeIndent(): void
    {
        $v = $this->valueOf("k: |-2\n   x\n   y\n");
        $this->assertSame(" x\n y", $v);
    }

    public function testCommentAfterIndicator(): void
    {
        $v = $this->valueOf("k: | # comment after the indicator\n  hello\n");
        $this->assertSame("hello\n", $v);
    }

    public function testEmptyBlockScalar(): void
    {
        $v = $this->valueOf("k: |\n");
        $this->assertSame('', $v);
    }

    public function testTabsInLiteralContentPreserved(): void
    {
        $v = $this->valueOf("k: |\n  hello\tworld\n");
        $this->assertSame("hello\tworld\n", $v);
    }

    public function testMoreIndentedLineKeepsExtraSpaces(): void
    {
        // Auto-detect: first non-empty line is at 2 spaces.
        // Second line at 4 spaces means 2 spaces of content prefix.
        $v = $this->valueOf("k: |\n  one\n    two\n");
        $this->assertSame("one\n  two\n", $v);
    }

    public function testRoundTripPreservesLayout(): void
    {
        $src = "k: |\n  hello\n  world\n";
        $stream = (new YamlStringLoader())->load($src);
        $out = (new YamlStringDumper())->dump($stream);
        $this->assertSame($src, $out);
    }

    public function testRoundTripPreservesExplicitIndicator(): void
    {
        $src = "k: |2\n      with indicator\n      and content\n";
        $stream = (new YamlStringLoader())->load($src);
        $out = (new YamlStringDumper())->dump($stream);
        $this->assertSame($src, $out);
    }

    public function testRoundTripPreservesChompStrip(): void
    {
        $src = "k: |-\n  no trailing newline\n";
        $stream = (new YamlStringLoader())->load($src);
        $out = (new YamlStringDumper())->dump($stream);
        $this->assertSame($src, $out);
    }

    public function testBlockScalarInSequence(): void
    {
        $src = "items:\n  - |\n    multi\n    line\n  - plain\n";
        $resolved = (new YamlStringLoader())->load($src)->getDocument(0)->root()->resolved();
        $this->assertSame(
            ['items' => ["multi\nline\n", 'plain']],
            $resolved,
        );
    }

    public function testFoldedHandlesMultipleBlanks(): void
    {
        // Folded: blank lines fold to one fewer newline than blank
        // count plus one (per spec). Two blanks => one extra \n in
        // output, etc.
        $v = $this->valueOf("k: >\n  a\n\n  b\n\n\n  c\n");
        $this->assertSame("a\nb\n\nc\n", $v);
    }

    public function testZeroIndentIndicatorRejected(): void
    {
        $this->expectException(\Horde\Yaml\Document\ParseException::class);
        $this->expectExceptionMessageMatches('/indent indicator may not be 0/');
        (new YamlStringLoader())->load("k: |0\n  hello\n");
    }
}
