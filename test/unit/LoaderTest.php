<?php

declare(strict_types=1);

/**
 * Horde\Yaml\Loader test
 *
 * Copyright 2008-2026 Horde LLC (http://www.horde.org/)
 *
 * @author     Mike Naberezny <mike@maintainable.com>
 * @license    http://www.horde.org/licenses/bsd BSD
 * @category   Horde
 * @package    Yaml
 * @subpackage UnitTests
 */

namespace Horde\Yaml\Test\Unit;

use ArrayObject;
use DomainException;
use Horde\Yaml\Exception;
use Horde\Yaml\Loader;
use Horde\Yaml\Test\Helper\LoaderTestMockLoader;
use Horde\Yaml\Test\Helper\TestNotSerializable;
use Horde\Yaml\Test\Helper\TestSerializable;
use Horde\Yaml\Yaml;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XMLParser;

/**
 * @category   Horde
 * @package    Yaml
 * @subpackage UnitTests
 */
#[CoversClass(Yaml::class)]
#[CoversClass(Loader::class)]
class LoaderTest extends TestCase
{
    public function setUp(): void
    {
        Yaml::$loadfunc = 'nonexistant_callback';
    }

    // Loading: load()

    public function testLoad(): void
    {
        $expected = ['foo' => 'bar'];
        $actual = Yaml::load('foo: bar');

        $this->assertEquals($expected, $actual);
    }

    public function testLoadUsesCallbackForParsingIfAvailable(): void
    {
        Yaml::$loadfunc = '\Horde\Yaml\Test\Helper\LoaderTestMockLoader::returnArray';

        $yaml = 'foo';
        $expected = LoaderTestMockLoader::returnArray($yaml);
        $actual   = Yaml::load($yaml);

        $this->assertEquals($expected, $actual);
    }

    public function testLoadThrowsWhenInputStringIsEmpty(): void
    {
        $emptyString = '';
        try {
            Yaml::load($emptyString);
            $this->fail();
        } catch (InvalidArgumentException $e) {
            $this->assertMatchesRegularExpression('/cannot be empty/i', $e->getMessage());
        }
    }

    public function testLoadReturnsEmptyArrayWhenStringCannotBeParsedAsYaml(): void
    {
        $notYaml = 'notyaml';
        $this->assertEquals([], Yaml::load($notYaml));
    }

    // Loading: loadFile()

    public function testLoadFile(): void
    {
        $parsed = Yaml::loadFile($this->fixture('basic'));
        $this->assertEquals('bar', $parsed['foo']);
    }

    public function testLoadFileThrowsWhenFilenameIsEmptyString(): void
    {
        $emptyString = '';
        try {
            Yaml::loadFile($emptyString);
            $this->fail();
        } catch (InvalidArgumentException $e) {
            $this->assertMatchesRegularExpression('/cannot be empty/i', $e->getMessage());
        }
    }

    public function testLoadFileThrowsWhenFilenameCannotBeOpened(): void
    {
        $nonexistant = '/path/to/a/nonexistant/filename';
        try {
            Yaml::loadFile($nonexistant);
            $this->fail();
        } catch (Exception | RuntimeException $e) {
            $this->assertMatchesRegularExpression('/failed to open/i', $e->getMessage());
        }
    }

    // Loading: loadStream()

    public function testLoadStream(): void
    {
        $fp = fopen($this->fixture('basic'), 'rb');
        $parsed = Yaml::loadStream($fp);
        $this->assertEquals('bar', $parsed['foo']);
    }

    public function testLoadStreamThrowsWhenStreamIsNotResource(): void
    {
        $notResource = 42;
        try {
            Yaml::loadStream($notResource);
        } catch (InvalidArgumentException $e) {
            $this->assertMatchesRegularExpression('/stream resource/i', $e->getMessage());
        }
    }

    /**
     * Correctly error on non-stream resource
     *
     * This test becomes less useful
     * as there are few resources left in PHP 8.x
     */
    public function testLoadStreamThrowsWhenStreamIsResourceButNotStream(): void
    {
        $resourceButNotStream = xml_parser_create();
        if (PHP_VERSION_ID > 80000) {
            $this->assertInstanceOf(XMLParser::class, $resourceButNotStream);
        } else {
            $this->assertIsResource($resourceButNotStream);
        }

        try {
            Yaml::loadStream($resourceButNotStream);
        } catch (InvalidArgumentException $e) {
            $this->assertMatchesRegularExpression('/stream resource/i', $e->getMessage());
        }
    }

