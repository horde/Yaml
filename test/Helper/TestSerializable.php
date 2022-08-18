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

use Serializable;
use Exception;

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
        if (null === $string) {
            throw new Exception('This is not supposed to be called implicitly');
        }

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

    public function __serialize(): array
    {
        return [$this->string];
    }

    public function __unserialize(array $serialized): void
    {
        $this->string = array_pop($serialized);
    }

    public function test()
    {
        return $this->string;
    }
}
