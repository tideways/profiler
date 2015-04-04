<?php

namespace Tideways;

class ProfilerTest extends \PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        if (function_exists('tideways_prepend_overwritten') && tideways_prepend_overwritten()) {
            $this->markTestSkipped('Cannot run tests when tideways is installed and loaded globally. Run with -dtideways.auto_prepend_library=0');
        }
    }

    public function testStartStopProfile()
    {
        $alwaysSample = 100;

        $backend = self::createBackend();
        $backend->expects($this->once())->method('storeProfile');

        \Tideways\Profiler::start('foo', $alwaysSample);
        \Tideways\Profiler::setTransactionName(__CLASS__ . '::' . __METHOD__);

        $this->assertTrue(\Tideways\Profiler::isProfiling());
        $this->assertTrue(\Tideways\Profiler::isStarted());

        \Tideways\Profiler::stop();

        $this->assertFalse(\Tideways\Profiler::isProfiling());
        $this->assertFalse(\Tideways\Profiler::isStarted());
    }

    public function testStartStopMeasurement()
    {
        $alwaysSample = 0;

        $backend = self::createBackend();
        $backend->expects($this->once())->method('storeMeasurement');

        \Tideways\Profiler::start('foo', $alwaysSample);
        \Tideways\Profiler::setTransactionName(__CLASS__ . '::' . __METHOD__);

        $this->assertFalse(\Tideways\Profiler::isProfiling());
        $this->assertTrue(\Tideways\Profiler::isStarted());

        \Tideways\Profiler::stop();

        $this->assertFalse(\Tideways\Profiler::isProfiling());
        $this->assertFalse(\Tideways\Profiler::isStarted());
    }

    private function createBackend()
    {
        $backend = $this->getMock('Tideways\Profiler\Backend');
        \Tideways\Profiler::setBackend($backend);

        return $backend;
    }

    public function testDetectFramework()
    {
        \Tideways\Profiler::detectFramework(\Tideways\Profiler::FRAMEWORK_SYMFONY2_FRAMEWORK);
        \Tideways\Profiler::start('foo');

        $reflection = new \ReflectionClass('Tideways\Profiler');
        $property = $reflection->getProperty('defaultOptions');
        $property->setAccessible(true);
        $options = $property->getValue();

        $this->assertEquals('Symfony\Bundle\FrameworkBundle\Controller\ControllerResolver::createController', $options['transaction_function']);
    }

    public function testCreateSpan()
    {
        \Tideways\Profiler::start('foo', 100);

        $span = \Tideways\Profiler::createSpan('sql');

        $this->assertInstanceOf('Tideways\Traces\Span', $span);
    }
}
