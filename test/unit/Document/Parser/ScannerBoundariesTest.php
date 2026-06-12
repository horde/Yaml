<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Parser;

use Horde\Yaml\Document\EncodingException;
use Horde\Yaml\Document\Parser\Scanner;
use Horde\Yaml\Document\TokenType;
use Horde\Yaml\Document\TriviaType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies B.01 scanner behavior: stream and document boundaries,
 * directive recognition, comment and blank-line trivia capture, UTF-8
 * validation.
 */
#[CoversClass(Scanner::class)]
#[CoversClass(EncodingException::class)]
final class ScannerBoundariesTest extends TestCase
{
    public function testEmptyInputProducesStreamStartAndEnd(): void
    {
        $tokens = (new Scanner())->scan('');
        $this->assertCount(2, $tokens);
        $this->assertSame(TokenType::StreamStart, $tokens[0]->type);
        $this->assertSame(TokenType::StreamEnd, $tokens[1]->type);
    }

    public function testStreamStartHasPositionOneOne(): void
    {
        $tokens = (new Scanner())->scan('');
        $this->assertSame(1, $tokens[0]->line);
        $this->assertSame(1, $tokens[0]->column);
    }

    public function testDocumentStartMarker(): void
    {
        $tokens = (new Scanner())->scan("---\n");
        $this->assertCount(3, $tokens);
        $this->assertSame(TokenType::StreamStart, $tokens[0]->type);
        $this->assertSame(TokenType::DocumentStart, $tokens[1]->type);
        $this->assertSame(1, $tokens[1]->line);
        $this->assertSame(1, $tokens[1]->column);
        $this->assertSame(TokenType::StreamEnd, $tokens[2]->type);
    }

    public function testDocumentEndMarker(): void
    {
        $tokens = (new Scanner())->scan("---\n...\n");
        $this->assertCount(4, $tokens);
        $this->assertSame(TokenType::DocumentStart, $tokens[1]->type);
        $this->assertSame(TokenType::DocumentEnd, $tokens[2]->type);
        $this->assertSame(2, $tokens[2]->line);
    }

    public function testYamlDirective(): void
    {
        $tokens = (new Scanner())->scan("%YAML 1.2\n---\n");
        $this->assertSame(TokenType::Directive, $tokens[1]->type);
        $this->assertSame('YAML 1.2', $tokens[1]->value);
        $this->assertSame(TokenType::DocumentStart, $tokens[2]->type);
    }

    public function testTagDirective(): void
    {
        $tokens = (new Scanner())->scan("%TAG !my! tag:example.com,2026:\n---\n");
        $this->assertSame(TokenType::Directive, $tokens[1]->type);
        $this->assertSame('TAG !my! tag:example.com,2026:', $tokens[1]->value);
    }

    public function testStandaloneCommentAttachesToNextStructuralToken(): void
    {
        $tokens = (new Scanner())->scan("# leading\n---\n");
        $this->assertSame(TokenType::DocumentStart, $tokens[1]->type);
        $this->assertCount(1, $tokens[1]->leadingTrivia);
        $this->assertSame(TriviaType::Comment, $tokens[1]->leadingTrivia[0]->type);
        $this->assertSame('# leading', $tokens[1]->leadingTrivia[0]->text);
        $this->assertSame(1, $tokens[1]->leadingTrivia[0]->line);
    }

    public function testBlankLinesAttachAsTriviaWithCount(): void
    {
        $tokens = (new Scanner())->scan("\n\n\n---\n");
        $this->assertSame(TokenType::DocumentStart, $tokens[1]->type);
        $this->assertCount(1, $tokens[1]->leadingTrivia);
        $this->assertSame(TriviaType::BlankLines, $tokens[1]->leadingTrivia[0]->type);
        $this->assertSame(3, $tokens[1]->leadingTrivia[0]->count);
    }

    public function testTriviaSequenceOrder(): void
    {
        $source = "\n# first\n\n# second\n---\n";
        $tokens = (new Scanner())->scan($source);
        $trivia = $tokens[1]->leadingTrivia;
        $this->assertCount(4, $trivia);
        $this->assertSame(TriviaType::BlankLines, $trivia[0]->type);
        $this->assertSame(TriviaType::Comment, $trivia[1]->type);
        $this->assertSame('# first', $trivia[1]->text);
        $this->assertSame(TriviaType::BlankLines, $trivia[2]->type);
        $this->assertSame(TriviaType::Comment, $trivia[3]->type);
        $this->assertSame('# second', $trivia[3]->text);
    }

    public function testCrlfNormalizesToLf(): void
    {
        $tokens = (new Scanner())->scan("---\r\n...\r\n");
        $this->assertCount(4, $tokens);
        $this->assertSame(TokenType::DocumentStart, $tokens[1]->type);
        $this->assertSame(TokenType::DocumentEnd, $tokens[2]->type);
        $this->assertSame(2, $tokens[2]->line);
    }

    public function testLoneCrNormalizesToLf(): void
    {
        $tokens = (new Scanner())->scan("---\r...\r");
        $this->assertCount(4, $tokens);
        $this->assertSame(TokenType::DocumentStart, $tokens[1]->type);
        $this->assertSame(TokenType::DocumentEnd, $tokens[2]->type);
    }

    public function testBomIsStripped(): void
    {
        $tokens = (new Scanner())->scan("\xEF\xBB\xBF---\n");
        $this->assertCount(3, $tokens);
        $this->assertSame(TokenType::DocumentStart, $tokens[1]->type);
        $this->assertSame(1, $tokens[1]->line);
        $this->assertSame(1, $tokens[1]->column);
    }

    public function testInvalidUtf8ThrowsEncodingException(): void
    {
        $this->expectException(EncodingException::class);
        // 0xC3 0x28 is an invalid UTF-8 sequence (0xC3 starts a 2-byte
        // sequence but 0x28 is not a valid continuation byte).
        (new Scanner())->scan("\xC3\x28");
    }

    public function testInvalidUtf8AfterValidContentReportsCorrectPosition(): void
    {
        try {
            (new Scanner())->scan("---\n\xC3\x28");
            $this->fail('Expected EncodingException');
        } catch (EncodingException $e) {
            // Position should reflect the location of the bad byte.
            $this->assertStringContainsString('line 2', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function multiByteCases(): iterable
    {
        yield 'ASCII only' => ['# hello'];
        yield 'two-byte UTF-8 (Latin-1 supplement)' => ['# café'];
        yield 'three-byte UTF-8 (CJK)' => ['# 日本'];
        yield 'four-byte UTF-8 (emoji)' => ['# 🎉'];
    }

    #[DataProvider('multiByteCases')]
    public function testScannerHandlesMultiByteUtf8WithoutError(string $source): void
    {
        $tokens = (new Scanner())->scan($source . "\n---\n");
        // Should produce StreamStart, DocumentStart with the comment as
        // leading trivia, StreamEnd.
        $this->assertCount(3, $tokens);
        $this->assertSame(TokenType::DocumentStart, $tokens[1]->type);
        $this->assertCount(1, $tokens[1]->leadingTrivia);
        $this->assertSame(TriviaType::Comment, $tokens[1]->leadingTrivia[0]->type);
    }
}
