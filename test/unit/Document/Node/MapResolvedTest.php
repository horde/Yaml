<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Node;

use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies J.02 MapNode::resolved() view: merge expansion with
 * YAML 1.2 precedence.
 */
#[CoversNothing]
final class MapResolvedTest extends TestCase
{
    private function entryValue(string $yaml, string $key): MapNode
    {
        $stream = (new YamlStringLoader())->load($yaml);
        $value = $stream->getDocuments()[0]->root()->entry($key)?->getValue();
        if (!$value instanceof MapNode) {
            throw new RuntimeException('Expected MapNode value');
        }
        return $value;
    }

    public function testSimpleMergeExpands(): void
    {
        $source = "defaults: &d\n  timeout: 30\nprod:\n  <<: *d\n  host: prod\n";
        $resolved = $this->entryValue($source, 'prod')->resolved();
        $this->assertSame(['timeout' => 30, 'host' => 'prod'], $resolved);
    }

    public function testExplicitKeyOverridesMerged(): void
    {
        $source = "defaults: &d\n  timeout: 30\nfast:\n  <<: *d\n  timeout: 5\n";
        $resolved = $this->entryValue($source, 'fast')->resolved();
        $this->assertSame(['timeout' => 5], $resolved);
    }

    public function testMultiMergeEarlierWins(): void
    {
        $source = "a: &a\n  x: 1\nb: &b\n  x: 2\n  y: 3\nresult:\n  <<: [*a, *b]\n";
        $resolved = $this->entryValue($source, 'result')->resolved();
        $this->assertSame(['x' => 1, 'y' => 3], $resolved);
    }

    public function testMergeAndExplicitTogether(): void
    {
        $source = "a: &a\n  x: 1\n  y: 2\nresult:\n  <<: *a\n  x: 99\n  z: 3\n";
        $resolved = $this->entryValue($source, 'result')->resolved();
        $this->assertSame(['x' => 99, 'y' => 2, 'z' => 3], $resolved);
    }

    public function testMergeKeyDoesNotAppearInResolvedView(): void
    {
        $source = "a: &a\n  x: 1\nresult:\n  <<: *a\n";
        $resolved = $this->entryValue($source, 'result')->resolved();
        $this->assertArrayNotHasKey('<<', $resolved);
    }

    public function testNoMergeJustTypedView(): void
    {
        $source = "foo:\n  a: 1\n  b: hello\n  c: true\n";
        $resolved = $this->entryValue($source, 'foo')->resolved();
        $this->assertSame(['a' => 1, 'b' => 'hello', 'c' => true], $resolved);
    }

    public function testNestedMapResolvedRecursively(): void
    {
        $source = "outer:\n  inner:\n    a: 1\n";
        $resolved = $this->entryValue($source, 'outer')->resolved();
        $this->assertSame(['inner' => ['a' => 1]], $resolved);
    }

    public function testSyntacticEntriesUnaffectedByResolved(): void
    {
        // resolved() must not mutate the AST; the merge entry is
        // still present in entries().
        $source = "a: &a\n  x: 1\nresult:\n  <<: *a\n  z: 3\n";
        $stream = (new YamlStringLoader())->load($source);
        $result = $stream->getDocuments()[0]->root()->entry('result')->getValue();
        $resolved = $result->resolved();
        $this->assertSame(['x' => 1, 'z' => 3], $resolved);
        // Syntactic side: <<: still exists.
        $this->assertNotNull($result->mergeEntry());
        $this->assertCount(2, $result->entries()); // <<: and z:
    }

    public function testSelfMergeCycleThrows(): void
    {
        $source = "a: &a\n  <<: *a\n  x: 1\n";
        $stream = (new YamlStringLoader())->load($source);
        $a = $stream->getDocuments()[0]->root()->entry('a')->getValue();
        $this->assertInstanceOf(MapNode::class, $a);
        $this->expectException(\Horde\Yaml\Document\StructuralException::class);
        $this->expectExceptionMessage('Cyclic merge key reference');
        $a->resolved();
    }

    public function testMutualMergeCycleThrows(): void
    {
        $source = "a: &a\n  <<: *b\n  x: 1\nb: &b\n  <<: *a\n  y: 2\n";
        $stream = (new YamlStringLoader())->load($source);
        $a = $stream->getDocuments()[0]->root()->entry('a')->getValue();
        $this->assertInstanceOf(MapNode::class, $a);
        $this->expectException(\Horde\Yaml\Document\StructuralException::class);
        $a->resolved();
    }

    public function testMergeOfNonMapIsNoOp(): void
    {
        // Merging a sequence value is silently ignored (not a merge
        // source); only explicit keys appear in the resolved view.
        $source = "a: &a [1, 2, 3]\nb:\n  <<: *a\n  x: 1\n";
        $stream = (new YamlStringLoader())->load($source);
        $b = $stream->getDocuments()[0]->root()->entry('b')->getValue();
        $this->assertSame(['x' => 1], $b->resolved());
    }
}
