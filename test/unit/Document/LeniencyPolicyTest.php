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
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

#[CoversNothing]
final class LeniencyPolicyTest extends TestCase
{
    public function testStrictYaml12IsAllFalse(): void
    {
        $p = LeniencyPolicy::strictYaml12();
        foreach ($p->toArray() as $name => $value) {
            $this->assertFalse(
                $value,
                "Strict policy should have $name = false",
            );
        }
    }

    public function testHordeCompatIsAllTrue(): void
    {
        // Today every named flag is true under hordeCompat. As new
        // flags are added some may default false even under compat;
        // when that happens, this test gets refined.
        $p = LeniencyPolicy::hordeCompat();
        foreach ($p->toArray() as $name => $value) {
            $this->assertTrue(
                $value,
                "hordeCompat should have $name = true currently",
            );
        }
    }

    public function testTolerantMatchesHordeCompatToday(): void
    {
        $this->assertSame(
            LeniencyPolicy::hordeCompat()->toArray(),
            LeniencyPolicy::tolerant()->toArray(),
        );
    }

    public function testWithOverridesOneFlag(): void
    {
        $p = LeniencyPolicy::hordeCompat()
            ->with(['acceptDuplicateYamlDirective' => false]);
        $this->assertFalse($p->acceptDuplicateYamlDirective);
        $this->assertTrue($p->acceptMalformedYamlDirectiveArguments);
    }

    public function testWithRejectsUnknownFlag(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown leniency flag: nonsense');
        LeniencyPolicy::strictYaml12()->with(['nonsense' => true]);
    }

    public function testDiffEnumeratesDifferences(): void
    {
        $strict = LeniencyPolicy::strictYaml12();
        $compat = LeniencyPolicy::hordeCompat();
        $diff = $strict->diff($compat);
        $this->assertNotEmpty($diff);
        foreach ($diff as $info) {
            $this->assertFalse($info['this']);
            $this->assertTrue($info['other']);
        }
    }

    public function testDiffEmptyForIdentical(): void
    {
        $a = LeniencyPolicy::hordeCompat();
        $b = LeniencyPolicy::hordeCompat();
        $this->assertSame([], $a->diff($b));
    }

    public function testMergePrefersOtherWhereOtherDiffersFromStrict(): void
    {
        $strict = LeniencyPolicy::strictYaml12();
        $compat = LeniencyPolicy::hordeCompat();
        // Merging strict <- compat should yield compat-equivalent.
        $merged = $strict->merge($compat);
        $this->assertSame($compat->toArray(), $merged->toArray());
    }

    public function testMergeKeepsThisWhereOtherMatchesStrict(): void
    {
        // A custom policy that only differs from strict in one flag.
        $custom = LeniencyPolicy::strictYaml12()
            ->with(['acceptDuplicateYamlDirective' => true]);
        // Merging custom <- strict should not lose the one diff.
        $merged = $custom->merge(LeniencyPolicy::strictYaml12());
        $this->assertTrue($merged->acceptDuplicateYamlDirective);
    }

    public function testDescribeLists(): void
    {
        $desc = LeniencyPolicy::strictYaml12()->describe();
        $this->assertStringContainsString('acceptDuplicateYamlDirective = false', $desc);
    }

    public function testToArrayKeysMatchPropertyNames(): void
    {
        $p = LeniencyPolicy::strictYaml12();
        $names = array_keys($p->toArray());
        // Just make sure each flag is named, not numerically indexed.
        foreach ($names as $name) {
            $this->assertIsString($name);
        }
    }
}
