<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Node;

use Horde\Yaml\Document\Node\ChompMode;
use Horde\Yaml\Document\Node\FlowFormat;
use Horde\Yaml\Document\Node\MapStyle;
use Horde\Yaml\Document\Node\ScalarStyle;
use Horde\Yaml\Document\Node\SequenceStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Error;

/**
 * Smoke tests for the style enums and the FlowFormat record. Each
 * enum has the cases listed in Stage 3 §3; FlowFormat is a final
 * readonly record.
 */
#[CoversClass(ScalarStyle::class)]
#[CoversClass(MapStyle::class)]
#[CoversClass(SequenceStyle::class)]
#[CoversClass(ChompMode::class)]
#[CoversClass(FlowFormat::class)]
final class StyleEnumsTest extends TestCase
{
    public function testScalarStyleHasFiveCases(): void
    {
        $this->assertCount(5, ScalarStyle::cases());
        $this->assertSame(
            ['Plain', 'SingleQuoted', 'DoubleQuoted', 'LiteralBlock', 'FoldedBlock'],
            array_map(static fn(ScalarStyle $s): string => $s->name, ScalarStyle::cases()),
        );
    }

    public function testMapStyleHasBlockAndFlow(): void
    {
        $names = array_map(static fn(MapStyle $s): string => $s->name, MapStyle::cases());
        $this->assertSame(['Block', 'Flow'], $names);
    }

    public function testSequenceStyleHasBlockAndFlow(): void
    {
        $names = array_map(static fn(SequenceStyle $s): string => $s->name, SequenceStyle::cases());
        $this->assertSame(['Block', 'Flow'], $names);
    }

    public function testChompModeHasClipStripKeep(): void
    {
        $names = array_map(static fn(ChompMode $c): string => $c->name, ChompMode::cases());
        $this->assertSame(['Clip', 'Strip', 'Keep'], $names);
    }

    public function testFlowFormatStoresSingleLineFlag(): void
    {
        $fmt = new FlowFormat(singleLine: true);
        $this->assertTrue($fmt->singleLine);
        $this->assertSame('', $fmt->rawText);
    }

    public function testFlowFormatStoresRawText(): void
    {
        $fmt = new FlowFormat(singleLine: false, rawText: "  a,\n  b,\n  c\n");
        $this->assertFalse($fmt->singleLine);
        $this->assertSame("  a,\n  b,\n  c\n", $fmt->rawText);
    }

    public function testFlowFormatIsReadonly(): void
    {
        $fmt = new FlowFormat(singleLine: true);
        $this->expectException(Error::class);
        // @phpstan-ignore-next-line: deliberately attempting to mutate
        $fmt->singleLine = false;
    }
}
