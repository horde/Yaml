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
use Horde\Yaml\Document\YamlFileDumper;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(YamlFileDumper::class)]
final class YamlFileDumperTest extends TestCase
{
    /** @var list<string> */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $p) {
            if (file_exists($p)) {
                unlink($p);
            }
        }
    }

    private function freshTempPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'yamldump');
        unlink($path);
        $this->tempPaths[] = $path;
        return $path;
    }

    public function testDumpsToFile(): void
    {
        $stream = (new YamlStringLoader())->load("foo: 1\nbar: 2\n");
        $path = $this->freshTempPath();
        (new YamlFileDumper())->dump($stream, $path);
        $this->assertTrue(file_exists($path));
        $this->assertSame("foo: 1\nbar: 2\n", file_get_contents($path));
    }

    public function testOverwritesExistingFile(): void
    {
        $path = $this->freshTempPath();
        file_put_contents($path, "old content\n");
        $stream = (new YamlStringLoader())->load("new: content\n");
        (new YamlFileDumper())->dump($stream, $path);
        $this->assertSame("new: content\n", file_get_contents($path));
    }

    public function testNoTempFileLingersOnSuccess(): void
    {
        $stream = (new YamlStringLoader())->load("foo: 1\n");
        $path = $this->freshTempPath();
        (new YamlFileDumper())->dump($stream, $path);

        $dir = dirname($path);
        $base = basename($path);
        $temps = glob("$dir/$base.tmp.*") ?: [];
        $this->assertSame([], $temps);
    }

    public function testThrowsOnUnwritableDir(): void
    {
        $stream = (new YamlStringLoader())->load("foo: 1\n");
        $this->expectException(IoException::class);
        (new YamlFileDumper())->dump($stream, '/nonexistent-dir/file.yml');
    }

    public function testRoundTripFileLoadDump(): void
    {
        $original = "foo: 1\nbar: 2\n# comment\nbaz: 3\n";
        $path = $this->freshTempPath();
        file_put_contents($path, $original);

        $stream = (new \Horde\Yaml\Document\YamlFileLoader())->load($path);
        $path2 = $this->freshTempPath();
        (new YamlFileDumper())->dump($stream, $path2);

        $this->assertSame($original, file_get_contents($path2));
    }
}
