<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Parser;

use Horde\Yaml\Document\Node\ChompMode;
use Horde\Yaml\Document\Node\ScalarStyle;
use Horde\Yaml\Document\Parser\Scanner;
use Horde\Yaml\Document\TokenType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies F.01 scanner behavior: literal block scalar (`|`)
 * recognition with chomp and indent indicators.
 */
#[CoversClass(Scanner::class)]
final class ScannerLiteralBlockTest extends TestCase
{
    private function scalarTokenAfterValue(string $source): \Horde\Yaml\Document\Token
    {
        $tokens = (new Scanner())->scan($source);
        for ($i = 0; $i < count($tokens); $i++) {
            if ($tokens[$i]->type === TokenType::Value
                && isset($tokens[$i + 1])
                && $tokens[$i + 1]->type === TokenType::Scalar
            ) {
                return $tokens[$i + 1];
            }
        }
        throw new RuntimeException('No scalar token after Value');
    }

    public function testSimpleLiteralBlockClipChomp(): void
    {
        $source = "key: |\n  line one\n  line two\n";
        $token = $this->scalarTokenAfterValue($source);
        $this->assertSame(ScalarStyle::LiteralBlock, $token->style);
        $this->assertSame(ChompMode::Clip, $token->chomp);
        $this->assertNull($token->indentIndicator);
        $this->assertSame("line one\nline two\n", $token->value);
    }

    public function testLiteralBlockStripChomp(): void
    {
        $source = "key: |-\n  line one\n  line two\n";
        $token = $this->scalarTokenAfterValue($source);
        $this->assertSame(ChompMode::Strip, $token->chomp);
        $this->assertSame("line one\nline two", $token->value);
    }

    public function testLiteralBlockKeepChomp(): void
    {
        $source = "key: |+\n  line one\n  line two\n\n\n";
        $token = $this->scalarTokenAfterValue($source);
        $this->assertSame(ChompMode::Keep, $token->chomp);
        // Keep retains all trailing newlines (the two blank lines plus
        // the implicit final newline).
        $this->assertStringStartsWith("line one\nline two\n", $token->value);
        $this->assertStringEndsWith("\n\n\n", $token->value);
    }

    public function testLiteralBlockExplicitIndentIndicator(): void
    {
        // |2 means content is indented 2 more than parent. Parent
        // here is the key line at indent 0 so content at column 3
        // (2 spaces leading). With exact match the content has no
        // extra indent.
        $source = "key: |2\n  line one\n  line two\n";
        $token = $this->scalarTokenAfterValue($source);
        $this->assertSame(2, $token->indentIndicator);
        $this->assertSame("line one\nline two\n", $token->value);
    }

    public function testLiteralBlockChompAndIndentIndicator(): void
    {
        $source = "key: |-2\n  line one\n  line two\n";
        $token = $this->scalarTokenAfterValue($source);
        $this->assertSame(ChompMode::Strip, $token->chomp);
        $this->assertSame(2, $token->indentIndicator);
    }

    public function testLiteralBlockIndentBeforeChomp(): void
    {
        // |2- equivalent to |-2
        $source = "key: |2-\n  line one\n  line two\n";
        $token = $this->scalarTokenAfterValue($source);
        $this->assertSame(ChompMode::Strip, $token->chomp);
        $this->assertSame(2, $token->indentIndicator);
    }

    public function testLiteralBlockEmptyLinesPreserved(): void
    {
        $source = "key: |\n  line one\n\n  line three\n";
        $token = $this->scalarTokenAfterValue($source);
        $this->assertSame("line one\n\nline three\n", $token->value);
    }

    public function testLiteralBlockEndsAtDedent(): void
    {
        // After a dedent, the block scalar ends. The next line at indent 0
        // starts a new entry of the parent map.
        $source = "first: |\n  block content\nsecond: plain\n";
        $tokens = (new Scanner())->scan($source);
        // Find scalars in order.
        $scalars = [];
        foreach ($tokens as $t) {
            if ($t->type === TokenType::Scalar) {
                $scalars[] = $t;
            }
        }
        // Expect: first, block-content, second, plain
        $this->assertSame('first', $scalars[0]->value);
        $this->assertSame(ScalarStyle::LiteralBlock, $scalars[1]->style);
        $this->assertSame("block content\n", $scalars[1]->value);
        $this->assertSame('second', $scalars[2]->value);
        $this->assertSame('plain', $scalars[3]->value);
    }

    public function testLiteralBlockPreservesInternalIndent(): void
    {
        // Content lines indented MORE than the detected base keep
        // their extra indent.
        $source = "key: |\n  outer\n    inner\n  outer2\n";
        $token = $this->scalarTokenAfterValue($source);
        $this->assertSame("outer\n  inner\nouter2\n", $token->value);
    }
}
