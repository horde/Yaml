<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Node;

use Horde\Yaml\Document\Node\AliasNode;
use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Verifies J.01 merge-key recognition: MapNode::mergeEntry() returns
 * the entry whose key is `<<`, null otherwise.
 */
#[CoversNothing]
final class MapMergeEntryTest extends TestCase
{
    public function testMergeEntryRecognized(): void
    {
        $source = "defaults: &d\n  timeout: 30\nprod:\n  <<: *d\n  host: prod\n";
        $stream = (new YamlStringLoader())->load($source);
        $prod = $stream->getDocuments()[0]->root()->entry('prod')->getValue();
        $this->assertInstanceOf(MapNode::class, $prod);
        $merge = $prod->mergeEntry();
        $this->assertNotNull($merge);
        $this->assertSame('<<', $merge->getKeyString());
        $this->assertInstanceOf(AliasNode::class, $merge->getValue());
    }

    public function testNoMergeEntryReturnsNull(): void
    {
        $source = "foo: 1\nbar: 2\n";
        $stream = (new YamlStringLoader())->load($source);
        $root = $stream->getDocuments()[0]->root();
        $this->assertInstanceOf(MapNode::class, $root);
        $this->assertNull($root->mergeEntry());
    }

    public function testMergeEntryRoundTrip(): void
    {
        $source = "defaults: &d\n  timeout: 30\nprod:\n  <<: *d\n  host: prod\n";
        $stream = (new YamlStringLoader())->load($source);
        $out = (new \Horde\Yaml\Document\YamlStringDumper())->dump($stream);
        $this->assertSame($source, $out);
    }
}
