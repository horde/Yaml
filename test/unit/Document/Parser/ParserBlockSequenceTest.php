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
use Horde\Yaml\Document\Node\SequenceNode;
use Horde\Yaml\Document\Node\SequenceStyle;
use Horde\Yaml\Document\Parser\Parser;
use Horde\Yaml\Document\Parser\Scanner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies D.02 parser behavior: block-sequence construction.
 */
#[CoversClass(Parser::class)]
final class ParserBlockSequenceTest extends TestCase
{
    private function parse(string $yaml): SequenceNode
    {
        $tokens = (new Scanner())->scan($yaml);
        $stream = (new Parser())->parse($tokens);
        $root = $stream->getDocuments()[0]->root();
        if (!$root instanceof SequenceNode) {
            throw new RuntimeException('Expected SequenceNode root');
        }
        return $root;
    }

    public function testSingleItemSequence(): void
    {
        $root = $this->parse("- foo\n");
        $this->assertSame(SequenceStyle::Block, $root->getStyle());
        $items = $root->items();
        $this->assertCount(1, $items);
        $value = $items[0]->getValue();
        $this->assertInstanceOf(ScalarNode::class, $value);
        $this->assertSame('foo', $value->getValue());
    }

    public function testThreeItemSequencePreservesOrder(): void
    {
        $root = $this->parse("- a\n- b\n- c\n");
        $items = $root->items();
        $this->assertCount(3, $items);
        $this->assertSame('a', $items[0]->getValue()->getValue());
        $this->assertSame('b', $items[1]->getValue()->getValue());
        $this->assertSame('c', $items[2]->getValue()->getValue());
    }

    public function testItemByIndex(): void
    {
        $root = $this->parse("- alpha\n- beta\n- gamma\n");
        $beta = $root->item(1);
        $this->assertNotNull($beta);
        $this->assertSame('beta', $beta->getValue()->getValue());
    }

    public function testOutOfRangeIndexReturnsNull(): void
    {
        $root = $this->parse("- one\n");
        $this->assertNull($root->item(5));
    }

    public function testItemParentIsSequence(): void
    {
        $root = $this->parse("- foo\n");
        $item = $root->items()[0];
        $this->assertSame($root, $item->parent());
    }

    public function testItemValueParentIsItem(): void
    {
        $root = $this->parse("- foo\n");
        $item = $root->items()[0];
        $value = $item->getValue();
        $this->assertSame($item, $value->parent());
    }

    public function testTypedValuesAfterResolver(): void
    {
        // The resolver runs in the Pipeline (not in the bare parser).
        // For typed values we go through YamlStringLoader.
        $stream = (new \Horde\Yaml\Document\YamlStringLoader())->load("- 1\n- true\n- 3.14\n");
        $root = $stream->getDocuments()[0]->root();
        $this->assertInstanceOf(SequenceNode::class, $root);
        $values = array_map(
            static fn($i) => $i->getValue()->getValue(),
            $root->items(),
        );
        $this->assertSame([1, true, 3.14], $values);
    }
}
