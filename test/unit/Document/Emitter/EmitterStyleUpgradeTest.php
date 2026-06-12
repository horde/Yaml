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
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\ScalarStyle;
use Horde\Yaml\Document\YamlDocument;
use Horde\Yaml\Document\YamlStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies E.04 emitter behavior: quote style preservation and the
 * Stage 6 §3.1 upgrade ladder (plain -> single -> double).
 */
#[CoversClass(Emitter::class)]
final class EmitterStyleUpgradeTest extends TestCase
{
    private function emit(ScalarNode $node): string
    {
        $stream = new YamlStream();
        $doc = new YamlDocument();
        $doc->setParentStream($stream);
        $doc->setRootInternal($node);
        $stream->appendInternalDocument($doc);
        return (new Emitter())->emit($stream);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function plainSafeStrings(): iterable
    {
        yield 'simple word' => ['hello', "hello\n"];
        yield 'identifier' => ['my_var', "my_var\n"];
        yield 'with internal space' => ['hello world', "hello world\n"];
    }

    #[DataProvider('plainSafeStrings')]
    public function testPlainSafeStringEmitsPlain(string $value, string $expected): void
    {
        $output = $this->emit(new ScalarNode($value));
        $this->assertSame($expected, $output);
    }

    /**
     * Strings that look like other types must be quoted to avoid
     * round-tripping as int/bool/null.
     *
     * @return iterable<string, array{string}>
     */
    public static function ambiguousStrings(): iterable
    {
        yield 'looks like int' => ['42'];
        yield 'looks like float' => ['3.14'];
        yield 'looks like true' => ['true'];
        yield 'looks like null' => ['null'];
        yield 'looks like ~' => ['~'];
        yield 'looks like hex' => ['0xFF'];
        yield 'empty string' => [''];
    }

    #[DataProvider('ambiguousStrings')]
    public function testAmbiguousStringUpgradesToQuoted(string $value): void
    {
        $output = $this->emit(new ScalarNode($value));
        // Should be quoted, either single or double, but not plain.
        $this->assertMatchesRegularExpression(
            '/^[\'"].*[\'"]\n$/',
            $output,
            "Expected quoted output for ambiguous '$value', got: $output",
        );
    }

    public function testReservedLeaderUpgradesToQuoted(): void
    {
        $output = $this->emit(new ScalarNode('# starts with hash'));
        $this->assertSame("'# starts with hash'\n", $output);
    }

    public function testColonSpaceForcesQuoting(): void
    {
        $output = $this->emit(new ScalarNode('key: value-like'));
        // Must be quoted.
        $this->assertNotSame("key: value-like\n", $output);
        $this->assertStringContainsString('key', $output);
    }

    public function testPinnedSingleQuotedStyleHonored(): void
    {
        $node = new ScalarNode('safe', ScalarStyle::SingleQuoted);
        $this->assertSame("'safe'\n", $this->emit($node));
    }

    public function testPinnedDoubleQuotedStyleHonored(): void
    {
        $node = new ScalarNode('safe', ScalarStyle::DoubleQuoted);
        $this->assertSame("\"safe\"\n", $this->emit($node));
    }

    public function testSingleQuotedWithApostropheDoublesIt(): void
    {
        $node = new ScalarNode("don't", ScalarStyle::SingleQuoted);
        $this->assertSame("'don''t'\n", $this->emit($node));
    }

    public function testDoubleQuotedEscapesQuotesAndBackslashes(): void
    {
        $node = new ScalarNode('say "hi" with \\', ScalarStyle::DoubleQuoted);
        $this->assertSame("\"say \\\"hi\\\" with \\\\\"\n", $this->emit($node));
    }

    public function testDoubleQuotedEscapesNewlinesAndTabs(): void
    {
        $node = new ScalarNode("a\nb\tc", ScalarStyle::DoubleQuoted);
        $this->assertSame("\"a\\nb\\tc\"\n", $this->emit($node));
    }

    public function testNewlineForcesUpgradeFromSingleToDouble(): void
    {
        $node = new ScalarNode("line1\nline2", ScalarStyle::SingleQuoted);
        // Single-quoted can't represent newlines in our scope; upgrade.
        $this->assertSame("\"line1\\nline2\"\n", $this->emit($node));
    }
}