    public function testLoadStreamUsesCallbackForParsingIfAvailable(): void
    {
        Yaml::$loadfunc = 'Horde\Yaml\Test\Helper\LoaderTestMockLoader::returnArray';

        $stream = fopen('php://memory', 'r');
        $expected = LoaderTestMockLoader::returnArray($stream);
        $actual   = Yaml::loadStream($stream);

        $this->assertEquals($expected, $actual);
    }

    // Parsing: Mappings

    public function testMappingStringValue(): void
    {
        $yaml = "String: Anyone's name, really.";
        $parsed = Yaml::load($yaml);
        $this->assertEquals("Anyone's name, really.", $parsed['String']);
    }

    public function testMappingIntegerValue(): void
    {
        $yaml = 'Int: 13';
        $parsed = Yaml::load($yaml);
        $this->assertEquals(13, $parsed['Int']);
    }

    public function testMappingIntegerZeroValue(): void
    {
        $yaml = 'Zero: 0';
        $parsed = Yaml::load($yaml);
        $this->assertSame(0, $parsed['Zero']);
    }

    public function testMappingFloatValue(): void
    {
        $yaml = 'Float: 5.34';
        $parsed = Yaml::load($yaml);
        $this->assertEquals(5.34, $parsed['Float']);
    }

    public function testMappingBooleanTrue(): void
    {
        $trues = ['TRUE', 'True', 'true', 'On', 'on', '+', 'YES', 'Yes', 'yes'];
        foreach ($trues as $true) {
            $yaml = "True: $true";
            $parsed = Yaml::load($yaml);
            $this->assertTrue($parsed['True'], $true);
        }
    }

    public function testMappingBooleanFalse(): void
    {
        $falses = ['FALSE', 'False', 'false', 'Off', 'off', '-', 'NO', 'No', 'no'];
        foreach ($falses as $false) {
            $yaml = "False: $false";
            $parsed = Yaml::load($yaml);
            $this->assertFalse($parsed['False'], $false);
        }
    }

    public function testMappingNullValue(): void
    {
        $nulls = ['NULL', 'Null', 'null', '', '~'];
        foreach ($nulls as $null) {
            $yaml = "Null: $null";
            $parsed = Yaml::load($yaml);
            $this->assertNull($parsed['Null'], $null);
        }
    }

    public function testMappedValueWithFoldedBlock(): void
    {
        $parsed = Yaml::loadFile($this->fixture('basic'));

        $expected = "There isn't any time for your tricks!\nDo you understand?\n";
        $actual = $parsed['no time'];
        $this->assertEquals($expected, $actual);
    }

    public function testMappedValueWithMapping(): void
    {
        $yaml = "foo:\n"
              . "  bar: baz";
        $expected = ['foo' => ['bar' => 'baz']];
        $actual   = Yaml::load($yaml);
        $this->assertEquals($expected, $actual);
    }

    // Parsing: Types

    public function testFloatExponential(): void
    {
        $this->assertSame(['e' => 10.0], Yaml::load('e: 1.0e+1'));
        $this->assertSame(['e' => 0.1], Yaml::load('e: 1.0e-1'));
    }

    public function testInfinity(): void
    {
        $this->assertSame(['i' => INF], Yaml::load('i: .inf'));
        $this->assertSame(['i' => INF], Yaml::load('i: .Inf'));
        $this->assertSame(['i' => INF], Yaml::load('i: .INF'));
    }

    public function testNegativeInfinity(): void
    {
        $this->assertSame(['i' => -INF], Yaml::load('i: -.inf'));
        $this->assertSame(['i' => -INF], Yaml::load('i: -.Inf'));
        $this->assertSame(['i' => -INF], Yaml::load('i: -.INF'));
    }

