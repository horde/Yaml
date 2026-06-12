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
 * Specific ParseException subtype thrown by the scanner when input
 * is not valid UTF-8.
 *
 * Per Stage 5 §11.4: validation runs upfront via mb_check_encoding;
 * on failure the scanner walks the input to locate the first invalid
 * byte and throws this exception with line and column of that byte
 * before emitting any token.
 *
 * @see /home/i567442/php/horde-development/libraries/yaml/05-parser-strategy-2026-06-12.md §11.4
 * @see /home/i567442/php/horde-development/libraries/yaml/07-error-model-2026-06-12.md §4.1
 */
final class EncodingException extends ParseException {}
