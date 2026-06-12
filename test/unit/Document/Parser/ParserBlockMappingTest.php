<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Parser;

use Horde\Yaml\Document\Node\MapEntry;
use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\Node\MapStyle;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\ScalarStyle;
use Horde\Yaml\Document\Parser\Parser;
use Horde\Yaml\Document\Parser\Scanner;
use Horde\Yaml\Document\Token;
use Horde\Yaml\Document\TokenType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies C.02 parser behavior: block-mapping construction.
 */
#[CoversClass(Parser::class)]
final class ParserBlockMappingTest extends TestCase
{
    public function testBuildsMapNodeWithSingleEntry(): void
    {
        $tokens = (new Scanner())->scan("foo: 1\n");
        $stream = (new Parser())->parse($tokens);
        $root = $stream->getDocuments()[0]->root();

        $this->assertInstanceOf(MapNode::class, $root);
        $this->assertSame(MapStyle::Block, $root->getStyle());

        $entries = $root->entries();
        $this->assertCount(1, $entries);
        $entry = $entries[0];
        $this->assertSame('foo', $entry->getKeyString());
        $value = $entry->getValue();
        $this->assertInstanceOf(ScalarNode::class, $value);
        $this->assertSame('1', $value->getValue());
    }

    public function testBuildsMapNodeWithTwoEntriesPreservingOrder(): void
    {
        $tokens = (new Scanner())->scan("foo: 1\nbar: 2\n");
        $stream = (new Parser())->parse($tokens);
        $root = $stream->getDocuments()[0]->root();
        $this->assertInstanceOf(MapNode::class, $root);

        $entries = $root->entries();
        $this->assertCount(2, $entries);
        $this->assertSame('foo', $entries[0]->getKeyString());
        $this->assertSame('bar', $entries[1]->getKeyString());
    }

    public function testEntryByKey(): void
    {
        $tokens = (new Scanner())->scan("alpha: A\nbeta: B\ngamma: C\n");
        $stream = (new Parser())->parse($tokens);
        $root = $stream->getDocuments()[0]->root();
        $this->assertInstanceOf(MapNode::class, $root);
        $entry = $root->entry('beta');
        $this->assertInstanceOf(MapEntry::class, $entry);
        $value = $entry->getValue();
        $this->assertInstanceOf(ScalarNode::class, $value);
        $this->assertSame('B', $value->getValue());
    }

    public function testMissingKeyReturnsNull(): void
    {
        $tokens = (new Scanner())->scan("foo: 1\n");
        $stream = (new Parser())->parse($tokens);
        $root = $stream->getDocuments()[0]->root();
        $this->assertInstanceOf(MapNode::class, $root);
        $this->assertNull($root->entry('nonexistent'));
    }

    public function testEntryParentIsMapNode(): void
    {
        $tokens = (new Scanner())->scan("foo: 1\n");
        $stream = (new Parser())->parse($tokens);
        $root = $stream->getDocuments()[0]->root();
        $this->assertInstanceOf(MapNode::class, $root);
        $entry = $root->entries()[0];
        $this->assertSame($root, $entry->parent());
    }

    public function testKeyAndValueParentIsEntry(): void
    {
        $tokens = (new Scanner())->scan("foo: 1\n");
        $stream = (new Parser())->parse($tokens);
        $root = $stream->getDocuments()[0]->root();
        $this->assertInstanceOf(MapNode::class, $root);
        $entry = $root->entries()[0];
        $this->assertSame($entry, $entry->getKey()->parent());
        $this->assertSame($entry, $entry->getValue()->parent());
    }

    public function testNullValueFromColonOnly(): void
    {
        // After resolver runs (via the Pipeline), the empty scalar
        // becomes null. Here we test the parser side directly: the
        // value scalar exists with empty source bytes.
        $tokens = (new Scanner())->scan("foo:\n");
        $stream = (new Parser())->parse($tokens);
        $root = $stream->getDocuments()[0]->root();
        $this->assertInstanceOf(MapNode::class, $root);
        $entry = $root->entries()[0];
        $value = $entry->getValue();
        $this->assertInstanceOf(ScalarNode::class, $value);
        $this->assertSame('', $value->getValue());
    }

    public function testHandBuiltTokenStreamWithBlockMappingStartProducesMap(): void
    {
        $tokens = [
            new Token(TokenType::StreamStart, line: 1, column: 1),
            new Token(TokenType::BlockMappingStart, line: 1, column: 1),
            new Token(TokenType::Key, line: 1, column: 1),
            new Token(TokenType::Scalar, line: 1, column: 1, value: 'k', style: ScalarStyle::Plain),
            new Token(TokenType::Value, line: 1, column: 2),
            new Token(TokenType::Scalar, line: 1, column: 4, value: 'v', style: ScalarStyle::Plain),
            new Token(TokenType::BlockEnd, line: 2, column: 1),
            new Token(TokenType::StreamEnd, line: 2, column: 1),
        ];
        $stream = (new Parser())->parse($tokens);
        $root = $stream->getDocuments()[0]->root();
        $this->assertInstanceOf(MapNode::class, $root);
        $entry = $root->entries()[0];
        $this->assertSame('k', $entry->getKeyString());
    }
}
