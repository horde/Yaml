<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\IoException;
use Horde\Yaml\Document\YamlResourceDumper;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(YamlResourceDumper::class)]
final class YamlResourceDumperTest extends TestCase
{
    public function testWritesToMemoryResource(): void
    {
        $stream = (new YamlStringLoader())->load("foo: 1\n");
        $resource = fopen('php://memory', 'r+');
        (new YamlResourceDumper())->dump($stream, $resource);
        rewind($resource);
        $output = stream_get_contents($resource);
        fclose($resource);
        $this->assertSame("foo: 1\n", $output);
    }

    public function testThrowsOnNonResource(): void
    {
        $stream = (new YamlStringLoader())->load("foo: 1\n");
        $this->expectException(IoException::class);
        (new YamlResourceDumper())->dump($stream, 'not a resource');
    }

    public function testFilterStylePipeline(): void
    {
        // Filter-style: load from one resource, dump to another.
        $in = fopen('php://memory', 'r+');
        fwrite($in, "foo: 1\nbar: 2\n");
        rewind($in);

        $stream = (new \Horde\Yaml\Document\YamlResourceLoader())->load($in);
        fclose($in);

        $out = fopen('php://memory', 'r+');
        (new YamlResourceDumper())->dump($stream, $out);
        rewind($out);
        $written = stream_get_contents($out);
        fclose($out);

        $this->assertSame("foo: 1\nbar: 2\n", $written);
    }

    public function testDoesNotCloseResource(): void
    {
        $stream = (new YamlStringLoader())->load("foo: 1\n");
        $resource = fopen('php://memory', 'r+');
        (new YamlResourceDumper())->dump($stream, $resource);
        $this->assertTrue(is_resource($resource));
        fclose($resource);
    }
}
