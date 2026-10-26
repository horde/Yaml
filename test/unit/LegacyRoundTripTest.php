<?php

declare(strict_types=1);

namespace Horde\Yaml\Test\Unit;

use Horde\Yaml\Document\YamlStringLoader;
use Horde\Yaml\Yaml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The legacy Loader and Dumper, behind Yaml::load() and Yaml::dump().
 *
 * The Loader took any plain scalar ending in braces for an inline mapping, so
 * an ordinary `${HOME}` threw a TypeError. The Dumper wrote strings unquoted
 * that read back as another type (`'2048'` as the integer 2048), or that do
 * not parse in YAML 1.2 at all (`oidc:`).
 * @coversNothing
 */
final class LegacyRoundTripTest extends TestCase
{
    /** @return array<string, array{string, array<string, mixed>}> */
    public static function braces(): array
    {
        return [
            'variable'           => ["a: \${x}\n", ['a' => '${x}']],
            'variable in a word' => ["a: b\${x}\n", ['a' => 'b${x}']],
            // The negative control: an inline mapping is still one.
            'inline mapping'     => ["a: {b: 1}\n", ['a' => ['b' => 1]]],
        ];
    }

    /** @param array<string, mixed> $expected */
    #[DataProvider('braces')]
    public function testAScalarEndingInBracesIsNotAnInlineMapping(string $source, array $expected): void
    {
        self::assertSame($expected, Yaml::load($source));
    }

    /** @return array<string, array{string}> */
    public static function strings(): array
    {
        return [
            'integer'         => ['2048'],
            'float'           => ['1.5'],
            'boolean'         => ['true'],
            'yes'             => ['yes'],
            'null'            => ['null'],
            'tilde'           => ['~'],
            'empty'           => [''],
            'trailing colon'  => ['oidc:'],
            'leading space'   => [' x'],
            'leading bracket' => ['[x]'],
            'leading brace'   => ['{x}'],
            'leading quote'   => ["'x'"],
            'quote inside'    => ["it's #1"],
        ];
    }

    #[DataProvider('strings')]
    public function testAStringIsDumpedSoItReadsBackAsTheSameString(string $value): void
    {
        $dumped = Yaml::dump(['k' => $value]);

        self::assertSame(['k' => $value], Yaml::load($dumped), $dumped);
        self::assertSame(
            ['k' => $value],
            (new YamlStringLoader())->load($dumped)->getDocument()->root()->resolved(),
            $dumped,
        );
    }

    /** The negative control: an ordinary string and a number are not quoted. */
    public function testOrdinaryValuesAreNotQuoted(): void
    {
        self::assertSame("---\nk: v\nn: 2048\n", Yaml::dump(['k' => 'v', 'n' => 2048]));
    }
}
