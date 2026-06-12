<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Integration;

use Horde\Yaml\Document\LeniencyPolicy;
use Horde\Yaml\Document\ParseException;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * yaml-test-suite conformance harness.
 *
 * Walks every directory under test/fixtures/yaml-test-suite/ and
 * exercises in.yaml. Behavior is dictated by the manifest:
 *   pass: case must load without throwing
 *   error: case must throw a ParseException
 *   skip:<reason>: marked skipped with the documented reason
 *
 * Cases not in the manifest are reported as errors so the manifest
 * stays comprehensive (see ManifestCoverageTest).
 * @coversNothing
 */
final class ConformanceTest extends TestCase
{
    private const SUITE_PATH = __DIR__ . '/../fixtures/yaml-test-suite';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        if (!is_dir(self::SUITE_PATH)) {
            return;
        }
        $manifest = require __DIR__ . '/../fixtures/conformance/yaml-test-suite-status.php';
        $entries = glob(self::SUITE_PATH . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($entries as $caseDir) {
            $id = basename($caseDir);
            // Skip metadata directories.
            if ($id === 'name' || $id === 'tags') {
                continue;
            }
            // Multi-format directories contain numbered subcase
            // directories (e.g. 2G84/00, 2G84/01). Each subcase is
            // independently scored under its composite ID "PARENT/NN".
            if (!file_exists($caseDir . '/in.yaml')) {
                $subdirs = glob($caseDir . '/[0-9][0-9]', GLOB_ONLYDIR) ?: [];
                foreach ($subdirs as $sub) {
                    $subId = $id . '/' . basename($sub);
                    $status = $manifest[$subId] ?? 'unknown';
                    yield $subId => [$sub, $status];
                }
                continue;
            }
            $status = $manifest[$id] ?? 'unknown';
            yield $id => [$caseDir, $status];
        }
    }

    #[DataProvider('cases')]
    public function testCase(string $caseDir, string $status): void
    {
        if (!is_dir(self::SUITE_PATH)) {
            $this->markTestSkipped(
                'yaml-test-suite submodule not initialized; run git submodule update --init',
            );
        }

        if (str_starts_with($status, 'skip:')) {
            $this->markTestSkipped(substr($status, 5));
        }

        if ($status === 'unknown') {
            $this->fail(
                'Case ' . basename($caseDir) . ' not in manifest. '
                    . 'Add an entry to test/fixtures/conformance/yaml-test-suite-status.php',
            );
        }

        $inFile = "$caseDir/in.yaml";
        if (!file_exists($inFile)) {
            $this->markTestSkipped('Case has no in.yaml');
        }

        $yaml = file_get_contents($inFile);
        $errorMarker = file_exists("$caseDir/error");

        if ($status === 'error' || $errorMarker) {
            $this->expectException(ParseException::class);
            (new YamlStringLoader(policy: LeniencyPolicy::strictYaml12()))->load($yaml);
            return;
        }

        // Status is 'pass'. Must load without throwing.
        try {
            (new YamlStringLoader(policy: LeniencyPolicy::strictYaml12()))->load($yaml);
            $this->assertTrue(true);
        } catch (Throwable $e) {
            $this->fail('Expected case ' . basename($caseDir) . ' to pass; got '
                . $e::class . ': ' . $e->getMessage());
        }
    }
}
