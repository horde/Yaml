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
namespace Horde\Yaml\Helper;
use \Serializable;
use \Exception;
/**
 * @category   Horde
 * @package    Yaml
 * @subpackage UnitTests
 */
class TestSerializable implements Serializable

{
    private $string = null;

    public function __construct($string = null)
    {
        if (null === $string)
            throw new Exception('This is not supposed to be called implicitly');

        $this->string = $string;
    }

    public function serialize()
    {
        return $this->string;
    }

    public function unserialize($serialized)
    {
        $this->string = $serialized;
    }

    public function test()
    {
        return $this->string;
    }

}
