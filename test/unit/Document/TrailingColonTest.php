<?php

declare(strict_types=1);

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\Emitter\Emitter;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A ":" at the end of a plain scalar is a mapping indicator.
 *
 * YAML 1.2 admits ":" inside a plain scalar only when it is followed by a
 * plain-safe character. At the end of the scalar, or before a tab, it starts a
 * mapping value, so `k: oidc:` does not parse. The emitter checked for ": "
 * but not for these, and wrote such strings unquoted. Likewise it checked for
 * " #" but not for a tab before "#", which also starts a comment.
 * @coversNothing
 */
final class TrailingColonTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function strings(): array
    {
        return [
            'trailing colon'      => ['oidc:'],
            'only colons'         => ['::'],
            'url scheme'          => ['https:'],
            'colon before a tab'  => ["a:\tb"],
            'hash after a tab'    => ["a\t#b"],
        ];
    }

    #[DataProvider('strings')]
    public function testAStringEndingInAColonSurvivesTheRoundTrip(string $value): void
    {
        $stream = (new YamlStringLoader())->load("k: x\n");
        $stream->getDocument()->root()->setEntry('k', new ScalarNode($value));

        $emitted = (new Emitter())->emit($stream);
        $reparsed = (new YamlStringLoader())->load($emitted);

        self::assertSame($value, $reparsed->getDocument()->root()->entry('k')->getValue()->getValue(), $emitted);
    }

    /** @return array<string, array{string}> */
    public static function stillPlain(): array
    {
        return [
            'colon inside'   => ["key: a:b\n"],
            'ipv6'           => ["key: ::1\n"],
            'port'           => ["key: localhost:8080\n"],
        ];
    }

    /**
     * The negative control: a ":" followed by a plain-safe character is
     * still content, and must not be quoted.
     */
    #[DataProvider('stillPlain')]
    public function testAColonInsideAPlainScalarStaysPlain(string $source): void
    {
        $stream = (new YamlStringLoader())->load($source);
        $root = $stream->getDocument()->root();
        $root->setEntry('key', new ScalarNode($root->entry('key')->getValue()->getValue()));

        self::assertSame($source, (new Emitter())->emit($stream));
    }
}
