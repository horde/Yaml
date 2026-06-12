<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Node;

use Horde\Yaml\Document\Node\MapEntry;
use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\SequenceItem;
use Horde\Yaml\Document\Node\SequenceNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;
use Stringable;

#[CoversClass(MapEntry::class)]
#[CoversClass(SequenceItem::class)]
final class EntryItemSettersTest extends TestCase
{
    public function testMapEntrySetKey(): void
    {
        $entry = new MapEntry(new ScalarNode('old'), new ScalarNode(1));
        $entry->setKey('new');
        $this->assertSame('new', $entry->getKeyString());
    }

    public function testMapEntrySetKeyAcceptsScalarNode(): void
    {
        $entry = new MapEntry(new ScalarNode('old'), new ScalarNode(1));
        $newKey = new ScalarNode('explicit');
        $entry->setKey($newKey);
        $this->assertSame($newKey, $entry->getKey());
    }

    public function testMapEntrySetValueAcceptsScalar(): void
    {
        $entry = new MapEntry(new ScalarNode('k'), new ScalarNode(1));
        $entry->setValue(99);
        $this->assertSame(99, $entry->getValue()->getValue());
    }

    public function testMapEntrySetValueAcceptsNode(): void
    {
        $entry = new MapEntry(new ScalarNode('k'), new ScalarNode(1));
        $nested = new MapNode();
        $entry->setValue($nested);
        $this->assertSame($nested, $entry->getValue());
    }

    public function testMapEntrySetValueRejectsStructural(): void
    {
        $entry = new MapEntry(new ScalarNode('k'), new ScalarNode(1));
        $this->expectException(InvalidArgumentException::class);
        $entry->setValue(new MapEntry(new ScalarNode('x'), new ScalarNode('y')));
    }

    public function testSequenceItemSetValueAcceptsScalar(): void
    {
        $item = new SequenceItem(new ScalarNode('a'));
        $item->setValue('b');
        $this->assertSame('b', $item->getValue()->getValue());
    }

    public function testSequenceItemSetValueAcceptsNode(): void
    {
        $item = new SequenceItem(new ScalarNode('a'));
        $nested = new SequenceNode();
        $item->setValue($nested);
        $this->assertSame($nested, $item->getValue());
    }

    public function testSequenceItemSetValueAcceptsStringable(): void
    {
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return 'value';
            }
        };
        $item = new SequenceItem(new ScalarNode('a'));
        $item->setValue($stringable);
        $this->assertSame('value', $item->getValue()->getValue());
    }

    public function testSetValueParentsValueToContainer(): void
    {
        $entry = new MapEntry(new ScalarNode('k'), new ScalarNode(1));
        $newValue = new ScalarNode(2);
        $entry->setValue($newValue);
        $this->assertSame($entry, $newValue->parent());
    }
}
