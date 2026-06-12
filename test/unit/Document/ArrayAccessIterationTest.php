<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\InvalidAccessException;
use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\SequenceNode;
use Horde\Yaml\Document\UnsupportedOperationException;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ArrayAccessIterationTest extends TestCase
{
    public function testMapArrayAccessReadReturnsNode(): void
    {
        $doc = (new YamlStringLoader())->load("foo: 1\n")->getDocument();
        $value = $doc['foo'];
        $this->assertInstanceOf(ScalarNode::class, $value);
        $this->assertSame(1, $value->getValue());
    }

    public function testMapArrayAccessChainedDescent(): void
    {
        $src = "servers:\n  mail:\n    port: 25\n";
        $doc = (new YamlStringLoader())->load($src)->getDocument();
        $port = $doc['servers']['mail']['port'];
        $this->assertSame(25, $port->getValue());
    }

    public function testMapArrayAccessFollowsAlias(): void
    {
        $src = "shared: &x hello\nref: *x\n";
        $doc = (new YamlStringLoader())->load($src)->getDocument();
        $value = $doc['ref'];
        $this->assertInstanceOf(ScalarNode::class, $value);
        $this->assertSame('hello', $value->getValue());
    }

    public function testMapArrayAccessFollowsMerge(): void
    {
        $src = "defaults: &d\n  timeout: 30\nprod:\n  <<: *d\n  host: x\n";
        $doc = (new YamlStringLoader())->load($src)->getDocument();
        $prod = $doc['prod'];
        $this->assertInstanceOf(MapNode::class, $prod);
        $timeout = $prod['timeout'];
        $this->assertInstanceOf(ScalarNode::class, $timeout);
        $this->assertSame(30, $timeout->getValue());
    }

    public function testMapArrayAccessReturnsNullForMissingKey(): void
    {
        $doc = (new YamlStringLoader())->load("foo: 1\n")->getDocument();
        $this->assertNull($doc['missing']);
    }

    public function testMapArrayAccessIssetForMissingKey(): void
    {
        $doc = (new YamlStringLoader())->load("foo: 1\n")->getDocument();
        $this->assertFalse(isset($doc['missing']));
        $this->assertTrue(isset($doc['foo']));
    }

    public function testMapArrayAccessWriteThrows(): void
    {
        $doc = (new YamlStringLoader())->load("foo: 1\n")->getDocument();
        $this->expectException(UnsupportedOperationException::class);
        $doc['foo'] = 99;
    }

    public function testMapArrayAccessUnsetThrows(): void
    {
        $doc = (new YamlStringLoader())->load("foo: 1\n")->getDocument();
        $this->expectException(UnsupportedOperationException::class);
        unset($doc['foo']);
    }

    public function testSequenceArrayAccessRead(): void
    {
        $doc = (new YamlStringLoader())->load("items:\n  - a\n  - b\n")->getDocument();
        $first = $doc['items'][0];
        $this->assertInstanceOf(ScalarNode::class, $first);
        $this->assertSame('a', $first->getValue());
    }

    public function testCountOnMap(): void
    {
        $doc = (new YamlStringLoader())->load("a: 1\nb: 2\nc: 3\n")->getDocument();
        $this->assertSame(3, count($doc));
    }

    public function testCountOnSequence(): void
    {
        $doc = (new YamlStringLoader())->load("items:\n  - a\n  - b\n")->getDocument();
        $this->assertSame(2, count($doc['items']));
    }

    public function testIterateMap(): void
    {
        $doc = (new YamlStringLoader())->load("a: 1\nb: 2\n")->getDocument();
        $keys = [];
        $values = [];
        foreach ($doc as $k => $v) {
            $keys[] = $k;
            $values[] = $v->getValue();
        }
        $this->assertSame(['a', 'b'], $keys);
        $this->assertSame([1, 2], $values);
    }

    public function testIterateSequence(): void
    {
        $doc = (new YamlStringLoader())->load("items:\n  - a\n  - b\n")->getDocument();
        $items = [];
        foreach ($doc['items'] as $i => $v) {
            $items[] = [$i, $v->getValue()];
        }
        $this->assertSame([[0, 'a'], [1, 'b']], $items);
    }

    public function testScalarRootedDocumentSubscriptThrows(): void
    {
        $doc = (new YamlStringLoader())->load("hello\n")->getDocument();
        $this->expectException(InvalidAccessException::class);
        $doc['anything'];
    }

    public function testStringableScalarInStringContext(): void
    {
        $doc = (new YamlStringLoader())->load("port: 25\n")->getDocument();
        $port = $doc['port'];
        $this->assertSame('25', "$port");
    }
}
