<?php

declare(strict_types=1);

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\Emitter\Emitter;
use Horde\Yaml\Document\Node\CommentNode;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A comment comes back at the column its author put it.
 *
 * The emitter already honoured `CommentNode::getIndent()`, and the scanner
 * already captured each comment's column - but the parser passed a hardcoded 0,
 * so every standalone comment was re-indented to its surrounding container.
 *
 * The indent is nullable rather than zero-defaulted because column 1 is a real
 * position: a flush-left comment inside an indented block is a deliberate style
 * that "0 means unset" could not express.
 */
final class CommentIndentTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function comments(): array
    {
        return [
            'matching its sibling'   => ["parent:\n  # aligned\n  key: 1\n"],
            'deeper than its sibling'=> ["parent:\n  child:\n      # over-indented\n    key: 1\n"],
            'shallower'              => ["parent:\n    # four spaces\n  key: 1\n"],
            'flush left in a block'  => ["parent:\n# flush left\n  key: 1\n"],
            'before a nested block'  => ["a:\n  b:\n    # note\n    c: 1\n"],
        ];
    }

    #[DataProvider('comments')]
    public function testCommentsKeepTheirColumn(string $source): void
    {
        self::assertSame($source, (new Emitter())->emit((new YamlStringLoader())->load($source)));
    }

    public function testAProgrammaticCommentStillFollowsItsContainer(): void
    {
        // No column was ever recorded for it, so it indents with the block it
        // is added to rather than landing at column 1.
        $stream = (new YamlStringLoader())->load("parent:\n  key: 1\n");
        $parent = $stream->getDocument(0)->root()->entry('parent')->getValue();
        $parent->appendChildInternal(new CommentNode('# added later'));

        self::assertStringContainsString("  # added later", (new Emitter())->emit($stream));
    }
}
