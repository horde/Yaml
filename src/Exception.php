<?php

declare(strict_types=1);

/**
 * Horde YAML package
 *
 * This package is heavily inspired by the Spyc PHP YAML
 * implementation (http://spyc.sourceforge.net/), and portions are
 * copyright 2005-2006 Chris Wanstrath.
 *
 * @author   Chris Wanstrath <chris@ozmm.org>
 * @author   Chuck Hagenbuch <chuck@horde.org>
 * @author   Mike Naberezny <mike@maintainable.com>
 * @license  http://www.horde.org/licenses/bsd BSD
 * @category Horde
 * @package  Yaml
 */

namespace Horde\Yaml;

use Horde_Exception_LastError;

/**
 * Exception class for exceptions thrown by Horde\Yaml
 *
 * Copyright 2008-2026 Horde LLC (http://www.horde.org/)
 *
 * @category Horde
 * @package  Yaml
 */
class Exception extends Horde_Exception_LastError {}
