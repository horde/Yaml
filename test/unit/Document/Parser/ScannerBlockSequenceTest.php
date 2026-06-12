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
 * Verifies D.01 scanner behavior: block-style sequence recognition.
 *
 * The scanner emits BlockSequenceStart on the first `-` of a new
 * sequence context, BlockEntry per `-` indicator, and BlockEnd when
 * indent drops or at end of stream.
 */
#[CoversClass(Scanner::class)]
final class ScannerBlockSequenceTest extends TestCase
{
    /**
     * @return list<TokenType>
     */
    private function tokenTypes(string $source): array
    {
        $tokens = (new Scanner())->scan($source);
        return array_map(static fn($t): TokenType => $t->type, $tokens);
    }

    public function testSingleItemBlockSequence(): void
    {
        $types = $this->tokenTypes("- foo\n");
        $this->assertSame([
            TokenType::StreamStart,
            TokenType::BlockSequenceStart,
            TokenType::BlockEntry,
            TokenType::Scalar,
            TokenType::BlockEnd,
            TokenType::StreamEnd,
        ], $types);
    }

    public function testThreeItemBlockSequence(): void
    {
        $types = $this->tokenTypes("- a\n- b\n- c\n");
        $this->assertSame([
            TokenType::StreamStart,
            TokenType::BlockSequenceStart,
            TokenType::BlockEntry,
            TokenType::Scalar,
            TokenType::BlockEntry,
            TokenType::Scalar,
            TokenType::BlockEntry,
            TokenType::Scalar,
            TokenType::BlockEnd,
            TokenType::StreamEnd,
        ], $types);
    }

    public function testItemValueCarriesScalar(): void
    {
        $tokens = (new Scanner())->scan("- foo\n");
        $this->assertSame('foo', $tokens[3]->value);
    }

    public function testItemWithEmptyValue(): void
    {
        $tokens = (new Scanner())->scan("-\n");
        $types = array_map(static fn($t): TokenType => $t->type, $tokens);
        // -\n alone is empty value (no following deeper content).
        $this->assertContains(TokenType::BlockEntry, $types);
        $this->assertContains(TokenType::Scalar, $types);
    }

    public function testItemValueWithEolComment(): void
    {
        $tokens = (new Scanner())->scan("- foo  # important\n");
        $scalar = $tokens[3];
        $this->assertSame('foo', $scalar->value);
        $this->assertCount(1, $scalar->trailingTrivia);
    }

    public function testSequenceFollowedByDocumentEndEmitsBlockEnd(): void
    {
        $types = $this->tokenTypes("- a\n- b\n...\n");
        $this->assertSame([
            TokenType::StreamStart,
            TokenType::BlockSequenceStart,
            TokenType::BlockEntry,
            TokenType::Scalar,
            TokenType::BlockEntry,
            TokenType::Scalar,
            TokenType::BlockEnd,
            TokenType::DocumentEnd,
            TokenType::StreamEnd,
        ], $types);
    }

    public function testSequenceWithIntegerItems(): void
    {
        $tokens = (new Scanner())->scan("- 1\n- 2\n- 3\n");
        $values = [];
        foreach ($tokens as $t) {
            if ($t->type === TokenType::Scalar) {
                $values[] = $t->value;
            }
        }
        $this->assertSame(['1', '2', '3'], $values);
    }
}
