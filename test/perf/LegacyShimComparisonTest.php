<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Perf;

use Horde\Yaml\Document\TagHandlers\PhpObjectTagHandler;
use Horde\Yaml\Document\TagRegistry;
use Horde\Yaml\Document\YamlFileLoader;
use Horde_Yaml;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Stage 12 Chapter Z gate: compare a candidate document-layer shim
 * against the existing legacy `Horde_Yaml::loadFile()` across the
 * snapshotted .horde.yml corpus. The shim ships only if its corpus
 * median is no more than 2x the legacy implementation's median.
 *
 * The benchmark reports both medians and the ratio. It does not
 * fail on a slow ratio. It only records. The Z gate decision in
 * the next sub-phase reads the recorded numbers.
 */
#[CoversNothing]
final class LegacyShimComparisonTest extends TestCase
{
    private const CORPUS_PATH = __DIR__ . '/../fixtures/perf/horde-yml-corpus';
    private const RUNS_PER_FILE = 3;

    /**
     * @return array{legacy: float, shim: float, ratio: float, files: int}
     */
    private function benchmark(): array
    {
        $files = glob(self::CORPUS_PATH . '/*.horde.yml') ?: [];
        if (count($files) < 10) {
            $this->markTestSkipped(
                'Corpus has only ' . count($files) . ' files; refresh via scripts/snapshot-horde-yml-corpus.sh',
            );
        }

        $registry = new TagRegistry();
        $registry->register(new PhpObjectTagHandler());

        $legacyTimes = [];
        $shimTimes = [];

        // Warm-up.
        foreach (array_slice($files, 0, 5) as $f) {
            try {
                Horde_Yaml::loadFile($f);
            } catch (Throwable) {
            }
            try {
                (new YamlFileLoader(
                    legacyBooleans: true,
                    tagRegistry: $registry,
                    recognizeTimestamps: true,
                ))->load($f);
            } catch (Throwable) {
            }
        }

        foreach ($files as $path) {
            // Legacy
            $legacy = [];
            for ($i = 0; $i < self::RUNS_PER_FILE; $i++) {
                $start = microtime(true);
                try {
                    Horde_Yaml::loadFile($path);
                } catch (Throwable) {
                    continue 2; // skip this file in both
                }
                $legacy[] = microtime(true) - $start;
            }
            if ($legacy === []) {
                continue;
            }
            sort($legacy);
            $legacyTimes[] = $legacy[(int) (count($legacy) / 2)];

            // Shim candidate
            $shim = [];
            for ($i = 0; $i < self::RUNS_PER_FILE; $i++) {
                $start = microtime(true);
                try {
                    (new YamlFileLoader(
                        legacyBooleans: true,
                        tagRegistry: $registry,
                        recognizeTimestamps: true,
                    ))->load($path);
                } catch (Throwable) {
                    continue 2;
                }
                $shim[] = microtime(true) - $start;
            }
            if ($shim === []) {
                array_pop($legacyTimes);
                continue;
            }
            sort($shim);
            $shimTimes[] = $shim[(int) (count($shim) / 2)];
        }

        if ($legacyTimes === [] || $shimTimes === []) {
            $this->markTestSkipped('No comparable files in corpus');
        }

        sort($legacyTimes);
        sort($shimTimes);
        $legacyMedian = $legacyTimes[(int) (count($legacyTimes) / 2)];
        $shimMedian = $shimTimes[(int) (count($shimTimes) / 2)];

        return [
            'legacy' => $legacyMedian,
            'shim' => $shimMedian,
            'ratio' => $legacyMedian > 0 ? $shimMedian / $legacyMedian : INF,
            'files' => count($legacyTimes),
        ];
    }

    public function testReportComparison(): void
    {
        $result = $this->benchmark();

        $message = sprintf(
            "Legacy median: %.4f ms  Shim median: %.4f ms  Ratio: %.2fx  (%d files)",
            $result['legacy'] * 1000,
            $result['shim'] * 1000,
            $result['ratio'],
            $result['files'],
        );

        // Persist to a file so the user / CI can read the result.
        $reportPath = __DIR__ . '/../../build/legacy-shim-perf.txt';
        @mkdir(dirname($reportPath), 0o755, recursive: true);
        @file_put_contents(
            $reportPath,
            $message . "\n" . date('c') . "\n",
        );

        // Gate: per the recorded acceptance criterion, the shim
        // ships only if the ratio ≤ 2x. As of this writing the
        // ratio is around 4x. The document layer trades runtime
        // for round-trip fidelity (Stage 1 §A4). The shim does not
        // ship; legacy Horde_Yaml::loadFile keeps its existing
        // implementation.
        //
        // This test stays in the perf suite as an informational
        // probe. If the document layer is later optimised below
        // 2x, the test starts passing and the shim becomes
        // eligible for adoption.
        if ($result['ratio'] > 2.0) {
            $this->markTestSkipped(
                'Shim is too slow to ship as Horde_Yaml::loadFile drop-in. '
                    . $message,
            );
        }
        $this->assertLessThanOrEqual(2.0, $result['ratio'], $message);
    }
}
