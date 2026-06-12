<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Test\Unit\Document;

use Horde\Yaml\Document\LeniencyPolicy;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Stage 15 AU: confirm the policy reaches the parser. AU does not
 * change behaviour. Flags are not consumed yet, but the loader,
 * pipeline, and individual stage classes must accept the parameter
 * without error and the existing default-policy behaviour must be
 * preserved.
 */
#[CoversNothing]
final class LoaderPolicyPlumbingTest extends TestCase
{
    public function testDefaultPolicyAcceptsCorpusFile(): void
    {
        // No explicit policy: loader applies hordeCompat.
        $src = "id: kronolith\nversion: 6.0.0\n";
        $r = (new YamlStringLoader())->load($src)->getDocument(0)->root()->resolved();
        $this->assertSame(['id' => 'kronolith', 'version' => '6.0.0'], $r);
    }

    public function testStrictPolicyAcceptsValidInput(): void
    {
        // Strict input parses identically under any policy.
        $src = "id: kronolith\nversion: 6.0.0\n";
        $r = (new YamlStringLoader(policy: LeniencyPolicy::strictYaml12()))
            ->load($src)
            ->getDocument(0)
            ->root()
            ->resolved();
        $this->assertSame(['id' => 'kronolith', 'version' => '6.0.0'], $r);
    }

    public function testTolerantPolicyMatchesDefault(): void
    {
        $src = "id: kronolith\nversion: 6.0.0\n";
        $a = (new YamlStringLoader())
            ->load($src)
            ->getDocument(0)
            ->root()
            ->resolved();
        $b = (new YamlStringLoader(policy: LeniencyPolicy::tolerant()))
            ->load($src)
            ->getDocument(0)
            ->root()
            ->resolved();
        $this->assertSame($a, $b);
    }
}
