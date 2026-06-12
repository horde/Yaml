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
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Parser\Parser;
use Horde\Yaml\Document\Parser\Scanner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies C.05 parser behavior: nested block-mapping construction.
 */
#[CoversClass(Parser::class)]
#[CoversClass(Scanner::class)]
final class ParserNestedMappingTest extends TestCase
{
    private function parse(string $yaml): MapNode
    {
        $tokens = (new Scanner())->scan($yaml);
        $stream = (new Parser())->parse($tokens);
        $root = $stream->getDocuments()[0]->root();
        if (!$root instanceof MapNode) {
            throw new RuntimeException('Expected MapNode root');
        }
        return $root;
    }

    public function testTwoLevelNesting(): void
    {
        $source = "outer:\n  inner: 1\n";
        $root = $this->parse($source);

        $outer = $root->entry('outer');
        $this->assertNotNull($outer);
        $value = $outer->getValue();
        $this->assertInstanceOf(MapNode::class, $value);

        $inner = $value->entry('inner');
        $this->assertNotNull($inner);
        $innerValue = $inner->getValue();
        $this->assertInstanceOf(ScalarNode::class, $innerValue);
        $this->assertSame('1', $innerValue->getValue());
    }

    public function testThreeLevelNesting(): void
    {
        $source = "a:\n  b:\n    c: 1\n";
        $root = $this->parse($source);
        $a = $root->entry('a')?->getValue();
        $this->assertInstanceOf(MapNode::class, $a);
        $b = $a->entry('b')?->getValue();
        $this->assertInstanceOf(MapNode::class, $b);
        $c = $b->entry('c')?->getValue();
        $this->assertInstanceOf(ScalarNode::class, $c);
    }

    public function testNestedMapWithMultipleEntriesAtEachLevel(): void
    {
        $source = "servers:\n  mail:\n    host: smtp\n    port: 25\n  db:\n    host: localhost\n";
        $root = $this->parse($source);
        $servers = $root->entry('servers')?->getValue();
        $this->assertInstanceOf(MapNode::class, $servers);
        $this->assertCount(2, $servers->entries());

        $mail = $servers->entry('mail')?->getValue();
        $this->assertInstanceOf(MapNode::class, $mail);
        $this->assertCount(2, $mail->entries());

        $db = $servers->entry('db')?->getValue();
        $this->assertInstanceOf(MapNode::class, $db);
        $this->assertCount(1, $db->entries());
    }

    public function testEmptyValueAtBottomOfDocumentRemainsScalar(): void
    {
        // A `foo:` with no following indented content is an empty scalar.
        $source = "foo:\n";
        $root = $this->parse($source);
        $foo = $root->entry('foo');
        $this->assertNotNull($foo);
        $value = $foo->getValue();
        $this->assertInstanceOf(ScalarNode::class, $value);
        // Resolver hasn't run via the bare parser. Value is empty
        // string.
        $this->assertSame('', $value->getValue());
    }

    public function testSiblingDoesNotTriggerNestedMap(): void
    {
        // `foo:` followed by `bar:` at the same indent: foo is empty.
        $source = "foo:\nbar: 1\n";
        $root = $this->parse($source);
        $this->assertCount(2, $root->entries());
        $fooValue = $root->entry('foo')?->getValue();
        $this->assertInstanceOf(ScalarNode::class, $fooValue);
        $this->assertSame('', $fooValue->getValue());
    }

    public function testNestedMapEntryParentIsContainingMap(): void
    {
        $source = "outer:\n  inner: 1\n";
        $root = $this->parse($source);
        $outer = $root->entry('outer');
        $innerMap = $outer?->getValue();
        $this->assertInstanceOf(MapNode::class, $innerMap);
        // Inner map's parent should be the outer entry.
        $this->assertSame($outer, $innerMap->parent());
    }
}
