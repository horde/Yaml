<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Document;

/**
 * Thrown by MapNode::addEntry when the key already exists.
 *
 */
final class DuplicateKeyException extends StructuralException
{
    public function __construct(string $message, public readonly string $key)
    {
        parent::__construct($message);
    }
}
