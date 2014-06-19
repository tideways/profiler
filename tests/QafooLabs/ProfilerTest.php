<?php

namespace QafooLabs;

class ProfilerTest extends \PHPUnit_Framework_TestCase
{
    public function testEndToEnd()
    {
        \QafooLabs\Profiler::start('foo', true);
        \QafooLabs\Profiler::setTransactionName(__CLASS__ . '::' . __METHOD__);
        $this->assertTrue(\QafooLabs\Profiler::isProfiling());
        \QafooLabs\Profiler::stop();
        $this->assertFalse(\QafooLabs\Profiler::isProfiling());
    }
}
