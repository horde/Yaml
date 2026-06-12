<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Node;

use Horde\Yaml\Document\Node\BlankLineNode;
use Horde\Yaml\Document\Node\MapEntry;
use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\SequenceItem;
use Horde\Yaml\Document\Node\SequenceNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

/**
 * Verifies G.06 blank-line manipulation API on MapNode and
 * SequenceNode.
 */
#[CoversClass(MapNode::class)]
#[CoversClass(SequenceNode::class)]
final class BlankLineManipulationTest extends TestCase
{
    private function mapWithEntries(string ...$keys): MapNode
    {
        $map = new MapNode();
        foreach ($keys as $key) {
            $map->appendChildInternal(
                new MapEntry(new ScalarNode($key), new ScalarNode($key . '_value')),
            );
        }
        return $map;
    }

    public function testAppendBlankLineDefaultCount(): void
    {
        $map = $this->mapWithEntries('foo');
        $node = $map->appendBlankLines();
        $this->assertSame(1, $node->getCount());
        $this->assertSame($map, $node->parent());
    }

    public function testAppendBlankLinesWithCount(): void
    {
        $map = $this->mapWithEntries('foo');
        $node = $map->appendBlankLines(3);
        $this->assertSame(3, $node->getCount());
    }

    public function testInsertBlankLinesBeforeKey(): void
    {
        $map = $this->mapWithEntries('foo', 'bar');
        $node = $map->insertBlankLinesBefore('bar', 2);
        $children = $map->children();
        $this->assertCount(3, $children);
        $this->assertInstanceOf(MapEntry::class, $children[0]);
        $this->assertSame($node, $children[1]);
        $this->assertSame(2, $children[1]->getCount());
        $this->assertInstanceOf(MapEntry::class, $children[2]);
    }

    public function testInsertBlankLinesAfterKey(): void
    {
        $map = $this->mapWithEntries('foo', 'bar');
        $node = $map->insertBlankLinesAfter('foo', 1);
        $this->assertSame($node, $map->children()[1]);
    }

    public function testRemoveBlankLines(): void
    {
        $map = $this->mapWithEntries('foo');
        $node = $map->appendBlankLines(2);
        $map->removeBlankLines($node);
        $this->assertCount(1, $map->children());
        $this->assertNull($node->parent());
    }

    public function testZeroCountThrowsAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BlankLineNode(0);
    }

    public function testNegativeCountThrowsAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BlankLineNode(-1);
    }

    public function testSequenceBlankLineInsertion(): void
    {
        $seq = new SequenceNode();
        $seq->appendChildInternal(new SequenceItem(new ScalarNode('a')));
        $seq->appendChildInternal(new SequenceItem(new ScalarNode('b')));
        $node = $seq->insertBlankLinesBefore(1, 1);
        $this->assertSame($node, $seq->children()[1]);
    }
}
