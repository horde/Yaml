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
 * Thrown by ArrayAccess::offsetSet/offsetUnset on document-layer
 * containers. ArrayAccess is reads-only; writes
 * go through named methods (setEntry, addEntry, etc.).
 *
 */
final class UnsupportedOperationException extends StructuralException {}
