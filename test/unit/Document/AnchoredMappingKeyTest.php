<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\LeniencyPolicy;
use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Properties (anchors and tags) attached to block-mapping keys, both
 * inline (`&anchor key: value`) and stacked across the mapping value
 * boundary (`top: &node\n  &keyAnchor key: value`). Covers
 * yaml-test-suite cases HMQ5, 7BMT, U3XV, ZWK4.
 */
#[CoversNothing]
final class AnchoredMappingKeyTest extends TestCase
{
    private function strict(): YamlStringLoader
    {
        return new YamlStringLoader(policy: LeniencyPolicy::strictYaml12());
    }

    public function testAnchorOnFirstKeyOfNestedMapping(): void
    {
        // 7BMT shape: `top: &node\n  &k1 key: value`
        $stream = $this->strict()->load(
            "top1: &node1\n  &k1 key1: one\n",
        );
        $docs = $stream->getDocuments();
        $this->assertCount(1, $docs);
        $root = $docs[0]->root();
        $this->assertInstanceOf(MapNode::class, $root);
        $entries = $root->entries();
        $this->assertCount(1, $entries);
        $value = $entries[0]->getValue();
        $this->assertInstanceOf(MapNode::class, $value);
        $this->assertSame('node1', $value->getAnchor());
        $innerEntries = $value->entries();
        $this->assertCount(1, $innerEntries);
        $this->assertSame('k1', $innerEntries[0]->getKey()->getAnchor());
    }

    public function testAnchorAtLineStartContinuesOuterMapping(): void
    {
        // ZWK4 shape: anchor at column 1 attaches to the next key,
        // not a fresh nested mapping.
        $stream = $this->strict()->load(
            "---\na: 1\n? b\n&anchor c: 3\n",
        );
        $root = $stream->getDocuments()[0]->root();
        $this->assertInstanceOf(MapNode::class, $root);
        $entries = $root->entries();
        $this->assertCount(3, $entries);
        $this->assertSame('a', $entries[0]->getKeyString());
        $this->assertSame('b', $entries[1]->getKeyString());
        $this->assertSame('c', $entries[2]->getKeyString());
        $this->assertSame('anchor', $entries[2]->getKey()->getAnchor());
    }

    public function testTagAndAnchorStackedOnQuotedKey(): void
    {
        // HMQ5 shape: `!!str &a1 "foo": ...`. Verifies the loader
        // accepts the document without raising. The exact assignment
        // of properties to MAP vs KEY is a separate emitter concern.
        $stream = $this->strict()->load(
            "!!str &a1 \"foo\":\n  !!str bar\n",
        );
        $root = $stream->getDocuments()[0]->root();
        $this->assertInstanceOf(MapNode::class, $root);
        $entries = $root->entries();
        $this->assertCount(1, $entries);
        $this->assertSame('foo', $entries[0]->getKeyString());
    }

    public function testAnchorOnSeparateLineBeforeNestedMappingFirstKey(): void
    {
        // U3XV shape: `top:\n  &node\n  &key key: val`
        $stream = $this->strict()->load(
            "top:\n  &node4\n  &k4 key4: four\n",
        );
        $root = $stream->getDocuments()[0]->root();
        $this->assertInstanceOf(MapNode::class, $root);
        $entries = $root->entries();
        $value = $entries[0]->getValue();
        $this->assertInstanceOf(MapNode::class, $value);
        $this->assertSame('node4', $value->getAnchor());
        $innerEntries = $value->entries();
        $this->assertSame('k4', $innerEntries[0]->getKey()->getAnchor());
    }
}
