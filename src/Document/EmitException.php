<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Document;

use Horde\Exception\DetailsTrait;
use Horde\Exception\LogThrowable;
use Horde\Exception\LogTrait;
use Horde\Yaml\Document\Node\Node;
use RuntimeException;

/**
 * Category base for any exception raised by the emitter while turning
 * an AST back into bytes.
 *
 * Carries a reference to the offending node so callers can inspect
 * via $exception->node->line(), $exception->node->parent(), etc.
 * Synthesized nodes return 0 for line/column, which is itself useful
 * diagnostic info ("this came from API construction, not source").
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/07-error-model-2026-06-12.md §4.2
 */
class EmitException extends RuntimeException implements Exception, LogThrowable
{
    use DetailsTrait;
    use LogTrait;

    public function __construct(
        string $message,
        public readonly ?Node $node = null,
    ) {
        parent::__construct($message);
    }
}
