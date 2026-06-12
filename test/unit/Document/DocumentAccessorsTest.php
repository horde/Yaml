<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\KeyNotFoundException;
use Horde\Yaml\Document\Node\MapEntry;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class DocumentAccessorsTest extends TestCase
{
    public function testGetEntry(): void
    {
        $doc = (new YamlStringLoader())->load("foo: 1\nbar: 2\n")->getDocument();
        $entry = $doc->getEntry('foo');
        $this->assertInstanceOf(MapEntry::class, $entry);
        $this->assertSame(1, $entry->getValue()->getValue());
    }

    public function testGetEntryReturnsNullForMissing(): void
    {
        $doc = (new YamlStringLoader())->load("foo: 1\n")->getDocument();
        $this->assertNull($doc->getEntry('missing'));
    }

    public function testRequireEntryFindsKey(): void
    {
        $doc = (new YamlStringLoader())->load("foo: 1\n")->getDocument();
        $this->assertSame('foo', $doc->requireEntry('foo')->getKeyString());
    }

    public function testRequireEntryThrowsOnMissing(): void
    {
        $doc = (new YamlStringLoader())->load("foo: 1\n")->getDocument();
        $this->expectException(KeyNotFoundException::class);
        $doc->requireEntry('missing');
    }

    public function testGetNodeDeepPath(): void
    {
        $src = "servers:\n  mail:\n    host: smtp\n    port: 25\n";
        $doc = (new YamlStringLoader())->load($src)->getDocument();
        $port = $doc->getNode('servers', 'mail', 'port');
        $this->assertInstanceOf(ScalarNode::class, $port);
        $this->assertSame(25, $port->getValue());
    }

    public function testGetNodeReturnsNullOnMiss(): void
    {
        $doc = (new YamlStringLoader())->load("a: 1\n")->getDocument();
        $this->assertNull($doc->getNode('a', 'b'));
    }

    public function testGetNodeWithSequenceIndex(): void
    {
        $src = "items:\n  - alpha\n  - beta\n";
        $doc = (new YamlStringLoader())->load($src)->getDocument();
        $second = $doc->getNode('items', 1);
        $this->assertInstanceOf(ScalarNode::class, $second);
        $this->assertSame('beta', $second->getValue());
    }

    public function testValueAtDottedPath(): void
    {
        $src = "servers:\n  mail:\n    host: smtp\n";
        $doc = (new YamlStringLoader())->load($src)->getDocument();
        $this->assertSame('smtp', $doc->valueAt('servers.mail.host'));
    }

    public function testValueAtArrayPath(): void
    {
        $src = "a:\n  b: 42\n";
        $doc = (new YamlStringLoader())->load($src)->getDocument();
        $this->assertSame(42, $doc->valueAt(['a', 'b']));
    }

    public function testValueAtReturnsNullOnMiss(): void
    {
        $doc = (new YamlStringLoader())->load("foo: 1\n")->getDocument();
        $this->assertNull($doc->valueAt('foo.bar'));
    }

    public function testValueAtTypedScalars(): void
    {
        $src = "port: 25\nhost: smtp\nactive: true\n";
        $doc = (new YamlStringLoader())->load($src)->getDocument();
        $this->assertSame(25, $doc->valueAt('port'));
        $this->assertSame('smtp', $doc->valueAt('host'));
        $this->assertTrue($doc->valueAt('active'));
    }
}
