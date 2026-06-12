<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Node;

use Horde\Yaml\Document\Node\AliasNode;
use Horde\Yaml\Document\Node\BlankLineNode;
use Horde\Yaml\Document\Node\CommentNode;
use Horde\Yaml\Document\Node\Directive;
use Horde\Yaml\Document\Node\MapEntry;
use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\Node\Node;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\SequenceItem;
use Horde\Yaml\Document\Node\SequenceNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stringable;

/**
 * Smoke tests for the AST node skeletons. Each concrete class:
 * - is instantiable,
 * - implements the Node interface,
 * - returns 0 for line() and column() before any source position is
 *   stamped (synthesized-node behavior per Stage 3 §0.5),
 * - returns null from parent() before being attached to a tree.
 *
 * Specific node behaviour (children lists, value fields, etc.) is
 * tested as it lands in later phases.
 */
#[CoversClass(MapNode::class)]
#[CoversClass(MapEntry::class)]
#[CoversClass(SequenceNode::class)]
#[CoversClass(SequenceItem::class)]
#[CoversClass(ScalarNode::class)]
#[CoversClass(AliasNode::class)]
#[CoversClass(CommentNode::class)]
#[CoversClass(BlankLineNode::class)]
#[CoversClass(Directive::class)]
final class NodeSkeletonTest extends TestCase
{
    /**
     * @return iterable<string, array{Node}>
     */
    public static function everyNodeType(): iterable
    {
        yield 'MapNode' => [new MapNode()];
        yield 'MapEntry' => [new MapEntry(new ScalarNode('k'), new ScalarNode('v'))];
        yield 'SequenceNode' => [new SequenceNode()];
        yield 'SequenceItem' => [new SequenceItem()];
        yield 'ScalarNode' => [new ScalarNode()];
        yield 'AliasNode' => [new AliasNode()];
        yield 'CommentNode' => [new CommentNode()];
        yield 'BlankLineNode' => [new BlankLineNode()];
        yield 'Directive' => [new Directive()];
    }

    #[DataProvider('everyNodeType')]
    public function testImplementsNodeInterface(Node $node): void
    {
        $this->assertInstanceOf(Node::class, $node);
    }

    #[DataProvider('everyNodeType')]
    public function testFreshNodeReturnsZeroLine(Node $node): void
    {
        $this->assertSame(0, $node->line());
    }

    #[DataProvider('everyNodeType')]
    public function testFreshNodeReturnsZeroColumn(Node $node): void
    {
        $this->assertSame(0, $node->column());
    }

    #[DataProvider('everyNodeType')]
    public function testFreshNodeHasNoParent(Node $node): void
    {
        $this->assertNull($node->parent());
    }

    #[DataProvider('everyNodeType')]
    public function testFreshNodeHasNoDocument(Node $node): void
    {
        $this->assertNull($node->document());
    }

    #[DataProvider('everyNodeType')]
    public function testFreshNodeHasNoStream(Node $node): void
    {
        $this->assertNull($node->stream());
    }

    public function testScalarNodeIsStringable(): void
    {
        $this->assertInstanceOf(Stringable::class, new ScalarNode());
    }

    public function testSetParentLinksTwoNodes(): void
    {
        $parent = new MapNode();
        $child = new ScalarNode();
        $child->setParent($parent);
        $this->assertSame($parent, $child->parent());
    }

    public function testSetPositionStampsLineAndColumn(): void
    {
        $node = new ScalarNode();
        $node->setPosition(12, 5);
        $this->assertSame(12, $node->line());
        $this->assertSame(5, $node->column());
    }
}
