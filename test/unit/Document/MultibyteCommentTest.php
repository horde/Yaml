<?php

declare(strict_types=1);

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\Emitter\Emitter;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Comments containing non-ASCII characters must survive a round trip.
 *
 * The scanner advances by whole UTF-8 characters but the comment and directive
 * accumulators appended a single BYTE, so every multi-byte character lost its
 * continuation bytes: `§` (C2 A7) was collected as C2 alone.
 *
 * `currentChar()` already existed for exactly this reason - its docblock says
 * so - and was used for scalar content but not for trivia.
 * @coversNothing
 */
final class MultibyteCommentTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function nonAsciiComments(): array
    {
        return [
            'latin-1 supplement' => ["# see §4.3\nkey: value\n"],
            'accented latin'     => ["# café rollout\nkey: value\n"],
            'arabic'             => ["# مملوك\nkey: value\n"],
            'cjk'                => ["# 設定\nkey: value\n"],
            'emoji (4-byte)'     => ["# ship it 🚀\nkey: value\n"],
            'eol comment'        => ["key: value   # ° degrees\n"],
            'indented comment'   => ["parent:\n  # é\n  child: 1\n"],
        ];
    }

    #[DataProvider('nonAsciiComments')]
    public function testNonAsciiCommentsRoundTripByteIdentically(string $source): void
    {
        $stream = (new YamlStringLoader())->load($source);

        self::assertSame($source, (new Emitter())->emit($stream));
    }

    public function testTheCommentTextItselfIsIntact(): void
    {
        $stream = (new YamlStringLoader())->load("# see §4.3 café\nkey: value\n");

        $texts = [];
        foreach ($stream->getDocument(0)->root()->children() as $child) {
            if (method_exists($child, 'getText')) {
                $texts[] = $child->getText();
            }
        }

        self::assertContains('# see §4.3 café', $texts);
    }
}
