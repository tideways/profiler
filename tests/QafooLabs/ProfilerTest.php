<?php

namespace QafooLabs;

class ProfilerTest extends \PHPUnit_Framework_TestCase
{
    public function testStartStopProfile()
    {
        $alwaysSample = 10000;

        $backend = self::createBackend();
        $backend->expects($this->once())->method('storeProfile');

        \QafooLabs\Profiler::start('foo', $alwaysSample);
        \QafooLabs\Profiler::setTransactionName(__CLASS__ . '::' . __METHOD__);

        $this->assertTrue(\QafooLabs\Profiler::isProfiling());
        $this->assertTrue(\QafooLabs\Profiler::isStarted());

        \QafooLabs\Profiler::stop();

        $this->assertFalse(\QafooLabs\Profiler::isProfiling());
        $this->assertFalse(\QafooLabs\Profiler::isStarted());
    }

    public function testStartDevelopmentProfile()
    {
        $backend = self::createBackend();
        $backend->expects($this->once())->method('storeDevProfile');

        \QafooLabs\Profiler::startDevelopment('foo');
        \QafooLabs\Profiler::setTransactionName(__CLASS__ . '::' . __METHOD__);

        $this->assertTrue(\QafooLabs\Profiler::isProfiling());
        $this->assertTrue(\QafooLabs\Profiler::isStarted());

        \QafooLabs\Profiler::stop();

        $this->assertFalse(\QafooLabs\Profiler::isProfiling());
        $this->assertFalse(\QafooLabs\Profiler::isStarted());
    }

    public function testStartStopMeasurement()
    {
        $alwaysSample = 0;

        $backend = self::createBackend();
        $backend->expects($this->once())->method('storeMeasurement');

        \QafooLabs\Profiler::start('foo', $alwaysSample);
        \QafooLabs\Profiler::setTransactionName(__CLASS__ . '::' . __METHOD__);

        $this->assertFalse(\QafooLabs\Profiler::isProfiling());
        $this->assertTrue(\QafooLabs\Profiler::isStarted());

        \QafooLabs\Profiler::stop();

        $this->assertFalse(\QafooLabs\Profiler::isProfiling());
        $this->assertFalse(\QafooLabs\Profiler::isStarted());
    }

    public function testCustomTimers()
    {
        $alwaysSample = 10000;

        $backend = self::createBackend();

        \QafooLabs\Profiler::start('foo', $alwaysSample);
        \QafooLabs\Profiler::setTransactionName(__CLASS__ . '::' . __METHOD__);

        $sqlId = \QafooLabs\Profiler::startSqlCustomTimer('SELECT 1 FROM "bar"');
        $oId = \QafooLabs\Profiler::startCustomTimer('solr', 'foo=bar');
        \QafooLabs\Profiler::stopCustomTimer($oId);
        \QafooLabs\Profiler::stopCustomTimer($sqlId);

        \QafooLabs\Profiler::stop();

        $timers = \QafooLabs\Profiler::getCustomTimers();

        $this->assertCount(2, $timers);
        $this->assertEquals('sql', $timers[0]['group']);
        $this->assertEquals('solr', $timers[1]['group']);
        $this->assertEquals('SELECT ? FROM ?', $timers[0]['id']);
        $this->assertEquals('foo=bar', $timers[1]['id']);
    }

    private function createBackend()
    {
        $backend = $this->getMock('QafooLabs\Profiler\Backend');
        \QafooLabs\Profiler::setBackend($backend);

        return $backend;
    }
}
