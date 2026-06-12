<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Parser;

use Horde\Yaml\Document\Node\CommentNode;
use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\Node\SequenceNode;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Verifies G.03 parser behavior: trailing trivia (EOL comments) on
 * tokens lands in the eolComment slot of the corresponding MapEntry
 * or SequenceItem.
 */
#[CoversNothing]
final class ParserEolCommentTest extends TestCase
{
    public function testEolCommentOnMapEntry(): void
    {
        $stream = (new YamlStringLoader())->load("foo: 1  # important\n");
        $root = $stream->getDocuments()[0]->root();
        $this->assertInstanceOf(MapNode::class, $root);
        $entry = $root->entry('foo');
        $this->assertNotNull($entry);
        $eol = $entry->getEolComment();
        $this->assertInstanceOf(CommentNode::class, $eol);
        $this->assertSame('# important', $eol->getText());
    }

    public function testNoEolCommentMeansNullSlot(): void
    {
        $stream = (new YamlStringLoader())->load("foo: 1\n");
        $root = $stream->getDocuments()[0]->root();
        $this->assertInstanceOf(MapNode::class, $root);
        $entry = $root->entry('foo');
        $this->assertNotNull($entry);
        $this->assertNull($entry->getEolComment());
    }

    public function testEolCommentOnSequenceItem(): void
    {
        $stream = (new YamlStringLoader())->load("- mail.example.com  # primary\n- backup.example.com\n");
        $root = $stream->getDocuments()[0]->root();
        $this->assertInstanceOf(SequenceNode::class, $root);
        $items = $root->items();
        $this->assertCount(2, $items);
        $eol0 = $items[0]->getEolComment();
        $this->assertInstanceOf(CommentNode::class, $eol0);
        $this->assertSame('# primary', $eol0->getText());
        $this->assertNull($items[1]->getEolComment());
    }

    public function testEolCommentParentIsEntry(): void
    {
        $stream = (new YamlStringLoader())->load("foo: 1  # x\n");
        $entry = $stream->getDocuments()[0]->root()->entry('foo');
        $this->assertSame($entry, $entry->getEolComment()->parent());
    }
}
