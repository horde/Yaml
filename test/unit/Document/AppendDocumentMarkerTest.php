<?php

declare(strict_types=1);

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\Emitter\Emitter;
use Horde\Yaml\Document\YamlStream;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\TestCase;

/**
 * appendDocument() adds a `---` only where one is needed.
 *
 * It set the start marker on every document it appended, so a stream built
 * from one document was always emitted with a `---` its source did not have,
 * whatever the caller had set. insertDocumentAt() already set it only for a
 * document after the first.
 * @coversNothing
 */
final class AppendDocumentMarkerTest extends TestCase
{
    public function testTheFirstDocumentKeepsItsOwnMarkerSetting(): void
    {
        $stream = new YamlStream();
        $stream->setTrailingNewline(true);
        $stream->appendDocument((new YamlStringLoader())->load("a: 1\n")->getDocument());

        self::assertSame("a: 1\n", (new Emitter())->emit($stream));
    }

    public function testAFirstDocumentWithAMarkerKeepsIt(): void
    {
        $stream = new YamlStream();
        $stream->setTrailingNewline(true);
        $stream->appendDocument((new YamlStringLoader())->load("---\na: 1\n")->getDocument());

        self::assertSame("---\na: 1\n", (new Emitter())->emit($stream));
    }

    /** The negative control: a second document is still separated. */
    public function testASecondDocumentIsSeparated(): void
    {
        $stream = new YamlStream();
        $stream->setTrailingNewline(true);
        $stream->appendDocument((new YamlStringLoader())->load("a: 1\n")->getDocument());
        $second = $stream->appendDocument((new YamlStringLoader())->load("b: 2\n")->getDocument());

        self::assertTrue($second->getStartMarker());
        self::assertSame("a: 1\n---\nb: 2\n", (new Emitter())->emit($stream));
    }

    public function testADetachedCloneIsEmittedAsItsSourceWas(): void
    {
        $document = (new YamlStringLoader())->load("a: 1\n")->getDocument();

        self::assertSame("a: 1\n", (new Emitter())->emit($document->cloneDetached()->parent()));
    }
}
