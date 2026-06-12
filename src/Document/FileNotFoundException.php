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
 * Thrown by YamlFileLoader when the requested file does not exist or
 * cannot be read.
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/07-error-model-2026-06-12.md §4.3
 */
final class FileNotFoundException extends IoException {}
