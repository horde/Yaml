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
use Horde\Yaml\Document\TokenType;
use Horde\Yaml\Document\TriviaType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies B.02 scanner behavior: plain scalar recognition.
 *
 * Plain scalars are unquoted, do not start with a reserved indicator,
 * and (in B.02 scope) end at end-of-line or at a `# ...` end-of-line
 * comment.
 */
#[CoversClass(Scanner::class)]
final class ScannerPlainScalarTest extends TestCase
{
    public function testSingleWordPlainScalar(): void
    {
        $tokens = (new Scanner())->scan("hello\n");
        $this->assertCount(3, $tokens);
        $this->assertSame(TokenType::Scalar, $tokens[1]->type);
        $this->assertSame('hello', $tokens[1]->value);
        $this->assertSame(ScalarStyle::Plain, $tokens[1]->style);
        $this->assertSame(1, $tokens[1]->line);
        $this->assertSame(1, $tokens[1]->column);
    }

    public function testPlainScalarWithSpacesInside(): void
    {
        $tokens = (new Scanner())->scan("hello world\n");
        $this->assertSame('hello world', $tokens[1]->value);
    }

    public function testPlainScalarWithoutTrailingNewline(): void
    {
        $tokens = (new Scanner())->scan('hello');
        $this->assertSame('hello', $tokens[1]->value);
        $this->assertSame(TokenType::StreamEnd, $tokens[2]->type);
    }

    /**
     * @return iterable<string, array{string, scalar}>
     */
    public static function plainScalarSamples(): iterable
    {
        yield 'integer-shaped' => ["42\n", '42'];
        yield 'float-shaped' => ["3.14\n", '3.14'];
        yield 'true-shaped' => ["true\n", 'true'];
        yield 'null-shaped' => ["null\n", 'null'];
        yield 'identifier' => ["my_var\n", 'my_var'];
        yield 'with hyphens' => ["foo-bar-baz\n", 'foo-bar-baz'];
        yield 'leading hyphen with letter' => ["-foo\n", '-foo'];
        yield 'leading colon with letter' => [":foo\n", ':foo'];
    }

    #[DataProvider('plainScalarSamples')]
    public function testPlainScalarSampleSourceTokenizesCleanly(string $source, string $expected): void
    {
        $tokens = (new Scanner())->scan($source);
        $this->assertSame(TokenType::Scalar, $tokens[1]->type);
        $this->assertSame($expected, $tokens[1]->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedLeaders(): iterable
    {
        yield 'left-bracket' => ['['];
        yield 'right-bracket' => [']'];
        yield 'left-brace' => ['{'];
        yield 'right-brace' => ['}'];
        yield 'comma' => [','];
        yield 'apostrophe' => ["'"];
        yield 'quote' => ['"'];
        yield 'at' => ['@'];
        yield 'backtick' => ['`'];
    }

    #[DataProvider('reservedLeaders')]
    public function testReservedLeaderRejectedAsPlainScalarStart(string $leader): void
    {
        $this->expectException(\Horde\Yaml\Document\ParseException::class);
        (new Scanner())->scan($leader . "rest\n");
    }

    public function testHyphenSpaceIsBlockSequenceIndicatorNotPlainStart(): void
    {
        // After D.01 `- item` is a valid block-sequence item, not a
        // plain scalar starting with `-`. The scanner should produce
        // BlockSequenceStart, BlockEntry, Scalar, BlockEnd. No
        // ParseException.
        $tokens = (new Scanner())->scan("- item\n");
        $types = array_map(static fn($t): TokenType => $t->type, $tokens);
        $this->assertContains(TokenType::BlockSequenceStart, $types);
        $this->assertContains(TokenType::BlockEntry, $types);
    }

    public function testColonSpaceStartsEmptyKeyEntry(): void
    {
        // Stage 13 AE: `: value` at line start is a block-mapping
        // entry with an empty (null) key.
        $tokens = (new Scanner())->scan(": value\n");
        $types = array_map(static fn($t) => $t->type->name, $tokens);
        $this->assertContains('Key', $types);
        $this->assertContains('Value', $types);
    }

    public function testQuestionSpaceStartsExplicitKey(): void
    {
        // `? key` at line start is an explicit-key block-mapping
        // entry per YAML 1.2 §8.1.1, not a plain scalar.
        $tokens = (new Scanner())->scan("? key\n");
        $types = array_map(static fn($t) => $t->type->name, $tokens);
        $this->assertContains('Key', $types);
        $this->assertContains('Value', $types);
    }

    public function testEolCommentBecomesTrailingTrivia(): void
    {
        $tokens = (new Scanner())->scan("hello # important\n");
        $this->assertSame('hello', $tokens[1]->value);
        $this->assertCount(1, $tokens[1]->trailingTrivia);
        $this->assertSame(TriviaType::Comment, $tokens[1]->trailingTrivia[0]->type);
        $this->assertSame('# important', $tokens[1]->trailingTrivia[0]->text);
    }

    public function testEolCommentRequiresLeadingWhitespace(): void
    {
        // `#` immediately after non-whitespace is part of the value.
        $tokens = (new Scanner())->scan("foo#bar\n");
        $this->assertSame('foo#bar', $tokens[1]->value);
        $this->assertSame([], $tokens[1]->trailingTrivia);
    }

    public function testLeadingCommentBecomesScalarLeadingTrivia(): void
    {
        $tokens = (new Scanner())->scan("# leading\nhello\n");
        $this->assertSame(TokenType::Scalar, $tokens[1]->type);
        $this->assertCount(1, $tokens[1]->leadingTrivia);
        $this->assertSame('# leading', $tokens[1]->leadingTrivia[0]->text);
    }
}
