<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\ScalarStyle;
use Horde\Yaml\Document\Parser\Pipeline;
use Horde\Yaml\Document\YamlStream;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end smoke test for the load pipeline plumbing.
 *
 * Scanner / Parser / Resolver are stubs in this phase; verifying the
 * plumbing wires up correctly is the goal. Real load behavior is
 * tested progressively starting in chapter B.
 */
#[CoversClass(YamlStringLoader::class)]
#[CoversClass(Pipeline::class)]
final class YamlStringLoaderTest extends TestCase
{
    public function testLoadsEmptyStringToYamlStream(): void
    {
        $stream = (new YamlStringLoader())->load('');
        $this->assertInstanceOf(YamlStream::class, $stream);
    }

    public function testEmptyStreamHasZeroDocuments(): void
    {
        $stream = (new YamlStringLoader())->load('');
        $this->assertSame(0, $stream->documentCount());
        $this->assertSame([], $stream->getDocuments());
    }

    public function testEmptyStreamHasDefaultLineEnding(): void
    {
        $stream = (new YamlStringLoader())->load('');
        $this->assertSame("\n", $stream->getLineEnding());
    }

    public function testEmptyStreamHasNoTrailingNewlineByDefault(): void
    {
        $stream = (new YamlStringLoader())->load('');
        $this->assertFalse($stream->getTrailingNewline());
    }

    public function testLoaderIsStateless(): void
    {
        $loader = new YamlStringLoader();
        $a = $loader->load('');
        $b = $loader->load('');
        $this->assertNotSame($a, $b);
        $this->assertInstanceOf(YamlStream::class, $a);
        $this->assertInstanceOf(YamlStream::class, $b);
    }

    public function testLoadsScalarDocumentEndToEnd(): void
    {
        $stream = (new YamlStringLoader())->load("hello\n");
        $this->assertSame(1, $stream->documentCount());
        $doc = $stream->getDocuments()[0];
        $this->assertInstanceOf(ScalarNode::class, $doc->root());
        $this->assertSame('hello', $doc->root()->getValue());
        $this->assertSame(ScalarStyle::Plain, $doc->root()->getStyle());
    }

    public function testLoadsScalarDocumentWithDocumentMarker(): void
    {
        $stream = (new YamlStringLoader())->load("---\nhello\n");
        $doc = $stream->getDocuments()[0];
        $this->assertTrue($doc->getStartMarker());
        $this->assertSame('hello', $doc->root()->getValue());
    }
}
