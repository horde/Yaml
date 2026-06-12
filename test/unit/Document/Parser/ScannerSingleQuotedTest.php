<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Parser;

use Horde\Yaml\Document\Node\ScalarStyle;
use Horde\Yaml\Document\Parser\Scanner;
use Horde\Yaml\Document\ParseException;
use Horde\Yaml\Document\TokenType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies E.01 scanner behavior: single-quoted scalar recognition.
 *
 * YAML 1.2: `'...'` delimits; only escape is `''` (doubled single
 * quote) to literal `'`. Multi-line single-quoted strings are not
 * supported in E.01.
 */
#[CoversClass(Scanner::class)]
final class ScannerSingleQuotedTest extends TestCase
{
    public function testEmptySingleQuoted(): void
    {
        $tokens = (new Scanner())->scan("''\n");
        $this->assertSame(TokenType::Scalar, $tokens[1]->type);
        $this->assertSame('', $tokens[1]->value);
        $this->assertSame(ScalarStyle::SingleQuoted, $tokens[1]->style);
    }

    public function testSimpleSingleQuoted(): void
    {
        $tokens = (new Scanner())->scan("'hello'\n");
        $this->assertSame(TokenType::Scalar, $tokens[1]->type);
        $this->assertSame('hello', $tokens[1]->value);
        $this->assertSame(ScalarStyle::SingleQuoted, $tokens[1]->style);
    }

    public function testSingleQuotedWithSpaces(): void
    {
        $tokens = (new Scanner())->scan("'hello world'\n");
        $this->assertSame('hello world', $tokens[1]->value);
    }

    public function testSingleQuotedWithIntegerLikeContent(): void
    {
        // Even "42" stays a string when single-quoted.
        $tokens = (new Scanner())->scan("'42'\n");
        $this->assertSame('42', $tokens[1]->value);
        $this->assertSame(ScalarStyle::SingleQuoted, $tokens[1]->style);
    }

    public function testDoubledQuoteEscapes(): void
    {
        // `'don''t'` becomes `don't`
        $tokens = (new Scanner())->scan("'don''t'\n");
        $this->assertSame("don't", $tokens[1]->value);
    }

    public function testBackslashIsLiteral(): void
    {
        // Backslashes inside single-quoted strings are literal. No
        // escape interpretation.
        $tokens = (new Scanner())->scan("'a\\nb'\n");
        $this->assertSame('a\\nb', $tokens[1]->value);
    }

    public function testColonInsideQuotedValue(): void
    {
        // The colon inside a quoted scalar is part of the value, not
        // a mapping indicator.
        $tokens = (new Scanner())->scan("'http://example.com'\n");
        $this->assertSame('http://example.com', $tokens[1]->value);
    }

    public function testHashInsideQuotedValue(): void
    {
        // `#` inside the quoted scalar is literal, not a comment.
        $tokens = (new Scanner())->scan("'#tag'\n");
        $this->assertSame('#tag', $tokens[1]->value);
    }

    public function testSingleQuotedAsBlockMappingValue(): void
    {
        $tokens = (new Scanner())->scan("greeting: 'hello'\n");
        // Find the value Scalar (after Value token).
        $valueScalar = null;
        for ($i = 0; $i < count($tokens); $i++) {
            if ($tokens[$i]->type === TokenType::Value
                && isset($tokens[$i + 1])
                && $tokens[$i + 1]->type === TokenType::Scalar
            ) {
                $valueScalar = $tokens[$i + 1];
                break;
            }
        }
        $this->assertNotNull($valueScalar);
        $this->assertSame('hello', $valueScalar->value);
        $this->assertSame(ScalarStyle::SingleQuoted, $valueScalar->style);
    }

    public function testSingleQuotedAsBlockSequenceItem(): void
    {
        $tokens = (new Scanner())->scan("- 'foo'\n");
        // Find the scalar after BlockEntry.
        $valueScalar = null;
        for ($i = 0; $i < count($tokens); $i++) {
            if ($tokens[$i]->type === TokenType::BlockEntry
                && isset($tokens[$i + 1])
                && $tokens[$i + 1]->type === TokenType::Scalar
            ) {
                $valueScalar = $tokens[$i + 1];
                break;
            }
        }
        $this->assertNotNull($valueScalar);
        $this->assertSame('foo', $valueScalar->value);
        $this->assertSame(ScalarStyle::SingleQuoted, $valueScalar->style);
    }

    public function testSingleQuotedAsMapKey(): void
    {
        $tokens = (new Scanner())->scan("'with spaces': 1\n");
        // Find the Key token's following scalar.
        $keyScalar = null;
        for ($i = 0; $i < count($tokens); $i++) {
            if ($tokens[$i]->type === TokenType::Key
                && isset($tokens[$i + 1])
                && $tokens[$i + 1]->type === TokenType::Scalar
            ) {
                $keyScalar = $tokens[$i + 1];
                break;
            }
        }
        $this->assertNotNull($keyScalar);
        $this->assertSame('with spaces', $keyScalar->value);
        $this->assertSame(ScalarStyle::SingleQuoted, $keyScalar->style);
    }

    public function testEolCommentAfterQuoted(): void
    {
        $tokens = (new Scanner())->scan("'hello'  # important\n");
        $scalar = $tokens[1];
        $this->assertSame('hello', $scalar->value);
        $this->assertCount(1, $scalar->trailingTrivia);
        $this->assertSame('# important', $scalar->trailingTrivia[0]->text);
    }

    public function testUnterminatedSingleQuoteThrows(): void
    {
        $this->expectException(ParseException::class);
        (new Scanner())->scan("'unterminated\n");
    }

    public function testMultiLineSingleQuotedFolds(): void
    {
        // Stage 13 Chapter AD: multi-line single-quoted scalars
        // fold per YAML 1.2 §7.4.1. A single line break between
        // content lines folds to one space; leading whitespace on
        // the continuation is stripped.
        $tokens = (new Scanner())->scan("k: 'line1\n  line2'\n");
        $scalar = null;
        foreach ($tokens as $t) {
            if ($t->type->name === 'Scalar' && $t->value === 'line1 line2') {
                $scalar = $t;
                break;
            }
        }
        $this->assertNotNull($scalar);
    }
}
