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
use RuntimeException;
use Throwable;

/**
 * Category base for filesystem and resource I/O failures.
 *
 * Carries the path or resource identifier when applicable.
 *
 */
class IoException extends RuntimeException implements Exception, LogThrowable
{
    use DetailsTrait;
    use LogTrait;

    public function __construct(
        string $message,
        public readonly string $path = '',
        ?Throwable $cause = null,
    ) {
        parent::__construct($message, 0, $cause);
    }
}
