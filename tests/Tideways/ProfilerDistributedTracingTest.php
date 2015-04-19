<?php

namespace Tideways;

class ProfilerDistributedTracingTest extends \PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        if (function_exists('tideways_prepend_overwritten') && tideways_prepend_overwritten()) {
            $this->markTestSkipped('Cannot run tests when tideways is installed and loaded globally. Run with -dtideways.auto_prepend_library=0');
        }

        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    public static function dataAllowedDistributedHosts()
    {
        return array(
            array('127.0.0.1', '', '127.0.0.1', true),
            array('62.12.13.14', '', '127.0.0.1', false),
            array('10.6.0.1', '10.6.0.11', '127.0.0.1', false),
            array('10.6.0.1', '62.12.13.14', '127.0.0.1', false),
            array('10.6.0.1', '10.6.0.11', 'X-Forwarded-For:10.6.0.1:10.6.0.10,10.6.0.11', true),
            array('10.6.0.1', '62.12.13.14', 'X-Forwarded-For:10.6.0.1:10.6.0.10,10.6.0.11', false),
            array('10.6.0.1', '10.6.0.11', 'X-Forwarded-For:10.6.0.1:10.6.0.10,10.6.0.11', true),
            array('10.6.0.1', '10.6.0.11,10.6.0.2', 'X-Forwarded-For:10.6.0.1,10.6.0.2:10.6.0.10,10.6.0.11', true),
            array('10.6.0.1', '62.12.13.14,10.6.0.2', 'X-Forwarded-For:10.6.0.1,10.6.0.2:10.6.0.10,10.6.0.11', false),
            array('10.6.0.1', '62.12.13.14,10.6.0.11,10.6.0.2', 'X-Forwarded-For:10.6.0.1,10.6.0.2:10.6.0.10,10.6.0.11', true),
        );
    }

    /**
     * @dataProvider dataAllowedDistributedHosts
     */
    public function testAllowedDistributedHosts($ipAddress, $forwardedFor, $allowedHosts, $expected)
    {
        $_SERVER['REMOTE_ADDR'] = $ipAddress;
        $_SERVER['HTTP_X_FORWARDED_FOR'] = $forwardedFor;

        $reflClass = new \ReflectionClass('Tideways\Profiler');
        $method = $reflClass->getMethod('allowAutoStartDistributedTracing');
        $method->setAccessible(true);

        $actual = $method->invoke(null, $allowedHosts);

        $this->assertEquals($expected, $actual);
    }
}
