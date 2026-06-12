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
use Horde\Yaml\Document\YamlResourceLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(YamlResourceLoader::class)]
final class YamlResourceLoaderTest extends TestCase
{
    public function testLoadsFromMemoryResource(): void
    {
        $resource = fopen('php://memory', 'r+');
        fwrite($resource, "foo: 1\n");
        rewind($resource);
        $stream = (new YamlResourceLoader())->load($resource);
        $this->assertSame(1, $stream->getDocument()->root()->entry('foo')->getValue()->getValue());
        fclose($resource);
    }

    public function testLoadsFromFilePointer(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'yamlres');
        file_put_contents($path, "bar: 2\n");
        $fp = fopen($path, 'r');
        $stream = (new YamlResourceLoader())->load($fp);
        fclose($fp);
        unlink($path);
        $this->assertSame(2, $stream->getDocument()->root()->entry('bar')->getValue()->getValue());
    }

    public function testThrowsOnNonResource(): void
    {
        $this->expectException(IoException::class);
        (new YamlResourceLoader())->load('not a resource');
    }

    public function testDoesNotCloseResource(): void
    {
        $resource = fopen('php://memory', 'r+');
        fwrite($resource, "x: 1\n");
        rewind($resource);
        (new YamlResourceLoader())->load($resource);
        // Resource should still be valid (a small valid op proves it).
        $this->assertTrue(is_resource($resource));
        fclose($resource);
    }
}
