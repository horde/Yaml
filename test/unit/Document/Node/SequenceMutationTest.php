<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Node;

use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\SequenceItem;
use Horde\Yaml\Document\Node\SequenceNode;
use Horde\Yaml\Document\OutOfRangeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SequenceNode::class)]
final class SequenceMutationTest extends TestCase
{
    public function testAppendItem(): void
    {
        $seq = new SequenceNode();
        $seq->appendItem('a');
        $seq->appendItem('b');
        $values = array_map(static fn($i) => $i->getValue()->getValue(), $seq->items());
        $this->assertSame(['a', 'b'], $values);
    }

    public function testPrependItem(): void
    {
        $seq = new SequenceNode();
        $seq->appendItem('b');
        $seq->prependItem('a');
        $values = array_map(static fn($i) => $i->getValue()->getValue(), $seq->items());
        $this->assertSame(['a', 'b'], $values);
    }

    public function testInsertItemAt(): void
    {
        $seq = new SequenceNode();
        $seq->appendItem('a');
        $seq->appendItem('c');
        $seq->insertItemAt(1, 'b');
        $values = array_map(static fn($i) => $i->getValue()->getValue(), $seq->items());
        $this->assertSame(['a', 'b', 'c'], $values);
    }

    public function testInsertAtCountAppends(): void
    {
        $seq = new SequenceNode();
        $seq->appendItem('a');
        $seq->insertItemAt(1, 'b');
        $values = array_map(static fn($i) => $i->getValue()->getValue(), $seq->items());
        $this->assertSame(['a', 'b'], $values);
    }

    public function testInsertAtNegativeThrows(): void
    {
        $seq = new SequenceNode();
        $this->expectException(OutOfRangeException::class);
        $seq->insertItemAt(-1, 'x');
    }

    public function testInsertAtOutOfRangeThrows(): void
    {
        $seq = new SequenceNode();
        $seq->appendItem('a');
        $this->expectException(OutOfRangeException::class);
        $seq->insertItemAt(99, 'x');
    }

    public function testSetItemAt(): void
    {
        $seq = new SequenceNode();
        $seq->appendItem('a');
        $seq->appendItem('b');
        $seq->setItemAt(1, 'B');
        $this->assertSame('B', $seq->item(1)->getValue()->getValue());
    }

    public function testSetItemAtOutOfRangeThrows(): void
    {
        $seq = new SequenceNode();
        $seq->appendItem('a');
        $this->expectException(OutOfRangeException::class);
        $seq->setItemAt(5, 'x');
    }

    public function testRemoveItemByIndex(): void
    {
        $seq = new SequenceNode();
        $seq->appendItem('a');
        $seq->appendItem('b');
        $seq->appendItem('c');
        $seq->removeItem(1);
        $values = array_map(static fn($i) => $i->getValue()->getValue(), $seq->items());
        $this->assertSame(['a', 'c'], $values);
    }

    public function testRemoveItemByInstance(): void
    {
        $seq = new SequenceNode();
        $a = $seq->appendItem('a');
        $b = $seq->appendItem('b');
        $seq->removeItem($a);
        $this->assertCount(1, $seq->items());
        $this->assertNull($a->parent());
    }
}
