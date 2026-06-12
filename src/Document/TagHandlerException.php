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
 * A TagHandler refused to coerce a value (lexically or by type).
 *
 * Subclass of ParseException so it surfaces during load through the
 * usual error model, but distinct so callers can catch handler
 * failures specifically (e.g. to fall back to raw string).
 */
class TagHandlerException extends ParseException {}
