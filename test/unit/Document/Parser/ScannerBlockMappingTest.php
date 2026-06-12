<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Parser;

use Horde\Yaml\Document\Parser\Scanner;
use Horde\Yaml\Document\TokenType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies C.01 scanner behavior: block-style mapping recognition.
 *
 * The scanner emits BlockMappingStart at the first key line of a new
 * block context, Key tokens (implicit, position-only) before each
 * key, Value tokens after each `:`, and BlockEnd when indent drops or
 * at end of stream.
 */
#[CoversClass(Scanner::class)]
final class ScannerBlockMappingTest extends TestCase
{
    /**
     * @return list<TokenType>
     */
    private function tokenTypes(string $source): array
    {
        $tokens = (new Scanner())->scan($source);
        return array_map(static fn($t): TokenType => $t->type, $tokens);
    }

    public function testSingleEntryBlockMapping(): void
    {
        $types = $this->tokenTypes("foo: 1\n");
        $this->assertSame([
            TokenType::StreamStart,
            TokenType::BlockMappingStart,
            TokenType::Key,
            TokenType::Scalar,
            TokenType::Value,
            TokenType::Scalar,
            TokenType::BlockEnd,
            TokenType::StreamEnd,
        ], $types);
    }

    public function testTwoEntryBlockMapping(): void
    {
        $types = $this->tokenTypes("foo: 1\nbar: 2\n");
        $this->assertSame([
            TokenType::StreamStart,
            TokenType::BlockMappingStart,
            TokenType::Key,
            TokenType::Scalar,
            TokenType::Value,
            TokenType::Scalar,
            TokenType::Key,
            TokenType::Scalar,
            TokenType::Value,
            TokenType::Scalar,
            TokenType::BlockEnd,
            TokenType::StreamEnd,
        ], $types);
    }

    public function testKeyAndValueTokensCarrySource(): void
    {
        $tokens = (new Scanner())->scan("foo: 1\n");
        // Key scalar is index 3
        $this->assertSame('foo', $tokens[3]->value);
        // Value scalar is index 5
        $this->assertSame('1', $tokens[5]->value);
    }

    public function testKeyWithNullValue(): void
    {
        // `foo:` with no value: emits an empty scalar.
        $tokens = (new Scanner())->scan("foo:\n");
        $this->assertSame(TokenType::Scalar, $tokens[5]->type);
        $this->assertSame('', $tokens[5]->value);
    }

    public function testKeyWithStringValueContainingColon(): void
    {
        // The colon inside a value's string is fine (no trailing space).
        $tokens = (new Scanner())->scan("foo: http://example.com\n");
        $this->assertSame('foo', $tokens[3]->value);
        $this->assertSame('http://example.com', $tokens[5]->value);
    }

    public function testKeyWithEolComment(): void
    {
        $tokens = (new Scanner())->scan("foo: 1  # important\n");
        $this->assertSame('1', $tokens[5]->value);
        $this->assertCount(1, $tokens[5]->trailingTrivia);
        $this->assertSame('# important', $tokens[5]->trailingTrivia[0]->text);
    }

    public function testTopLevelScalarWithoutColonStillScalar(): void
    {
        // No `: ` on the line - this is a top-level plain scalar
        // document, not a mapping.
        $types = $this->tokenTypes("hello\n");
        $this->assertSame([
            TokenType::StreamStart,
            TokenType::Scalar,
            TokenType::StreamEnd,
        ], $types);
    }

    public function testMappingBeforeDocumentEndMarkerEmitsBlockEnd(): void
    {
        $types = $this->tokenTypes("foo: 1\n...\n");
        $this->assertSame([
            TokenType::StreamStart,
            TokenType::BlockMappingStart,
            TokenType::Key,
            TokenType::Scalar,
            TokenType::Value,
            TokenType::Scalar,
            TokenType::BlockEnd,
            TokenType::DocumentEnd,
            TokenType::StreamEnd,
        ], $types);
    }

    public function testCommentBetweenKeysAttachesAsLeadingTrivia(): void
    {
        $tokens = (new Scanner())->scan("foo: 1\n# about bar\nbar: 2\n");
        // Find the second Key token (index 7 after foo: 1 entry).
        $keyIndices = [];
        foreach ($tokens as $i => $token) {
            if ($token->type === TokenType::Key) {
                $keyIndices[] = $i;
            }
        }
        $this->assertCount(2, $keyIndices);
        // Comment should be on the Key OR on the leading scalar of bar.
        // Look for the comment in the second entry's tokens.
        $secondKeyAndScalar = [$tokens[$keyIndices[1]], $tokens[$keyIndices[1] + 1]];
        $hasComment = false;
        foreach ($secondKeyAndScalar as $t) {
            foreach ($t->leadingTrivia as $tr) {
                if ($tr->text === '# about bar') {
                    $hasComment = true;
                    break 2;
                }
            }
        }
        $this->assertTrue($hasComment, 'Expected the comment as leading trivia on the second key or its scalar');
    }
}
