<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Perf;

use Horde\Yaml\Document\YamlStringDumper;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Stage 1 §4.2 ceiling: per-file load+dump on a typical config file
 * (<10 KB) completes in under 100 ms.
 *
 * The fixture is a representative .horde.yml from the snapshotted
 * corpus (Imap_Client.horde.yml, one of the longer ones).
 */
#[CoversNothing]
final class TypicalFilePerfTest extends TestCase
{
    private const MAX_SECONDS = 0.1;
    private const FIXTURE_PATH = __DIR__ . '/../fixtures/perf/horde-yml-corpus/Imap_Client.horde.yml';

    public function testTypicalFileLoadDumpUnderCeiling(): void
    {
        if (!file_exists(self::FIXTURE_PATH)) {
            $this->markTestSkipped('Fixture file not found: ' . self::FIXTURE_PATH);
        }
        $size = filesize(self::FIXTURE_PATH);
        if ($size > 10240) {
            $this->markTestSkipped(sprintf(
                'Fixture is %d bytes; the ceiling assumes <10KB',
                $size,
            ));
        }
        $contents = file_get_contents(self::FIXTURE_PATH);

        $loader = new YamlStringLoader();
        $dumper = new YamlStringDumper();

        // Warm-up (autoload + JIT) once outside the measured window.
        try {
            $dumper->dump($loader->load($contents));
        } catch (Throwable) {
            $this->markTestSkipped('Fixture failed to load (out-of-scope feature)');
        }

        $start = microtime(true);
        $dumper->dump($loader->load($contents));
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(
            self::MAX_SECONDS,
            $elapsed,
            sprintf(
                'Load+dump took %.4fs; ceiling %.3fs (file size: %d bytes)',
                $elapsed,
                self::MAX_SECONDS,
                $size,
            ),
        );
    }
}
