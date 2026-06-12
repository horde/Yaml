<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Node;

use Horde\Yaml\Document\DuplicateKeyException;
use Horde\Yaml\Document\Node\CommentNode;
use Horde\Yaml\Document\Node\MapEntry;
use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\SequenceNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;
use Stringable;

#[CoversClass(MapNode::class)]
final class MapMutationTest extends TestCase
{
    public function testAddEntryAppends(): void
    {
        $map = new MapNode();
        $entry = $map->addEntry('foo', 1);
        $this->assertSame('foo', $entry->getKeyString());
        $this->assertSame(1, $entry->getValue()->getValue());
        $this->assertSame(1, count($map->entries()));
    }

    public function testAddEntryThrowsOnDuplicate(): void
    {
        $map = new MapNode();
        $map->addEntry('foo', 1);
        $this->expectException(DuplicateKeyException::class);
        $map->addEntry('foo', 2);
    }

    public function testSetEntryAppendsWhenMissing(): void
    {
        $map = new MapNode();
        $entry = $map->setEntry('foo', 1);
        $this->assertSame('foo', $entry->getKeyString());
    }

    public function testSetEntryReplacesExisting(): void
    {
        $map = new MapNode();
        $first = $map->addEntry('foo', 1);
        $second = $map->setEntry('foo', 99);
        $this->assertSame($first, $second);
        $this->assertSame(99, $second->getValue()->getValue());
    }

    public function testSetEntryRetainsTrivia(): void
    {
        $map = new MapNode();
        $entry = $map->addEntry('foo', 1);
        $entry->setEolComment('# important');
        $map->setEntry('foo', 99);
        $this->assertSame('# important', $entry->getEolComment()->getText());
    }

    public function testInsertEntryBefore(): void
    {
        $map = new MapNode();
        $map->addEntry('a', 1);
        $map->addEntry('c', 3);
        $map->insertEntryBefore('c', 'b', 2);
        $keys = array_map(static fn($e) => $e->getKeyString(), $map->entries());
        $this->assertSame(['a', 'b', 'c'], $keys);
    }

    public function testInsertEntryAfter(): void
    {
        $map = new MapNode();
        $map->addEntry('a', 1);
        $map->addEntry('c', 3);
        $map->insertEntryAfter('a', 'b', 2);
        $keys = array_map(static fn($e) => $e->getKeyString(), $map->entries());
        $this->assertSame(['a', 'b', 'c'], $keys);
    }

    public function testRemoveEntryByKey(): void
    {
        $map = new MapNode();
        $map->addEntry('a', 1);
        $map->addEntry('b', 2);
        $map->removeEntry('a');
        $keys = array_map(static fn($e) => $e->getKeyString(), $map->entries());
        $this->assertSame(['b'], $keys);
    }

    public function testRemoveEntryByInstance(): void
    {
        $map = new MapNode();
        $a = $map->addEntry('a', 1);
        $b = $map->addEntry('b', 2);
        $map->removeEntry($b);
        $keys = array_map(static fn($e) => $e->getKeyString(), $map->entries());
        $this->assertSame(['a'], $keys);
        $this->assertNull($b->parent());
    }

    public function testRemoveEntryDoesNotAffectAdjacentTrivia(): void
    {
        $map = new MapNode();
        $map->addEntry('foo', 1);
        $map->appendComment('# comment');
        $map->addEntry('bar', 2);
        $map->removeEntry('bar');
        $children = $map->children();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(CommentNode::class, $children[1]);
    }

    public function testValueAcceptsScalar(): void
    {
        $map = new MapNode();
        $entry = $map->addEntry('a', 'hello');
        $this->assertInstanceOf(ScalarNode::class, $entry->getValue());
    }

    public function testValueAcceptsNode(): void
    {
        $map = new MapNode();
        $nested = new MapNode();
        $entry = $map->addEntry('outer', $nested);
        $this->assertSame($nested, $entry->getValue());
    }

    public function testValueAcceptsStringable(): void
    {
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return 'value';
            }
        };
        $map = new MapNode();
        $entry = $map->addEntry('a', $stringable);
        $this->assertSame('value', $entry->getValue()->getValue());
    }

    public function testValueRejectsStructuralNode(): void
    {
        $map = new MapNode();
        $this->expectException(InvalidArgumentException::class);
        $map->addEntry('a', new MapEntry(new ScalarNode('k'), new ScalarNode('v')));
    }
}
