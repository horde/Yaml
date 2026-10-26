<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Integration;

use Horde\Yaml\Document\LeniencyPolicy;
use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\Node\Node;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\SequenceNode;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Anchors and tags sit on the kind of node yaml-test-suite says they do.
 *
 * ConformanceTest checks only whether a case loads. A loader can accept
 * `- &k a: b` and still attach the anchor to the wrong node, or read it as
 * part of the key's text, and pass. This compares, for every case that loads,
 * the anchors in the AST, each with the kind of node holding it, and the
 * kinds of node carrying a tag, against the case's test.event.
 *
 * Before this test, 10 cases placed an anchor differently and 5 a tag.
 * @coversNothing
 */
final class PropertyPlacementConformanceTest extends TestCase
{
    private const SUITE_PATH = __DIR__ . '/../fixtures/yaml-test-suite';

    /**
     * @return iterable<string, array{string}>
     */
    public static function cases(): iterable
    {
        $inputs = array_merge(
            glob(self::SUITE_PATH . '/[0-9A-Z]*/in.yaml') ?: [],
            glob(self::SUITE_PATH . '/[0-9A-Z]*/[0-9][0-9]/in.yaml') ?: [],
        );
        foreach ($inputs as $in) {
            $dir = dirname($in);
            if (file_exists("$dir/error") || !file_exists("$dir/test.event")) {
                continue;
            }
            yield substr($dir, strlen(self::SUITE_PATH) + 1) => [$dir];
        }
    }

    #[DataProvider('cases')]
    public function testPropertiesSitWhereTheEventStreamPutsThem(string $dir): void
    {
        // hordeCompat() accepts the repeated keys of 2JQS; placement, not
        // strictness, is what is checked here.
        try {
            $stream = (new YamlStringLoader(policy: LeniencyPolicy::hordeCompat()))
                ->load((string) file_get_contents("$dir/in.yaml"));
        } catch (Throwable) {
            // Whether a case loads is ConformanceTest's concern.
            $this->addToAssertionCount(1);
            return;
        }

        $anchors = [];
        $tags = [];
        foreach ($stream->getDocuments() as $document) {
            self::collect($document->root(), $anchors, $tags);
        }

        $events = (string) file_get_contents("$dir/test.event");
        // An anchor name may itself contain "<" (W5VH), so the tag is
        // matched as its own field rather than by searching the line.
        preg_match_all('/^[+=](MAP|SEQ|VAL)(?: (?:\[\]|\{\}))?(?: &(\S+))?(?: (<\S*>))?/m', $events, $lines, PREG_SET_ORDER);
        $expectedAnchors = [];
        $expectedTags = [];
        foreach ($lines as $line) {
            if (($line[2] ?? '') !== '') {
                $expectedAnchors[] = $line[1] . '&' . $line[2];
            }
            if (($line[3] ?? '') !== '') {
                $expectedTags[] = $line[1];
            }
        }

        sort($anchors);
        sort($expectedAnchors);
        sort($tags);
        sort($expectedTags);
        self::assertSame($expectedAnchors, $anchors, 'anchors');
        self::assertSame($expectedTags, $tags, 'tags');
    }

    /**
     * @param list<string> $anchors
     * @param list<string> $tags
     */
    private static function collect(?Node $node, array &$anchors, array &$tags): void
    {
        if (!$node instanceof MapNode && !$node instanceof SequenceNode && !$node instanceof ScalarNode) {
            return;
        }
        $kind = $node instanceof MapNode ? 'MAP' : ($node instanceof SequenceNode ? 'SEQ' : 'VAL');
        if ($node->getAnchor() !== null) {
            $anchors[] = $kind . '&' . $node->getAnchor();
        }
        if ($node->getTag() !== null) {
            $tags[] = $kind;
        }
        if ($node instanceof MapNode) {
            foreach ($node->entries() as $entry) {
                self::collect($entry->getKey(), $anchors, $tags);
                self::collect($entry->getValue(), $anchors, $tags);
            }
        } elseif ($node instanceof SequenceNode) {
            foreach ($node->items() as $item) {
                self::collect($item->getValue(), $anchors, $tags);
            }
        }
    }
}
