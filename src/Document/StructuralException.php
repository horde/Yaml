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
use LogicException;

/**
 * Category base for programmer-error exceptions originating from API
 * misuse on the document model: invalid arguments, missing keys
 * accessed in strict mode, ArrayAccess writes (which are unsupported),
 * out-of-range sequence indices, etc.
 *
 * No source position info; stack traces are the diagnostic for
 * structural errors.
 *
 * @author    Ralf Lang <lang@b1-systems.de>
 * @category  Horde
 * @copyright 2008-2026 Horde LLC
 * @license   http://www.horde.org/licenses/bsd BSD
 * @package   Yaml
 */
class StructuralException extends LogicException implements Exception, LogThrowable
{
    use DetailsTrait;
    use LogTrait;
}
