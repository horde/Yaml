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
 * Verifies E.02 scanner behavior: double-quoted scalar recognition.
 */
#[CoversClass(Scanner::class)]
final class ScannerDoubleQuotedTest extends TestCase
{
    public function testEmptyDoubleQuoted(): void
    {
        $tokens = (new Scanner())->scan("\"\"\n");
        $this->assertSame(TokenType::Scalar, $tokens[1]->type);
        $this->assertSame('', $tokens[1]->value);
        $this->assertSame(ScalarStyle::DoubleQuoted, $tokens[1]->style);
    }

    public function testSimpleDoubleQuoted(): void
    {
        $tokens = (new Scanner())->scan('"hello"' . "\n");
        $this->assertSame('hello', $tokens[1]->value);
        $this->assertSame(ScalarStyle::DoubleQuoted, $tokens[1]->style);
    }

    public function testIntegerLikeContentStaysString(): void
    {
        $tokens = (new Scanner())->scan('"42"' . "\n");
        $this->assertSame('42', $tokens[1]->value);
        $this->assertSame(ScalarStyle::DoubleQuoted, $tokens[1]->style);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function escapeSamples(): iterable
    {
        yield 'newline' => ['"a\\nb"' . "\n", "a\nb"];
        yield 'tab' => ['"a\\tb"' . "\n", "a\tb"];
        yield 'carriage return' => ['"a\\rb"' . "\n", "a\rb"];
        yield 'backslash' => ['"a\\\\b"' . "\n", 'a\\b'];
        yield 'double quote' => ['"a\\"b"' . "\n", 'a"b'];
        yield 'null byte' => ['"\\0"' . "\n", "\0"];
        yield 'forward slash' => ['"a\\/b"' . "\n", 'a/b'];
        yield 'escape escape' => ['"\\e"' . "\n", "\x1B"];
    }

    #[DataProvider('escapeSamples')]
    public function testEscapeSequence(string $source, string $expected): void
    {
        $tokens = (new Scanner())->scan($source);
        $this->assertSame($expected, $tokens[1]->value);
    }

    public function testHexEscape(): void
    {
        $tokens = (new Scanner())->scan('"\x41"' . "\n");
        $this->assertSame('A', $tokens[1]->value);
    }

    public function testUnicodeFourHexEscape(): void
    {
        // é is é (U+00E9, two-byte UTF-8: 0xC3 0xA9)
        $tokens = (new Scanner())->scan('"café"' . "\n");
        $this->assertSame('café', $tokens[1]->value);
    }

    public function testUnicodeEightHexEscape(): void
    {
        // \U0001F389 is 🎉 (U+1F389, four-byte UTF-8)
        $tokens = (new Scanner())->scan('"\U0001F389"' . "\n");
        $this->assertSame('🎉', $tokens[1]->value);
    }

    public function testUnknownEscapeThrows(): void
    {
        $this->expectException(ParseException::class);
        (new Scanner())->scan('"\q"' . "\n");
    }

    public function testTruncatedHexEscapeThrows(): void
    {
        $this->expectException(ParseException::class);
        (new Scanner())->scan('"\xZZ"' . "\n");
    }

    public function testUnterminatedDoubleQuoteThrows(): void
    {
        $this->expectException(ParseException::class);
        (new Scanner())->scan('"unterminated' . "\n");
    }

    public function testColonAndHashInsideAreLiteral(): void
    {
        $tokens = (new Scanner())->scan('"http://example.com#anchor"' . "\n");
        $this->assertSame('http://example.com#anchor', $tokens[1]->value);
    }

    public function testDoubleQuotedAsBlockMappingValue(): void
    {
        $tokens = (new Scanner())->scan('greeting: "hello"' . "\n");
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
        $this->assertSame(ScalarStyle::DoubleQuoted, $valueScalar->style);
    }

    public function testEolCommentAfterDoubleQuoted(): void
    {
        $tokens = (new Scanner())->scan('"hello"  # important' . "\n");
        $this->assertCount(1, $tokens[1]->trailingTrivia);
        $this->assertSame('# important', $tokens[1]->trailingTrivia[0]->text);
    }
}
