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
use Horde\Yaml\Document\Node\AliasNode;
use Horde\Yaml\Document\Node\MapEntry;
use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\YamlDocument;
use Horde\Yaml\Document\YamlStream;
use Horde\Yaml\Document\YamlStringDumper;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies H.06 emitter behavior: anchors, aliases, and tags emitted
 * in canonical order (tag before anchor before value).
 */
#[CoversClass(Emitter::class)]
final class EmitterAnchorsAliasesTagsTest extends TestCase
{
    public function testAnchorRoundTrip(): void
    {
        $source = "foo: &x hello\n";
        $stream = (new YamlStringLoader())->load($source);
        $out = (new YamlStringDumper())->dump($stream);
        $this->assertSame($source, $out);
    }

    public function testAliasRoundTrip(): void
    {
        $source = "a: &x hello\nb: *x\n";
        $stream = (new YamlStringLoader())->load($source);
        $out = (new YamlStringDumper())->dump($stream);
        $this->assertSame($source, $out);
    }

    public function testTagRoundTrip(): void
    {
        $source = "foo: !!str 42\n";
        $stream = (new YamlStringLoader())->load($source);
        $out = (new YamlStringDumper())->dump($stream);
        $this->assertSame($source, $out);
    }

    public function testTagAndAnchorTogetherRoundTrip(): void
    {
        $source = "foo: !!str &x 42\n";
        $stream = (new YamlStringLoader())->load($source);
        $out = (new YamlStringDumper())->dump($stream);
        $this->assertSame($source, $out);
    }

    public function testAnchorBeforeTagInSourceEmitsCanonical(): void
    {
        // Stage 6 §6.4 canonical order is tag before anchor; the
        // emitter normalises regardless of source order.
        $source = "foo: &x !!int 42\n";
        $stream = (new YamlStringLoader())->load($source);
        $out = (new YamlStringDumper())->dump($stream);
        $this->assertSame("foo: !!int &x 42\n", $out);
    }

    public function testSynthesizedAnchorOnScalar(): void
    {
        $stream = new YamlStream();
        $doc = new YamlDocument();
        $doc->setParentStream($stream);
        $map = new MapNode();
        $value = new ScalarNode('hello');
        $value->setAnchor('greeting');
        $map->appendChildInternal(new MapEntry(new ScalarNode('foo'), $value));
        $doc->setRootInternal($map);
        $stream->appendInternalDocument($doc);
        $out = (new Emitter())->emit($stream);
        $this->assertSame("foo: &greeting hello\n", $out);
    }

    public function testSynthesizedTagOnScalar(): void
    {
        $stream = new YamlStream();
        $doc = new YamlDocument();
        $doc->setParentStream($stream);
        $map = new MapNode();
        $value = new ScalarNode('hello');
        $value->setTag('!mytag');
        $map->appendChildInternal(new MapEntry(new ScalarNode('foo'), $value));
        $doc->setRootInternal($map);
        $stream->appendInternalDocument($doc);
        $out = (new Emitter())->emit($stream);
        $this->assertSame("foo: !mytag hello\n", $out);
    }

    public function testSynthesizedAlias(): void
    {
        $stream = new YamlStream();
        $doc = new YamlDocument();
        $doc->setParentStream($stream);
        $map = new MapNode();
        $alias = new AliasNode('shared');
        $map->appendChildInternal(new MapEntry(new ScalarNode('ref'), $alias));
        $doc->setRootInternal($map);
        $stream->appendInternalDocument($doc);
        $out = (new Emitter())->emit($stream);
        $this->assertSame("ref: *shared\n", $out);
    }
}
