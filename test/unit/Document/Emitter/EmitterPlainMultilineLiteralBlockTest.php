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
use Horde\Yaml\Document\Node\MapEntry;
use Horde\Yaml\Document\Node\MapNode;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\ScalarStyle;
use Horde\Yaml\Document\YamlDocument;
use Horde\Yaml\Document\YamlStream;
use Horde\Yaml\Yaml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that a Plain-styled synthesized scalar carrying real
 * newlines emits as a YAML literal block instead of falling through
 * the Plain -> SingleQuoted -> DoubleQuoted ladder and producing an
 * escaped `\n` sequence. Regression against the bug where a
 * changelog.yml release entry written by horde-components ended up
 * containing literal `\n` characters in the notes field.
 */
#[CoversClass(Emitter::class)]
final class EmitterPlainMultilineLiteralBlockTest extends TestCase
{
    private function emitRoot(ScalarNode $node): string
    {
        $stream = new YamlStream();
        $doc = new YamlDocument();
        $doc->setParentStream($stream);
        $doc->setRootInternal($node);
        $stream->appendInternalDocument($doc);
        return (new Emitter())->emit($stream);
    }

    private function emitMap(string $key, ScalarNode $value): string
    {
        $map = new MapNode();
        $map->appendChildInternal(new MapEntry(new ScalarNode($key), $value));
        $stream = new YamlStream();
        $doc = new YamlDocument();
        $doc->setParentStream($stream);
        $doc->setRootInternal($map);
        $stream->appendInternalDocument($doc);
        return (new Emitter())->emit($stream);
    }

    public function testPlainMultilineMapValueEmitsAsLiteralBlockNotEscaped(): void
    {
        // This is the changelog.yml case: a Plain-styled scalar with
        // real newlines given as a map value. Before the fix, the
        // emitter wrote `notes: "line1\nline2\n"`. After the fix it
        // emits a literal block and no `\n` escapes appear.
        $value = "line1\nline2\n";
        $output = $this->emitMap('notes', new ScalarNode($value));

        $expected = "notes: |\n  line1\n  line2\n";
        $this->assertSame($expected, $output);
        $this->assertStringNotContainsString('\\n', $output);
    }

    public function testPlainMultilineRootScalarEmitsAsLiteralBlock(): void
    {
        $output = $this->emitRoot(new ScalarNode("line1\nline2\n"));

        $expected = "|\n  line1\n  line2\n";
        $this->assertSame($expected, $output);
    }

    public function testTrailingNewlineChoosesClipIndicator(): void
    {
        $output = $this->emitMap('n', new ScalarNode("a\nb\n"));
        $this->assertStringContainsString(": |\n", $output);
        $this->assertStringNotContainsString(': |-', $output);
        $this->assertStringNotContainsString(': |+', $output);
    }

    public function testNoTrailingNewlineChoosesStripIndicator(): void
    {
        $output = $this->emitMap('n', new ScalarNode("a\nb"));
        $this->assertStringContainsString(": |-\n", $output);
    }

    public function testMultipleTrailingNewlinesChoosesKeepIndicator(): void
    {
        $output = $this->emitMap('n', new ScalarNode("a\nb\n\n\n"));
        $this->assertStringContainsString(": |+\n", $output);
    }

    public function testPinnedSingleQuotedWithNewlineStillUpgradesToDoubleQuoted(): void
    {
        // Regression: pinned styles bypass the literal-block upgrade.
        // The existing "single can't carry newlines, fall through to
        // double" contract in EmitterStyleUpgradeTest still holds.
        $node = new ScalarNode("line1\nline2", ScalarStyle::SingleQuoted);
        $output = $this->emitRoot($node);
        $this->assertSame("\"line1\\nline2\"\n", $output);
    }

    public function testPinnedDoubleQuotedWithNewlineStillEscapes(): void
    {
        $node = new ScalarNode("line1\nline2", ScalarStyle::DoubleQuoted);
        $output = $this->emitRoot($node);
        $this->assertSame("\"line1\\nline2\"\n", $output);
    }

    public function testTaggedPlainMultilineFallsBackToDoubleQuoted(): void
    {
        // Explicit tag means the caller cares about typing; keep the
        // ladder behavior in that case.
        $node = new ScalarNode("a\nb");
        $node->setTag('!custom');
        $output = $this->emitRoot($node);
        $this->assertStringContainsString('\\n', $output);
        $this->assertStringNotContainsString("\n  a\n", $output);
    }

    public function testLineStartingWithSpaceFallsBackToDoubleQuoted(): void
    {
        // Content whose first character (or any line's first
        // character) is a space would require an explicit indent
        // indicator (`|2` etc.) we do not synthesize. Fall through
        // to double-quoted for those.
        $output = $this->emitMap('n', new ScalarNode(" leading\nnormal"));
        $this->assertStringContainsString('\\n', $output);
    }

    public function testCarriageReturnForcesDoubleQuoted(): void
    {
        // Literal blocks cannot carry a bare "\r"; force double-quoted
        // with hex escapes.
        $output = $this->emitMap('n', new ScalarNode("a\r\nb"));
        $this->assertStringContainsString('\\r', $output);
        $this->assertStringNotContainsString(": |\n", $output);
    }

    public function testSingleLinePlainStillEmitsPlain(): void
    {
        // No newline in value: the upgrade must not trigger. This
        // guards against accidentally routing every plain string
        // through the literal-block path.
        $output = $this->emitMap('n', new ScalarNode('just one line'));
        $this->assertSame("n: just one line\n", $output);
    }

    public function testRoundTripPreservesNewlines(): void
    {
        // End-to-end: emit a synthesized multi-line notes entry, feed
        // the output back to Yaml::load, and confirm the string comes
        // back with real newlines. This is the changelog contract.
        $value = "fix(security): plug hole\nfeat(imp): new toggle\n";
        $output = $this->emitMap('notes', new ScalarNode($value));

        $parsed = Yaml::load($output);
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('notes', $parsed);
        $this->assertSame($value, $parsed['notes']);
    }
}
