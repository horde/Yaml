<?php

declare(strict_types=1);

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\Emitter\Emitter;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A float is emitted as a float, with every digit it has.
 *
 * The emitter cast floats to string. PHP writes 1.0 as "1", which reads back
 * as the integer 1, and rounds to `precision` (14) significant digits, so
 * 0.1 + 0.2 was written as 0.3 and read back as a different number.
 * @coversNothing
 */
final class FloatEmitTest extends TestCase
{
    /** @return array<string, array{float}> */
    public static function floats(): array
    {
        return [
            'no fraction'      => [1.0],
            'negative'         => [-3.0],
            'negative zero'    => [-0.0],
            'large'            => [1e20],
            'small'            => [1e-7],
            'seventeen digits' => [0.1 + 0.2],
            'fraction'         => [0.5],
        ];
    }

    #[DataProvider('floats')]
    public function testAFloatReadsBackAsTheSameFloat(float $value): void
    {
        $stream = (new YamlStringLoader())->load("f: x\n");
        $stream->getDocument()->root()->setEntry('f', new ScalarNode($value));

        $emitted = (new Emitter())->emit($stream);
        $read = (new YamlStringLoader())->load($emitted)->getDocument()->root()->entry('f')->getValue()->getValue();

        self::assertIsFloat($read, $emitted);
        self::assertSame(var_export($value, true), var_export($read, true), $emitted);
    }

    /** The negative control: an integer is still written without a fraction. */
    public function testAnIntegerIsStillAnInteger(): void
    {
        $stream = (new YamlStringLoader())->load("f: x\n");
        $stream->getDocument()->root()->setEntry('f', new ScalarNode(1));

        self::assertSame("f: 1\n", (new Emitter())->emit($stream));
    }
}
