<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\Node\ChompMode;
use Horde\Yaml\Document\Node\MapStyle;
use Horde\Yaml\Document\Node\ScalarStyle;
use Horde\Yaml\Document\Token;
use Horde\Yaml\Document\TokenType;
use Horde\Yaml\Document\TriviaToken;
use Horde\Yaml\Document\TriviaType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Error;

/**
 * Smoke tests for the lexical token vocabulary.
 *
 * Verifies TokenType has the documented twenty cases, TriviaType has
 * Comment and BlankLines, and Token / TriviaToken are constructible
 * with the documented shape and are immutable (final readonly).
 */
#[CoversClass(TokenType::class)]
#[CoversClass(TriviaType::class)]
#[CoversClass(Token::class)]
#[CoversClass(TriviaToken::class)]
final class TokenTest extends TestCase
{
    public function testTokenTypeHasTwentyCases(): void
    {
        $this->assertCount(20, TokenType::cases());
    }

    public function testTokenTypeIncludesStreamMarkers(): void
    {
        $names = array_map(static fn(TokenType $t): string => $t->name, TokenType::cases());
        $this->assertContains('StreamStart', $names);
        $this->assertContains('StreamEnd', $names);
        $this->assertContains('DocumentStart', $names);
        $this->assertContains('DocumentEnd', $names);
    }

    public function testTriviaTypeHasCommentAndBlankLines(): void
    {
        $this->assertSame(
            ['Comment', 'BlankLines'],
            array_map(static fn(TriviaType $t): string => $t->name, TriviaType::cases()),
        );
    }

    public function testTriviaTokenCommentFactory(): void
    {
        $t = TriviaToken::comment('# leading', 3, 1);
        $this->assertSame(TriviaType::Comment, $t->type);
        $this->assertSame('# leading', $t->text);
        $this->assertSame(0, $t->count);
        $this->assertSame(3, $t->line);
        $this->assertSame(1, $t->column);
    }

    public function testTriviaTokenBlankLinesFactory(): void
    {
        $t = TriviaToken::blankLines(2, 5, 1);
        $this->assertSame(TriviaType::BlankLines, $t->type);
        $this->assertSame('', $t->text);
        $this->assertSame(2, $t->count);
        $this->assertSame(5, $t->line);
    }

    public function testTriviaTokenIsReadonly(): void
    {
        $t = TriviaToken::comment('# x', 1, 1);
        $this->expectException(Error::class);
        // @phpstan-ignore-next-line
        $t->text = 'mutated';
    }

    public function testTokenMinimalConstruction(): void
    {
        $tok = new Token(TokenType::StreamStart, line: 1, column: 1);
        $this->assertSame(TokenType::StreamStart, $tok->type);
        $this->assertSame(1, $tok->line);
        $this->assertSame(1, $tok->column);
        $this->assertNull($tok->value);
        $this->assertNull($tok->style);
        $this->assertNull($tok->chomp);
        $this->assertNull($tok->indentIndicator);
        $this->assertSame([], $tok->leadingTrivia);
        $this->assertSame([], $tok->trailingTrivia);
    }

    public function testTokenWithScalarPayload(): void
    {
        $tok = new Token(
            TokenType::Scalar,
            line: 7,
            column: 4,
            value: 'foo',
            style: ScalarStyle::Plain,
        );
        $this->assertSame('foo', $tok->value);
        $this->assertSame(ScalarStyle::Plain, $tok->style);
    }

    public function testTokenWithBlockMappingStyle(): void
    {
        $tok = new Token(
            TokenType::BlockMappingStart,
            line: 1,
            column: 1,
            style: MapStyle::Block,
        );
        $this->assertSame(MapStyle::Block, $tok->style);
    }

    public function testTokenWithBlockScalarMetadata(): void
    {
        $tok = new Token(
            TokenType::Scalar,
            line: 1,
            column: 1,
            value: "line1\nline2\n",
            style: ScalarStyle::LiteralBlock,
            chomp: ChompMode::Keep,
            indentIndicator: 2,
        );
        $this->assertSame(ChompMode::Keep, $tok->chomp);
        $this->assertSame(2, $tok->indentIndicator);
    }

    public function testTokenWithLeadingTrivia(): void
    {
        $leading = [
            TriviaToken::blankLines(1, 1, 1),
            TriviaToken::comment('# heading', 2, 1),
        ];
        $tok = new Token(
            TokenType::Scalar,
            line: 3,
            column: 1,
            value: 'foo',
            style: ScalarStyle::Plain,
            leadingTrivia: $leading,
        );
        $this->assertCount(2, $tok->leadingTrivia);
        $this->assertSame(TriviaType::Comment, $tok->leadingTrivia[1]->type);
    }

    public function testTokenIsReadonly(): void
    {
        $tok = new Token(TokenType::StreamStart, line: 1, column: 1);
        $this->expectException(Error::class);
        // @phpstan-ignore-next-line
        $tok->line = 999;
    }
}
