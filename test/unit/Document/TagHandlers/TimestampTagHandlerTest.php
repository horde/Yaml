<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\TagHandlers;

use DateTimeImmutable;
use Horde\Yaml\Document\TagHandlerException;
use Horde\Yaml\Document\TagHandlers\TimestampTagHandler;
use Horde\Yaml\Document\TagRegistry;
use Horde\Yaml\Document\YamlStringDumper;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class TimestampTagHandlerTest extends TestCase
{
    private function loader(): YamlStringLoader
    {
        $reg = new TagRegistry();
        $reg->register(new TimestampTagHandler());
        return new YamlStringLoader(tagRegistry: $reg);
    }

    public function testExplicitTagOnDate(): void
    {
        $stream = $this->loader()->load("d: !!timestamp 2026-06-16\n");
        $value = $stream->getDocument(0)->root()->entry('d')->getValue()->getResolvedValue();
        $this->assertInstanceOf(DateTimeImmutable::class, $value);
        $this->assertSame('2026-06-16T00:00:00+00:00', $value->format('c'));
    }

    public function testExplicitTagOnDatetimeWithZone(): void
    {
        $stream = $this->loader()->load("d: !!timestamp 2026-06-16T14:30:00Z\n");
        $value = $stream->getDocument(0)->root()->entry('d')->getValue()->getResolvedValue();
        $this->assertInstanceOf(DateTimeImmutable::class, $value);
        $this->assertSame('2026-06-16T14:30:00+00:00', $value->format('c'));
    }

    public function testExplicitTagOnDatetimeWithOffset(): void
    {
        $stream = $this->loader()->load("d: !!timestamp 2026-06-16T14:30:00+02:00\n");
        $value = $stream->getDocument(0)->root()->entry('d')->getValue()->getResolvedValue();
        $this->assertInstanceOf(DateTimeImmutable::class, $value);
        $this->assertSame('2026-06-16T14:30:00+02:00', $value->format('c'));
    }

    public function testExplicitTagOnNaiveDatetimeIsUtc(): void
    {
        $stream = $this->loader()->load("d: !!timestamp 2026-06-16T14:30:00\n");
        $value = $stream->getDocument(0)->root()->entry('d')->getValue()->getResolvedValue();
        $this->assertSame('2026-06-16T14:30:00+00:00', $value->format('c'));
    }

    public function testExplicitTagOnInvalidThrows(): void
    {
        $this->expectException(TagHandlerException::class);
        $this->loader()->load("d: !!timestamp not-a-date\n");
    }

    public function testImplicitRecognitionOff(): void
    {
        $stream = (new YamlStringLoader())->load("d: 2026-06-16\n");
        $node = $stream->getDocument(0)->root()->entry('d')->getValue();
        $this->assertFalse($node->hasResolvedValue());
        $this->assertSame('2026-06-16', $node->getValue());
    }

    public function testImplicitRecognitionOn(): void
    {
        $loader = new YamlStringLoader(recognizeTimestamps: true);
        $stream = $loader->load("d: 2026-06-16T14:30:00Z\n");
        $value = $stream->getDocument(0)->root()->entry('d')->getValue()->getResolvedValue();
        $this->assertInstanceOf(DateTimeImmutable::class, $value);
    }

    public function testImplicitRecognitionDoesNotEatNonTimestamps(): void
    {
        $loader = new YamlStringLoader(recognizeTimestamps: true);
        $stream = $loader->load("a: hello\nb: 42\nc: 2026-06-16\n");
        $r = $stream->getDocument(0)->root()->resolved();
        $this->assertSame('hello', $r['a']);
        $this->assertSame(42, $r['b']);
        $this->assertInstanceOf(DateTimeImmutable::class, $r['c']);
    }

    public function testRoundTripPreservesSourceForm(): void
    {
        $src = "created: 2026-06-16T14:30:00Z\nexpires: 2026-12-31\n";
        $stream = (new YamlStringLoader(recognizeTimestamps: true))->load($src);
        $out = (new YamlStringDumper())->dump($stream);
        $this->assertSame($src, $out);
    }

    public function testToYamlEmitsCanonicalForm(): void
    {
        $h = new TimestampTagHandler();
        $node = $h->toYaml(new DateTimeImmutable('2026-06-16T14:30:00+00:00'));
        $this->assertSame('!!timestamp', $node->getTag());
        $this->assertSame('2026-06-16T14:30:00+00:00', $node->getValue());
    }

    public function testToYamlRejectsNonDateTime(): void
    {
        $this->expectException(TagHandlerException::class);
        (new TimestampTagHandler())->toYaml('not a date');
    }

    public function testIsTimestampLikeMatchesIso8601Forms(): void
    {
        $this->assertTrue(TimestampTagHandler::isTimestampLike('2026-06-16'));
        $this->assertTrue(TimestampTagHandler::isTimestampLike('2026-06-16T14:30:00'));
        $this->assertTrue(TimestampTagHandler::isTimestampLike('2026-06-16T14:30:00Z'));
        $this->assertTrue(TimestampTagHandler::isTimestampLike('2026-06-16T14:30:00.123Z'));
        $this->assertTrue(TimestampTagHandler::isTimestampLike('2026-06-16t14:30:00+02:00'));
        $this->assertTrue(TimestampTagHandler::isTimestampLike('2026-06-16 14:30:00'));
        $this->assertFalse(TimestampTagHandler::isTimestampLike('not a date'));
        $this->assertFalse(TimestampTagHandler::isTimestampLike('2026'));
        $this->assertFalse(TimestampTagHandler::isTimestampLike('14:30:00'));
    }
}
