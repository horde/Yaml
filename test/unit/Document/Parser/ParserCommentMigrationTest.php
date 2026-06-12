<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Parser;

use Horde\Yaml\Document\Node\BlankLineNode;
use Horde\Yaml\Document\Node\CommentNode;
use Horde\Yaml\Document\Node\MapEntry;
use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies G.02 parser behavior: standalone comments and blank-line
 * groups become first-class CommentNode / BlankLineNode children of
 * the surrounding container, in source order.
 */
#[CoversNothing]
final class ParserCommentMigrationTest extends TestCase
{
    private function rootMap(string $yaml): MapNode
    {
        $stream = (new YamlStringLoader())->load($yaml);
        $root = $stream->getDocuments()[0]->root();
        if (!$root instanceof MapNode) {
            throw new RuntimeException('Expected MapNode root');
        }
        return $root;
    }

    public function testLeadingFileCommentBecomesFirstChild(): void
    {
        $root = $this->rootMap("# leading\nfoo: 1\n");
        $children = $root->children();
        $this->assertGreaterThanOrEqual(2, count($children));
        $this->assertInstanceOf(CommentNode::class, $children[0]);
        $this->assertSame('# leading', $children[0]->getText());
        $this->assertInstanceOf(MapEntry::class, $children[1]);
    }

    public function testCommentBetweenEntriesBecomesSibling(): void
    {
        $root = $this->rootMap("foo: 1\n# between\nbar: 2\n");
        $children = $root->children();
        // Expect: MapEntry(foo), CommentNode, MapEntry(bar)
        $this->assertCount(3, $children);
        $this->assertInstanceOf(MapEntry::class, $children[0]);
        $this->assertInstanceOf(CommentNode::class, $children[1]);
        $this->assertInstanceOf(MapEntry::class, $children[2]);
        $this->assertSame('# between', $children[1]->getText());
    }

    public function testBlankLineBetweenEntriesBecomesSibling(): void
    {
        $root = $this->rootMap("foo: 1\n\nbar: 2\n");
        $children = $root->children();
        $this->assertCount(3, $children);
        $this->assertInstanceOf(MapEntry::class, $children[0]);
        $this->assertInstanceOf(BlankLineNode::class, $children[1]);
        $this->assertSame(1, $children[1]->getCount());
        $this->assertInstanceOf(MapEntry::class, $children[2]);
    }

    public function testMultipleBlankLinesGroupedIntoOneNode(): void
    {
        $root = $this->rootMap("foo: 1\n\n\n\nbar: 2\n");
        $children = $root->children();
        $blanks = array_values(array_filter(
            $children,
            static fn($c) => $c instanceof BlankLineNode,
        ));
        $this->assertCount(1, $blanks);
        $this->assertSame(3, $blanks[0]->getCount());
    }

    public function testInterleavedCommentsAndBlanksPreserveOrder(): void
    {
        $root = $this->rootMap("foo: 1\n\n# between\n\nbar: 2\n");
        $children = $root->children();
        $kinds = array_map(
            static function ($c) {
                if ($c instanceof MapEntry) {
                    return 'entry:' . $c->getKeyString();
                }
                if ($c instanceof CommentNode) {
                    return 'comment:' . $c->getText();
                }
                if ($c instanceof BlankLineNode) {
                    return 'blank:' . $c->getCount();
                }
                return 'other';
            },
            $children,
        );
        $this->assertSame(
            ['entry:foo', 'blank:1', 'comment:# between', 'blank:1', 'entry:bar'],
            $kinds,
        );
    }

    public function testCommentInBlockSequence(): void
    {
        $stream = (new YamlStringLoader())->load("- a\n# between\n- b\n");
        $root = $stream->getDocuments()[0]->root();
        $children = $root->children();
        $this->assertCount(3, $children);
        $this->assertInstanceOf(CommentNode::class, $children[1]);
        $this->assertSame('# between', $children[1]->getText());
    }

    public function testCommentNodeParentIsContainer(): void
    {
        $root = $this->rootMap("foo: 1\n# between\nbar: 2\n");
        $children = $root->children();
        $this->assertSame($root, $children[1]->parent());
    }
}
