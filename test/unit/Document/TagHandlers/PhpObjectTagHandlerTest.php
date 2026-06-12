<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\TagHandlers;

use Horde\Yaml\Document\TagHandlerException;
use Horde\Yaml\Document\TagHandlers\PhpObjectTagHandler;
use Horde\Yaml\Document\TagRegistry;
use Horde\Yaml\Document\YamlStringDumper;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use __PHP_Incomplete_Class;

final class PhpObjectFixture
{
    public function __construct(public string $name = '') {}
}

final class PhpObjectGadget {}

#[CoversNothing]
final class PhpObjectTagHandlerTest extends TestCase
{
    private function srcWithSerialized(object $obj): string
    {
        $encoded = serialize($obj);
        // Single-quoted YAML scalar to avoid escape-sequence
        // collisions on backslashes in fully-qualified class names.
        return "obj: !php/object '" . str_replace("'", "''", $encoded) . "'\n";
    }

    public function testInstantiatesAllowedClass(): void
    {
        $reg = new TagRegistry();
        $reg->register(new PhpObjectTagHandler([PhpObjectFixture::class]));
        $stream = (new YamlStringLoader(tagRegistry: $reg))
            ->load($this->srcWithSerialized(new PhpObjectFixture('Jan')));
        $obj = $stream->getDocument(0)->root()->entry('obj')->getValue()->getResolvedValue();
        $this->assertInstanceOf(PhpObjectFixture::class, $obj);
        $this->assertSame('Jan', $obj->name);
    }

    public function testEmptyAllowListReturnsIncompleteClass(): void
    {
        $reg = new TagRegistry();
        $reg->register(new PhpObjectTagHandler());
        $stream = (new YamlStringLoader(tagRegistry: $reg))
            ->load($this->srcWithSerialized(new PhpObjectGadget()));
        $obj = $stream->getDocument(0)->root()->entry('obj')->getValue()->getResolvedValue();
        $this->assertInstanceOf(__PHP_Incomplete_Class::class, $obj);
    }

    public function testUnlistedClassReturnsIncompleteClass(): void
    {
        $reg = new TagRegistry();
        $reg->register(new PhpObjectTagHandler([PhpObjectFixture::class]));
        $stream = (new YamlStringLoader(tagRegistry: $reg))
            ->load($this->srcWithSerialized(new PhpObjectGadget()));
        $obj = $stream->getDocument(0)->root()->entry('obj')->getValue()->getResolvedValue();
        $this->assertInstanceOf(__PHP_Incomplete_Class::class, $obj);
    }

    public function testEmptyPayloadThrows(): void
    {
        $reg = new TagRegistry();
        $reg->register(new PhpObjectTagHandler());
        $this->expectException(TagHandlerException::class);
        (new YamlStringLoader(tagRegistry: $reg))->load("obj: !php/object \"\"\n");
    }

    public function testMalformedPayloadThrows(): void
    {
        $reg = new TagRegistry();
        $reg->register(new PhpObjectTagHandler());
        $this->expectException(TagHandlerException::class);
        (new YamlStringLoader(tagRegistry: $reg))
            ->load("obj: !php/object \"definitely-not-serialized\"\n");
    }

    public function testToYamlSerialises(): void
    {
        $h = new PhpObjectTagHandler();
        $node = $h->toYaml(new PhpObjectFixture('Ralf'));
        $this->assertSame('!php/object', $node->getTag());
        $unserialised = unserialize(
            $node->getValue(),
            ['allowed_classes' => [PhpObjectFixture::class]],
        );
        $this->assertInstanceOf(PhpObjectFixture::class, $unserialised);
        $this->assertSame('Ralf', $unserialised->name);
    }

    public function testToYamlRejectsNonObject(): void
    {
        $this->expectException(TagHandlerException::class);
        (new PhpObjectTagHandler())->toYaml('not an object');
    }

    public function testRoundTripPreservesLexicalSource(): void
    {
        $src = $this->srcWithSerialized(new PhpObjectFixture('Round Trip'));
        $reg = new TagRegistry();
        $reg->register(new PhpObjectTagHandler([PhpObjectFixture::class]));
        $stream = (new YamlStringLoader(tagRegistry: $reg))->load($src);
        $out = (new YamlStringDumper())->dump($stream);
        $this->assertSame($src, $out);
    }
}
