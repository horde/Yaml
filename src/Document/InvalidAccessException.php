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
 * Thrown when subscripting a YamlDocument whose root cannot accept
 * the access (e.g. a scalar root being subscripted).
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/07-error-model-2026-06-12.md §4.4
 */
final class InvalidAccessException extends StructuralException {}
