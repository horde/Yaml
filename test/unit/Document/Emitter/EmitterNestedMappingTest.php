<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Emitter;

use Horde\Yaml\Document\Emitter\Emitter;
use Horde\Yaml\Document\Node\MapEntry;
use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\YamlDocument;
use Horde\Yaml\Document\YamlStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies C.06 emitter behavior: nested block mappings.
 */
#[CoversClass(Emitter::class)]
final class EmitterNestedMappingTest extends TestCase
{
    private function streamWithMap(MapNode $map): YamlStream
    {
        $stream = new YamlStream();
        $doc = new YamlDocument();
        $doc->setParentStream($stream);
        $doc->setRootInternal($map);
        $stream->appendInternalDocument($doc);
        return $stream;
    }

    public function testTwoLevelNesting(): void
    {
        $inner = new MapNode();
        $inner->appendChildInternal(
            new MapEntry(new ScalarNode('inner'), new ScalarNode(1)),
        );
        $outer = new MapNode();
        $outer->appendChildInternal(
            new MapEntry(new ScalarNode('outer'), $inner),
        );
        $output = (new Emitter())->emit($this->streamWithMap($outer));
        $this->assertSame("outer:\n  inner: 1\n", $output);
    }

    public function testThreeLevelNesting(): void
    {
        $level3 = new MapNode();
        $level3->appendChildInternal(
            new MapEntry(new ScalarNode('c'), new ScalarNode(1)),
        );
        $level2 = new MapNode();
        $level2->appendChildInternal(
            new MapEntry(new ScalarNode('b'), $level3),
        );
        $level1 = new MapNode();
        $level1->appendChildInternal(
            new MapEntry(new ScalarNode('a'), $level2),
        );
        $output = (new Emitter())->emit($this->streamWithMap($level1));
        $this->assertSame("a:\n  b:\n    c: 1\n", $output);
    }

    public function testMixedFlatAndNestedAtSameLevel(): void
    {
        $nested = new MapNode();
        $nested->appendChildInternal(
            new MapEntry(new ScalarNode('host'), new ScalarNode('localhost')),
        );

        $root = new MapNode();
        $root->appendChildInternal(
            new MapEntry(new ScalarNode('name'), new ScalarNode('app')),
        );
        $root->appendChildInternal(
            new MapEntry(new ScalarNode('db'), $nested),
        );
        $root->appendChildInternal(
            new MapEntry(new ScalarNode('debug'), new ScalarNode(true)),
        );
        $output = (new Emitter())->emit($this->streamWithMap($root));
        $this->assertSame(
            "name: app\ndb:\n  host: localhost\ndebug: true\n",
            $output,
        );
    }

    public function testNestedMapWithMultipleInnerEntries(): void
    {
        $inner = new MapNode();
        $inner->appendChildInternal(
            new MapEntry(new ScalarNode('a'), new ScalarNode(1)),
        );
        $inner->appendChildInternal(
            new MapEntry(new ScalarNode('b'), new ScalarNode(2)),
        );
        $outer = new MapNode();
        $outer->appendChildInternal(
            new MapEntry(new ScalarNode('outer'), $inner),
        );
        $output = (new Emitter())->emit($this->streamWithMap($outer));
        $this->assertSame("outer:\n  a: 1\n  b: 2\n", $output);
    }
}
