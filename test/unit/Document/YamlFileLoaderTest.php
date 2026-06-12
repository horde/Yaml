<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\FileNotFoundException;
use Horde\Yaml\Document\YamlFileLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(YamlFileLoader::class)]
#[CoversClass(FileNotFoundException::class)]
final class YamlFileLoaderTest extends TestCase
{
    private string $tempFile = '';

    protected function tearDown(): void
    {
        if ($this->tempFile !== '' && file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    private function writeTemp(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'yamlloader');
        file_put_contents($path, $content);
        $this->tempFile = $path;
        return $path;
    }

    public function testLoadsSimpleFile(): void
    {
        $path = $this->writeTemp("foo: 1\nbar: 2\n");
        $stream = (new YamlFileLoader())->load($path);
        $this->assertSame(1, $stream->documentCount());
        $this->assertSame(1, $stream->getDocument()->root()->entry('foo')->getValue()->getValue());
    }

    public function testThrowsFileNotFoundOnMissing(): void
    {
        $this->expectException(FileNotFoundException::class);
        (new YamlFileLoader())->load('/nonexistent/path/to/file.yml');
    }

    public function testFileNotFoundCarriesPath(): void
    {
        try {
            (new YamlFileLoader())->load('/nonexistent.yml');
            $this->fail('Expected exception');
        } catch (FileNotFoundException $e) {
            $this->assertSame('/nonexistent.yml', $e->path);
        }
    }

    public function testStateless(): void
    {
        $path = $this->writeTemp("foo: 1\n");
        $loader = new YamlFileLoader();
        $a = $loader->load($path);
        $b = $loader->load($path);
        $this->assertNotSame($a, $b);
    }
}
