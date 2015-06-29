<?php

namespace Tideways\Traces;

class TwExtensionSpanTest extends \PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        if (!extension_loaded('tideways')) {
            $this->markTestSkipped('Requires tideways extension.');
        }
        tideways_enable();
    }

    public function tearDown()
    {
        tideways_disable();
    }

    public function testGetId()
    {
        $span = \Tideways\Traces\TwExtensionSpan::createSpan('php');
        $this->assertEquals(1, $span->getId());
    }
}
