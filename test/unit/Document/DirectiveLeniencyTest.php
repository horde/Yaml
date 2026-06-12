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
use Horde\Yaml\Document\ParseException;
use Horde\Yaml\Document\YamlStringLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Stage 15 AV: directive leniencies are gated by named flags. Under
 * strictYaml12() each is rejected; under hordeCompat() each is
 * accepted.
 */
#[CoversNothing]
final class DirectiveLeniencyTest extends TestCase
{
    public function testStrictRejectsDuplicateYamlDirective(): void
    {
        $loader = new YamlStringLoader(policy: LeniencyPolicy::strictYaml12());
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/Duplicate %YAML/');
        $loader->load("%YAML 1.2\n%YAML 1.2\n---\n");
    }

    public function testHordeCompatAcceptsDuplicateYamlDirective(): void
    {
        $loader = new YamlStringLoader(policy: LeniencyPolicy::hordeCompat());
        $stream = $loader->load("%YAML 1.2\n%YAML 1.2\n---\n");
        $this->assertCount(1, $stream->getDocuments());
    }

    public function testStrictRejectsMalformedYamlArguments(): void
    {
        $loader = new YamlStringLoader(policy: LeniencyPolicy::strictYaml12());
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/Malformed %YAML/');
        $loader->load("%YAML 1.2 foo\n---\n");
    }

    public function testHordeCompatAcceptsMalformedYamlArguments(): void
    {
        $loader = new YamlStringLoader(policy: LeniencyPolicy::hordeCompat());
        $stream = $loader->load("%YAML 1.2 foo\n---\n");
        $this->assertCount(1, $stream->getDocuments());
    }

    public function testStrictRejectsDirectiveOnlyDocument(): void
    {
        $loader = new YamlStringLoader(policy: LeniencyPolicy::strictYaml12());
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/`---` start marker/');
        $loader->load("%YAML 1.2\n...\n");
    }

    public function testHordeCompatAcceptsDirectiveOnlyDocument(): void
    {
        $loader = new YamlStringLoader(policy: LeniencyPolicy::hordeCompat());
        $stream = $loader->load("%YAML 1.2\n...\n");
        $this->assertCount(1, $stream->getDocuments());
    }

    public function testCustomFlagOverride(): void
    {
        // Disable just the duplicate flag while keeping others.
        $policy = LeniencyPolicy::hordeCompat()->with([
            'acceptDuplicateYamlDirective' => false,
        ]);
        $loader = new YamlStringLoader(policy: $policy);

        // Duplicate now rejected
        try {
            $loader->load("%YAML 1.2\n%YAML 1.2\n---\n");
            $this->fail('Expected ParseException');
        } catch (ParseException $e) {
            $this->assertMatchesRegularExpression('/Duplicate %YAML/', $e->getMessage());
        }

        // Malformed args still tolerated
        $stream = $loader->load("%YAML 1.2 foo\n---\n");
        $this->assertCount(1, $stream->getDocuments());
    }
}
