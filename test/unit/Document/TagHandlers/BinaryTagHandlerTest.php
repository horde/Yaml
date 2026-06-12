<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\TagHandlers;

use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\TagHandlerException;
use Horde\Yaml\Document\TagHandlers\BinaryTagHandler;
use Horde\Yaml\Document\TagRegistry;
use Horde\Yaml\Document\YamlStringDumper;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class BinaryTagHandlerTest extends TestCase
{
    private function loader(): YamlStringLoader
    {
        $reg = new TagRegistry();
        $reg->register(new BinaryTagHandler());
        return new YamlStringLoader(tagRegistry: $reg);
    }

    public function testDecodesPlainBase64(): void
    {
        $stream = $this->loader()->load("x: !!binary aGVsbG8=\n");
        $node = $stream->getDocument(0)->root()->entry('x')->getValue();
        $this->assertSame('hello', $node->getResolvedValue());
    }

    public function testDecodesMultilineBase64(): void
    {
        $stream = $this->loader()->load("x: !!binary |\n  aGVsbG8g\n  d29ybGQ=\n");
        $node = $stream->getDocument(0)->root()->entry('x')->getValue();
        $this->assertSame('hello world', $node->getResolvedValue());
    }

    public function testEmptyPayload(): void
    {
        $stream = $this->loader()->load("x: !!binary \"\"\n");
        $node = $stream->getDocument(0)->root()->entry('x')->getValue();
        $this->assertSame('', $node->getResolvedValue());
    }

    public function testInvalidBase64Throws(): void
    {
        $this->expectException(TagHandlerException::class);
        $this->expectExceptionMessageMatches('/!!binary/');
        $this->loader()->load("x: !!binary \"not-valid-base-64-***\"\n");
    }

    public function testRoundTripPreservesSource(): void
    {
        $src = "icon: !!binary aGVsbG8gd29ybGQ=\n";
        $stream = $this->loader()->load($src);
        $out = (new YamlStringDumper())->dump($stream);
        $this->assertSame($src, $out);
    }

    public function testToYamlEncodes(): void
    {
        $h = new BinaryTagHandler();
        $node = $h->toYaml('hello');
        $this->assertInstanceOf(ScalarNode::class, $node);
        $this->assertSame('!!binary', $node->getTag());
        $this->assertSame('aGVsbG8=', $node->getValue());
    }

    public function testToYamlRejectsNonString(): void
    {
        $this->expectException(TagHandlerException::class);
        (new BinaryTagHandler())->toYaml(42);
    }

    public function testRoundTripBinaryBytes(): void
    {
        // Random binary payload through the encode/decode pair.
        $bytes = "\x00\x01\x02\xff\xfe\xfd";
        $encoded = base64_encode($bytes);
        $stream = $this->loader()->load("x: !!binary $encoded\n");
        $node = $stream->getDocument(0)->root()->entry('x')->getValue();
        $this->assertSame($bytes, $node->getResolvedValue());
    }
}
