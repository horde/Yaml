<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\YamlStringDumper;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The first end-to-end round-trip test: load a YAML scalar document,
 * dump it, expect byte-identical output (Stage 1 §1.1).
 *
 * Validates the entire pipeline: Scanner produces tokens, Parser
 * builds AST, Resolver applies typing, Emitter renders bytes.
 */
#[CoversClass(YamlStringLoader::class)]
#[CoversClass(YamlStringDumper::class)]
final class ScalarRoundTripTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function scalarSources(): iterable
    {
        yield 'plain string' => ["hello\n"];
        yield 'integer 42' => ["42\n"];
        yield 'integer 0' => ["0\n"];
        yield 'negative integer' => ["-7\n"];
        yield 'hex integer' => ["0xFF\n"];
        yield 'octal integer' => ["0o17\n"];
        yield 'float' => ["3.14\n"];
        yield 'scientific float' => ["1e2\n"];
        yield 'true lower' => ["true\n"];
        yield 'True capital' => ["True\n"];
        yield 'TRUE upper' => ["TRUE\n"];
        yield 'false' => ["false\n"];
        yield 'null lowercase' => ["null\n"];
        yield 'null tilde' => ["~\n"];
        yield 'NULL upper' => ["NULL\n"];
        yield 'inf positive' => [".inf\n"];
        yield 'inf negative' => ["-.inf\n"];
        yield 'string-shaped yes' => ["yes\n"];
        yield 'identifier with underscore' => ["my_var\n"];
    }

    #[DataProvider('scalarSources')]
    public function testRoundTrip(string $source): void
    {
        $stream = (new YamlStringLoader())->load($source);
        $output = (new YamlStringDumper())->dump($stream);
        $this->assertSame($source, $output);
    }

    public function testRoundTripWithDocumentStartMarker(): void
    {
        $source = "---\nhello\n";
        $stream = (new YamlStringLoader())->load($source);
        $output = (new YamlStringDumper())->dump($stream);
        $this->assertSame($source, $output);
    }

    public function testRoundTripWithBothDocumentMarkers(): void
    {
        $source = "---\nhello\n...\n";
        $stream = (new YamlStringLoader())->load($source);
        $output = (new YamlStringDumper())->dump($stream);
        $this->assertSame($source, $output);
    }
}
