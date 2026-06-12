<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Document\TagHandlers;

use DateTimeImmutable;
use DateTimeZone;
use Horde\Yaml\Document\Node\Node;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\ScalarStyle;
use Horde\Yaml\Document\TagHandler;
use Horde\Yaml\Document\TagHandlerException;
use Exception;

/**
 * Handler for the YAML 1.1-era `!!timestamp` tag, plus an optional
 * implicit-recognition mode that also resolves bare ISO 8601
 * date/datetime strings to DateTimeImmutable.
 *
 * The handler claims `tag:yaml.org,2002:timestamp` (the canonical
 * URI form). When the tag handle map remaps `!!`, this handler is
 * not invoked for `!!timestamp`. That's correct per spec.
 *
 * Recognised lexical forms (subset of YAML 1.1 §10.5.2):
 *
 *   - YYYY-MM-DD                        (date)
 *   - YYYY-MM-DDtHH:MM:SS               (datetime, naive)
 *   - YYYY-MM-DD HH:MM:SS               (datetime, naive)
 *   - YYYY-MM-DDTHH:MM:SS.fff           (with fractional seconds)
 *   - YYYY-MM-DDTHH:MM:SSZ              (UTC)
 *   - YYYY-MM-DDTHH:MM:SS+HH:MM         (offset)
 *   - YYYY-MM-DDTHH:MM:SS-HHMM          (offset, no colon)
 *
 * Naive datetimes are interpreted as UTC. Per Stage 12 §V the
 * returned type is DateTimeImmutable (not DateTime).
 */
final class TimestampTagHandler implements TagHandler
{
    public const TAG = 'tag:yaml.org,2002:timestamp';

    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';
    private const DATETIME_PATTERN
        = '/^\d{4}-\d{2}-\d{2}[Tt ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?$/';

    public function tag(): string
    {
        return self::TAG;
    }

    public function fromYaml(ScalarNode $node): mixed
    {
        $source = trim((string) $node);
        return self::parseTimestamp($source);
    }

    public function toYaml(mixed $value): Node
    {
        if (!$value instanceof DateTimeImmutable) {
            throw new TagHandlerException(
                'TimestampTagHandler expects DateTimeImmutable, got '
                    . get_debug_type($value),
            );
        }
        // Canonical: ISO 8601 with seconds and timezone. Use offset
        // form (+00:00) for UTC rather than `Z` to keep the round
        // trip predictable.
        $formatted = $value->format('Y-m-d\\TH:i:sP');
        return new ScalarNode(
            value: $formatted,
            style: ScalarStyle::Plain,
            tag: '!!timestamp',
        );
    }

    /**
     * Recognise a string as an ISO 8601 timestamp; return null on
     * mismatch. Used by the resolver's optional implicit mode.
     */
    public static function isTimestampLike(string $source): bool
    {
        return (bool) (
            preg_match(self::DATE_PATTERN, $source)
            || preg_match(self::DATETIME_PATTERN, $source)
        );
    }

    /**
     * Parse a timestamp lexical form into DateTimeImmutable.
     *
     * @throws TagHandlerException on malformed input.
     */
    public static function parseTimestamp(string $source): DateTimeImmutable
    {
        if (preg_match(self::DATE_PATTERN, $source)) {
            $dt = DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $source,
                new DateTimeZone('UTC'),
            );
            if ($dt === false) {
                throw new TagHandlerException(
                    'Invalid !!timestamp date: "' . $source . '"',
                );
            }
            return $dt;
        }
        if (!preg_match(self::DATETIME_PATTERN, $source)) {
            throw new TagHandlerException(
                'Invalid !!timestamp lexical form: "' . $source . '"',
            );
        }
        // Normalise: lowercase `t` to `T`, replace space with `T`.
        $normalised = str_replace([' ', 't'], ['T', 'T'], $source);
        // Naive timestamps (no zone) get interpreted as UTC.
        $hasZone = (bool) preg_match(
            '/(Z|[+-]\d{2}:?\d{2})$/',
            $normalised,
        );
        if (!$hasZone) {
            $normalised .= 'Z';
        }
        try {
            return new DateTimeImmutable($normalised);
        } catch (Exception $e) {
            throw new TagHandlerException(
                'Could not parse !!timestamp "' . $source . '": ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }
}
