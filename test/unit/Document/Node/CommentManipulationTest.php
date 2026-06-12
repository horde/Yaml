<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Node;

use Horde\Yaml\Document\Node\CommentNode;
use Horde\Yaml\Document\Node\MapEntry;
use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\SequenceItem;
use Horde\Yaml\Document\Node\SequenceNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

/**
 * Verifies G.05 comment manipulation API on MapNode, SequenceNode,
 * MapEntry, SequenceItem.
 */
#[CoversClass(MapNode::class)]
#[CoversClass(SequenceNode::class)]
#[CoversClass(MapEntry::class)]
#[CoversClass(SequenceItem::class)]
final class CommentManipulationTest extends TestCase
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

    public function testAppendCommentToMap(): void
    {
        $map = $this->mapWithEntries('foo', 'bar');
        $comment = $map->appendComment('# trailing');
        $this->assertSame('# trailing', $comment->getText());
        $this->assertSame($map, $comment->parent());
        $children = $map->children();
        $this->assertSame($comment, $children[count($children) - 1]);
    }

    public function testAppendCommentAcceptsNode(): void
    {
        $map = $this->mapWithEntries('foo');
        $node = new CommentNode('# x');
        $map->appendComment($node);
        $this->assertSame($node, $map->children()[1]);
    }

    public function testAppendCommentRejectsTextWithoutHash(): void
    {
        $map = new MapNode();
        $this->expectException(InvalidArgumentException::class);
        $map->appendComment('no hash');
    }

    public function testInsertCommentBeforeKey(): void
    {
        $map = $this->mapWithEntries('foo', 'bar');
        $map->insertCommentBefore('bar', '# above bar');
        $children = $map->children();
        $this->assertCount(3, $children);
        $this->assertInstanceOf(MapEntry::class, $children[0]);
        $this->assertInstanceOf(CommentNode::class, $children[1]);
        $this->assertSame('# above bar', $children[1]->getText());
        $this->assertInstanceOf(MapEntry::class, $children[2]);
    }

    public function testInsertCommentAfterKey(): void
    {
        $map = $this->mapWithEntries('foo', 'bar');
        $map->insertCommentAfter('foo', '# below foo');
        $children = $map->children();
        $this->assertSame('# below foo', $children[1]->getText());
    }

    public function testCommentBeforeFindsImmediatelyPrecedingComment(): void
    {
        $map = $this->mapWithEntries('foo');
        $map->insertCommentBefore('foo', '# header');
        $found = $map->commentBefore('foo');
        $this->assertNotNull($found);
        $this->assertSame('# header', $found->getText());
    }

    public function testCommentBeforeReturnsNullWhenNoPrecedingComment(): void
    {
        $map = $this->mapWithEntries('foo');
        $this->assertNull($map->commentBefore('foo'));
    }

    public function testRemoveComment(): void
    {
        $map = $this->mapWithEntries('foo');
        $comment = $map->appendComment('# x');
        $map->removeComment($comment);
        $this->assertCount(1, $map->children());
        $this->assertNull($comment->parent());
    }

    public function testInsertCommentBeforeUnknownKeyThrows(): void
    {
        $map = $this->mapWithEntries('foo');
        $this->expectException(InvalidArgumentException::class);
        $map->insertCommentBefore('nonexistent', '# x');
    }

    public function testSetEolCommentOnEntryAcceptsString(): void
    {
        $entry = new MapEntry(new ScalarNode('foo'), new ScalarNode(1));
        $entry->setEolComment('# important');
        $this->assertSame('# important', $entry->getEolComment()->getText());
        $this->assertSame($entry, $entry->getEolComment()->parent());
    }

    public function testSetEolCommentOnEntryAcceptsNull(): void
    {
        $entry = new MapEntry(
            new ScalarNode('foo'),
            new ScalarNode(1),
            new CommentNode('# x'),
        );
        $entry->setEolComment(null);
        $this->assertNull($entry->getEolComment());
    }

    public function testSetEolCommentRejectsTextWithoutHash(): void
    {
        $entry = new MapEntry(new ScalarNode('foo'), new ScalarNode(1));
        $this->expectException(InvalidArgumentException::class);
        $entry->setEolComment('no hash');
    }

    public function testSequenceCommentInsertion(): void
    {
        $seq = new SequenceNode();
        $seq->appendChildInternal(new SequenceItem(new ScalarNode('a')));
        $seq->appendChildInternal(new SequenceItem(new ScalarNode('b')));
        $seq->insertCommentBefore(1, '# above b');
        $children = $seq->children();
        $this->assertCount(3, $children);
        $this->assertInstanceOf(CommentNode::class, $children[1]);
        $this->assertSame('# above b', $children[1]->getText());
    }

    public function testSequenceItemEolComment(): void
    {
        $item = new SequenceItem(new ScalarNode('a'));
        $item->setEolComment('# first');
        $this->assertSame('# first', $item->getEolComment()->getText());
        $this->assertSame($item, $item->getEolComment()->parent());
    }
}
