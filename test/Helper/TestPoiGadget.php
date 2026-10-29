<?php

/**
 * Horde_Yaml test helpers
 *
 * @author     Mike Naberezny <mike@maintainable.com>
 * @license    http://www.horde.org/licenses/bsd BSD
 * @category   Horde
 * @package    Yaml
 * @subpackage UnitTests
 */

namespace Horde\Yaml\Test\Helper;

/**
 * PHP object injection gadget detector.
 *
 * This class is deliberately NOT in the YAML loader's whitelist. Its
 * only purpose is to record whether PHP ever instantiated it during an unserialize() call.
 * A secure loader must never let this class wake up when it is smuggled inside the
 * serialized payload of a whitelisted carrier class.
 *
 * @category   Horde
 * @package    Yaml
 * @subpackage UnitTests
 */
class TestPoiGadget
{
    /**
     * Set to true by __wakeup() if this gadget is ever instantiated
     * through unserialize(). Reset to false before each assertion.
     *
     * @var bool
     */
    public static $awoken = false;

    public function __wakeup()
    {
        self::$awoken = true;
    }
}
