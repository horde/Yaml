<?php
/**
 * Horde\Yaml\Helper\LoaderTestMockLoader
 *
 * @author     Mike Naberezny <mike@maintainable.com>
 * @license    http://www.horde.org/licenses/bsd BSD
 * @category   Horde
 * @package    Yaml
 * @subpackage UnitTests
 */
namespace Horde\Yaml\Helper;

/**
 * Used to test Horde_Yaml::$loadfunc callback.
 *
 * @package    Yaml
 * @subpackage UnitTests
 */
class LoaderTestMockLoader
{
    public static function returnArray($yaml)
    {
        return array('loaded');
    }

    public static function returnFalse($yaml)
    {
        return false;
    }

}
