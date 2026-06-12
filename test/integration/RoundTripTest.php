<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Integration;

use Horde\Yaml\Document\YamlStringDumper;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fixture-driven round-trip test.
 *
 * Walks test/fixtures/roundtrip/ for case directories. Each case
 * has input.yml and expected.yml; mutation cases additionally have
 * a mutation.php returning a closure (introduced when mutation
 * fixtures arrive in chapter G).
 *
 * Pattern per Stage 8 §2.3.
 * @coversNothing
 */
final class RoundTripTest extends TestCase
{
    #[DataProvider('fixtures')]
    public function testRoundTrip(string $caseDir): void
    {
        $input = file_get_contents("$caseDir/input.yml");
        $expected = file_get_contents("$caseDir/expected.yml");
        $this->assertNotFalse($input, "missing input.yml in $caseDir");
        $this->assertNotFalse($expected, "missing expected.yml in $caseDir");

        $stream = (new YamlStringLoader())->load($input);

        $mutationFile = "$caseDir/mutation.php";
        if (file_exists($mutationFile)) {
            $mutate = require $mutationFile;
            $mutate($stream->getDocuments()[0]);
        }

        $output = (new YamlStringDumper())->dump($stream);
        $this->assertSame($expected, $output);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtures(): iterable
    {
        $base = __DIR__ . '/../fixtures/roundtrip';
        if (!is_dir($base)) {
            return;
        }
        foreach (glob("$base/*", GLOB_ONLYDIR) ?: [] as $categoryDir) {
            foreach (glob("$categoryDir/*", GLOB_ONLYDIR) ?: [] as $caseDir) {
                $name = basename($categoryDir) . '/' . basename($caseDir);
                yield $name => [$caseDir];
            }
        }
    }
}
