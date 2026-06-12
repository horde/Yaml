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
 * Stage 1 §4.2 ceiling: load + dump every .horde.yml in the
 * snapshotted Horde-component corpus within 5 seconds.
 *
 * The corpus lives at test/fixtures/perf/horde-yml-corpus/. It is
 * a one-time snapshot of .horde.yml files from across the Horde
 * components, taken to make the perf gate portable (no dependency
 * on a developer's monorepo checkout). To refresh the snapshot,
 * re-run scripts/snapshot-horde-yml-corpus.sh from the repo root.
 *
 * The gate is a regression detector, not a marketing promise.
 */
#[CoversNothing]
final class MonorepoRoundTripPerfTest extends TestCase
{
    private const MAX_SECONDS = 5.0;
    private const CORPUS_PATH = __DIR__ . '/../fixtures/perf/horde-yml-corpus';

    public function testCorpusRoundTripUnderCeiling(): void
    {
        $files = glob(self::CORPUS_PATH . '/*.horde.yml') ?: [];
        if (count($files) < 10) {
            $this->markTestSkipped(
                'Corpus has only ' . count($files) . ' files; refresh via scripts/snapshot-horde-yml-corpus.sh',
            );
        }

        $loader = new YamlStringLoader();
        $dumper = new YamlStringDumper();

        $start = microtime(true);
        $processed = 0;
        $skipped = 0;
        foreach ($files as $path) {
            $contents = file_get_contents($path);
            try {
                $stream = $loader->load($contents);
                $dumper->dump($stream);
                $processed++;
            } catch (Throwable) {
                // Some .horde.yml use YAML 1.1 booleans or other
                // out-of-scope features; skip them. Per Stage 1 §B9,
                // the document layer is strict 1.2.
                $skipped++;
            }
        }
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(
            self::MAX_SECONDS,
            $elapsed,
            sprintf(
                'Corpus round-trip took %.3fs over %d files (%d skipped); ceiling %.1fs',
                $elapsed,
                $processed,
                $skipped,
                self::MAX_SECONDS,
            ),
        );
    }
}
