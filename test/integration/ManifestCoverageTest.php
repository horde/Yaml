<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Verifies that the conformance manifest covers every case in the
 * vendored yaml-test-suite. If the submodule grows new cases, this
 * test fails until they're triaged into the manifest.
 * @coversNothing
 */
final class ManifestCoverageTest extends TestCase
{
    private const SUITE_PATH = __DIR__ . '/../fixtures/yaml-test-suite';
    private const MANIFEST = __DIR__ . '/../fixtures/conformance/yaml-test-suite-status.php';

    public function testManifestCoversAllCases(): void
    {
        if (!is_dir(self::SUITE_PATH)) {
            $this->markTestSkipped(
                'yaml-test-suite submodule not initialized; run git submodule update --init',
            );
        }

        /** @var array<string, string> $manifest */
        $manifest = require self::MANIFEST;

        $caseDirs = glob(self::SUITE_PATH . '/*', GLOB_ONLYDIR) ?: [];
        $missing = [];
        foreach ($caseDirs as $caseDir) {
            $id = basename($caseDir);
            if ($id === 'name' || $id === 'tags') {
                continue;
            }
            // Multi-format directories: each numbered subdir is its
            // own scored case under the composite ID "PARENT/NN".
            if (!file_exists($caseDir . '/in.yaml')) {
                $subdirs = glob($caseDir . '/[0-9][0-9]', GLOB_ONLYDIR) ?: [];
                if ($subdirs === []) {
                    continue;
                }
                foreach ($subdirs as $sub) {
                    $subId = $id . '/' . basename($sub);
                    if (!array_key_exists($subId, $manifest)) {
                        $missing[] = $subId;
                    }
                }
                continue;
            }
            if (!array_key_exists($id, $manifest)) {
                $missing[] = $id;
            }
        }

        $this->assertSame([], $missing, sprintf(
            "Manifest missing %d case(s): %s. Re-run scripts/triage-yaml-test-suite.php to refresh.",
            count($missing),
            implode(', ', array_slice($missing, 0, 10)) . (count($missing) > 10 ? '...' : ''),
        ));
    }

    public function testManifestHasNoOrphans(): void
    {
        if (!is_dir(self::SUITE_PATH)) {
            $this->markTestSkipped('submodule not initialized');
        }

        /** @var array<string, string> $manifest */
        $manifest = require self::MANIFEST;

        $existingCases = [];
        foreach (glob(self::SUITE_PATH . '/*', GLOB_ONLYDIR) ?: [] as $caseDir) {
            $id = basename($caseDir);
            if (file_exists($caseDir . '/in.yaml')) {
                $existingCases[$id] = true;
                continue;
            }
            // Multi-format: register each numbered subcase.
            foreach (glob($caseDir . '/[0-9][0-9]', GLOB_ONLYDIR) ?: [] as $sub) {
                $existingCases[$id . '/' . basename($sub)] = true;
            }
        }

        $orphans = [];
        foreach ($manifest as $id => $_status) {
            if (!isset($existingCases[$id])) {
                $orphans[] = $id;
            }
        }

        $this->assertSame([], $orphans, sprintf(
            "Manifest references %d case(s) no longer in the suite: %s",
            count($orphans),
            implode(', ', array_slice($orphans, 0, 10)) . (count($orphans) > 10 ? '...' : ''),
        ));
    }

    public function testManifestStatusValuesAreValid(): void
    {
        /** @var array<string, string> $manifest */
        $manifest = require self::MANIFEST;

        $invalid = [];
        foreach ($manifest as $id => $status) {
            if ($status !== 'pass' && $status !== 'error' && !str_starts_with($status, 'skip:')) {
                $invalid[$id] = $status;
            }
        }

        $this->assertSame([], $invalid, sprintf(
            "Manifest has %d invalid status value(s): %s",
            count($invalid),
            json_encode(array_slice($invalid, 0, 5, preserve_keys: true)),
        ));
    }
}
