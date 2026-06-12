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
 * Legacy `!php/object` tag handler.
 *
 * `!php/object` is a Horde-specific tag inherited from the legacy
 * `Horde_Yaml` loader. Its lexical value is a serialized PHP value
 * (output of `serialize()`); the handler unserialises it.
 *
 * **Security boundary.** `unserialize()` with arbitrary class names
 * is a deserialisation gadget invitation. This handler refuses to
 * instantiate without an explicit allow-list of class names. Calling
 * code MUST list the classes it expects to see; passing an empty
 * list means "only built-in scalar wrappers" (PHP's behaviour with
 * `allowed_classes => []`).
 *
 * The handler is NOT in the default registry. Callers (the legacy
 * `Horde_Yaml::loadFile()` shim, integration tests with explicit
 * intent) wire it up themselves with the allow-list they trust.
 */
final class PhpObjectTagHandler implements TagHandler
{
    public const TAG = '!php/object';

    /**
     * @param list<class-string> $allowedClasses Classes that may be
     *     instantiated during unserialization. An empty list means
     *     no classes are allowed (only `__PHP_Incomplete_Class`
     *     placeholders). Use this to bound the security exposure.
     */
    public function __construct(
        private readonly array $allowedClasses = [],
    ) {}

    public function tag(): string
    {
        return self::TAG;
    }

    public function fromYaml(ScalarNode $node): mixed
    {
        $payload = (string) $node;
        if ($payload === '') {
            throw new TagHandlerException(
                '!php/object payload is empty',
            );
        }
        $previous = error_reporting(0);
        try {
            $result = @unserialize(
                $payload,
                ['allowed_classes' => $this->allowedClasses],
            );
        } finally {
            error_reporting($previous);
        }
        if ($result === false && $payload !== serialize(false)) {
            throw new TagHandlerException(
                '!php/object failed to unserialise; payload may be malformed '
                    . 'or its class is not in the allow-list',
            );
        }
        return $result;
    }

    public function toYaml(mixed $value): Node
    {
        if (!is_object($value)) {
            throw new TagHandlerException(
                'PhpObjectTagHandler expects object, got ' . get_debug_type($value),
            );
        }
        return new ScalarNode(
            value: serialize($value),
            style: ScalarStyle::SingleQuoted,
            tag: self::TAG,
        );
    }
}
