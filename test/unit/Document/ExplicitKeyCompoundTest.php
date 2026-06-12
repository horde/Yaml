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
 * Explicit-key entries (`?` indicator) with compound keys per
 * YAML 1.2 §8.1.3. Covers yaml-test-suite cases 5WE3, 6PBE, M5DY,
 * FH7J, V9D5, M2N8/01.
 *
 * Per AST scope (MapEntry doc), compound keys are coerced to a
 * placeholder ScalarNode at the parser layer; these tests verify
 * only that the loader produces a YamlStream without throwing.
 */
#[CoversNothing]
final class ExplicitKeyCompoundTest extends TestCase
{
    private function strict(): YamlStringLoader
    {
        return new YamlStringLoader(policy: LeniencyPolicy::strictYaml12());
    }

    /** 6PBE shape: block-sequence-as-key, block-sequence-as-value. */
    public function testBlockSequenceAsKeyAndValue(): void
    {
        $stream = $this->strict()->load(
            "---\n?\n- a\n- b\n:\n- c\n- d\n",
        );
        $this->assertCount(1, $stream->getDocuments());
        $this->assertInstanceOf(MapNode::class, $stream->getDocuments()[0]->root());
    }

    /** M5DY shape: inline `? - item` and flow-as-key. */
    public function testInlineBlockSequenceAndFlowAsKey(): void
    {
        $stream = $this->strict()->load(
            "? - Detroit Tigers\n  - Chicago cubs\n:\n  - 2001-07-23\n",
        );
        $this->assertCount(1, $stream->getDocuments());
    }

    /** 5WE3 shape: explicit-key plus compact sequence value. */
    public function testExplicitKeyWithCompactSequenceValue(): void
    {
        $stream = $this->strict()->load(
            "? explicit key\n? |\n  block key\n: - one\n  - two\n",
        );
        $this->assertCount(1, $stream->getDocuments());
    }

    /** FH7J shape: tagged-empty key/value within a sequence-of-maps. */
    public function testTaggedEmptyKeyValue(): void
    {
        $stream = $this->strict()->load(
            "- !!str\n-\n  !!null : a\n  b: !!str\n- !!str : !!null\n",
        );
        $this->assertCount(1, $stream->getDocuments());
    }

    /** M2N8/01 shape: `? []: x`. */
    public function testEmptyFlowSequenceAsExplicitKey(): void
    {
        $stream = $this->strict()->load("? []: x\n");
        $this->assertCount(1, $stream->getDocuments());
    }
}
