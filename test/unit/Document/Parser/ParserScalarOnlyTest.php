<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Parser;

use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\ScalarStyle;
use Horde\Yaml\Document\Parser\Parser;
use Horde\Yaml\Document\ParseException;
use Horde\Yaml\Document\Token;
use Horde\Yaml\Document\TokenType;
use Horde\Yaml\Document\YamlDocument;
use Horde\Yaml\Document\YamlStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies B.03 parser behavior: scalar-only document handling on a
 * hand-built token stream.
 */
#[CoversClass(Parser::class)]
final class ParserScalarOnlyTest extends TestCase
{
    public function testEmptyTokenStreamProducesEmptyYamlStream(): void
    {
        $tokens = [
            new Token(TokenType::StreamStart, line: 1, column: 1),
            new Token(TokenType::StreamEnd, line: 1, column: 1),
        ];
        $stream = (new Parser())->parse($tokens);
        $this->assertInstanceOf(YamlStream::class, $stream);
        $this->assertSame(0, $stream->documentCount());
    }

    public function testSingleScalarBecomesOneDocumentWithScalarRoot(): void
    {
        $tokens = [
            new Token(TokenType::StreamStart, line: 1, column: 1),
            new Token(
                TokenType::Scalar,
                line: 1,
                column: 1,
                value: 'hello',
                style: ScalarStyle::Plain,
            ),
            new Token(TokenType::StreamEnd, line: 1, column: 6),
        ];

        $stream = (new Parser())->parse($tokens);
        $this->assertSame(1, $stream->documentCount());

        $doc = $stream->getDocuments()[0];
        $this->assertInstanceOf(YamlDocument::class, $doc);
        $this->assertSame($stream, $doc->parent());

        $root = $doc->root();
        $this->assertInstanceOf(ScalarNode::class, $root);
        $this->assertSame('hello', $root->getValue());
        $this->assertSame(ScalarStyle::Plain, $root->getStyle());
        $this->assertSame(1, $root->line());
        $this->assertSame(1, $root->column());
    }

    public function testDocumentMarkersArePreservedOnDocument(): void
    {
        $tokens = [
            new Token(TokenType::StreamStart, line: 1, column: 1),
            new Token(TokenType::DocumentStart, line: 1, column: 1),
            new Token(
                TokenType::Scalar,
                line: 2,
                column: 1,
                value: 'hello',
                style: ScalarStyle::Plain,
            ),
            new Token(TokenType::DocumentEnd, line: 3, column: 1),
            new Token(TokenType::StreamEnd, line: 3, column: 4),
        ];

        $stream = (new Parser())->parse($tokens);
        $doc = $stream->getDocuments()[0];
        $this->assertTrue($doc->getStartMarker());
        $this->assertTrue($doc->getEndMarker());
    }

    public function testDocumentWithoutMarkersHasFalseFlags(): void
    {
        $tokens = [
            new Token(TokenType::StreamStart, line: 1, column: 1),
            new Token(
                TokenType::Scalar,
                line: 1,
                column: 1,
                value: 'hello',
                style: ScalarStyle::Plain,
            ),
            new Token(TokenType::StreamEnd, line: 1, column: 6),
        ];

        $stream = (new Parser())->parse($tokens);
        $doc = $stream->getDocuments()[0];
        $this->assertFalse($doc->getStartMarker());
        $this->assertFalse($doc->getEndMarker());
    }

    public function testDirectivesAreSkippedInB03(): void
    {
        $tokens = [
            new Token(TokenType::StreamStart, line: 1, column: 1),
            new Token(TokenType::Directive, line: 1, column: 1, value: 'YAML 1.2'),
            new Token(TokenType::DocumentStart, line: 2, column: 1),
            new Token(
                TokenType::Scalar,
                line: 3,
                column: 1,
                value: 'hello',
                style: ScalarStyle::Plain,
            ),
            new Token(TokenType::StreamEnd, line: 3, column: 6),
        ];

        $stream = (new Parser())->parse($tokens);
        $this->assertSame(1, $stream->documentCount());
    }

    public function testParseAcceptsEmptyDocumentBetweenMarkers(): void
    {
        // Stage 13 Chapter AB: empty document body is legal (root
        // stays null).
        $tokens = [
            new Token(TokenType::StreamStart, line: 1, column: 1),
            new Token(TokenType::DocumentStart, line: 1, column: 1),
            new Token(TokenType::DocumentEnd, line: 2, column: 1),
            new Token(TokenType::StreamEnd, line: 2, column: 4),
        ];

        $stream = (new Parser())->parse($tokens);
        $this->assertCount(1, $stream->getDocuments());
        $this->assertNull($stream->getDocument(0)->root());
    }

    public function testParseExceptionOnMissingStreamStart(): void
    {
        $tokens = [
            new Token(TokenType::Scalar, line: 1, column: 1, value: 'foo', style: ScalarStyle::Plain),
        ];

        $this->expectException(ParseException::class);
        (new Parser())->parse($tokens);
    }

    public function testParseExceptionOnMissingStreamEnd(): void
    {
        $tokens = [
            new Token(TokenType::StreamStart, line: 1, column: 1),
            new Token(TokenType::Scalar, line: 1, column: 1, value: 'foo', style: ScalarStyle::Plain),
        ];

        $this->expectException(ParseException::class);
        (new Parser())->parse($tokens);
    }
}
