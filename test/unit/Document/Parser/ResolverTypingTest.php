<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Parser;

use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\ScalarStyle;
use Horde\Yaml\Document\Parser\Resolver;
use Horde\Yaml\Document\YamlDocument;
use Horde\Yaml\Document\YamlStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies B.04 resolver behavior: YAML 1.2 core schema typing for
 * plain scalars, rawSource retention rules, explicit tag handling.
 */
#[CoversClass(Resolver::class)]
final class ResolverTypingTest extends TestCase
{
    private function streamWithScalar(string $rawSource, ScalarStyle $style = ScalarStyle::Plain, ?string $tag = null): YamlStream
    {
        $stream = new YamlStream();
        $doc = new YamlDocument();
        $doc->setParentStream($stream);
        $node = new ScalarNode(value: $rawSource, style: $style, tag: $tag);
        $doc->setRootInternal($node);
        $stream->appendInternalDocument($doc);
        return $stream;
    }

    private function rootScalar(YamlStream $stream): ScalarNode
    {
        $root = $stream->getDocuments()[0]->root();
        if (!$root instanceof ScalarNode) {
            throw new RuntimeException('expected ScalarNode root');
        }
        return $root;
    }

    /**
     * @return iterable<string, array{string, mixed, ?string}>
     */
    public static function plainScalarSamples(): iterable
    {
        // [source, expected typed value, expected rawSource (null if no retention)]
        yield 'null lowercase' => ['null', null, null];
        yield 'null tilde' => ['~', null, '~'];
        yield 'null Capital' => ['Null', null, 'Null'];
        yield 'null UPPER' => ['NULL', null, 'NULL'];
        yield 'empty string is null per YAML' => ['', null, null];
        yield 'true' => ['true', true, null];
        yield 'True' => ['True', true, 'True'];
        yield 'TRUE' => ['TRUE', true, 'TRUE'];
        yield 'false' => ['false', false, null];
        yield 'integer 42' => ['42', 42, null];
        yield 'integer 0' => ['0', 0, null];
        yield 'negative integer' => ['-7', -7, null];
        yield 'positive integer with sign' => ['+7', 7, '+7'];
        yield 'hex integer' => ['0xFF', 255, '0xFF'];
        yield 'octal integer' => ['0o17', 15, '0o17'];
        yield 'plain string' => ['hello', 'hello', null];
        yield 'string with internal space' => ['hello world', 'hello world', null];
        yield 'inf positive' => ['.inf', INF, '.inf'];
        yield 'inf negative' => ['-.inf', -INF, '-.inf'];
    }

    #[DataProvider('plainScalarSamples')]
    public function testPlainScalarResolution(string $source, mixed $expectedValue, ?string $expectedRaw): void
    {
        $stream = $this->streamWithScalar($source);
        (new Resolver())->resolve($stream);
        $node = $this->rootScalar($stream);

        if (is_float($expectedValue) && is_nan($expectedValue)) {
            $this->assertNan($node->getValue());
        } else {
            $this->assertSame($expectedValue, $node->getValue());
        }
        $this->assertSame($expectedRaw, $node->getRawSource());
    }

    public function testNanIsResolved(): void
    {
        $stream = $this->streamWithScalar('.nan');
        (new Resolver())->resolve($stream);
        $node = $this->rootScalar($stream);
        $this->assertNan($node->getValue());
        $this->assertSame('.nan', $node->getRawSource());
    }

    public function testFloatRetainsRawWhenScientific(): void
    {
        $stream = $this->streamWithScalar('1e2');
        (new Resolver())->resolve($stream);
        $node = $this->rootScalar($stream);
        $this->assertSame(100.0, $node->getValue());
        $this->assertSame('1e2', $node->getRawSource());
    }

    public function testFloatNoRawWhenCanonical(): void
    {
        $stream = $this->streamWithScalar('3.14');
        (new Resolver())->resolve($stream);
        $node = $this->rootScalar($stream);
        $this->assertSame(3.14, $node->getValue());
        $this->assertNull($node->getRawSource());
    }

    public function testQuotedScalarStaysString(): void
    {
        $stream = $this->streamWithScalar('42', ScalarStyle::SingleQuoted);
        (new Resolver())->resolve($stream);
        $node = $this->rootScalar($stream);
        $this->assertSame('42', $node->getValue());
        $this->assertNull($node->getRawSource());
    }

    public function testDoubleQuotedScalarStaysString(): void
    {
        $stream = $this->streamWithScalar('true', ScalarStyle::DoubleQuoted);
        (new Resolver())->resolve($stream);
        $node = $this->rootScalar($stream);
        $this->assertSame('true', $node->getValue());
    }

    public function testYaml11BooleansResolveAsString(): void
    {
        $stream = $this->streamWithScalar('yes');
        (new Resolver())->resolve($stream);
        $node = $this->rootScalar($stream);
        // Strict YAML 1.2: 'yes' is a string, not bool.
        $this->assertSame('yes', $node->getValue());
        $this->assertNull($node->getRawSource());
    }

    public function testExplicitStrTagOverridesPlainTyping(): void
    {
        $stream = $this->streamWithScalar('42', ScalarStyle::Plain, '!!str');
        (new Resolver())->resolve($stream);
        $node = $this->rootScalar($stream);
        $this->assertSame('42', $node->getValue());
    }

    public function testExplicitIntTagOverridesQuoted(): void
    {
        $stream = $this->streamWithScalar('42', ScalarStyle::DoubleQuoted, '!!int');
        (new Resolver())->resolve($stream);
        $node = $this->rootScalar($stream);
        $this->assertSame(42, $node->getValue());
    }

    public function testExplicitBoolTag(): void
    {
        $stream = $this->streamWithScalar('true', ScalarStyle::SingleQuoted, '!!bool');
        (new Resolver())->resolve($stream);
        $node = $this->rootScalar($stream);
        $this->assertTrue($node->getValue());
    }

    public function testExplicitNullTag(): void
    {
        // !!null on an empty scalar resolves to null. Non-empty
        // source now throws. See testExplicitNullTagRejectsNonEmpty.
        $stream = $this->streamWithScalar('', ScalarStyle::Plain, '!!null');
        (new Resolver())->resolve($stream);
        $node = $this->rootScalar($stream);
        $this->assertNull($node->getValue());
    }

    public function testExplicitNullTagRejectsNonEmpty(): void
    {
        $stream = $this->streamWithScalar('whatever', ScalarStyle::Plain, '!!null');
        $this->expectException(\Horde\Yaml\Document\ParseException::class);
        (new Resolver())->resolve($stream);
    }

    public function testCustomTagLeavesValueAsString(): void
    {
        $stream = $this->streamWithScalar('encrypted', ScalarStyle::Plain, '!vault');
        (new Resolver())->resolve($stream);
        $node = $this->rootScalar($stream);
        $this->assertSame('encrypted', $node->getValue());
    }

    public function testEmptyStreamDoesNotCrash(): void
    {
        $stream = new YamlStream();
        (new Resolver())->resolve($stream);
        $this->assertSame(0, $stream->documentCount());
    }
}
