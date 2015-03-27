<?php

namespace Tideways;

class ProfilerTest extends \PHPUnit_Framework_TestCase
{
    public function testStartStopProfile()
    {
        $alwaysSample = 100;

        $backend = self::createBackend();
        $backend->expects($this->once())->method('storeProfile');

        \Tideways\Profiler::start('foo', $alwaysSample);
        \Tideways\Profiler::setTransactionName(__CLASS__ . '::' . __METHOD__);

        $this->assertTrue(\Tideways\Profiler::isProfiling());
        $this->assertTrue(\Tideways\Profiler::isStarted());
        $this->assertEquals(22, strlen(\Tideways\Profiler::getProfileTraceUuid()));

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

    public function testCustomTimers()
    {
        $alwaysSample = 100;

        $backend = self::createBackend();

        \Tideways\Profiler::start('foo', $alwaysSample);
        \Tideways\Profiler::setTransactionName(__CLASS__ . '::' . __METHOD__);

        $sqlId = \Tideways\Profiler::startSqlCustomTimer('SELECT 1 FROM "bar"');
        $oId = \Tideways\Profiler::startCustomTimer('solr', 'foo=bar');
        \Tideways\Profiler::stopCustomTimer($oId);
        \Tideways\Profiler::stopCustomTimer($sqlId);

        \Tideways\Profiler::stop();

        $timers = \Tideways\Profiler::getCustomTimers();

        $this->assertCount(2, $timers);
        $this->assertEquals('sql', $timers[0]['group']);
        $this->assertEquals('solr', $timers[1]['group']);
        $this->assertEquals('SELECT ? FROM ?', $timers[0]['id']);
        $this->assertEquals('foo=bar', $timers[1]['id']);
    }

    public function testCustomVariables()
    {
        $alwaysSample = 100;

        $backend = self::createBackend();

        \Tideways\Profiler::start('foo', $alwaysSample);
        \Tideways\Profiler::setTransactionName(__CLASS__ . '::' . __METHOD__);

        \Tideways\Profiler::setCustomVariable("foo", "bar");

        $this->assertEquals('bar', \Tideways\Profiler::getCustomVariable("foo"));
    }

    public function testDefaultCustomVariables()
    {
        $_SERVER['SERVER_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_URI'] = '/foo/bar?baz';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['REQUEST_METHOD'] = 'PUT';

        \Tideways\Profiler::setDefaultCustomVariables();

        $this->assertEquals('PUT', \Tideways\Profiler::getCustomVariable("method"));
        $this->assertEquals('https://127.0.0.1/foo/bar', \Tideways\Profiler::getCustomVariable("url"));
    }

    private function createBackend()
    {
        $backend = $this->getMock('Tideways\Profiler\Backend');
        \Tideways\Profiler::setBackend($backend);

        return $backend;
    }
}
