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
 * Verifies I.01 scanner behavior: flow context tokens.
 */
#[CoversClass(Scanner::class)]
final class ScannerFlowTest extends TestCase
{
    /**
     * @return list<TokenType>
     */
    private function tokenTypes(string $source): array
    {
        $tokens = (new Scanner())->scan($source);
        return array_map(static fn($t): TokenType => $t->type, $tokens);
    }

    public function testEmptyFlowSequence(): void
    {
        $types = $this->tokenTypes("[]\n");
        $this->assertContains(TokenType::FlowSequenceStart, $types);
        $this->assertContains(TokenType::FlowSequenceEnd, $types);
    }

    public function testEmptyFlowMapping(): void
    {
        $types = $this->tokenTypes("{}\n");
        $this->assertContains(TokenType::FlowMappingStart, $types);
        $this->assertContains(TokenType::FlowMappingEnd, $types);
    }

    public function testFlowSequenceWithItems(): void
    {
        $types = $this->tokenTypes("[a, b, c]\n");
        $expected = [
            TokenType::StreamStart,
            TokenType::FlowSequenceStart,
            TokenType::Scalar,
            TokenType::FlowEntry,
            TokenType::Scalar,
            TokenType::FlowEntry,
            TokenType::Scalar,
            TokenType::FlowSequenceEnd,
            TokenType::StreamEnd,
        ];
        $this->assertSame($expected, $types);
    }

    public function testFlowMappingWithKeyValue(): void
    {
        $types = $this->tokenTypes("{a: 1, b: 2}\n");
        $expected = [
            TokenType::StreamStart,
            TokenType::FlowMappingStart,
            TokenType::Scalar,
            TokenType::Value,
            TokenType::Scalar,
            TokenType::FlowEntry,
            TokenType::Scalar,
            TokenType::Value,
            TokenType::Scalar,
            TokenType::FlowMappingEnd,
            TokenType::StreamEnd,
        ];
        $this->assertSame($expected, $types);
    }

    public function testFlowSequenceItemValues(): void
    {
        $tokens = (new Scanner())->scan("[apple, banana, cherry]\n");
        $values = array_values(array_filter(
            array_map(static fn($t): ?string => $t->type === TokenType::Scalar ? $t->value : null, $tokens),
            static fn($v) => $v !== null,
        ));
        $this->assertSame(['apple', 'banana', 'cherry'], $values);
    }

    public function testFlowSequenceAsBlockMapValue(): void
    {
        $tokens = (new Scanner())->scan("hosts: [a, b]\n");
        $types = array_map(static fn($t): TokenType => $t->type, $tokens);
        $this->assertContains(TokenType::FlowSequenceStart, $types);
        $this->assertContains(TokenType::FlowSequenceEnd, $types);
    }

    public function testNestedFlow(): void
    {
        $types = $this->tokenTypes("[[1, 2], [3, 4]]\n");
        $startCount = count(array_filter($types, static fn($t) => $t === TokenType::FlowSequenceStart));
        $endCount = count(array_filter($types, static fn($t) => $t === TokenType::FlowSequenceEnd));
        $this->assertSame(3, $startCount);
        $this->assertSame(3, $endCount);
    }

    public function testUnterminatedFlowThrows(): void
    {
        $this->expectException(\Horde\Yaml\Document\ParseException::class);
        (new Scanner())->scan("[1, 2,\n");
    }

    public function testMixedFlowMappingAndSequence(): void
    {
        $tokens = (new Scanner())->scan("config: {hosts: [a, b], port: 25}\n");
        $types = array_map(static fn($t): TokenType => $t->type, $tokens);
        $this->assertContains(TokenType::FlowMappingStart, $types);
        $this->assertContains(TokenType::FlowSequenceStart, $types);
    }
}
