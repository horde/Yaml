<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Emitter;

use Horde\Yaml\Document\Emitter\Emitter;
use Horde\Yaml\Document\EmitException;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\ScalarStyle;
use Horde\Yaml\Document\YamlDocument;
use Horde\Yaml\Document\YamlStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies B.05 emitter behavior: scalar-rooted documents emit back
 * to bytes correctly. rawSource preferred when set; typed value
 * derives the output otherwise.
 */
#[CoversClass(Emitter::class)]
final class EmitterScalarTest extends TestCase
{
    private function streamWithScalar(
        string|int|float|bool|null $value,
        ScalarStyle $style = ScalarStyle::Plain,
        ?string $rawSource = null,
    ): YamlStream {
        $stream = new YamlStream();
        $doc = new YamlDocument();
        $doc->setParentStream($stream);
        $node = new ScalarNode(value: $value, style: $style, rawSource: $rawSource);
        $doc->setRootInternal($node);
        $stream->appendInternalDocument($doc);
        return $stream;
    }

    /**
     * @return iterable<string, array{string|int|float|bool|null, string}>
     */
    public static function typedValues(): iterable
    {
        yield 'null' => [null, "null\n"];
        yield 'true' => [true, "true\n"];
        yield 'false' => [false, "false\n"];
        yield 'int 42' => [42, "42\n"];
        yield 'int 0' => [0, "0\n"];
        yield 'int negative' => [-5, "-5\n"];
        yield 'float 3.14' => [3.14, "3.14\n"];
        yield 'string hello' => ['hello', "hello\n"];
    }

    #[DataProvider('typedValues')]
    public function testEmitsTypedValueWithoutRawSource(string|int|float|bool|null $value, string $expected): void
    {
        $stream = $this->streamWithScalar($value);
        $output = (new Emitter())->emit($stream);
        $this->assertSame($expected, $output);
    }

    public function testEmitsRawSourceWhenSet(): void
    {
        $stream = $this->streamWithScalar(255, ScalarStyle::Plain, '0xFF');
        $output = (new Emitter())->emit($stream);
        $this->assertSame("0xFF\n", $output);
    }

    public function testEmitsScientificFloatViaRawSource(): void
    {
        $stream = $this->streamWithScalar(100.0, ScalarStyle::Plain, '1e2');
        $output = (new Emitter())->emit($stream);
        $this->assertSame("1e2\n", $output);
    }

    public function testEmitsPositiveInfinity(): void
    {
        $stream = $this->streamWithScalar(INF);
        $output = (new Emitter())->emit($stream);
        $this->assertSame(".inf\n", $output);
    }

    public function testEmitsNegativeInfinity(): void
    {
        $stream = $this->streamWithScalar(-INF);
        $output = (new Emitter())->emit($stream);
        $this->assertSame("-.inf\n", $output);
    }

    public function testEmitsNan(): void
    {
        $stream = $this->streamWithScalar(NAN);
        $output = (new Emitter())->emit($stream);
        $this->assertSame(".nan\n", $output);
    }

    public function testEmptyStreamProducesEmptyString(): void
    {
        $stream = new YamlStream();
        $output = (new Emitter())->emit($stream);
        $this->assertSame('', $output);
    }

    public function testRespectsStreamLineEnding(): void
    {
        $stream = $this->streamWithScalar('hello');
        $stream->setLineEnding("\r\n");
        $output = (new Emitter())->emit($stream);
        $this->assertSame("hello\r\n", $output);
    }

    public function testEmitsDocumentStartMarker(): void
    {
        $stream = $this->streamWithScalar('hello');
        $stream->getDocuments()[0]->setStartMarker(true);
        $output = (new Emitter())->emit($stream);
        $this->assertSame("---\nhello\n", $output);
    }

    public function testEmitsDocumentEndMarker(): void
    {
        $stream = $this->streamWithScalar('hello');
        $stream->getDocuments()[0]->setEndMarker(true);
        $output = (new Emitter())->emit($stream);
        $this->assertSame("hello\n...\n", $output);
    }

    public function testSingleQuotedScalarEmitsWithQuotes(): void
    {
        $stream = $this->streamWithScalar('hello', ScalarStyle::SingleQuoted);
        $output = (new Emitter())->emit($stream);
        $this->assertSame("'hello'\n", $output);
    }

    public function testDoubleQuotedScalarEmitsWithQuotes(): void
    {
        $stream = $this->streamWithScalar('hello', ScalarStyle::DoubleQuoted);
        $output = (new Emitter())->emit($stream);
        $this->assertSame("\"hello\"\n", $output);
    }

    public function testBlockScalarSynthesizedEmitsLiteralForm(): void
    {
        $stream = $this->streamWithScalar("line1\nline2\n", ScalarStyle::LiteralBlock);
        $output = (new Emitter())->emit($stream);
        // Top-level block scalar: indicator on its own line, content
        // indented 2.
        $this->assertStringContainsString('|', $output);
        $this->assertStringContainsString('line1', $output);
        $this->assertStringContainsString('line2', $output);
    }
}
