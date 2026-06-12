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

/**
 * Category base for any exception raised during the parse pipeline
 * (Scanner, Parser, Resolver).
 *
 * Concrete subtypes (e.g. EncodingException) extend this class. The
 * category-level catch is:
 *
 *     catch (\Horde\Yaml\Document\ParseException $e) { ... }
 *
 * which matches every parse-pipeline error regardless of subtype.
 *
 * Rich diagnostic fields (line, column, unexpectedToken, partialAst,
 * sourceConsumedUpTo, sourceRemaining) are added in a later phase once
 * the referenced types (Token, YamlStream) exist. Until then the class
 * carries only a message; this is sufficient for the umbrella and
 * category catch contracts to be testable.
 *
 * @author    Ralf Lang <lang@b1-systems.de>
 * @category  Horde
 * @copyright 2008-2026 Horde LLC
 * @license   http://www.horde.org/licenses/bsd BSD
 * @package   Yaml
 */
class ParseException extends RuntimeException implements Exception, LogThrowable
{
    use DetailsTrait;
    use LogTrait;
}
