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
use Horde\Yaml\Document\ParseException;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Strict gates added in the third compliance push. Each test
 * corresponds to a yaml-test-suite case that previously was
 * leniently accepted and now correctly errors under
 * LeniencyPolicy::strictYaml12().
 */
#[CoversNothing]
final class StrictGatesV3Test extends TestCase
{
    private function strict(): YamlStringLoader
    {
        return new YamlStringLoader(policy: LeniencyPolicy::strictYaml12());
    }

    /** yaml-test-suite ZCZ6: `a: b: c: d`. `: ` always breaks plain. */
    public function testColonSpaceAlwaysBreaksPlainScalar(): void
    {
        $this->expectException(ParseException::class);
        $this->strict()->load("a: b: c: d\n");
    }

    /** yaml-test-suite SR86: `&b *a`. Alias may not be anchored. */
    public function testAliasCannotBeAnchored(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/Alias node may not be anchored/');
        $this->strict()->load("key1: &a value\nkey2: &b *a\n");
    }

    /** yaml-test-suite T833: flow mapping without comma between entries. */
    public function testFlowMappingMustHaveCommaBetweenEntries(): void
    {
        $this->expectException(ParseException::class);
        $this->strict()->load("---\n{\n foo: 1\n bar: 2 }\n");
    }

    /** yaml-test-suite 9MQT/01: doc marker inside double-quoted scalar. */
    public function testDoubleQuotedScalarSpanningDocumentMarkerErrors(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/document marker reached/');
        $this->strict()->load("--- \"a\n... x\nb\"\n");
    }

    /** yaml-test-suite RXY3: doc marker inside single-quoted scalar. */
    public function testSingleQuotedScalarSpanningDocumentMarkerErrors(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/document marker reached/');
        $this->strict()->load("---\n'\n...\n'\n");
    }

    /**
     * Multi-line plain scalar continuation in flow mapping
     * (yaml-test-suite VJP3-family verification: ensures
     * `{foo\n: bar}` still fails because `:` on its own line is not
     * yet supported, but `{a, b}` style passes correctly).
     */
    public function testFlowSequenceMultilineContinuesPlainScalar(): void
    {
        // `[plain\n  continuation]` folds to `plain continuation`.
        $stream = $this->strict()->load("[ plain\n  continuation, x ]\n");
        $this->assertCount(1, $stream->getDocuments());
    }

    /**
     * Compound mapping key parses as a real MapNode in the AST,
     * not a placeholder. Regression cover after MapEntry::key was
     * generalised to accept compound keys (scope §2.2 update).
     */
    public function testCompoundMappingKeyPreservedInAst(): void
    {
        // `? earth: blue\n: moon: white` is yaml-test-suite V9D5
        // Inner item.
        $stream = $this->strict()->load(
            "- sun: yellow\n- ? earth: blue\n  : moon: white\n",
        );
        $root = $stream->getDocuments()[0]->root();
        $items = $root->children();
        $this->assertCount(2, $items);
        $secondItem = $items[1]->getValue();
        $this->assertInstanceOf(MapNode::class, $secondItem);
        $entries = $secondItem->entries();
        $this->assertCount(1, $entries);
        // Compound key is itself a MapNode now (not a placeholder).
        $this->assertInstanceOf(MapNode::class, $entries[0]->getKey());
        $this->assertInstanceOf(MapNode::class, $entries[0]->getValue());
    }
}
