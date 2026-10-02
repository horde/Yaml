<?php

declare(strict_types=1);

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\Emitter\Emitter;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A CRLF document comes back as CRLF.
 *
 * The scanner normalises CRLF to LF so the rest of the parser sees one line
 * ending, and the emitter already writes whatever the stream names - but
 * nothing recorded what the source used, so every Windows-authored document was
 * silently rewritten with LF.
 * @coversNothing
 */
final class LineEndingTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function documents(): array
    {
        return [
            'crlf'                  => ["# note\r\na:\r\n  b: 1\r\n"],
            'lf'                    => ["# note\na:\n  b: 1\n"],
            'crlf with indentation' => ["a:\r\n  b:\r\n    c: 1\r\n"],
            'crlf with a comment column' => ["a:\r\n    # deep\r\n  b: 1\r\n"],
            'crlf with a block scalar'   => ["a: |\r\n  line one\r\n  line two\r\n"],
            'crlf with a sequence'  => ["a:\r\n  - one\r\n  - two\r\n"],
        ];
    }

    #[DataProvider('documents')]
    public function testLineEndingsRoundTrip(string $source): void
    {
        self::assertSame($source, (new Emitter())->emit((new YamlStringLoader())->load($source)));
    }

    public function testTheStreamReportsTheEndingItRead(): void
    {
        self::assertSame("\r\n", (new YamlStringLoader())->load("a: 1\r\n")->getLineEnding());
        self::assertSame("\n", (new YamlStringLoader())->load("a: 1\n")->getLineEnding());
    }

    public function testAMixedDocumentIsNotGuessedAt(): void
    {
        // No single ending to preserve. LF leaves it visibly changed rather
        // than subtly, so a caller comparing input to output can still tell.
        self::assertSame("\n", (new YamlStringLoader())->load("a:\r\n  b: 1\n")->getLineEnding());
    }
}
