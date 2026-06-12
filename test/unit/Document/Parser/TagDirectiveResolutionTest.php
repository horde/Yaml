<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document\Parser;

use Horde\Yaml\Document\Node\Node;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\ScalarStyle;
use Horde\Yaml\Document\TagHandler;
use Horde\Yaml\Document\TagHandlerException;
use Horde\Yaml\Document\TagRegistry;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

use function assert;

/**
 * Stage 12 Chapter Y: per-document `%TAG` directive resolution.
 */
#[CoversNothing]
final class TagDirectiveResolutionTest extends TestCase
{
    private function tagOf(string $src, ?TagRegistry $reg = null): string
    {
        $stream = (new YamlStringLoader(tagRegistry: $reg))->load($src);
        $root = $stream->getDocument(0)->root();
        assert($root instanceof ScalarNode);
        return (string) $root->getTag();
    }

    public function testSecondaryHandleRemapMakesIntCustom(): void
    {
        // Per YAML 1.2, %TAG !! tag:example.com,2000:app/ remaps the
        // secondary handle. !!int after this directive is a custom
        // tag (tag:example.com,...:app/int), not the core int.
        $src = "%TAG !! tag:example.com,2000:app/\n---\n!!int 1 - 3\n";
        $stream = (new YamlStringLoader())->load($src);
        $root = $stream->getDocument(0)->root();
        assert($root instanceof ScalarNode);
        // Lexical value preserved verbatim.
        $this->assertSame('1 - 3', $root->getValue());
        // No core-int coercion applied.
        $this->assertSame('!!int', $root->getTag());
    }

    public function testNamedHandle(): void
    {
        $reg = new TagRegistry();
        $reg->register(new class implements TagHandler {
            public function tag(): string
            {
                return 'tag:horde.org,2026:Permission';
            }
            public function fromYaml(ScalarNode $node): mixed
            {
                return ['perm' => (string) $node];
            }
            public function toYaml(mixed $value): Node
            {
                throw new TagHandlerException('not implemented');
            }
        });
        $src = "%TAG !p! tag:horde.org,2026:\n---\n!p!Permission read\n";
        $stream = (new YamlStringLoader(tagRegistry: $reg))->load($src);
        $root = $stream->getDocument(0)->root();
        assert($root instanceof ScalarNode);
        $this->assertTrue($root->hasResolvedValue());
        $this->assertSame(['perm' => 'read'], $root->getResolvedValue());
    }

    public function testPrimaryHandleRemap(): void
    {
        // The primary handle `!` resolves locally by default. With a
        // %TAG ! directive it expands to a URI prefix.
        $src = "%TAG ! tag:horde.org,2026:\n---\n!Foo bar\n";
        $stream = (new YamlStringLoader())->load($src);
        $root = $stream->getDocument(0)->root();
        assert($root instanceof ScalarNode);
        // No registered handler: leaves the lexical value alone but
        // the tag stays in shorthand on the AST for round-trip.
        $this->assertSame('!Foo', $root->getTag());
        $this->assertSame('bar', $root->getValue());
    }

    public function testVerbatimTagFormSkipsHandleExpansion(): void
    {
        // `!<...>` is already-fully-qualified.
        $reg = new TagRegistry();
        $reg->register(new class implements TagHandler {
            public function tag(): string
            {
                return 'urn:x:foo';
            }
            public function fromYaml(ScalarNode $node): mixed
            {
                return ['fqn' => true];
            }
            public function toYaml(mixed $value): Node
            {
                throw new TagHandlerException('nope');
            }
        });
        $src = "x: !<urn:x:foo> hello\n";
        $stream = (new YamlStringLoader(tagRegistry: $reg))->load($src);
        $node = $stream->getDocument(0)->root()->entry('x')->getValue();
        assert($node instanceof ScalarNode);
        $this->assertTrue($node->hasResolvedValue());
        $this->assertSame(['fqn' => true], $node->getResolvedValue());
    }

    public function testDirectivesArePerDocument(): void
    {
        // Multi-document stream with directives only before the first
        // document; second document does NOT inherit them.
        $src = "%TAG !p! tag:horde.org,2026:\n"
            . "---\n"
            . "!p!Foo first\n"
            . "...\n"
            . "---\n"
            . "!p!Foo second\n";
        $stream = (new YamlStringLoader())->load($src);
        $first = $stream->getDocument(0)->root();
        $second = $stream->getDocument(1)->root();
        assert($first instanceof ScalarNode);
        assert($second instanceof ScalarNode);
        // Both retain shorthand on the AST. No registered handler so
        // resolution doesn't run, but the second document has no
        // `!p!` handle entry.
        $this->assertSame([], $stream->getDocument(1)->getTagHandles());
        $this->assertNotEmpty($stream->getDocument(0)->getTagHandles());
    }
}
