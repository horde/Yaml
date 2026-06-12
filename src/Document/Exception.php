<?php

declare(strict_types=1);

/**
 * Copyright 2008-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 */

namespace Horde\Yaml\Document;

use Horde\Exception\HordeThrowable;

/**
 * Umbrella marker interface for every exception thrown by the Horde\Yaml
 * document layer.
 *
 * Every exception in this namespace implements this interface. Callers
 * wanting to catch any document-layer error use:
 *
 *     catch (\Horde\Yaml\Document\Exception $e) { ... }
 *
 * The interface extends Horde's HordeThrowable, so framework-wide catch
 * idioms (`catch (HordeThrowable)`) also match document-layer exceptions.
 *
 * @author    Ralf Lang <lang@b1-systems.de>
 * @category  Horde
 * @copyright 2008-2026 Horde LLC
 * @license   http://www.horde.org/licenses/bsd BSD
 * @package   Yaml
 */
interface Exception extends HordeThrowable {}
