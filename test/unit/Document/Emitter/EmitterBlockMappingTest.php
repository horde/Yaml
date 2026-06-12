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
use Horde\Yaml\Document\Node\CommentNode;
use Horde\Yaml\Document\Node\MapEntry;
use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\ScalarStyle;
use Horde\Yaml\Document\YamlDocument;
use Horde\Yaml\Document\YamlStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies C.03 emitter behavior: flat block mappings emit correctly.
 *
 * Hand-built ASTs feed the emitter; output bytes are asserted.
 */
#[CoversClass(Emitter::class)]
final class EmitterBlockMappingTest extends TestCase
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

    public function testSingleEntryFlatMap(): void
    {
        $map = new MapNode();
        $map->appendChildInternal(
            new MapEntry(new ScalarNode('foo'), new ScalarNode(1)),
        );
        $output = (new Emitter())->emit($this->streamWithMap($map));
        $this->assertSame("foo: 1\n", $output);
    }

    public function testTwoEntryFlatMapPreservesOrder(): void
    {
        $map = new MapNode();
        $map->appendChildInternal(
            new MapEntry(new ScalarNode('foo'), new ScalarNode(1)),
        );
        $map->appendChildInternal(
            new MapEntry(new ScalarNode('bar'), new ScalarNode(2)),
        );
        $output = (new Emitter())->emit($this->streamWithMap($map));
        $this->assertSame("foo: 1\nbar: 2\n", $output);
    }

    public function testEntryWithStringValue(): void
    {
        $map = new MapNode();
        $map->appendChildInternal(
            new MapEntry(new ScalarNode('greeting'), new ScalarNode('hello world')),
        );
        $output = (new Emitter())->emit($this->streamWithMap($map));
        $this->assertSame("greeting: hello world\n", $output);
    }

    public function testEntryWithNullValue(): void
    {
        $map = new MapNode();
        $map->appendChildInternal(
            new MapEntry(new ScalarNode('foo'), new ScalarNode(null)),
        );
        $output = (new Emitter())->emit($this->streamWithMap($map));
        $this->assertSame("foo: null\n", $output);
    }

    public function testEntryWithBooleanValue(): void
    {
        $map = new MapNode();
        $map->appendChildInternal(
            new MapEntry(new ScalarNode('debug'), new ScalarNode(true)),
        );
        $output = (new Emitter())->emit($this->streamWithMap($map));
        $this->assertSame("debug: true\n", $output);
    }

    public function testEntryRetainsRawSourceOnValue(): void
    {
        $map = new MapNode();
        $value = new ScalarNode(255, ScalarStyle::Plain, '0xFF');
        $map->appendChildInternal(
            new MapEntry(new ScalarNode('mask'), $value),
        );
        $output = (new Emitter())->emit($this->streamWithMap($map));
        $this->assertSame("mask: 0xFF\n", $output);
    }

    public function testEntryWithEolComment(): void
    {
        $map = new MapNode();
        $map->appendChildInternal(
            new MapEntry(
                new ScalarNode('foo'),
                new ScalarNode(1),
                new CommentNode('# important'),
            ),
        );
        $output = (new Emitter())->emit($this->streamWithMap($map));
        $this->assertSame("foo: 1  # important\n", $output);
    }

    public function testStandaloneCommentBetweenEntries(): void
    {
        $map = new MapNode();
        $map->appendChildInternal(
            new MapEntry(new ScalarNode('foo'), new ScalarNode(1)),
        );
        $map->appendChildInternal(new CommentNode('# about bar'));
        $map->appendChildInternal(
            new MapEntry(new ScalarNode('bar'), new ScalarNode(2)),
        );
        $output = (new Emitter())->emit($this->streamWithMap($map));
        $this->assertSame("foo: 1\n# about bar\nbar: 2\n", $output);
    }
}
