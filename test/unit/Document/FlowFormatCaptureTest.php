<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Verifies I.03 multi-line flow with FlowFormat capture.
 */
#[CoversNothing]
final class FlowFormatCaptureTest extends TestCase
{
    public function testSingleLineFlowHasNoFormat(): void
    {
        $stream = (new YamlStringLoader())->load("hosts: [a, b]\n");
        $hosts = $stream->getDocuments()[0]->root()->entry('hosts')->getValue();
        $this->assertNull($hosts->getFlowFormat());
    }

    public function testMultiLineFlowSequenceCapturesFormat(): void
    {
        $source = "hosts: [\n  a,\n  b\n]\n";
        $stream = (new YamlStringLoader())->load($source);
        $hosts = $stream->getDocuments()[0]->root()->entry('hosts')->getValue();
        $ff = $hosts->getFlowFormat();
        $this->assertNotNull($ff);
        $this->assertFalse($ff->singleLine);
        $this->assertStringContainsString('a,', $ff->rawText);
    }

    public function testMultiLineFlowMappingCapturesFormat(): void
    {
        $source = "config: {\n  a: 1,\n  b: 2\n}\n";
        $stream = (new YamlStringLoader())->load($source);
        $config = $stream->getDocuments()[0]->root()->entry('config')->getValue();
        $ff = $config->getFlowFormat();
        $this->assertNotNull($ff);
        $this->assertFalse($ff->singleLine);
    }

    public function testMultiLineFlowParsesItems(): void
    {
        $source = "hosts: [\n  alpha,\n  beta,\n  gamma\n]\n";
        $stream = (new YamlStringLoader())->load($source);
        $hosts = $stream->getDocuments()[0]->root()->entry('hosts')->getValue();
        $items = $hosts->items();
        $this->assertCount(3, $items);
        $this->assertSame('alpha', $items[0]->getValue()->getValue());
        $this->assertSame('gamma', $items[2]->getValue()->getValue());
    }
}
