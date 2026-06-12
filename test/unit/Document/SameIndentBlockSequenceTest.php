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
use Horde\Yaml\Document\Node\SequenceNode;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Per YAML 1.2 §8.1.1 a block sequence may indent to the same column
 * as its parent mapping key when it is the value of that mapping
 * entry. Locks in the scanner fix that recognises the construct
 * without synthesising an empty scalar value.
 *
 * Covers yaml-test-suite cases RLU9, 57H4, 7ZZ5, AZ63, S3PD, S9E8.
 */
#[CoversNothing]
final class SameIndentBlockSequenceTest extends TestCase
{
    public function testSameIndentSequenceIsTheMappingValue(): void
    {
        $yaml = "foo:\n- 42\nbar:\n  - 44\n";
        $stream = (new YamlStringLoader(policy: LeniencyPolicy::strictYaml12()))
            ->load($yaml);
        $docs = $stream->getDocuments();
        $this->assertCount(1, $docs);
        $root = $docs[0]->root();
        $this->assertInstanceOf(MapNode::class, $root);
        $entries = $root->entries();
        $this->assertCount(2, $entries);
        // foo: [42]
        $fooValue = $entries[0]->getValue();
        $this->assertInstanceOf(SequenceNode::class, $fooValue);
        $fooItems = $fooValue->children();
        $this->assertCount(1, $fooItems);
        $this->assertEquals(42, $fooItems[0]->getValue()->getValue());
        // bar: [44]
        $barValue = $entries[1]->getValue();
        $this->assertInstanceOf(SequenceNode::class, $barValue);
        $barItems = $barValue->children();
        $this->assertCount(1, $barItems);
        $this->assertEquals(44, $barItems[0]->getValue()->getValue());
    }

    public function testTopLevelSequenceCannotSwitchToMapping(): void
    {
        // Per yaml-test-suite BD7L this is invalid: the top-level
        // sequence cannot become a mapping at the same indent without
        // a `---` document marker.
        $yaml = "- item1\n- item2\ninvalid: x\n";
        $this->expectException(\Horde\Yaml\Document\ParseException::class);
        (new YamlStringLoader(policy: LeniencyPolicy::strictYaml12()))
            ->load($yaml);
    }
}
