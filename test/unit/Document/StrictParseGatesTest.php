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
 * Strict-mode gates added while pursuing yaml-test-suite compliance.
 *
 * Each test corresponds to a yaml-test-suite case that previously was
 * leniently accepted and now correctly errors under
 * LeniencyPolicy::strictYaml12().
 */
#[CoversNothing]
final class StrictParseGatesTest extends TestCase
{
    private function strict(): YamlStringLoader
    {
        return new YamlStringLoader(policy: LeniencyPolicy::strictYaml12());
    }

    /** yaml-test-suite 9MAG */
    public function testLeadingCommaInFlowSequenceErrors(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/empty flow entry/');
        $this->strict()->load("[ , a, b, c ]\n");
    }

    /** yaml-test-suite CTN5 */
    public function testConsecutiveCommasInFlowSequenceError(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/empty flow entry/');
        $this->strict()->load("[ a, b, c, , ]\n");
    }

    /** yaml-test-suite CVW2: comment glued to comma in flow */
    public function testCommentMustBePrecededByWhitespaceInFlow(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/preceded by whitespace/');
        $this->strict()->load("[ a, b, c,#invalid\n]\n");
    }

    /** yaml-test-suite SU5Z: comment glued to quoted scalar */
    public function testCommentMustBePrecededByWhitespaceAfterScalar(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/preceded by whitespace/');
        $this->strict()->load("key: \"value\"# invalid comment\n");
    }

    /** yaml-test-suite YJV2 */
    public function testBareDashFollowedByFlowIndicatorErrors(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/Bare `-` followed by flow indicator/');
        $this->strict()->load("[-]\n");
    }

    /** yaml-test-suite G5U8 */
    public function testBareDashFollowedByCommaInFlowErrors(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/Bare `-` followed by flow indicator/');
        $this->strict()->load("---\n- [-, -]\n");
    }

    /** yaml-test-suite LHL4: tag suffix must be terminated by whitespace */
    public function testTagMustBeFollowedByWhitespace(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/must be followed by whitespace/');
        $this->strict()->load("---\n!invalid{}tag scalar\n");
    }

    /** yaml-test-suite N782: `---` inside flow content */
    public function testDocumentMarkerInsideFlowErrors(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/Document marker `---` is not allowed inside flow/');
        $this->strict()->load("[\n--- ,\n...\n]\n");
    }

    /** yaml-test-suite RTP8: `... # Suffix` is permitted */
    public function testTrailingCommentOnDocumentEndIsAllowed(): void
    {
        $stream = $this->strict()->load("%YAML 1.2\n---\nDocument\n... # Suffix\n");
        $this->assertCount(1, $stream->getDocuments());
    }
}
