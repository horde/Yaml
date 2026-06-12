<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\Node\Node;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\ScalarStyle;
use Horde\Yaml\Document\TagHandler;
use Horde\Yaml\Document\TagHandlerException;
use Horde\Yaml\Document\TagRegistry;
use Horde\Yaml\Document\YamlStringDumper;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversNothing]
final class TagRegistryTest extends TestCase
{
    private function upperHandler(): TagHandler
    {
        return new class implements TagHandler {
            public function tag(): string
            {
                return '!Upper';
            }

            public function fromYaml(ScalarNode $node): mixed
            {
                return strtoupper((string) $node);
            }

            public function toYaml(mixed $value): Node
            {
                if (!is_string($value)) {
                    throw new TagHandlerException(
                        'Upper expects string, got ' . get_debug_type($value),
                    );
                }
                return new ScalarNode(
                    value: $value,
                    style: ScalarStyle::Plain,
                    tag: '!Upper',
                );
            }
        };
    }

    public function testRegisterAndLookup(): void
    {
        $reg = new TagRegistry();
        $h = $this->upperHandler();
        $reg->register($h);
        $this->assertTrue($reg->has('!Upper'));
        $this->assertSame($h, $reg->get('!Upper'));
        $this->assertSame(['!Upper'], $reg->tags());
    }

    public function testUnregister(): void
    {
        $reg = new TagRegistry();
        $reg->register($this->upperHandler());
        $reg->unregister('!Upper');
        $this->assertFalse($reg->has('!Upper'));
        $this->assertNull($reg->get('!Upper'));
    }

    public function testHandlerProducesResolvedValue(): void
    {
        $reg = new TagRegistry();
        $reg->register($this->upperHandler());
        $loader = new YamlStringLoader(tagRegistry: $reg);
        $stream = $loader->load("msg: !Upper hello\n");
        $node = $stream->getDocument(0)->root()->entry('msg')->getValue();
        $this->assertInstanceOf(ScalarNode::class, $node);
        $this->assertTrue($node->hasResolvedValue());
        $this->assertSame('HELLO', $node->getResolvedValue());
        // Lexical preserved for round-trip.
        $this->assertSame('hello', $node->getValue());
    }

    public function testResolvedViewSurfacesHandlerOutput(): void
    {
        $reg = new TagRegistry();
        $reg->register($this->upperHandler());
        $loader = new YamlStringLoader(tagRegistry: $reg);
        $stream = $loader->load("a: !Upper foo\nb: bar\n");
        $r = $stream->getDocument(0)->root()->resolved();
        $this->assertSame(['a' => 'FOO', 'b' => 'bar'], $r);
    }

    public function testRoundTripPreservesTaggedSource(): void
    {
        $reg = new TagRegistry();
        $reg->register($this->upperHandler());
        $src = "msg: !Upper hello world\n";
        $stream = (new YamlStringLoader(tagRegistry: $reg))->load($src);
        $out = (new YamlStringDumper())->dump($stream);
        $this->assertSame($src, $out);
    }

    public function testUnregisteredTagFallsThroughToString(): void
    {
        // No registry: custom tag value stays as the lexical string,
        // hasResolvedValue is false.
        $stream = (new YamlStringLoader())->load("x: !Unknown content\n");
        $node = $stream->getDocument(0)->root()->entry('x')->getValue();
        $this->assertInstanceOf(ScalarNode::class, $node);
        $this->assertFalse($node->hasResolvedValue());
        $this->assertSame('content', $node->getValue());
        $this->assertSame('!Unknown', $node->getTag());
    }

    public function testHandlerExceptionPropagates(): void
    {
        $reg = new TagRegistry();
        $reg->register(new class implements TagHandler {
            public function tag(): string
            {
                return '!Strict';
            }
            public function fromYaml(ScalarNode $node): mixed
            {
                throw new TagHandlerException('always fails');
            }
            public function toYaml(mixed $value): Node
            {
                throw new TagHandlerException('not implemented');
            }
        });
        $loader = new YamlStringLoader(tagRegistry: $reg);
        $this->expectException(TagHandlerException::class);
        $this->expectExceptionMessage('always fails');
        $loader->load("x: !Strict whatever\n");
    }

    public function testFindForValueLocatesHandlerByType(): void
    {
        $reg = new TagRegistry();
        $reg->register($this->upperHandler());
        // The Upper handler accepts strings; passing an object should
        // skip it and return null (no other handlers).
        $this->assertNull($reg->findForValue(new stdClass()));
    }

    public function testSetValueClearsResolvedValue(): void
    {
        $reg = new TagRegistry();
        $reg->register($this->upperHandler());
        $loader = new YamlStringLoader(tagRegistry: $reg);
        $stream = $loader->load("x: !Upper hello\n");
        $node = $stream->getDocument(0)->root()->entry('x')->getValue();
        $this->assertTrue($node->hasResolvedValue());
        $node->setValue('changed');
        $this->assertFalse($node->hasResolvedValue());
    }
}
