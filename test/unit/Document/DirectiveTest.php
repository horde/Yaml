<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\Node\Directive;
use Horde\Yaml\Document\YamlStringDumper;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Verifies K.04 directive handling: %YAML and %TAG round-trip
 * verbatim.
 */
#[CoversNothing]
final class DirectiveTest extends TestCase
{
    public function testYamlDirectiveOnStream(): void
    {
        $stream = (new YamlStringLoader())->load("%YAML 1.2\n---\nfoo: 1\n");
        $directives = $stream->getDirectives();
        $this->assertCount(1, $directives);
        $this->assertSame('YAML', $directives[0]->getName());
        $this->assertSame('1.2', $directives[0]->getParameters());
    }

    public function testTagDirectiveOnStream(): void
    {
        $stream = (new YamlStringLoader())->load("%TAG !my! tag:example.com,2026:\n---\nfoo: 1\n");
        $directives = $stream->getDirectives();
        $this->assertCount(1, $directives);
        $this->assertSame('TAG', $directives[0]->getName());
        $this->assertSame('!my! tag:example.com,2026:', $directives[0]->getParameters());
    }

    public function testYamlDirectiveRoundTrip(): void
    {
        $src = "%YAML 1.2\n---\nfoo: 1\n";
        $stream = (new YamlStringLoader())->load($src);
        $out = (new YamlStringDumper())->dump($stream);
        $this->assertSame($src, $out);
    }

    public function testTagDirectiveRoundTrip(): void
    {
        $src = "%TAG !my! tag:example.com,2026:\n---\nfoo: !my!setting value\n";
        $stream = (new YamlStringLoader())->load($src);
        $out = (new YamlStringDumper())->dump($stream);
        $this->assertSame($src, $out);
    }

    public function testFromValueParses(): void
    {
        $d = Directive::fromValue('YAML 1.2');
        $this->assertSame('YAML', $d->getName());
        $this->assertSame('1.2', $d->getParameters());
    }

    public function testFromValueWithoutParameters(): void
    {
        $d = Directive::fromValue('YAML');
        $this->assertSame('YAML', $d->getName());
        $this->assertSame('', $d->getParameters());
    }
}
