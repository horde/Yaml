<?php
/**
 * Setup autoloading for the tests.
 *
 * Copyright 2011-2017 Horde LLC (http://www.horde.org/)
 *
 * @category   Horde
 * @package    Yaml
 * @subpackage UnitTests
 * @author     Jan Schneider <jan@horde.org>
 * @license    http://www.horde.org/licenses/bsd BSD
 */

if ( !class_exists('Horde\Yaml\Helper\TestSerializable') ) {
     require_once __DIR__ . '/Helper/TestSerializable.php';
     require_once __DIR__ . '/Helper/TestNotSerializable.php';
}
