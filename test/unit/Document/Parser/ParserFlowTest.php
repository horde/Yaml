<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Parser;

use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\Node\MapStyle;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\SequenceNode;
use Horde\Yaml\Document\Node\SequenceStyle;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Verifies I.02 parser behavior: flow mappings and sequences.
 */
#[CoversNothing]
final class ParserFlowTest extends TestCase
{
    public function testEmptyFlowSequence(): void
    {
        $stream = (new YamlStringLoader())->load("[]\n");
        $root = $stream->getDocuments()[0]->root();
        $this->assertInstanceOf(SequenceNode::class, $root);
        $this->assertSame(SequenceStyle::Flow, $root->getStyle());
        $this->assertCount(0, $root->items());
    }

    public function testEmptyFlowMapping(): void
    {
        $stream = (new YamlStringLoader())->load("{}\n");
        $root = $stream->getDocuments()[0]->root();
        $this->assertInstanceOf(MapNode::class, $root);
        $this->assertSame(MapStyle::Flow, $root->getStyle());
        $this->assertCount(0, $root->entries());
    }

    public function testFlowSequenceWithItems(): void
    {
        $stream = (new YamlStringLoader())->load("[apple, banana, cherry]\n");
        $root = $stream->getDocuments()[0]->root();
        $this->assertInstanceOf(SequenceNode::class, $root);
        $items = $root->items();
        $this->assertCount(3, $items);
        $this->assertSame('apple', $items[0]->getValue()->getValue());
        $this->assertSame('banana', $items[1]->getValue()->getValue());
        $this->assertSame('cherry', $items[2]->getValue()->getValue());
    }

    public function testFlowMappingWithKeyValue(): void
    {
        $stream = (new YamlStringLoader())->load("{a: 1, b: 2}\n");
        $root = $stream->getDocuments()[0]->root();
        $this->assertInstanceOf(MapNode::class, $root);
        $this->assertSame(1, $root->entry('a')->getValue()->getValue());
        $this->assertSame(2, $root->entry('b')->getValue()->getValue());
    }

    public function testFlowSequenceAsBlockMapValue(): void
    {
        $stream = (new YamlStringLoader())->load("hosts: [a, b]\n");
        $root = $stream->getDocuments()[0]->root();
        $this->assertInstanceOf(MapNode::class, $root);
        $this->assertSame(MapStyle::Block, $root->getStyle());
        $hosts = $root->entry('hosts')->getValue();
        $this->assertInstanceOf(SequenceNode::class, $hosts);
        $this->assertSame(SequenceStyle::Flow, $hosts->getStyle());
    }

    public function testNestedFlowSequences(): void
    {
        $stream = (new YamlStringLoader())->load("[[1, 2], [3, 4]]\n");
        $root = $stream->getDocuments()[0]->root();
        $this->assertInstanceOf(SequenceNode::class, $root);
        $items = $root->items();
        $this->assertCount(2, $items);
        $this->assertInstanceOf(SequenceNode::class, $items[0]->getValue());
        $this->assertInstanceOf(SequenceNode::class, $items[1]->getValue());
    }

    public function testIntegerValuesTypedInFlow(): void
    {
        $stream = (new YamlStringLoader())->load("[1, 2, 3]\n");
        $root = $stream->getDocuments()[0]->root();
        $values = array_map(static fn($i) => $i->getValue()->getValue(), $root->items());
        $this->assertSame([1, 2, 3], $values);
    }

    public function testFlowMappingAsBlockSequenceItem(): void
    {
        $source = "authors:\n  - { name: Jan, email: jan@horde.org }\n";
        $stream = (new YamlStringLoader())->load($source);
        $root = $stream->getDocuments()[0]->root();
        $this->assertInstanceOf(MapNode::class, $root);
        $authors = $root->entry('authors')->getValue();
        $this->assertInstanceOf(SequenceNode::class, $authors);
        $this->assertCount(1, $authors->items());
        $author = $authors->items()[0]->getValue();
        $this->assertInstanceOf(MapNode::class, $author);
        $this->assertSame(MapStyle::Flow, $author->getStyle());
        $this->assertSame('Jan', $author->entry('name')->getValue()->getValue());
        $this->assertSame('jan@horde.org', $author->entry('email')->getValue()->getValue());
    }

    public function testFlowSequenceAsBlockSequenceItem(): void
    {
        $source = "tags:\n  - [a, b, c]\n  - [d, e]\n";
        $stream = (new YamlStringLoader())->load($source);
        $root = $stream->getDocuments()[0]->root();
        $tags = $root->entry('tags')->getValue();
        $this->assertInstanceOf(SequenceNode::class, $tags);
        $this->assertCount(2, $tags->items());
        $first = $tags->items()[0]->getValue();
        $this->assertInstanceOf(SequenceNode::class, $first);
        $this->assertSame(SequenceStyle::Flow, $first->getStyle());
        $this->assertCount(3, $first->items());
    }
}
