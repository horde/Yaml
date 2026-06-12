<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Document\TagHandlers;

use Horde\Yaml\Document\Node\Node;
use Horde\Yaml\Document\Node\ScalarNode;
use Horde\Yaml\Document\Node\ScalarStyle;
use Horde\Yaml\Document\TagHandler;
use Horde\Yaml\Document\TagHandlerException;

/**
 * Handler for `!!binary`: base64-decoded byte payloads.
 *
 * Whitespace inside the lexical value is stripped before decoding.
 * Decoded bytes are returned as a PHP string. Emission base64-encodes
 * the bytes; line wrapping is left to the emitter / scalar style
 * (literal block scalars naturally wrap, plain scalars don't).
 */
final class BinaryTagHandler implements TagHandler
{
    public const TAG = 'tag:yaml.org,2002:binary';

    public function tag(): string
    {
        return self::TAG;
    }

    public function fromYaml(ScalarNode $node): mixed
    {
        $source = (string) $node;
        // Strip all whitespace (newlines, spaces, tabs).
        $packed = preg_replace('/\s+/', '', $source) ?? '';
        if ($packed === '') {
            return '';
        }
        $decoded = base64_decode($packed, true);
        if ($decoded === false) {
            throw new TagHandlerException(
                'Invalid !!binary base64 payload',
            );
        }
        return $decoded;
    }

    public function toYaml(mixed $value): Node
    {
        if (!is_string($value)) {
            throw new TagHandlerException(
                'BinaryTagHandler expects string bytes, got '
                    . get_debug_type($value),
            );
        }
        $encoded = base64_encode($value);
        return new ScalarNode(
            value: $encoded,
            style: ScalarStyle::Plain,
            tag: '!!binary',
        );
    }
}
