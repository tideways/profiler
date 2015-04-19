<?php

namespace Tideways\Profiler;

class BacktraceConverterTest extends \PHPUnit_Framework_TestCase
{
    public function testConvertCurrentBacktrace()
    {
        $trace = BacktraceConverter::convertToString(debug_backtrace());

        $this->assertContains('Tideways\Profiler\BacktraceConverterTest->testConvertCurrentBacktrace', $trace);
    }

    public function testHardcodedBacktrace()
    {
        $backtrace = array (
                0 => array (
                    'function' => 'testHardcodedBacktrace',
                    'class' => 'Tideways\\Profiler\\BacktraceConverterTest',
                    'type' => '->',
                    ),
                1 => array (
                    'file' => 'phar:///usr/local/bin/phpunit/phpunit/Framework/TestCase.php',
                    'line' => 860,
                    'function' => 'invokeArgs',
                    'class' => 'ReflectionMethod',
                    'type' => '->',
                    ),
                2 => array (
                    'file' => 'phar:///usr/local/bin/phpunit/phpunit/Framework/TestCase.php',
                    'line' => 737,
                    'function' => 'runTest',
                    'class' => 'PHPUnit_Framework_TestCase',
                    'type' => '->',
                    ),
                3 => array (
                    'file' => 'phar:///usr/local/bin/phpunit/phpunit/Framework/TestResult.php',
                    'line' => 609,
                    'function' => 'runBare',
                    'class' => 'PHPUnit_Framework_TestCase',
                    'type' => '->',
                    ),
                4 => array (
                        'file' => 'phar:///usr/local/bin/phpunit/phpunit/Framework/TestCase.php',
                        'line' => 693,
                        'function' => 'run',
                        'class' => 'PHPUnit_Framework_TestResult',
                        'type' => '->',
                        ),
                5 => array (
                        'file' => 'phar:///usr/local/bin/phpunit/phpunit/Framework/TestSuite.php',
                        'line' => 716,
                        'function' => 'run',
                        'class' => 'PHPUnit_Framework_TestCase',
                        'type' => '->',
                        ),
                6 => array (
                        'file' => 'phar:///usr/local/bin/phpunit/phpunit/TextUI/TestRunner.php',
                        'line' => 398,
                        'function' => 'run',
                        'class' => 'PHPUnit_Framework_TestSuite',
                        'type' => '->',
                        ),
                7 => array (
                        'file' => 'phar:///usr/local/bin/phpunit/phpunit/TextUI/Command.php',
                        'line' => 152,
                        'function' => 'doRun',
                        'class' => 'PHPUnit_TextUI_TestRunner',
                        'type' => '->',
                        ),
                8 => array (
                        'file' => 'phar:///usr/local/bin/phpunit/phpunit/TextUI/Command.php',
                        'line' => 104,
                        'function' => 'run',
                        'class' => 'PHPUnit_TextUI_Command',
                        'type' => '->',
                        ),
                9 => array (
                        'file' => '/usr/local/bin/phpunit',
                        'line' => 721,
                        'function' => 'main',
                        'class' => 'PHPUnit_TextUI_Command',
                        'type' => '::',
                        ),
                );

        $trace = BacktraceConverter::convertToString($backtrace);

        $this->assertEquals(<<<OUT
#0 (): Tideways\Profiler\BacktraceConverterTest->testHardcodedBacktrace()
#1 phar:///usr/local/bin/phpunit/phpunit/Framework/TestCase.php(860): ReflectionMethod->invokeArgs()
#2 phar:///usr/local/bin/phpunit/phpunit/Framework/TestCase.php(737): PHPUnit_Framework_TestCase->runTest()
#3 phar:///usr/local/bin/phpunit/phpunit/Framework/TestResult.php(609): PHPUnit_Framework_TestCase->runBare()
#4 phar:///usr/local/bin/phpunit/phpunit/Framework/TestCase.php(693): PHPUnit_Framework_TestResult->run()
#5 phar:///usr/local/bin/phpunit/phpunit/Framework/TestSuite.php(716): PHPUnit_Framework_TestCase->run()
#6 phar:///usr/local/bin/phpunit/phpunit/TextUI/TestRunner.php(398): PHPUnit_Framework_TestSuite->run()
#7 phar:///usr/local/bin/phpunit/phpunit/TextUI/Command.php(152): PHPUnit_TextUI_TestRunner->doRun()
#8 phar:///usr/local/bin/phpunit/phpunit/TextUI/Command.php(104): PHPUnit_TextUI_Command->run()
#9 /usr/local/bin/phpunit(721): PHPUnit_TextUI_Command->main()

OUT
            , $trace);
    }

    public function testConvertCommandlineCode()
    {
        $backtrace = array (
          0 => array (
            'file' => 'Command line code',
            'line' => 1,
            'function' => 'foo',
            'args' => array (),
          ),
          1 => array (
            'file' => 'Command line code',
            'line' => 1,
            'function' => 'bar',
            'args' => array (),
          ),
        );

        $trace = BacktraceConverter::convertToString($backtrace);

        $this->assertEquals(<<<OUT
#0 Command line code(1): foo()
#1 Command line code(1): bar()

OUT
            , $trace);
    }
}
