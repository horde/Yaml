<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\Node\ChompMode;
use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\ScalarStyle;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies F.03 end-to-end: block scalars carry their style, chomp,
 * and indent metadata through scanner -> parser -> resolver onto the
 * AST. Block scalars are always strings (resolver does not type
 * them).
 */
#[CoversNothing]
final class BlockScalarTypingTest extends TestCase
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

    public function testLiteralBlockValueIsScalarNodeWithStyle(): void
    {
        $root = $this->rootMap("desc: |\n  hello\n");
        $value = $root->entry('desc')?->getValue();
        $this->assertInstanceOf(ScalarNode::class, $value);
        $this->assertSame(ScalarStyle::LiteralBlock, $value->getStyle());
        $this->assertSame("hello\n", $value->getValue());
    }

    public function testFoldedBlockValueIsScalarNodeWithStyle(): void
    {
        $root = $this->rootMap("desc: >\n  hello world\n");
        $value = $root->entry('desc')?->getValue();
        $this->assertInstanceOf(ScalarNode::class, $value);
        $this->assertSame(ScalarStyle::FoldedBlock, $value->getStyle());
    }

    public function testChompModeThreadsToNode(): void
    {
        $root = $this->rootMap("desc: |-\n  hello\n");
        $value = $root->entry('desc')?->getValue();
        $this->assertSame(ChompMode::Strip, $value->getChomp());
    }

    public function testIndentIndicatorThreadsToNode(): void
    {
        $root = $this->rootMap("desc: |2\n  hello\n");
        $value = $root->entry('desc')?->getValue();
        $this->assertSame(2, $value->getIndentIndicator());
    }

    public function testBlockScalarContentLikeIntegerStaysString(): void
    {
        // `42` inside a block scalar is a string, not an int.
        $root = $this->rootMap("count: |\n  42\n");
        $value = $root->entry('count')?->getValue();
        $this->assertSame("42\n", $value->getValue());
    }

    public function testBlockScalarContentLikeBooleanStaysString(): void
    {
        $root = $this->rootMap("debug: |\n  true\n");
        $value = $root->entry('debug')?->getValue();
        $this->assertSame("true\n", $value->getValue());
    }

    public function testNodeHasNullChompForPlainAndQuotedScalars(): void
    {
        $root = $this->rootMap("plain: hello\nsq: 'hi'\n");
        $this->assertNull($root->entry('plain')?->getValue()->getChomp());
        $this->assertNull($root->entry('sq')?->getValue()->getChomp());
    }
}