    public function testNan(): void
    {
        // NAN !== NAN, but NAN == NAN
        $yaml = Yaml::load('n: .nan');
        $this->assertTrue(is_nan($yaml['n']));
        $yaml = Yaml::load('n: .NaN');
        $this->assertTrue(is_nan($yaml['n']));
        $yaml = Yaml::load('n: .NAN');
        $this->assertTrue(is_nan($yaml['n']));
    }

    public function testArray(): void
    {
        $this->assertEquals(['a' => []], Yaml::load('a: []'));
        $this->assertEquals(['a' => ['a', 'b', 'c']], Yaml::load('a: [a, b, c]'));
        $this->assertEquals(['a' => []], Yaml::load('a: !php/array []'));

        // ArrayObject implements ArrayAccess: OK
        $this->assertEquals(['ao' => new ArrayObject()], Yaml::load('ao: !php/array::ArrayObject []'));
        $this->assertEquals(['ao' => new ArrayObject([1, 2, 3])], Yaml::load('ao: !php/array::ArrayObject [1, 2, 3]'));

        // TestNotSerializable doesn't implement ArrayAccess: FAILURE
        Yaml::$allowedClasses[] = TestNotSerializable::class;
        try {
            Yaml::load('array: !php/array::Horde\Yaml\Test\Helper\TestNotSerializable []');
            $this->fail();
        } catch (Exception | LogicException $e) {
            $this->assertEquals('Horde\Yaml\Test\Helper\TestNotSerializable does not implement ArrayAccess', $e->getMessage());
        }

        // OtherClass doesn't exist: FAILURE
        Yaml::$allowedClasses[] = 'Horde_Yaml_Test_OtherClass';
        try {
            Yaml::load('array: !php/array::Horde_Yaml_Test_OtherClass []');
            $this->fail();
        } catch (Exception | LogicException $e) {
            $this->assertEquals('Horde_Yaml_Test_OtherClass is not defined', $e->getMessage());
        }

        // Disallowed is not whitelisted
        try {
            Yaml::load('array: !php/array::Horde_Yaml_Test_Disallowed []');
            $this->fail();
        } catch (Exception | LogicException $e) {
            $this->assertEquals('Horde_Yaml_Test_Disallowed is not in the list of allowed classes', $e->getMessage());
        }
    }

    public function testHash(): void
    {
        $this->assertEquals(['a' => []], Yaml::load('a: {}'));
        $this->assertEquals(['a' => ['a', 'b', 'c']], Yaml::load('a: {0: a, 1: b, 2: c}'));

        // ArrayObject implements ArrayAccess: OK
        $this->assertEquals(['ao' => new ArrayObject()], Yaml::load('ao: !php/hash::ArrayObject {}'));
        $this->assertEquals(
            ['ao' => new ArrayObject(['a' => 1, 'b' => 2, 3 => 3, 4 => 'd', 'e' => 5])],
            Yaml::load('ao: !php/hash::ArrayObject {a: 1, b: 2, 3: 3, 4: d, e: 5}')
        );

        // TestNotSerializable doesn't implement ArrayAccess: FAILURE
        Yaml::$allowedClasses[] = TestNotSerializable::class;
        try {
            Yaml::load('hash: !php/hash::Horde\Yaml\Test\Helper\TestNotSerializable {}');
            $this->fail();
        } catch (Exception | LogicException $e) {
            $this->assertEquals('Horde\Yaml\Test\Helper\TestNotSerializable does not implement ArrayAccess', $e->getMessage());
        }

        // OtherClass doesn't exist: FAILURE
        Yaml::$allowedClasses[] = 'Horde_Yaml_Test_OtherClass';
        try {
            Yaml::load('hash: !php/hash::Horde_Yaml_Test_OtherClass {}');
            $this->fail();
        } catch (Exception | LogicException $e) {
            $this->assertEquals('Horde_Yaml_Test_OtherClass is not defined', $e->getMessage());
        }

        // Disallowed is not whitelisted
        try {
            Yaml::load('hash: !php/hash::Horde_Yaml_Test_Disallowed []');
            $this->fail();
        } catch (Exception | LogicException $e) {
            $this->assertEquals('Horde_Yaml_Test_Disallowed is not in the list of allowed classes', $e->getMessage());
        }
    }

    public function testSerializable(): void
    {
        Yaml::$allowedClasses[] = TestSerializable::class;
        $result = Yaml::load('obj: >
  !php/object::Horde\Yaml\Test\Helper\TestSerializable
  string');

        $this->assertInstanceOf(TestSerializable::class, $result['obj']);
        $this->assertSame('string', $result['obj']->test());

        // TestNotSerializable doesn't implement Serializable: FAILURE
        Yaml::$allowedClasses[] = TestNotSerializable::class;
        try {
            Yaml::load('o: !php/object::Horde\Yaml\Test\Helper\TestNotSerializable string');
            $this->fail();
        } catch (Exception | LogicException $e) {
            $this->assertEquals('Horde\Yaml\Test\Helper\TestNotSerializable does not implement Serializable', $e->getMessage());
        }

        // Disallowed is not whitelisted
        try {
            Yaml::load('o: !php/object::Horde_Yaml_Test_Disallowed string');
            $this->fail();
        } catch (Exception | LogicException $e) {
            $this->assertEquals('Horde_Yaml_Test_Disallowed is not in the list of allowed classes', $e->getMessage());
        }
    }

    // Parsing: Sequences

    public function testSequenceBasic(): void
    {
        $yaml = "- PHP Class\n"
              . "- Basic YAML Loader\n"
              . "- Very Basic YAML Dumper";
        $parsed = Yaml::load($yaml);

        $this->assertEquals("PHP Class", $parsed[0]);
        $this->assertEquals("Basic YAML Loader", $parsed[1]);
        $this->assertEquals("Very Basic YAML Dumper", $parsed[2]);
    }

    public function testSequenceOfSequence(): void
    {
        $yaml = "-\n"
              . "  - YAML is so easy to learn.\n"
              . "  - Your config files will never be the same.";
        $parsed = Yaml::load($yaml);

        $expected = ["YAML is so easy to learn.",
                          "Your config files will never be the same.", ];
        $actual = $parsed[0];
        $this->assertEquals($expected, $actual);
    }

    public function testSequenceofMappings(): void
    {
        $yaml = "-\n"
              . "  cpu: 1.5ghz\n"
              . "  ram: 1 gig\n"
              . "  os : os x 10.4.1";
        $parsed = Yaml::load($yaml);

        $expected = ["cpu" => "1.5ghz", "ram" => "1 gig", "os" => "os x 10.4.1"];
        $actual = $parsed[0];
        $this->assertEquals($expected, $actual, 'Sequence of mappings');
    }

    public function testMappedSequence(): void
    {
        $yaml = "domains:\n"
              . "  - yaml.org\n"
              . "  - php.net\n";
        $parsed = Yaml::load($yaml);

        $expected = ["yaml.org", "php.net"];
        $actual = $parsed['domains'];
        $this->assertEquals($expected, $actual);
    }

    public function testSequenceWithMappedValuesStartingWithCaps(): void
    {
        $yaml = "- program: Adium\n"
              . "  platform: OS X\n"
              . "  type: Chat Client\n";
        $parsed = Yaml::load($yaml);

        $expected = ["program" => "Adium", "platform" => "OS X", "type" => "Chat Client"];
        $actual = $parsed[0];
        $this->assertEquals($expected, $actual);
    }

    // Parsing: References

    public function testReferencesAssignment1(): void
    {
        $parsed = Yaml::loadFile($this->fixture('references'));

        $expected = ['Perl', 'Python', 'PHP', 'Ruby'];
        $actual = $parsed['dynamic languages'];
        $this->assertEquals($expected, $actual);
    }

    public function testReferencesAssignment2(): void
    {
        $parsed = Yaml::loadFile($this->fixture('references'));

        $expected = ['C/C++', 'Java'];
        $actual = $parsed['compiled languages'];
        $this->assertEquals($expected, $actual);
    }

    public function testReferenceUsage(): void
    {
        $parsed = Yaml::loadFile($this->fixture('references'));

        $assignment1 = ['Perl', 'Python', 'PHP', 'Ruby'];
        $assignment2 = ['C/C++', 'Java'];

        $expected = [$assignment1, $assignment2];
        $actual = $parsed['all languages'];

        $this->assertEquals($expected, $actual);
    }

    // Parsing: Inlines

    public function testInlinedSequence(): void
    {
        $yaml = '- [One, Two, Three, Four]';
        $parsed = Yaml::load($yaml);

        $expected = ["One", "Two", "Three", "Four"];
        $actual = $parsed[0];
        $this->assertEquals($expected, $actual);
    }

    public function testInlineSequenceWithQuotes(): void
    {
        $yaml = "- ['complex: string', 'another [string]']";
        $parsed = Yaml::load($yaml);

        $expected = ['complex: string', 'another [string]'];
        $actual = $parsed[0];
        $this->assertEquals($expected, $actual);
    }

    public function testInlineSequenceOneDeep(): void
    {
        $yaml = '- [One, [Two, And, Three], Four, Five]';
        $parsed = Yaml::load($yaml);

        $expected = ["One", ["Two", "And", "Three"], "Four", "Five"];
        $actual = $parsed[0];
        $this->assertEquals($expected, $actual);
    }

    public function testInlineSequenceOneDeepWithQuotes(): void
    {
        $yaml = '- [a, [\'1\', "2"], b]';
        $parsed = Yaml::load($yaml);

        $expected = ['a', ['1', '2'], 'b'];
        $actual = $parsed[0];
        $this->assertEquals($expected, $actual);
    }

    public function testInlineSequenceTwoDeep(): void
    {
        $yaml = '- [This, [Is, Getting, [Ridiculous, Guys]], Seriously, [Show, Mercy]]';
        $parsed = Yaml::load($yaml);

        $expected = ["This", ["Is", "Getting", ["Ridiculous", "Guys"]],
                                            "Seriously", ["Show", "Mercy"], ];
        $actual = $parsed[0];
        $this->assertEquals($expected, $actual);
    }

    public function testInlineSequenceWhenEmpty(): void
    {
        $yaml = '- []';
        $parsed = Yaml::load($yaml);

        $expected = [];
        $actual = $parsed[0];
        $this->assertEquals($actual, $expected);
    }

    public function testInlineSequenceWhenEmptyWithWhitespace(): void
    {
        $yaml = "- [ \t]";
        $parsed = Yaml::load($yaml);

        $expected = [];
        $actual = $parsed[0];
        $this->assertEquals($actual, $expected);
    }

    public function testInlineMapping(): void
    {
        $yaml = '- {name: chris, age: young, brand: lucky strike}';
        $parsed = Yaml::load($yaml);

        $expected = ["name" => "chris", "age" => "young", "brand" => "lucky strike"];
        $actual = $parsed[0];
        $this->assertEquals($expected, $actual);
    }

    public function testInlineMappingWhenEmpty(): void
    {
        $yaml = '- {}';
        $parsed = Yaml::load($yaml);

        $expected = [];
        $actual = $parsed[0];
        $this->assertEquals($expected, $actual);
    }

    public function testInlineMappingWhenEmptyWithWhitespace(): void
    {
        $yaml = "- { \t}";
        $parsed = Yaml::load($yaml);

        $expected = [];
        $actual = $parsed[0];
        $this->assertEquals($expected, $actual);
    }

    public function testInlineMappingWithQuotesInlinedInSequence(): void
    {
        $yaml = '- {name: "Foo, Bar\'s", age: 20}';
        $parsed = Yaml::load($yaml);

        $expected = ['name' => "Foo, Bar's", 'age' => 20];
        $actual = $parsed[0];

        $this->assertEquals($expected, $actual);
    }


    public function testInlineMappingWithQuotesInlinedInMapping(): void
    {
        $yaml = 'outer: { inner1: "foo bar", inner2: \'baz qux\' }';

        $expected = ['outer' => ['inner1' => "foo bar", 'inner2' => "baz qux"]];
        $actual = Yaml::load($yaml);
        $this->assertEquals($expected, $actual);
    }

    public function testInlineMappingOneDeep(): void
    {
        $yaml = "- {name: mark, age: older than chris, brand: [marlboro, lucky strike]}";
        $parsed = Yaml::load($yaml);

        $expected = ["name" => "mark", "age" => "older than chris",
                                             "brand" => ["marlboro", "lucky strike"], ];
        $actual = $parsed[0];
        $this->assertEquals($expected, $actual);
    }

    // Parsing: Quotes

    public function testQuotesCanBeEscaped(): void
    {
        $yaml = "- one'apostrophe on line\n"
              . "- two'apostrophes' on line\n"
              . "- one\"quote on line\n"
              . "- two\"quotes\" on line\n";
        $parsed = Yaml::load($yaml);

        $this->assertEquals(
            "one'apostrophe on line",
            $parsed[0]
        );

        $this->assertEquals(
            "two'apostrophes' on line",
            $parsed[1]
        );

        $this->assertEquals(
            'one"quote on line',
            $parsed[2]
        );

        $this->assertEquals(
            'two"quotes" on line',
            $parsed[3]
        );
    }

    public function testQuotesCanBeUsedForComplexKeys(): void
    {
        $yaml = '"if: you\'d": like';
        $parsed = Yaml::load($yaml);

        $this->assertEquals("like", $parsed["if: you'd"]);
    }

    public function testQuotesCanBeEmptyWhenQuotes(): void
    {
        $yaml = 'empty: ""';
        $parsed = Yaml::load($yaml);

        $this->assertSame('', $parsed['empty']);
    }

    public function testQuotesCanBeEmptyWhenApostrophes(): void
    {
        $yaml = "empty: ''";
        $parsed = Yaml::load($yaml);

        $this->assertSame('', $parsed['empty']);
    }

    public function testWhitespaceBetweenQuotesIsPreserved(): void
    {
        $yaml = 'empty: "   "';
        $parsed = Yaml::load($yaml);

        $this->assertSame('   ', $parsed['empty']);
    }

    public function testWhitespaceBetweenApostrophesIsPreserved(): void
    {
        $yaml = "empty: '   '";
        $parsed = Yaml::load($yaml);

        $this->assertSame('   ', $parsed['empty']);
    }

    // Parsing: Keys

    public function testKeyAsNumeric(): void
    {
        // Added in Spyc .2
        $yaml = '1040: Ooo, a numeric key! # And working comments? Wow!';
        $parsed = Yaml::load($yaml);

        $this->assertEquals("Ooo, a numeric key!", $parsed[1040]);
    }

    // Tab Detection

    public function testThrowsAnExceptionWhenFirstCharacterOfLineIsTab(): void
    {
        try {
            Yaml::load("\tfoo: bar");
            $this->fail();
        } catch (Exception $e) {
            $this->assertMatchesRegularExpression('/indent contains a tab/i', $e->getMessage());
        } catch (DomainException $e) {
            $this->assertMatchesRegularExpression('/indent contains a tab/i', $e->getMessage());
        }
    }

    public function testThrowsExceptionWhenLineIndentContainsTab(): void
    {
        try {
            Yaml::load(" \tfoo: bar");
            $this->fail();
        } catch (Exception | DomainException $e) {
            $this->assertMatchesRegularExpression('/indent contains a tab/i', $e->getMessage());
        }
    }

    public function testDoesNotThrowOnAnEmptyLineWithTabsOrSpaces(): void
    {
        /**
         * Workaround: There is no specific method to show no exception is
         * raised. Just running code without any asserts will get the unit
         * test marked "risky" by phpunit > 6
         */
        $exception = null;
        try {
            Yaml::load(" ");
            Yaml::load("\t");
            Yaml::load(" \t");
            Yaml::load("\t ");
        } catch (\Exception $e) {
            $exception = $e;
        }
        $this->assertNull($exception);
    }

    // Comments

    public function testCommentOnEmptyLine(): void
    {
        $yaml = "# foo\nbar: baz";
        $expected = ['bar' => 'baz'];
        $actual = Yaml::load($yaml);
        $this->assertEquals($expected, $actual);
    }

    public function testCommentAtEndOfLine(): void
    {
        $yaml = 'foo: bar # baz';
        $parsed = Yaml::load($yaml);

        $expected = 'bar';
        $actual = $parsed['foo'];
        $this->assertEquals($expected, $actual);
    }

    public function testDecoyCommentEmbeddedInQuotes(): void
    {
        $yaml = 'foo: "bar # baz"';
        $parsed = Yaml::load($yaml);

        $expected = 'bar # baz';
        $actual = $parsed['foo'];
        $this->assertEquals($expected, $actual);
    }

    public function testDecoyCommentEmbeddedInQuotesAndEndOfLineComment(): void
    {
        $yaml = 'foo: "bar # baz" # qux';
        $parsed = Yaml::load($yaml);

        $expected = 'bar # baz';
        $actual = $parsed['foo'];
        $this->assertEquals($expected, $actual);
    }

    public function testDecoyCommentEmbeddedInApostrophes(): void
    {
        $yaml = "foo: 'bar # baz'";
        $parsed = Yaml::load($yaml);

        $expected = 'bar # baz';
        $actual = $parsed['foo'];
        $this->assertEquals($expected, $actual);
    }

    public function testDecoyCommentEmbeddedInApostrophesAndEndOfLineVersion(): void
    {
        $yaml = "foo: 'bar # baz' # qux";
        $parsed = Yaml::load($yaml);

        $expected = 'bar # baz';
        $actual = $parsed['foo'];
        $this->assertEquals($expected, $actual);
    }

    // Chomping

    public function testChompClip(): void
    {
        $parsed = Yaml::loadFile($this->fixture('basic'));
        $this->assertEquals("Line 1\nLine 2\n", $parsed['chompClip']);
    }

    public function testChompStrip(): void
    {
        $parsed = Yaml::loadFile($this->fixture('basic'));
        $this->assertEquals("Line 1\nLine 2", $parsed['chompStrip']);
    }

    public function testChompKeep(): void
    {
        $parsed = Yaml::loadFile($this->fixture('basic'));
        $this->assertEquals("Line 1\nLine 2\n\n\n", $parsed['chompKeep']);
    }

    // Misc

    public function testComplexParse(): void
    {
        $yaml = "databases:\n"
              . "  - name: spartan\n"
              . "    notes:\n"
              . "      - Needs to be backed up\n"
              . "      - Needs to be normalized\n"
              . "    type: mysql\n";
        $expected = ['databases' => [['name' => 'spartan',
                                                     'notes' => ['Needs to be backed up',
                                                                      'Needs to be normalized', ],
                                                     'type' => 'mysql', ]]];
        $actual = Yaml::load($yaml);
        $this->assertEquals($expected, $actual);

        $yaml = <<<YAML
            authors:
              -
                name: Gunnar Wrobel
                user: wrobel
                email: p@rdus.de
                active: true
                role: lead
            dependencies:
              required:
                php: ^5
                pear:
                  pear.php.net/Console_Getopt: '*'
              optional:
                pear:
                  pecl.php.net/PECL: '*'
            YAML;
        $expected = [
            'authors' => [
                [
                    'name' => 'Gunnar Wrobel',
                    'user' => 'wrobel',
                    'email' => 'p@rdus.de',
                    'active' => true,
                    'role' => 'lead',
                ],
            ],
            'dependencies' => [
                'required' => [
                    'php' => '^5',
                    'pear' => [
                        'pear.php.net/Console_Getopt' => '*',
                    ],
                ],
                'optional' => [
                    'pear' => [
                        'pecl.php.net/PECL' => '*',
                    ],
                ],
            ],
        ];
        $actual = Yaml::load($yaml);
        $this->assertEquals($expected, $actual);
    }

    public function testUnliteralizing(): void
    {
        $parsed = Yaml::loadFile($this->fixture('basic'));
        $expected = "Line #1\nLine #2\n";
        $this->assertEquals($expected, $parsed['literalStringTest']);
    }

    public function testUnfolding(): void
    {
        $parsed = Yaml::loadFile($this->fixture('basic'));
        $expected = "Line 1 Line 2\n";
        $this->assertEquals($expected, $parsed['foldedStringTest']);
        $expected = "The Horde Application Framework is a flexible, modular, general-purpose web application framework written in PHP. It provides an extensive array of components that are targeted at the common problems and tasks involved in developing modern web applications.\n";
        $this->assertEquals($expected, $parsed['description']);
    }

    // Test Helpers

    public function fixture(string $name): string
    {
        return __DIR__ . "/../fixtures/{$name}.yml";
    }
}
